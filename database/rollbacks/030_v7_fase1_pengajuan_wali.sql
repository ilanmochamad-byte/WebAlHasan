-- Rollback PRD V7 Fase 1 (migrasi 030, pengajuan wali dan routing pembimbing).
-- Hanya untuk staging atau pemulihan terencana. Buat backup terverifikasi dulu
-- dan jalankan `php bin/v7_phase1_rollback_preflight.php`: rollback DITOLAK
-- bila sudah ada data V7 — pengajuan berasal Wali, baris bertahap V7, riwayat
-- penetapan pembimbing, atau kunci idempotensi operasi `izin.wali.*`. Data itu
-- tidak boleh kehilangan asal/identitas wali lewat rollback; pulihkan backup
-- atau buat migrasi korektif.
--
-- Melepas: tabel `izin_pembimbing_penetapan`, CHECK/kunci asing/indeks/kolom
-- V7 pada `izin_pengajuan`, lalu mengembalikan ENUM `status` ke lima nilai V2.
-- Pernyataan dibungkus pemeriksaan INFORMATION_SCHEMA sehingga aman diulang.
--
-- Yang TIDAK disentuh: kolom/ID/baris V2 dan warisan `izin_pengajuan`,
-- `perizinan`, `izin_keputusan`, `izin_riwayat_status`, `izin_idempotency_keys`,
-- santri, wali, santri_wali, users, penugasan, PSB, V1–V6, dan `audit_logs`.
--
-- Kode V7 tetap berjalan setelah rollback ini: halaman/API pengajuan wali
-- menjawab 503 "belum dipasang"; jalur pengurus V2 tetap seperti sebelum 030.

-- Penjaga data: bila ada data V7, INSERT melanggar CHECK, Migrator berhenti
-- sebelum perubahan skema, dan migrasi tetap tercatat. Memerlukan CHECK yang
-- ditegakkan (MariaDB 10.2+/MySQL 8.0.16+).
DROP TEMPORARY TABLE IF EXISTS v7_fase1_rollback_guard;
CREATE TEMPORARY TABLE v7_fase1_rollback_guard (jumlah BIGINT NOT NULL, CONSTRAINT v7_fase1_rollback_guard_kosong CHECK (jumlah = 0));
INSERT INTO v7_fase1_rollback_guard (jumlah)
SELECT (SELECT COUNT(*) FROM izin_pengajuan
         WHERE asal_pengajuan = 'Wali' OR wali_id IS NOT NULL
            OR status IN ('Menunggu Pembimbing', 'Perlu Penetapan Pembimbing', 'Perlu Perbaikan Wali', 'Ditolak Pembimbing'))
     + (SELECT COUNT(*) FROM izin_pembimbing_penetapan)
     + (SELECT COUNT(*) FROM izin_idempotency_keys WHERE operation LIKE 'izin.wali.%');
DROP TEMPORARY TABLE v7_fase1_rollback_guard;

DROP TABLE IF EXISTS izin_pembimbing_penetapan;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND CONSTRAINT_NAME = 'izin_pengajuan_v7_asal_check') > 0,
    'ALTER TABLE izin_pengajuan DROP CONSTRAINT izin_pengajuan_v7_asal_check',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND CONSTRAINT_NAME = 'izin_pengajuan_v7_wali_fk') > 0,
    'ALTER TABLE izin_pengajuan DROP FOREIGN KEY izin_pengajuan_v7_wali_fk',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND CONSTRAINT_NAME = 'izin_pengajuan_v7_pembimbing_fk') > 0,
    'ALTER TABLE izin_pengajuan DROP FOREIGN KEY izin_pengajuan_v7_pembimbing_fk',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND CONSTRAINT_NAME = 'izin_pengajuan_v7_penetap_fk') > 0,
    'ALTER TABLE izin_pengajuan DROP FOREIGN KEY izin_pengajuan_v7_penetap_fk',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND INDEX_NAME = 'izin_pengajuan_v7_pembimbing_index') > 0,
    'ALTER TABLE izin_pengajuan DROP INDEX izin_pengajuan_v7_pembimbing_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND INDEX_NAME = 'izin_pengajuan_v7_asal_index') > 0,
    'ALTER TABLE izin_pengajuan DROP INDEX izin_pengajuan_v7_asal_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND INDEX_NAME = 'izin_pengajuan_v7_wali_index') > 0,
    'ALTER TABLE izin_pengajuan DROP INDEX izin_pengajuan_v7_wali_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND INDEX_NAME = 'izin_pengajuan_v7_penetap_index') > 0,
    'ALTER TABLE izin_pengajuan DROP INDEX izin_pengajuan_v7_penetap_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'pembimbing_ditetapkan_pada') > 0,
    'ALTER TABLE izin_pengajuan DROP COLUMN pembimbing_ditetapkan_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'pembimbing_ditetapkan_oleh_user_id') > 0,
    'ALTER TABLE izin_pengajuan DROP COLUMN pembimbing_ditetapkan_oleh_user_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'pembimbing_routing_pada') > 0,
    'ALTER TABLE izin_pengajuan DROP COLUMN pembimbing_routing_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'pembimbing_routing_catatan') > 0,
    'ALTER TABLE izin_pengajuan DROP COLUMN pembimbing_routing_catatan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'pembimbing_routing_kandidat') > 0,
    'ALTER TABLE izin_pengajuan DROP COLUMN pembimbing_routing_kandidat',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'pembimbing_pengurus_id') > 0,
    'ALTER TABLE izin_pengajuan DROP COLUMN pembimbing_pengurus_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'catatan_wali') > 0,
    'ALTER TABLE izin_pengajuan DROP COLUMN catatan_wali',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'wali_id') > 0,
    'ALTER TABLE izin_pengajuan DROP COLUMN wali_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'asal_pengajuan') > 0,
    'ALTER TABLE izin_pengajuan DROP COLUMN asal_pengajuan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'status'
        AND COLUMN_TYPE LIKE '%Menunggu Pembimbing%') > 0,
    'ALTER TABLE izin_pengajuan MODIFY COLUMN status ENUM(''Diajukan'',''Perlu Penetapan Admin'',''Disetujui'',''Ditolak'',''Dibatalkan'') NOT NULL DEFAULT ''Diajukan''',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
