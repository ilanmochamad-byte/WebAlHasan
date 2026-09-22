-- Rollback kode terlebih dahulu. Seluruh snapshot, draft, relasi pengiriman,
-- riwayat dan constraint sengaja dipertahankan, termasuk tabel yang kosong.
-- Migrasi ulang idempoten; tidak ada keputusan bisnis yang dibuang.
SELECT 1;
