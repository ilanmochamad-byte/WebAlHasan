-- Rollback paket "Koreksi Pengelolaan Alumni" (migrasi 011).
--
-- Hanya untuk staging atau pemulihan terencana. Buat backup terverifikasi dulu.
--
-- Rollback ini melepas kolom, indeks, dan kunci asing yang ditambahkan migrasi
-- 011, lalu MENCOBA memasang kembali `UNIQUE KEY nis (nis)` seperti semula.
--
-- ===========================================================================
-- PERINGATAN KEHILANGAN DATA YANG DISENGAJA
-- ===========================================================================
-- Setelah rollback, hal berikut HILANG dari skema (baris alumninya sendiri
-- TIDAK dihapus):
--
--   * referensi `santri_id` ke santri sumber;
--   * snapshot kelas/kamar terakhir;
--   * catatan, penanda arsip, jenis arsip, dan alasan arsip;
--   * jejak pelaku dan waktu (created_by / updated_by / created_at / updated_at).
--
-- Baris alumni yang tadinya BERSTATUS ARSIP akan kembali tampil sebagai
-- catatan biasa, karena penandanya sudah tidak ada. Jejak pengarsipan tetap
-- dapat ditelusuri pada `audit_logs` — tabel itu TIDAK disentuh rollback ini.
--
-- ===========================================================================
-- PEMERIKSAAN WAJIB SEBELUM ROLLBACK
-- ===========================================================================
-- Pemasangan kembali `UNIQUE KEY nis` HANYA berhasil bila tidak ada NIS ganda
-- di seluruh tabel (termasuk baris arsip). Jalankan lebih dahulu:
--
--   SELECT nis, COUNT(*) AS jumlah
--     FROM alumni
--    GROUP BY nis
--   HAVING COUNT(*) > 1;
--
-- Bila query itu mengembalikan baris, JANGAN lanjutkan sebelum memutuskan
-- baris mana yang dipertahankan. Rollback ini SENGAJA tidak menghapus atau
-- menggabungkan baris apa pun untuk Anda. Langkah 9 di bawah melewatkan
-- pemasangan kunci unik bila masih ada NIS ganda, sehingga rollback tetap
-- selesai tanpa galat, tetapi tabel berakhir TANPA kunci unik NIS. Kondisi itu
-- harus diselesaikan manual.

-- ---------------------------------------------------------------------------
-- 1. Kunci asing dilepas lebih dahulu (indeks penopangnya menyusul).
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND CONSTRAINT_NAME = 'alumni_updater_fk') = 1,
    'ALTER TABLE alumni DROP FOREIGN KEY alumni_updater_fk',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND CONSTRAINT_NAME = 'alumni_creator_fk') = 1,
    'ALTER TABLE alumni DROP FOREIGN KEY alumni_creator_fk',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND CONSTRAINT_NAME = 'alumni_santri_fk') = 1,
    'ALTER TABLE alumni DROP FOREIGN KEY alumni_santri_fk',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Indeks tambahan.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND INDEX_NAME = 'alumni_santri_aktif_unique') > 0,
    'ALTER TABLE alumni DROP INDEX alumni_santri_aktif_unique',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND INDEX_NAME = 'alumni_nis_aktif_unique') > 0,
    'ALTER TABLE alumni DROP INDEX alumni_nis_aktif_unique',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND INDEX_NAME = 'alumni_santri_index') > 0,
    'ALTER TABLE alumni DROP INDEX alumni_santri_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND INDEX_NAME = 'alumni_filter_index') > 0,
    'ALTER TABLE alumni DROP INDEX alumni_filter_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND INDEX_NAME = 'alumni_arsip_index') > 0,
    'ALTER TABLE alumni DROP INDEX alumni_arsip_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. Kolom turunan penjaga keunikan.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'nis_aktif_guard') = 1,
    'ALTER TABLE alumni DROP COLUMN nis_aktif_guard',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'santri_aktif_guard') = 1,
    'ALTER TABLE alumni DROP COLUMN santri_aktif_guard',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. Kolom jejak pelaku dan waktu.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'updated_at') = 1,
    'ALTER TABLE alumni DROP COLUMN updated_at',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'created_at') = 1,
    'ALTER TABLE alumni DROP COLUMN created_at',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'updated_by') = 1,
    'ALTER TABLE alumni DROP COLUMN updated_by',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'created_by') = 1,
    'ALTER TABLE alumni DROP COLUMN created_by',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 5. Kolom arsip dan catatan.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'alasan_arsip') = 1,
    'ALTER TABLE alumni DROP COLUMN alasan_arsip',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'jenis_arsip') = 1,
    'ALTER TABLE alumni DROP COLUMN jenis_arsip',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'archived_at') = 1,
    'ALTER TABLE alumni DROP COLUMN archived_at',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'catatan') = 1,
    'ALTER TABLE alumni DROP COLUMN catatan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 6. Snapshot penempatan terakhir.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'kamar_terakhir') = 1,
    'ALTER TABLE alumni DROP COLUMN kamar_terakhir',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'kelas_terakhir') = 1,
    'ALTER TABLE alumni DROP COLUMN kelas_terakhir',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 7. Referensi santri sumber.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = 'santri_id') = 1,
    'ALTER TABLE alumni DROP COLUMN santri_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 8. Indeks NIS non-unik bantuan migrasi 011.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND INDEX_NAME = 'alumni_nis_index') > 0,
    'ALTER TABLE alumni DROP INDEX alumni_nis_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 9. Memasang kembali `UNIQUE KEY nis (nis)` — HANYA bila tidak ada NIS ganda.
--
-- Bila masih ada NIS ganda, pernyataan ini dilewati dengan sengaja agar
-- rollback tidak berhenti di tengah jalan. Periksa ulang dengan query pada
-- bagian "PEMERIKSAAN WAJIB" di atas lalu pasang manual:
--   ALTER TABLE alumni ADD UNIQUE KEY nis (nis);
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND INDEX_NAME = 'nis') = 0
    AND (SELECT COUNT(*) FROM (SELECT nis FROM alumni GROUP BY nis HAVING COUNT(*) > 1) ganda) = 0,
    'ALTER TABLE alumni ADD UNIQUE KEY nis (nis)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
