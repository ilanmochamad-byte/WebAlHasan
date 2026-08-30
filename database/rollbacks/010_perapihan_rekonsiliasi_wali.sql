-- Rollback paket perapihan V1–V2 — Koreksi ke-2 (rekonsiliasi wali).
-- Hanya untuk staging atau pemulihan terencana. Buat backup terverifikasi dulu.
--
-- Migrasi 010 hanya menambahkan satu kolom penanda dan dua indeks pada tabel
-- `wali`. Rollback ini melepasnya kembali.
--
-- Yang TIDAK disentuh sama sekali:
--   - seluruh baris `wali` dan `santri_wali`, termasuk identitas yang sudah
--     digabungkan dan relasi yang sudah diarsipkan;
--   - kolom lama `santri.nama_ayah`, `no_hp_ayah`, `nama_ibu`, `no_hp_ibu`;
--   - `audit_logs`, sehingga jejak setiap penggabungan yang pernah dikonfirmasi
--     admin TETAP ADA meskipun kolom penandanya dilepas.
--
-- CATATAN KEHILANGAN DATA YANG DISENGAJA: setelah rollback, penanda "identitas
-- ini digabungkan ke wali #X" hilang dari skema. Wali sumber tetap ada dengan
-- ID aslinya dan tetap berstatus arsip; hubungan penggabungannya hanya dapat
-- ditelusuri lewat `audit_logs`.

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wali' AND INDEX_NAME = 'wali_merged_into_index') = 1,
    'ALTER TABLE wali DROP INDEX wali_merged_into_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wali' AND INDEX_NAME = 'wali_no_hp_index') = 1,
    'ALTER TABLE wali DROP INDEX wali_no_hp_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wali' AND COLUMN_NAME = 'merged_into_wali_id') = 1,
    'ALTER TABLE wali DROP COLUMN merged_into_wali_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
