-- PRD V3 Fase 2 (koreksi audit): melepas fingerprint catatan yang sudah tidak
-- berlaku, memisahkan alasan pembatalan dari alasan revisi, menandai
-- rekomendasi yang menjadi basi, dan memulihkan agregat dari ledger.
--
-- Migrasi 001-014 tidak diubah. Seluruh pernyataan di bawah idempoten sehingga
-- runner ulang maupun pemasangan ulang sesudah rollback aman.

-- ---------------------------------------------------------------------------
-- 1. Alasan pembatalan tidak lagi menimpa alasan revisi milik koreksi.
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_pelanggaran'
        AND COLUMN_NAME='alasan_pembatalan') = 0,
    'ALTER TABLE v3_pelanggaran ADD COLUMN alasan_pembatalan TEXT NULL AFTER alasan_revisi',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Catatan lama menyimpan alasan pembatalan pada `alasan_revisi`. Nilai itu
-- dipindahkan, bukan dihapus. Untuk baris yang bukan revisi, `alasan_revisi`
-- hanya mungkin berisi alasan pembatalan sehingga aman dikosongkan setelah
-- disalin. Baris revisi yang terlanjur tertimpa tidak dikarang ulang.
UPDATE v3_pelanggaran
   SET alasan_pembatalan = alasan_revisi
 WHERE status = 'Dibatalkan'
   AND alasan_pembatalan IS NULL
   AND alasan_revisi IS NOT NULL;

UPDATE v3_pelanggaran
   SET alasan_revisi = NULL
 WHERE status = 'Dibatalkan'
   AND revisi_dari_id IS NULL
   AND alasan_revisi IS NOT NULL
   AND alasan_pembatalan IS NOT NULL;

-- Rollback 015 melepas kolom ini bersama isinya. Ketika migrasi dipasang lagi,
-- catatan batal yang alasannya sudah tidak ada di baris tidak dikarang ulang:
-- diberi penanda jujur bahwa nilainya tidak tersedia, sedangkan alasan aslinya
-- tetap dapat ditelusuri pada `audit_logs` peristiwa pembatalan.
UPDATE v3_pelanggaran
   SET alasan_pembatalan = 'Alasan pembatalan tidak tersedia pada baris ini; telusuri audit_logs peristiwa pembatalan.'
 WHERE status = 'Dibatalkan'
   AND alasan_pembatalan IS NULL;

-- ---------------------------------------------------------------------------
-- 2. Fingerprint hanya menjaga catatan yang masih berlaku.
--
-- `pelanggaran_fingerprint` tetap unik dan tidak diubah. Catatan yang sudah
-- digantikan revisi atau dibatalkan melepas fingerprint-nya agar koreksi balik
-- dan pencatatan ulang tidak ditolak sebagai duplikat. Nilai ini turunan murni
-- dari kolom bisnis yang tetap tersimpan, jadi selalu dapat dihitung ulang.
-- ---------------------------------------------------------------------------
UPDATE v3_pelanggaran p
  LEFT JOIN (
        SELECT DISTINCT revisi_dari_id AS id
          FROM v3_pelanggaran
         WHERE revisi_dari_id IS NOT NULL
  ) r ON r.id = p.id
   SET p.fingerprint = NULL
 WHERE p.fingerprint IS NOT NULL
   AND (p.status = 'Dibatalkan' OR r.id IS NOT NULL);

-- ---------------------------------------------------------------------------
-- 3. Rekomendasi dapat ditandai tidak berlaku tanpa dihapus.
-- ---------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi'
        AND COLUMN_NAME='tidak_berlaku_pada') = 0,
    'ALTER TABLE v3_rekomendasi ADD COLUMN tidak_berlaku_pada DATETIME NULL AFTER status',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi'
        AND COLUMN_NAME='tidak_berlaku_alasan') = 0,
    'ALTER TABLE v3_rekomendasi ADD COLUMN tidak_berlaku_alasan VARCHAR(255) NULL AFTER tidak_berlaku_pada',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi'
        AND INDEX_NAME='rekomendasi_berlaku_index') = 0,
    'ALTER TABLE v3_rekomendasi ADD KEY rekomendasi_berlaku_index (santri_id, tahun_ajaran_id, tidak_berlaku_pada)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. Agregat dipulihkan dari ledger.
--
-- `v3_poin_agregat` adalah cache turunan; rollback Fase 2 membuangnya
-- sedangkan ledger tetap utuh. Backfill ini membuat pemasangan ulang 014
-- swa-pulih sehingga verifier tidak lagi menemukan subjek tanpa agregat.
-- ---------------------------------------------------------------------------
INSERT INTO v3_poin_agregat (santri_id, tahun_ajaran_id, total_poin, direkonsiliasi_pada)
SELECT l.santri_id, l.tahun_ajaran_id, COALESCE(SUM(l.perubahan_poin), 0), NOW()
  FROM v3_poin_ledger l
 WHERE l.archived_at IS NULL
 GROUP BY l.santri_id, l.tahun_ajaran_id
    ON DUPLICATE KEY UPDATE total_poin = VALUES(total_poin), direkonsiliasi_pada = NOW();

-- ---------------------------------------------------------------------------
-- 5. Rekomendasi yang totalnya sudah keluar dari rentang ambang ditandai basi.
--    Dijalankan sesudah backfill agar total yang dipakai sudah terrekonsiliasi.
-- ---------------------------------------------------------------------------
UPDATE v3_rekomendasi r
  JOIN v3_ambang a ON a.id = r.ambang_id
  LEFT JOIN v3_poin_agregat g
    ON g.santri_id = r.santri_id AND g.tahun_ajaran_id = r.tahun_ajaran_id
   SET r.tidak_berlaku_pada = NOW(),
       r.tidak_berlaku_alasan = 'Total poin di luar rentang ambang saat migrasi 015.'
 WHERE r.tidak_berlaku_pada IS NULL
   AND NOT (
        a.nilai_minimum <= COALESCE(g.total_poin, 0)
        AND (a.nilai_maksimum IS NULL OR a.nilai_maksimum >= COALESCE(g.total_poin, 0))
   );
