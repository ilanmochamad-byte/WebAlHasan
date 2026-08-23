-- Rollback V2 Fase 4 hanya untuk staging atau pemulihan terencana.
-- Buat backup terverifikasi terlebih dahulu (bin/v2_phase4_preflight.php).
--
-- Rollback ini melepas KOLOM DAN TABEL JEJAK Fase 4 saja:
--   - tabel `notifikasi_percobaan` (riwayat percobaan pengiriman),
--   - tabel `notifikasi_pengaturan_audit` (audit sakelar/pemeriksaan/pesan uji),
--   - tabel `notifikasi_worker_lock` (sewa worker),
--   - kolom operasional pada `notifikasi_outbox` (backoff, sewa, kegagalan permanen),
--   - kolom tambahan pada `perangkat_push` dan `pengaturan_notifikasi`,
--   - indeks yang ditambahkan Fase 4,
--   - CHECK yang menegakkan in-app selalu aktif.
--
-- Yang TIDAK disentuh: seluruh data bisnis Fase 1-3 dan V1. Baris
-- `notifikasi_outbox` (termasuk notifikasi in-app yang sudah dibaca pengguna),
-- `perangkat_push`, dan `pengaturan_notifikasi` tetap ada; hanya kolom jejak
-- operasional Fase 4 yang hilang. Tabel `perizinan`, `izin_pengajuan`,
-- `izin_keputusan`, `izin_riwayat_status`, dan `audit_logs` tidak disentuh
-- sama sekali.
--
-- CATATAN KEHILANGAN DATA YANG DISENGAJA: riwayat percobaan pengiriman dan
-- audit khusus pengaturan kanal ikut terhapus bersama tabelnya. Jejak
-- perubahan sakelar juga tercatat pada `audit_logs` umum, yang TIDAK dihapus
-- rollback ini, sehingga pertanggungjawaban tetap dapat ditelusuri.
--
-- Seluruh pernyataan dibungkus pemeriksaan INFORMATION_SCHEMA sehingga rollback
-- aman dijalankan ulang.

DROP TABLE IF EXISTS notifikasi_percobaan;
DROP TABLE IF EXISTS notifikasi_pengaturan_audit;
DROP TABLE IF EXISTS notifikasi_worker_lock;

-- ---------------------------------------------------------------------------
-- 1. pengaturan_notifikasi
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengaturan_notifikasi'
        AND CONSTRAINT_NAME = 'pengaturan_notifikasi_inapp_check') > 0,
    'ALTER TABLE pengaturan_notifikasi DROP CONSTRAINT pengaturan_notifikasi_inapp_check',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengaturan_notifikasi'
        AND CONSTRAINT_NAME = 'pengaturan_notifikasi_pemeriksa_fk') > 0,
    'ALTER TABLE pengaturan_notifikasi DROP FOREIGN KEY pengaturan_notifikasi_pemeriksa_fk',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengaturan_notifikasi' AND COLUMN_NAME = 'whatsapp_check_oleh_user_id') > 0,
    'ALTER TABLE pengaturan_notifikasi DROP COLUMN whatsapp_check_oleh_user_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengaturan_notifikasi' AND COLUMN_NAME = 'push_check_status') > 0,
    'ALTER TABLE pengaturan_notifikasi DROP COLUMN push_check_status',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengaturan_notifikasi' AND COLUMN_NAME = 'push_check_pesan') > 0,
    'ALTER TABLE pengaturan_notifikasi DROP COLUMN push_check_pesan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengaturan_notifikasi' AND COLUMN_NAME = 'push_check_pada') > 0,
    'ALTER TABLE pengaturan_notifikasi DROP COLUMN push_check_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. perangkat_push
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perangkat_push'
        AND INDEX_NAME = 'perangkat_push_kirim_index') > 0,
    'ALTER TABLE perangkat_push DROP INDEX perangkat_push_kirim_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perangkat_push'
        AND INDEX_NAME = 'perangkat_push_user_device_unique') > 0,
    'ALTER TABLE perangkat_push DROP INDEX perangkat_push_user_device_unique',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perangkat_push' AND COLUMN_NAME = 'gagal_berturut') > 0,
    'ALTER TABLE perangkat_push DROP COLUMN gagal_berturut',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perangkat_push' AND COLUMN_NAME = 'alasan_pencabutan') > 0,
    'ALTER TABLE perangkat_push DROP COLUMN alasan_pencabutan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perangkat_push' AND COLUMN_NAME = 'push_aktif') > 0,
    'ALTER TABLE perangkat_push DROP COLUMN push_aktif',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perangkat_push' AND COLUMN_NAME = 'app_version') > 0,
    'ALTER TABLE perangkat_push DROP COLUMN app_version',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perangkat_push' AND COLUMN_NAME = 'device_id') > 0,
    'ALTER TABLE perangkat_push DROP COLUMN device_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. notifikasi_outbox
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox'
        AND INDEX_NAME = 'notifikasi_inapp_index') > 0,
    'ALTER TABLE notifikasi_outbox DROP INDEX notifikasi_inapp_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox'
        AND INDEX_NAME = 'notifikasi_worker_index') > 0,
    'ALTER TABLE notifikasi_outbox DROP INDEX notifikasi_worker_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'locked_until') > 0,
    'ALTER TABLE notifikasi_outbox DROP COLUMN locked_until',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'locked_by') > 0,
    'ALTER TABLE notifikasi_outbox DROP COLUMN locked_by',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'error_kode') > 0,
    'ALTER TABLE notifikasi_outbox DROP COLUMN error_kode',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'gagal_permanen') > 0,
    'ALTER TABLE notifikasi_outbox DROP COLUMN gagal_permanen',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'tersedia_pada') > 0,
    'ALTER TABLE notifikasi_outbox DROP COLUMN tersedia_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'data_json') > 0,
    'ALTER TABLE notifikasi_outbox DROP COLUMN data_json',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
