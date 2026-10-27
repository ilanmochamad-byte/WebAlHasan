<?php

declare(strict_types=1);

// F5-1: outbox nyata + worker dengan provider palsu. Tidak ada jaringan luar.
if (getenv('V7_RUN_AUDIT') !== '1' || !preg_match('/^test_[A-Za-z0-9_]*_test$/D', (string) getenv('DB_NAME'))) exit(2);
require_once dirname(__DIR__).'/app/bootstrap.php';
use App\Izin\IzinWaliNotifikasi as N;
use App\Notification\{DeviceRepository,NotificationDispatcher,OutboxRepository,PushTokenProtector,SettingsRepository,WorkerLock};
$db=app_db();$rows=static fn(string $q):array=>$db->query($q)->fetch_all(MYSQLI_ASSOC);$n=static fn(string $q):int=>(int)$db->query($q)->fetch_row()[0];
$account=static fn(string $u):array=>$rows("SELECT id,guru_id FROM users WHERE username='$u'")[0];
$w=$account('sbx_v7_wali_a');$pb=$account('sbx_v7_pembimbing_satu');$m=$account('sbx_murobi_a');$sid=$n("SELECT id FROM santri WHERE nis='V7S0008'");
$key=static fn():string=>'notif-f51-'.bin2hex(random_bytes(10));$pass=$fail=0;
$assert=static function(bool $ok,string $label)use(&$pass,&$fail):void{echo($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;$ok?$pass++:$fail++;};
$settings=$rows('SELECT push_enabled,whatsapp_enabled FROM pengaturan_notifikasi WHERE singleton=1')[0];$devicesBefore=$rows('SELECT id,push_aktif FROM perangkat_push');
$queueBefore=$rows("SELECT id,tersedia_pada FROM notifikasi_outbox WHERE kanal<>'InApp' AND status IN ('Queued','Failed')");
$db->query('UPDATE perangkat_push SET push_aktif=0');$db->query("UPDATE notifikasi_outbox SET tersedia_pada=NOW()+INTERVAL 1 DAY WHERE kanal<>'InApp' AND status IN ('Queued','Failed')");
$db->query('UPDATE pengaturan_notifikasi SET push_enabled=1,whatsapp_enabled=0 WHERE singleton=1');
$protector=new PushTokenProtector(base64_encode(str_repeat('f',32)));$devices=new DeviceRepository($db);$deviceId='f51-'.bin2hex(random_bytes(8));$token='ExponentPushToken[f51-'.bin2hex(random_bytes(12)).']';
$devices->register(['user_id'=>(int)$m['id'],'token_hash'=>$protector->hash($token),'token_terlindungi'=>$protector->protect($token),'platform'=>'android','device_id'=>$deviceId]);
$push=new class implements App\Notification\Push\PushClient {
    public int $calls=0;
    public function send(array $messages):array{$this->calls++;return ['ok'=>true,'tickets'=>array_map(static fn()=>['status'=>'ok','id'=>'f51-fake'], $messages),'kode'=>'OK','pesan'=>'Palsu','permanen'=>false];}
};
$wa=new class implements App\Notification\WhatsApp\WhatsAppProvider {
    public function name():string{return 'f51';}public function mengirimNyata():bool{return false;}
    public function readiness():array{return ['siap'=>true,'pesan'=>'Palsu','detail'=>[]];}
    public function verify():App\Notification\WhatsApp\ProviderResult{return App\Notification\WhatsApp\ProviderResult::ok();}
    public function send(App\Notification\WhatsApp\WhatsAppMessage $m):App\Notification\WhatsApp\ProviderResult{throw new RuntimeException('WhatsApp tidak boleh dipanggil');}
};
$assignment=$n("SELECT ma.id FROM murobi_assignments ma JOIN plotting_kamar pk ON pk.id_kamar=ma.kamar_id AND pk.id_tahun=ma.tahun_ajaran_id WHERE pk.id_santri=$sid AND ma.guru_id={$m['guru_id']} AND ma.is_active=1 LIMIT 1");
try {
    $day=(new DateTimeImmutable('2070-01-01'))->modify('+'.$n("SELECT COUNT(*)*3 FROM izin_pengajuan WHERE asal_pengajuan='Wali'").' days')->format('Y-m-d');
    $id=(int)izin_wali_service()->buat($w,['santri_id'=>$sid,'tgl_izin'=>$day,'tgl_kembali'=>$day,'alasan'=>'Penanda privat notifikasi F5-1'],$key(),[])['id'];
    izin_wali_service()->tinjau($pb,$id,'teruskan',null,1,$key(),[]);
    $out=$rows("SELECT * FROM notifikasi_outbox WHERE event_key='v7:izin:$id:masuk_murobi:v2' AND kanal='Push'");
    $assert(count($out)===1 && N::pushMasihBerhak($db,$out[0])!==null,'Kontrol: satu Push murobi benar-benar diantre dan awalnya berhak');
    $db->query("UPDATE murobi_assignments SET is_active=0 WHERE id=$assignment");
    $assert(N::pushMasihBerhak($db,$out[0])===null,'Pencabutan sebagian membatalkan hak Push walau masih menjadi murobi tujuan');
    $dispatcher=new NotificationDispatcher($db,new OutboxRepository($db),$devices,$protector,$push,$wa,new SettingsRepository($db),new WorkerLock($db));
    $dispatcher->run('Push',100);
    $row=$rows('SELECT error_kode FROM notifikasi_outbox WHERE id='.(int)$out[0]['id'])[0];
    $assert($row['error_kode']==='AKSES_BERUBAH' && $push->calls===0,'Worker menolak Push lama dengan AKSES_BERUBAH dan nol request provider palsu');
    izin_wali_service()->batalkan($w,$id,'Pembatalan setelah pemindahan murobi',2,$key(),[]);
    $actual=array_map('intval',array_column($rows("SELECT penerima_user_id FROM notifikasi_outbox WHERE event_key LIKE 'v7:izin:$id:%' AND event_type='".N::DIBATALKAN."' AND kanal='InApp' ORDER BY penerima_user_id"),'penerima_user_id'));
    $admins=array_map('intval',array_column($rows("SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.slug='admin' AND u.is_active=1 ORDER BY u.id"),'id'));
    $assert($actual===$admins && !in_array((int)$m['id'],$actual,true),'Pembatalan saat murobi tidak sah memberi tahu admin, tanpa penerima lama yang kehilangan cakupan');
    $assert($n("SELECT COUNT(*) FROM notifikasi_outbox WHERE event_key LIKE 'v7:izin:$id:%' AND kanal='WhatsApp'")===0,'Koreksi cakupan tetap tidak mengantre WhatsApp');
} finally {
    $db->query("UPDATE murobi_assignments SET is_active=1 WHERE id=$assignment");
    $db->query('UPDATE pengaturan_notifikasi SET push_enabled='.(int)$settings['push_enabled'].',whatsapp_enabled='.(int)$settings['whatsapp_enabled'].' WHERE singleton=1');
    $s=$db->prepare('UPDATE perangkat_push SET push_aktif=0 WHERE device_id=?');$s->bind_param('s',$deviceId);$s->execute();$s->close();
    foreach($devicesBefore as$r)$db->query('UPDATE perangkat_push SET push_aktif='.(int)$r['push_aktif'].' WHERE id='.(int)$r['id']);
    $s=$db->prepare('UPDATE notifikasi_outbox SET tersedia_pada=? WHERE id=?');foreach($queueBefore as$r){$s->bind_param('si',$r['tersedia_pada'],$r['id']);$s->execute();}$s->close();
}
echo "Hasil: $pass lulus, $fail gagal\n";exit($fail===0?0:1);
