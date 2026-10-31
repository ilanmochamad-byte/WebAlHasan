--
-- Perbaikan jadwal pengajian kelas gabungan — Fase 1 — migrasi 034
--
-- Keputusan produk Human Developer 31 Oktober 2026 (docs/jadwal-kelas-gabungan/
-- fase-1/README.md). Branch `claude/jadwal-kelas-gabungan-fase-1`.
-- Implementator Claude Code, auditor Codex.
--
-- Satu jadwal dapat mencakup satu atau beberapa kelas yang benar-benar belajar
-- bersama (satu guru, pelajaran, waktu, tempat). Satu jadwal tetap menghasilkan
-- tepat satu pertemuan per tanggal, satu absensi guru, dan paling banyak satu
-- absensi per santri.
--
-- Sifat migrasi: ADITIF dan DAPAT DIJALANKAN ULANG. Dua tabel baru. Tidak ada
-- ALTER, UPDATE, DELETE, atau TRUNCATE terhadap tabel yang sudah ada; ID
-- jadwal, pertemuan, peserta, santri, dan absensi lama tidak berubah.
-- `jadwal_ngaji.id_kelas` tetap ada sebagai kelas UTAMA untuk kompatibilitas.
--
-- YANG DIBANGUN
-- -------------
--   jadwal_kelas     seluruh kelas terpilih sebuah jadwal (termasuk kelas utama,
--                    `is_utama = 1`). Sumber resmi peserta dan saringan kelas
--                    untuk pertemuan BERIKUTNYA.
--   pertemuan_kelas  kelas yang dibekukan saat pertemuan dibuka. Perubahan kelas
--                    pada jadwal sesudahnya tidak mengubah pertemuan ini; kelas
--                    asal tiap santri tetap di `pertemuan_peserta.kelas_id_snapshot`.
--
-- PENGISIAN DATA LAMA (tanpa penggabungan otomatis)
-- -------------------------------------------------
--   * Setiap jadwal lama mendapat tepat satu baris `jadwal_kelas` dari
--     `jadwal_ngaji.id_kelas` (kelas utama). Jadwal yang kelasnya tidak ada di
--     tabel `kelas` dilewati (sudah tidak tampil pada kode lama yang memakai JOIN)
--     dan dilaporkan preflight.
--   * Setiap pertemuan yang sudah dibuka (status Dibuka/Selesai) mendapat baris
--     `pertemuan_kelas` dari kelas snapshot pesertanya — yaitu kelas jadwal saat
--     pertemuan itu dibuka — bukan dari kelas jadwal sekarang. Pertemuan dibuka
--     tanpa peserta memakai `jadwal_ngaji.id_kelas`. Draf tidak diisi: kelasnya
--     dibekukan saat dibuka.
--
-- WAJIB sebelum produksi: backup terverifikasi, uji pada salinan MySQL/MariaDB
-- berakhiran `_test`, dan rollback berpasangan hanya selama belum ada jadwal
-- atau pertemuan gabungan (lihat database/rollbacks/034_*.sql).

CREATE TABLE IF NOT EXISTS jadwal_kelas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    jadwal_id INT NOT NULL,
    kelas_id INT NOT NULL,
    is_utama TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY jadwal_kelas_unique (jadwal_id, kelas_id),
    KEY jadwal_kelas_kelas_index (kelas_id, jadwal_id),
    KEY jadwal_kelas_creator_index (created_by),
    CONSTRAINT jadwal_kelas_jadwal_fk FOREIGN KEY (jadwal_id) REFERENCES jadwal_ngaji (id),
    CONSTRAINT jadwal_kelas_kelas_fk FOREIGN KEY (kelas_id) REFERENCES kelas (id),
    CONSTRAINT jadwal_kelas_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT jadwal_kelas_utama_check CHECK (is_utama IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pertemuan_kelas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pertemuan_id BIGINT UNSIGNED NOT NULL,
    kelas_id INT NOT NULL,
    is_utama TINYINT(1) NOT NULL DEFAULT 0,
    sumber ENUM('Pembukaan','Migrasi 034') NOT NULL DEFAULT 'Pembukaan',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY pertemuan_kelas_unique (pertemuan_id, kelas_id),
    KEY pertemuan_kelas_kelas_index (kelas_id, pertemuan_id),
    CONSTRAINT pertemuan_kelas_meeting_fk FOREIGN KEY (pertemuan_id) REFERENCES pertemuan_pengajian (id),
    CONSTRAINT pertemuan_kelas_kelas_fk FOREIGN KEY (kelas_id) REFERENCES kelas (id),
    CONSTRAINT pertemuan_kelas_utama_check CHECK (is_utama IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jadwal lama → satu kelas utama. NOT EXISTS membuat berkas aman dijalankan ulang.
INSERT INTO jadwal_kelas (jadwal_id, kelas_id, is_utama, created_by, created_at)
SELECT j.id, j.id_kelas, 1, NULL, NOW()
FROM jadwal_ngaji j
JOIN kelas k ON k.id = j.id_kelas
WHERE NOT EXISTS (SELECT 1 FROM jadwal_kelas jk WHERE jk.jadwal_id = j.id AND jk.kelas_id = j.id_kelas)
ORDER BY j.id;

-- Pertemuan lama yang sudah dibuka → kelas snapshot pesertanya (historis).
INSERT INTO pertemuan_kelas (pertemuan_id, kelas_id, is_utama, sumber, created_at)
SELECT x.pertemuan_id, x.kelas_id, x.kelas_id = x.kelas_terkecil, 'Migrasi 034', NOW()
FROM (
    SELECT pp.pertemuan_id, pp.kelas_id_snapshot AS kelas_id,
           (SELECT MIN(pp2.kelas_id_snapshot) FROM pertemuan_peserta pp2 WHERE pp2.pertemuan_id = pp.pertemuan_id) AS kelas_terkecil
    FROM pertemuan_peserta pp
    JOIN pertemuan_pengajian p ON p.id = pp.pertemuan_id AND p.status IN ('Dibuka', 'Selesai')
    JOIN kelas k ON k.id = pp.kelas_id_snapshot
    GROUP BY pp.pertemuan_id, pp.kelas_id_snapshot
) x
WHERE NOT EXISTS (SELECT 1 FROM pertemuan_kelas pk WHERE pk.pertemuan_id = x.pertemuan_id AND pk.kelas_id = x.kelas_id)
ORDER BY x.pertemuan_id, x.kelas_id;

-- Pertemuan lama yang dibuka tanpa peserta → kelas utama jadwal.
INSERT INTO pertemuan_kelas (pertemuan_id, kelas_id, is_utama, sumber, created_at)
SELECT p.id, j.id_kelas, 1, 'Migrasi 034', NOW()
FROM pertemuan_pengajian p
JOIN jadwal_ngaji j ON j.id = p.jadwal_id
JOIN kelas k ON k.id = j.id_kelas
WHERE p.status IN ('Dibuka', 'Selesai')
  AND NOT EXISTS (SELECT 1 FROM pertemuan_peserta pp WHERE pp.pertemuan_id = p.id)
  AND NOT EXISTS (SELECT 1 FROM pertemuan_kelas pk WHERE pk.pertemuan_id = p.id)
ORDER BY p.id;
