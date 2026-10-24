-- Rollback PRD V5 Fase 1 (migrasi 024).
-- Hanya untuk staging atau pemulihan terencana. Buat backup terverifikasi dulu
-- dan jalankan `php bin/v5_phase1_rollback_preflight.php`: rollback DITOLAK
-- prosedur bila tabel kesehatan sudah berisi data. Catatan klinis tidak boleh
-- hilang lewat rollback; pulihkan backup atau buat migrasi korektif.
--
-- Melepas lima tabel baru V5 Fase 1, urut dari anak ke induk.
--
-- Yang TIDAK disentuh: santri, wali, santri_wali, users, pengurus,
-- tahun_ajaran, seluruh tabel penugasan lain, tabel V1–V4, dan `audit_logs`
-- (jejak `penugasan.*`/`kesehatan.*` tetap tersimpan sebagai riwayat).
--
-- Kode aplikasi Fase 1 tetap berjalan setelah rollback ini: halaman dan API
-- kesehatan menampilkan "belum dipasang", penugasan jenis kesehatan tidak
-- menghasilkan capability, dan pusat penugasan tetap dapat dibuka.

DROP TABLE IF EXISTS kesehatan_akses_log;
DROP TABLE IF EXISTS kesehatan_idempotensi;
DROP TABLE IF EXISTS kesehatan_kunjungan_versi;
DROP TABLE IF EXISTS kesehatan_kunjungan;
DROP TABLE IF EXISTS kesehatan_assignments;
