-- Rollback PRD V7 Fase 2 (migrasi 031, tinjauan pembimbing dan perbaikan wali).
-- Hanya untuk staging atau pemulihan terencana. Buat backup terverifikasi dulu
-- dan jalankan `php bin/v7_phase2_rollback_preflight.php`: rollback DITOLAK
-- bila sudah ada data Fase 2 — baris tinjauan/perbaikan, kunci idempotensi
-- operasi `izin.wali.tinjauan|perbaikan|batal`, atau pengajuan wali yang sudah
-- melewati tahap Fase 1 (diperbaiki, ditolak pembimbing, diteruskan, diputus,
-- atau dibatalkan). Jejak itu tidak boleh hilang lewat rollback; pulihkan
-- backup atau buat migrasi korektif.
--
-- Melepas hanya tabel `izin_wali_perbaikan` dan `izin_pembimbing_tinjauan`.
-- Yang TIDAK disentuh: seluruh kolom/baris `izin_pengajuan` (termasuk kolom
-- V7 migrasi 030), keputusan, riwayat, idempotensi, `perizinan`, santri,
-- wali, relasi, users, penugasan, PSB, V1–V6, dan `audit_logs`.
--
-- Kode tetap berjalan setelah rollback ini: tindakan Fase 2 menjawab 503
-- "belum dipasang"; pengajuan dan penetapan pembimbing Fase 1 tetap tersedia.

-- Penjaga data: bila ada data Fase 2, INSERT melanggar CHECK, Migrator berhenti
-- sebelum perubahan skema, dan migrasi tetap tercatat. Memerlukan CHECK yang
-- ditegakkan (MariaDB 10.2+/MySQL 8.0.16+).
DROP TEMPORARY TABLE IF EXISTS v7_fase2_rollback_guard;
CREATE TEMPORARY TABLE v7_fase2_rollback_guard (jumlah BIGINT NOT NULL, CONSTRAINT v7_fase2_rollback_guard_kosong CHECK (jumlah = 0));
INSERT INTO v7_fase2_rollback_guard (jumlah)
SELECT (SELECT COUNT(*) FROM izin_pembimbing_tinjauan)
     + (SELECT COUNT(*) FROM izin_wali_perbaikan)
     + (SELECT COUNT(*) FROM izin_idempotency_keys
         WHERE operation IN ('izin.wali.tinjauan', 'izin.wali.perbaikan', 'izin.wali.batal'))
     + (SELECT COUNT(*) FROM izin_pengajuan
         WHERE asal_pengajuan = 'Wali' AND status NOT IN ('Menunggu Pembimbing', 'Perlu Penetapan Pembimbing'));
DROP TEMPORARY TABLE v7_fase2_rollback_guard;

DROP TABLE IF EXISTS izin_wali_perbaikan;
DROP TABLE IF EXISTS izin_pembimbing_tinjauan;
