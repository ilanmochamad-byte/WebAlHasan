-- Rollback PRD V6 Fase 3 (migrasi 029, tabungan lengkap dan pembayaran silang).
-- Hanya untuk staging atau pemulihan terencana. Buat backup terverifikasi dulu
-- dan jalankan `php bin/v6_phase3_rollback_preflight.php`: rollback DITOLAK
-- bila sudah ada data Fase 3 — setoran transfer (menunggu/sah/ditolak),
-- baris ledger 027 berjenis Belanja/Pembalik/PembayaranTagihan, atau
-- pembayaran tagihan bersumber Tabungan. Data itu tidak boleh kehilangan
-- konteks Fase 3 lewat rollback; pulihkan backup atau buat migrasi korektif.
--
-- Melepas satu tabel baru Fase 3: `bsa_setoran_transfer`.
--
-- Yang TIDAK disentuh: tabel 027 dan 028, santri, wali, santri_wali, users,
-- pengurus, penugasan, PSB, V1–V5, dan `audit_logs`.
--
-- Kode Fase 3 tetap berjalan setelah rollback ini: fitur Fase 3 (setoran
-- transfer menunggu verifikasi, belanja, koreksi pembalik, pembayaran dari
-- tabungan, kuitansi tabungan, rekonsiliasi) menjawab 503 "belum dipasang";
-- fitur Fase 1–2 tetap berjalan seperti sebelum 029.

-- Penjaga data: bila ada data Fase 3, INSERT melanggar CHECK, Migrator
-- berhenti sebelum DROP, dan migrasi tetap tercatat. Memerlukan CHECK yang
-- ditegakkan.
DROP TEMPORARY TABLE IF EXISTS v6_fase3_rollback_guard;
CREATE TEMPORARY TABLE v6_fase3_rollback_guard (jumlah BIGINT NOT NULL, CONSTRAINT v6_fase3_rollback_guard_kosong CHECK (jumlah = 0));
INSERT INTO v6_fase3_rollback_guard (jumlah) SELECT (SELECT COUNT(*) FROM bsa_setoran_transfer) + (SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE jenis IN ('Belanja', 'Pembalik', 'PembayaranTagihan') OR metode = 'Internal') + (SELECT COUNT(*) FROM bsa_pembayaran WHERE sumber = 'Tabungan');
DROP TEMPORARY TABLE v6_fase3_rollback_guard;

DROP TABLE IF EXISTS bsa_setoran_transfer;
