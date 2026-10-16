# Hasil pengujian Fase 1

Semua perintah DB berikut memakai `DB_HOST=localhost DB_USER=root DB_PASSWORD='' DB_NAME=webalhasan_v4_test` atau `webalhasan_v4_schema_test` dan `php -d mysqli.default_socket=/private/tmp/webalhasan-v4-fase1.sock`. Socket/database berada di `/private/tmp`, bukan server produksi. Nilai konfigurasi test ini tidak dipakai untuk rilis.

| Perintah | Hasil nyata |
| --- | --- |
| `php bin/v4_phase1_preflight.php /private/tmp/webalhasan-v4-schema-baseline.json` sebelum 020 | 13 tabel prasyarat dan migrasi 019 lulus; manifest tersimpan. |
| `php bin/migrate.php up` pada salinan schema | 020 diterapkan; ulang: “Tidak ada migrasi baru”. SQL 020 langsung ulang juga sukses. |
| `php bin/v4_phase1_verify.php /private/tmp/webalhasan-v4-schema-baseline.json` | Exit 0; 11 tabel; 0 yatim V4; jumlah semua tabel bisnis V1–V3 yang dimanifestasikan sama. Log lengkap `verifier-schema-uji.txt`. |
| `php bin/v4_phase1_rollback_preflight.php`; `php bin/migrate.php rollback`; `php bin/migrate.php up` | Lulus ketika semua tabel V4 kosong. Dengan satu baris V4, preflight exit 1, rollback exit 1, baris dan catatan migrasi tetap. |
| `php tests/v4_phase1_integration.php` | Lulus 18 pemeriksaan pada DB uji; lihat `integrasi-mariadb.txt`. |
| `node tests/browser/uji-v4-fase1.mjs` | Lulus 17 pemeriksaan API/browser Chromium termasuk 375 px dan teks lebih besar; lihat `http-browser-375.txt` dan gambar. |
| `node tests/browser/uji-v4-penanda.mjs` | Lulus unggah privat, pilih aset aktif, nonaktifkan, penolakan penggunaan ulang. |
| `php tests/penugasan_static.php`; `php tests/v2_phase5_static.php` | Keduanya exit 0, log tersimpan. |
| `php -l` pada semua PHP yang berubah; `git diff --check` | Lulus. |
| `php tests/phase5_static.php` | Exit 1 pada path laporan mobile lama yang telah dipindah oleh redesain; rincian `risiko-terbuka.md`. |
| `php tests/v3_phase2_static.php` | Exit 77: DB fixture V3 yang diwajibkan test tidak tersedia dalam nama yang dijaga. |

Uji HTTP memakai server PHP lokal dengan router uji yang menolak akses langsung ke `/storage`; di hosting Apache, `.htaccess` repositori dan `storage/private/.htaccess` menolak path tersebut. Tidak ada pembuktian lewat hosting produksi.

## Reproduksi pada DB uji kosong

1. Buat database bernama `*_test` pada MariaDB/MySQL uji, impor `k1807225_webalhasan.sql`, lalu terapkan migrasi 001–019. Pada sesi ini, migrator diarahkan sementara ke direktori symlink yang hanya berisi 001–019; keluaran: **19 migrasi dasar diterapkan**.
2. Set variabel koneksi `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, `APP_ENV=testing`, dan `API_TOKEN_HASH_SECRET` untuk lingkungan uji. Jalankan preflight sebelum 020:

   ```sh
   php bin/v4_phase1_preflight.php /private/tmp/v4-baseline.json
   php bin/migrate.php up
   php bin/migrate.php up
   php bin/v4_phase1_verify.php /private/tmp/v4-baseline.json
   ```

3. Sesudah verifier, siapkan fixture sintetis dan jalankan layanan:

   ```sh
   # V4_TEST_PASSWORD diisi password sementara khusus uji, minimal 12 karakter.
   php tests/v4_phase1_seed.php /private/tmp/v4-http-tokens.json
   php tests/v4_phase1_integration.php
   ```

4. Jalankan server lokal dengan DB uji dan router yang memblokir storage, lalu uji browser/API. `V4_HTTP_TOKENS` menunjuk file token privat dari langkah 3; `V4_TEST_PASSWORD` sama dengan nilai langkah 3.

   ```sh
   php -S 127.0.0.1:8961 -t . tests/v4_phase1_router.php
   node tests/browser/uji-v4-fase1.mjs
   node tests/browser/uji-v4-penanda.mjs
   ```

Pada macOS sesi ini, semua perintah PHP DB memakai `-d mysqli.default_socket=/private/tmp/webalhasan-v4-fase1.sock`. File token uji tetap di `/private/tmp` dengan mode 0600; jangan masukkan ke commit. `SHA256SUMS` berisi hash berkas implementasi/bukti untuk audit.
