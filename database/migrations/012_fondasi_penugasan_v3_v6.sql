-- Fondasi Penugasan dan Hak Akses Lintas PRD V3–V6 — migrasi 012
--
-- Keputusan pengguna 7 September 2026. Branch `feat/fondasi-penugasan-v3-v6`.
--
-- Sifat migrasi: ADITIF dan DAPAT DIJALANKAN ULANG (idempoten). Tidak ada
-- DROP TABLE, DROP COLUMN, DELETE, UPDATE, atau TRUNCATE terhadap data apa
-- pun. Tidak ada baris penugasan yang diisi berdasarkan tebakan: seluruh tabel
-- baru dibuat KOSONG dan hanya diisi admin melalui pusat penugasan.
--
-- YANG DIBANGUN
-- -------------
-- Penugasan fungsional untuk PRD V3–V6 sebagai PENUGASAN yang menghasilkan
-- capability — bukan role login baru. Role dasar tetap `admin`, `guru`,
-- `pengurus`, dan `orang_tua`; tabel `roles` TIDAK ditambah.
--
--   mata_pelajaran                 master minimum mata pelajaran (untuk V4)
--   guru_mapel_assignments         guru pengampu mapel per kelas + tahun ajaran (V4)
--   pendidikan_assignments         pengurus sebagai Bagian Pendidikan (V4)
--   bendahara_bulanan_assignments  pengurus sebagai bendahara pembiayaan bulanan (V5)
--   panitia_psb_assignments        pengurus sebagai panitia PSB (V6)
--   bendahara_psb_assignments      pengurus sebagai bendahara PSB, TERPISAH dari panitia (V6)
--
-- Tabel lama `murobi_assignments` (002) dan `pembimbing_assignments` (006)
-- DIPERTAHANKAN dan hanya ditambah kolom jejak perubahan agar dapat dikelola
-- dari pusat penugasan yang sama: `catatan`, `updated_by`, `diakhiri_pada`,
-- `diakhiri_oleh`, `alasan_pengakhiran`. Tidak ada kolom lama yang diubah.
--
-- MENGAPA TABEL PER DOMAIN, BUKAN SATU TABEL GENERIK
-- ---------------------------------------------------
-- Satu tabel generik ber-`target_type`/`target_id` tidak dapat memasang kunci
-- asing ke `guru`, `pengurus`, `kelas`, `mata_pelajaran`, dan `tahun_ajaran`
-- sekaligus. Tabel per domain memberi kunci asing nyata, CHECK rentang
-- tanggal, dan kunci unik pencegah duplikat yang sesuai bentuk cakupannya.
--
-- MASTER MATA PELAJARAN
-- ---------------------
-- Tabel warisan `mapel` (latin1, tanpa status/arsip/jejak, kosong pada dump
-- produksi, tidak dipakai kode aplikasi mana pun) TIDAK diubah dan TIDAK
-- dihapus. Tabel `mata_pelajaran` baru memakai utf8mb4, status aktif/arsip,
-- kunci unik nama dan kode, serta jejak pelaku — hanya yang benar-benar
-- dibutuhkan penugasan guru; bukan modul penilaian.
--
-- CAKUPAN YANG BELUM PUNYA MASTER
-- -------------------------------
-- * `jenjang` (unit/tingkat) mengikuti nilai bebas `kelas.jenjang` yang sudah
--   ada. NULL berarti seluruh unit.
-- * Gelombang PSB belum mempunyai tabel master pada skema ini; kolom
--   `gelombang` adalah label opsional (NULL = seluruh gelombang tahun itu).
--   Saat PRD V6 membuat master gelombang, migrasi lanjutan dapat menambah
--   `gelombang_id` dan memasangkannya dari label ini — tanpa mengubah baris.
--
-- WAJIB sebelum dijalankan di produksi:
--   1. backup terverifikasi (lihat docs/fondasi-penugasan-v3-v6/migrasi-dan-rollback.md);
--   2. `php bin/penugasan_preflight.php` tidak melaporkan penghalang;
--   3. uji lengkap pada salinan MySQL berakhiran `_test`;
--   4. rollback berpasangan tersedia di database/rollbacks/012_*.sql;
--   5. `php bin/penugasan_verify.php` setelah migrasi.

-- ===========================================================================
-- 1. Master mata pelajaran (minimum).
-- ===========================================================================
CREATE TABLE IF NOT EXISTS mata_pelajaran (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kode VARCHAR(20) NULL,
    nama VARCHAR(100) NOT NULL,
    kategori VARCHAR(30) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    archived_at TIMESTAMP NULL DEFAULT NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    kode_unique_key VARCHAR(20) GENERATED ALWAYS AS (NULLIF(TRIM(kode), '')) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY mata_pelajaran_nama_unique (nama),
    UNIQUE KEY mata_pelajaran_kode_unique (kode_unique_key),
    KEY mata_pelajaran_status_index (is_active, archived_at),
    CONSTRAINT mata_pelajaran_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT mata_pelajaran_updater_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 2. Guru pengampu mata pelajaran (PRD V4).
--    Semester tidak disimpan terpisah: baris `tahun_ajaran` sudah memuat
--    pasangan (tahun, semester) yang unik, sehingga satu referensi cukup dan
--    tidak dapat saling bertentangan.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS guru_mapel_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    guru_id INT NOT NULL,
    mata_pelajaran_id BIGINT UNSIGNED NOT NULL,
    kelas_id INT NOT NULL,
    tahun_ajaran_id INT NOT NULL,
    tanggal_mulai DATE NOT NULL,
    tanggal_selesai DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    catatan VARCHAR(500) NULL,
    alasan_perubahan VARCHAR(500) NULL,
    diakhiri_pada DATETIME NULL,
    diakhiri_oleh BIGINT UNSIGNED NULL,
    alasan_pengakhiran VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY guru_mapel_assignment_unique (guru_id, mata_pelajaran_id, kelas_id, tahun_ajaran_id, tanggal_mulai),
    KEY guru_mapel_guru_status_index (guru_id, is_active, tanggal_mulai, tanggal_selesai),
    KEY guru_mapel_year_status_index (tahun_ajaran_id, is_active),
    KEY guru_mapel_kelas_index (kelas_id),
    KEY guru_mapel_mapel_index (mata_pelajaran_id),
    KEY guru_mapel_diakhiri_oleh_index (diakhiri_oleh),
    KEY guru_mapel_updated_by_index (updated_by),
    CONSTRAINT guru_mapel_guru_fk FOREIGN KEY (guru_id) REFERENCES guru (id),
    CONSTRAINT guru_mapel_mapel_fk FOREIGN KEY (mata_pelajaran_id) REFERENCES mata_pelajaran (id),
    CONSTRAINT guru_mapel_kelas_fk FOREIGN KEY (kelas_id) REFERENCES kelas (id),
    CONSTRAINT guru_mapel_tahun_fk FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran (id),
    CONSTRAINT guru_mapel_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT guru_mapel_updater_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT guru_mapel_ender_fk FOREIGN KEY (diakhiri_oleh) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT guru_mapel_range_check CHECK (tanggal_selesai IS NULL OR tanggal_selesai >= tanggal_mulai)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 3. Bagian Pendidikan (PRD V4). Cakupan `jenjang` NULL = seluruh unit.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS pendidikan_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengurus_id BIGINT UNSIGNED NOT NULL,
    tahun_ajaran_id INT NOT NULL,
    jenjang VARCHAR(20) NULL,
    tanggal_mulai DATE NOT NULL,
    tanggal_selesai DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    catatan VARCHAR(500) NULL,
    alasan_perubahan VARCHAR(500) NULL,
    diakhiri_pada DATETIME NULL,
    diakhiri_oleh BIGINT UNSIGNED NULL,
    alasan_pengakhiran VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    jenjang_key VARCHAR(20) GENERATED ALWAYS AS (COALESCE(jenjang, '*')) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY pendidikan_assignment_unique (pengurus_id, tahun_ajaran_id, jenjang_key, tanggal_mulai),
    KEY pendidikan_pengurus_status_index (pengurus_id, is_active, tanggal_mulai, tanggal_selesai),
    KEY pendidikan_year_status_index (tahun_ajaran_id, is_active),
    KEY pendidikan_diakhiri_oleh_index (diakhiri_oleh),
    KEY pendidikan_updated_by_index (updated_by),
    CONSTRAINT pendidikan_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT pendidikan_tahun_fk FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran (id),
    CONSTRAINT pendidikan_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT pendidikan_updater_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT pendidikan_ender_fk FOREIGN KEY (diakhiri_oleh) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT pendidikan_range_check CHECK (tanggal_selesai IS NULL OR tanggal_selesai >= tanggal_mulai)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 4. Bendahara pembiayaan bulanan (PRD V5). Cakupan `jenjang` NULL = seluruh unit.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS bendahara_bulanan_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengurus_id BIGINT UNSIGNED NOT NULL,
    tahun_ajaran_id INT NOT NULL,
    jenjang VARCHAR(20) NULL,
    tanggal_mulai DATE NOT NULL,
    tanggal_selesai DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    catatan VARCHAR(500) NULL,
    alasan_perubahan VARCHAR(500) NULL,
    diakhiri_pada DATETIME NULL,
    diakhiri_oleh BIGINT UNSIGNED NULL,
    alasan_pengakhiran VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    jenjang_key VARCHAR(20) GENERATED ALWAYS AS (COALESCE(jenjang, '*')) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY bendahara_bulanan_assignment_unique (pengurus_id, tahun_ajaran_id, jenjang_key, tanggal_mulai),
    KEY bendahara_bulanan_pengurus_status_index (pengurus_id, is_active, tanggal_mulai, tanggal_selesai),
    KEY bendahara_bulanan_year_status_index (tahun_ajaran_id, is_active),
    KEY bendahara_bulanan_diakhiri_oleh_index (diakhiri_oleh),
    KEY bendahara_bulanan_updated_by_index (updated_by),
    CONSTRAINT bendahara_bulanan_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT bendahara_bulanan_tahun_fk FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran (id),
    CONSTRAINT bendahara_bulanan_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT bendahara_bulanan_updater_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT bendahara_bulanan_ender_fk FOREIGN KEY (diakhiri_oleh) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT bendahara_bulanan_range_check CHECK (tanggal_selesai IS NULL OR tanggal_selesai >= tanggal_mulai)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 5. Panitia PSB (PRD V6). `tahun_ajaran_id` = tahun ajaran penerimaan; boleh
--    tahun ajaran yang belum aktif karena PSB berjalan sebelum tahun dimulai.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS panitia_psb_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengurus_id BIGINT UNSIGNED NOT NULL,
    tahun_ajaran_id INT NOT NULL,
    gelombang VARCHAR(50) NULL,
    tanggal_mulai DATE NOT NULL,
    tanggal_selesai DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    catatan VARCHAR(500) NULL,
    alasan_perubahan VARCHAR(500) NULL,
    diakhiri_pada DATETIME NULL,
    diakhiri_oleh BIGINT UNSIGNED NULL,
    alasan_pengakhiran VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    gelombang_key VARCHAR(50) GENERATED ALWAYS AS (COALESCE(gelombang, '*')) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY panitia_psb_assignment_unique (pengurus_id, tahun_ajaran_id, gelombang_key, tanggal_mulai),
    KEY panitia_psb_pengurus_status_index (pengurus_id, is_active, tanggal_mulai, tanggal_selesai),
    KEY panitia_psb_year_status_index (tahun_ajaran_id, is_active),
    KEY panitia_psb_diakhiri_oleh_index (diakhiri_oleh),
    KEY panitia_psb_updated_by_index (updated_by),
    CONSTRAINT panitia_psb_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT panitia_psb_tahun_fk FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran (id),
    CONSTRAINT panitia_psb_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT panitia_psb_updater_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT panitia_psb_ender_fk FOREIGN KEY (diakhiri_oleh) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT panitia_psb_range_check CHECK (tanggal_selesai IS NULL OR tanggal_selesai >= tanggal_mulai)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 6. Bendahara PSB (PRD V6). TERPISAH dari panitia PSB: panitia tidak
--    otomatis memperoleh akses keuangan, dan sebaliknya.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS bendahara_psb_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengurus_id BIGINT UNSIGNED NOT NULL,
    tahun_ajaran_id INT NOT NULL,
    gelombang VARCHAR(50) NULL,
    tanggal_mulai DATE NOT NULL,
    tanggal_selesai DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    catatan VARCHAR(500) NULL,
    alasan_perubahan VARCHAR(500) NULL,
    diakhiri_pada DATETIME NULL,
    diakhiri_oleh BIGINT UNSIGNED NULL,
    alasan_pengakhiran VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    gelombang_key VARCHAR(50) GENERATED ALWAYS AS (COALESCE(gelombang, '*')) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY bendahara_psb_assignment_unique (pengurus_id, tahun_ajaran_id, gelombang_key, tanggal_mulai),
    KEY bendahara_psb_pengurus_status_index (pengurus_id, is_active, tanggal_mulai, tanggal_selesai),
    KEY bendahara_psb_year_status_index (tahun_ajaran_id, is_active),
    KEY bendahara_psb_diakhiri_oleh_index (diakhiri_oleh),
    KEY bendahara_psb_updated_by_index (updated_by),
    CONSTRAINT bendahara_psb_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT bendahara_psb_tahun_fk FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran (id),
    CONSTRAINT bendahara_psb_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT bendahara_psb_updater_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT bendahara_psb_ender_fk FOREIGN KEY (diakhiri_oleh) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT bendahara_psb_range_check CHECK (tanggal_selesai IS NULL OR tanggal_selesai >= tanggal_mulai)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 7. Kolom jejak perubahan pada tabel penugasan lama (aditif, dijaga
--    information_schema agar aman dijalankan ulang). Tidak ada kolom lama
--    yang diubah; alur lama tetap menulis kolom yang sama seperti sebelumnya.
-- ===========================================================================

-- 7a. murobi_assignments
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'murobi_assignments' AND COLUMN_NAME = 'catatan') = 0,
    'ALTER TABLE murobi_assignments ADD COLUMN catatan VARCHAR(500) NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'murobi_assignments' AND COLUMN_NAME = 'updated_by') = 0,
    'ALTER TABLE murobi_assignments ADD COLUMN updated_by BIGINT UNSIGNED NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'murobi_assignments' AND COLUMN_NAME = 'diakhiri_pada') = 0,
    'ALTER TABLE murobi_assignments ADD COLUMN diakhiri_pada DATETIME NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'murobi_assignments' AND COLUMN_NAME = 'diakhiri_oleh') = 0,
    'ALTER TABLE murobi_assignments ADD COLUMN diakhiri_oleh BIGINT UNSIGNED NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'murobi_assignments' AND COLUMN_NAME = 'alasan_pengakhiran') = 0,
    'ALTER TABLE murobi_assignments ADD COLUMN alasan_pengakhiran VARCHAR(500) NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'murobi_assignments' AND CONSTRAINT_NAME = 'murobi_updater_fk') = 0,
    'ALTER TABLE murobi_assignments ADD CONSTRAINT murobi_updater_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'murobi_assignments' AND CONSTRAINT_NAME = 'murobi_ender_fk') = 0,
    'ALTER TABLE murobi_assignments ADD CONSTRAINT murobi_ender_fk FOREIGN KEY (diakhiri_oleh) REFERENCES users (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7b. pembimbing_assignments
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pembimbing_assignments' AND COLUMN_NAME = 'catatan') = 0,
    'ALTER TABLE pembimbing_assignments ADD COLUMN catatan VARCHAR(500) NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pembimbing_assignments' AND COLUMN_NAME = 'updated_by') = 0,
    'ALTER TABLE pembimbing_assignments ADD COLUMN updated_by BIGINT UNSIGNED NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pembimbing_assignments' AND COLUMN_NAME = 'diakhiri_pada') = 0,
    'ALTER TABLE pembimbing_assignments ADD COLUMN diakhiri_pada DATETIME NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pembimbing_assignments' AND COLUMN_NAME = 'diakhiri_oleh') = 0,
    'ALTER TABLE pembimbing_assignments ADD COLUMN diakhiri_oleh BIGINT UNSIGNED NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pembimbing_assignments' AND COLUMN_NAME = 'alasan_pengakhiran') = 0,
    'ALTER TABLE pembimbing_assignments ADD COLUMN alasan_pengakhiran VARCHAR(500) NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pembimbing_assignments' AND CONSTRAINT_NAME = 'pembimbing_updater_fk') = 0,
    'ALTER TABLE pembimbing_assignments ADD CONSTRAINT pembimbing_updater_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pembimbing_assignments' AND CONSTRAINT_NAME = 'pembimbing_ender_fk') = 0,
    'ALTER TABLE pembimbing_assignments ADD CONSTRAINT pembimbing_ender_fk FOREIGN KEY (diakhiri_oleh) REFERENCES users (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Migrasi ini SENGAJA tidak menambah baris ke `roles`, tidak menyentuh
-- `users`/`user_roles`, dan tidak mengisi satu pun tabel penugasan.
