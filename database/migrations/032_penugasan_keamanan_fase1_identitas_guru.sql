-- Penugasan lintas guru/pengurus dan Keamanan — Fase 1: identitas pengurus milik guru — migrasi 032
--
-- Keputusan Human Developer 30 Oktober 2026 (docs/penugasan-keamanan/rancangan.md,
-- PK-1…PK-4). Branch `claude/penugasan-keamanan-fase-1`. Implementator Claude
-- Code, auditor Codex.
--
-- Sifat migrasi: ADITIF dan DAPAT DIJALANKAN ULANG. Satu kolom NULL, satu
-- unique key, dan satu foreign key pada `pengurus`. Tidak ada UPDATE, DELETE,
-- atau TRUNCATE: seluruh baris pengurus lama tetap `guru_id = NULL` dan
-- tidak berubah. Tabel penugasan, users, user_roles, guru, serta seluruh tabel
-- V1–V7 tidak disentuh.
--
-- IDENTITAS PENGURUS MILIK GURU (pendekatan A, PK-1)
-- ---------------------------------------------------
-- Penugasan berbasis pengurus (pembimbing, Bagian Pendidikan, kesehatan, BSA,
-- PSB) tetap merujuk `pengurus_id`. Seorang guru yang ditugaskan pada jenis itu
-- memperoleh SATU baris pengurus dengan `guru_id` = guru tersebut, dibentuk
-- layanan penugasan di dalam transaksi dan dihubungkan ke akun gurunya sendiri.
--   - UNIQUE (guru_id): satu guru paling banyak satu identitas pengurus.
--   - FK ke guru(id): identitas tidak dapat menunjuk guru yang tidak ada.
--   - Nama, nomor HP, status aktif, dan arsip identitas mengikuti data guru
--     (disinkronkan layanan Data Guru); identitas tidak diubah dari Data Pengurus.
-- `guru.id` bertipe INT bertanda (skema V1), sehingga kolom memakai INT.

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengurus' AND COLUMN_NAME = 'guru_id') = 0,
    'ALTER TABLE pengurus ADD COLUMN guru_id INT NULL DEFAULT NULL COMMENT ''PK-1: identitas pengurus milik guru ini. NULL = pengurus biasa.'' AFTER jabatan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengurus' AND INDEX_NAME = 'pengurus_guru_unique') = 0,
    'ALTER TABLE pengurus ADD UNIQUE KEY pengurus_guru_unique (guru_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengurus' AND CONSTRAINT_NAME = 'pengurus_guru_fk') = 0,
    'ALTER TABLE pengurus ADD CONSTRAINT pengurus_guru_fk FOREIGN KEY (guru_id) REFERENCES guru (id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
