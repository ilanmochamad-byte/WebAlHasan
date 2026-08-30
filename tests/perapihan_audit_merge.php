<?php
declare(strict_types=1);
if (getenv('PERAPIHAN_AUDIT_DB') !== '1') { exit(77); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (!str_ends_with((string)app_config('database.database'), '_test')) { exit(2); }
$db=app_db(); $master=master_data_service();
if (($argv[1] ?? '') === 'worker') {
    try { $master->mergeWali((int)$argv[2], (int)$argv[3], (int)$argv[4], true); echo json_encode(['ok'=>true]); }
    catch (Throwable $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}
$actor=(int)$db->query("SELECT id FROM users WHERE username='sbx_admin'")->fetch_assoc()['id'];
$_SESSION['user_id']=$actor;
$suffix=bin2hex(random_bytes(4)); $ids=[]; $fail=0;
$check=static function(bool $ok,string $label) use (&$fail):void { echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL; $fail+=!$ok; };
try {
    for($i=0;$i<2;$i++) $ids[]=$master->saveWali(['nama'=>'Audit Merge '.$suffix.$i]);
    $db->begin_transaction();
    $db->query('SELECT id FROM wali WHERE id IN ('.implode(',',$ids).') FOR UPDATE');
    $jobs=[];
    foreach ([[$ids[0],$ids[1]],[$ids[1],$ids[0]]] as [$source,$target]) {
        $pipes=[];
        $proc=proc_open([PHP_BINARY,__FILE__,'worker',(string)$source,(string)$target,(string)$actor],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $jobs[]=[$proc,$pipes];
    }
    // Pastikan kedua worker benar-benar menunggu lock pasangan wali, bukan
    // mengandalkan waktu mulai yang kebetulan bersamaan.
    $waiting=0; $until=microtime(true)+5;
    do {
        $waiting=0;
        foreach($db->query('SHOW PROCESSLIST')->fetch_all(MYSQLI_ASSOC) as $p) {
            if ((int)$p['Id']!==$db->thread_id && str_contains((string)$p['Info'],'SELECT id FROM wali WHERE id IN')) $waiting++;
        }
        if($waiting<2) usleep(10000);
    } while($waiting<2 && microtime(true)<$until);
    $check($waiting===2,'A-03 dua penggabungan berlawanan menunggu lock yang sama');
    $db->commit();
    $success=0;
    foreach($jobs as [$proc,$pipes]) {
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($proc);
        $result=json_decode($out,true);
        $check($exit===0 && is_array($result),'A-03 worker menyelesaikan hasil terstruktur');
        $success+=(int)($result['ok']??false);
    }
    $rows=$db->query('SELECT id,is_active,merged_into_wali_id FROM wali WHERE id IN ('.implode(',',$ids).')')->fetch_all(MYSQLI_ASSOC);
    $active=array_filter($rows,static fn($r)=>$r['merged_into_wali_id']===null && (int)$r['is_active']===1);
    $check($success===1 && count($active)===1,'A-03 tepat satu merge berhasil, tidak ada siklus atau seluruh wali diarsipkan');
} finally {
    $db->rollback();
    if($ids) { $list=implode(',',$ids); $db->query("UPDATE wali SET merged_into_wali_id=NULL WHERE id IN ($list)"); $db->query("DELETE FROM wali WHERE id IN ($list)"); }
}
exit($fail?1:0);
