-- PRD V5 Fase 1 — Penugasan Kesehatan dan Kunjungan Dasar — migrasi 024
--
-- Keputusan Human Developer 24 Oktober 2026 (PRD-V5.md). Branch
-- `claude/prd-v5-fase-1`. Implementator Claude Code, auditor Codex.
--
-- Sifat migrasi: ADITIF dan DAPAT DIJALANKAN ULANG (idempoten). Tidak ada
-- DROP, DELETE, UPDATE, TRUNCATE, atau ALTER terhadap tabel yang sudah ada.
-- Tabel santri, wali, santri_wali, users, pengurus, penugasan lama, dan data
-- V1–V4 tidak disentuh. Seluruh tabel baru dibuat KOSONG; tidak ada pengisian
-- berdasarkan tebakan.
--
-- YANG DIBANGUN
-- -------------
--   kesehatan_assignments     penugasan pengurus sebagai petugas kesehatan
--                             (jenis `kesehatan` pada fondasi penugasan pusat;
--                             bukan role login baru, bukan bendahara bulanan
--                             lama yang berganti nama)
--   kesehatan_kunjungan       satu baris per kunjungan klinik (versi terbaru)
--   kesehatan_kunjungan_versi snapshot setiap versi isi klinis (versi 1 dibuat
--                             saat pencatatan; koreksi berversi menyusul Fase 2)
--   kesehatan_idempotensi     klaim kunci idempotensi per akun/operasi; TIDAK
--                             menyimpan salinan respons/isi klinis
--   kesehatan_akses_log       audit akses baca rincian (metadata saja: siapa,
--                             kunjungan, santri, sumber hak, kanal, waktu)
--
-- PRIVASI: isi klinis (keluhan, catatan klinis, tindakan) hanya berada di
-- `kesehatan_kunjungan` dan `kesehatan_kunjungan_versi`. `audit_logs` dan
-- `kesehatan_akses_log` hanya menerima ID, status, versi, dan waktu.
--
-- CAKUPAN PENUGASAN: seluruh santri aktif (PRD V5 §5.2). Tidak ada kolom
-- unit/jenjang; pembatasan menurut unit hanya ditambahkan bila Human
-- Developer menetapkannya. `tahun_ajaran_id` mengikuti bentuk seluruh tabel
-- penugasan fondasi (tahun ajaran saat penugasan dibuat); masa berlaku
-- ditentukan `tanggal_mulai`/`tanggal_selesai`/`is_active`, tidak gugur saat
-- semester berganti.
--
-- WAJIB sebelum dijalankan di produksi:
--   1. backup terverifikasi + `php bin/v5_phase1_preflight.php manifest.json`;
--   2. uji lengkap pada salinan MySQL/MariaDB berakhiran `_test`;
--   3. rollback berpasangan di database/rollbacks/024_*.sql, hanya setelah
--      `php bin/v5_phase1_rollback_preflight.php` lulus (tabel kesehatan kosong);
--   4. `php bin/v5_phase1_verify.php manifest.json` setelah migrasi.

-- ===========================================================================
-- 1. Penugasan petugas kesehatan.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS kesehatan_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengurus_id BIGINT UNSIGNED NOT NULL,
    tahun_ajaran_id INT NOT NULL,
    tanggal_mulai DATE NOT NULL,
    tanggal_selesai DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    catatan VARCHAR(500) NULL,
    alasan_perubahan VARCHAR(500) NULL,
    diakhiri_pada DATETIME NULL,
    diakhiri_oleh BIGINT UNSIGNED NULL,
    alasan_pengakhiran VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY kesehatan_assignment_unique (pengurus_id, tahun_ajaran_id, tanggal_mulai),
    KEY kesehatan_pengurus_status_index (pengurus_id, is_active, tanggal_mulai, tanggal_selesai),
    KEY kesehatan_year_status_index (tahun_ajaran_id, is_active),
    KEY kesehatan_diakhiri_oleh_index (diakhiri_oleh),
    KEY kesehatan_updated_by_index (updated_by),
    CONSTRAINT kesehatan_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT kesehatan_tahun_fk FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran (id),
    CONSTRAINT kesehatan_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT kesehatan_updater_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT kesehatan_ender_fk FOREIGN KEY (diakhiri_oleh) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT kesehatan_range_check CHECK (tanggal_selesai IS NULL OR tanggal_selesai >= tanggal_mulai)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 2. Kunjungan klinik. Satu baris per kunjungan; rincian anak (obat,
--    rujukan, tindak lanjut — Fase 2) berada di tabel terpisah sehingga daftar
--    kunjungan tidak pernah tergandakan.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS kesehatan_kunjungan (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    santri_id INT NOT NULL,
    waktu_kunjungan DATETIME NOT NULL,
    status ENUM('Draf','Tercatat') NOT NULL DEFAULT 'Tercatat',
    versi INT UNSIGNED NOT NULL DEFAULT 1,
    keluhan TEXT NOT NULL,
    catatan_klinis TEXT NULL,
    tindakan TEXT NULL,
    petugas_user_id BIGINT UNSIGNED NOT NULL,
    petugas_pengurus_id BIGINT UNSIGNED NOT NULL,
    penugasan_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY kesehatan_kunjungan_retry (created_by, idempotency_key),
    KEY kesehatan_kunjungan_santri_index (santri_id, waktu_kunjungan),
    KEY kesehatan_kunjungan_status_index (status, waktu_kunjungan),
    KEY kesehatan_kunjungan_petugas_index (petugas_user_id),
    KEY kesehatan_kunjungan_pengurus_index (petugas_pengurus_id),
    KEY kesehatan_kunjungan_penugasan_index (penugasan_id),
    KEY kesehatan_kunjungan_updater_index (updated_by),
    CONSTRAINT kesehatan_kunjungan_santri_fk FOREIGN KEY (santri_id) REFERENCES santri (id),
    CONSTRAINT kesehatan_kunjungan_petugas_fk FOREIGN KEY (petugas_user_id) REFERENCES users (id),
    CONSTRAINT kesehatan_kunjungan_pengurus_fk FOREIGN KEY (petugas_pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT kesehatan_kunjungan_penugasan_fk FOREIGN KEY (penugasan_id) REFERENCES kesehatan_assignments (id),
    CONSTRAINT kesehatan_kunjungan_creator_fk FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT kesehatan_kunjungan_updater_fk FOREIGN KEY (updated_by) REFERENCES users (id),
    CONSTRAINT kesehatan_kunjungan_versi_check CHECK (versi > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 3. Snapshot versi isi klinis. Versi 1 = pencatatan awal (alasan NULL).
-- ===========================================================================
CREATE TABLE IF NOT EXISTS kesehatan_kunjungan_versi (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kunjungan_id BIGINT UNSIGNED NOT NULL,
    versi INT UNSIGNED NOT NULL,
    status ENUM('Draf','Tercatat') NOT NULL,
    waktu_kunjungan DATETIME NOT NULL,
    keluhan TEXT NOT NULL,
    catatan_klinis TEXT NULL,
    tindakan TEXT NULL,
    alasan VARCHAR(1000) NULL,
    dibuat_oleh BIGINT UNSIGNED NOT NULL,
    dibuat_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY kesehatan_versi_unique (kunjungan_id, versi),
    KEY kesehatan_versi_pembuat_index (dibuat_oleh),
    CONSTRAINT kesehatan_versi_kunjungan_fk FOREIGN KEY (kunjungan_id) REFERENCES kesehatan_kunjungan (id),
    CONSTRAINT kesehatan_versi_pembuat_fk FOREIGN KEY (dibuat_oleh) REFERENCES users (id),
    CONSTRAINT kesehatan_versi_check CHECK (versi > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 4. Idempotensi. Kunci sama + isi sama → kunjungan yang sama (dibaca ulang
--    dengan otorisasi baru); kunci sama + isi berbeda → 409 tanpa perubahan.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS kesehatan_idempotensi (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    operasi VARCHAR(60) NOT NULL,
    kunci VARCHAR(100) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    kunjungan_id BIGINT UNSIGNED NULL,
    status_code SMALLINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY kesehatan_idempotensi_unique (user_id, operasi, kunci),
    KEY kesehatan_idempotensi_kunjungan_index (kunjungan_id),
    CONSTRAINT kesehatan_idempotensi_user_fk FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT kesehatan_idempotensi_kunjungan_fk FOREIGN KEY (kunjungan_id) REFERENCES kesehatan_kunjungan (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 5. Audit akses baca rincian (tanpa isi klinis).
-- ===========================================================================
CREATE TABLE IF NOT EXISTS kesehatan_akses_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kunjungan_id BIGINT UNSIGNED NOT NULL,
    santri_id INT NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    sumber VARCHAR(20) NOT NULL,
    aksi VARCHAR(30) NOT NULL,
    kanal VARCHAR(10) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY kesehatan_akses_kunjungan_index (kunjungan_id, created_at),
    KEY kesehatan_akses_user_index (user_id, created_at),
    KEY kesehatan_akses_santri_index (santri_id),
    CONSTRAINT kesehatan_akses_kunjungan_fk FOREIGN KEY (kunjungan_id) REFERENCES kesehatan_kunjungan (id),
    CONSTRAINT kesehatan_akses_santri_fk FOREIGN KEY (santri_id) REFERENCES santri (id),
    CONSTRAINT kesehatan_akses_user_fk FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
