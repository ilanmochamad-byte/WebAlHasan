# Perintah dan penyiapan audit

Semua DB fixture sintetis lokal. Jalankan rantai DB **berurutan**, karena
skrip warisan memakai beberapa nama perantara tetap. Jangan arahkan ke data
pengguna atau produksi. Bukti implementator tidak ditimpa.

1. Awal: `php tests/penugasan_keamanan_f4_static.php`.
2. HTTP awal: `PKF3_DB=test_pk_f4audit_http_test bash tests/penugasan_keamanan_f4_db.sh`,
   lalu `PKF3_DB=test_pk_f4audit_http_test php tests/penugasan_keamanan_f4_http.php`.
3. Regresi: `OUT="$PWD/docs/penugasan-keamanan/fase-4/audit-codex/regresi" PFX=pkf4audit bash docs/penugasan-keamanan/fase-4/perintah-regresi.sh`.
4. Probe baru: salin DB HTTP yang sudah di-seed ke
   `test_pk_f4audit_probe_test` dengan `mysqldump --single-transaction`/`mysql`.
   Server kini: `DB_NAME=test_pk_f4audit_probe_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8967 tests/redesain_web_tahap1_router.php`.
   Server kontrol: perintah sama pada arsip `git archive 933f8a1` di
   `/private/tmp/pkf4audit-claude`, port 8968, konfigurasi DB lokal yang sama,
   vendor lokal. Jalankan probe secara **berurutan**:
   `DB_NAME=test_pk_f4audit_probe_test PKF4_WEB=http://127.0.0.1:8968 node tests/browser/uji-penugasan-keamanan-f4-api-probe.mjs`,
   kemudian ulangi dengan `PKF4_WEB=http://127.0.0.1:8967`.
   Jangan jalankan browser/native lain pada DB ini selama probe preservasi.
5. HTTP akhir: salin fixture `test_pkf4audit_f3m_test` sesudah suite migrasi
   ke `test_pk_f4audit_final_test`, kosongkan **hanya** penugasan Keamanan
   sintetis pada salinan baru (`DELETE FROM keamanan_assignments`) agar seed
   dapat dijalankan sekali; lalu
   `PKF3_DB=test_pk_f4audit_final_test PKF4_PORT=8969 php tests/penugasan_keamanan_f4_http.php`.
   Salinan sumber tidak diubah. Hasil 66/0, sama dengan uji awal.
6. Aplikasi: `git archive 10d13c3` mobile ke `/private/tmp/pkf4audit-mobile`;
   package/lock ter-commit, copy-on-write `node_modules` Expo 57.0.15 dari
   salinan handoff. Tanpa `.env.local` atau konfigurasi produksi.
   `EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8967/api/v1 CI=1 npx expo start --web --port 8097 --clear`.
   Expo web memakai `uji-penugasan-keamanan-f4-app.mjs` dengan
   `DB_NAME=test_pk_f4audit_probe_test`, `PKF4_WEB=http://127.0.0.1:8967`,
   `PKF4_APP=http://127.0.0.1:8097`, ID JSON dari DB seed yang sama, dan PNG di
   `/private/tmp/pkf4audit-screens`. ID fixture bukan token.
7. Di salinan aplikasi: `npx tsc --noEmit`, `npx expo lint`, dan
   `node --experimental-strip-types --test tests/*.test.ts`.
   Tsc dijalankan setelah Metro menghasilkan deklarasi Expo/CSS.
8. Android: AVD Medium_Phone, dev client terpasang,
   `adb reverse tcp:8967 tcp:8967`, `adb reverse tcp:8097 tcp:8097`, buka
   `exp+alhasanapps://expo-development-client/?url=http%3A%2F%2F127.0.0.1%3A8097`.
   Login fixture K, ubin Keamanan → izin Wali → rincian; periksa
   `dumpsys window windows` dan `screencap -p`. Kembali ke pintu → konseling
   Internal → rincian; cabut K di DB fixture → HOME → launcher → isi ditutup.
   Pulihkan penugasan di `finally`; tiga kali Back hingga Beranda, tunggu
   transisi dan periksa flag dilepas. Profil K; logout; login G; ubin dan dua
   identitas Profil. Hierarki UI lewat `uiautomator dump`, bukan OCR tebakan.
9. Dua probe Fase 2: dua salinan baru fixture Fase 3 migrasi, kosongkan hanya
   penugasan Keamanan sintetis; jalankan `penugasan_keamanan_f2_integration.php`
   pada masing-masing sebelum probe. Probe pertama memakai
   `PKF2_BASELINE=/private/tmp/pkf4-arsip-464d9a1`; probe kedua V4 memakai
   salinan lain sesudah integrasi. Hasil 24/0 dan 9/0.

Tidak ada migrasi, build distribusi atau perintah rilis produksi dalam audit ini.
