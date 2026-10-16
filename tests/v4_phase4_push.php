<?php
require __DIR__.'/v4_phase4_support.php';
use App\Notification\{NotificationDispatcher,OutboxRepository,DeviceRepository,PushTokenProtector,SettingsRepository,WorkerLock,NotificationRepository};
$push=new class implements App\Notification\Push\PushClient {
 public int $calls=0;public bool $fail=true;public array $payloads=[];
 public function send(array $messages):array{$this->calls++;$this->payloads=array_merge($this->payloads,$messages);if($this->fail)return ['ok'=>false,'tickets'=>[],'kode'=>'UJI_RETRY','pesan'=>'Kegagalan sintetis','permanen'=>false];return ['ok'=>true,'tickets'=>array_map(fn($m)=>['status'=>'ok','id'=>'synthetic-ticket-'.bin2hex(random_bytes(6))],$messages),'kode'=>'OK','pesan'=>'Uji','permanen'=>false];}
};
$wa=new class implements App\Notification\WhatsApp\WhatsAppProvider {
 public int $calls=0;public function name():string{return 'mock';}public function mengirimNyata():bool{return false;}public function readiness():array{return ['siap'=>true,'pesan'=>'mock','detail'=>[]];}public function verify():App\Notification\WhatsApp\ProviderResult{throw new Exception('Tidak diperlukan');}public function send(App\Notification\WhatsApp\WhatsAppMessage $m):App\Notification\WhatsApp\ProviderResult{$this->calls++;return App\Notification\WhatsApp\ProviderResult::permanen('MOCK','mock');}
};
$protector=new PushTokenProtector((string)app_config('notifikasi.push_token_key'));$devices=new DeviceRepository($db);$ok($protector->ready(),'Proteksi token sintetis siap');
foreach(['v4_wali4a','v4_wali4b'] as $name){$token='ExponentPushToken['.bin2hex(random_bytes(12)).']';$devices->register(['user_id'=>$users[$name]['id'],'token_hash'=>$protector->hash($token),'token_terlindungi'=>$protector->protect($token),'platform'=>'android','device_id'=>'synthetic-'.$name]);}
$dispatcher=new NotificationDispatcher($db,new OutboxRepository($db),$devices,$protector,$push,$wa,new SettingsRepository($db),new WorkerLock($db));
$db->query('UPDATE pengaturan_notifikasi SET push_enabled=0,whatsapp_enabled=0 WHERE singleton=1');$dispatcher->run('Push');$dispatcher->run('WhatsApp');$ok($push->calls===0&&$wa->calls===0,'K6 Push OFF + WhatsApp OFF: tepat nol request mock provider meskipun outbox pending');
$db->query('UPDATE pengaturan_notifikasi SET push_enabled=1 WHERE singleton=1');$r=$dispatcher->run('Push');$ok($push->calls===2&&$r['gagal']===2,'Kegagalan provider sintetis menyimpan dua kegagalan retry');
foreach($q("SELECT id FROM notifikasi_outbox WHERE kanal='Push'") as $n)(new NotificationRepository($db))->requeue((int)$n['id']);
$push->fail=false;$r=$dispatcher->run('Push');$ok($r['terkirim']===2&&$push->calls===4,'Retry baris yang sama sukses pada mock, tanpa duplikasi outbox');
$dispatcher->run('Push');$ok($push->calls===4,'Worker ulang tidak mengirim baris Sent');
foreach($push->payloads as $p){$ok($p['title']==='Rapor semester tersedia'&&$p['body']==='Rapor semester tersedia'&&array_keys($p['data'])===['tipe','rapor_id'],'K6 payload provider benar-benar hanya generik dan penunjuk');}
$ok((int)$q("SELECT COUNT(*) n FROM notifikasi_outbox WHERE kanal='Push'")[0]['n']===2&&(int)$q("SELECT COUNT(*) n FROM notifikasi_outbox WHERE kanal='InApp'")[0]['n']===2,'Retry provider mempertahankan dua InApp dan dua outbox Push');
$db->query('UPDATE pengaturan_notifikasi SET push_enabled=0 WHERE singleton=1');
echo "Tidak ada panggilan jaringan/provider nyata atau pengiriman push fisik dalam suite ini.\n";
