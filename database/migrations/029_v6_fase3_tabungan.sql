-- PRD V6 Fase 3 — Tabungan Lengkap dan Pembayaran Silang — migrasi 029
--
-- Instruksi Human Developer 25 Oktober 2026 (PRD-V6.md §6 Fase 3). Branch
-- `claude/prd-v6-fase-3` dari audit Fase 2 `ca62c31`. Implementator Claude
-- Code, auditor Codex.
--
-- Sifat migrasi: ADITIF dan DAPAT DIJALANKAN ULANG (idempoten). Hanya
-- CREATE TABLE IF NOT EXISTS. Tidak ada DROP, DELETE, UPDATE, TRUNCATE, atau
-- ALTER terhadap tabel mana pun — termasuk tabel 027/028, santri, wali,
-- santri_wali, users, penugasan, PSB, dan V1–V5. Tabel baru dibuat KOSONG.
--
-- YANG DIBANGUN
-- -------------
--   bsa_setoran_transfer   setoran tabungan lewat transfer yang BELUM
--                          diperiksa petugas (Menunggu), lalu diverifikasi
--                          (Sah, menunjuk tepat satu baris ledger) atau
--                          ditolak beralasan (Ditolak). Nominalnya tidak
--                          pernah dihitung sebagai saldo sebelum Sah.
--
-- MENGAPA TABEL TERPISAH (bukan status Menunggu di ledger 027): ledger
-- `bsa_tabungan_transaksi` tetap HANYA-TAMBAH dan urut efektif menurut ID —
-- setiap baris yang ada sudah sah, saldo sesudahnya tidak pernah diubah, dan
-- invarians Fase 1 (saldo akun = saldo sesudah transaksi sah terakhir =
-- jumlah berjalan) tetap berlaku tanpa kolom urutan baru. Verifikasi
-- menyisipkan baris ledger `Setoran/Transfer/Sah` pada saat itu juga dengan
-- referensi silang `transaksi_id`.
--
-- Fitur Fase 3 lain TIDAK memerlukan tabel baru karena skema 027 sudah
-- menyiapkannya: penarikan/belanja (`jenis` Belanja), koreksi pembalik
-- beralasan (`jenis` Pembalik + `pembalik_dari_id` unik + `alasan`), dan
-- pembayaran tagihan dari tabungan (`bsa_pembayaran.sumber` Tabungan +
-- `tabungan_transaksi_id` unik ke debit ledger `PembayaranTagihan`/
-- `Internal`). Kuitansi tabungan memakai nomor turunan ID ledger yang tidak
-- berubah (`BSA-TB-TTTT-NNNNNN`), sebab baris ledger sah tidak pernah diubah.
--
-- NOMINAL: rupiah bulat BIGINT (tanpa float), dijaga CHECK selain layanan.
-- AUDIT: `audit_logs` jejak `bsa.*` hanya ID/jenis/status; nominal, alasan,
-- dan referensi tetap di tabel BSA (PRD V6 §5.4).
--
-- WAJIB sebelum dijalankan di produksi:
--   1. backup terverifikasi + `php bin/v6_phase3_preflight.php manifest.json`;
--   2. uji lengkap pada salinan MySQL/MariaDB berakhiran `_test`;
--   3. rollback berpasangan database/rollbacks/029_*.sql hanya setelah
--      `php bin/v6_phase3_rollback_preflight.php` lulus;
--   4. `php bin/v6_phase3_verify.php manifest.json` setelah migrasi.
-- Fase 3 BUKAN izin rilis parsial: tagihan dan tabungan dirilis bersama
-- sesudah Fase 5 dan keputusan Human Developer.

CREATE TABLE IF NOT EXISTS bsa_setoran_transfer (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    santri_id INT NOT NULL,
    nominal BIGINT NOT NULL,
    referensi VARCHAR(100) NOT NULL,
    catatan VARCHAR(500) NULL,
    status ENUM('Menunggu','Sah','Ditolak') NOT NULL DEFAULT 'Menunggu',
    transaksi_id BIGINT UNSIGNED NULL,
    diputus_oleh BIGINT UNSIGNED NULL,
    diputus_pada DATETIME NULL,
    alasan_tolak VARCHAR(500) NULL,
    penugasan_id BIGINT UNSIGNED NOT NULL,
    pengurus_id BIGINT UNSIGNED NOT NULL,
    dicatat_oleh BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY bsa_setoran_transfer_retry_unique (dicatat_oleh, idempotency_key),
    UNIQUE KEY bsa_setoran_transfer_transaksi_unique (transaksi_id),
    KEY bsa_setoran_transfer_santri_index (santri_id, status),
    KEY bsa_setoran_transfer_status_index (status, created_at),
    KEY bsa_setoran_transfer_penugasan_index (penugasan_id),
    KEY bsa_setoran_transfer_pengurus_index (pengurus_id),
    KEY bsa_setoran_transfer_pemutus_index (diputus_oleh),
    CONSTRAINT bsa_setoran_transfer_santri_fk FOREIGN KEY (santri_id) REFERENCES santri (id),
    CONSTRAINT bsa_setoran_transfer_transaksi_fk FOREIGN KEY (transaksi_id) REFERENCES bsa_tabungan_transaksi (id),
    CONSTRAINT bsa_setoran_transfer_pemutus_fk FOREIGN KEY (diputus_oleh) REFERENCES users (id),
    CONSTRAINT bsa_setoran_transfer_penugasan_fk FOREIGN KEY (penugasan_id) REFERENCES bendahara_bulanan_assignments (id),
    CONSTRAINT bsa_setoran_transfer_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT bsa_setoran_transfer_pencatat_fk FOREIGN KEY (dicatat_oleh) REFERENCES users (id),
    CONSTRAINT bsa_setoran_transfer_nominal_check CHECK (nominal > 0),
    CONSTRAINT bsa_setoran_transfer_referensi_check CHECK (CHAR_LENGTH(referensi) > 0),
    CONSTRAINT bsa_setoran_transfer_sah_check CHECK ((status = 'Sah') = (transaksi_id IS NOT NULL)),
    CONSTRAINT bsa_setoran_transfer_putus_check CHECK ((status = 'Menunggu') = (diputus_oleh IS NULL AND diputus_pada IS NULL)),
    CONSTRAINT bsa_setoran_transfer_tolak_check CHECK ((status = 'Ditolak') = (alasan_tolak IS NOT NULL AND CHAR_LENGTH(alasan_tolak) >= 5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
