-- Hanya setelah backup terverifikasi; menghapus data V3. Tabel lama tetap utuh.
DROP TABLE IF EXISTS v3_idempotency;
DROP TABLE IF EXISTS v3_lampiran;
DROP TABLE IF EXISTS v3_publikasi;
DROP TABLE IF EXISTS v3_murobi_catatan;
DROP TABLE IF EXISTS v3_konseling_tautan;
DROP TABLE IF EXISTS v3_konseling_sesi;
DROP TABLE IF EXISTS v3_konseling_kasus;
DROP TABLE IF EXISTS v3_poin_ledger;
DROP TABLE IF EXISTS v3_pelanggaran;
DROP TABLE IF EXISTS v3_ambang;
DROP TABLE IF EXISTS v3_katalog;
DROP TABLE IF EXISTS v3_kategori;
