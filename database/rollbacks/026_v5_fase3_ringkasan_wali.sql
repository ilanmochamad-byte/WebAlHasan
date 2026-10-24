-- Rollback PRD V5 Fase 3 — migrasi 026 (ringkasan wali kesehatan).
--
-- HANYA dijalankan bila `php bin/v5_phase3_rollback_preflight.php` lulus:
-- keempat tabel ringkasan KOSONG. Bila sudah ada ringkasan terbit, pulihkan
-- backup atau buat migrasi korektif — jangan rollback. Baris
-- `notifikasi_outbox` milik V5 (event_type `v5_kesehatan_%`) tidak dihapus di
-- sini; preflight menolak rollback bila baris itu ada.
--
-- Urutan mengikuti kunci asing (anak lebih dahulu). Tabel 024–025 dan tabel
-- notifikasi V2 tidak disentuh.

-- Penjaga data (PRD V5 Fase 5): rollback ini juga dapat dijalankan lewat
-- `bin/migrate.php rollback` tanpa preflight. Bila ada ringkasan wali atau notifikasi V5 (event_type v5_kesehatan_%),
-- INSERT melanggar CHECK, Migrator berhenti sebelum DROP mana pun, dan
-- migrasi tetap tercatat. Tabel TEMPORARY hilang sendiri saat sesi berakhir.
-- Memerlukan CHECK yang ditegakkan (MariaDB 10.2+/MySQL 8.0.16+).
DROP TEMPORARY TABLE IF EXISTS v5_fase3_rollback_guard;
CREATE TEMPORARY TABLE v5_fase3_rollback_guard (jumlah BIGINT NOT NULL, CONSTRAINT v5_fase3_rollback_guard_kosong CHECK (jumlah = 0));
INSERT INTO v5_fase3_rollback_guard (jumlah) SELECT (SELECT COUNT(*) FROM kesehatan_ringkasan_penerima) + (SELECT COUNT(*) FROM kesehatan_ringkasan_peristiwa) + (SELECT COUNT(*) FROM kesehatan_ringkasan_versi) + (SELECT COUNT(*) FROM kesehatan_ringkasan) + (SELECT COUNT(*) FROM notifikasi_outbox WHERE event_type LIKE 'v5\\_kesehatan\\_%');
DROP TEMPORARY TABLE v5_fase3_rollback_guard;

DROP TABLE IF EXISTS kesehatan_ringkasan_penerima;
DROP TABLE IF EXISTS kesehatan_ringkasan_peristiwa;
DROP TABLE IF EXISTS kesehatan_ringkasan_versi;
DROP TABLE IF EXISTS kesehatan_ringkasan;
