-- Rollback PRD V5 Fase 2 (migrasi 025).
-- Hanya untuk staging atau pemulihan terencana. Buat backup terverifikasi dulu
-- dan jalankan `php bin/v5_phase2_rollback_preflight.php`: rollback DITOLAK
-- prosedur bila tabel rincian kesehatan sudah berisi data. Obat, rujukan,
-- tindak lanjut, dan snapshot versi tidak boleh hilang lewat rollback;
-- pulihkan backup atau buat migrasi korektif.
--
-- Melepas empat tabel baru V5 Fase 2, urut dari anak ke induk.
--
-- Yang TIDAK disentuh: lima tabel kesehatan migrasi 024 (penugasan,
-- kunjungan, versi, idempotensi, log akses), santri, wali, santri_wali,
-- users, pengurus, guru, penugasan pembimbing/murobi, tabel V1–V4, dan
-- `audit_logs`.
--
-- Kode Fase 2 tetap berjalan setelah rollback ini selama migrasi 024 masih
-- terpasang: layanan kesehatan menjawab "belum dipasang" (503) sampai 025
-- dipasang kembali, pusat penugasan tetap dapat dibuka.

-- Penjaga data (PRD V5 Fase 5): rollback ini juga dapat dijalankan lewat
-- `bin/migrate.php rollback` tanpa preflight. Bila salah satu tabel 025 berisi baris (rincian klinis atau snapshot versi),
-- INSERT melanggar CHECK, Migrator berhenti sebelum DROP mana pun, dan
-- migrasi tetap tercatat. Tabel TEMPORARY hilang sendiri saat sesi berakhir.
-- Memerlukan CHECK yang ditegakkan (MariaDB 10.2+/MySQL 8.0.16+).
DROP TEMPORARY TABLE IF EXISTS v5_fase2_rollback_guard;
CREATE TEMPORARY TABLE v5_fase2_rollback_guard (jumlah BIGINT NOT NULL, CONSTRAINT v5_fase2_rollback_guard_kosong CHECK (jumlah = 0));
INSERT INTO v5_fase2_rollback_guard (jumlah) SELECT (SELECT COUNT(*) FROM kesehatan_versi_rincian) + (SELECT COUNT(*) FROM kesehatan_tindak_lanjut) + (SELECT COUNT(*) FROM kesehatan_rujukan) + (SELECT COUNT(*) FROM kesehatan_obat);
DROP TEMPORARY TABLE v5_fase2_rollback_guard;

DROP TABLE IF EXISTS kesehatan_versi_rincian;
DROP TABLE IF EXISTS kesehatan_tindak_lanjut;
DROP TABLE IF EXISTS kesehatan_rujukan;
DROP TABLE IF EXISTS kesehatan_obat;
