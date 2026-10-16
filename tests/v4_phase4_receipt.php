<?php
require __DIR__.'/v4_phase4_support.php';
use App\Notification\{NotificationDispatcher,OutboxRepository,DeviceRepository,PushTokenProtector,SettingsRepository,WorkerLock};
$mock=new class implements App\Notification\Push\PushClient,App\Notification\Push\PushReceiptClient{
 public int $calls=0;
 public function send(array $m):array{throw new Exception('Receipt suite tidak mengirim');}
 public function getReceipts(array $ids):array{$this->calls++;return ['ok'=>true,'receipts'=>array_fill_keys($ids,['status'=>'error','details'=>['error'=>'DeviceNotRegistered'],'message'=>'Token sintetis dicabut']),'kode'=>'OK','pesan'=>'mock','permanen'=>false];}
};
$dispatcher=new NotificationDispatcher($db,new OutboxRepository($db),new DeviceRepository($db),new PushTokenProtector((string)app_config('notifikasi.push_token_key')),$mock,new App\Notification\WhatsApp\NullProvider(),new SettingsRepository($db),new WorkerLock($db));
$db->query('UPDATE pengaturan_notifikasi SET push_enabled=0 WHERE singleton=1');$dispatcher->reconcileReceipts(100,0);$ok($mock->calls===0,'Push OFF: nol request receipt provider');
try{
 $db->query('UPDATE pengaturan_notifikasi SET push_enabled=1 WHERE singleton=1');$result=$dispatcher->reconcileReceipts(100,0);
 $ok($mock->calls===1&&$result['gagal']===2&&$result['token_dicabut']===2,'Receipt mock DeviceNotRegistered: dua token sintetis dicabut tanpa warning/fatal');
 $dispatcher->reconcileReceipts(100,0);$ok($mock->calls===1,'Receipt ulang idempoten tanpa request tambahan');
}finally{$db->query('UPDATE pengaturan_notifikasi SET push_enabled=0 WHERE singleton=1');}
