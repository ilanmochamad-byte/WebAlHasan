-- Rollback "Fondasi Penugasan dan Hak Akses Lintas PRD V3–V6" (migrasi 012).
--
-- Hanya untuk staging atau pemulihan terencana. Buat backup terverifikasi dulu.
--
-- ===========================================================================
-- PERINGATAN KEHILANGAN DATA YANG DISENGAJA
-- ===========================================================================
-- Rollback ini MENGHAPUS lima tabel penugasan baru dan master `mata_pelajaran`
-- BESERTA SELURUH ISINYA:
--
--   * guru_mapel_assignments
--   * pendidikan_assignments
--   * bendahara_bulanan_assignments
--   * panitia_psb_assignments
--   * bendahara_psb_assignments
--   * mata_pelajaran
--
-- Seluruh penugasan V3–V6 yang pernah dibuat admin HILANG. Jejak siapa membuat
-- dan mengakhiri penugasan tetap tersedia pada `audit_logs` (aksi
-- `penugasan.*`) — tabel itu TIDAK disentuh rollback ini.
--
-- Pada `murobi_assignments` dan `pembimbing_assignments`, rollback melepas
-- kolom jejak tambahan (`catatan`, `updated_by`, `diakhiri_pada`,
-- `diakhiri_oleh`, `alasan_pengakhiran`) beserta kunci asingnya. BARIS
-- penugasan murobi/pembimbing sendiri TIDAK dihapus dan tidak diubah nilainya;
-- pengakhiran yang dilakukan lewat pusat penugasan tetap berlaku karena
-- `tanggal_selesai` adalah kolom lama.
--
-- ===========================================================================
-- PEMERIKSAAN WAJIB SEBELUM ROLLBACK
-- ===========================================================================
-- Simpan dahulu isi tabel yang akan hilang bila masih dibutuhkan:
--
--   SELECT COUNT(*) FROM guru_mapel_assignments;
--   SELECT COUNT(*) FROM pendidikan_assignments;
--   SELECT COUNT(*) FROM bendahara_bulanan_assignments;
--   SELECT COUNT(*) FROM panitia_psb_assignments;
--   SELECT COUNT(*) FROM bendahara_psb_assignments;
--   SELECT COUNT(*) FROM mata_pelajaran;
--
-- Bila salah satu tidak nol, ekspor tabelnya (mysqldump) sebelum melanjutkan.
--
-- Urutan pemulihan yang benar:
--   1. kembalikan kode ke commit sebelum paket ini;
--   2. baru jalankan `php bin/migrate.php rollback`.

-- ---------------------------------------------------------------------------
-- 1. Tabel penugasan baru (yang merujuk mata_pelajaran dilepas lebih dahulu).
DROP TABLE IF EXISTS guru_mapel_assignments;
DROP TABLE IF EXISTS pendidikan_assignments;
DROP TABLE IF EXISTS bendahara_bulanan_assignments;
DROP TABLE IF EXISTS panitia_psb_assignments;
DROP TABLE IF EXISTS bendahara_psb_assignments;
DROP TABLE IF EXISTS mata_pelajaran;

-- ---------------------------------------------------------------------------
-- 2. Kunci asing tambahan pada tabel lama.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'murobi_assignments' AND CONSTRAINT_NAME = 'murobi_ender_fk') = 1,
    'ALTER TABLE murobi_assignments DROP FOREIGN KEY murobi_ender_fk',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'murobi_assignments' AND CONSTRAINT_NAME = 'murobi_updater_fk') = 1,
    'ALTER TABLE murobi_assignments DROP FOREIGN KEY murobi_updater_fk',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pembimbing_assignments' AND CONSTRAINT_NAME = 'pembimbing_ender_fk') = 1,
    'ALTER TABLE pembimbing_assignments DROP FOREIGN KEY pembimbing_ender_fk',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pembimbing_assignments' AND CONSTRAINT_NAME = 'pembimbing_updater_fk') = 1,
    'ALTER TABLE pembimbing_assignments DROP FOREIGN KEY pembimbing_updater_fk',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. Kolom jejak tambahan pada tabel lama. Baris tidak disentuh.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'murobi_assignments' AND COLUMN_NAME = 'alasan_pengakhiran') = 1,
    'ALTER TABLE murobi_assignments DROP COLUMN alasan_pengakhiran', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'murobi_assignments' AND COLUMN_NAME = 'diakhiri_oleh') = 1,
    'ALTER TABLE murobi_assignments DROP COLUMN diakhiri_oleh', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'murobi_assignments' AND COLUMN_NAME = 'diakhiri_pada') = 1,
    'ALTER TABLE murobi_assignments DROP COLUMN diakhiri_pada', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'murobi_assignments' AND COLUMN_NAME = 'updated_by') = 1,
    'ALTER TABLE murobi_assignments DROP COLUMN updated_by', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'murobi_assignments' AND COLUMN_NAME = 'catatan') = 1,
    'ALTER TABLE murobi_assignments DROP COLUMN catatan', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pembimbing_assignments' AND COLUMN_NAME = 'alasan_pengakhiran') = 1,
    'ALTER TABLE pembimbing_assignments DROP COLUMN alasan_pengakhiran', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pembimbing_assignments' AND COLUMN_NAME = 'diakhiri_oleh') = 1,
    'ALTER TABLE pembimbing_assignments DROP COLUMN diakhiri_oleh', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pembimbing_assignments' AND COLUMN_NAME = 'diakhiri_pada') = 1,
    'ALTER TABLE pembimbing_assignments DROP COLUMN diakhiri_pada', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pembimbing_assignments' AND COLUMN_NAME = 'updated_by') = 1,
    'ALTER TABLE pembimbing_assignments DROP COLUMN updated_by', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pembimbing_assignments' AND COLUMN_NAME = 'catatan') = 1,
    'ALTER TABLE pembimbing_assignments DROP COLUMN catatan', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
