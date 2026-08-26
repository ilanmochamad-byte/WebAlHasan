-- Rollback V2 Fase 5 hanya untuk staging atau pemulihan terencana.
-- Buat backup terverifikasi terlebih dahulu (bin/v2_phase5_preflight.php).
--
-- Migrasi 009 hanya menambahkan JEJAK RECEIPT PUSH pada `notifikasi_outbox`.
-- Rollback ini melepas kembali kolom dan indeks tersebut.
--
-- Yang TIDAK disentuh sama sekali:
--   - seluruh data perizinan V1 dan V2 (`perizinan`, `izin_pengajuan`,
--     `izin_keputusan`, `izin_riwayat_status`, `izin_keputusan_koreksi`);
--   - baris `notifikasi_outbox` itu sendiri, termasuk status Queued/Sent/Failed;
--   - `perangkat_push`, `pengaturan_notifikasi`, dan `audit_logs`.
--
-- Laporan Fase 5 TIDAK memerlukan rollback: laporan hanya membaca tabel yang
-- sudah ada dan tidak membuat satu pun tabel, kolom, atau indeks laporan.
-- Menghapus berkas PHP laporan sudah cukup untuk mengembalikan keadaan
-- fungsional sebelum Fase 5.
--
-- CATATAN KEHILANGAN DATA YANG DISENGAJA: hasil pengambilan receipt akhir
-- (`receipt_status`, `receipt_kode`, `receipt_pesan`, `receipt_diperiksa_pada`,
-- `receipt_percobaan`, `tiket_id`) ikut terhapus. Status pengiriman utama
-- (`status`, `dikirim_pada`, `percobaan`, `error_terakhir`) TETAP ADA, sehingga
-- sistem kembali persis ke perilaku Fase 4 — yaitu berhenti pada tiket awal.
--
-- Seluruh pernyataan dibungkus pemeriksaan INFORMATION_SCHEMA sehingga rollback
-- aman dijalankan ulang.

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox'
        AND INDEX_NAME = 'notifikasi_receipt_index') > 0,
    'ALTER TABLE notifikasi_outbox DROP KEY notifikasi_receipt_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'receipt_percobaan') > 0,
    'ALTER TABLE notifikasi_outbox DROP COLUMN receipt_percobaan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'receipt_diperiksa_pada') > 0,
    'ALTER TABLE notifikasi_outbox DROP COLUMN receipt_diperiksa_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'receipt_pesan') > 0,
    'ALTER TABLE notifikasi_outbox DROP COLUMN receipt_pesan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'receipt_kode') > 0,
    'ALTER TABLE notifikasi_outbox DROP COLUMN receipt_kode',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'receipt_status') > 0,
    'ALTER TABLE notifikasi_outbox DROP COLUMN receipt_status',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'tiket_id') > 0,
    'ALTER TABLE notifikasi_outbox DROP COLUMN tiket_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
