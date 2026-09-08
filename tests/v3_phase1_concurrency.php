<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$r=new \App\V3\KatalogRepository(app_db());
if(($argv[1]??'')==='deadlock') {
    try {
        $r->transaction(function()use($r,$argv){
            $r->rows("SELECT id FROM schema_migrations WHERE migration='013_v3_fase1.sql' FOR UPDATE");
            echo "ready\n";flush();fgets(STDIN);
            $r->find('kategori',(int)$argv[2],true);
        });echo 'acquired';
    } catch(\App\V3\V3Exception $e) {echo $e->status===409?'conflict':'failed';}
    exit;
}
if(in_array($argv[1]??'', ['worker','timeout'], true)) {
    if ($argv[1]==='timeout') { $r->execute('SET SESSION innodb_lock_wait_timeout=1'); }
    $admin=$r->rows("SELECT id FROM users WHERE username='sbx_admin'")[0];$year=$r->rows("SELECT id FROM tahun_ajaran WHERE status='Aktif' LIMIT 1")[0]['id'];
    try {v3_katalog_service()->save('ambang',['tahun_ajaran_id'=>$year,'nilai_minimum'=>$argv[2],'nilai_maksimum'=>(int)$argv[2]+10,'label'=>'Konkurensi fiktif','rekomendasi'=>'Rekomendasi','tanggal_mulai'=>date('Y-m-d'),'tanggal_selesai'=>'','is_active'=>1],$admin);echo 'created';}
    catch(\App\V3\V3Exception $e){echo $e->status===409?'conflict':'failed';}exit;
}
$value=random_int(3000000,1900000000);$processes=[];
// Hold the service's serialization gate so both children genuinely contend.
app_db()->begin_transaction();$r->rows("SELECT id FROM schema_migrations WHERE migration='013_v3_fase1.sql' FOR UPDATE");
try {
 for($i=0;$i<2;$i++) {$p=proc_open([PHP_BINARY,__FILE__,'worker',(string)$value],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Worker gagal');fclose($pipes[0]);$processes[]=[$p,$pipes];}
 usleep(350000);
} finally {app_db()->commit();}
$result=[];foreach($processes as [$p,$pipes]) {$result[]=stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);proc_close($p);}sort($result);
$ok=$result===['conflict','created']&&(int)$r->rows('SELECT COUNT(*) n FROM v3_ambang WHERE nilai_minimum=?',[$value])[0]['n']===1;
echo ($ok?'[lulus]':'[gagal]')." Dua proses bersamaan: satu tersimpan, satu konflik, tanpa duplikasi.\n";
app_db()->begin_transaction();$r->rows("SELECT id FROM schema_migrations WHERE migration='013_v3_fase1.sql' FOR UPDATE");
try {
    $p=proc_open([PHP_BINARY,__FILE__,'timeout',(string)($value+30)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);proc_close($p);
} finally {app_db()->rollback();}
$timeoutOk=$output==='conflict'&&$r->rows('SELECT id FROM v3_ambang WHERE nilai_minimum=?',[$value+30])===[];
echo ($timeoutOk?'[lulus] ':'[gagal] ')."Lock timeout menghasilkan konflik aman tanpa baris baru.\n";
$category=(int)$r->rows('SELECT id FROM v3_kategori ORDER BY id LIMIT 1')[0]['id'];
app_db()->begin_transaction();$r->find('kategori',$category,true);
$p=proc_open([PHP_BINARY,__FILE__,'deadlock',(string)$category],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
$ready=trim((string)fgets($pipes[1]));fwrite($pipes[0],"go\n");fclose($pipes[0]);usleep(150000);
try {$r->rows("SELECT id FROM schema_migrations WHERE migration='013_v3_fase1.sql' FOR UPDATE");$parent='acquired';app_db()->commit();}
catch(\App\V3\V3Exception $e){$parent=$e->status===409?'conflict':'failed';app_db()->rollback();}
$child=stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);proc_close($p);
$deadlock=[$parent,$child];sort($deadlock);$deadlockOk=$ready==='ready'&&$deadlock===['acquired','conflict'];
echo ($deadlockOk?'[lulus] ':'[gagal] ')."Deadlock nyata dipetakan ke konflik aman.\n";
exit($ok&&$timeoutOk&&$deadlockOk?0:1);
