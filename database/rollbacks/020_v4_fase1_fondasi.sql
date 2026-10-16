-- Jalankan HANYA setelah bin/v4_phase1_rollback_preflight.php lulus dan backup diverifikasi.
-- Seluruh tabel V4 Fase 1 harus kosong; data bisnis lama tidak disentuh.
SET @v4_rows := (SELECT COUNT(*) FROM v4_verifikasi)+(SELECT COUNT(*) FROM v4_rapor_versi)
 +(SELECT COUNT(*) FROM v4_rapor)+(SELECT COUNT(*) FROM v4_sikap_riwayat)
 +(SELECT COUNT(*) FROM v4_sikap)+(SELECT COUNT(*) FROM v4_nilai_riwayat)
 +(SELECT COUNT(*) FROM v4_nilai)+(SELECT COUNT(*) FROM v4_rencana_mapel)
 +(SELECT COUNT(*) FROM v4_rencana)+(SELECT COUNT(*) FROM v4_tanda_tangan_aset)
 +(SELECT COUNT(*) FROM v4_penanda_tangan);
SET @v4_guard := IF(@v4_rows=0,'DO 0','SELECT * FROM v4_ROLLBACK_DITOLAK_KARENA_DATA_ADA');
PREPARE v4_guard_stmt FROM @v4_guard; EXECUTE v4_guard_stmt; DEALLOCATE PREPARE v4_guard_stmt;
DROP TABLE IF EXISTS v4_verifikasi;
DROP TABLE IF EXISTS v4_rapor_versi;
DROP TABLE IF EXISTS v4_rapor;
DROP TABLE IF EXISTS v4_sikap_riwayat;
DROP TABLE IF EXISTS v4_sikap;
DROP TABLE IF EXISTS v4_nilai_riwayat;
DROP TABLE IF EXISTS v4_nilai;
DROP TABLE IF EXISTS v4_rencana_mapel;
DROP TABLE IF EXISTS v4_rencana;
DROP TABLE IF EXISTS v4_tanda_tangan_aset;
DROP TABLE IF EXISTS v4_penanda_tangan;
