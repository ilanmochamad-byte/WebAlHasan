# Perintah dan reproduksi uji

Seluruh perintah DB/HTTP di bawah hanya untuk instance MariaDB uji tanpa jaringan dan salinan checkout. Jangan source environment produksi, menjalankan seed pada produksi, atau memakai storage produksi. File env/token sementara mode 0600, tidak disertakan Git.

## Setup yang benar-benar digunakan

1. `mariadb-install-db --datadir=/private/tmp/v4p5-db --auth-root-authentication-method=normal --skip-test-db` kemudian `mariadbd --datadir=/private/tmp/v4p5-db --socket=/private/tmp/v4p5.sock --pid-file=/private/tmp/v4p5-db.pid --skip-networking`. Socket itu khusus sesi ini.
2. Salin repo ke `/private/tmp/v4p5-web` tanpa .git, .env, docs gambar lama atau storage lama. Sertakan .env.example; gunakan vendor dan Playwright yang sudah terpasang dari lock. Buat storage/private/v4 baru. Salin dokumentasi teks yang diperlukan suite statis. Pada **salinan pengujian saja**, arahkan path fixture `/private/tmp/v4-fase1` … `v4-fase4` ke `v4p5-fase1` … `v4p5-fase4`, dan output docs/phase-v4-4 ke docs/phase-v4-5.
3. Siapkan env privat: APP_ENV=testing, APP_DEBUG=false, DB_HOST=localhost, DB_USER=root, DB_PASSWORD kosong hanya pada instance tanpa jaringan ini, DB_NAME=webalhasan_v4_fase2_test; token hash secret, PUSH_TOKEN_KEY dan V4_TEST_PASSWORD acak baru. PHP_INI_SCAN_DIR memuat mysqli.default_socket=/private/tmp/v4p5.sock. APP_URL/V4_TEST_URL=http://127.0.0.1:8965, V4_HTTP_TOKENS=/private/tmp/v4p5-fase4-tokens.json, V4_EVIDENCE_DIR=docs/phase-v4-5, MOBILE_APP_ROOT menunjuk checkout mobile fitur.
4. Buat DB uji itu dan impor dump baseline lokal yang sudah ada di repo; dump/hasil backup tidak masuk bukti baru. `php bin/migrate.php up` → `up` ulang. `php tests/v4_phase1_seed.php /private/tmp/v4p5-fase4-tokens.json` lalu `php tests/v4_phase1_integration.php`. Jalankan server `php -d upload_max_filesize=10M -d post_max_size=12M -S 127.0.0.1:8965 tests/v4_phase1_router.php` dari salinan; log di /private/tmp.
5. `bash docs/phase-v4-5/perintah-regresi-v4.sh` merangkum urutan regresi yang dijalankan, termasuk ekstraksi PDF sebelum browser Fase 4 (langkah ini sempat terlewat pada percobaan pertama). Gunakan Python dengan pypdf/pypdfium2. Suite menginjeksi kegagalan transaksi secara sengaja; jangan menafsirkan pesan generik audit sebagai kebocoran nilai.
6. Regresi V1–V3 memakai schema baseline terpisah `/private/tmp/v4-regression-schema.sql`, DB `webalhasan_v3_phase1_test`, flag integrasi per suite seperti pada `perintah-regresi-v1-v3.sh`. Path schema ini berasal dari harness audit sebelumnya, tidak dibundel sebagai dump baru. Auditor harus membuat schema uji bersihnya sendiri bila path tidak tersedia. Awal empat statis gagal (.env.example/validator), final semua statis diulang dengan DB_NAME=webalhasan_v3_phase1_test dan V3_RUN_TESTS=1; lihat statis-final.txt. Integrasi 14 suite tetap exit 0.

## Aplikasi, proyeksi dan privasi

```sh
# Dari salinan web yang sudah memiliki fixture Fase 1–4 dan env uji privat:
php tests/v4_phase5_seed.php
php tests/v4_phase5_projection.php
V4_HTTP_LOG=/private/tmp/v4p5-http.log V4_METRO_LOG=/private/tmp/v4p5-metro-isolated.txt php tests/v4_phase5_privacy.php
V4_APP_URL=http://127.0.0.1:8085 V4_IDS_FILE=/private/tmp/v4p5-fase4-ids.json node tests/browser/uji-v4-fase5-app.mjs
V4_APP_URL=http://127.0.0.1:8085 V4_IDS_FILE=/private/tmp/v4p5-fase4-ids.json node tests/browser/uji-v4-fase5-admin.mjs
```

Salin mobile ke /private/tmp/v4p5-mobile tanpa .env/.expo/native build, hubungkan node_modules yang sesuai lock. Jalankan `EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8965/api/v1 CI=1 npx expo start --clear --web --port 8085` dari salinan. Harness web menyediakan proxy transport lokal/CORS dan **memblokir origin API selain localhost**; otorisasi, mutasi dan PDF tetap dijalankan backend nyata. Gangguan jaringan dan 401 terakhir sengaja mock. Ini Expo web, bukan native Push.

Dari mobile fitur: `npx tsc --noEmit`, `EXPO_NO_DOTENV=1 npm run lint`, `node --experimental-strip-types --test tests/*.test.ts`. Hasil 21 lulus; warning MODULE_TYPELESS_PACKAGE_JSON Node tetap dicatat dan bukan kegagalan aplikasi. `npx expo export --platform ios --platform android --output-dir /private/tmp/v4p5-native-final` dari salinan env uji memvalidasi bundel, bukan IPA/APK/runtime.

Android: emulator Medium_Phone tanpa snapshot save; `expo run:android --no-bundler --no-install` membangun development client. `adb reverse tcp:8085 tcp:8085` dan `adb reverse tcp:8965 tcp:8965`, lalu intent development-client ke Metro localhost. UIAutomator/adb input dipakai untuk login akun sintetis, menu guru, logout, intent alhasanapps://rapor/ID sebelum login, wali dan dialog PDF. Credential hanya dibaca dari env privat, tidak dicetak. Hash dihitung dengan run-as sha256sum pada satu PDF cache yang diketahui, dibandingkan SELECT pdf_sha256 DB uji; sesudah Back, find cache -name v4-rapor-*.pdf kosong. Tidak memilih provider berbagi. iOS simctl dicoba dengan timeout 20 detik; gagal inventaris, bukan lulus.

## Pemulihan/Apache

Backup `mariadb-dump --single-transaction --routines --triggers` dan `tar -czf` storage pada saat penulis uji berhenti. Impor ke webalhasan_v4_fase5_restore dan ekstrak storage ke /private/tmp/v4p5-restore-web. Jalankan verifiers dari **checkout restore**. `tests/v4_phase5_restore.php` sengaja hanya menerima env testing, nama DB restore dan root restore-web; membandingkan baris V4, mengorupsi satu PDF salinan, menguji 503, lalu memulihkan byte tepercaya dalam finally. Latihan final diulang sesudah E2E final; backup pertama tetap dipertahankan. Hash arsip saja masuk Git.

Skema kosong di DB webalhasan_v4_fase5_empty memakai dump --no-data dan registry schema_migrations saja: preflight rollback022 → rollback → up → up. DB restore berisi data: preflight exit 1 sesuai harapan. Verifier tidak dilemahkan.

Apache lokal memakai document root sintetis dengan .htaccess repositori identik, AllowOverride All dan modul rewrite/authz/setenvif. Uji HTTP 13 path privat →403 serta ok.txt→200; lihat apache-lokal.txt. Ini tidak menguji PHP/FastCGI/cPanel. Worker lokal: --status, --kanal=push --uji-coba, --receipts --uji-coba, semuanya OFF. Tidak memasang cron.
