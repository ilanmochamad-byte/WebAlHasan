<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$r=new \App\V3\KatalogRepository(app_db());
$run=static function():int{$p=proc_open([PHP_BINARY,APP_ROOT.'/bin/v3_verify.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);return proc_close($p);};
$healthy=$run()===0;
$year=$r->rows("SELECT id FROM tahun_ajaran WHERE status='Aktif' LIMIT 1")[0]['id'];$ids=[];
try {
    foreach([1,2] as $n)$ids[]=$r->execute("INSERT INTO v3_ambang(tahun_ajaran_id,nilai_minimum,nilai_maksimum,label,rekomendasi,tanggal_mulai) VALUES(?,2147000000,2147000010,'Fixture rusak','Hanya uji',CURDATE())",[$year]);
    $broken=$run()!==0;
} finally {foreach($ids as $id)$r->execute('UPDATE v3_ambang SET is_active=0 WHERE id=?',[$id]);}
$restored=$run()===0;
foreach(['Sehat exit 0'=>$healthy,'Overlap fixture exit nonzero'=>$broken,'Setelah perbaikan exit 0'=>$restored] as $label=>$ok)echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;
exit($healthy&&$broken&&$restored?0:1);
