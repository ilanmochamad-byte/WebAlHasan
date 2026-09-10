-- PRD V3 Fase 3: konseling terhubung dan riwayat tindakan.
-- Migrasi 001-015 tidak diubah. Struktur kasus/sesi/tautan sudah dibuat oleh
-- 013; migrasi ini hanya menambah metadata yang dibutuhkan alur Fase 3.

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_kasus'
        AND COLUMN_NAME='alasan_revisi_terakhir') = 0,
    'ALTER TABLE v3_konseling_kasus ADD COLUMN alasan_revisi_terakhir TEXT NULL AFTER ringkasan_penutupan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Bersihkan nama indeks penyangga yang mungkin tertinggal dari versi awal
-- rollback 016. Unique di atas sudah menopang foreign key revisi.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi'
        AND INDEX_NAME='sesi_revisi_fk') > 0,
    'ALTER TABLE v3_konseling_sesi DROP INDEX sesi_revisi_fk',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_tautan'
        AND COLUMN_NAME='sesi_unik_guard') = 0,
    'ALTER TABLE v3_konseling_tautan ADD COLUMN sesi_unik_guard BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(sesi_id,0)) STORED AFTER sesi_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- UNIQUE lama mengizinkan lebih dari satu NULL pada sesi_id. Guard menjadikan
-- tautan tingkat kasus bernilai 0, sementara tiap sesi tetap memakai ID asli.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_tautan'
        AND INDEX_NAME='tautan_unik_efektif') = 0,
    'ALTER TABLE v3_konseling_tautan ADD UNIQUE KEY tautan_unik_efektif (pelanggaran_id,kasus_id,sesi_unik_guard)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_kasus'
        AND COLUMN_NAME='alasan_pembatalan') = 0,
    'ALTER TABLE v3_konseling_kasus ADD COLUMN alasan_pembatalan TEXT NULL AFTER alasan_revisi_terakhir',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi'
        AND COLUMN_NAME='alasan_penjadwalan_ulang') = 0,
    'ALTER TABLE v3_konseling_sesi ADD COLUMN alasan_penjadwalan_ulang TEXT NULL AFTER alasan_revisi',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi'
        AND COLUMN_NAME='alasan_pembatalan') = 0,
    'ALTER TABLE v3_konseling_sesi ADD COLUMN alasan_pembatalan TEXT NULL AFTER alasan_penjadwalan_ulang',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Baris yang mungkin sudah dimasukkan sejak fondasi 013 tidak diberi alasan
-- rekaan. Penanda eksplisit menjaga invariant baru sambil mengarahkan auditor
-- ke audit_logs bila alasan historis memang tersedia di sana.
UPDATE v3_konseling_sesi
   SET alasan_penjadwalan_ulang='Alasan penjadwalan ulang tidak tersedia pada baris warisan; telusuri audit_logs.'
 WHERE status='Dijadwalkan Ulang'
   AND (alasan_penjadwalan_ulang IS NULL OR alasan_penjadwalan_ulang='');

UPDATE v3_konseling_sesi
   SET alasan_pembatalan='Alasan pembatalan tidak tersedia pada baris warisan; telusuri audit_logs.'
 WHERE status='Dibatalkan'
   AND (alasan_pembatalan IS NULL OR alasan_pembatalan='');

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi'
        AND INDEX_NAME='sesi_satu_revisi') = 0,
    'ALTER TABLE v3_konseling_sesi ADD UNIQUE KEY sesi_satu_revisi (revisi_dari_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi'
        AND COLUMN_NAME='ditindaklanjuti_kasus_id') = 0,
    'ALTER TABLE v3_rekomendasi ADD COLUMN ditindaklanjuti_kasus_id BIGINT UNSIGNED NULL AFTER tidak_berlaku_alasan',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi'
        AND COLUMN_NAME='ditindaklanjuti_pada') = 0,
    'ALTER TABLE v3_rekomendasi ADD COLUMN ditindaklanjuti_pada DATETIME NULL AFTER ditindaklanjuti_kasus_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi'
        AND CONSTRAINT_NAME='rekomendasi_kasus_fk') = 0,
    'ALTER TABLE v3_rekomendasi ADD CONSTRAINT rekomendasi_kasus_fk FOREIGN KEY (ditindaklanjuti_kasus_id) REFERENCES v3_konseling_kasus(id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi'
        AND INDEX_NAME='rekomendasi_tindak_lanjut_index') = 0,
    'ALTER TABLE v3_rekomendasi ADD KEY rekomendasi_tindak_lanjut_index (tahun_ajaran_id, tidak_berlaku_pada, ditindaklanjuti_kasus_id, id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
