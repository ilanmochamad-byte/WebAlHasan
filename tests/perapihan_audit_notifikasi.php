<?php
declare(strict_types=1);
if(getenv('PERAPIHAN_AUDIT_DB')!=='1') exit(77);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(!str_ends_with((string)app_config('database.database'),'_test')) exit(2);
$db=app_db(); $repo=new App\Notification\NotificationRepository($db);
$ids=[]; $manifest=tempnam(sys_get_temp_dir(),'audit-notification-');
$tag='audit-b1-'.bin2hex(random_bytes(6));
$users=[];
foreach(['sbx_murobi_a','sbx_murobi_b'] as $u) $users[]=(int)$db->query("SELECT id FROM users WHERE username='$u'")->fetch_assoc()['id'];
if(min($users)<1) throw new RuntimeException('Fixture user tidak ada.');
$previous=$db->query('SELECT id,dibaca_pada FROM notifikasi_outbox WHERE penerima_user_id IN ('.implode(',',$users).')')->fetch_all(MYSQLI_ASSOC);
try {
    foreach($users as $index=>$user) for($i=0;$i<($index===0?25:1);$i++) {
        $id=$repo->enqueue(['event_key'=>"$tag-$index-$i",'event_type'=>'pengajuan_baru','kanal'=>App\Notification\NotificationChannel::IN_APP,'penerima_user_id'=>$user,'pengajuan_id'=>null,'judul'=>'Audit B-1 '.($i+1),'isi'=>'Notifikasi sintetis audit client mobile.','data_json'=>null]);
        if(!$id) throw new RuntimeException('Fixture notifikasi gagal.');
        $ids[]=$id;
    }
    file_put_contents($manifest,json_encode(['mine'=>array_slice($ids,0,25),'foreign'=>$ids[25]]));
    $env=getenv(); $env['AUDIT_NOTIFICATION_MANIFEST']=$manifest;
    $env['EXPO_PUBLIC_API_BASE_URL']='http://127.0.0.1:8940/api/v1';
    $process=proc_open(['node',__DIR__.'/perapihan_audit_mobile_client.cjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env);
    if(!is_resource($process)) throw new RuntimeException('Client test tidak dapat dijalankan.');
    fclose($pipes[0]); $exit=proc_close($process);
} finally {
    if($ids) $db->query('DELETE FROM notifikasi_outbox WHERE id IN ('.implode(',',$ids).')');
    $stmt=$db->prepare('UPDATE notifikasi_outbox SET dibaca_pada=? WHERE id=?');
    foreach($previous as $row) { $stmt->bind_param('si',$row['dibaca_pada'],$row['id']); $stmt->execute(); }
    unlink($manifest);
}
exit($exit);
