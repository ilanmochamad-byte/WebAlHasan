-- Status "Izin" otomatis pada absensi pengajian — migrasi 036
--
-- Keputusan Human Developer 23 November 2026 (revisi keputusan S, IP-1…IP-6);
-- rencana docs/izin-otomatis-pengajian/rencana.md §3.2. Branch
-- `claude/izin-otomatis-absensi`. Implementator Claude Code, auditor Codex.
--
-- Sifat migrasi: ADITIF dan DAPAT DIJALANKAN ULANG. Dua kolom NULL pada
-- `pertemuan_peserta` beserta indeks dan kunci asing. Tidak ada UPDATE,
-- DELETE, atau TRUNCATE; baris peserta lama tetap NULL (pertemuan lama tidak
-- diisi ulang). `absensi_santri` dan seluruh tabel `izin_*` tidak disentuh.
--
-- "Izin" otomatis adalah USULAN status awal yang dibekukan pada peserta saat
-- pertemuan dibuka atau "Perbarui daftar peserta", BUKAN baris absensi.
-- Status Izin baru tersimpan di `absensi_santri` ketika guru menyimpan absensi
-- dari aplikasi (keputusan R), sehingga pencatat, audit, idempotensi, dan
-- koreksi beralasan tetap seperti sebelumnya.
--
--   izin_pengajuan_id : izin Disetujui (murobi/admin pengganti) yang mencakup
--                       tanggal pertemuan; NULL = tanpa usulan.
--   izin_keterangan   : teks umum IP-3 yang dibekukan, mis.
--                       "Izin No. 123 — disetujui murobi s.d. 25 Nov".
--                       Tidak pernah memuat alasan, catatan, keputusan, atau
--                       nama pengaju.

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pertemuan_peserta' AND COLUMN_NAME = 'izin_pengajuan_id') = 0,
    'ALTER TABLE pertemuan_peserta ADD COLUMN izin_pengajuan_id BIGINT UNSIGNED NULL DEFAULT NULL COMMENT ''IP-1: usulan status Izin (izin Disetujui yang mencakup tanggal pertemuan).''',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pertemuan_peserta' AND COLUMN_NAME = 'izin_keterangan') = 0,
    'ALTER TABLE pertemuan_peserta ADD COLUMN izin_keterangan VARCHAR(160) NULL DEFAULT NULL COMMENT ''IP-3: keterangan umum usulan Izin yang dibekukan, tanpa alasan.'' AFTER izin_pengajuan_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pertemuan_peserta' AND INDEX_NAME = 'pertemuan_peserta_izin_index') = 0,
    'ALTER TABLE pertemuan_peserta ADD KEY pertemuan_peserta_izin_index (izin_pengajuan_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pertemuan_peserta' AND CONSTRAINT_NAME = 'pertemuan_peserta_izin_fk') = 0,
    'ALTER TABLE pertemuan_peserta ADD CONSTRAINT pertemuan_peserta_izin_fk FOREIGN KEY (izin_pengajuan_id) REFERENCES izin_pengajuan (id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
