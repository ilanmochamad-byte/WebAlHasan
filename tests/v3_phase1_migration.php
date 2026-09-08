<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$r=new \App\V3\KatalogRepository(app_db());$fail=0;
$check=static function(bool $ok,string $label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
$manifest=static function()use($r):array{
    $data=[];foreach($r->rows('SHOW TABLES') as $row){$t=array_values($row)[0];if(str_starts_with($t,'v3_')||$t==='schema_migrations')continue;if(!preg_match('/^[a-zA-Z0-9_]+$/D',$t))throw new RuntimeException('Nama tabel tidak valid');$rows=$r->rows('SELECT * FROM `'.$t.'`');$hashes=array_map(static fn($x)=>hash('sha256',json_encode($x)), $rows);sort($hashes);$data[$t]=hash('sha256',implode('',$hashes));}ksort($data);return $data;
};
$santri=(int)$r->rows('SELECT id FROM santri ORDER BY id LIMIT 1')[0]['id'];
$legacyTag='V3-MIGRATION-'.bin2hex(random_bytes(5));
$r->execute('INSERT INTO pelanggaran(id_santri,tgl_pelanggaran,jenis_pelanggaran,poin,hukuman) VALUES(?,CURDATE(),?,3,?)',[$santri,$legacyTag,'Fixture warisan, bukan keputusan hukuman']);
$before=$manifest();$m=new \App\Database\Migrator(app_db(),APP_ROOT.'/database/migrations',APP_ROOT.'/database/rollbacks');
$latest=$r->rows('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1')[0]['migration'];
if($latest!=='013_v3_fase1.sql') {echo "Urutan migrasi tidak aman untuk drill.\n";exit(2);}
$check($m->up()===[],'Runner ulang tidak menggandakan migrasi');
$check($m->rollbackLast()==='013_v3_fase1.sql','Rollback 013 berhasil');
$check($r->rows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'v3\\_%'")===[],'Seluruh tabel V3 dilepas pada rollback');
$check($manifest()===$before,'Isi seluruh tabel lama identik setelah rollback');
$check($m->up()===['013_v3_fase1.sql'],'Migrasi setelah rollback berhasil');
$check($m->up()===[],'Runner setelah pemasangan ulang idempoten');
$check($manifest()===$before,'Isi seluruh tabel lama identik setelah migrasi ulang');
exit($fail?1:0);
