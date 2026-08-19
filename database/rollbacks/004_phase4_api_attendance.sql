-- Rollback Fase 4 HANYA untuk staging atau pemulihan terencana dari backup penuh.
-- Jangan jalankan di produksi: seluruh absensi dan catatan idempotensi Fase 4 akan hilang.

DROP TABLE IF EXISTS api_idempotency_keys;
DROP TABLE IF EXISTS absensi_santri;
DROP TABLE IF EXISTS absensi_guru;
