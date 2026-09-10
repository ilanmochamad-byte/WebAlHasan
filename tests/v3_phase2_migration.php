<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$repo=new App\V3\PelanggaranRepository(app_db());$fail=0;$check=static function(bool $ok,string $label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
$count=static fn(string $table):int=>(int)$repo->one('SELECT COUNT(*) n FROM `'.$table.'`')['n'];
$latest=(string)$repo->one('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1')['migration'];
if($latest!=='014_v3_fase2_pelanggaran.sql'){echo "Urutan migrasi tidak aman untuk drill Fase 2.\n";exit(2);}
$preserved=[];foreach(['v3_kategori','v3_katalog','v3_ambang','v3_pelanggaran','v3_poin_ledger','v3_murobi_catatan','v3_idempotency','pelanggaran'] as $table){$preserved[$table]=$count($table);}
$migrator=new App\Database\Migrator(app_db(),APP_ROOT.'/database/migrations',APP_ROOT.'/database/rollbacks');
$check($migrator->up()===[],'Runner ulang tidak menggandakan migrasi 014');
$check($migrator->rollbackLast()==='014_v3_fase2_pelanggaran.sql','Rollback 014 berhasil');
$check($repo->one("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('v3_poin_agregat','v3_rekomendasi')")['n']==0,'Rollback hanya melepas dua tabel Fase 2');
$check($repo->one("SELECT COUNT(*) n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_pelanggaran' AND INDEX_NAME='pelanggaran_satu_revisi'")['n']==0,'Rollback melepas indeks revisi Fase 2');
$unchanged=true;foreach($preserved as $table=>$before){if($count($table)!==$before)$unchanged=false;}$check($unchanged,'Tabel Fase 1 dan data warisan tetap utuh setelah rollback');
$check($migrator->up()===['014_v3_fase2_pelanggaran.sql'],'Migrasi 014 terpasang kembali');
$check($migrator->up()===[],'Runner sesudah pemasangan ulang idempoten');
$check($repo->one("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('v3_poin_agregat','v3_rekomendasi')")['n']==2,'Dua tabel Fase 2 tersedia kembali');
$check($repo->one("SELECT COUNT(*) n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_pelanggaran' AND INDEX_NAME='pelanggaran_satu_revisi'")['n']>0,'Indeks satu revisi tersedia kembali');
exit($fail?1:0);
