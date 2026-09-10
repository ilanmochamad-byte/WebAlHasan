<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$repo=new App\V3\KonselingRepository(app_db());$fail=0;$check=static function(bool $ok,string $label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};$count=static fn(string $sql,array $p=[]):int=>(int)array_values($repo->one($sql,$p)??[0])[0];
$latest=(string)$repo->one('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1')['migration'];if($latest!=='016_v3_fase3_konseling.sql'){echo "Urutan migrasi tidak aman untuk drill Fase 3.\n";exit(2);}
$preserved=[];foreach(['v3_konseling_kasus','v3_konseling_sesi','v3_konseling_tautan','v3_rekomendasi','v3_pelanggaran','v3_poin_ledger'] as $table)$preserved[$table]=$count('SELECT COUNT(*) FROM `'.$table.'`');
$migrator=new App\Database\Migrator(app_db(),APP_ROOT.'/database/migrations',APP_ROOT.'/database/rollbacks');$check($migrator->up()===[],'Runner ulang tidak menggandakan migrasi 016');$check($migrator->rollbackLast()==='016_v3_fase3_konseling.sql','Rollback 016 berhasil');
$check($count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_kasus' AND COLUMN_NAME IN ('alasan_revisi_terakhir','alasan_pembatalan')")===0,'Rollback melepas metadata kasus Fase 3');
$check($count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi' AND COLUMN_NAME IN ('alasan_penjadwalan_ulang','alasan_pembatalan')")===0,'Rollback melepas metadata sesi Fase 3');
$check($count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_tautan' AND COLUMN_NAME='sesi_unik_guard'")===0,'Rollback melepas guard tautan Fase 3');
$check($count("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi' AND INDEX_NAME='revisi_dari_id'")>0,'Rollback memulihkan indeks penyangga foreign key revisi');
$unchanged=true;foreach($preserved as $table=>$before)if($count('SELECT COUNT(*) FROM `'.$table.'`')!==$before)$unchanged=false;$check($unchanged,'Rollback tidak menghapus kasus, sesi, tautan, rekomendasi, pelanggaran, atau ledger');
$check($migrator->up()===['016_v3_fase3_konseling.sql'],'Migrasi 016 terpasang kembali');$check($migrator->up()===[],'Pemasangan ulang tetap idempoten');
$check($count("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi' AND INDEX_NAME='sesi_satu_revisi'")>0,'Penjaga satu revisi sesi tersedia kembali');
$check($count("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_tautan' AND INDEX_NAME='tautan_unik_efektif'")>0,'Penjaga tautan efektif tersedia kembali');
$check($count("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi' AND INDEX_NAME='sesi_revisi_fk'")===0,'Pemasangan ulang tidak meninggalkan indeks rollback redundan');
$check($count("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi' AND CONSTRAINT_NAME='rekomendasi_kasus_fk'")===1,'Foreign key rekomendasi ke kasus tersedia kembali');
exit($fail?1:0);
