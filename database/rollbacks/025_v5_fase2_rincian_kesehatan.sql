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

DROP TABLE IF EXISTS kesehatan_versi_rincian;
DROP TABLE IF EXISTS kesehatan_tindak_lanjut;
DROP TABLE IF EXISTS kesehatan_rujukan;
DROP TABLE IF EXISTS kesehatan_obat;
