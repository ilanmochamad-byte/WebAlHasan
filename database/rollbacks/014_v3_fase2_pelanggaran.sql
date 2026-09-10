DROP TABLE IF EXISTS v3_rekomendasi;
DROP TABLE IF EXISTS v3_poin_agregat;

-- MariaDB dapat membuang indeks implisit foreign key ketika indeks UNIQUE
-- pengganti ditambahkan. Sediakan kembali indeks biasa sebelum melepas
-- pengaman revisi milik Fase 2.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_pelanggaran'
        AND INDEX_NAME='pelanggaran_revisi_fk') = 0,
    'ALTER TABLE v3_pelanggaran ADD INDEX pelanggaran_revisi_fk (revisi_dari_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_pelanggaran'
        AND INDEX_NAME='pelanggaran_satu_revisi') > 0,
    'ALTER TABLE v3_pelanggaran DROP INDEX pelanggaran_satu_revisi',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
