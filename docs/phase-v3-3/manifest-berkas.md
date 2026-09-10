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

## Koreksi audit Claude Code

Berkas baru: `docs/phase-v3-3/audit-claude-code.md`.

Berkas yang berubah karena koreksi K1–K11:

- `app/V3/KonselingService.php` dan `app/V3/KonselingRepository.php` — pembekuan sesi kasus tertutup, pelepasan dan penautan rekomendasi, rantai revisi pelanggaran, aturan status revisi sesi, field kosong formulir, kunci outbox berversi, timeline tanpa audit ganda
- `app/V3/PelanggaranRepository.php` dan `app/V3/PelanggaranService.php` — tindak lanjut konseling dibaca pada seluruh rantai revisi
- `database/migrations/016_v3_fase3_konseling.sql` dan `database/rollbacks/016_v3_fase3_konseling.sql` — guard tautan duplikat dilepas, rollback mempertahankan kolom keputusan, penanda kasus batal
- `bin/v3_phase3_verify.php` dan `bin/v3_verify.php` — invariant baru dan hitungan unique eksak tanpa guard duplikat
- `portal/v3_konseling_detail.php` dan `portal/v3_konseling_cetak.php` — formulir tautan manual, label, pembekuan formulir status, audit akses tunggal
- `tests/v3_phase3_static.php`, `tests/v3_phase3_migration.php`, `tests/v3_phase3_integration.php`, `tests/browser/uji-v3-fase3.mjs` — regresi koreksi
- `docs/phase-v3-3/` — desain, kontrak API, migrasi, hasil uji, status penerimaan, README
