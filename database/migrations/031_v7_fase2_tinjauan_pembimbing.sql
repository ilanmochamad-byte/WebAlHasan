-- PRD V7 Fase 2 — Tinjauan Pembimbing, Perbaikan, dan Keputusan Akhir — migrasi 031
--
-- Instruksi Human Developer 27 Oktober 2026 (PRD-V7.md §6 Fase 2). Branch
-- `claude/prd-v7-fase-2` dari commit audit Fase 1 192711e. Implementator
-- Claude Code, auditor Codex.
--
-- Sifat migrasi: ADITIF dan DAPAT DIJALANKAN ULANG (`CREATE TABLE IF NOT
-- EXISTS`). Tidak ada ALTER, DROP, DELETE, UPDATE, atau TRUNCATE terhadap
-- tabel lama mana pun: `izin_pengajuan` (termasuk kolom V7 migrasi 030),
-- keputusan, riwayat, idempotensi, `perizinan` warisan, santri, wali, relasi,
-- users, penugasan, dan seluruh tabel V1–V6 tidak disentuh.
--
-- SATU IDENTITAS KANONIS (PRD V7 §5.2)
-- ------------------------------------
-- Tinjauan pembimbing dan perbaikan wali tidak membuat pengajuan baru. Baris
-- `izin_pengajuan` yang sama berpindah tahap dengan versi optimistis:
--   Menunggu Pembimbing --minta perbaikan--> Perlu Perbaikan Wali
--   Perlu Perbaikan Wali --wali pengaju kirim ulang--> Menunggu Pembimbing
--   Menunggu Pembimbing --tolak--> Ditolak Pembimbing (terminal; BUKAN izin_keputusan)
--   Menunggu Pembimbing --teruskan--> Diajukan | Perlu Penetapan Admin (routing murobi V2)
--   tahap mana pun sebelum keputusan --wali pengaju batal--> Dibatalkan (kolom pembatalan V2)
-- Sesudah diteruskan, penetapan murobi, keputusan murobi/admin pengganti, dan
-- koreksi keputusan memakai tabel dan aturan V2 tanpa perubahan.
--
-- YANG DIBANGUN
-- -------------
--   izin_pembimbing_tinjauan  setiap tindakan pembimbing (minta perbaikan,
--                             tolak, teruskan): pengurus dan penugasan yang
--                             dipakai, pelaku, catatan/alasan, status dan versi
--                             sebelum/sesudah, sidik isi yang ditinjau (SHA-256
--                             tanggal+alasan+catatan; isi tidak disalin), serta
--                             hasil routing murobi untuk penerusan. Hanya bertambah.
--   izin_wali_perbaikan       setiap pengiriman ulang wali pengaju atas satu
--                             permintaan perbaikan: isi sebelum dan sesudah,
--                             sidik keduanya, versi sebelum/sesudah. Isi lama
--                             tidak pernah hilang walau baris pengajuan memuat
--                             isi terbaru. Hanya bertambah.
--
-- Idempotensi memakai `izin_idempotency_keys` V2 (operasi `izin.wali.tinjauan`,
-- `izin.wali.perbaikan`, `izin.wali.batal`); riwayat memakai
-- `izin_riwayat_status`; audit memakai `audit_logs`. Notifikasi V7 adalah
-- Fase 3 — migrasi ini tidak menyentuh outbox.
--
-- WAJIB sebelum dijalankan di produksi (bukan bagian Fase 2):
--   1. Backup terverifikasi.
--   2. php bin/v7_phase2_preflight.php /jalur/aman/manifest-031.json
--   3. php bin/migrate.php up
--   4. php bin/v7_phase2_verify.php /jalur/aman/manifest-031.json

-- ---------------------------------------------------------------------------
-- 1. Tinjauan pembimbing. CHECK menjaga pasangan tindakan ↔ status sesudah,
--    alasan wajib untuk minta perbaikan dan tolak, versi naik tepat satu, dan
--    hasil routing murobi hanya pada penerusan (tepat satu murobi ↔ Diajukan).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS izin_pembimbing_tinjauan (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengajuan_id BIGINT UNSIGNED NOT NULL,
    tindakan ENUM('Minta Perbaikan','Tolak','Teruskan') NOT NULL,
    pembimbing_pengurus_id BIGINT UNSIGNED NOT NULL,
    pembimbing_assignment_id BIGINT UNSIGNED NOT NULL,
    ditinjau_oleh_user_id BIGINT UNSIGNED NULL,
    catatan TEXT NULL,
    status_sebelum VARCHAR(30) NOT NULL,
    status_sesudah VARCHAR(30) NOT NULL,
    versi_sebelum INT UNSIGNED NOT NULL,
    versi_sesudah INT UNSIGNED NOT NULL,
    isi_hash CHAR(64) NOT NULL,
    murobi_guru_id INT NULL,
    murobi_routing_kandidat SMALLINT UNSIGNED NULL,
    ditinjau_pada DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY izin_pembimbing_tinjauan_versi_unique (pengajuan_id, versi_sesudah),
    KEY izin_pembimbing_tinjauan_pembimbing_index (pembimbing_pengurus_id, ditinjau_pada),
    KEY izin_pembimbing_tinjauan_assignment_index (pembimbing_assignment_id),
    KEY izin_pembimbing_tinjauan_actor_index (ditinjau_oleh_user_id),
    KEY izin_pembimbing_tinjauan_murobi_index (murobi_guru_id),
    CONSTRAINT izin_pembimbing_tinjauan_pengajuan_fk FOREIGN KEY (pengajuan_id) REFERENCES izin_pengajuan (id),
    CONSTRAINT izin_pembimbing_tinjauan_pembimbing_fk FOREIGN KEY (pembimbing_pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT izin_pembimbing_tinjauan_assignment_fk FOREIGN KEY (pembimbing_assignment_id) REFERENCES pembimbing_assignments (id),
    CONSTRAINT izin_pembimbing_tinjauan_actor_fk FOREIGN KEY (ditinjau_oleh_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT izin_pembimbing_tinjauan_murobi_fk FOREIGN KEY (murobi_guru_id) REFERENCES guru (id),
    CONSTRAINT izin_pembimbing_tinjauan_versi_check CHECK (versi_sesudah = versi_sebelum + 1),
    CONSTRAINT izin_pembimbing_tinjauan_hash_check CHECK (CHAR_LENGTH(isi_hash) = 64),
    CONSTRAINT izin_pembimbing_tinjauan_alasan_check CHECK (
        tindakan = 'Teruskan' OR (catatan IS NOT NULL AND CHAR_LENGTH(TRIM(catatan)) >= 3)
    ),
    CONSTRAINT izin_pembimbing_tinjauan_status_check CHECK (
        status_sebelum = 'Menunggu Pembimbing'
        AND (
            (tindakan = 'Minta Perbaikan' AND status_sesudah = 'Perlu Perbaikan Wali'
                AND murobi_guru_id IS NULL AND murobi_routing_kandidat IS NULL)
            OR (tindakan = 'Tolak' AND status_sesudah = 'Ditolak Pembimbing'
                AND murobi_guru_id IS NULL AND murobi_routing_kandidat IS NULL)
            OR (tindakan = 'Teruskan' AND murobi_routing_kandidat IS NOT NULL
                AND ((status_sesudah = 'Diajukan' AND murobi_guru_id IS NOT NULL AND murobi_routing_kandidat = 1)
                  OR (status_sesudah = 'Perlu Penetapan Admin' AND murobi_guru_id IS NULL AND murobi_routing_kandidat <> 1)))
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. Perbaikan wali pengaju. Satu perbaikan menjawab tepat satu permintaan
--    perbaikan (UNIQUE tinjauan_id); isi sebelum/sesudah disimpan utuh agar
--    versi lama tetap dapat diaudit sesudah baris pengajuan diperbarui.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS izin_wali_perbaikan (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengajuan_id BIGINT UNSIGNED NOT NULL,
    tinjauan_id BIGINT UNSIGNED NOT NULL,
    wali_id BIGINT UNSIGNED NOT NULL,
    dikirim_oleh_user_id BIGINT UNSIGNED NULL,
    tgl_izin_sebelum DATE NOT NULL,
    tgl_kembali_sebelum DATE NOT NULL,
    alasan_sebelum TEXT NOT NULL,
    catatan_sebelum TEXT NULL,
    tgl_izin_sesudah DATE NOT NULL,
    tgl_kembali_sesudah DATE NOT NULL,
    alasan_sesudah TEXT NOT NULL,
    catatan_sesudah TEXT NULL,
    isi_hash_sebelum CHAR(64) NOT NULL,
    isi_hash_sesudah CHAR(64) NOT NULL,
    versi_sebelum INT UNSIGNED NOT NULL,
    versi_sesudah INT UNSIGNED NOT NULL,
    dikirim_pada DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY izin_wali_perbaikan_versi_unique (pengajuan_id, versi_sesudah),
    UNIQUE KEY izin_wali_perbaikan_tinjauan_unique (tinjauan_id),
    KEY izin_wali_perbaikan_wali_index (wali_id),
    KEY izin_wali_perbaikan_actor_index (dikirim_oleh_user_id),
    CONSTRAINT izin_wali_perbaikan_pengajuan_fk FOREIGN KEY (pengajuan_id) REFERENCES izin_pengajuan (id),
    CONSTRAINT izin_wali_perbaikan_tinjauan_fk FOREIGN KEY (tinjauan_id) REFERENCES izin_pembimbing_tinjauan (id),
    CONSTRAINT izin_wali_perbaikan_wali_fk FOREIGN KEY (wali_id) REFERENCES wali (id),
    CONSTRAINT izin_wali_perbaikan_actor_fk FOREIGN KEY (dikirim_oleh_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT izin_wali_perbaikan_versi_check CHECK (versi_sesudah = versi_sebelum + 1),
    CONSTRAINT izin_wali_perbaikan_rentang_check CHECK (tgl_kembali_sesudah >= tgl_izin_sesudah),
    CONSTRAINT izin_wali_perbaikan_alasan_check CHECK (CHAR_LENGTH(TRIM(alasan_sesudah)) >= 3),
    CONSTRAINT izin_wali_perbaikan_berubah_check CHECK (isi_hash_sebelum <> isi_hash_sesudah)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
