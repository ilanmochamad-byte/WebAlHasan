-- Rollback 015. Hanya melepas struktur yang ditambahkan migrasi 015.
--
-- Tidak memulihkan nilai `fingerprint` yang dilepas maupun `alasan_revisi`
-- yang dipindahkan. Fingerprint adalah turunan murni dari kolom bisnis yang
-- tetap tersimpan sehingga dapat dihitung ulang kapan saja, dan ketiadaannya
-- hanya melonggarkan penolakan duplikat untuk catatan yang memang sudah tidak
-- berlaku. Alasan pembatalan ikut terbuang bersama kolomnya; lakukan backup
-- sebelum rollback skema, sesuai panduan docs/phase-v3-2/migrasi-dan-rollback.md.

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi'
        AND INDEX_NAME='rekomendasi_berlaku_index') > 0,
    'ALTER TABLE v3_rekomendasi DROP INDEX rekomendasi_berlaku_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi'
        AND COLUMN_NAME='tidak_berlaku_alasan') > 0,
    'ALTER TABLE v3_rekomendasi DROP COLUMN tidak_berlaku_alasan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi'
        AND COLUMN_NAME='tidak_berlaku_pada') > 0,
    'ALTER TABLE v3_rekomendasi DROP COLUMN tidak_berlaku_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_pelanggaran'
        AND COLUMN_NAME='alasan_pembatalan') > 0,
    'ALTER TABLE v3_pelanggaran DROP COLUMN alasan_pembatalan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
