-- Redesain website Tahap 6 — data pesantren & penugasan (23 Oktober 2026)
--
-- Keputusan Human Developer 22 Oktober 2026 (docs/redesain-web/keputusan.md):
--
--   AE  Kamar mendapat kolom Asrama putra/putri. Penempatan yang tidak sesuai
--       memunculkan peringatan dan hanya dilanjutkan dengan konfirmasi
--       beralasan (layar M-2 dan D-5).
--   AD  Tombol "Bukan ganda" pada rekonsiliasi wali menyimpan tanda pasangan
--       (layar D-7), dengan alasan dan pelaku, dan dapat dibatalkan.
--
-- Sifat migrasi: ADITIF DAN DAPAT DIJALANKAN ULANG (idempoten).
-- Tidak ada DROP, DELETE, UPDATE, atau TRUNCATE terhadap data mana pun.
--
--   kamar.asrama           ENUM('Putra','Putri') NULL
--       Sengaja TANPA pengisian otomatis: kamar lama tetap NULL ("Belum
--       ditentukan") sampai admin melengkapinya dari halaman Kelas & kobong.
--       Menebak asrama dari penghuni sekarang dapat salah dan justru
--       membenarkan penempatan yang keliru.
--
--   wali_bukan_ganda       tabel penanda pasangan wali yang dinyatakan admin
--       BUKAN orang yang sama. Pasangan disimpan terurut (wali_a_id <
--       wali_b_id). Pembatalan tidak menghapus baris: kolom dibatalkan_* diisi,
--       sehingga jejak "pernah ditandai" tetap ada. `aktif_kunci` menjamin
--       paling banyak satu tanda aktif per pasangan.
--
-- Kode aplikasi Tahap 6 memeriksa keberadaan kolom/tabel ini dan tetap
-- berjalan (tanpa label asrama dan tanpa tombol "Bukan ganda") bila migrasi
-- belum dijalankan atau sudah di-rollback.
--
-- WAJIB sebelum dijalankan di produksi:
--   1. backup terverifikasi;
--   2. uji pada salinan MySQL berakhiran `_test`;
--   3. rollback berpasangan: database/rollbacks/023_*.sql.

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kamar' AND COLUMN_NAME = 'asrama') = 0,
    'ALTER TABLE kamar ADD COLUMN asrama ENUM(''Putra'',''Putri'') NULL DEFAULT NULL COMMENT ''Keputusan AE: asrama putra/putri. NULL = belum ditentukan admin.''',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kamar' AND INDEX_NAME = 'kamar_asrama_index') = 0,
    'ALTER TABLE kamar ADD KEY kamar_asrama_index (asrama)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS wali_bukan_ganda (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    wali_a_id BIGINT UNSIGNED NOT NULL COMMENT 'ID wali terkecil pada pasangan',
    wali_b_id BIGINT UNSIGNED NOT NULL COMMENT 'ID wali terbesar pada pasangan',
    alasan VARCHAR(500) NOT NULL,
    ditandai_oleh BIGINT UNSIGNED NULL,
    ditandai_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    dibatalkan_pada TIMESTAMP NULL DEFAULT NULL,
    dibatalkan_oleh BIGINT UNSIGNED NULL,
    alasan_batal VARCHAR(500) NULL,
    aktif_kunci TINYINT GENERATED ALWAYS AS (CASE WHEN dibatalkan_pada IS NULL THEN 1 ELSE NULL END) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY wali_bukan_ganda_aktif_unique (wali_a_id, wali_b_id, aktif_kunci),
    KEY wali_bukan_ganda_b_index (wali_b_id),
    CONSTRAINT wali_bukan_ganda_urut CHECK (wali_a_id < wali_b_id),
    CONSTRAINT wali_bukan_ganda_a_fk FOREIGN KEY (wali_a_id) REFERENCES wali (id),
    CONSTRAINT wali_bukan_ganda_b_fk FOREIGN KEY (wali_b_id) REFERENCES wali (id),
    CONSTRAINT wali_bukan_ganda_oleh_fk FOREIGN KEY (ditandai_oleh) REFERENCES users (id),
    CONSTRAINT wali_bukan_ganda_batal_fk FOREIGN KEY (dibatalkan_oleh) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
