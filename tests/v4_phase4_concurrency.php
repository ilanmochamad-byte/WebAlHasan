<?php
require __DIR__.'/v4_phase4_support.php';
$counts=static fn()=>[(int)$q('SELECT COUNT(*) n FROM v4_rapor_versi')[0]['n'],(int)$q("SELECT COUNT(*) n FROM notifikasi_outbox WHERE kanal='InApp'")[0]['n']];
foreach(['same','different'] as $mode){
 file_put_contents('/private/tmp/v4-fase4-concurrent.json',json_encode($make('Ganti')));$before=$counts();$workers=[];
 for($i=0;$i<6;$i++){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/v4_phase4_worker.php',$mode,(string)$i],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$p,$pipes];}
 $codes=[];foreach($workers as [$p,$pipes]){$codes[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);foreach($pipes as $f)fclose($f);$ok(proc_close($p)===0&&$err==='','Worker koneksi terpisah selesai'.($err!==''?' '.$err:''));}
 sort($codes);$ok($codes===($mode==='same'?array_fill(0,6,'200'):['200','409','409','409','409','409']),'Enam penerbit '.$mode.' hasil '.json_encode($codes));$ok($counts()===[$before[0]+1,$before[1]+2],'K2 tepat satu versi/PDF baru dan dua InApp pada '.$mode);
}
// Verify source write cannot slip between gate and DB commit.
@unlink('/private/tmp/v4-fase4-worker-marker');file_put_contents('/private/tmp/v4-fase4-concurrent.json',json_encode($make('Ganti')));$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/v4_phase4_worker.php','delay'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
$end=microtime(true)+10;while(!file_exists('/private/tmp/v4-fase4-worker-marker')&&microtime(true)<$end)usleep(20000);
$ok(file_exists('/private/tmp/v4-fase4-worker-marker'),'Penerbit mencapai penulisan PDF sambil memegang kunci sumber');
$db->query('SET SESSION innodb_lock_wait_timeout=1');$id=(int)$q("SELECT id FROM absensi_santri WHERE santri_id=$sid ORDER BY id LIMIT 1")[0]['id'];
try{$db->query("UPDATE absensi_santri SET status='Alpa' WHERE id=$id");$ok(false,'Sumber harus terkunci');}catch(mysqli_sql_exception $e){$ok((int)$e->getCode()===1205,'Kehadiran bersamaan timeout 1205 sebelum commit, tidak lolos gate');}
$code=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);foreach($pipes as $f)fclose($f);$ok(proc_close($p)===0&&$code==='200'&&$err==='','Penerbit terkontrol commit satu versi');
// Process crash after fsync and before DB commit: file orphan, no version or notification.
@unlink('/private/tmp/v4-fase4-worker-marker');file_put_contents('/private/tmp/v4-fase4-concurrent.json',json_encode($make('Ganti')));$before=$counts();$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/v4_phase4_worker.php','crash'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);foreach($pipes as $f){stream_get_contents($f);fclose($f);}$exit=proc_close($p);
$name=file_get_contents('/private/tmp/v4-fase4-worker-marker');$ok($exit===99&&$counts()===$before,'Crash sebelum commit: nol versi/outbox baru');
$ok(is_file((new App\V4\RaporStorage())->path($name))&&!$q("SELECT id FROM v4_rapor_versi WHERE pdf_private_path='$name'"),'Berkas crash privat tanpa referensi; tidak tersedia lewat unduhan');
// Test-only targeted cleanup after proof that no row committed. Normal reconcile remains read-only.
(new App\V4\RaporStorage())->remove($name);$ok(!is_file((new App\V4\RaporStorage())->path($name)),'Pembersihan fixture orphan terarah setelah DB dikonfirmasi');
