-- Rollback Fase 1 profil pengguna dan impor guru/pengurus (migrasi 035).
-- Hanya untuk staging atau pemulihan terencana. Buat backup terverifikasi dulu.
--
-- Rollback DITOLAK bila tabel `profil_pribadi` sudah berisi baris apa pun: data
-- pribadi (tempat/tanggal lahir, alamat berjenjang, email, NIK, foto) tidak boleh
-- hilang karena rollback. Pulihkan backup atau buat migrasi korektif. Berkas foto
-- di storage/private/profil tidak pernah dihapus rollback.
--
-- Bila tabel kosong: token aplikasi `ganti_password` yang masih berlaku DICABUT
-- lebih dulu (kode lama tidak mengenal pembatasan itu, sehingga token tersebut
-- akan menjadi token penuh), lalu kolom `api_tokens.cakupan`, `users.sesi_versi`,
-- dan tabel kosong dilepas. Baris guru, pengurus, wali, users, penugasan, dan
-- `audit_logs` tidak disentuh. Kode tetap berjalan setelah rollback: fitur profil
-- melapor "belum dipasang", sesi web tidak lagi diperiksa versinya.

-- Penjaga data: bila ada baris profil, INSERT melanggar CHECK, Migrator berhenti
-- sebelum perubahan skema, dan migrasi tetap tercatat.
DROP TEMPORARY TABLE IF EXISTS profil_fase1_rollback_guard;
CREATE TEMPORARY TABLE profil_fase1_rollback_guard (jumlah BIGINT NOT NULL, CONSTRAINT profil_fase1_rollback_guard_kosong CHECK (jumlah = 0));
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profil_pribadi') = 1,
    'INSERT INTO profil_fase1_rollback_guard (jumlah) SELECT COUNT(*) FROM profil_pribadi',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
DROP TEMPORARY TABLE profil_fase1_rollback_guard;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_tokens' AND COLUMN_NAME = 'cakupan') = 1,
    'UPDATE api_tokens SET revoked_at = NOW() WHERE cakupan = ''ganti_password'' AND revoked_at IS NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_tokens' AND COLUMN_NAME = 'cakupan') = 1,
    'ALTER TABLE api_tokens DROP COLUMN cakupan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'sesi_versi') = 1,
    'ALTER TABLE users DROP COLUMN sesi_versi',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP TABLE IF EXISTS profil_pribadi;
