-- Rollback V2 Fase 1 hanya untuk staging atau pemulihan terencana.
-- Buat backup terverifikasi terlebih dahulu: rollback ini menghapus seluruh tabel
-- baru V2 beserta isinya. Tabel `perizinan` lama TIDAK disentuh sama sekali,
-- sehingga ID dan nilai bisnis izin warisan tetap utuh setelah rollback.
--
-- Urutan drop mengikuti arah foreign key (anak lebih dulu).

DROP TABLE IF EXISTS notifikasi_outbox;
DROP TABLE IF EXISTS perangkat_push;
DROP TABLE IF EXISTS pengaturan_notifikasi;
DROP TABLE IF EXISTS izin_idempotency_keys;
DROP TABLE IF EXISTS izin_riwayat_status;
DROP TABLE IF EXISTS izin_keputusan;
DROP TABLE IF EXISTS izin_pengajuan;
DROP TABLE IF EXISTS pembimbing_assignments;

ALTER TABLE users
    DROP FOREIGN KEY users_wali_fk,
    DROP FOREIGN KEY users_pengurus_fk,
    DROP INDEX users_wali_unique,
    DROP INDEX users_pengurus_unique,
    DROP COLUMN wali_id,
    DROP COLUMN pengurus_id;

DELETE FROM user_roles WHERE role_id IN (SELECT id FROM roles WHERE slug IN ('pengurus', 'orang_tua'));
DELETE FROM roles WHERE slug IN ('pengurus', 'orang_tua');
