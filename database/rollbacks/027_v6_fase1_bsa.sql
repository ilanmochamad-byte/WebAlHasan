-- Rollback PRD V6 Fase 1 (migrasi 027, fondasi BSA).
-- Hanya untuk staging atau pemulihan terencana. Buat backup terverifikasi dulu
-- dan jalankan `php bin/v6_phase1_rollback_preflight.php`: rollback DITOLAK
-- bila tabel BSA sudah berisi data. Transaksi uang tidak boleh hilang lewat
-- rollback; pulihkan backup atau buat migrasi korektif.
--
-- Melepas enam tabel baru V6 Fase 1, urut dari anak ke induk.
--
-- Yang TIDAK disentuh: santri, wali, santri_wali, users, pengurus,
-- tahun_ajaran, `bendahara_bulanan_assignments` beserta seluruh penugasan
-- lain, tabel PSB, tabel V1–V5, dan `audit_logs` (jejak `bsa.*`/`penugasan.*`
-- tetap tersimpan sebagai riwayat).
--
-- Kode aplikasi Fase 1 tetap berjalan setelah rollback ini: halaman dan API
-- BSA menampilkan "belum dipasang" (503), menu Keuangan tidak tampil, dan
-- pusat penugasan tetap dapat mengelola penugasan Petugas BSA.

-- Penjaga data: rollback ini juga dapat dijalankan lewat
-- `bin/migrate.php rollback` tanpa preflight. Bila salah satu tabel 027 berisi
-- baris (tagihan, pembayaran, ledger, akun, atau klaim idempotensi), INSERT
-- melanggar CHECK, Migrator berhenti sebelum DROP mana pun, dan migrasi tetap
-- tercatat. Tabel TEMPORARY hilang sendiri saat sesi berakhir.
-- Memerlukan CHECK yang ditegakkan (MariaDB 10.2+/MySQL 8.0.16+).
DROP TEMPORARY TABLE IF EXISTS v6_fase1_rollback_guard;
CREATE TEMPORARY TABLE v6_fase1_rollback_guard (jumlah BIGINT NOT NULL, CONSTRAINT v6_fase1_rollback_guard_kosong CHECK (jumlah = 0));
INSERT INTO v6_fase1_rollback_guard (jumlah) SELECT (SELECT COUNT(*) FROM bsa_idempotensi) + (SELECT COUNT(*) FROM bsa_pembayaran) + (SELECT COUNT(*) FROM bsa_tabungan_akun) + (SELECT COUNT(*) FROM bsa_tabungan_transaksi) + (SELECT COUNT(*) FROM bsa_tagihan_komponen) + (SELECT COUNT(*) FROM bsa_tagihan);
DROP TEMPORARY TABLE v6_fase1_rollback_guard;

DROP TABLE IF EXISTS bsa_idempotensi;
DROP TABLE IF EXISTS bsa_pembayaran;
DROP TABLE IF EXISTS bsa_tabungan_akun;
DROP TABLE IF EXISTS bsa_tabungan_transaksi;
DROP TABLE IF EXISTS bsa_tagihan_komponen;
DROP TABLE IF EXISTS bsa_tagihan;
