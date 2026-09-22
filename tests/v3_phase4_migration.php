<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$r=new App\V3\KonselingRepository(app_db());$m=new App\Database\Migrator(app_db(),APP_ROOT.'/database/migrations',APP_ROOT.'/database/rollbacks');$fail=0;
$check=static function($ok,$label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
if($r->one('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1')['migration']!=='018_v3_fase4_publikasi.sql')exit(2);
$manifest=function()use($r){$hash=[];foreach($r->all('SHOW TABLES') as $row){$name=array_values($row)[0];if($name==='schema_migrations')continue;if(!preg_match('/^[a-zA-Z0-9_]+$/',$name))throw new RuntimeException('Invalid table');$rows=array_map(fn($r)=>hash('sha256',json_encode($r)),$r->all('SELECT * FROM `'.$name.'`'));sort($rows);$hash[$name]=hash('sha256',implode('',$rows));}ksort($hash);return $hash;};
$before=$manifest();$check($m->up()===[],'Migrasi ulang idempoten');$check($m->rollbackLast()==='018_v3_fase4_publikasi.sql','Rollback 018 berjalan');$check($manifest()===$before,'Rollback mempertahankan hash seluruh baris semua tabel bisnis');$check($m->up()===['018_v3_fase4_publikasi.sql'],'Migrasi 018 kembali terpasang');$check($manifest()===$before,'Migrasi ulang mempertahankan semua data lama dan publikasi');$check($m->up()===[],'Migrasi ulang kedua idempoten');exit($fail?1:0);
