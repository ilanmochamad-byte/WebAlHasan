-- Rollback redesain website Tahap 6 (migrasi 023).
-- Hanya untuk staging atau pemulihan terencana. Buat backup terverifikasi dulu.
--
-- Melepas kolom `kamar.asrama` beserta indeksnya dan tabel `wali_bukan_ganda`.
--
-- Yang TIDAK disentuh: seluruh baris `kamar`, `wali`, `santri_wali`,
-- penempatan (`plotting_kamar`, `plotting_kelas`), dan `audit_logs`.
--
-- CATATAN KEHILANGAN DATA YANG DISENGAJA:
--   - label asrama yang sudah diisi admin hilang; kamar kembali tanpa asrama;
--   - tanda "bukan ganda" hilang, sehingga pasangan itu muncul lagi sebagai
--     kemungkinan ganda di halaman Rekonsiliasi wali.
-- Jejak setiap pengisian asrama, penempatan beralasan, dan tanda "bukan
-- ganda" tetap ada di `audit_logs` (aksi master.update kamar,
-- penempatan.kamar.*, master.wali.bukan_ganda*).
--
-- Kode aplikasi Tahap 6 tetap berjalan setelah rollback ini: label asrama,
-- peringatan asrama, dan tombol "Bukan ganda" tidak ditampilkan.

DROP TABLE IF EXISTS wali_bukan_ganda;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kamar' AND INDEX_NAME = 'kamar_asrama_index') = 1,
    'ALTER TABLE kamar DROP INDEX kamar_asrama_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kamar' AND COLUMN_NAME = 'asrama') = 1,
    'ALTER TABLE kamar DROP COLUMN asrama',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
