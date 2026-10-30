-- Penugasan lintas guru/pengurus dan Keamanan — Fase 3: penugasan Keamanan — migrasi 033
--
-- Keputusan Human Developer 30 Oktober 2026 (docs/penugasan-keamanan/rancangan.md,
-- PK-5, PK-6, PK-T2, PK-T4). Branch `claude/penugasan-keamanan-fase-3`.
-- Implementator Claude Code, auditor Codex.
--
-- Sifat migrasi: ADITIF dan DAPAT DIJALANKAN ULANG. Satu tabel baru yang
-- dibuat KOSONG. Tidak ada ALTER, UPDATE, DELETE, atau TRUNCATE terhadap tabel
-- yang sudah ada: pengurus, guru, users, user_roles, penugasan lain, izin
-- V2/V7, pembinaan V3, kehadiran pengajian, dan `audit_logs` tidak disentuh.
--
-- YANG DIBANGUN
-- -------------
--   keamanan_assignments  penugasan pengurus (termasuk identitas pengurus milik
--                         guru, migrasi 032) sebagai Keamanan: jenis `keamanan`
--                         pada fondasi penugasan pusat; bukan role login baru.
--
-- Bentuk tabel sama dengan `kesehatan_assignments` (024): cakupan seluruh
-- santri aktif tanpa kolom cakupan, `tahun_ajaran_id` = tahun ajaran saat
-- penugasan dibuat, masa berlaku ditentukan `tanggal_mulai`/`tanggal_selesai`/
-- `is_active`, tidak gugur saat semester berganti (PK-5).
--
-- HANYA BACA: Keamanan tidak memiliki tabel data sendiri. Hak bacanya
-- (kehadiran pengajian, perizinan, pembinaan Internal) ditegakkan layanan yang
-- sudah ada; setiap pembukaan rincian tercatat di `audit_logs` tanpa isi.
--
-- WAJIB sebelum dijalankan di produksi: backup terverifikasi, uji pada salinan
-- MySQL/MariaDB berakhiran `_test`, dan rollback berpasangan hanya pada keadaan
-- tabel kosong (lihat database/rollbacks/033_*.sql).

CREATE TABLE IF NOT EXISTS keamanan_assignments (
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
    UNIQUE KEY keamanan_assignment_unique (pengurus_id, tahun_ajaran_id, tanggal_mulai),
    KEY keamanan_pengurus_status_index (pengurus_id, is_active, tanggal_mulai, tanggal_selesai),
    KEY keamanan_year_status_index (tahun_ajaran_id, is_active),
    KEY keamanan_diakhiri_oleh_index (diakhiri_oleh),
    KEY keamanan_updated_by_index (updated_by),
    CONSTRAINT keamanan_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT keamanan_tahun_fk FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran (id),
    CONSTRAINT keamanan_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT keamanan_updater_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT keamanan_ender_fk FOREIGN KEY (diakhiri_oleh) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT keamanan_range_check CHECK (tanggal_selesai IS NULL OR tanggal_selesai >= tanggal_mulai)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
