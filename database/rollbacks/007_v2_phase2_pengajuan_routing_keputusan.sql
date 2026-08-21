-- Rollback V2 Fase 2 hanya untuk staging atau pemulihan terencana.
-- Buat backup terverifikasi terlebih dahulu (bin/v2_phase2_preflight.php).
--
-- Rollback ini melepas KOLOM DAN TABEL JEJAK Fase 2 saja:
--   - tabel `izin_keputusan_koreksi` beserta isinya,
--   - kolom jejak routing/penetapan/pembatalan pada `izin_pengajuan`,
--   - kolom jejak koreksi pada `izin_keputusan`,
--   - indeks antrean dan indeks tumpang tindih.
--
-- Yang TIDAK disentuh: tabel `perizinan` lama, baris `izin_pengajuan`,
-- `izin_keputusan`, `izin_riwayat_status`, dan `izin_idempotency_keys`. Pengajuan
-- serta keputusan yang sudah dibuat pada Fase 2 tetap utuh; hanya kolom jejak
-- tambahan yang hilang. Riwayat status (kronologi lengkap) tetap ada.
--
-- Seluruh pernyataan dibungkus pemeriksaan INFORMATION_SCHEMA sehingga rollback
-- aman dijalankan ulang.

DROP TABLE IF EXISTS izin_keputusan_koreksi;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND CONSTRAINT_NAME = 'izin_pengajuan_penetap_fk') > 0,
    'ALTER TABLE izin_pengajuan DROP FOREIGN KEY izin_pengajuan_penetap_fk',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND CONSTRAINT_NAME = 'izin_pengajuan_pembatal_fk') > 0,
    'ALTER TABLE izin_pengajuan DROP FOREIGN KEY izin_pengajuan_pembatal_fk',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND INDEX_NAME = 'izin_pengajuan_antrean_index') > 0,
    'ALTER TABLE izin_pengajuan DROP INDEX izin_pengajuan_antrean_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND INDEX_NAME = 'izin_pengajuan_overlap_index') > 0,
    'ALTER TABLE izin_pengajuan DROP INDEX izin_pengajuan_overlap_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND COLUMN_NAME IN ('routing_kandidat','routing_catatan','routing_pada',
                            'murobi_ditetapkan_oleh_user_id','murobi_ditetapkan_pada',
                            'dibatalkan_oleh_user_id','dibatalkan_pada','alasan_pembatalan')) = 8,
    'ALTER TABLE izin_pengajuan
        DROP COLUMN alasan_pembatalan,
        DROP COLUMN dibatalkan_pada,
        DROP COLUMN dibatalkan_oleh_user_id,
        DROP COLUMN murobi_ditetapkan_pada,
        DROP COLUMN murobi_ditetapkan_oleh_user_id,
        DROP COLUMN routing_pada,
        DROP COLUMN routing_catatan,
        DROP COLUMN routing_kandidat',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_keputusan'
        AND COLUMN_NAME IN ('dikoreksi_pada','jumlah_koreksi')) = 2,
    'ALTER TABLE izin_keputusan DROP COLUMN jumlah_koreksi, DROP COLUMN dikoreksi_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
