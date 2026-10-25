-- PRD V6 Fase 2 — Tagihan Bulanan Lengkap — migrasi 028
--
-- Instruksi Human Developer 25 Oktober 2026 (PRD-V6.md §6 Fase 2). Branch
-- `claude/prd-v6-fase-2` dari audit Fase 1 `e895dbe`. Implementator Claude
-- Code, auditor Codex.
--
-- Sifat migrasi: ADITIF dan DAPAT DIJALANKAN ULANG (idempoten). Hanya
-- CREATE TABLE IF NOT EXISTS. Tidak ada DROP, DELETE, UPDATE, TRUNCATE, atau
-- ALTER terhadap tabel mana pun — termasuk enam tabel 027 (`bsa_tagihan`,
-- `bsa_pembayaran`, dan seterusnya), santri, wali, santri_wali, users,
-- penugasan, PSB, dan V1–V5. Seluruh tabel baru dibuat KOSONG.
--
-- YANG DIBANGUN
-- -------------
--   bsa_tarif                    tarif bulanan per unit/jenjang mulai satu
--                                periode; perubahan = baris baru, baris lama
--                                tidak pernah diubah nominalnya
--   bsa_tarif_komponen           komponen biaya tarif (≥ 1 baris)
--   bsa_penyesuaian              penyesuaian per santri beralasan (potongan
--                                atau tambahan) untuk rentang periode;
--                                pencabutan beralasan, tanpa DELETE
--   bsa_penerbitan               satu penerbitan massal (periode, unit, jatuh
--                                tempo, hash pratinjau, jumlah hasil)
--   bsa_penerbitan_santri        hasil per santri sasaran: Terbit, SudahAda,
--                                atau Dilewati beserta alasannya
--   bsa_tagihan_penyesuaian      baris penyesuaian pada tagihan (dari
--                                penyesuaian santri saat terbit atau dari
--                                revisi beralasan); hanya INSERT — total
--                                tagihan = Σ komponen + Σ tambahan − Σ potongan
--   bsa_tagihan_riwayat          satu baris per versi tagihan (Terbit, Revisi,
--                                Dibatalkan) dengan aktor, waktu, dan alasan
--   bsa_pembayaran_kuitansi      snapshot kuitansi saat pembayaran sah: versi
--                                dan total tagihan, terbayar sebelum, sisa
--                                sesudah — tidak berubah oleh tarif/revisi
--   bsa_pembayaran_pembatalan    entri penyeimbang beralasan untuk pembayaran
--                                yang dibatalkan atau transfer yang ditolak
--
-- SNAPSHOT: tagihan menyalin komponen tarif ke `bsa_tagihan_komponen` (027)
-- dan penyesuaian santri ke `bsa_tagihan_penyesuaian`; perubahan tarif atau
-- penyesuaian sesudahnya tidak menyentuh tagihan yang sudah terbit.
--
-- NOMINAL: rupiah bulat BIGINT (tanpa float), dijaga CHECK selain layanan.
-- AUDIT: `audit_logs` jejak `bsa.*` hanya ID/jenis/status; nominal, alasan,
-- dan referensi tetap di tabel BSA (PRD V6 §5.4).
--
-- WAJIB sebelum dijalankan di produksi:
--   1. backup terverifikasi + `php bin/v6_phase2_preflight.php manifest.json`;
--   2. uji lengkap pada salinan MySQL/MariaDB berakhiran `_test`;
--   3. rollback berpasangan database/rollbacks/028_*.sql hanya setelah
--      `php bin/v6_phase2_rollback_preflight.php` lulus;
--   4. `php bin/v6_phase2_verify.php manifest.json` setelah migrasi.
-- Fase 2 BUKAN izin rilis parsial: tagihan dan tabungan dirilis bersama.
--
-- Memerlukan kolom terbangkit STORED dengan indeks unik dan CHECK yang
-- ditegakkan (MariaDB 10.2.1+/MySQL 8.0.16+; diperiksa preflight).

-- ===========================================================================
-- 1. Tarif bulanan per unit/jenjang. Satu tarif BERLAKU per unit per periode
--    mulai (kunci unik terbangkit). Mengganti tarif periode mulai yang sama
--    menandai baris lama `Diganti` (beralasan); nominal lama tidak diubah.
--    Untuk periode P, tarif efektif unit U = baris Berlaku dengan
--    berlaku_mulai terbesar yang ≤ P.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS bsa_tarif (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    jenjang VARCHAR(20) NOT NULL,
    -- VARCHAR (bukan CHAR seperti periode lain): MariaDB menolak kolom CHAR
    -- di ekspresi kolom terbangkit karena nilainya bergantung sql_mode.
    berlaku_mulai VARCHAR(7) NOT NULL,
    total BIGINT NOT NULL,
    status ENUM('Berlaku','Diganti') NOT NULL DEFAULT 'Berlaku',
    alasan VARCHAR(500) NULL,
    diganti_oleh_id BIGINT UNSIGNED NULL,
    diganti_pada DATETIME NULL,
    penugasan_id BIGINT UNSIGNED NOT NULL,
    pengurus_id BIGINT UNSIGNED NOT NULL,
    dibuat_oleh BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    kunci_berlaku VARCHAR(40) AS (IF(status = 'Berlaku', CONCAT(jenjang, '|', berlaku_mulai), NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY bsa_tarif_berlaku_unique (kunci_berlaku),
    UNIQUE KEY bsa_tarif_retry_unique (dibuat_oleh, idempotency_key),
    UNIQUE KEY bsa_tarif_pengganti_unique (diganti_oleh_id),
    KEY bsa_tarif_unit_index (jenjang, berlaku_mulai, status),
    KEY bsa_tarif_penugasan_index (penugasan_id),
    KEY bsa_tarif_pengurus_index (pengurus_id),
    CONSTRAINT bsa_tarif_pengganti_fk FOREIGN KEY (diganti_oleh_id) REFERENCES bsa_tarif (id),
    CONSTRAINT bsa_tarif_penugasan_fk FOREIGN KEY (penugasan_id) REFERENCES bendahara_bulanan_assignments (id),
    CONSTRAINT bsa_tarif_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT bsa_tarif_pembuat_fk FOREIGN KEY (dibuat_oleh) REFERENCES users (id),
    CONSTRAINT bsa_tarif_periode_check CHECK (berlaku_mulai REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'),
    CONSTRAINT bsa_tarif_total_check CHECK (total > 0),
    CONSTRAINT bsa_tarif_diganti_check CHECK ((status = 'Diganti') = (diganti_pada IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bsa_tarif_komponen (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tarif_id BIGINT UNSIGNED NOT NULL,
    urutan TINYINT UNSIGNED NOT NULL,
    nama VARCHAR(100) NOT NULL,
    nominal BIGINT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY bsa_tarif_komponen_urutan_unique (tarif_id, urutan),
    CONSTRAINT bsa_tarif_komponen_tarif_fk FOREIGN KEY (tarif_id) REFERENCES bsa_tarif (id),
    CONSTRAINT bsa_tarif_komponen_nominal_check CHECK (nominal > 0),
    CONSTRAINT bsa_tarif_komponen_urutan_check CHECK (urutan > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 2. Penyesuaian per santri. Berlaku untuk tagihan yang terbit dari tarif
--    pada periode mulai..sampai (sampai NULL = seterusnya). Tidak mengubah
--    tagihan yang sudah terbit. Pencabutan = status Dicabut + aktor, waktu,
--    alasan; baris tidak dihapus.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS bsa_penyesuaian (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    santri_id INT NOT NULL,
    jenis ENUM('Potongan','Tambahan') NOT NULL,
    nama VARCHAR(100) NOT NULL,
    nominal BIGINT NOT NULL,
    berlaku_mulai CHAR(7) NOT NULL,
    berlaku_sampai CHAR(7) NULL,
    alasan VARCHAR(500) NOT NULL,
    status ENUM('Aktif','Dicabut') NOT NULL DEFAULT 'Aktif',
    dicabut_oleh BIGINT UNSIGNED NULL,
    dicabut_pada DATETIME NULL,
    alasan_cabut VARCHAR(500) NULL,
    penugasan_id BIGINT UNSIGNED NOT NULL,
    pengurus_id BIGINT UNSIGNED NOT NULL,
    dibuat_oleh BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY bsa_penyesuaian_retry_unique (dibuat_oleh, idempotency_key),
    KEY bsa_penyesuaian_santri_index (santri_id, status),
    KEY bsa_penyesuaian_penugasan_index (penugasan_id),
    KEY bsa_penyesuaian_pengurus_index (pengurus_id),
    KEY bsa_penyesuaian_pencabut_index (dicabut_oleh),
    CONSTRAINT bsa_penyesuaian_santri_fk FOREIGN KEY (santri_id) REFERENCES santri (id),
    CONSTRAINT bsa_penyesuaian_penugasan_fk FOREIGN KEY (penugasan_id) REFERENCES bendahara_bulanan_assignments (id),
    CONSTRAINT bsa_penyesuaian_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT bsa_penyesuaian_pembuat_fk FOREIGN KEY (dibuat_oleh) REFERENCES users (id),
    CONSTRAINT bsa_penyesuaian_pencabut_fk FOREIGN KEY (dicabut_oleh) REFERENCES users (id),
    CONSTRAINT bsa_penyesuaian_nominal_check CHECK (nominal > 0),
    CONSTRAINT bsa_penyesuaian_mulai_check CHECK (berlaku_mulai REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'),
    CONSTRAINT bsa_penyesuaian_sampai_check CHECK (berlaku_sampai IS NULL OR (berlaku_sampai REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$' AND berlaku_sampai >= berlaku_mulai)),
    CONSTRAINT bsa_penyesuaian_alasan_check CHECK (CHAR_LENGTH(alasan) >= 5),
    CONSTRAINT bsa_penyesuaian_cabut_check CHECK ((status = 'Dicabut') = (dicabut_oleh IS NOT NULL AND dicabut_pada IS NOT NULL AND alasan_cabut IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 3. Penerbitan massal. Satu baris per penerbitan yang menghasilkan tagihan;
--    hasil per santri sasaran dicatat (termasuk yang sudah punya tagihan
--    periode itu dan yang dilewati). Kunci unik santri+periode pada
--    `bsa_tagihan` (027) tetap menjamin paling banyak satu tagihan.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS bsa_penerbitan (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    periode CHAR(7) NOT NULL,
    jenjang VARCHAR(20) NULL,
    jatuh_tempo DATE NOT NULL,
    jumlah_sasaran INT UNSIGNED NOT NULL,
    jumlah_terbit INT UNSIGNED NOT NULL,
    jumlah_sudah_ada INT UNSIGNED NOT NULL,
    jumlah_dilewati INT UNSIGNED NOT NULL,
    pratinjau_hash CHAR(64) NOT NULL,
    penugasan_id BIGINT UNSIGNED NOT NULL,
    pengurus_id BIGINT UNSIGNED NOT NULL,
    dibuat_oleh BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY bsa_penerbitan_retry_unique (dibuat_oleh, idempotency_key),
    KEY bsa_penerbitan_periode_index (periode),
    KEY bsa_penerbitan_penugasan_index (penugasan_id),
    KEY bsa_penerbitan_pengurus_index (pengurus_id),
    CONSTRAINT bsa_penerbitan_penugasan_fk FOREIGN KEY (penugasan_id) REFERENCES bendahara_bulanan_assignments (id),
    CONSTRAINT bsa_penerbitan_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT bsa_penerbitan_pembuat_fk FOREIGN KEY (dibuat_oleh) REFERENCES users (id),
    CONSTRAINT bsa_penerbitan_periode_check CHECK (periode REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'),
    CONSTRAINT bsa_penerbitan_jumlah_check CHECK (jumlah_terbit > 0 AND jumlah_sasaran = jumlah_terbit + jumlah_sudah_ada + jumlah_dilewati)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bsa_penerbitan_santri (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    penerbitan_id BIGINT UNSIGNED NOT NULL,
    santri_id INT NOT NULL,
    jenjang VARCHAR(20) NULL,
    hasil ENUM('Terbit','SudahAda','Dilewati') NOT NULL,
    tagihan_id BIGINT UNSIGNED NULL,
    tarif_id BIGINT UNSIGNED NULL,
    keterangan VARCHAR(100) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY bsa_penerbitan_santri_unique (penerbitan_id, santri_id),
    UNIQUE KEY bsa_penerbitan_santri_tagihan_unique (tagihan_id),
    KEY bsa_penerbitan_santri_santri_index (santri_id),
    KEY bsa_penerbitan_santri_tarif_index (tarif_id),
    CONSTRAINT bsa_penerbitan_santri_penerbitan_fk FOREIGN KEY (penerbitan_id) REFERENCES bsa_penerbitan (id),
    CONSTRAINT bsa_penerbitan_santri_santri_fk FOREIGN KEY (santri_id) REFERENCES santri (id),
    CONSTRAINT bsa_penerbitan_santri_tagihan_fk FOREIGN KEY (tagihan_id) REFERENCES bsa_tagihan (id),
    CONSTRAINT bsa_penerbitan_santri_tarif_fk FOREIGN KEY (tarif_id) REFERENCES bsa_tarif (id),
    CONSTRAINT bsa_penerbitan_santri_hasil_check CHECK ((hasil = 'Terbit') = (tagihan_id IS NOT NULL AND tarif_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 4. Baris penyesuaian pada tagihan dan riwayat versi tagihan. Hanya INSERT.
--    `bsa_tagihan.total` (027) = Σ komponen + Σ tambahan − Σ potongan;
--    `bsa_tagihan.versi` = versi riwayat terakhir (diperiksa verifier).
-- ===========================================================================
CREATE TABLE IF NOT EXISTS bsa_tagihan_penyesuaian (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tagihan_id BIGINT UNSIGNED NOT NULL,
    versi INT UNSIGNED NOT NULL,
    jenis ENUM('Potongan','Tambahan') NOT NULL,
    nama VARCHAR(100) NOT NULL,
    nominal BIGINT NOT NULL,
    asal ENUM('Penyesuaian','Revisi') NOT NULL,
    penyesuaian_id BIGINT UNSIGNED NULL,
    alasan VARCHAR(500) NOT NULL,
    dicatat_oleh BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY bsa_tagihan_penyesuaian_tagihan_index (tagihan_id, versi),
    KEY bsa_tagihan_penyesuaian_sumber_index (penyesuaian_id),
    KEY bsa_tagihan_penyesuaian_pencatat_index (dicatat_oleh),
    CONSTRAINT bsa_tagihan_penyesuaian_tagihan_fk FOREIGN KEY (tagihan_id) REFERENCES bsa_tagihan (id),
    CONSTRAINT bsa_tagihan_penyesuaian_sumber_fk FOREIGN KEY (penyesuaian_id) REFERENCES bsa_penyesuaian (id),
    CONSTRAINT bsa_tagihan_penyesuaian_pencatat_fk FOREIGN KEY (dicatat_oleh) REFERENCES users (id),
    CONSTRAINT bsa_tagihan_penyesuaian_nominal_check CHECK (nominal > 0),
    CONSTRAINT bsa_tagihan_penyesuaian_versi_check CHECK (versi > 0),
    CONSTRAINT bsa_tagihan_penyesuaian_asal_check CHECK ((asal = 'Penyesuaian') = (penyesuaian_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bsa_tagihan_riwayat (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tagihan_id BIGINT UNSIGNED NOT NULL,
    versi INT UNSIGNED NOT NULL,
    tindakan ENUM('Terbit','Revisi','Dibatalkan') NOT NULL,
    total BIGINT NOT NULL,
    jatuh_tempo DATE NOT NULL,
    alasan VARCHAR(500) NULL,
    penugasan_id BIGINT UNSIGNED NOT NULL,
    aktor BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY bsa_tagihan_riwayat_versi_unique (tagihan_id, versi),
    KEY bsa_tagihan_riwayat_penugasan_index (penugasan_id),
    KEY bsa_tagihan_riwayat_aktor_index (aktor),
    CONSTRAINT bsa_tagihan_riwayat_tagihan_fk FOREIGN KEY (tagihan_id) REFERENCES bsa_tagihan (id),
    CONSTRAINT bsa_tagihan_riwayat_penugasan_fk FOREIGN KEY (penugasan_id) REFERENCES bendahara_bulanan_assignments (id),
    CONSTRAINT bsa_tagihan_riwayat_aktor_fk FOREIGN KEY (aktor) REFERENCES users (id),
    CONSTRAINT bsa_tagihan_riwayat_total_check CHECK (total > 0),
    CONSTRAINT bsa_tagihan_riwayat_versi_check CHECK (versi > 0),
    CONSTRAINT bsa_tagihan_riwayat_alasan_check CHECK (tindakan = 'Terbit' OR CHAR_LENGTH(alasan) >= 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 5. Kuitansi dan pembatalan pembayaran. `bsa_pembayaran` (027) memuat
--    nominal, sumber, nomor kuitansi unik, verifikator; status berpindah
--    Menunggu → Sah (verifikasi) atau → Dibatalkan, nominal tidak pernah
--    diubah. Snapshot kuitansi dibuat sekali saat pembayaran sah; pembatalan
--    dicatat sebagai entri penyeimbang beralasan.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS bsa_pembayaran_kuitansi (
    pembayaran_id BIGINT UNSIGNED NOT NULL,
    tagihan_versi INT UNSIGNED NOT NULL,
    total_tagihan BIGINT NOT NULL,
    terbayar_sebelum BIGINT NOT NULL,
    sisa_sesudah BIGINT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (pembayaran_id),
    CONSTRAINT bsa_kuitansi_pembayaran_fk FOREIGN KEY (pembayaran_id) REFERENCES bsa_pembayaran (id),
    CONSTRAINT bsa_kuitansi_nilai_check CHECK (total_tagihan > 0 AND terbayar_sebelum >= 0 AND sisa_sesudah >= 0 AND terbayar_sebelum + sisa_sesudah < total_tagihan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bsa_pembayaran_pembatalan (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pembayaran_id BIGINT UNSIGNED NOT NULL,
    jenis ENUM('Pembatalan','Penolakan') NOT NULL,
    status_sebelum ENUM('Menunggu','Sah') NOT NULL,
    alasan VARCHAR(500) NOT NULL,
    penugasan_id BIGINT UNSIGNED NOT NULL,
    pengurus_id BIGINT UNSIGNED NOT NULL,
    dibatalkan_oleh BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY bsa_pembatalan_pembayaran_unique (pembayaran_id),
    KEY bsa_pembatalan_penugasan_index (penugasan_id),
    KEY bsa_pembatalan_pengurus_index (pengurus_id),
    KEY bsa_pembatalan_aktor_index (dibatalkan_oleh),
    CONSTRAINT bsa_pembatalan_pembayaran_fk FOREIGN KEY (pembayaran_id) REFERENCES bsa_pembayaran (id),
    CONSTRAINT bsa_pembatalan_penugasan_fk FOREIGN KEY (penugasan_id) REFERENCES bendahara_bulanan_assignments (id),
    CONSTRAINT bsa_pembatalan_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT bsa_pembatalan_aktor_fk FOREIGN KEY (dibatalkan_oleh) REFERENCES users (id),
    CONSTRAINT bsa_pembatalan_jenis_check CHECK ((jenis = 'Penolakan') = (status_sebelum = 'Menunggu')),
    CONSTRAINT bsa_pembatalan_alasan_check CHECK (CHAR_LENGTH(alasan) >= 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
