-- Utamakan rollback kode. Riwayat revisi kasus adalah catatan bisnis, sehingga
-- tabelnya hanya dilepas bila masih kosong; tabel berisi dipertahankan dan aman
-- bagi kode lama. Sesi yang sudah ditutup otomatis tidak dibuka kembali karena
-- keputusan penutupan kasusnya tetap berlaku.

SET @ada := (SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_kasus_revisi');
SET @sql := IF(@ada > 0,
    'SELECT COUNT(*) INTO @isi FROM v3_konseling_kasus_revisi',
    'SELECT 0 INTO @isi');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@ada > 0 AND @isi = 0,
    'DROP TABLE v3_konseling_kasus_revisi',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
