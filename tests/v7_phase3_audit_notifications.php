<?php
declare(strict_types=1);

// Matriks auditor: sepuluh jenis peristiwa melalui mutasi nyata, Push ON.
if(getenv('V7_RUN_AUDIT')!=='1' || !preg_match('/^test_[A-Za-z0-9_]*_test$/D',(string)getenv('DB_NAME'))) exit(2);
require_once dirname(__DIR__).'/app/bootstrap.php';
use App\Izin\IzinWaliNotifikasi as N;
use App\Notification\{DeviceRepository,NotificationDispatcher,OutboxRepository,PushTokenProtector,SettingsRepository,WorkerLock};
$db=app_db();
$value=static fn(string $sql): mixed=>$db->query($sql)->fetch_row()[0];
$rows=static fn(string $sql): array=>$db->query($sql)->fetch_all(MYSQLI_ASSOC);
$firstAudit=(int)$value('SELECT COALESCE(MAX(id),0) FROM audit_logs');
$account=static fn(string $u): array=>$rows("SELECT id,guru_id,pengurus_id FROM users WHERE username='$u'")[0];
$w=$account('sbx_v7_wali_a');$pb=$account('sbx_v7_pembimbing_satu');$pb2=$account('sbx_v7_pembimbing_dua');$m=$account('sbx_murobi_a');$m2=$account('sbx_murobi_b');$a=$account('sbx_admin');
$admins=array_map('intval',array_column($rows("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.is_active=1 AND r.slug='admin'"),'id'));
$nonce=bin2hex(random_bytes(5));$seq=0;
$key=static function() use (&$seq,$nonce): string{return 'audit-notif-'.$nonce.'-'.++$seq;};
$date=(new DateTimeImmutable('2051-01-01'))->modify('+'.random_int(1,1000).' days');
$makeInput=static function(string $nis) use (&$date,$value): array {
    $date=$date->modify('+2 days');$d=$date->format('Y-m-d');
    return ['santri_id'=>(int)$value("SELECT id FROM santri WHERE nis='$nis'"),'tgl_izin'=>$d,'tgl_kembali'=>$d,'alasan'=>'PRIVAT-AUDIT-NOTIF'];
};
$push=new class implements App\Notification\Push\PushClient {
    public array $messages=[];
    public function send(array $messages): array {
        $this->messages=array_merge($this->messages,$messages);
        return ['ok'=>true,'tickets'=>array_map(static fn()=>['status'=>'ok','id'=>'audit-'.bin2hex(random_bytes(8))],$messages),'kode'=>'OK','pesan'=>'Palsu','permanen'=>false];
    }
};
$wa=new class implements App\Notification\WhatsApp\WhatsAppProvider {
    public int $calls=0;
    public function name(): string{return 'audit';}
    public function mengirimNyata(): bool{return false;}
    public function readiness(): array{return ['siap'=>true,'pesan'=>'Palsu','detail'=>[]];}
    public function verify(): App\Notification\WhatsApp\ProviderResult {$this->calls++;return App\Notification\WhatsApp\ProviderResult::ok();}
    public function send(App\Notification\WhatsApp\WhatsAppMessage $m): App\Notification\WhatsApp\ProviderResult {$this->calls++;return App\Notification\WhatsApp\ProviderResult::ok();}
};
$devices=new DeviceRepository($db);$protector=new PushTokenProtector(base64_encode(str_repeat('n',32)));
$oldSettings=$rows('SELECT push_enabled,whatsapp_enabled FROM pengaturan_notifikasi WHERE singleton=1')[0];
$oldDevices=$rows('SELECT id,push_aktif FROM perangkat_push');
$db->query('UPDATE perangkat_push SET push_aktif=0');
$db->query("UPDATE notifikasi_outbox SET tersedia_pada=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE kanal<>'InApp' AND status IN ('Queued','Failed')");
$db->query('UPDATE pengaturan_notifikasi SET push_enabled=1,whatsapp_enabled=0 WHERE singleton=1');
$tokenUsers=[];
foreach(array_unique(array_merge($admins,array_map('intval',[$w['id'],$pb['id'],$pb2['id'],$m['id'],$m2['id'],$account('sbx_v7_wali_b')['id'],$account('sbx_ortu_b')['id']]))) as $uid) {
    $token='ExponentPushToken[audit-'.bin2hex(random_bytes(12)).']';$tokenUsers[$token]=$uid;
    $devices->register(['user_id'=>$uid,'token_hash'=>$protector->hash($token),'token_terlindungi'=>$protector->protect($token),'platform'=>'android','device_id'=>'audit-'.$nonce.'-'.$uid]);
}
$dispatcher=new NotificationDispatcher($db,new OutboxRepository($db),$devices,$protector,$push,$wa,new SettingsRepository($db),new WorkerLock($db));
$pass=$fail=0;$seen=[];
$assert=static function(bool $ok,string $label) use (&$pass,&$fail): void {echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;$ok?$pass++:$fail++;};
$sort=static function(array $ids): array {$ids=array_map('intval',$ids);sort($ids);return $ids;};
$step=static function(string $label,callable $action,array $expected) use ($rows,$value,$sort,$assert,$dispatcher,$push,$tokenUsers,&$seen): array {
    $before=(int)$value('SELECT COALESCE(MAX(id),0) FROM notifikasi_outbox');$sent=count($push->messages);
    $result=$action();$new=$rows("SELECT * FROM notifikasi_outbox WHERE id>$before");
    $expectedTokens=[];$ok=true;
    foreach($expected as $event=>$users) {
        $seen[$event]=true;
        foreach(['InApp','Push'] as $channel) {
            $found=array_column(array_filter($new,static fn($r)=>$r['event_type']===$event && $r['kanal']===$channel),'penerima_user_id');
            if($sort($found)!==$sort($users))$ok=false;
        }
        $expectedTokens=array_merge($expectedTokens,$users);
    }
    $assert($ok && count($new)===2*count($expectedTokens),"$label: InApp/Push hanya kepada penerima yang diharapkan");
    $dispatcher->run('Push',100);
    $messages=array_slice($push->messages,$sent);
    $got=array_map(static fn($msg)=>$tokenUsers[$msg['to']]??-1,$messages);
    $assert($sort($got)===$sort($expectedTokens),"$label: worker benar-benar mengirim kepada penerima yang tepat");
    $action();$dispatcher->run('Push',100);
    $assert(count($push->messages)===$sent+count($messages) && (int)$value('SELECT COALESCE(MAX(id),0) FROM notifikasi_outbox')===$before+count($new),"$label: replay mutasi dan worker tanpa duplikat efektif");
    return $result;
};
try {
    $input=$makeInput('V7S0008');$k=$key();
    $id=(int)$step('Buat',fn()=>izin_wali_service()->buat($w,$input,$k,[]),[N::MASUK_PEMBIMBING=>[$pb['id']]])['id'];
    $k=$key();$step('Minta perbaikan',fn()=>izin_wali_service()->tinjau($pb,$id,'minta_perbaikan','PRIVAT-AUDIT-NOTIF',1,$k,[]),[N::PERLU_PERBAIKAN=>[$w['id']]]);
    $input['alasan']='PRIVAT-AUDIT-NOTIF diperbaiki';$k=$key();$step('Kirim ulang',fn()=>izin_wali_service()->perbaiki($w,$id,$input,2,$k,[]),[N::MASUK_PEMBIMBING=>[$pb['id']]]);
    $k=$key();$step('Tolak pembimbing',fn()=>izin_wali_service()->tinjau($pb,$id,'tolak','PRIVAT-AUDIT-NOTIF',3,$k,[]),[N::DITOLAK_PEMBIMBING=>[$w['id']]]);
    $input=$makeInput('V7S0008');$k=$key();$id=(int)$step('Buat kedua',fn()=>izin_wali_service()->buat($w,$input,$k,[]),[N::MASUK_PEMBIMBING=>[$pb['id']]])['id'];
    $k=$key();$step('Teruskan',fn()=>izin_wali_service()->tinjau($pb,$id,'teruskan',null,1,$k,[]),[N::DITERUSKAN=>[$w['id']],N::MASUK_MUROBI=>[$m['id']]]);
    $k=$key();$step('Putuskan',fn()=>izin_workflow_service()->decide($m,$id,'Disetujui','PRIVAT-AUDIT-NOTIF',null,2,$k,[],'murobi'),[N::DIPUTUS=>[$w['id']]]);
    $k=$key();$step('Koreksi',fn()=>izin_workflow_service()->correctDecision($a,$id,'Ditolak','PRIVAT-AUDIT-NOTIF','Koreksi fixture auditor',3,$k,[]),[N::DIKOREKSI=>[$w['id']]]);
    $input=$makeInput('V7S0003');$k=$key();$id=(int)$step('Pembimbing ambigu',fn()=>izin_wali_service()->buat($w,$input,$k,[]),[N::PERLU_PENETAPAN_PEMBIMBING=>$admins])['id'];
    $k=$key();$step('Tetapkan pembimbing',fn()=>izin_wali_service()->tetapkanPembimbing($a,$id,(int)$pb2['pengurus_id'],'Penetapan fixture auditor',1,$k,[]),[N::MASUK_PEMBIMBING=>[$pb2['id']]]);
    $k=$key();$step('Batal pembimbing',fn()=>izin_wali_service()->batalkan($w,$id,'Pembatalan fixture auditor',2,$k,[]),[N::DIBATALKAN=>[$pb2['id']]]);
    $input=$makeInput('V7S0006');$k=$key();$id=(int)$step('Buat tanpa murobi',fn()=>izin_wali_service()->buat($w,$input,$k,[]),[N::MASUK_PEMBIMBING=>[$pb['id']]])['id'];
    $k=$key();$step('Routing murobi kosong',fn()=>izin_wali_service()->tinjau($pb,$id,'teruskan',null,1,$k,[]),[N::DITERUSKAN=>[$w['id']],N::PERLU_PENETAPAN_MUROBI=>$admins]);
    $k=$key();$step('Tetapkan murobi',fn()=>izin_workflow_service()->assignMurobi($a,$id,(int)$m2['guru_id'],'Penetapan fixture auditor',2,$k,[]),[N::MASUK_MUROBI=>[$m2['id']]]);
    $k=$key();$step('Batal murobi',fn()=>izin_wali_service()->batalkan($w,$id,'Pembatalan fixture auditor',3,$k,[]),[N::DIBATALKAN=>[$m2['id']]]);
    $assert(count($seen)===10,'Kesepuluh jenis peristiwa dibuktikan dengan Push ON');
    $db->query('UPDATE pengaturan_notifikasi SET push_enabled=0 WHERE singleton=1');$n=count($push->messages);
    $input=$makeInput('V7S0008');izin_wali_service()->buat($w,$input,$key(),[]);$dispatcher->run('Push',100);$dispatcher->run('WhatsApp',100);
    $assert(count($push->messages)===$n && $wa->calls===0,'Push OFF / WhatsApp OFF: nol request provider tambahan, pengajuan tetap berhasil');
    $leaks=0;
    foreach($push->messages as $message){unset($message['to']);$text=json_encode($message);if(str_contains($text,'PRIVAT-AUDIT-NOTIF')||str_contains($text,'SBX V7 Santri')||str_contains($text,'PushToken['))$leaks++;}
    $assert($leaks===0,'Payload seluruh peristiwa hanya generik/penunjuk, token tujuan hanya di medan to');
    $auditLeaks=0;
    foreach($rows("SELECT before_json,after_json FROM audit_logs WHERE id>$firstAudit AND entity_type='izin_pengajuan'") as $row) {
        foreach($row as $json) {
            $data=json_decode((string)$json,true);
            if(is_array($data) && (array_key_exists('alasan',$data)||array_key_exists('alasan_koreksi',$data)||str_contains((string)$json,'PRIVAT-AUDIT-NOTIF'))) $auditLeaks++;
        }
    }
    $assert($auditLeaks===0,"Seluruh action audit V7, termasuk pemroses V2: nol alasan mentah ($auditLeaks bocor)");
} finally {
    $db->query('UPDATE pengaturan_notifikasi SET push_enabled='.(int)$oldSettings['push_enabled'].',whatsapp_enabled='.(int)$oldSettings['whatsapp_enabled'].' WHERE singleton=1');
    foreach($oldDevices as $d)$db->query('UPDATE perangkat_push SET push_aktif='.(int)$d['push_aktif'].' WHERE id='.(int)$d['id']);
}
echo "Hasil: $pass lulus, $fail gagal\n";exit($fail===0?0:1);
