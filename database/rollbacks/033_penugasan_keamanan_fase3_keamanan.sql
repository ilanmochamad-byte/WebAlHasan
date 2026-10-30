-- Rollback Fase 3 penugasan lintas guru/pengurus (migrasi 033, penugasan Keamanan).
-- Hanya untuk staging atau pemulihan terencana. Buat backup terverifikasi dulu.
--
-- Rollback DITOLAK bila `keamanan_assignments` sudah berisi baris apa pun,
-- termasuk penugasan yang sudah berakhir atau dinonaktifkan: riwayat siapa yang
-- pernah memegang hak baca Keamanan tidak boleh hilang lewat rollback. Pulihkan
-- backup atau buat migrasi korektif.
--
-- Melepas hanya tabel `keamanan_assignments`. Pengurus, guru, users,
-- user_roles, penugasan lain, V1–V7, dan `audit_logs` (jejak `penugasan.*` dan
-- `keamanan.*`/`v3.*.dilihat.keamanan`) tidak disentuh. Kode tetap berjalan
-- setelah rollback: jenis Keamanan tidak menghasilkan capability, menu
-- Keamanan tidak tampil, dan pusat penugasan menampilkan "belum dipasang".

-- Penjaga data: bila tabel berisi baris, INSERT melanggar CHECK, Migrator
-- berhenti sebelum DROP, dan migrasi tetap tercatat. Memerlukan CHECK yang
-- ditegakkan (MariaDB 10.2+/MySQL 8.0.16+). Tabel yang belum ada (migrasi
-- separuh) dihitung nol lewat penjaga skema.
DROP TEMPORARY TABLE IF EXISTS penugasan_fase3_rollback_guard;
CREATE TEMPORARY TABLE penugasan_fase3_rollback_guard (jumlah BIGINT NOT NULL, CONSTRAINT penugasan_fase3_rollback_guard_kosong CHECK (jumlah = 0));
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'keamanan_assignments') = 1,
    'INSERT INTO penugasan_fase3_rollback_guard (jumlah) SELECT COUNT(*) FROM keamanan_assignments',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
DROP TEMPORARY TABLE penugasan_fase3_rollback_guard;

DROP TABLE IF EXISTS keamanan_assignments;
