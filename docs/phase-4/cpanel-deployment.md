# Pemasangan Fase 4 di cPanel

Dokumen ini hanya mencakup Fase 4. Jangan menjalankan rollback atau menghapus tabel
lama di produksi.

## Informasi yang diperlukan

- URL cPanel dan akun yang berhak membuka File Manager, Database, serta Terminal
  (atau phpMyAdmin bila Terminal tidak tersedia).
- domain HTTPS dan document root website.
- versi PHP, versi MySQL/MariaDB, serta ekstensi `mysqli` dan `mbstring`.
- nama database produksi dan kredensial yang saat ini dipakai website.
- satu database salinan khusus dengan nama berakhiran `_test` untuk pengujian integrasi.
- akun guru uji aktif, akun guru uji nonaktif, dan dua jadwal uji milik guru berbeda.

Jangan memasukkan kredensial atau secret ke repository, chat, tangkapan layar, maupun
berkas yang dapat diunduh publik.

## Urutan pemasangan

1. Aktifkan mode pemeliharaan atau pilih jendela perubahan yang disepakati.
2. Jalankan `php bin/preflight.php` terhadap database produksi. Simpan `database.sql`,
   `manifest.json`, dan `report.md` di lokasi yang tidak dapat diakses web.
3. Pastikan status migrasi 001–003 sesuai keadaan database dengan
   `php bin/migrate.php status`.
4. Unggah berkas Fase 4 tanpa menimpa `.env` produksi dan tanpa mengunggah direktori
   hasil build atau dependency mobile.
5. Tambahkan konfigurasi berikut ke environment server:

   ```dotenv
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://DOMAIN-HTTPS
   APP_BASE_PATH=
   API_TOKEN_HASH_SECRET=SECRET-ACAK-MINIMAL-32-BYTE
   API_TOKEN_TTL_DAYS=30
   ```

   `API_TOKEN_HASH_SECRET` harus dibuat langsung di lingkungan hosting. Jangan memakai
   nilai contoh dan jangan mengubahnya setelah token produksi diterbitkan, karena token
   lama akan menjadi tidak valid.

6. Terapkan hanya migrasi yang masih menunggu dengan `php bin/migrate.php up`.
   Bila Terminal tidak tersedia, impor
   `database/migrations/004_phase4_api_attendance.sql` melalui phpMyAdmin, lalu catat
   migrasinya pada `schema_migrations` hanya setelah seluruh perintah berhasil.
7. Pastikan tabel `absensi_guru`, `absensi_santri`, dan `api_idempotency_keys` ada,
   seluruhnya memakai InnoDB, dan constraint uniknya aktif.
8. Pada database salinan `_test`, gunakan konfigurasi terpisah lalu jalankan:

   ```sh
   PHASE4_RUN_INTEGRATION=1 php tests/phase4_integration.php
   ```

9. Lakukan smoke test HTTPS untuk login aktif, password salah, akun nonaktif,
   kepemilikan jadwal, simpan dan baca ulang absensi, retry idempoten, rollback
   transaksi, serta token setelah logout.
10. Isi konfigurasi build aplikasi tanpa menulis URL produksi di kode:

    ```dotenv
    EXPO_PUBLIC_API_BASE_URL=https://DOMAIN-HTTPS/api/v1
    ```

11. Buat build aplikasi dari environment produksi, pasang pada perangkat uji, lalu
    ulangi alur login sampai absensi tersimpan.

## Pemulihan

- Bila migrasi belum dimulai, batalkan perubahan dan kembalikan berkas aplikasi dari
  salinan sebelum pemasangan.
- Bila migrasi berhasil tetapi API gagal, pertahankan tabel aditif Fase 4 dan kembalikan
  berkas PHP ke versi sebelumnya. Struktur lama tidak terganggu.
- Jangan menjalankan `database/rollbacks/004_phase4_api_attendance.sql` di produksi
  tanpa persetujuan eksplisit dan backup terverifikasi; rollback tersebut menghapus data
  absensi Fase 4.

## Catatan pelaksanaan 19 Agustus 2026

- Hosting memakai PHP 8.3.32, MariaDB 10.6.27, dan document root `/public_html`.
- Secret hash token dibuat langsung di server, tidak pernah ditampilkan, dan `.env`
  dibatasi ke permission `0600`.
- Database uji `k1807225_webalhasan_test` dibuat dari salinan produksi dan digunakan
  untuk migrasi, restore, serta pengujian integrasi.
- Migrasi 004 diterapkan ke produksi setelah seluruh pengujian pada database uji lulus.
- Backup terverifikasi tersimpan di
  `storage/backups/20260819_230000_fixed`; `database.sql` berhasil dipulihkan dan seluruh
  jumlah baris cocok dengan `manifest.json`.
- Backup native pra-migrasi tetap tersedia di
  `storage/backups/20260819_222851/database.native.sql` sebagai lapisan pemulihan tambahan.
