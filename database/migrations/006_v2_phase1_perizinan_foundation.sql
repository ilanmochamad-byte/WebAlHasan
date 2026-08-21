-- V2 Fase 1: Fondasi Perizinan, Akun, dan Otorisasi
--
-- Sifat migrasi: SEPENUHNYA ADITIF.
-- Tidak ada DROP TABLE, DROP COLUMN, DELETE, atau TRUNCATE terhadap data V1.
-- Tabel `perizinan` lama TIDAK diubah dan TIDAK dihapus; ia tetap menjadi sumber
-- kebenaran historis sampai pengguna menyetujui pensiunnya pada fase berikutnya.
--
-- WAJIB sebelum dijalankan (lihat docs/phase-v2-1/migration-and-rollback.md):
--   1. php bin/v2_phase1_preflight.php  -> backup + manifest + laporan relasi yatim.
--   2. Laporan relasi yatim harus NOL untuk `perizinan.id_santri`; migrasi ini
--      memasang foreign key ke `santri` sehingga baris yatim akan menggagalkan migrasi.
--   3. Uji lengkap pada salinan MySQL berakhiran `_test` terlebih dahulu.
--   4. php bin/v2_phase1_verify.php setelah migrasi.

-- ---------------------------------------------------------------------------
-- 1. Role baru. Admin tetap `admin` dan guru tetap `guru` (PRD 5.2).
--    Hak murobi TIDAK memakai role baru; hak itu berasal dari `murobi_assignments`.
-- ---------------------------------------------------------------------------
INSERT INTO roles (slug, name) VALUES ('pengurus', 'Pengurus')
    ON DUPLICATE KEY UPDATE name = VALUES(name);
INSERT INTO roles (slug, name) VALUES ('orang_tua', 'Orang Tua/Wali')
    ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ---------------------------------------------------------------------------
-- 2. Relasi akun: satu user terhubung ke tepat satu `pengurus` atau satu `wali`.
--    Kolom lama `guru_id` dan `santri_id` tidak disentuh.
-- ---------------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN pengurus_id BIGINT UNSIGNED NULL AFTER guru_id,
    ADD COLUMN wali_id BIGINT UNSIGNED NULL AFTER pengurus_id,
    ADD UNIQUE KEY users_pengurus_unique (pengurus_id),
    ADD UNIQUE KEY users_wali_unique (wali_id),
    ADD CONSTRAINT users_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    ADD CONSTRAINT users_wali_fk FOREIGN KEY (wali_id) REFERENCES wali (id);

-- ---------------------------------------------------------------------------
-- 3. Penugasan pembimbing: tugas inti/tambahan PENGURUS terhadap kamar atau kelas
--    pada satu tahun ajaran. Pembimbing bukan guru dan bukan murobi (PRD 5.1).
-- ---------------------------------------------------------------------------
CREATE TABLE pembimbing_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengurus_id BIGINT UNSIGNED NOT NULL,
    tahun_ajaran_id INT NOT NULL,
    target_type ENUM('Kamar','Kelas') NOT NULL,
    kamar_id INT NULL,
    kelas_id INT NULL,
    tanggal_mulai DATE NOT NULL,
    tanggal_selesai DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    archived_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    target_key VARCHAR(50) GENERATED ALWAYS AS (CONCAT(target_type, ':', COALESCE(kamar_id, kelas_id))) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY pembimbing_assignment_unique (pengurus_id, tahun_ajaran_id, target_key, tanggal_mulai),
    KEY pembimbing_year_status_index (tahun_ajaran_id, is_active, archived_at),
    KEY pembimbing_kamar_index (kamar_id),
    KEY pembimbing_kelas_index (kelas_id),
    CONSTRAINT pembimbing_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT pembimbing_tahun_fk FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran (id),
    CONSTRAINT pembimbing_kamar_fk FOREIGN KEY (kamar_id) REFERENCES kamar (id),
    CONSTRAINT pembimbing_kelas_fk FOREIGN KEY (kelas_id) REFERENCES kelas (id),
    CONSTRAINT pembimbing_actor_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT pembimbing_target_check CHECK (
        (target_type = 'Kamar' AND kamar_id IS NOT NULL AND kelas_id IS NULL)
        OR (target_type = 'Kelas' AND kelas_id IS NOT NULL AND kamar_id IS NULL)
    ),
    CONSTRAINT pembimbing_range_check CHECK (tanggal_selesai IS NULL OR tanggal_selesai >= tanggal_mulai)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Pengajuan izin V2. ID berbagi ruang dengan `perizinan` lama sehingga setiap
--    ID pengajuan lama tetap sama sebelum dan sesudah migrasi (PRD 5.5).
--    Kolom pelaku bernilai NULL untuk data warisan; tidak ada akun fiktif.
-- ---------------------------------------------------------------------------
CREATE TABLE izin_pengajuan (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    legacy_perizinan_id INT NULL,
    is_legacy TINYINT(1) NOT NULL DEFAULT 0,
    santri_id INT NOT NULL,
    pengurus_id BIGINT UNSIGNED NULL,
    diajukan_oleh_user_id BIGINT UNSIGNED NULL,
    pembimbing_assignment_id BIGINT UNSIGNED NULL,
    murobi_guru_id INT NULL,
    tahun_ajaran_id INT NULL,
    tgl_izin DATE NOT NULL,
    tgl_kembali DATE NOT NULL,
    alasan TEXT NOT NULL,
    catatan_pengurus TEXT NULL,
    status ENUM('Diajukan','Perlu Penetapan Admin','Disetujui','Ditolak','Dibatalkan') NOT NULL DEFAULT 'Diajukan',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    idempotency_key VARCHAR(100) NULL,
    diajukan_pada DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY izin_pengajuan_legacy_unique (legacy_perizinan_id),
    KEY izin_pengajuan_santri_range_index (santri_id, tgl_izin, tgl_kembali),
    KEY izin_pengajuan_status_index (status, tgl_izin),
    KEY izin_pengajuan_murobi_index (murobi_guru_id, status),
    KEY izin_pengajuan_pengurus_index (pengurus_id, status),
    KEY izin_pengajuan_tahun_index (tahun_ajaran_id),
    CONSTRAINT izin_pengajuan_santri_fk FOREIGN KEY (santri_id) REFERENCES santri (id),
    CONSTRAINT izin_pengajuan_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT izin_pengajuan_actor_fk FOREIGN KEY (diajukan_oleh_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT izin_pengajuan_pembimbing_fk FOREIGN KEY (pembimbing_assignment_id) REFERENCES pembimbing_assignments (id),
    CONSTRAINT izin_pengajuan_murobi_fk FOREIGN KEY (murobi_guru_id) REFERENCES guru (id),
    CONSTRAINT izin_pengajuan_tahun_fk FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran (id),
    CONSTRAINT izin_pengajuan_range_check CHECK (tgl_kembali >= tgl_izin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. Keputusan: satu peristiwa final per pengajuan. Koreksi dilakukan lewat
--    peristiwa riwayat baru, bukan dengan menimpa atau menghapus baris ini.
-- ---------------------------------------------------------------------------
CREATE TABLE izin_keputusan (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengajuan_id BIGINT UNSIGNED NOT NULL,
    hasil ENUM('Disetujui','Ditolak') NOT NULL,
    alasan TEXT NOT NULL,
    diputus_oleh_user_id BIGINT UNSIGNED NULL,
    kapasitas ENUM('Murobi','Admin Pengganti') NOT NULL,
    alasan_penggantian TEXT NULL,
    diputus_pada DATETIME NOT NULL,
    pengajuan_version INT UNSIGNED NOT NULL DEFAULT 1,
    idempotency_key VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY izin_keputusan_pengajuan_unique (pengajuan_id),
    KEY izin_keputusan_actor_index (diputus_oleh_user_id),
    KEY izin_keputusan_waktu_index (diputus_pada),
    CONSTRAINT izin_keputusan_pengajuan_fk FOREIGN KEY (pengajuan_id) REFERENCES izin_pengajuan (id),
    CONSTRAINT izin_keputusan_actor_fk FOREIGN KEY (diputus_oleh_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT izin_keputusan_pengganti_check CHECK (
        kapasitas <> 'Admin Pengganti' OR (alasan_penggantian IS NOT NULL AND CHAR_LENGTH(TRIM(alasan_penggantian)) > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. Riwayat status: tidak pernah ditimpa. Menyimpan pelaku, alasan, IP, dan
--    user agent, tanpa credential atau secret.
-- ---------------------------------------------------------------------------
CREATE TABLE izin_riwayat_status (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengajuan_id BIGINT UNSIGNED NOT NULL,
    peristiwa VARCHAR(60) NOT NULL,
    status_sebelum VARCHAR(30) NULL,
    status_sesudah VARCHAR(30) NULL,
    pelaku_user_id BIGINT UNSIGNED NULL,
    pelaku_kapasitas VARCHAR(30) NULL,
    alasan TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY izin_riwayat_pengajuan_index (pengajuan_id, id),
    KEY izin_riwayat_actor_index (pelaku_user_id),
    CONSTRAINT izin_riwayat_pengajuan_fk FOREIGN KEY (pengajuan_id) REFERENCES izin_pengajuan (id),
    CONSTRAINT izin_riwayat_actor_fk FOREIGN KEY (pelaku_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 7. Idempotensi mutasi perizinan (create/decision/cancel/reassign).
--    Tabel V1 `api_idempotency_keys` sengaja tidak diubah agar kontrak V1 utuh.
-- ---------------------------------------------------------------------------
CREATE TABLE izin_idempotency_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    operation VARCHAR(80) NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    response_status SMALLINT UNSIGNED NULL,
    response_json JSON NULL,
    pengajuan_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY izin_idempotency_unique (user_id, operation, idempotency_key),
    KEY izin_idempotency_created_index (created_at),
    KEY izin_idempotency_pengajuan_index (pengajuan_id),
    CONSTRAINT izin_idempotency_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT izin_idempotency_pengajuan_fk FOREIGN KEY (pengajuan_id) REFERENCES izin_pengajuan (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 8. Notifikasi (outbox). Kunci unik peristiwa/kanal/penerima membuat retry
--    tidak pernah mengirim duplikat. Isi tidak memuat alasan izin lengkap.
-- ---------------------------------------------------------------------------
CREATE TABLE notifikasi_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_key VARCHAR(120) NOT NULL,
    event_type VARCHAR(60) NOT NULL,
    kanal ENUM('InApp','Push','WhatsApp') NOT NULL,
    penerima_user_id BIGINT UNSIGNED NOT NULL,
    pengajuan_id BIGINT UNSIGNED NULL,
    judul VARCHAR(150) NOT NULL,
    isi VARCHAR(500) NOT NULL,
    status ENUM('Queued','Sent','Failed') NOT NULL DEFAULT 'Queued',
    percobaan INT UNSIGNED NOT NULL DEFAULT 0,
    error_terakhir VARCHAR(255) NULL,
    dibaca_pada DATETIME NULL,
    dikirim_pada DATETIME NULL,
    percobaan_terakhir_pada DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY notifikasi_event_channel_recipient_unique (event_key, kanal, penerima_user_id),
    KEY notifikasi_penerima_index (penerima_user_id, dibaca_pada, id),
    KEY notifikasi_status_index (status, kanal, percobaan),
    KEY notifikasi_pengajuan_index (pengajuan_id),
    CONSTRAINT notifikasi_penerima_fk FOREIGN KEY (penerima_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT notifikasi_pengajuan_fk FOREIGN KEY (pengajuan_id) REFERENCES izin_pengajuan (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 9. Perangkat push per pengguna/perangkat. Token hanya disimpan sebagai hash;
--    nilai mentah tidak pernah masuk basis data, log, atau audit.
-- ---------------------------------------------------------------------------
CREATE TABLE perangkat_push (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    token_terlindungi VARBINARY(512) NULL,
    platform ENUM('android','ios','web') NOT NULL,
    device_label VARCHAR(100) NULL,
    terakhir_aktif_pada DATETIME NULL,
    dicabut_pada DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY perangkat_push_token_unique (token_hash),
    KEY perangkat_push_user_index (user_id, dicabut_pada),
    CONSTRAINT perangkat_push_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 10. Pengaturan kanal notifikasi. Baris tunggal dijaga oleh kolom `singleton`.
--     Secret provider TETAP di environment; hanya hasil pemeriksaan disimpan.
-- ---------------------------------------------------------------------------
CREATE TABLE pengaturan_notifikasi (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    singleton TINYINT(1) NOT NULL DEFAULT 1,
    inapp_enabled TINYINT(1) NOT NULL DEFAULT 1,
    push_enabled TINYINT(1) NOT NULL DEFAULT 0,
    whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    whatsapp_provider VARCHAR(50) NULL,
    whatsapp_check_status ENUM('Belum Diperiksa','Lulus','Gagal') NOT NULL DEFAULT 'Belum Diperiksa',
    whatsapp_check_pesan VARCHAR(255) NULL,
    whatsapp_check_pada DATETIME NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY pengaturan_notifikasi_singleton_unique (singleton),
    CONSTRAINT pengaturan_notifikasi_actor_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT pengaturan_notifikasi_whatsapp_check CHECK (whatsapp_enabled = 0 OR whatsapp_check_status = 'Lulus')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO pengaturan_notifikasi (singleton, inapp_enabled, push_enabled, whatsapp_enabled)
VALUES (1, 1, 0, 0)
ON DUPLICATE KEY UPDATE singleton = VALUES(singleton);

-- ---------------------------------------------------------------------------
-- BACKFILL:BEGIN
-- Blok di bawah ini dijalankan ulang secara aman oleh bin/v2_phase1_backfill.php
-- (skrip itu membaca file ini di antara penanda BACKFILL:BEGIN/BACKFILL:END).
-- Sifatnya idempoten: baris yang sudah termigrasi tidak pernah diduplikasi dan
-- baris V2 asli tidak pernah tersentuh.
--
-- Pemetaan status lama: Pending -> Diajukan, Disetujui -> Disetujui, Ditolak -> Ditolak.
-- Pelaku (pengurus, murobi, pemberi keputusan) tidak diketahui pada data lama dan
-- karena itu tetap NULL. Penanda `is_legacy` = 1 dipakai UI untuk label "Data warisan".
INSERT INTO izin_pengajuan
    (id, legacy_perizinan_id, is_legacy, santri_id, pengurus_id, diajukan_oleh_user_id,
     pembimbing_assignment_id, murobi_guru_id, tahun_ajaran_id, tgl_izin, tgl_kembali,
     alasan, catatan_pengurus, status, version, idempotency_key, diajukan_pada)
SELECT
    p.id,
    p.id,
    1,
    p.id_santri,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    p.tgl_izin,
    p.tgl_kembali,
    p.alasan,
    NULL,
    CASE p.status
        WHEN 'Pending' THEN 'Diajukan'
        WHEN 'Disetujui' THEN 'Disetujui'
        WHEN 'Ditolak' THEN 'Ditolak'
    END,
    1,
    NULL,
    NULL
FROM perizinan p
WHERE NOT EXISTS (SELECT 1 FROM izin_pengajuan t WHERE t.legacy_perizinan_id = p.id);

INSERT INTO izin_riwayat_status
    (pengajuan_id, peristiwa, status_sebelum, status_sesudah, pelaku_user_id,
     pelaku_kapasitas, alasan, ip_address, user_agent)
SELECT
    t.id,
    'migrasi_warisan',
    NULL,
    t.status,
    NULL,
    NULL,
    'Data warisan V1: pelaku tidak tercatat pada sistem lama.',
    NULL,
    NULL
FROM izin_pengajuan t
WHERE t.is_legacy = 1
  AND NOT EXISTS (
      SELECT 1 FROM izin_riwayat_status r
      WHERE r.pengajuan_id = t.id AND r.peristiwa = 'migrasi_warisan'
  );

-- Selaraskan penghitung AUTO_INCREMENT agar pengajuan V2 baru tidak pernah
-- memakai ulang ID warisan.
SET @izin_next_id := (SELECT IFNULL(MAX(id), 0) + 1 FROM izin_pengajuan);
SET @izin_next_sql := CONCAT('ALTER TABLE izin_pengajuan AUTO_INCREMENT = ', @izin_next_id);
PREPARE izin_next_stmt FROM @izin_next_sql;
EXECUTE izin_next_stmt;
DEALLOCATE PREPARE izin_next_stmt;
-- BACKFILL:END
