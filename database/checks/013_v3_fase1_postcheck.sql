-- Post-check migrasi 013 (PRD V3 Fase 1) dalam SQL murni.
--
-- TUJUAN: memverifikasi struktur V3 pada server hosting (MariaDB cPanel) tanpa
-- memerlukan PHP, .env, atau akses shell. Jalankan lewat phpMyAdmin dengan
-- database salinan uji terpilih -- JANGAN pada database produksi.
--
-- Skrip ini hanya MEMBACA. Tidak ada INSERT/UPDATE/DELETE/DROP.
-- Nilai "diharapkan" berasal dari skema yang sudah diverifikasi auditor pada
-- MariaDB 12.3.2. Selisih pada versi hosting adalah temuan yang harus dilaporkan,
-- bukan sesuatu yang boleh disamakan diam-diam.

-- 1. Seluruh 12 tabel V3 terpasang.
SELECT '1. Jumlah tabel V3' AS pemeriksaan, 12 AS diharapkan, COUNT(*) AS aktual,
       IF(COUNT(*) = 12, 'LULUS', 'GAGAL') AS hasil
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'v3\_%';

-- 2. Jumlah FOREIGN KEY / UNIQUE / CHECK per tabel dibanding acuan.
SELECT '2. Constraint per tabel' AS pemeriksaan, e.tabel,
       e.fk AS fk_diharapkan, COALESCE(a.fk, 0) AS fk_aktual,
       e.uq AS uq_diharapkan, COALESCE(a.uq, 0) AS uq_aktual,
       e.ck AS ck_diharapkan, COALESCE(a.ck, 0) AS ck_aktual,
       IF(e.fk = COALESCE(a.fk, 0) AND e.uq = COALESCE(a.uq, 0) AND e.ck = COALESCE(a.ck, 0),
          'LULUS', 'GAGAL') AS hasil
  FROM (
        SELECT 'v3_ambang' AS tabel, 3 AS fk, 0 AS uq, 5 AS ck
  UNION ALL SELECT 'v3_idempotency',      3, 1, 1
  UNION ALL SELECT 'v3_katalog',          3, 1, 4
  UNION ALL SELECT 'v3_kategori',         2, 1, 3
  UNION ALL SELECT 'v3_konseling_kasus',  6, 2, 2
  UNION ALL SELECT 'v3_konseling_sesi',   5, 1, 1
  UNION ALL SELECT 'v3_konseling_tautan', 5, 1, 2
  UNION ALL SELECT 'v3_lampiran',         5, 0, 4
  UNION ALL SELECT 'v3_murobi_catatan',   7, 1, 3
  UNION ALL SELECT 'v3_pelanggaran',      8, 3, 3
  UNION ALL SELECT 'v3_poin_ledger',      6, 2, 1
  UNION ALL SELECT 'v3_publikasi',        7, 1, 3
       ) e
  LEFT JOIN (
        SELECT TABLE_NAME,
               SUM(CONSTRAINT_TYPE = 'FOREIGN KEY') AS fk,
               SUM(CONSTRAINT_TYPE = 'UNIQUE')      AS uq,
               SUM(CONSTRAINT_TYPE = 'CHECK')       AS ck
          FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'v3\_%'
         GROUP BY TABLE_NAME
       ) a ON a.TABLE_NAME = e.tabel
 ORDER BY e.tabel;

-- 3. Indeks bernama yang wajib ada.
SELECT '3. Indeks bernama' AS pemeriksaan, e.indeks, e.tabel,
       IF(EXISTS (SELECT 1 FROM information_schema.STATISTICS s
                   WHERE s.TABLE_SCHEMA = DATABASE() AND s.TABLE_NAME = e.tabel
                     AND s.INDEX_NAME = e.indeks), 'LULUS', 'GAGAL') AS hasil
  FROM (
        SELECT 'v3_katalog' AS tabel, 'katalog_filter' AS indeks
  UNION ALL SELECT 'v3_ambang',           'ambang_rentang'
  UNION ALL SELECT 'v3_pelanggaran',      'pelanggaran_riwayat'
  UNION ALL SELECT 'v3_pelanggaran',      'pelanggaran_warisan'
  UNION ALL SELECT 'v3_pelanggaran',      'pelanggaran_retry'
  UNION ALL SELECT 'v3_pelanggaran',      'pelanggaran_fingerprint'
  UNION ALL SELECT 'v3_poin_ledger',      'ledger_rekonsiliasi'
  UNION ALL SELECT 'v3_konseling_kasus',  'kasus_riwayat'
  UNION ALL SELECT 'v3_konseling_sesi',   'sesi_jadwal'
  UNION ALL SELECT 'v3_konseling_tautan', 'tautan_unik'
  UNION ALL SELECT 'v3_publikasi',        'publikasi_penerima'
  UNION ALL SELECT 'v3_idempotency',      'v3_request_unik'
       ) e
 ORDER BY e.tabel, e.indeks;

-- 4. Seluruh tabel V3 memakai InnoDB dan utf8mb4.
SELECT '4. Engine dan charset' AS pemeriksaan, TABLE_NAME, ENGINE, TABLE_COLLATION,
       IF(ENGINE = 'InnoDB' AND TABLE_COLLATION LIKE 'utf8mb4%', 'LULUS', 'GAGAL') AS hasil
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'v3\_%'
 ORDER BY TABLE_NAME;

-- 5. Kolom generated `sesi_key` benar-benar terbentuk (fitur paling berisiko lintas versi).
SELECT '5. Kolom generated sesi_key' AS pemeriksaan, COUNT(*) AS aktual, 1 AS diharapkan,
       IF(COUNT(*) = 1, 'LULUS', 'GAGAL') AS hasil
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'v3_konseling_tautan'
   AND COLUMN_NAME = 'sesi_key' AND EXTRA LIKE '%GENERATED%';

-- 6. Setiap kolom `version` punya default 1 dan bertipe unsigned.
SELECT '6. Kolom version' AS pemeriksaan, 12 AS diharapkan, COUNT(*) AS aktual,
       IF(COUNT(*) = 12, 'LULUS', 'GAGAL') AS hasil
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'v3\_%'
   AND COLUMN_NAME = 'version' AND COLUMN_DEFAULT = '1' AND COLUMN_TYPE LIKE '%unsigned%';

-- 7. Tidak ada referensi yatim pada seluruh FK milik tabel V3.
--    Pada salinan baru tabel V3 masih kosong, sehingga hasil wajib 0.
SELECT '7. Referensi yatim tabel V3' AS pemeriksaan, 0 AS diharapkan,
       (SELECT COUNT(*) FROM v3_katalog c
         LEFT JOIN v3_kategori p ON p.id = c.kategori_id
         WHERE c.kategori_id IS NOT NULL AND p.id IS NULL)
     + (SELECT COUNT(*) FROM v3_konseling_sesi c
         LEFT JOIN v3_konseling_kasus p ON p.id = c.kasus_id
         WHERE c.kasus_id IS NOT NULL AND p.id IS NULL)
     + (SELECT COUNT(*) FROM v3_poin_ledger c
         LEFT JOIN v3_pelanggaran p ON p.id = c.pelanggaran_id
         WHERE c.pelanggaran_id IS NOT NULL AND p.id IS NULL) AS aktual;

-- 8. Versi server yang benar-benar dipakai. Catat nilainya sebagai bukti.
SELECT '8. Identitas server' AS pemeriksaan, VERSION() AS versi, DATABASE() AS basis_data,
       @@sql_mode AS sql_mode;
