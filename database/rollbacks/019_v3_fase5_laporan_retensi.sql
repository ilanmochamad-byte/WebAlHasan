-- Rollback hanya indeks Fase 5; semua data tetap utuh.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_publikasi_pratinjau' AND INDEX_NAME='v3_preview_retensi')>0,'ALTER TABLE v3_publikasi_pratinjau DROP INDEX v3_preview_retensi','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_pelanggaran' AND INDEX_NAME='v3_laporan_periode')>0,'ALTER TABLE v3_pelanggaran DROP INDEX v3_laporan_periode','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_kasus' AND INDEX_NAME='v3_laporan_kasus')>0,'ALTER TABLE v3_konseling_kasus DROP INDEX v3_laporan_kasus','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
