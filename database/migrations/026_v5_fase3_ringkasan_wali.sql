-- PRD V5 Fase 3 — Ringkasan Orang Tua dan Notifikasi — migrasi 026
--
-- Keputusan Human Developer 24 Oktober 2026 (PRD-V5.md). Branch
-- `claude/prd-v5-fase-3`. Implementator Claude Code, auditor Codex.
--
-- Sifat migrasi: ADITIF dan DAPAT DIJALANKAN ULANG (idempoten). Hanya
-- CREATE TABLE IF NOT EXISTS. Tidak ada DROP, DELETE, UPDATE, TRUNCATE, atau
-- ALTER terhadap tabel mana pun, termasuk sembilan tabel kesehatan migrasi
-- 024–025 dan tabel notifikasi V2. Seluruh tabel baru dibuat KOSONG.
--
-- YANG DIBANGUN
-- -------------
--   kesehatan_ringkasan            kepala ringkasan wali, SATU per kunjungan:
--                                  status Terbit/Ditarik, versi publikasi yang
--                                  sedang berlaku, versi terakhir, dan `revisi`
--                                  (token optimistic yang naik pada SETIAP
--                                  Terbit/Ganti/Tarik).
--   kesehatan_ringkasan_versi      snapshot TETAP setiap versi publikasi: teks
--                                  aman yang disusun petugas (ringkasan,
--                                  tindakan umum, tindak lanjut untuk orang
--                                  tua), tanggal kunjungan, versi kunjungan
--                                  sumber. Teks tidak pernah diperbarui;
--                                  hanya status (Terbit → Diganti/Ditarik) dan
--                                  waktu berakhirnya yang berubah.
--   kesehatan_ringkasan_peristiwa  jejak Terbit/Ganti/Tarik: pelaku, waktu,
--                                  alasan, jumlah penerima, kanal, dan klaim
--                                  idempotensi yang menghasilkannya.
--   kesehatan_ringkasan_penerima   wali berakun yang berhak pada saat satu
--                                  peristiwa terbit (dasar notifikasi).
--
-- PEMISAHAN DARI CATATAN KLINIS: tampilan wali hanya membaca dua tabel
-- pertama ditambah relasi wali. Tidak ada salinan otomatis keluhan, catatan
-- klinis, tindakan, obat, rujukan, atau tindak lanjut internal ke tabel ini;
-- teks ringkasan ditulis sendiri oleh petugas dan dipratinjau sebelum terbit.
-- Koreksi klinis tidak menyentuh tabel ini sehingga snapshot terbit tidak
-- berubah diam-diam; perubahan bagi wali memerlukan versi publikasi baru.
--
-- NOTIFIKASI memakai `notifikasi_outbox` V2 yang ada (kunci unik peristiwa ×
-- kanal × penerima). Tidak ada tabel atau kolom notifikasi baru.
--
-- WAJIB sebelum dijalankan di produksi:
--   1. backup terverifikasi + `php bin/v5_phase3_preflight.php manifest.json`;
--   2. uji lengkap pada salinan MySQL/MariaDB berakhiran `_test`;
--   3. rollback berpasangan di database/rollbacks/026_*.sql, hanya setelah
--      `php bin/v5_phase3_rollback_preflight.php` lulus (tabel ringkasan kosong);
--   4. `php bin/v5_phase3_verify.php manifest.json` setelah migrasi.

-- ===========================================================================
-- 1. Kepala ringkasan wali — satu per kunjungan.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS kesehatan_ringkasan (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kunjungan_id BIGINT UNSIGNED NOT NULL,
    santri_id INT NOT NULL,
    status ENUM('Terbit','Ditarik') NOT NULL,
    versi_berlaku INT UNSIGNED NULL,
    versi_terakhir INT UNSIGNED NOT NULL,
    revisi INT UNSIGNED NOT NULL,
    dibuat_oleh BIGINT UNSIGNED NOT NULL,
    diperbarui_oleh BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY kesehatan_ringkasan_kunjungan_unique (kunjungan_id),
    KEY kesehatan_ringkasan_santri_index (santri_id, status),
    KEY kesehatan_ringkasan_pembuat_index (dibuat_oleh),
    KEY kesehatan_ringkasan_pembaru_index (diperbarui_oleh),
    CONSTRAINT kesehatan_ringkasan_kunjungan_fk FOREIGN KEY (kunjungan_id) REFERENCES kesehatan_kunjungan (id),
    CONSTRAINT kesehatan_ringkasan_santri_fk FOREIGN KEY (santri_id) REFERENCES santri (id),
    CONSTRAINT kesehatan_ringkasan_pembuat_fk FOREIGN KEY (dibuat_oleh) REFERENCES users (id),
    CONSTRAINT kesehatan_ringkasan_pembaru_fk FOREIGN KEY (diperbarui_oleh) REFERENCES users (id),
    CONSTRAINT kesehatan_ringkasan_status_check CHECK (
        (status = 'Terbit' AND versi_berlaku IS NOT NULL AND versi_berlaku = versi_terakhir)
        OR (status = 'Ditarik' AND versi_berlaku IS NULL)
    ),
    CONSTRAINT kesehatan_ringkasan_versi_check CHECK (versi_terakhir > 0 AND revisi >= versi_terakhir)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 2. Snapshot tetap setiap versi publikasi.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS kesehatan_ringkasan_versi (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ringkasan_id BIGINT UNSIGNED NOT NULL,
    versi INT UNSIGNED NOT NULL,
    kunjungan_id BIGINT UNSIGNED NOT NULL,
    kunjungan_versi INT UNSIGNED NOT NULL,
    tanggal_kunjungan DATE NOT NULL,
    ringkasan VARCHAR(2000) NOT NULL,
    tindakan_umum VARCHAR(1000) NULL,
    tindak_lanjut_wali VARCHAR(1000) NULL,
    status ENUM('Terbit','Diganti','Ditarik') NOT NULL DEFAULT 'Terbit',
    diterbitkan_oleh BIGINT UNSIGNED NOT NULL,
    diterbitkan_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    berakhir_pada DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY kesehatan_ringkasan_versi_unique (ringkasan_id, versi),
    KEY kesehatan_ringkasan_versi_kunjungan_index (kunjungan_id, kunjungan_versi),
    KEY kesehatan_ringkasan_versi_penerbit_index (diterbitkan_oleh),
    CONSTRAINT kesehatan_ringkasan_versi_kepala_fk FOREIGN KEY (ringkasan_id) REFERENCES kesehatan_ringkasan (id),
    CONSTRAINT kesehatan_ringkasan_versi_sumber_fk FOREIGN KEY (kunjungan_id, kunjungan_versi) REFERENCES kesehatan_kunjungan_versi (kunjungan_id, versi),
    CONSTRAINT kesehatan_ringkasan_versi_penerbit_fk FOREIGN KEY (diterbitkan_oleh) REFERENCES users (id),
    CONSTRAINT kesehatan_ringkasan_versi_status_check CHECK (
        (status = 'Terbit' AND berakhir_pada IS NULL) OR (status IN ('Diganti','Ditarik') AND berakhir_pada IS NOT NULL)
    ),
    CONSTRAINT kesehatan_ringkasan_versi_check CHECK (versi > 0 AND kunjungan_versi > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 3. Jejak peristiwa publikasi. Satu baris per Terbit/Ganti/Tarik.
--    `idempotensi_id` menunjuk klaim yang menghasilkan peristiwa ini sehingga
--    pengulangan permintaan menjawab peristiwa yang sama tanpa membuat versi
--    atau notifikasi kedua.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS kesehatan_ringkasan_peristiwa (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ringkasan_id BIGINT UNSIGNED NOT NULL,
    versi_id BIGINT UNSIGNED NOT NULL,
    jenis ENUM('Terbit','Ganti','Tarik') NOT NULL,
    alasan VARCHAR(1000) NULL,
    jumlah_penerima INT UNSIGNED NOT NULL DEFAULT 0,
    kanal VARCHAR(10) NOT NULL,
    idempotensi_id BIGINT UNSIGNED NOT NULL,
    dibuat_oleh BIGINT UNSIGNED NOT NULL,
    dibuat_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY kesehatan_ringkasan_peristiwa_idempotensi (idempotensi_id),
    KEY kesehatan_ringkasan_peristiwa_kepala_index (ringkasan_id, id),
    KEY kesehatan_ringkasan_peristiwa_versi_index (versi_id),
    KEY kesehatan_ringkasan_peristiwa_pembuat_index (dibuat_oleh),
    CONSTRAINT kesehatan_ringkasan_peristiwa_kepala_fk FOREIGN KEY (ringkasan_id) REFERENCES kesehatan_ringkasan (id),
    CONSTRAINT kesehatan_ringkasan_peristiwa_versi_fk FOREIGN KEY (versi_id) REFERENCES kesehatan_ringkasan_versi (id),
    CONSTRAINT kesehatan_ringkasan_peristiwa_idempotensi_fk FOREIGN KEY (idempotensi_id) REFERENCES kesehatan_idempotensi (id),
    CONSTRAINT kesehatan_ringkasan_peristiwa_pembuat_fk FOREIGN KEY (dibuat_oleh) REFERENCES users (id),
    CONSTRAINT kesehatan_ringkasan_peristiwa_alasan_check CHECK (jenis = 'Terbit' OR alasan IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 4. Penerima berhak pada saat peristiwa Terbit/Ganti (wali berakun dengan
--    relasi aktif). Hak baca wali tetap diperiksa ulang pada SETIAP
--    permintaan; tabel ini hanya jejak dasar notifikasi.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS kesehatan_ringkasan_penerima (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    peristiwa_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    wali_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY kesehatan_ringkasan_penerima_unique (peristiwa_id, user_id),
    KEY kesehatan_ringkasan_penerima_user_index (user_id),
    KEY kesehatan_ringkasan_penerima_wali_index (wali_id),
    CONSTRAINT kesehatan_ringkasan_penerima_peristiwa_fk FOREIGN KEY (peristiwa_id) REFERENCES kesehatan_ringkasan_peristiwa (id),
    CONSTRAINT kesehatan_ringkasan_penerima_user_fk FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT kesehatan_ringkasan_penerima_wali_fk FOREIGN KEY (wali_id) REFERENCES wali (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
