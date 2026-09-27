<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$r=new App\V3\KonselingRepository(app_db());
$count=static fn()=>[
 (int)$r->one('SELECT COUNT(*) n FROM notifikasi_outbox o LEFT JOIN izin_pengajuan p ON p.id=o.pengajuan_id WHERE o.pengajuan_id IS NOT NULL AND p.id IS NULL')['n'],
 (int)$r->one('SELECT COUNT(*) n FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_user_id WHERE a.actor_user_id IS NOT NULL AND u.id IS NULL')['n'],
];
putenv('V2_PHASE2_RUN_INTEGRATION=1');putenv('V2_PHASE2_RUN_WEB=1');putenv('V2_PHASE3_RUN_API=1');
$before=$count();$fail=0;
foreach(['v2_phase2_integration','v2_phase2_web_smoke','v2_phase3_api_contract'] as $suite){
 $p=proc_open([PHP_BINARY,APP_ROOT.'/tests/'.$suite.'.php'],[0=>['pipe','r'],1=>['file',sys_get_temp_dir().'/f5-teardown-'.$suite.'.log','w'],2=>['file',sys_get_temp_dir().'/f5-teardown-'.$suite.'.log','a']],$pipes);fclose($pipes[0]);$ok=proc_close($p)===0;
 echo ($ok?'[lulus] ':'[gagal] ').'Fixture '.$suite.PHP_EOL;if(!$ok)$fail++;
 $ok=$count()===$before;echo ($ok?'[lulus] ':'[gagal] ').'Tidak menambah/menghapus residu lama: '.$suite.PHP_EOL;if(!$ok)$fail++;
}
echo json_encode(['orphan_before'=>$before,'orphan_after'=>$count(),'database'=>'database uji saja']).PHP_EOL;
exit($fail?1:0);
