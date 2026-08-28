-- V2 Fase 5: Laporan, Pencetakan, dan Kesiapan Rilis
--
-- Sifat migrasi: SEPENUHNYA ADITIF DAN DAPAT DIJALANKAN ULANG (idempoten).
-- Tidak ada DROP TABLE, DROP COLUMN, DELETE, atau TRUNCATE terhadap data V1/V2.
-- Tabel `perizinan` lama TIDAK diubah dan TIDAK dihapus.
-- Tidak ada satu pun kolom laporan baru: laporan Fase 5 membaca tabel yang
-- sudah ada (`izin_pengajuan`, `izin_keputusan`, `izin_riwayat_status`,
-- `notifikasi_outbox`) tanpa denormalisasi.
--
-- WAJIB sebelum dijalankan (lihat docs/phase-v2-5/migration-and-rollback.md):
--   1. php bin/v2_phase5_preflight.php  -> backup + manifest + laporan konflik.
--   2. Uji lengkap pada salinan MySQL berakhiran `_test` terlebih dahulu.
--   3. php bin/v2_phase5_verify.php setelah migrasi.
--
-- ===========================================================================
-- CATATAN INDEKS LAPORAN — KEPUTUSAN BERBASIS PENGUKURAN
-- ===========================================================================
-- PRD Fase 5 §6 mensyaratkan indeks ditambahkan HANYA bila didukung hasil
-- pengukuran. Pengukuran dilakukan pada fixture 1.004 dan 20.004 pengajuan
-- (bin/v2_phase5_fixture.php + bin/v2_phase5_ukur_laporan.php); hasil lengkap
-- ada pada docs/phase-v2-5/bukti-performa.md.
--
-- Ringkasnya:
--   - pada 1.004 pengajuan halaman pertama selesai <= 18,1 ms (target 2.000 ms);
--   - pada 20.004 pengajuan halaman pertama selesai <= 397,7 ms;
--   - tiga indeks kandidat DIUJI dan DIBUANG kembali karena selisihnya masih
--     di dalam derau pengukuran (9 pengulangan) dan tidak dapat diulang:
--       * izin_pengajuan (tgl_izin, id)
--       * plotting_kamar (id_santri, id_tahun)
--       * plotting_kelas (id_santri, id_tahun, status)
--   - `EXPLAIN` menunjukkan query laporan SUDAH memakai indeks yang dibuat
--     migrasi 006/007: izin_pengajuan_santri_range_index,
--     izin_pengajuan_status_index, izin_pengajuan_pengurus_index,
--     izin_pengajuan_murobi_index, izin_keputusan_pengajuan_unique, dan
--     notifikasi_pengajuan_index.
--
-- KARENA ITU migrasi ini SENGAJA TIDAK menambahkan indeks laporan. Menambahkan
-- indeks yang tidak terbukti hanya memperlambat penulisan tanpa mempercepat
-- pembacaan. Ambang peninjauan ulang tercatat pada bukti-performa.md.
--
-- ===========================================================================
-- ISI MIGRASI: penyelesaian temuan terbuka Fase 4 — push receipt akhir.
-- ===========================================================================
-- Temuan Fase 4 (acceptance-status.md §5): server baru memeriksa TIKET AWAL
-- Expo. Tiket hanya membuktikan Expo MENERIMA pesan, bukan bahwa FCM/APNs
-- benar-benar mengantarkannya. Kolom di bawah menyimpan id tiket dan hasil
-- pengambilan receipt akhir sehingga status `Sent` dapat direkonsiliasi
-- menjadi terkirim atau gagal berdasarkan jawaban akhir penyedia.
--
-- Seluruh kolom NULLABLE atau memiliki DEFAULT, sehingga baris outbox lama
-- tetap valid tanpa backfill dan tanpa perubahan nilai.

-- ---------------------------------------------------------------------------
-- 1. Id tiket Expo untuk baris yang sudah dikirim.
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'tiket_id') = 0,
    'ALTER TABLE notifikasi_outbox ADD COLUMN tiket_id VARCHAR(120) NULL AFTER dikirim_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Status receipt akhir.
--    `Belum Diperlukan` = kanal non-push atau belum terkirim.
--    `Menunggu`         = tiket diterima, receipt akhir belum diambil.
--    `Terkirim`         = penyedia mengonfirmasi pengantaran.
--    `Gagal`            = penyedia menolak/gagal mengantarkan.
--    `Tidak Tersedia`   = penyedia tidak mengembalikan receipt dalam batas waktu.
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'receipt_status') = 0,
    "ALTER TABLE notifikasi_outbox ADD COLUMN receipt_status ENUM('Belum Diperlukan','Menunggu','Terkirim','Gagal','Tidak Tersedia') NOT NULL DEFAULT 'Belum Diperlukan' AFTER tiket_id",
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. Kode dan pesan receipt. Keduanya WAJIB sudah melewati `SafeError` sebelum
--    disimpan: tidak boleh memuat token perangkat, nomor, atau credential.
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'receipt_kode') = 0,
    'ALTER TABLE notifikasi_outbox ADD COLUMN receipt_kode VARCHAR(60) NULL AFTER receipt_status',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'receipt_pesan') = 0,
    'ALTER TABLE notifikasi_outbox ADD COLUMN receipt_pesan VARCHAR(255) NULL AFTER receipt_kode',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'receipt_diperiksa_pada') = 0,
    'ALTER TABLE notifikasi_outbox ADD COLUMN receipt_diperiksa_pada DATETIME NULL AFTER receipt_pesan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'receipt_percobaan') = 0,
    'ALTER TABLE notifikasi_outbox ADD COLUMN receipt_percobaan SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER receipt_diperiksa_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. Indeks pengambil receipt.
--    Indeks ini TERBUKTI diperlukan, bukan spekulatif: worker receipt mencari
--    baris `receipt_status = 'Menunggu'` yang paling lama menunggu, dan tanpa
--    indeks ini query tersebut memindai seluruh outbox pada setiap putaran
--    cron (setiap menit). Pola aksesnya diketahui pasti karena hanya ada satu
--    pemanggil, yaitu `OutboxRepository::claimForReceipts()`.
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox'
        AND INDEX_NAME = 'notifikasi_receipt_index') = 0,
    'ALTER TABLE notifikasi_outbox ADD KEY notifikasi_receipt_index (receipt_status, dikirim_pada, id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
