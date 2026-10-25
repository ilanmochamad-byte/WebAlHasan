-- PRD V6 Fase 1 — Fondasi BSA dan Transaksi Minimum — migrasi 027
--
-- Keputusan Human Developer 25 Oktober 2026 (PRD-V6.md). Branch
-- `claude/prd-v6-fase-1`. Implementator Claude Code, auditor Codex.
--
-- Sifat migrasi: ADITIF dan DAPAT DIJALANKAN ULANG (idempoten). Tidak ada
-- DROP, DELETE, UPDATE, TRUNCATE, atau ALTER terhadap tabel yang sudah ada.
-- Tabel santri, wali, santri_wali, users, pengurus, penugasan (termasuk
-- `bendahara_bulanan_assignments`), PSB, dan V1–V5 tidak disentuh. Seluruh
-- tabel baru dibuat KOSONG: saldo awal semua santri nol dan tidak ada data
-- lama yang dipindahkan (PRD V6 §5.1).
--
-- PENUGASAN BSA
-- -------------
-- Satu penugasan BSA = jenis fondasi `bendahara_bulanan` (tabel 012
-- `bendahara_bulanan_assignments`, cakupan unit/jenjang opsional). Perluasan
-- label/capability dan "tidak gugur saat semester berganti" (keputusan Human
-- Developer 25 Oktober 2026) berada di kode `App\Penugasan\PenugasanJenis`,
-- bukan di skema; tabel penugasan tidak diubah. Transaksi BSA merujuk baris
-- penugasan yang dipakai saat mencatat.
--
-- YANG DIBANGUN
-- -------------
--   bsa_tagihan              satu tagihan bulanan per santri per periode
--                            (kunci unik santri+periode); total dan
--                            `terbayar` (cerminan pembayaran sah, Fase 2)
--   bsa_tagihan_komponen     snapshot komponen biaya tagihan (≥ 1 baris)
--   bsa_pembayaran           pembayaran tagihan tunai/transfer/tabungan
--                            (skema minimum; alurnya Fase 2–3)
--   bsa_tabungan_akun        satu akun saldo per santri; CERMINAN ledger,
--                            saldo tidak pernah negatif (CHECK)
--   bsa_tabungan_transaksi   ledger tabungan: setoran, penarikan, belanja,
--                            pembayaran tagihan, pembalik; saldo sesudah
--                            transaksi disimpan per baris
--   bsa_idempotensi          klaim kunci idempotensi per akun (kunci sama +
--                            isi sama → hasil sama; isi beda → 409)
--
-- NOMINAL: rupiah bulat dalam BIGINT (numerik eksak, tanpa float). Saldo dan
-- nominal dijaga CHECK di basis data selain validasi layanan.
--
-- AUDIT: setiap baris uang menyimpan aktor, waktu, penugasan, dan tujuan/
-- catatan. `audit_logs` menerima jejak `bsa.*` berisi ID dan jenis saja —
-- tanpa nominal, referensi, atau tujuan (PRD V6 §5.4: data keuangan tidak
-- masuk log umum).
--
-- WAJIB sebelum dijalankan di produksi:
--   1. backup terverifikasi + `php bin/v6_phase1_preflight.php manifest.json`;
--   2. uji lengkap pada salinan MySQL/MariaDB berakhiran `_test`;
--   3. rollback berpasangan di database/rollbacks/027_*.sql, hanya setelah
--      `php bin/v6_phase1_rollback_preflight.php` lulus (tabel BSA kosong);
--   4. `php bin/v6_phase1_verify.php manifest.json` setelah migrasi.
-- Fase 1 BUKAN izin rilis parsial: tagihan dan tabungan dirilis bersama.

-- ===========================================================================
-- 1. Tagihan bulanan. Periode = bulan kalender 'YYYY-MM'.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS bsa_tagihan (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    santri_id INT NOT NULL,
    periode CHAR(7) NOT NULL,
    jatuh_tempo DATE NOT NULL,
    total BIGINT NOT NULL,
    terbayar BIGINT NOT NULL DEFAULT 0,
    jenjang VARCHAR(20) NULL,
    status ENUM('Terbit','Dibatalkan') NOT NULL DEFAULT 'Terbit',
    versi INT UNSIGNED NOT NULL DEFAULT 1,
    catatan VARCHAR(500) NULL,
    penugasan_id BIGINT UNSIGNED NOT NULL,
    pengurus_id BIGINT UNSIGNED NOT NULL,
    diterbitkan_oleh BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY bsa_tagihan_santri_periode_unique (santri_id, periode),
    UNIQUE KEY bsa_tagihan_retry_unique (diterbitkan_oleh, idempotency_key),
    KEY bsa_tagihan_periode_index (periode, status),
    KEY bsa_tagihan_penugasan_index (penugasan_id),
    KEY bsa_tagihan_pengurus_index (pengurus_id),
    CONSTRAINT bsa_tagihan_santri_fk FOREIGN KEY (santri_id) REFERENCES santri (id),
    CONSTRAINT bsa_tagihan_penugasan_fk FOREIGN KEY (penugasan_id) REFERENCES bendahara_bulanan_assignments (id),
    CONSTRAINT bsa_tagihan_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT bsa_tagihan_penerbit_fk FOREIGN KEY (diterbitkan_oleh) REFERENCES users (id),
    CONSTRAINT bsa_tagihan_periode_check CHECK (periode REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'),
    CONSTRAINT bsa_tagihan_total_check CHECK (total > 0),
    CONSTRAINT bsa_tagihan_terbayar_check CHECK (terbayar >= 0 AND terbayar <= total),
    CONSTRAINT bsa_tagihan_versi_check CHECK (versi > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 2. Snapshot komponen tagihan. Jumlah komponen = total tagihan (diperiksa
--    layanan dan verifier).
-- ===========================================================================
CREATE TABLE IF NOT EXISTS bsa_tagihan_komponen (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tagihan_id BIGINT UNSIGNED NOT NULL,
    urutan TINYINT UNSIGNED NOT NULL,
    nama VARCHAR(100) NOT NULL,
    nominal BIGINT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY bsa_komponen_urutan_unique (tagihan_id, urutan),
    CONSTRAINT bsa_komponen_tagihan_fk FOREIGN KEY (tagihan_id) REFERENCES bsa_tagihan (id),
    CONSTRAINT bsa_komponen_nominal_check CHECK (nominal > 0),
    CONSTRAINT bsa_komponen_urutan_check CHECK (urutan > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 3. Ledger tabungan. Transaksi sah TIDAK diubah atau dihapus; koreksi
--    berupa baris `Pembalik` beralasan yang merujuk transaksi asal (Fase 3).
--    Status `Menunggu`/`Ditolak` disiapkan untuk verifikasi transfer Fase 3;
--    hanya status `Sah` yang mengubah saldo.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS bsa_tabungan_transaksi (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    santri_id INT NOT NULL,
    jenis ENUM('Setoran','Penarikan','Belanja','PembayaranTagihan','Pembalik') NOT NULL,
    arah ENUM('Masuk','Keluar') NOT NULL,
    nominal BIGINT NOT NULL,
    saldo_sesudah BIGINT NULL,
    status ENUM('Menunggu','Sah','Ditolak') NOT NULL DEFAULT 'Sah',
    metode ENUM('Tunai','Transfer','Internal') NOT NULL,
    tujuan VARCHAR(255) NULL,
    referensi VARCHAR(100) NULL,
    catatan VARCHAR(500) NULL,
    pembalik_dari_id BIGINT UNSIGNED NULL,
    alasan VARCHAR(500) NULL,
    penugasan_id BIGINT UNSIGNED NOT NULL,
    pengurus_id BIGINT UNSIGNED NOT NULL,
    dicatat_oleh BIGINT UNSIGNED NOT NULL,
    disetujui_oleh BIGINT UNSIGNED NULL,
    disetujui_pada DATETIME NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY bsa_transaksi_retry_unique (dicatat_oleh, idempotency_key),
    UNIQUE KEY bsa_transaksi_pembalik_unique (pembalik_dari_id),
    KEY bsa_transaksi_santri_index (santri_id, id),
    KEY bsa_transaksi_status_index (status, created_at),
    KEY bsa_transaksi_penugasan_index (penugasan_id),
    KEY bsa_transaksi_pengurus_index (pengurus_id),
    KEY bsa_transaksi_penyetuju_index (disetujui_oleh),
    CONSTRAINT bsa_transaksi_santri_fk FOREIGN KEY (santri_id) REFERENCES santri (id),
    CONSTRAINT bsa_transaksi_pembalik_fk FOREIGN KEY (pembalik_dari_id) REFERENCES bsa_tabungan_transaksi (id),
    CONSTRAINT bsa_transaksi_penugasan_fk FOREIGN KEY (penugasan_id) REFERENCES bendahara_bulanan_assignments (id),
    CONSTRAINT bsa_transaksi_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT bsa_transaksi_pencatat_fk FOREIGN KEY (dicatat_oleh) REFERENCES users (id),
    CONSTRAINT bsa_transaksi_penyetuju_fk FOREIGN KEY (disetujui_oleh) REFERENCES users (id),
    CONSTRAINT bsa_transaksi_nominal_check CHECK (nominal > 0),
    CONSTRAINT bsa_transaksi_saldo_check CHECK (saldo_sesudah IS NULL OR saldo_sesudah >= 0),
    CONSTRAINT bsa_transaksi_sah_check CHECK (status <> 'Sah' OR (saldo_sesudah IS NOT NULL AND disetujui_oleh IS NOT NULL AND disetujui_pada IS NOT NULL)),
    CONSTRAINT bsa_transaksi_arah_check CHECK (
        (jenis = 'Setoran' AND arah = 'Masuk')
        OR (jenis IN ('Penarikan', 'Belanja', 'PembayaranTagihan') AND arah = 'Keluar')
        OR jenis = 'Pembalik'),
    CONSTRAINT bsa_transaksi_pembalik_check CHECK ((jenis = 'Pembalik') = (pembalik_dari_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 4. Akun tabungan: satu per santri, cerminan ledger. Baris dibuat saat
--    transaksi pertama santri; saldo hanya berubah bersama baris ledger di
--    transaksi basis data yang sama dan tidak pernah negatif.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS bsa_tabungan_akun (
    santri_id INT NOT NULL,
    saldo BIGINT NOT NULL DEFAULT 0,
    jumlah_transaksi INT UNSIGNED NOT NULL DEFAULT 0,
    transaksi_terakhir_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (santri_id),
    KEY bsa_akun_transaksi_index (transaksi_terakhir_id),
    CONSTRAINT bsa_akun_santri_fk FOREIGN KEY (santri_id) REFERENCES santri (id),
    CONSTRAINT bsa_akun_transaksi_fk FOREIGN KEY (transaksi_terakhir_id) REFERENCES bsa_tabungan_transaksi (id),
    CONSTRAINT bsa_akun_saldo_check CHECK (saldo >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 5. Pembayaran tagihan (skema minimum; alur pembayaran tunai/transfer Fase 2,
--    pembayaran dari tabungan Fase 3). Satu pembayaran dari tabungan merujuk
--    tepat satu debit ledger (`tabungan_transaksi_id`, unik).
-- ===========================================================================
CREATE TABLE IF NOT EXISTS bsa_pembayaran (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tagihan_id BIGINT UNSIGNED NOT NULL,
    santri_id INT NOT NULL,
    nominal BIGINT NOT NULL,
    sumber ENUM('Tunai','Transfer','Tabungan') NOT NULL,
    status ENUM('Menunggu','Sah','Dibatalkan') NOT NULL DEFAULT 'Sah',
    tanggal_transaksi DATE NOT NULL,
    referensi VARCHAR(100) NULL,
    nomor_kuitansi VARCHAR(40) NOT NULL,
    tabungan_transaksi_id BIGINT UNSIGNED NULL,
    pembatalan_dari_id BIGINT UNSIGNED NULL,
    alasan VARCHAR(500) NULL,
    penugasan_id BIGINT UNSIGNED NOT NULL,
    pengurus_id BIGINT UNSIGNED NOT NULL,
    dicatat_oleh BIGINT UNSIGNED NOT NULL,
    diverifikasi_oleh BIGINT UNSIGNED NULL,
    diverifikasi_pada DATETIME NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY bsa_pembayaran_kuitansi_unique (nomor_kuitansi),
    UNIQUE KEY bsa_pembayaran_retry_unique (dicatat_oleh, idempotency_key),
    UNIQUE KEY bsa_pembayaran_tabungan_unique (tabungan_transaksi_id),
    UNIQUE KEY bsa_pembayaran_pembatalan_unique (pembatalan_dari_id),
    KEY bsa_pembayaran_tagihan_index (tagihan_id, status),
    KEY bsa_pembayaran_santri_index (santri_id),
    KEY bsa_pembayaran_penugasan_index (penugasan_id),
    KEY bsa_pembayaran_pengurus_index (pengurus_id),
    KEY bsa_pembayaran_verifikator_index (diverifikasi_oleh),
    CONSTRAINT bsa_pembayaran_tagihan_fk FOREIGN KEY (tagihan_id) REFERENCES bsa_tagihan (id),
    CONSTRAINT bsa_pembayaran_santri_fk FOREIGN KEY (santri_id) REFERENCES santri (id),
    CONSTRAINT bsa_pembayaran_tabungan_fk FOREIGN KEY (tabungan_transaksi_id) REFERENCES bsa_tabungan_transaksi (id),
    CONSTRAINT bsa_pembayaran_pembatalan_fk FOREIGN KEY (pembatalan_dari_id) REFERENCES bsa_pembayaran (id),
    CONSTRAINT bsa_pembayaran_penugasan_fk FOREIGN KEY (penugasan_id) REFERENCES bendahara_bulanan_assignments (id),
    CONSTRAINT bsa_pembayaran_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT bsa_pembayaran_pencatat_fk FOREIGN KEY (dicatat_oleh) REFERENCES users (id),
    CONSTRAINT bsa_pembayaran_verifikator_fk FOREIGN KEY (diverifikasi_oleh) REFERENCES users (id),
    CONSTRAINT bsa_pembayaran_nominal_check CHECK (nominal > 0),
    CONSTRAINT bsa_pembayaran_tabungan_check CHECK ((sumber = 'Tabungan') = (tabungan_transaksi_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 6. Idempotensi lintas operasi BSA. Satu kunci per akun hanya untuk satu
--    operasi dan satu isi; hash permintaan memuat operasi, santri, dan nominal.
--    Tidak menyimpan salinan respons.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS bsa_idempotensi (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    kunci VARCHAR(100) NOT NULL,
    operasi VARCHAR(40) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    hasil_id BIGINT UNSIGNED NULL,
    status_code SMALLINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY bsa_idempotensi_unique (user_id, kunci),
    CONSTRAINT bsa_idempotensi_user_fk FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
