-- V2 Fase 4: Notifikasi In-App, Push, dan WhatsApp Opsional
--
-- Sifat migrasi: SEPENUHNYA ADITIF DAN DAPAT DIJALANKAN ULANG (idempoten).
-- Tidak ada DROP TABLE, DROP COLUMN, DELETE, UPDATE, atau TRUNCATE terhadap
-- data bisnis Fase 1-3 maupun data V1. Tabel `perizinan` lama tidak disentuh.
--
-- Fondasi tabel notifikasi (`notifikasi_outbox`, `perangkat_push`,
-- `pengaturan_notifikasi`) sudah dibuat pada migrasi 006 Fase 1. Migrasi ini
-- hanya MENAMBAHKAN kolom operasional (backoff, kegagalan permanen, sewa
-- worker), tabel percobaan pengiriman, tabel audit pengaturan kanal, tabel
-- sewa worker, serta indeks yang dibutuhkan pekerja outbox.
--
-- Kompatibilitas MySQL 5.7+/8.x dan MariaDB 10.2+ (cPanel):
--   - seluruh pernyataan dibungkus pemeriksaan INFORMATION_SCHEMA sehingga aman
--     dijalankan berulang tanpa error "duplicate column/key/constraint";
--   - tidak memakai tipe atau sintaks yang khusus satu vendor;
--   - CHECK constraint dipakai sama seperti migrasi 006/007 yang sudah lolos.
--
-- WAJIB sebelum dijalankan (lihat docs/phase-v2-4/migration-and-rollback.md):
--   1. php bin/v2_phase4_preflight.php   -> backup + manifest + laporan konflik.
--   2. Uji lengkap pada salinan MySQL berakhiran `_test` terlebih dahulu.
--   3. php bin/v2_phase4_verify.php      -> verifikasi setelah migrasi.
--
-- Rollback: database/rollbacks/008_v2_phase4_notifikasi_push_whatsapp.sql.

-- ---------------------------------------------------------------------------
-- 1. Kolom operasional outbox.
--
--    `data_json`      : payload AMAN untuk deep link (tipe + pengajuan_id saja).
--                       Tidak pernah memuat alasan izin, catatan pengurus,
--                       credential, atau token.
--    `tersedia_pada`  : waktu paling awal percobaan berikutnya (backoff).
--    `gagal_permanen` : 1 bila retry dihentikan; baris tidak diambil lagi.
--    `locked_by` /
--    `locked_until`   : sewa (lease) worker agar dua proses cron bersamaan
--                       tidak pernah mengirim baris yang sama dua kali.
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'data_json') = 0,
    'ALTER TABLE notifikasi_outbox ADD COLUMN data_json VARCHAR(1000) NULL AFTER isi',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'tersedia_pada') = 0,
    'ALTER TABLE notifikasi_outbox ADD COLUMN tersedia_pada DATETIME NULL AFTER percobaan_terakhir_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'gagal_permanen') = 0,
    'ALTER TABLE notifikasi_outbox ADD COLUMN gagal_permanen TINYINT(1) NOT NULL DEFAULT 0 AFTER tersedia_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'error_kode') = 0,
    'ALTER TABLE notifikasi_outbox ADD COLUMN error_kode VARCHAR(60) NULL AFTER error_terakhir',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'locked_by') = 0,
    'ALTER TABLE notifikasi_outbox ADD COLUMN locked_by VARCHAR(64) NULL AFTER gagal_permanen',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'locked_until') = 0,
    'ALTER TABLE notifikasi_outbox ADD COLUMN locked_until DATETIME NULL AFTER locked_by',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indeks pekerja outbox: kanal + kelayakan retry + urutan pengambilan.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox'
        AND INDEX_NAME = 'notifikasi_worker_index') = 0,
    'ALTER TABLE notifikasi_outbox ADD KEY notifikasi_worker_index (kanal, status, gagal_permanen, tersedia_pada, id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indeks pusat notifikasi in-app: daftar dan jumlah belum dibaca per pengguna.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox'
        AND INDEX_NAME = 'notifikasi_inapp_index') = 0,
    'ALTER TABLE notifikasi_outbox ADD KEY notifikasi_inapp_index (penerima_user_id, kanal, id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Percobaan pengiriman per baris outbox.
--
--    Riwayat percobaan disimpan terpisah agar admin dapat melihat pola
--    kegagalan tanpa menimpa error terakhir. Kolom pesan hanya memuat ERROR
--    AMAN yang sudah dibersihkan: tidak ada credential, token, nomor telepon,
--    atau isi pesan.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifikasi_percobaan (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    outbox_id BIGINT UNSIGNED NOT NULL,
    kanal ENUM('InApp','Push','WhatsApp') NOT NULL,
    percobaan_ke INT UNSIGNED NOT NULL,
    hasil ENUM('Sent','Failed') NOT NULL,
    error_kode VARCHAR(60) NULL,
    error_pesan VARCHAR(255) NULL,
    durasi_ms INT UNSIGNED NULL,
    dicoba_pada DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY notifikasi_percobaan_unik (outbox_id, percobaan_ke),
    KEY notifikasi_percobaan_outbox_index (outbox_id, id),
    KEY notifikasi_percobaan_hasil_index (hasil, dicoba_pada),
    CONSTRAINT notifikasi_percobaan_outbox_fk FOREIGN KEY (outbox_id)
        REFERENCES notifikasi_outbox (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Kolom tambahan perangkat push.
--
--    `device_id`  : identitas instalasi yang stabil dari aplikasi. Dipakai agar
--                   token baru pada perangkat yang sama MENGGANTI token lama,
--                   bukan menumpuk baris.
--    `push_aktif` : pengguna dapat mematikan push per perangkat tanpa mencabut
--                   sesi dan tanpa mempengaruhi notifikasi in-app.
--    Token mentah tetap TIDAK PERNAH disimpan: hanya hash dan bentuk
--    terlindungi (terenkripsi) yang sudah ada sejak migrasi 006.
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perangkat_push' AND COLUMN_NAME = 'device_id') = 0,
    'ALTER TABLE perangkat_push ADD COLUMN device_id VARCHAR(100) NULL AFTER platform',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perangkat_push' AND COLUMN_NAME = 'app_version') = 0,
    'ALTER TABLE perangkat_push ADD COLUMN app_version VARCHAR(30) NULL AFTER device_label',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perangkat_push' AND COLUMN_NAME = 'push_aktif') = 0,
    'ALTER TABLE perangkat_push ADD COLUMN push_aktif TINYINT(1) NOT NULL DEFAULT 1 AFTER app_version',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perangkat_push' AND COLUMN_NAME = 'alasan_pencabutan') = 0,
    'ALTER TABLE perangkat_push ADD COLUMN alasan_pencabutan VARCHAR(40) NULL AFTER dicabut_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perangkat_push' AND COLUMN_NAME = 'gagal_berturut') = 0,
    'ALTER TABLE perangkat_push ADD COLUMN gagal_berturut SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER alasan_pencabutan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Satu baris aktif per (pengguna, perangkat). NULL device_id tetap diizinkan
-- berulang karena MySQL/MariaDB tidak menganggap NULL sebagai duplikat.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perangkat_push'
        AND INDEX_NAME = 'perangkat_push_user_device_unique') = 0,
    'ALTER TABLE perangkat_push ADD UNIQUE KEY perangkat_push_user_device_unique (user_id, device_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perangkat_push'
        AND INDEX_NAME = 'perangkat_push_kirim_index') = 0,
    'ALTER TABLE perangkat_push ADD KEY perangkat_push_kirim_index (user_id, dicabut_pada, push_aktif)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. Pengaturan kanal.
--
--    In-app adalah sumber status utama (PRD 5.7) sehingga TIDAK dapat dimatikan.
--    Aturan itu ditegakkan basis data, bukan hanya UI. Secret provider tetap
--    berada di environment server: tidak ada kolom untuk menyimpannya.
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengaturan_notifikasi' AND COLUMN_NAME = 'whatsapp_check_oleh_user_id') = 0,
    'ALTER TABLE pengaturan_notifikasi ADD COLUMN whatsapp_check_oleh_user_id BIGINT UNSIGNED NULL AFTER whatsapp_check_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengaturan_notifikasi' AND COLUMN_NAME = 'push_check_status') = 0,
    'ALTER TABLE pengaturan_notifikasi ADD COLUMN push_check_status ENUM(''Belum Diperiksa'',''Lulus'',''Gagal'') NOT NULL DEFAULT ''Belum Diperiksa'' AFTER push_enabled',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengaturan_notifikasi' AND COLUMN_NAME = 'push_check_pesan') = 0,
    'ALTER TABLE pengaturan_notifikasi ADD COLUMN push_check_pesan VARCHAR(255) NULL AFTER push_check_status',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengaturan_notifikasi' AND COLUMN_NAME = 'push_check_pada') = 0,
    'ALTER TABLE pengaturan_notifikasi ADD COLUMN push_check_pada DATETIME NULL AFTER push_check_pesan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengaturan_notifikasi'
        AND CONSTRAINT_NAME = 'pengaturan_notifikasi_pemeriksa_fk') = 0,
    'ALTER TABLE pengaturan_notifikasi ADD CONSTRAINT pengaturan_notifikasi_pemeriksa_fk FOREIGN KEY (whatsapp_check_oleh_user_id) REFERENCES users (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengaturan_notifikasi'
        AND CONSTRAINT_NAME = 'pengaturan_notifikasi_inapp_check') = 0,
    'ALTER TABLE pengaturan_notifikasi ADD CONSTRAINT pengaturan_notifikasi_inapp_check CHECK (inapp_enabled = 1)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 5. Audit khusus pengaturan kanal, pemeriksaan konfigurasi, dan pesan uji.
--
--    Terpisah dari `audit_logs` umum agar admin dapat menelusuri riwayat kanal
--    tanpa memfilter seluruh audit sistem. Struktur tabel ini SENGAJA tidak
--    memiliki kolom untuk credential, token, atau nomor tujuan.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifikasi_pengaturan_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    aksi ENUM('kanal_diubah','pemeriksaan_konfigurasi','pesan_uji','percobaan_ulang') NOT NULL,
    kanal ENUM('InApp','Push','WhatsApp') NULL,
    nilai_sebelum VARCHAR(255) NULL,
    nilai_sesudah VARCHAR(255) NULL,
    hasil VARCHAR(60) NULL,
    pesan VARCHAR(255) NULL,
    aktor_user_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY notifikasi_pengaturan_audit_waktu_index (created_at, id),
    KEY notifikasi_pengaturan_audit_aktor_index (aktor_user_id),
    CONSTRAINT notifikasi_pengaturan_audit_aktor_fk FOREIGN KEY (aktor_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. Sewa (lease) worker.
--
--    cPanel menjalankan cron tanpa jaminan proses sebelumnya sudah selesai.
--    Sewa berbasis baris ini membuat proses kedua berhenti dengan tenang
--    alih-alih mengirim ulang baris yang sedang diproses proses pertama.
--    Sewa memiliki kedaluwarsa sehingga proses yang mati tidak mengunci
--    antrean selamanya.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifikasi_worker_lock (
    nama VARCHAR(60) NOT NULL,
    pemilik VARCHAR(64) NOT NULL,
    dikunci_pada DATETIME NOT NULL,
    kedaluwarsa_pada DATETIME NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (nama)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO notifikasi_worker_lock (nama, pemilik, dikunci_pada, kedaluwarsa_pada)
VALUES
    ('notifikasi:push', '', '1970-01-02 00:00:00', '1970-01-02 00:00:00'),
    ('notifikasi:whatsapp', '', '1970-01-02 00:00:00', '1970-01-02 00:00:00')
ON DUPLICATE KEY UPDATE nama = VALUES(nama);
