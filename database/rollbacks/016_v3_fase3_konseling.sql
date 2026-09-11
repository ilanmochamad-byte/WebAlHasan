-- Utamakan rollback kode. Rollback skema ini hanya melepas indeks dan penjaga
-- struktural Fase 3. Kolom yang berisi keputusan bisnis — alasan koreksi kasus,
-- alasan pembatalan kasus/sesi, alasan penjadwalan ulang, dan tautan
-- rekomendasi ke kasus — sengaja DIPERTAHANKAN. Kolomnya nullable sehingga
-- aman bagi kode Fase 2, sedangkan membuangnya menghapus keputusan yang tidak
-- dapat dibangun ulang dari tabel lain (audit Claude Code Fase 3, temuan K4).
-- Kasus, sesi, tautan, pelanggaran, poin, dan catatan bisnis tidak dihapus.

-- Draf awal 016 memasang guard tautan kedua yang identik dengan tautan_unik
-- milik 013 (temuan K8). Lepas bila masih ada pada database yang sempat
-- memasang draf tersebut; tautan_unik tetap menjaga tautan sesi NULL.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_tautan'
        AND INDEX_NAME='tautan_unik_efektif') > 0,
    'ALTER TABLE v3_konseling_tautan DROP INDEX tautan_unik_efektif',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_tautan'
        AND COLUMN_NAME='sesi_unik_guard') > 0,
    'ALTER TABLE v3_konseling_tautan DROP COLUMN sesi_unik_guard',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi'
        AND INDEX_NAME='rekomendasi_tindak_lanjut_index') > 0,
    'ALTER TABLE v3_rekomendasi DROP INDEX rekomendasi_tindak_lanjut_index',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi'
        AND INDEX_NAME='revisi_dari_id') = 0,
    'ALTER TABLE v3_konseling_sesi ADD KEY revisi_dari_id (revisi_dari_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- MariaDB dapat memakai indeks unik Fase 3 sebagai indeks pendukung foreign
-- key lalu membuang indeks implisit lama. Sediakan indeks biasa lebih dahulu
-- agar foreign key revisi tetap valid ketika indeks unik dilepas.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi'
        AND INDEX_NAME='sesi_satu_revisi') > 0,
    'ALTER TABLE v3_konseling_sesi DROP INDEX sesi_satu_revisi',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
