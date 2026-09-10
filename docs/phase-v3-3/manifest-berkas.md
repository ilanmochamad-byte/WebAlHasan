# Manifest berkas Fase 3

## Berkas baru

- `app/V3/KonselingRepository.php`
- `app/V3/KonselingService.php`
- `database/migrations/016_v3_fase3_konseling.sql`
- `database/rollbacks/016_v3_fase3_konseling.sql`
- `portal/v3_konseling.php`
- `portal/v3_konseling_detail.php`
- `portal/v3_konseling_cetak.php`
- `bin/v3_phase3_verify.php`
- `bin/v3_phase3_preflight.php`
- `bin/v3_phase3_run_tests.sh`
- `tests/v3_phase3_static.php`
- `tests/v3_phase3_migration.php`
- `tests/v3_phase3_integration.php`
- `tests/browser/uji-v3-fase3.mjs`
- seluruh dokumen dalam `docs/phase-v3-3/`

## Berkas diubah

- `api/v1/index.php` — rute kasus, sesi, tautan, timeline, dan tindakan murobi
- `app/bootstrap.php` — factory service konseling
- `app/Ui/Navigation.php` — menu web Pembinaan V3
- `app/V3/PelanggaranRepository.php` dan `app/V3/PelanggaranService.php` — relasi baca pelanggaran ke seluruh sesi tindak lanjut
- `portal/v3_pelanggaran_detail.php` — tampilan tindak lanjut konseling
- `bin/v3_verify.php` — diagnostik fondasi mengenali unique revisi sesi 016 secara eksak
- `tests/v3_phase2_integration.php` — fixture masa berlaku stabil saat pengujian dekat tengah malam

Tidak ada berkas aplikasi mobile yang diubah; aplikasi dapat memakai API V3 yang sama. Tidak ada implementasi Fase 4.
