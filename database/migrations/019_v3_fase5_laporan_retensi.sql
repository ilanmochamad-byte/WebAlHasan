-- Fase 5: indeks aditif. Tidak mengubah data atau migrasi sebelumnya.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_publikasi_pratinjau' AND INDEX_NAME='v3_preview_retensi')=0,'ALTER TABLE v3_publikasi_pratinjau ADD INDEX v3_preview_retensi (expires_at,id)','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_pelanggaran' AND INDEX_NAME='v3_laporan_periode')=0,'ALTER TABLE v3_pelanggaran ADD INDEX v3_laporan_periode (archived_at,waktu_kejadian,id)','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_kasus' AND INDEX_NAME='v3_laporan_kasus')=0,'ALTER TABLE v3_konseling_kasus ADD INDEX v3_laporan_kasus (archived_at,dibuka_pada,id)','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
