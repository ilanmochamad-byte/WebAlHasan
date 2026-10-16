# Perintah dan hasil pengujian Fase 2

Seluruh perintah dijalankan 16 Oktober 2026 pada lokal, bukan produksi. MariaDB 12.3.2 memakai datadir baru `/private/tmp/webalhasan-v4-fase2-db`, socket `/private/tmp/webalhasan-v4-fase2.sock`, dan `--skip-networking`. Sandbox awal memblokir bind socket/HTTP; perintah uji dijalankan melalui izin eskalasi alat yang disetujui. Tidak ada hambatan uji wajib Fase 2 yang tersisa.

## Reproduksi pengisian Fase 2

Gunakan database baru bernama `webalhasan_v4_fase2_test`, impor `k1807225_webalhasan.sql`. Jangan menjalankan ulang suite stateful pada DB berisi hasil terdahulu: gunakan salinan uji baru. Pada sesi ini DB Fase 2 dibuat ulang sekali untuk pengujian kode akhir; database regresi tetap terpisah.

Konfigurasi shell lokal:

```sh
export DB_HOST=localhost DB_USER=root DB_PASSWORD='' DB_NAME=webalhasan_v4_fase2_test
export APP_ENV=testing APP_DEBUG=false
# Isi secret/password khusus uji; jangan gunakan credential produksi.
export API_TOKEN_HASH_SECRET='<secret-khusus-uji>' V4_TEST_PASSWORD='<password-uji-minimal-12-karakter>'
export V4_HTTP_TOKENS=/private/tmp/v4-fase2-tokens.json V4_TEST_URL=http://127.0.0.1:8962
php() { /opt/homebrew/bin/php -d mysqli.default_socket=/private/tmp/webalhasan-v4-fase2.sock "$@"; }
```

Urutan aktual, exit 0 seluruhnya:

```sh
php bin/migrate.php up
php bin/v4_phase1_preflight.php /private/tmp/v4-fase2-baseline.json
php bin/migrate.php up
php bin/v4_phase1_verify.php /private/tmp/v4-fase2-baseline.json
php tests/v4_phase1_seed.php /private/tmp/v4-fase2-tokens.json
php tests/v4_phase1_integration.php
# Jalankan server di proses terpisah dengan environment yang sama:
php -S 127.0.0.1:8962 -t . tests/v4_phase1_router.php
node tests/browser/uji-v4-fase1.mjs
php tests/v4_phase2_integration.php
php tests/v4_phase2_web_seed.php
php tests/v4_phase2_concurrency.php
node tests/browser/uji-v4-fase2.mjs
node tests/browser/uji-v4-fase2-guru.mjs
```

Integrasi Fase 2 menghasilkan `/private/tmp/v4-fase2-ids.json` (ID fixture, tanpa credential) yang dipakai suite berikutnya. Token disimpan privat oleh seeder, tidak masuk commit. Seeder web memberi password uji kepada guru dan alias sintetis kepada empat santri untuk screenshot. Log hasil akhir tersedia sesuai nama berkas pada `status-penerimaan.md`.

Uji konkurensi menggunakan enam proses PHP yang mulai pada batas waktu sama, masing-masing koneksi DB sendiri. Server HTTP PHP satu proses tidak digunakan untuk mengklaim konkurensi database. Uji browser memakai Playwright yang sudah tersedia di `tests/browser/node_modules` dan Chromium headless. Screenshot guru/pengganti telah diperiksa secara visual; tampilan 375 px dan teks besar dapat dipakai.

## Regresi V1–V3

Database kedua: `webalhasan_v3_phase1_test` pada instance/socket uji yang sama. Struktur CREATE/ALTER diekstrak dari dump tanpa INSERT data, migrasi 001–020 diterapkan, lalu:

```sh
V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php
```

Jalankan suite dengan guard environment yang sesuai, bukan menghapus guard:

```sh
export DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1
export PHASE2_RUN_INTEGRATION=1 PHASE3_RUN_INTEGRATION=1 PHASE4_RUN_INTEGRATION=1 PHASE5_RUN_INTEGRATION=1
export PENUGASAN_RUN_INTEGRATION=1
export V2_PHASE1_RUN_INTEGRATION=1 V2_PHASE2_RUN_INTEGRATION=1 V2_PHASE4_RUN_INTEGRATION=1 V2_PHASE5_RUN_INTEGRATION=1
```

Perintah setiap baris `regresi-ringkasan.txt` adalah `php tests/<nama-suite>.php`; keluaran lengkap di `regresi-<nama-suite>.txt`. Total 15 suite statis dan 14 suite integrasi dijalankan. Akhirnya 14 suite integrasi dan 14 suite statis lulus; satu kegagalan statis warisan tetap terbuka.

Perbaikan lingkungan untuk dua uji ulang:

1. V2 Fase 2: worker memakai PHP_BINARY tanpa opsi `-d` parent. Buat `/private/tmp/v4-fase2-php-ini/socket.ini` berisi `mysqli.default_socket=/private/tmp/webalhasan-v4-fase2.sock`; ekspor `PHP_INI_SCAN_DIR=:/private/tmp/v4-fase2-php-ini`, lalu ulang `php tests/v2_phase2_integration.php` → exit 0. Log `regresi-v2_phase2-perbaikan-env.txt`.
2. V3 Fase 2: seed resmi hanya menyediakan semester aktif. Tambahkan satu fixture `tahun_ajaran ('2026/2027','Genap','Non-Aktif')` pada DB regresi, lalu ulang `php tests/v3_phase2_integration.php` → exit 0. Log `regresi-v3_phase2-perbaikan-fixture.txt`. Tidak mengubah kode atau validator untuk meluluskan uji.

`phase5_static` exit 1 karena path laporan mobile lama; lihat log lengkap dan risiko. Tidak ada klaim seluruh regresi hijau.

## Pemeriksaan akhir

`php -l` pada semua PHP yang diubah/ditambahkan dan `git diff --check` lulus. Diff ditinjau terhadap baseline audit; endpoint V1–V3 dan API profil tidak diubah. Tidak ada migrasi baru, perubahan repository mobile, merge, deploy, atau eksekusi Claude Code bersamaan. Hash berkas implementasi dan bukti utama tercatat dalam `SHA256SUMS`.
