-- Rollback Fase 1 penugasan lintas guru/pengurus (migrasi 032, identitas pengurus milik guru).
-- Hanya untuk staging atau pemulihan terencana. Buat backup terverifikasi dulu.
--
-- Rollback DITOLAK bila sudah ada identitas pengurus milik guru (baris
-- `pengurus` dengan `guru_id` terisi). Melepas kolom pada keadaan itu akan
-- mengubah identitas tersebut menjadi pengurus biasa yang terhubung ke akun
-- guru, dan penugasannya kehilangan sinkronisasi status dengan data guru.
-- Pulihkan backup atau buat migrasi korektif.
--
-- Melepas hanya foreign key, unique key, dan kolom `pengurus.guru_id`. Baris
-- pengurus, users, user_roles, penugasan, guru, V1–V7, dan `audit_logs` tidak
-- disentuh. Kode tetap berjalan setelah rollback: pemilihan guru pada jenis
-- penugasan berbasis pengurus ditolak dengan pesan "belum dipasang".

-- Penjaga data: bila ada identitas guru, INSERT melanggar CHECK, Migrator
-- berhenti sebelum perubahan skema, dan migrasi tetap tercatat. Memerlukan CHECK
-- yang ditegakkan (MariaDB 10.2+/MySQL 8.0.16+). Kolom yang belum ada (migrasi
-- separuh) dihitung nol lewat penjaga skema.
DROP TEMPORARY TABLE IF EXISTS penugasan_fase1_rollback_guard;
CREATE TEMPORARY TABLE penugasan_fase1_rollback_guard (jumlah BIGINT NOT NULL, CONSTRAINT penugasan_fase1_rollback_guard_kosong CHECK (jumlah = 0));
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengurus' AND COLUMN_NAME = 'guru_id') = 1,
    'INSERT INTO penugasan_fase1_rollback_guard (jumlah) SELECT COUNT(*) FROM pengurus WHERE guru_id IS NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
DROP TEMPORARY TABLE penugasan_fase1_rollback_guard;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengurus' AND CONSTRAINT_NAME = 'pengurus_guru_fk') = 1,
    'ALTER TABLE pengurus DROP FOREIGN KEY pengurus_guru_fk',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengurus' AND INDEX_NAME = 'pengurus_guru_unique') = 1,
    'ALTER TABLE pengurus DROP INDEX pengurus_guru_unique',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengurus' AND COLUMN_NAME = 'guru_id') = 1,
    'ALTER TABLE pengurus DROP COLUMN guru_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
