<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
use App\Notification\{NotificationDispatcher,OutboxRepository,DeviceRepository,PushTokenProtector,SettingsRepository,WorkerLock};
use App\Notification\WhatsApp\{WhatsAppProvider,WhatsAppMessage,ProviderResult};
$push=new class implements App\Notification\Push\PushClient,App\Notification\Push\PushReceiptClient {
 public array $messages=[];public int $calls=0;public int $receipts=0;public bool $fail=true;
 public function send(array $messages):array {$this->calls++;$this->messages=array_merge($this->messages,$messages);return $this->fail?['ok'=>false,'tickets'=>[],'kode'=>'FAKE_RETRY','pesan'=>'Gangguan uji sementara','permanen'=>false]:['ok'=>true,'tickets'=>array_map(fn($m)=>['status'=>'ok','id'=>'f4-ticket-'.bin2hex(random_bytes(8))],$messages),'kode'=>'OK','pesan'=>'Fake only','permanen'=>false];}
 public function getReceipts(array $ids):array {$this->receipts++;return ['ok'=>true,'receipts'=>array_fill_keys($ids,['status'=>'ok']),'kode'=>'OK','pesan'=>'Fake receipt','permanen'=>false];}
};
$wa=new class implements WhatsAppProvider {
 public int $calls=0;
 public function name():string{return 'test';} public function mengirimNyata():bool{return false;}
 public function readiness():array{return ['siap'=>true,'pesan'=>'Fake','detail'=>[]];}
 public function verify():ProviderResult{$this->calls++;return ProviderResult::ok();}
 public function send(WhatsAppMessage $m):ProviderResult{$this->calls++;return ProviderResult::ok();}
};
$r=new App\V3\KonselingRepository(app_db());$settings=new SettingsRepository(app_db());$saved=$settings->current();$deviceRepo=new DeviceRepository(app_db());$protector=new PushTokenProtector(base64_encode(str_repeat('x',32)));
$dispatcher=new NotificationDispatcher(app_db(),new OutboxRepository(app_db()),$deviceRepo,$protector,$push,$wa,$settings,new WorkerLock(app_db()));$fail=0;$check=static function($ok,$label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
$a=$r->one("SELECT id FROM users WHERE username='sbx_pengurus_a'");$parent=$r->one("SELECT id,wali_id FROM users WHERE username='sbx_ortu_a'");$student=$r->studentOptions((int)$a['id'])[0];$key=fn()=>'f4-channel-'.bin2hex(random_bytes(9));$deviceId=null;
// Sementara tunda antrean fixture lain agar worker hanya memproses publikasi suite ini.
$pending=$r->all("SELECT id,tersedia_pada FROM notifikasi_outbox WHERE kanal='Push' AND status IN ('Queued','Failed') AND gagal_permanen=0");foreach($pending as $p)$r->execute('UPDATE notifikasi_outbox SET tersedia_pada=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE id=?',[(int)$p['id']]);
try{
 $r->execute('UPDATE pengaturan_notifikasi SET push_enabled=0,whatsapp_enabled=0 WHERE singleton=1');
 $dispatcher->run('Push');$dispatcher->run('WhatsApp');$dispatcher->reconcileReceipts(10,0);
 $check($push->calls===0&&$push->receipts===0,'Push OFF: nol send dan nol request receipt');$check($wa->calls===0,'WhatsApp OFF: nol request provider');
 $case=v3_konseling_service()->createCase($a,['santri_id'=>(int)$student['santri_id'],'tahun_ajaran_id'=>(int)$student['tahun_ajaran_id'],'tujuan'=>'PRIVATE-F4-CHANNEL','kerahasiaan'=>'Internal','idempotency_key'=>$key()]);$cid=$case['data']['kasus']['id'];
 $input=['sumber_type'=>'kasus','sumber_id'=>$cid,'sumber_version'=>1,'ringkasan'=>'Informasi keluarga saja','tindak_lanjut'=>'Tindak lanjut keluarga'];
 $publish=function($input)use($a,$key){$s=v3_publikasi_service();$p=$s->preview($a,$input);return $s->publish($a,['pratinjau_token'=>$p['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()])['data']['publikasi_ids'][0];};
 $offId=$publish($input);$check((int)$r->one("SELECT COUNT(*) n FROM notifikasi_outbox n JOIN v3_publikasi_outbox x ON x.outbox_id=n.id WHERE x.publikasi_id=? AND n.kanal<>'InApp'",[$offId])['n']===0,'OFF saat terbit: hanya InApp, tanpa antrean provider');
 $settings->setPushEnabled(true,(int)$a['id']);$input['ringkasan']='Informasi uji fake push';$id=$publish($input);
 $token='ExpoPushToken[f4-test-'.bin2hex(random_bytes(12)).']';$device=$deviceRepo->register(['user_id'=>(int)$parent['id'],'token_hash'=>$protector->hash($token),'token_terlindungi'=>$protector->protect($token),'platform'=>'android','device_id'=>$key(),'device_label'=>'Fixture F4','app_version'=>'test']);$deviceId=$device['id'];
 $oid=(int)$r->one("SELECT n.id FROM notifikasi_outbox n JOIN v3_publikasi_outbox x ON x.outbox_id=n.id WHERE x.publikasi_id=? AND n.kanal='Push'",[$id])['id'];
 $dispatcher->run('Push');$failed=$r->one('SELECT status,percobaan,tersedia_pada FROM notifikasi_outbox WHERE id=?',[$oid]);$check($failed['status']==='Failed'&&(int)$failed['percobaan']===1&&$push->calls===1,'Fake kegagalan sementara dicatat dengan retry');
 $push->fail=false;$r->execute('UPDATE notifikasi_outbox SET tersedia_pada=NOW() WHERE id=?',[$oid]);$dispatcher->run('Push');$dispatcher->run('Push');$sent=$r->one('SELECT status,percobaan FROM notifikasi_outbox WHERE id=?',[$oid]);$check($sent['status']==='Sent'&&(int)$sent['percobaan']===2&&$push->calls===2,'Retry worker memakai baris yang sama, tidak mengirim ulang baris Sent');
 $message=$push->messages[0];$check($message['data']===['tipe'=>'v3_publikasi','publikasi_id'=>$id]&&$message['body']==='Ada pembaruan pembinaan. Masuk untuk melihat informasi.','Payload push hanya penunjuk publikasi dan pesan generik');
 $dispatcher->reconcileReceipts(100,0);$check($push->receipts>0,'Receipt diproses melalui fake; bukan bukti perangkat fisik');
 $input['ringkasan']='Uji relasi dicabut sebelum worker';$revokeId=$publish($input);$calls=$push->calls;
 $r->execute('UPDATE santri_wali SET archived_at=NOW() WHERE santri_id=? AND wali_id=?',[(int)$student['santri_id'],(int)$parent['wali_id']]);
 try{$dispatcher->run('Push');$check($push->calls===$calls,'Worker tidak mengirim sesudah relasi wali dicabut');}finally{$r->execute('UPDATE santri_wali SET archived_at=NULL WHERE santri_id=? AND wali_id=?',[(int)$student['santri_id'],(int)$parent['wali_id']]);}
 $check($wa->calls===0,'Seluruh suite: WhatsApp tetap nol request');
}finally{
 $r->execute('UPDATE pengaturan_notifikasi SET push_enabled=?,whatsapp_enabled=? WHERE singleton=1',[(int)$saved['push_enabled'],(int)$saved['whatsapp_enabled']]);
 if($deviceId!==null)$r->execute('UPDATE perangkat_push SET push_aktif=0,dicabut_pada=NOW() WHERE id=?',[$deviceId]);
 foreach($pending as $p)$r->execute('UPDATE notifikasi_outbox SET tersedia_pada=? WHERE id=?',[$p['tersedia_pada'],(int)$p['id']]);
}
exit($fail?1:0);
