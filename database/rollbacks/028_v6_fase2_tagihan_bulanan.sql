-- Rollback PRD V6 Fase 2 (migrasi 028, tagihan bulanan lengkap).
-- Hanya untuk staging atau pemulihan terencana. Buat backup terverifikasi dulu
-- dan jalankan `php bin/v6_phase2_rollback_preflight.php`: rollback DITOLAK
-- bila tabel Fase 2 sudah berisi data atau `bsa_pembayaran` (027) sudah berisi
-- pembayaran. Tarif, penyesuaian, riwayat tagihan, kuitansi, dan pembatalan
-- tidak boleh hilang lewat rollback; pulihkan backup atau buat migrasi
-- korektif.
--
-- Melepas sembilan tabel baru Fase 2, urut dari anak ke induk.
--
-- Yang TIDAK disentuh: keenam tabel 027 (`bsa_tagihan`, `bsa_tagihan_komponen`,
-- `bsa_tabungan_akun`, `bsa_tabungan_transaksi`, `bsa_pembayaran`,
-- `bsa_idempotensi`), santri, wali, santri_wali, users, pengurus,
-- penugasan, PSB, V1–V5, dan `audit_logs`.
--
-- Kode Fase 2 tetap berjalan setelah rollback ini: fitur tarif, penerbitan
-- massal, pembayaran, kuitansi, dan tunggakan menjawab 503 "belum dipasang";
-- fitur Fase 1 (setoran, penarikan, satu tagihan manual) tetap berjalan.

-- Penjaga data: bila salah satu tabel 028 berisi baris atau sudah ada
-- pembayaran tagihan, INSERT melanggar CHECK, Migrator berhenti sebelum DROP
-- mana pun, dan migrasi tetap tercatat. Memerlukan CHECK yang ditegakkan.
DROP TEMPORARY TABLE IF EXISTS v6_fase2_rollback_guard;
CREATE TEMPORARY TABLE v6_fase2_rollback_guard (jumlah BIGINT NOT NULL, CONSTRAINT v6_fase2_rollback_guard_kosong CHECK (jumlah = 0));
INSERT INTO v6_fase2_rollback_guard (jumlah) SELECT (SELECT COUNT(*) FROM bsa_pembayaran_pembatalan) + (SELECT COUNT(*) FROM bsa_pembayaran_kuitansi) + (SELECT COUNT(*) FROM bsa_tagihan_riwayat) + (SELECT COUNT(*) FROM bsa_tagihan_penyesuaian) + (SELECT COUNT(*) FROM bsa_penerbitan_santri) + (SELECT COUNT(*) FROM bsa_penerbitan) + (SELECT COUNT(*) FROM bsa_penyesuaian) + (SELECT COUNT(*) FROM bsa_tarif_komponen) + (SELECT COUNT(*) FROM bsa_tarif) + (SELECT COUNT(*) FROM bsa_pembayaran);
DROP TEMPORARY TABLE v6_fase2_rollback_guard;

DROP TABLE IF EXISTS bsa_pembayaran_pembatalan;
DROP TABLE IF EXISTS bsa_pembayaran_kuitansi;
DROP TABLE IF EXISTS bsa_tagihan_riwayat;
DROP TABLE IF EXISTS bsa_tagihan_penyesuaian;
DROP TABLE IF EXISTS bsa_penerbitan_santri;
DROP TABLE IF EXISTS bsa_penerbitan;
DROP TABLE IF EXISTS bsa_penyesuaian;
DROP TABLE IF EXISTS bsa_tarif_komponen;
DROP TABLE IF EXISTS bsa_tarif;
