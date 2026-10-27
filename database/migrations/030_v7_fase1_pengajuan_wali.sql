-- PRD V7 Fase 1 — Fondasi Pengajuan Wali dan Routing Pembimbing — migrasi 030
--
-- Instruksi Human Developer 27 Oktober 2026 (PRD-V7.md §6 Fase 1). Branch
-- `claude/prd-v7-fase-1` dari `origin/main` 3e78564 (memuat spesifikasi
-- `codex/prd-v7-spesifikasi` ee8a3ff). Implementator Claude Code, auditor Codex.
--
-- Sifat migrasi: ADITIF dan DAPAT DIJALANKAN ULANG (idempoten). Setiap
-- pernyataan dibungkus pemeriksaan INFORMATION_SCHEMA seperti migrasi 007,
-- sehingga berjalan ulang tanpa galat "duplicate column/key" dan tetap
-- kompatibel dengan MySQL 8 maupun MariaDB. Tidak ada DROP, DELETE, UPDATE,
-- atau TRUNCATE terhadap data mana pun. Tabel `perizinan` warisan, santri,
-- wali, santri_wali, users, penugasan, dan seluruh tabel V1–V6 tidak disentuh.
--
-- SATU IDENTITAS KANONIS (PRD V7 §5.2)
-- ------------------------------------
-- Pengajuan wali disimpan di `izin_pengajuan` yang SAMA dengan jalur pengurus
-- V2, sehingga berada di ruang ID yang sama, tidak mengubah ID V2/warisan, dan
-- penerusan pembimbing (Fase 2) tidak membuat baris kedua. Benturan tanggal
-- kedua jalur memakai satu pemeriksaan dan satu kunci baris santri.
--
-- YANG DIBANGUN
-- -------------
--   izin_pengajuan.status   ENUM diperluas di UJUNG daftar (nilai lama dan
--                           urutannya tetap) dengan tahap V7:
--                             Menunggu Pembimbing          antrean pembimbing tunggal
--                             Perlu Penetapan Pembimbing   nol/lebih dari satu kandidat → admin
--                             Perlu Perbaikan Wali         (transisi Fase 2)
--                             Ditolak Pembimbing           (transisi Fase 2; bukan keputusan murobi)
--                           Status V2 dipakai lagi setelah pembimbing meneruskan
--                           (Fase 2), sehingga routing/keputusan murobi tetap V2.
--   izin_pengajuan.*        asal_pengajuan (Pengurus|Wali, bawaan Pengurus untuk
--                           seluruh baris lama), wali_id (wali pengaju),
--                           catatan_wali, pembimbing_pengurus_id (pembimbing
--                           tujuan), jejak routing pembimbing, dan jejak
--                           penetapan pembimbing oleh admin. Kolom lama
--                           `pembimbing_assignment_id` pada baris wali menunjuk
--                           penugasan pembimbing tujuan; `pengurus_id` dan
--                           `murobi_guru_id` tetap NULL sampai diteruskan.
--   izin_pengajuan CHECK    baris Wali wajib wali_id dan tanpa pengurus_id;
--                           baris Pengurus tanpa wali_id; status tahap V7 hanya
--                           untuk baris Wali (jalur pengurus tidak pernah masuk
--                           tahap pembimbing).
--   izin_pembimbing_penetapan  riwayat penetapan/penetapan ulang pembimbing
--                           oleh admin: pembimbing sebelum/sesudah, penugasan,
--                           jumlah kandidat, alasan, pelaku, waktu, versi.
--
-- Idempotensi memakai `izin_idempotency_keys` V2 (operasi baru `izin.wali.*`);
-- riwayat memakai `izin_riwayat_status`; audit memakai `audit_logs`. Notifikasi
-- V7 adalah Fase 3 — migrasi ini tidak menyentuh outbox.
--
-- WAJIB sebelum dijalankan di produksi (bukan bagian Fase 1):
--   1. Backup terverifikasi.
--   2. php bin/v7_phase1_preflight.php /jalur/aman/manifest-030.json
--   3. php bin/migrate.php up
--   4. php bin/v7_phase1_verify.php /jalur/aman/manifest-030.json

-- ---------------------------------------------------------------------------
-- 1. Status tahap V7 (ditambahkan di ujung ENUM; perubahan metadata saja).
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'status'
        AND COLUMN_TYPE LIKE '%Menunggu Pembimbing%') = 0,
    'ALTER TABLE izin_pengajuan MODIFY COLUMN status ENUM(''Diajukan'',''Perlu Penetapan Admin'',''Disetujui'',''Ditolak'',''Dibatalkan'',''Menunggu Pembimbing'',''Perlu Penetapan Pembimbing'',''Perlu Perbaikan Wali'',''Ditolak Pembimbing'') NOT NULL DEFAULT ''Diajukan''',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Asal pengajuan dan identitas wali pengaju.
--    Baris lama (V2 dan warisan) otomatis berasal `Pengurus` lewat DEFAULT;
--    nilai kolom lama tidak diubah.
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'asal_pengajuan') = 0,
    'ALTER TABLE izin_pengajuan ADD COLUMN asal_pengajuan ENUM(''Pengurus'',''Wali'') NOT NULL DEFAULT ''Pengurus''',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'wali_id') = 0,
    'ALTER TABLE izin_pengajuan ADD COLUMN wali_id BIGINT UNSIGNED NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'catatan_wali') = 0,
    'ALTER TABLE izin_pengajuan ADD COLUMN catatan_wali TEXT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. Pembimbing tujuan dan jejak routing pembimbing.
--    `pembimbing_routing_kandidat` menyimpan jumlah kandidat sah saat routing
--    sehingga kasus 0 dan >1 dapat diaudit, bukan hanya disimpulkan.
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'pembimbing_pengurus_id') = 0,
    'ALTER TABLE izin_pengajuan ADD COLUMN pembimbing_pengurus_id BIGINT UNSIGNED NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'pembimbing_routing_kandidat') = 0,
    'ALTER TABLE izin_pengajuan ADD COLUMN pembimbing_routing_kandidat SMALLINT UNSIGNED NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'pembimbing_routing_catatan') = 0,
    'ALTER TABLE izin_pengajuan ADD COLUMN pembimbing_routing_catatan VARCHAR(255) NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'pembimbing_routing_pada') = 0,
    'ALTER TABLE izin_pengajuan ADD COLUMN pembimbing_routing_pada DATETIME NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'pembimbing_ditetapkan_oleh_user_id') = 0,
    'ALTER TABLE izin_pengajuan ADD COLUMN pembimbing_ditetapkan_oleh_user_id BIGINT UNSIGNED NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'pembimbing_ditetapkan_pada') = 0,
    'ALTER TABLE izin_pengajuan ADD COLUMN pembimbing_ditetapkan_pada DATETIME NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. Indeks antrean pembimbing/wali dan kunci asing kolom baru.
--      - antrean pembimbing: WHERE asal_pengajuan = 'Wali' AND status = ? AND pembimbing_pengurus_id = ?
--      - daftar wali       : WHERE asal_pengajuan = 'Wali' AND santri_id IN (relasi aktif)
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND INDEX_NAME = 'izin_pengajuan_v7_pembimbing_index') = 0,
    'ALTER TABLE izin_pengajuan ADD KEY izin_pengajuan_v7_pembimbing_index (pembimbing_pengurus_id, asal_pengajuan, status, id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND INDEX_NAME = 'izin_pengajuan_v7_asal_index') = 0,
    'ALTER TABLE izin_pengajuan ADD KEY izin_pengajuan_v7_asal_index (asal_pengajuan, status, id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND INDEX_NAME = 'izin_pengajuan_v7_wali_index') = 0,
    'ALTER TABLE izin_pengajuan ADD KEY izin_pengajuan_v7_wali_index (wali_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND INDEX_NAME = 'izin_pengajuan_v7_penetap_index') = 0,
    'ALTER TABLE izin_pengajuan ADD KEY izin_pengajuan_v7_penetap_index (pembimbing_ditetapkan_oleh_user_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND CONSTRAINT_NAME = 'izin_pengajuan_v7_wali_fk') = 0,
    'ALTER TABLE izin_pengajuan ADD CONSTRAINT izin_pengajuan_v7_wali_fk FOREIGN KEY (wali_id) REFERENCES wali (id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND CONSTRAINT_NAME = 'izin_pengajuan_v7_pembimbing_fk') = 0,
    'ALTER TABLE izin_pengajuan ADD CONSTRAINT izin_pengajuan_v7_pembimbing_fk FOREIGN KEY (pembimbing_pengurus_id) REFERENCES pengurus (id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND CONSTRAINT_NAME = 'izin_pengajuan_v7_penetap_fk') = 0,
    'ALTER TABLE izin_pengajuan ADD CONSTRAINT izin_pengajuan_v7_penetap_fk FOREIGN KEY (pembimbing_ditetapkan_oleh_user_id) REFERENCES users (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 5. Konsistensi asal ↔ identitas ↔ tahap. Seluruh baris lama memenuhi CHECK
--    ini (asal Pengurus, wali_id NULL, status V2), sehingga pemasangan tidak
--    pernah gagal karena data lama.
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND CONSTRAINT_NAME = 'izin_pengajuan_v7_asal_check') = 0,
    'ALTER TABLE izin_pengajuan ADD CONSTRAINT izin_pengajuan_v7_asal_check CHECK (
        (asal_pengajuan = ''Pengurus'' AND wali_id IS NULL
            AND status IN (''Diajukan'',''Perlu Penetapan Admin'',''Disetujui'',''Ditolak'',''Dibatalkan''))
        OR (asal_pengajuan = ''Wali'' AND wali_id IS NOT NULL AND pengurus_id IS NULL)
    )',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 6. Riwayat penetapan pembimbing oleh admin. Hanya bertambah; penetapan
--    ulang menambah baris baru dan tidak pernah menimpa baris lama.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS izin_pembimbing_penetapan (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengajuan_id BIGINT UNSIGNED NOT NULL,
    pembimbing_sebelum_pengurus_id BIGINT UNSIGNED NULL,
    pembimbing_pengurus_id BIGINT UNSIGNED NOT NULL,
    pembimbing_assignment_id BIGINT UNSIGNED NOT NULL,
    status_sebelum VARCHAR(30) NOT NULL,
    jumlah_kandidat SMALLINT UNSIGNED NOT NULL,
    alasan TEXT NOT NULL,
    ditetapkan_oleh_user_id BIGINT UNSIGNED NULL,
    versi_sebelum INT UNSIGNED NOT NULL,
    versi_sesudah INT UNSIGNED NOT NULL,
    ditetapkan_pada DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY izin_pembimbing_penetapan_versi_unique (pengajuan_id, versi_sesudah),
    KEY izin_pembimbing_penetapan_pembimbing_index (pembimbing_pengurus_id),
    KEY izin_pembimbing_penetapan_sebelum_index (pembimbing_sebelum_pengurus_id),
    KEY izin_pembimbing_penetapan_assignment_index (pembimbing_assignment_id),
    KEY izin_pembimbing_penetapan_actor_index (ditetapkan_oleh_user_id),
    CONSTRAINT izin_pembimbing_penetapan_pengajuan_fk FOREIGN KEY (pengajuan_id) REFERENCES izin_pengajuan (id),
    CONSTRAINT izin_pembimbing_penetapan_sebelum_fk FOREIGN KEY (pembimbing_sebelum_pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT izin_pembimbing_penetapan_pembimbing_fk FOREIGN KEY (pembimbing_pengurus_id) REFERENCES pengurus (id),
    CONSTRAINT izin_pembimbing_penetapan_assignment_fk FOREIGN KEY (pembimbing_assignment_id) REFERENCES pembimbing_assignments (id),
    CONSTRAINT izin_pembimbing_penetapan_actor_fk FOREIGN KEY (ditetapkan_oleh_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT izin_pembimbing_penetapan_alasan_check CHECK (CHAR_LENGTH(TRIM(alasan)) >= 3),
    CONSTRAINT izin_pembimbing_penetapan_versi_check CHECK (versi_sesudah = versi_sebelum + 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
