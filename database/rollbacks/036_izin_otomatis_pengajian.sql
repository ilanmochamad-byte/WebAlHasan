-- Rollback status "Izin" otomatis pada absensi pengajian (migrasi 036).
-- Hanya untuk staging atau pemulihan terencana. Buat backup terverifikasi dulu.
--
-- Kolom usulan boleh dilepas walau berisi: usulan dapat dihitung ulang dari
-- izin, dan absensi yang sudah disimpan guru (`absensi_santri`) TIDAK
-- tersentuh. Akibatnya hanya pertemuan yang masih Dibuka kehilangan usulan
-- awal Izin di aplikasi (draf kembali dimulai "Hadir" seperti sebelum 036).
-- Sebelum menjalankan rollback, laporkan jumlahnya dengan kueri pemeriksaan
-- di docs/izin-otomatis-pengajian/implementasi/README.md (bagian rilis dan
-- pemulihan); Migrator tidak menampilkan hasil SELECT.
--
-- Kode tetap berjalan setelah rollback dengan perilaku baseline (label
-- "Sedang izin" saja, tanpa usulan), karena setiap pembacaan memeriksa skema.

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pertemuan_peserta' AND CONSTRAINT_NAME = 'pertemuan_peserta_izin_fk') = 1,
    'ALTER TABLE pertemuan_peserta DROP FOREIGN KEY pertemuan_peserta_izin_fk',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pertemuan_peserta' AND INDEX_NAME = 'pertemuan_peserta_izin_index') = 1,
    'ALTER TABLE pertemuan_peserta DROP INDEX pertemuan_peserta_izin_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pertemuan_peserta' AND COLUMN_NAME = 'izin_keterangan') = 1,
    'ALTER TABLE pertemuan_peserta DROP COLUMN izin_keterangan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pertemuan_peserta' AND COLUMN_NAME = 'izin_pengajuan_id') = 1,
    'ALTER TABLE pertemuan_peserta DROP COLUMN izin_pengajuan_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
