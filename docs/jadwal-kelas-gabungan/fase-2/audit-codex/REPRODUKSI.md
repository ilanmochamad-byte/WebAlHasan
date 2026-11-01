# Reproduksi audit Fase 2

Jalankan dari akar WebAlHasan, hanya dengan data sintetis. Jangan menjalankan rantai fixture bersamaan; setiap suite yang memutasi DB dimulai dari fixture baru. Audit memakai backend branch Fase 2 yang kode aplikasinya identik audit Fase 1, serta arsip mobile `586964c` dengan paket lock ter-commit. Checkout mobile pengguna mempunyai perubahan paket pekerjaan lain; jangan menggunakannya sebagai paket audit.

## Expo web dan probe auditor

1. `git -C /Users/ilanmochamad/alhasanApps archive 586964c | tar -x -C <salinan-mobile-kosong>`; pasang dependensi dari lock ter-commit atau salin direktori nyata dependensi dengan lock identik. Salinan tidak boleh memuat `.env`/`.env.local` produksi atau symlink `node_modules` ke luar root. `.env.example` boleh tetap ada.
2. `JKG_DB=test_jkg_f2_codex_test bash tests/jadwal_kelas_gabungan_f1_db.sh`, kemudian `JKG_DB=test_jkg_f2_codex_test php tests/jadwal_kelas_gabungan_f2_seed.php > <ids.json>`. Seed Fase 2 hanya sekali per fixture. Audit menyimpan snapshot privat **sebelum seed Fase 2**, lalu memulihkannya untuk putaran berikutnya; jangan menyimpan dump di Git.
3. Server API: `DB_NAME=test_jkg_f2_codex_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8961 tests/redesain_web_tahap1_router.php`.
4. Dari salinan mobile: `EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8961/api/v1 CI=1 node_modules/.bin/expo start --web --port 8098 --clear`. Tunggu server, bundel dan fixture siap sebelum pengujian. Expo membuat tipe CSS/env yang diperlukan `tsc --noEmit`.
5. Dari `tests/browser`: `DB_NAME=test_jkg_f2_codex_test JKG_WEB=http://127.0.0.1:8961 JKG_APP=http://127.0.0.1:8098 SEED_JSON=<ids.json> OUT_DIR=<folder> node uji-jadwal-kelas-gabungan-f2-app.mjs`.
6. Bangun ulang fixture/seed; jalankan variabel yang sama dengan `node uji-jadwal-kelas-gabungan-f2-audit.mjs`. Probe ini memodifikasi kelas lewat layanan resmi dan membuat expiry token lampau; jangan menjalankannya di DB suite lain yang sedang berjalan.
7. Kontrol satu kelas: fixture/seed baru + `JKG_KONTROL_SATU=1` pada suite asli. Kontrol baseline memakai arsip `b8d1825`, lock sama, server Expo port 8097 dan assertion asli yang sama. Gabungan baseline diharapkan gagal; satu kelas harus tetap lulus.

Unit: `node --experimental-strip-types --test tests/*.test.ts` di salinan mobile. TypeScript: `node_modules/.bin/tsc --noEmit`; lint: `EXPO_NO_DOTENV=1 node_modules/.bin/expo lint`.

Regresi aplikasi Keamanan: fixture Fase 1 baru, `PKF3_DB=test_jkg_f2_codex_test php tests/penugasan_keamanan_f3_seed.php > <ids-keamanan.json>`, lalu suite `tests/browser/uji-penugasan-keamanan-f4-app.mjs` dengan `DB_NAME`, `PKF4_WEB`, `PKF4_APP`, `SEED_JSON`, `OUT_DIR` sesuai server di atas. Jangan memakai seed Fase 2 sebelum seed Keamanan.

## Native

Android memakai emulator **Medium_Phone Android 16/API36**, dev client Debug yang sudah terpasang. Tidak membangun APK baru. Fixture/seed baru, proksi `python3 tests/android/jkg_f2_proksi.py 8962 8961 <folder-proksi>`, Metro native port 8099 dengan API `http://127.0.0.1:8962/api/v1`, `adb reverse` untuk 8099/8962, buka dev client dan tutup menu Continue. Jalankan `DB_NAME=test_jkg_f2_codex_test python3 tests/android/jkg_f2_android.py <ids.json> <folder-proksi> <folder-gambar>`. Lihat prasyarat pada skrip asli.

iOS menggunakan DB tersendiri `test_jkg_f2_ios_codex_test`, API 8963 dan proksi 8964. Ekspor bundel dari arsip sumber audit:

```sh
EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8964/api/v1 NODE_ENV=production \
  node_modules/.bin/expo export:embed --platform ios \
  --entry-file node_modules/expo-router/entry.js --bundle-output /private/tmp/main-ios.hbc \
  --assets-dest /private/tmp/ios-assets --dev false --bytecode --reset-cache
```

Verifikasi port final (8964 ada, 8962 dan host produksi tidak ada), kode/lock, hash executable dan resource lengkap. **Build baru crash; lihat F-1.** Kontrol audit hanya binary Release lama dengan bundel Hermes baru, resource lengkap dan tanda tangan ad-hoc. Jangan menyebutnya build Release baru atau distribusi.

Penggerak `uji-ios.py <ids.json> <folder-output-ios>` mengasumsikan UDID simulator yang dicatat di skrip, proksi pada folder saudara `proxy-ios`, aplikasi terpasang dengan API lokal dan fixture bersih. `IOS_SKIP_LOGIN=1` hanya untuk melanjutkan sesi login uji yang sudah diverifikasi. Koordinat tombol Buka pertemuan 50%,92% didasarkan pada hierarchy/tangkapan iPhone 17 Pro; judul panel memakai teks yang sama. Jangan memakai koordinat itu tanpa verifikasi pada perangkat/ukuran lain. Semua hasil native adalah emulator/simulator.

## Regresi backend

Pada DB fixture tersendiri, jalankan secara berurutan:

```sh
PHASE3_RUN_INTEGRATION=1 DB_NAME=test_jkg_f2_reg_codex_test php tests/phase3_integration.php
PHASE4_RUN_INTEGRATION=1 DB_NAME=test_jkg_f2_reg_codex_test php tests/phase4_integration.php
PHASE5_RUN_INTEGRATION=1 DB_NAME=test_jkg_f2_reg_codex_test php tests/phase5_integration.php
JKG_DB=test_jkg_f2_reg_codex_test php tests/jadwal_kelas_gabungan_f1_integration.php
```

**Pulihkan fixture bersih lagi** sebelum `JKG_DB=test_jkg_f2_reg_codex_test php tests/jadwal_kelas_gabungan_f1_http.php`. Statis Fase 1 dijalankan tanpa perubahan oracle; kegagalan batas berkas historis dicatat pada laporan.
