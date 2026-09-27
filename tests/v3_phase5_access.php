<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$r=new App\V3\KonselingRepository(app_db());$fail=0;
$check=static function($ok,$label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
foreach(['sbx_ortu_a','sbx_ortu_b'] as $name){
    $u=$r->one('SELECT id,wali_id FROM users WHERE username=?',[$name]);
    try{v3_pelanggaran_service()->options($u);$check(false,'Options internal menolak wali');}
    catch(App\V3\V3Exception $e){$check($e->status===403,'Options internal menolak wali tanpa katalog');}
}
foreach(['sbx_admin','sbx_pengurus_a','sbx_murobi_a'] as $name){
    $u=$r->one('SELECT id FROM users WHERE username=?',[$name]);
    $check(v3_pelanggaran_service()->options($u)['katalog']!==[],'Options tetap tersedia bagi petugas berhak: '.$name);
}
exit($fail?1:0);
