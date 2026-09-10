# Manifest berkas Fase 2

Baseline: `af6285ffe31f9ca22407446b32c9785530beabaa`. Branch: `prd-v3-fase-2`.

## Domain dan endpoint

- `app/V3/PelanggaranRepository.php`
- `app/V3/PelanggaranService.php`
- `app/V3/AttachmentStorage.php`
- `api/v1/index.php`
- `app/bootstrap.php`
- `portal/v3_pelanggaran.php`
- `portal/v3_pelanggaran_detail.php`
- `portal/v3_lampiran.php`
- `storage/private/.htaccess`
- `storage/private/index.html`
- `storage/private/v3/.gitignore`
- `.htaccess` (koreksi audit T5)

## Capability dan navigasi

- `app/Auth/Capabilities.php`
- `app/Ui/Layout.php`
- `app/Ui/Navigation.php`
- `app/Ui/functions.php`
- `admin/sidebar.php`
- `admin/admin_v3_katalog.php`

## Migrasi dan verifikasi

- `database/migrations/014_v3_fase2_pelanggaran.sql`
- `database/rollbacks/014_v3_fase2_pelanggaran.sql`
- `bin/v3_verify.php`
- `bin/v3_phase2_verify.php`
- `bin/v3_phase2_run_tests.sh`

## Pengujian

- `tests/v3_phase1_static.php` — ekspektasi fase-aware tanpa mengurangi 71 pemeriksaan Fase 1
- `tests/v3_phase2_static.php`
- `tests/v3_phase2_integration.php`
- `tests/v3_phase2_concurrency.php`
- `tests/v3_phase2_migration.php`
- `tests/v3_phase2_router.php`
- `tests/browser/uji-v3-fase2.mjs`

## Dokumentasi

- Seluruh berkas dalam `docs/phase-v3-2/`.

Tidak ada berkas `alhasanApps`, migrasi 001–013, atau PRD Fase 3 yang diubah. Hash commit final dicatat pada riwayat Git setelah commit/push.

## Koreksi audit Claude Code

- `database/migrations/015_v3_fase2_koreksi_dan_rekomendasi.sql`
- `database/rollbacks/015_v3_fase2_koreksi_dan_rekomendasi.sql`
- `bin/v3_rekonsiliasi_agregat.php`
- `docs/phase-v3-2/audit-claude-code.md`

Berkas Fase 2 yang ikut berubah karena koreksi T1–T4:
`app/V3/PelanggaranRepository.php`, `app/V3/PelanggaranService.php`,
`portal/v3_pelanggaran_detail.php`, `bin/v3_phase2_verify.php`,
`tests/v3_phase2_static.php`, `tests/v3_phase2_integration.php`,
`tests/v3_phase2_migration.php`.
