-- Rollback Fase 1 perbaikan jadwal kelas gabungan (migrasi 034).
-- Hanya untuk staging atau pemulihan terencana. Buat backup terverifikasi dulu.
--
-- Rollback DITOLAK bila sudah ada data gabungan yang akan hilang:
--   * jadwal yang memiliki kelas selain kelas utama (`jadwal_kelas.is_utama = 0`);
--   * pertemuan yang dibekukan dengan lebih dari satu kelas.
-- Pulihkan backup atau buat migrasi korektif bila keadaan itu sudah terjadi.
--
-- Selama jadwal masih satu kelas, kedua tabel hanya cermin `jadwal_ngaji.id_kelas`
-- dan kelas snapshot peserta, sehingga dapat dilepas tanpa kehilangan isi.
-- Tabel lama (jadwal, pertemuan, peserta, absensi, audit) tidak disentuh, dan
-- kode tetap berjalan setelah rollback dengan perilaku satu kelas.

-- Penjaga data: bila ada data gabungan, INSERT melanggar CHECK, Migrator
-- berhenti sebelum DROP, dan migrasi tetap tercatat. Memerlukan CHECK yang
-- ditegakkan (MariaDB 10.2+/MySQL 8.0.16+). Tabel yang belum ada (migrasi
-- separuh) dihitung nol lewat penjaga skema.
DROP TEMPORARY TABLE IF EXISTS jadwal_gabungan_rollback_guard;
CREATE TEMPORARY TABLE jadwal_gabungan_rollback_guard (jumlah BIGINT NOT NULL, CONSTRAINT jadwal_gabungan_rollback_guard_kosong CHECK (jumlah = 0));
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jadwal_kelas') = 1,
    'INSERT INTO jadwal_gabungan_rollback_guard (jumlah) SELECT COUNT(*) FROM jadwal_kelas WHERE is_utama = 0',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pertemuan_kelas') = 1,
    'INSERT INTO jadwal_gabungan_rollback_guard (jumlah) SELECT COUNT(*) FROM (SELECT pertemuan_id FROM pertemuan_kelas GROUP BY pertemuan_id HAVING COUNT(*) > 1) x',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
DROP TEMPORARY TABLE jadwal_gabungan_rollback_guard;

DROP TABLE IF EXISTS pertemuan_kelas;
DROP TABLE IF EXISTS jadwal_kelas;
