<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$repo=new App\V3\PelanggaranRepository(app_db());$fail=0;$check=static function(bool $ok,string $label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
$count=static fn(string $table):int=>(int)$repo->one('SELECT COUNT(*) n FROM `'.$table.'`')['n'];
$structure=static fn(string $sql,array $params=[]):int=>(int)array_values($repo->one($sql,$params)??[0])[0];
$latest=(string)$repo->one('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1')['migration'];
if($latest!=='015_v3_fase2_koreksi_dan_rekomendasi.sql'){echo "Urutan migrasi tidak aman untuk drill Fase 2.\n";exit(2);}
$preserved=[];foreach(['v3_kategori','v3_katalog','v3_ambang','v3_pelanggaran','v3_poin_ledger','v3_murobi_catatan','v3_idempotency','pelanggaran'] as $table){$preserved[$table]=$count($table);}
// Total ledger adalah sumber kebenaran poin; agregat wajib dapat dipulihkan darinya.
$ledgerSubjects=(int)$repo->one('SELECT COUNT(*) n FROM (SELECT santri_id FROM v3_poin_ledger WHERE archived_at IS NULL GROUP BY santri_id,tahun_ajaran_id) x')['n'];
$migrator=new App\Database\Migrator(app_db(),APP_ROOT.'/database/migrations',APP_ROOT.'/database/rollbacks');

$check($migrator->up()===[],'Runner ulang tidak menggandakan migrasi 014/015');

// --- Rollback 015 -----------------------------------------------------------
$check($migrator->rollbackLast()==='015_v3_fase2_koreksi_dan_rekomendasi.sql','Rollback 015 berhasil');
$check($structure("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_pelanggaran' AND COLUMN_NAME='alasan_pembatalan'")===0,'Rollback 015 melepas kolom alasan pembatalan');
$check($structure("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi' AND COLUMN_NAME IN ('tidak_berlaku_pada','tidak_berlaku_alasan')")===0,'Rollback 015 melepas penanda masa berlaku rekomendasi');
$check($structure("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_pelanggaran' AND COLUMN_NAME='fingerprint'")===1,'Rollback 015 tidak menyentuh kolom fingerprint');

// --- Rollback 014 -----------------------------------------------------------
$check($migrator->rollbackLast()==='014_v3_fase2_pelanggaran.sql','Rollback 014 berhasil');
$check($repo->one("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('v3_poin_agregat','v3_rekomendasi')")['n']==0,'Rollback hanya melepas dua tabel Fase 2');
$check($repo->one("SELECT COUNT(*) n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_pelanggaran' AND INDEX_NAME='pelanggaran_satu_revisi'")['n']==0,'Rollback melepas indeks revisi Fase 2');
$unchanged=true;foreach($preserved as $table=>$before){if($count($table)!==$before)$unchanged=false;}$check($unchanged,'Tabel Fase 1 dan data warisan tetap utuh setelah rollback');

// --- Pasang ulang ------------------------------------------------------------
$check($migrator->up()===['014_v3_fase2_pelanggaran.sql','015_v3_fase2_koreksi_dan_rekomendasi.sql'],'Migrasi 014 dan 015 terpasang kembali berurutan');
$check($migrator->up()===[],'Runner sesudah pemasangan ulang idempoten');
$check($repo->one("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('v3_poin_agregat','v3_rekomendasi')")['n']==2,'Dua tabel Fase 2 tersedia kembali');
$check($repo->one("SELECT COUNT(*) n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_pelanggaran' AND INDEX_NAME='pelanggaran_satu_revisi'")['n']>0,'Indeks satu revisi tersedia kembali');
$check($structure("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_pelanggaran' AND COLUMN_NAME='alasan_pembatalan'")===1,'Kolom alasan pembatalan tersedia kembali');
$check($structure("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi' AND COLUMN_NAME IN ('tidak_berlaku_pada','tidak_berlaku_alasan')")===2,'Penanda masa berlaku rekomendasi tersedia kembali');

// --- Agregat swa-pulih -------------------------------------------------------
// Rollback 014 membuang seluruh isi v3_poin_agregat sementara ledger utuh.
// Backfill migrasi 015 harus mengembalikan setiap subjek tanpa mutasi baru.
$check($count('v3_poin_agregat')===$ledgerSubjects,'Backfill 015 memulihkan agregat untuk seluruh subjek ledger');
$check($structure('SELECT COUNT(*) FROM (SELECT l.santri_id,l.tahun_ajaran_id,COALESCE(SUM(l.perubahan_poin),0) total FROM v3_poin_ledger l WHERE l.archived_at IS NULL GROUP BY l.santri_id,l.tahun_ajaran_id) x LEFT JOIN v3_poin_agregat a ON a.santri_id=x.santri_id AND a.tahun_ajaran_id=x.tahun_ajaran_id WHERE a.id IS NULL OR a.total_poin<>x.total')===0,'Agregat hasil pemasangan ulang tidak berselisih dengan ledger');
$check($structure("SELECT COUNT(*) FROM v3_pelanggaran p WHERE p.fingerprint IS NOT NULL AND (p.status='Dibatalkan' OR EXISTS (SELECT 1 FROM (SELECT revisi_dari_id FROM v3_pelanggaran WHERE revisi_dari_id IS NOT NULL) x WHERE x.revisi_dari_id=p.id))")===0,'Backfill 015 melepas fingerprint catatan yang tidak berlaku');
exit($fail?1:0);
