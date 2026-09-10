-- Utamakan rollback kode. Rollback skema ini hanya melepas tambahan Fase 3;
-- kasus, sesi, tautan, pelanggaran, poin, dan catatan bisnis tidak dihapus.

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_tautan'
        AND INDEX_NAME='tautan_unik_efektif') > 0,
    'ALTER TABLE v3_konseling_tautan DROP INDEX tautan_unik_efektif',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_tautan'
        AND COLUMN_NAME='sesi_unik_guard') > 0,
    'ALTER TABLE v3_konseling_tautan DROP COLUMN sesi_unik_guard',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi'
        AND CONSTRAINT_NAME='rekomendasi_kasus_fk') > 0,
    'ALTER TABLE v3_rekomendasi DROP FOREIGN KEY rekomendasi_kasus_fk',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi'
        AND INDEX_NAME='rekomendasi_tindak_lanjut_index') > 0,
    'ALTER TABLE v3_rekomendasi DROP INDEX rekomendasi_tindak_lanjut_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi'
        AND COLUMN_NAME='ditindaklanjuti_pada') > 0,
    'ALTER TABLE v3_rekomendasi DROP COLUMN ditindaklanjuti_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi'
        AND COLUMN_NAME='ditindaklanjuti_kasus_id') > 0,
    'ALTER TABLE v3_rekomendasi DROP COLUMN ditindaklanjuti_kasus_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi'
        AND INDEX_NAME='revisi_dari_id') = 0,
    'ALTER TABLE v3_konseling_sesi ADD KEY revisi_dari_id (revisi_dari_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- MariaDB dapat memakai indeks unik Fase 3 sebagai indeks pendukung foreign
-- key lalu membuang indeks implisit lama. Sediakan indeks biasa lebih dahulu
-- agar foreign key revisi tetap valid ketika indeks unik dilepas.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi'
        AND INDEX_NAME='sesi_satu_revisi') > 0,
    'ALTER TABLE v3_konseling_sesi DROP INDEX sesi_satu_revisi',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi'
        AND COLUMN_NAME='alasan_pembatalan') > 0,
    'ALTER TABLE v3_konseling_sesi DROP COLUMN alasan_pembatalan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi'
        AND COLUMN_NAME='alasan_penjadwalan_ulang') > 0,
    'ALTER TABLE v3_konseling_sesi DROP COLUMN alasan_penjadwalan_ulang',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_kasus'
        AND COLUMN_NAME='alasan_pembatalan') > 0,
    'ALTER TABLE v3_konseling_kasus DROP COLUMN alasan_pembatalan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_kasus'
        AND COLUMN_NAME='alasan_revisi_terakhir') > 0,
    'ALTER TABLE v3_konseling_kasus DROP COLUMN alasan_revisi_terakhir',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
