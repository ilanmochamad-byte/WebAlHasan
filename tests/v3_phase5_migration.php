<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$r=new App\V3\KonselingRepository(app_db());$m=new App\Database\Migrator(app_db(),APP_ROOT.'/database/migrations',APP_ROOT.'/database/rollbacks');$fail=0;
$check=static function($ok,$label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
if($r->one('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1')['migration']!=='019_v3_fase5_laporan_retensi.sql')exit(2);
$manifest=function()use($r){$hash=[];foreach($r->all('SHOW TABLES') as $row){$name=array_values($row)[0];if($name==='schema_migrations')continue;if(!preg_match('/^\w+$/D',$name))throw new RuntimeException('Invalid table');$rows=array_map(fn($r)=>hash('sha256',json_encode($r)),$r->all('SELECT * FROM `'.$name.'`'));sort($rows);$hash[$name]=hash('sha256',implode('',$rows));}ksort($hash);return $hash;};
$before=$manifest();$check($m->up()===[],'019 ulang idempoten');$check($m->rollbackLast()==='019_v3_fase5_laporan_retensi.sql','Rollback 019');$check($manifest()===$before,'Rollback tidak mengubah seluruh baris bisnis');$check($m->up()===['019_v3_fase5_laporan_retensi.sql'],'Migrasi ulang 019');$check($manifest()===$before,'Migrasi ulang tidak mengubah data');$check($m->up()===[],'019 ulang kedua idempoten');exit($fail?1:0);
