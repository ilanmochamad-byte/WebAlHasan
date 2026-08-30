-- Paket perapihan V1–V2 — Koreksi ke-2: rekonsiliasi identitas wali
--
-- Keputusan pengguna 30 Agustus 2026.
--
-- Sifat migrasi: SEPENUHNYA ADITIF DAN DAPAT DIJALANKAN ULANG (idempoten).
-- Tidak ada DROP TABLE, DROP COLUMN, DELETE, UPDATE, atau TRUNCATE terhadap
-- data mana pun. Kolom lama `santri.nama_ayah`, `santri.no_hp_ayah`,
-- `santri.nama_ibu`, dan `santri.no_hp_ibu` TIDAK dihapus dan TIDAK diubah oleh
-- migrasi ini.
--
-- Yang ditambahkan hanya SATU kolom penanda pada tabel `wali`:
--
--   wali.merged_into_wali_id  INT NULL
--
-- Kolom ini menyimpan jejak penggabungan identitas wali yang dikonfirmasi
-- admin. Baris wali sumber TIDAK PERNAH DIHAPUS: ID lamanya dipertahankan,
-- barisnya diarsipkan, dan kolom ini menunjuk ke identitas tujuan. Dengan
-- begitu laporan, ekspor, dan riwayat lama yang masih menyebut ID lama tetap
-- dapat ditelusuri.
--
-- Penggabungan itu sendiri TIDAK dilakukan oleh migrasi ini. Tidak ada
-- penggabungan massal, tidak ada penebakan identitas berdasarkan nama atau
-- nomor HP. Seluruh penggabungan dijalankan satu per satu dari halaman
-- Rekonsiliasi Wali setelah admin mengonfirmasi identitas dan santri yang
-- tepat, dan dicatat pada `audit_logs`.
--
-- WAJIB sebelum dijalankan di produksi:
--   1. backup terverifikasi (lihat docs/perapihan-v1-v2/migrasi-dan-rollback.md);
--   2. uji lengkap pada salinan MySQL berakhiran `_test`;
--   3. rollback berpasangan tersedia di database/rollbacks/010_*.sql.

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wali' AND COLUMN_NAME = 'merged_into_wali_id') = 0,
    'ALTER TABLE wali ADD COLUMN merged_into_wali_id INT NULL DEFAULT NULL COMMENT ''Diisi bila identitas ini digabungkan ke wali lain atas konfirmasi admin. Baris tetap disimpan.''',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Indeks penelusuran: mencari seluruh identitas yang digabungkan ke satu wali.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wali' AND INDEX_NAME = 'wali_merged_into_index') = 0,
    'ALTER TABLE wali ADD KEY wali_merged_into_index (merged_into_wali_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Indeks bantu laporan rekonsiliasi: pencarian kandidat berdasarkan nomor HP.
-- BUKAN kunci unik. Nomor HP SENGAJA boleh dipakai bersama beberapa wali
-- (keputusan pengguna: nomor HP bukan identitas unik wajib).
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wali' AND INDEX_NAME = 'wali_no_hp_index') = 0,
    'ALTER TABLE wali ADD KEY wali_no_hp_index (no_hp)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
