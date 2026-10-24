-- PRD V5 Fase 2 — Obat, Rujukan, Koreksi, dan Pembaca Internal — migrasi 025
--
-- Keputusan Human Developer 24 Oktober 2026 (PRD-V5.md). Branch
-- `claude/prd-v5-fase-2`. Implementator Claude Code, auditor Codex.
--
-- Sifat migrasi: ADITIF dan DAPAT DIJALANKAN ULANG (idempoten). Hanya
-- CREATE TABLE IF NOT EXISTS. Tidak ada DROP, DELETE, UPDATE, TRUNCATE, atau
-- ALTER terhadap tabel mana pun, termasuk lima tabel kesehatan migrasi 024.
-- Seluruh tabel baru dibuat KOSONG.
--
-- YANG DIBANGUN
-- -------------
--   kesehatan_obat            obat yang BENAR-BENAR diberikan pada satu
--                             kunjungan (nama, jumlah, satuan, petunjuk, waktu
--                             pemberian, pencatat). TIDAK ADA stok: tidak ada
--                             tabel persediaan, pembelian, atau sisa obat.
--   kesehatan_rujukan         rujukan ke luar klinik (tujuan, tanggal, alasan,
--                             hasil bila diketahui). Tanpa integrasi fasilitas
--                             luar.
--   kesehatan_tindak_lanjut   tindak lanjut kunjungan (uraian, target, status
--                             Terbuka/Selesai).
--   kesehatan_versi_rincian   snapshot obat/rujukan/tindak lanjut pada setiap
--                             versi kunjungan, berpasangan satu-satu dengan
--                             `kesehatan_kunjungan_versi` (isi utama + alasan).
--
-- MODEL VERSI: baris rincian tidak pernah dihapus. Rincian yang dihapus lewat
-- koreksi diberi `versi_dibatalkan`; rincian aktif = `versi_dibatalkan IS
-- NULL`. Setiap versi menyimpan snapshot utuh (JSON) sehingga keadaan dan
-- perubahan sebelum–sesudah setiap versi dapat dibaca ulang tanpa menebak.
-- Satu kunjungan tetap SATU baris `kesehatan_kunjungan`; rincian anak berada
-- di tabel terpisah sehingga daftar tidak tergandakan.
--
-- PRIVASI: isi klinis rincian (nama obat, petunjuk, tujuan/alasan/hasil
-- rujukan, uraian tindak lanjut) hanya berada di tabel-tabel ini dan di
-- snapshot. `audit_logs` dan `kesehatan_akses_log` tetap hanya metadata.
--
-- WAJIB sebelum dijalankan di produksi:
--   1. backup terverifikasi + `php bin/v5_phase2_preflight.php manifest.json`;
--   2. uji lengkap pada salinan MySQL/MariaDB berakhiran `_test`;
--   3. rollback berpasangan di database/rollbacks/025_*.sql, hanya setelah
--      `php bin/v5_phase2_rollback_preflight.php` lulus (tabel rincian kosong);
--   4. `php bin/v5_phase2_verify.php manifest.json` setelah migrasi.

-- ===========================================================================
-- 1. Obat yang diberikan.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS kesehatan_obat (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kunjungan_id BIGINT UNSIGNED NOT NULL,
    nama_obat VARCHAR(200) NOT NULL,
    jumlah DECIMAL(8,2) NULL,
    satuan VARCHAR(40) NULL,
    petunjuk VARCHAR(500) NULL,
    waktu_pemberian DATETIME NOT NULL,
    versi_dibuat INT UNSIGNED NOT NULL,
    versi_dibatalkan INT UNSIGNED NULL,
    dicatat_oleh BIGINT UNSIGNED NOT NULL,
    diperbarui_oleh BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY kesehatan_obat_kunjungan_index (kunjungan_id, versi_dibatalkan),
    KEY kesehatan_obat_pencatat_index (dicatat_oleh),
    KEY kesehatan_obat_pembaru_index (diperbarui_oleh),
    CONSTRAINT kesehatan_obat_kunjungan_fk FOREIGN KEY (kunjungan_id) REFERENCES kesehatan_kunjungan (id),
    CONSTRAINT kesehatan_obat_pencatat_fk FOREIGN KEY (dicatat_oleh) REFERENCES users (id),
    CONSTRAINT kesehatan_obat_pembaru_fk FOREIGN KEY (diperbarui_oleh) REFERENCES users (id),
    CONSTRAINT kesehatan_obat_jumlah_check CHECK (jumlah IS NULL OR jumlah > 0),
    CONSTRAINT kesehatan_obat_versi_check CHECK (versi_dibuat > 0 AND (versi_dibatalkan IS NULL OR versi_dibatalkan > versi_dibuat))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 2. Rujukan ke luar klinik. Status mengikuti isi: hasil kosong = Menunggu
--    hasil; hasil + tanggal hasil = Hasil diketahui.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS kesehatan_rujukan (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kunjungan_id BIGINT UNSIGNED NOT NULL,
    tujuan VARCHAR(200) NOT NULL,
    tanggal_rujukan DATE NOT NULL,
    alasan TEXT NULL,
    status ENUM('Menunggu hasil','Hasil diketahui') NOT NULL DEFAULT 'Menunggu hasil',
    hasil TEXT NULL,
    tanggal_hasil DATE NULL,
    versi_dibuat INT UNSIGNED NOT NULL,
    versi_dibatalkan INT UNSIGNED NULL,
    dicatat_oleh BIGINT UNSIGNED NOT NULL,
    diperbarui_oleh BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY kesehatan_rujukan_kunjungan_index (kunjungan_id, versi_dibatalkan),
    KEY kesehatan_rujukan_status_index (status, versi_dibatalkan),
    KEY kesehatan_rujukan_pencatat_index (dicatat_oleh),
    KEY kesehatan_rujukan_pembaru_index (diperbarui_oleh),
    CONSTRAINT kesehatan_rujukan_kunjungan_fk FOREIGN KEY (kunjungan_id) REFERENCES kesehatan_kunjungan (id),
    CONSTRAINT kesehatan_rujukan_pencatat_fk FOREIGN KEY (dicatat_oleh) REFERENCES users (id),
    CONSTRAINT kesehatan_rujukan_pembaru_fk FOREIGN KEY (diperbarui_oleh) REFERENCES users (id),
    CONSTRAINT kesehatan_rujukan_status_check CHECK (
        (status = 'Menunggu hasil' AND hasil IS NULL AND tanggal_hasil IS NULL)
        OR (status = 'Hasil diketahui' AND hasil IS NOT NULL AND tanggal_hasil IS NOT NULL AND tanggal_hasil >= tanggal_rujukan)
    ),
    CONSTRAINT kesehatan_rujukan_versi_check CHECK (versi_dibuat > 0 AND (versi_dibatalkan IS NULL OR versi_dibatalkan > versi_dibuat))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 3. Tindak lanjut kunjungan.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS kesehatan_tindak_lanjut (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kunjungan_id BIGINT UNSIGNED NOT NULL,
    uraian VARCHAR(1000) NOT NULL,
    tanggal_target DATE NULL,
    status ENUM('Terbuka','Selesai') NOT NULL DEFAULT 'Terbuka',
    tanggal_selesai DATE NULL,
    versi_dibuat INT UNSIGNED NOT NULL,
    versi_dibatalkan INT UNSIGNED NULL,
    dicatat_oleh BIGINT UNSIGNED NOT NULL,
    diperbarui_oleh BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY kesehatan_tindak_lanjut_kunjungan_index (kunjungan_id, versi_dibatalkan),
    KEY kesehatan_tindak_lanjut_status_index (status, versi_dibatalkan),
    KEY kesehatan_tindak_lanjut_pencatat_index (dicatat_oleh),
    KEY kesehatan_tindak_lanjut_pembaru_index (diperbarui_oleh),
    CONSTRAINT kesehatan_tindak_lanjut_kunjungan_fk FOREIGN KEY (kunjungan_id) REFERENCES kesehatan_kunjungan (id),
    CONSTRAINT kesehatan_tindak_lanjut_pencatat_fk FOREIGN KEY (dicatat_oleh) REFERENCES users (id),
    CONSTRAINT kesehatan_tindak_lanjut_pembaru_fk FOREIGN KEY (diperbarui_oleh) REFERENCES users (id),
    CONSTRAINT kesehatan_tindak_lanjut_status_check CHECK (
        (status = 'Terbuka' AND tanggal_selesai IS NULL) OR (status = 'Selesai' AND tanggal_selesai IS NOT NULL)
    ),
    CONSTRAINT kesehatan_tindak_lanjut_versi_check CHECK (versi_dibuat > 0 AND (versi_dibatalkan IS NULL OR versi_dibatalkan > versi_dibuat))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 4. Snapshot rincian per versi. Berpasangan dengan
--    `kesehatan_kunjungan_versi` (kunjungan_id, versi). `idempotensi_id`
--    menunjuk klaim yang menghasilkan versi ini sehingga pengulangan
--    permintaan menjawab versi yang sama tanpa membuat versi baru.
--    Kunjungan Fase 1 (versi 1 tanpa baris di sini) berarti tanpa rincian.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS kesehatan_versi_rincian (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kunjungan_id BIGINT UNSIGNED NOT NULL,
    versi INT UNSIGNED NOT NULL,
    rincian LONGTEXT NOT NULL,
    idempotensi_id BIGINT UNSIGNED NULL,
    dibuat_oleh BIGINT UNSIGNED NOT NULL,
    dibuat_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY kesehatan_versi_rincian_unique (kunjungan_id, versi),
    UNIQUE KEY kesehatan_versi_rincian_idempotensi (idempotensi_id),
    KEY kesehatan_versi_rincian_pembuat_index (dibuat_oleh),
    CONSTRAINT kesehatan_versi_rincian_versi_fk FOREIGN KEY (kunjungan_id, versi) REFERENCES kesehatan_kunjungan_versi (kunjungan_id, versi),
    CONSTRAINT kesehatan_versi_rincian_idempotensi_fk FOREIGN KEY (idempotensi_id) REFERENCES kesehatan_idempotensi (id),
    CONSTRAINT kesehatan_versi_rincian_pembuat_fk FOREIGN KEY (dibuat_oleh) REFERENCES users (id),
    CONSTRAINT kesehatan_versi_rincian_json_check CHECK (JSON_VALID(rincian))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
