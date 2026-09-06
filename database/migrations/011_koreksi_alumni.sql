-- Paket "Koreksi Pengelolaan Alumni" — migrasi 011
--
-- Keputusan pengguna 6 September 2026.
--
-- Sifat migrasi: ADITIF dan DAPAT DIJALANKAN ULANG (idempoten). Tidak ada
-- DROP TABLE, DELETE, atau TRUNCATE. Tidak ada satu pun baris `alumni` lama
-- yang dihapus, dan tidak ada kolom lama yang dihapus atau diubah tipenya.
-- Nilai `nis`, `foto`, identitas, dan snapshot orang tua pada baris lama tetap
-- persis seperti sebelumnya.
--
-- MASALAH YANG DIPERBAIKI
-- ----------------------
-- Tabel `alumni` warisan V1 tidak menyimpan referensi ke santri sumber. Satu-
-- satunya pengaman keunikannya adalah `UNIQUE KEY nis (nis)`. Akibatnya:
--
--   1. Tidak ada cara memastikan "satu santri hanya boleh punya satu catatan
--      alumni aktif" selain mencocokkan NIS sebagai teks.
--   2. Catatan alumni hanya dapat dihilangkan dengan DELETE permanen (pola
--      lama `admin_alumni.php?hapus=ID`), sehingga tidak ada arsip, tidak ada
--      alasan, dan tidak ada pemulihan.
--   3. Tidak ada jejak siapa memproses kelulusan/mutasi dan kapan.
--
-- YANG DITAMBAHKAN
-- ----------------
--   alumni.santri_id          referensi stabil ke `santri` (bukan cocok nama)
--   alumni.kelas_terakhir     snapshot kelas aktif saat diproses
--   alumni.kamar_terakhir     snapshot kamar aktif saat diproses
--   alumni.catatan            catatan admin saat memproses/mengoreksi
--   alumni.archived_at        penanda arsip; NULL berarti catatan aktif
--   alumni.jenis_arsip        'arsip' atau 'pembatalan'
--   alumni.alasan_arsip       alasan wajib saat mengarsipkan/membatalkan
--   alumni.created_by         admin yang memproses kelulusan/mutasi
--   alumni.updated_by         admin yang terakhir mengoreksi/mengarsipkan
--   alumni.created_at         waktu pembuatan (NULL untuk data warisan)
--   alumni.updated_at         waktu perubahan terakhir
--   alumni.santri_aktif_guard kolom turunan untuk kunci unik alumni aktif
--   alumni.nis_aktif_guard    kolom turunan untuk kunci unik NIS aktif
--
-- PERUBAHAN INDEKS YANG DISENGAJA
-- -------------------------------
-- `UNIQUE KEY nis (nis)` DILEPAS dan digantikan pasangan:
--
--   * `UNIQUE KEY alumni_nis_aktif_unique (nis_aktif_guard)` — satu NIS hanya
--     boleh punya SATU catatan alumni AKTIF. Aturan lama tetap berlaku penuh
--     untuk data yang aktif.
--   * `KEY alumni_nis_index (nis)` — pencarian NIS tetap cepat.
--
-- Alasannya: tanpa perubahan ini, mengarsipkan atau membatalkan kelulusan
-- membuat NIS tersebut TERKUNCI selamanya — santri yang kelulusannya dibatalkan
-- tidak akan pernah bisa diluluskan lagi karena baris arsipnya masih memakai
-- NIS itu. Keunikan tidak dilonggarkan untuk data aktif; ia hanya berhenti
-- berlaku bagi baris yang sudah diarsipkan.
--
-- APA YANG TIDAK DILAKUKAN MIGRASI INI
-- ------------------------------------
--   * TIDAK mengisi `santri_id` untuk data lama. Pemasangan dilakukan skrip
--     terpisah `bin/alumni_backfill.php` yang konservatif: hanya memasangkan
--     bila NIS cocok PERSIS satu santri dan satu baris alumni. Data ambigu
--     dilaporkan, tidak ditebak.
--   * TIDAK menghapus atau memindahkan berkas foto.
--   * TIDAK menyentuh `santri`, `plotting_kelas`, `plotting_kamar`,
--     `santri_wali`, `wali`, `users`, atau `audit_logs`.
--
-- WAJIB sebelum dijalankan di produksi:
--   1. backup terverifikasi (lihat docs/koreksi-alumni/migrasi-dan-rollback.md);
--   2. `php bin/alumni_preflight.php` tidak melaporkan penghalang;
--   3. uji lengkap pada salinan MySQL berakhiran `_test`;
--   4. rollback berpasangan tersedia di database/rollbacks/011_*.sql.

-- ---------------------------------------------------------------------------
-- 1. Kolom referensi santri sumber.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'santri_id') = 0,
    'ALTER TABLE alumni ADD COLUMN santri_id INT NULL DEFAULT NULL COMMENT ''Referensi stabil ke santri sumber. NULL berarti data warisan yang belum dapat dipasangkan.''',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Snapshot penempatan terakhir.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'kelas_terakhir') = 0,
    'ALTER TABLE alumni ADD COLUMN kelas_terakhir VARCHAR(50) NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'kamar_terakhir') = 0,
    'ALTER TABLE alumni ADD COLUMN kamar_terakhir VARCHAR(50) NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. Catatan, arsip, dan alasan.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'catatan') = 0,
    'ALTER TABLE alumni ADD COLUMN catatan TEXT NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'archived_at') = 0,
    'ALTER TABLE alumni ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL COMMENT ''NULL berarti catatan alumni aktif. Pengganti penghapusan permanen.''',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'jenis_arsip') = 0,
    'ALTER TABLE alumni ADD COLUMN jenis_arsip VARCHAR(20) NULL DEFAULT NULL COMMENT ''arsip = koreksi data; pembatalan = kelulusan/mutasi dibatalkan dan santri diaktifkan kembali.''',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'alasan_arsip') = 0,
    'ALTER TABLE alumni ADD COLUMN alasan_arsip VARCHAR(500) NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. Jejak pelaku dan waktu.
--
-- created_at SENGAJA tanpa DEFAULT CURRENT_TIMESTAMP: baris warisan tidak boleh
-- mengaku dibuat pada saat migrasi dijalankan. NULL = waktu tidak diketahui.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'created_by') = 0,
    'ALTER TABLE alumni ADD COLUMN created_by BIGINT UNSIGNED NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'updated_by') = 0,
    'ALTER TABLE alumni ADD COLUMN updated_by BIGINT UNSIGNED NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'created_at') = 0,
    'ALTER TABLE alumni ADD COLUMN created_at TIMESTAMP NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'updated_at') = 0,
    'ALTER TABLE alumni ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 5. Kolom turunan penjaga keunikan.
--
-- Pola yang sama sudah dipakai migrasi 002 (`plotting_kelas.active_year_guard`,
-- `santri_wali.active_guard`, `tahun_ajaran.active_guard`), sehingga versi
-- MySQL/MariaDB produksi sudah terbukti mendukungnya.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'santri_aktif_guard') = 0,
    'ALTER TABLE alumni ADD COLUMN santri_aktif_guard INT GENERATED ALWAYS AS (CASE WHEN archived_at IS NULL THEN santri_id ELSE NULL END) STORED',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'nis_aktif_guard') = 0,
    'ALTER TABLE alumni ADD COLUMN nis_aktif_guard VARCHAR(20) GENERATED ALWAYS AS (CASE WHEN archived_at IS NULL THEN nis ELSE NULL END) STORED',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 6. Kunci unik pencegah alumni ganda.
--
-- NULL boleh berulang pada kunci unik MySQL, sehingga:
--   * baris warisan tanpa `santri_id` tidak saling bertabrakan;
--   * baris yang sudah diarsipkan tidak menghalangi pemrosesan ulang.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND INDEX_NAME = 'alumni_santri_aktif_unique') = 0,
    'ALTER TABLE alumni ADD UNIQUE KEY alumni_santri_aktif_unique (santri_aktif_guard)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND INDEX_NAME = 'alumni_nis_aktif_unique') = 0,
    'ALTER TABLE alumni ADD UNIQUE KEY alumni_nis_aktif_unique (nis_aktif_guard)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 7. Indeks bantu pencarian, filter, dan referensi santri.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND INDEX_NAME = 'alumni_nis_index') = 0,
    'ALTER TABLE alumni ADD KEY alumni_nis_index (nis)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND INDEX_NAME = 'alumni_santri_index') = 0,
    'ALTER TABLE alumni ADD KEY alumni_santri_index (santri_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND INDEX_NAME = 'alumni_filter_index') = 0,
    'ALTER TABLE alumni ADD KEY alumni_filter_index (status_keluar, tahun_angkatan, tingkat)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND INDEX_NAME = 'alumni_arsip_index') = 0,
    'ALTER TABLE alumni ADD KEY alumni_arsip_index (archived_at, tgl_keluar)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 8. Kunci asing.
--
-- `santri_id` memakai RESTRICT (bawaan): baris santri sumber tidak boleh
-- dihapus selama catatan alumninya masih ada. Sistem ini memang tidak pernah
-- menghapus santri — hanya mengarsipkannya — sehingga constraint ini adalah
-- jaring pengaman, bukan perubahan alur.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND CONSTRAINT_NAME = 'alumni_santri_fk') = 0,
    'ALTER TABLE alumni ADD CONSTRAINT alumni_santri_fk FOREIGN KEY (santri_id) REFERENCES santri (id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND CONSTRAINT_NAME = 'alumni_creator_fk') = 0,
    'ALTER TABLE alumni ADD CONSTRAINT alumni_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND CONSTRAINT_NAME = 'alumni_updater_fk') = 0,
    'ALTER TABLE alumni ADD CONSTRAINT alumni_updater_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 9. Melepas `UNIQUE KEY nis` LAMA — dijalankan PALING AKHIR.
--
-- Urutan ini disengaja: kunci unik pengganti (`alumni_nis_aktif_unique`) sudah
-- terpasang lebih dahulu, sehingga tidak ada satu momen pun ketika tabel berada
-- tanpa perlindungan NIS ganda untuk data aktif.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND INDEX_NAME = 'nis') > 0
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND INDEX_NAME = 'alumni_nis_aktif_unique') > 0,
    'ALTER TABLE alumni DROP INDEX nis',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
