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

DROP TABLE IF EXISTS kesehatan_ringkasan_penerima;
DROP TABLE IF EXISTS kesehatan_ringkasan_peristiwa;
DROP TABLE IF EXISTS kesehatan_ringkasan_versi;
DROP TABLE IF EXISTS kesehatan_ringkasan;
