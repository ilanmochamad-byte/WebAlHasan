-- V2 Fase 2: Pengajuan, Routing, dan Keputusan Web
--
-- Sifat migrasi: SEPENUHNYA ADITIF DAN DAPAT DIJALANKAN ULANG (idempoten).
-- Tidak ada DROP TABLE, DROP COLUMN, DELETE, atau TRUNCATE terhadap data V1/V2.
-- Tabel `perizinan` lama TIDAK diubah dan TIDAK dihapus.
--
-- Fondasi tabel (`izin_pengajuan`, `izin_keputusan`, `izin_riwayat_status`,
-- `izin_idempotency_keys`) sudah dibuat pada migrasi 006 Fase 1. Migrasi ini hanya
-- menambahkan kolom jejak routing/penetapan/pembatalan, tabel koreksi keputusan,
-- dan indeks antrean yang dibutuhkan alur Fase 2.
--
-- WAJIB sebelum dijalankan (lihat docs/phase-v2-2/migration-and-rollback.md):
--   1. php bin/v2_phase2_preflight.php  -> backup + manifest + laporan konflik.
--   2. Uji lengkap pada salinan MySQL berakhiran `_test` terlebih dahulu.
--   3. php bin/v2_phase2_verify.php setelah migrasi.
--
-- Setiap pernyataan dibungkus pemeriksaan INFORMATION_SCHEMA sehingga migrasi ini
-- aman dijalankan berulang kali tanpa error "duplicate column/key".

-- ---------------------------------------------------------------------------
-- 1. Jejak routing pada pengajuan.
--    `routing_kandidat` menyimpan jumlah murobi kandidat saat routing dijalankan
--    sehingga kasus 0 dan >1 kandidat dapat diaudit, bukan hanya disimpulkan.
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'routing_kandidat') = 0,
    'ALTER TABLE izin_pengajuan ADD COLUMN routing_kandidat SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER murobi_guru_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'routing_catatan') = 0,
    'ALTER TABLE izin_pengajuan ADD COLUMN routing_catatan VARCHAR(255) NULL AFTER routing_kandidat',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'routing_pada') = 0,
    'ALTER TABLE izin_pengajuan ADD COLUMN routing_pada DATETIME NULL AFTER routing_catatan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Jejak penetapan murobi oleh admin (penetapan pertama maupun penetapan ulang).
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'murobi_ditetapkan_oleh_user_id') = 0,
    'ALTER TABLE izin_pengajuan ADD COLUMN murobi_ditetapkan_oleh_user_id BIGINT UNSIGNED NULL AFTER routing_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'murobi_ditetapkan_pada') = 0,
    'ALTER TABLE izin_pengajuan ADD COLUMN murobi_ditetapkan_pada DATETIME NULL AFTER murobi_ditetapkan_oleh_user_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND CONSTRAINT_NAME = 'izin_pengajuan_penetap_fk') = 0,
    'ALTER TABLE izin_pengajuan ADD CONSTRAINT izin_pengajuan_penetap_fk FOREIGN KEY (murobi_ditetapkan_oleh_user_id) REFERENCES users (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. Jejak pembatalan oleh pengurus. Riwayat tetap menjadi sumber kronologi;
--    kolom di bawah hanya mempercepat tampilan daftar/detail.
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'dibatalkan_oleh_user_id') = 0,
    'ALTER TABLE izin_pengajuan ADD COLUMN dibatalkan_oleh_user_id BIGINT UNSIGNED NULL AFTER murobi_ditetapkan_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'dibatalkan_pada') = 0,
    'ALTER TABLE izin_pengajuan ADD COLUMN dibatalkan_pada DATETIME NULL AFTER dibatalkan_oleh_user_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'alasan_pembatalan') = 0,
    'ALTER TABLE izin_pengajuan ADD COLUMN alasan_pembatalan TEXT NULL AFTER dibatalkan_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND CONSTRAINT_NAME = 'izin_pengajuan_pembatal_fk') = 0,
    'ALTER TABLE izin_pengajuan ADD CONSTRAINT izin_pengajuan_pembatal_fk FOREIGN KEY (dibatalkan_oleh_user_id) REFERENCES users (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. Indeks antrean dan indeks pemeriksaan tumpang tindih.
--    Ditambahkan setelah pola query Fase 2 diketahui, bukan spekulatif:
--      - antrean murobi/admin  : WHERE status = ? AND murobi_guru_id <=> ?
--      - tumpang tindih santri : WHERE santri_id = ? AND status IN (...) AND rentang
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND INDEX_NAME = 'izin_pengajuan_antrean_index') = 0,
    'ALTER TABLE izin_pengajuan ADD KEY izin_pengajuan_antrean_index (status, murobi_guru_id, id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND INDEX_NAME = 'izin_pengajuan_overlap_index') = 0,
    'ALTER TABLE izin_pengajuan ADD KEY izin_pengajuan_overlap_index (santri_id, status, tgl_izin, tgl_kembali)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 5. Koreksi keputusan.
--    PRD 5.3: koreksi TIDAK menghapus riwayat. Nilai sebelum dan sesudah setiap
--    koreksi disimpan permanen di sini, sedangkan `izin_keputusan` menyimpan nilai
--    yang berlaku sekarang dan `izin_riwayat_status` menyimpan kronologinya.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS izin_keputusan_koreksi (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengajuan_id BIGINT UNSIGNED NOT NULL,
    keputusan_id BIGINT UNSIGNED NOT NULL,
    hasil_sebelum ENUM('Disetujui','Ditolak') NOT NULL,
    hasil_sesudah ENUM('Disetujui','Ditolak') NOT NULL,
    alasan_sebelum TEXT NOT NULL,
    alasan_sesudah TEXT NOT NULL,
    status_sebelum VARCHAR(30) NOT NULL,
    status_sesudah VARCHAR(30) NOT NULL,
    alasan_koreksi TEXT NOT NULL,
    dikoreksi_oleh_user_id BIGINT UNSIGNED NULL,
    dikoreksi_pada DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY izin_koreksi_pengajuan_index (pengajuan_id, id),
    KEY izin_koreksi_actor_index (dikoreksi_oleh_user_id),
    CONSTRAINT izin_koreksi_pengajuan_fk FOREIGN KEY (pengajuan_id) REFERENCES izin_pengajuan (id),
    CONSTRAINT izin_koreksi_keputusan_fk FOREIGN KEY (keputusan_id) REFERENCES izin_keputusan (id),
    CONSTRAINT izin_koreksi_actor_fk FOREIGN KEY (dikoreksi_oleh_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT izin_koreksi_alasan_check CHECK (CHAR_LENGTH(TRIM(alasan_koreksi)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. Jejak versi pada keputusan yang dikoreksi.
--    Kolom `izin_keputusan.dikoreksi_pada` menandai bahwa nilai yang berlaku
--    sekarang berbeda dari keputusan pertama; nilai aslinya tetap ada pada
--    `izin_keputusan_koreksi` dan `izin_riwayat_status`.
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_keputusan' AND COLUMN_NAME = 'dikoreksi_pada') = 0,
    'ALTER TABLE izin_keputusan ADD COLUMN dikoreksi_pada DATETIME NULL AFTER diputus_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_keputusan' AND COLUMN_NAME = 'jumlah_koreksi') = 0,
    'ALTER TABLE izin_keputusan ADD COLUMN jumlah_koreksi SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER dikoreksi_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
