-- Profil pengguna aplikasi dan impor guru/pengurus — Fase 1 — migrasi 035
--
-- PRD-PROFIL-IMPORT.md, keputusan Human Developer 3 November 2026 (PI-1…PI-10).
-- Branch `claude/profil-impor-fase-1`. Implementator Claude Code, auditor Codex.
--
-- Sifat migrasi: ADITIF dan DAPAT DIJALANKAN ULANG. Satu tabel baru, satu kolom
-- pada `users`, dan satu kolom pada `api_tokens`. Tidak ada UPDATE, DELETE, atau
-- TRUNCATE atas baris lama; nama, nomor HP, NIP, nomor identitas, dan alamat
-- lama di `guru`, `pengurus`, `wali`, dan `users` tidak disentuh.
--
-- 1. PROFIL PRIBADI (PI-7)
--    Data pribadi tambahan menempel pada SATU jangkar identitas: guru, pengurus
--    biasa, wali, atau akun tanpa identitas master. Tepat satu kolom pemilik
--    terisi dan cocok dengan `jenis_pemilik` (CHECK). Guru yang merangkap
--    pengurus memakai profil guru; identitas pengurus milik guru (`pengurus.
--    guru_id`) tidak pernah diberi profil sendiri (ditegakkan layanan dan
--    diperiksa verifier). Nama dan nomor HP tetap disimpan di tabel master dan
--    `users` seperti sebelumnya; tabel ini memuat tempat/tanggal lahir, alamat
--    berjenjang, email, NIK, dan nama berkas foto.
--      - NIK (PI-4) tepat 16 digit atau NULL; unik per jenis pemilik.
--      - Foto (PI-5) hanya nama berkas acak di folder privat.
--      - `versi` naik pada setiap perubahan (kontrol konflik impor/formulir).
--    `guru.id` bertipe INT bertanda (skema V1), sehingga kolom memakai INT.
--
-- 2. `users.sesi_versi` (PI-2): naik ketika password diganti; sesi website yang
--    menyimpan nilai lebih lama dihentikan. Default 0 = sesi lama tetap berlaku.
--
-- 3. `api_tokens.cakupan` (PI-1): token `ganti_password` hanya boleh mengganti
--    password dan logout. Default 'penuh' = token lama tidak berubah makna.

CREATE TABLE IF NOT EXISTS profil_pribadi (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    jenis_pemilik ENUM('guru','pengurus','wali','akun') NOT NULL,
    guru_id INT NULL,
    pengurus_id BIGINT UNSIGNED NULL,
    wali_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    nik VARCHAR(16) NULL,
    tempat_lahir VARCHAR(100) NULL,
    tanggal_lahir DATE NULL,
    alamat_rumah VARCHAR(255) NULL,
    kelurahan VARCHAR(100) NULL,
    kecamatan VARCHAR(100) NULL,
    kabupaten VARCHAR(100) NULL,
    provinsi VARCHAR(100) NULL,
    email VARCHAR(191) NULL,
    foto VARCHAR(40) NULL,
    foto_versi INT UNSIGNED NOT NULL DEFAULT 0,
    versi INT UNSIGNED NOT NULL DEFAULT 1,
    diubah_oleh BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY profil_pribadi_guru_unique (guru_id),
    UNIQUE KEY profil_pribadi_pengurus_unique (pengurus_id),
    UNIQUE KEY profil_pribadi_wali_unique (wali_id),
    UNIQUE KEY profil_pribadi_user_unique (user_id),
    UNIQUE KEY profil_pribadi_nik_unique (jenis_pemilik, nik),
    KEY profil_pribadi_pengubah_index (diubah_oleh),
    CONSTRAINT profil_pribadi_guru_fk FOREIGN KEY (guru_id) REFERENCES guru (id),
    CONSTRAINT profil_pribadi_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT profil_pribadi_wali_fk FOREIGN KEY (wali_id) REFERENCES wali (id),
    CONSTRAINT profil_pribadi_user_fk FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT profil_pribadi_pengubah_fk FOREIGN KEY (diubah_oleh) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT profil_pribadi_pemilik_check CHECK (
        (jenis_pemilik = 'guru' AND guru_id IS NOT NULL AND pengurus_id IS NULL AND wali_id IS NULL AND user_id IS NULL)
        OR (jenis_pemilik = 'pengurus' AND guru_id IS NULL AND pengurus_id IS NOT NULL AND wali_id IS NULL AND user_id IS NULL)
        OR (jenis_pemilik = 'wali' AND guru_id IS NULL AND pengurus_id IS NULL AND wali_id IS NOT NULL AND user_id IS NULL)
        OR (jenis_pemilik = 'akun' AND guru_id IS NULL AND pengurus_id IS NULL AND wali_id IS NULL AND user_id IS NOT NULL)
    ),
    CONSTRAINT profil_pribadi_nik_check CHECK (nik IS NULL OR nik REGEXP '^[0-9]{16}$'),
    CONSTRAINT profil_pribadi_foto_check CHECK (foto IS NULL OR foto REGEXP '^[0-9a-f]{32}[.](jpg|png|webp)$'),
    CONSTRAINT profil_pribadi_versi_check CHECK (versi >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'sesi_versi') = 0,
    'ALTER TABLE users ADD COLUMN sesi_versi INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''PI-2: naik saat password diganti; sesi web dengan nilai lama dihentikan.'' AFTER force_password_change',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_tokens' AND COLUMN_NAME = 'cakupan') = 0,
    'ALTER TABLE api_tokens ADD COLUMN cakupan ENUM(''penuh'',''ganti_password'') NOT NULL DEFAULT ''penuh'' COMMENT ''PI-1: ganti_password = hanya ganti password dan logout.'' AFTER name',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
