# V2 Fase 5 — Deployment cPanel, Environment, dan Cron

> **Sudah dijalankan pada produksi 28–29 Agustus 2026.** Dokumen ini tetap
> menjadi prosedur pengulangan. Hasil aktual—backup 47 tabel, restore `_test`,
> verifikasi 22/22, cron 6/6, push nyata, dan receipt 3/3 Terkirim—dicatat pada
> `penutupan-fase5.md`.

## 1. Urutan rilis

Jangan melompati langkah. Setiap langkah menghasilkan bukti yang dipakai
langkah berikutnya.

| # | Langkah | Perintah | Gagal? |
| --- | --- | --- | --- |
| 1 | Preflight (backup + manifest + konflik) | `php bin/v2_phase5_preflight.php` | keluar 3 → perbaiki konflik, **jangan** lanjut |
| 2 | Uji restore pada salinan `_test` | lihat `backup-restore-dan-manifest.md` §2 | tidak cocok → **jangan** lanjut |
| 3 | Uji migrasi pada salinan `_test` | `php bin/migrate.php up` (DB_NAME = salinan) | gagal → perbaiki lebih dulu |
| 4 | Verifikasi salinan | `php bin/v2_phase5_verify.php <manifest>` | keluar 3 → **jangan** lanjut |
| 5 | Aktifkan mode pemeliharaan singkat | opsional, lihat §6 | — |
| 6 | Migrasi produksi | `php bin/migrate.php up` | gagal → `migration-and-rollback.md` §5 |
| 7 | Verifikasi produksi | `php bin/v2_phase5_verify.php <manifest>` | keluar 3 → rollback |
| 8 | Smoke test produksi | §5 | gagal → rollback |
| 9 | Pasang/aktifkan cron | §4 | — |
| 10 | Pemeriksaan kesehatan cron | `php bin/v2_phase5_cron_check.php` | keluar 1 → §4.3 |

## 2. Berkas yang diunggah

Fase 5 menambahkan berkas berikut. Seluruhnya aditif; tidak ada berkas V1 yang
dihapus atau diganti kontraknya.

```
app/Report/IzinReportFilter.php
app/Report/IzinReportRepository.php
app/Report/IzinReportService.php
app/Report/IzinCsvExport.php
app/Report/IzinPrintRenderer.php
app/Notification/Push/PushReceiptClient.php
portal/laporan.php
portal/laporan_cetak.php
portal/laporan_csv.php
bin/v2_phase5_preflight.php
bin/v2_phase5_verify.php
bin/v2_phase5_cron_check.php
bin/v2_phase5_fixture.php                 (JANGAN diunggah ke produksi)
bin/v2_phase5_ukur_laporan.php            (JANGAN diunggah ke produksi)
bin/v2_phase5_backup_restore_drill.php    (JANGAN diunggah ke produksi)
database/migrations/009_v2_phase5_laporan_dan_push_receipt.sql
database/rollbacks/009_v2_phase5_laporan_dan_push_receipt.sql
```

Berkas yang **diubah**: `app/bootstrap.php`, `api/v1/index.php`,
`portal/_ui.php`, `app/Notification/Push/ExpoPushClient.php`,
`app/Notification/NotificationDispatcher.php`,
`app/Notification/OutboxRepository.php`, `bin/notifikasi_worker.php`.

Tiga skrip bertanda "JANGAN diunggah" menolak berjalan di luar database `_test`
dan menolak `APP_ENV=production`, tetapi lebih aman tidak menaruhnya di server
produksi sama sekali. Berkas `tests/` juga tidak perlu diunggah.

Pastikan `bin/` dan `app/` **tidak** dapat diakses lewat web. Bila struktur
hosting menempatkannya di bawah `public_html`, lindungi dengan `.htaccess`:

```apache
<FilesMatch "\.php$">
    Require all denied
</FilesMatch>
```

## 3. Environment

Fase 5 **tidak menambah satu pun variabel environment baru.** Yang sudah ada
tetap berlaku:

| Variabel | Wajib | Dipakai untuk |
| --- | --- | --- |
| `APP_ENV` | ya | `production` menolak adapter uji WhatsApp dan seluruh perkakas fixture |
| `APP_DEBUG` | ya | **wajib `false`** pada produksi; bila `true`, pesan galat internal akan tampil kepada pengguna |
| `APP_TIMEZONE` | ya | Zona waktu laporan, median durasi, dan stempel waktu cetak |
| `DB_*` | ya | Koneksi basis data |
| `API_TOKEN_HASH_SECRET` | ya | Autentikasi API; preflight memblokir bila kosong |
| `PUSH_TOKEN_KEY` | untuk push | Perlindungan token perangkat. **Bila hilang atau diganti, token lama tidak dapat dibuka** dan worker akan mencabutnya; aplikasi mendaftar ulang. |
| `EXPO_ACCESS_TOKEN` | opsional | Dipakai bila proyek Expo mewajibkan autentikasi push |
| `PUSH_TIMEOUT_SECONDS` | opsional | Batas waktu permintaan ke Expo (default 10) |
| `NOTIFIKASI_WORKER_BATCH` | opsional | Ukuran batch worker (default 25) |
| `WHATSAPP_*` | **jangan diisi** | WhatsApp DITANGGUHKAN; biarkan kosong |

Zona waktu penting untuk laporan: median durasi keputusan dan filter
`basis_tanggal=keputusan` memakai `DATE()` pada kolom `DATETIME`. Pastikan
`APP_TIMEZONE` sama dengan zona waktu yang dipakai server basis data.

## 4. Cron

### 4.1 Baris cron

Salin ke cPanel → **Cron Jobs**. Ganti `AKUN` dan jalur PHP sesuai hosting.
`bin/v2_phase5_cron_check.php` mencetak baris ini dengan jalur yang sudah
terisi otomatis.

```cron
# Setiap menit — memproses antrean push.
* * * * * /usr/local/bin/php /home/AKUN/public_html/bin/notifikasi_worker.php --kanal=push >> /home/AKUN/logs/notifikasi_worker.log 2>&1

# Setiap 15 menit — mengambil receipt AKHIR dari Expo/FCM/APNs (V2 Fase 5).
*/15 * * * * /usr/local/bin/php /home/AKUN/public_html/bin/notifikasi_worker.php --receipts >> /home/AKUN/logs/notifikasi_receipt.log 2>&1

# Setiap jam — pemeriksaan kesehatan cron; keluarannya dikirim ke email admin.
0 * * * * /usr/local/bin/php /home/AKUN/public_html/bin/v2_phase5_cron_check.php
```

Pada akun produksi `k1807225`, jalur PHP yang terbukti benar adalah
`/opt/alt/php83/usr/bin/php` dan root aplikasi adalah
`/DATA/k1807225/public_html`. Bentuk `/opt/alt/php83/usr/local/bin/php` serta
jalur tanpa garis miring awal terbukti salah dan tidak boleh dipakai.

Mengapa receipt setiap 15 menit dan bukan setiap menit: Expo baru menyediakan
receipt setelah penyedia menjawab. Worker sengaja hanya meminta receipt untuk
tiket yang berumur **minimal 15 menit** (`RECEIPT_TUNGGU_DETIK`), sehingga
meminta lebih sering hanya menambah trafik tanpa hasil.

### 4.2 Sifat yang membuat cron aman

- **Aman diulang.** Dua cron yang tumpang tindih tidak pernah mengirim baris
  yang sama dua kali: ada sewa proses (`notifikasi_worker_lock`) **dan** klaim
  per baris dengan pemilik + masa berlaku. Diuji pada
  `tests/v2_phase4_concurrency.php` (12 baris → 12 pesan, 0 ganda).
- **Berhenti diam-diam saat kanal mati.** Tidak ada permintaan ke penyedia sama
  sekali, dan cron tetap keluar dengan status 0 agar tidak membanjiri email.
- **Kegagalan pengiriman bukan kegagalan cron.** Baris dicatat dan dicoba ulang
  sesuai backoff (maks. 5 percobaan, 60 s → 3.600 s).
- **Rekonsiliasi receipt tidak pernah mengirim ulang.** Receipt `Gagal` adalah
  informasi operasional, bukan pemicu retry — mengirim ulang akan menghasilkan
  notifikasi ganda di perangkat penerima.

### 4.3 Bila pemeriksaan kesehatan melaporkan masalah

`php bin/v2_phase5_cron_check.php` keluar dengan kode 1 bila menemukan indikasi
cron tidak berjalan. Yang diperiksanya:

| Gejala | Arti | Tindakan |
| --- | --- | --- |
| Baris push tertahan > 15 menit | Cron tidak berjalan atau gagal | Periksa log cron, jalur PHP, dan izin berkas |
| Tidak ada jejak sewa worker sama sekali | Cron belum pernah berjalan | Periksa apakah baris cron benar-benar tersimpan |
| Receipt tertahan > 6 jam | Cron `--receipts` belum dipasang | Tambahkan baris cron kedua pada §4.1 |
| `PUSH_TOKEN_KEY` belum siap | Environment belum diisi | Isi lalu muat ulang |
| WhatsApp menyala | Melanggar keputusan produk | Matikan segera lewat panel admin |

Skrip ini **hanya membaca** dan aman dijalankan pada produksi kapan saja.

## 5. Smoke test produksi

Jalankan sebagai manusia setelah migrasi, sebelum mengumumkan rilis.
Centang dan simpan hasilnya.

| # | Langkah | Harapan |
| --- | --- | --- |
| 1 | Masuk sebagai admin | Berhasil, menu **Laporan** muncul |
| 2 | Buka `portal/laporan.php` | Ringkasan, median durasi, dan daftar tampil tanpa galat |
| 3 | Terapkan filter status + rentang tanggal | Total ringkasan berubah dan sama dengan jumlah baris detail |
| 4 | Klik **Cetak / PDF** | Halaman cetak memuat identitas pesantren, filter, pembuat, waktu, dan nomor halaman |
| 5 | Klik **Unduh CSV** | Berkas terunduh; jumlah barisnya sama dengan total ringkasan |
| 6 | Buka CSV di Excel | Tidak ada sel yang dieksekusi sebagai formula |
| 7 | Masuk sebagai pengurus | Hanya melihat pengajuan miliknya |
| 8 | Masuk sebagai murobi | Hanya melihat pengajuan yang diarahkan kepadanya |
| 9 | Masuk sebagai orang tua | Hanya melihat santri yang terhubung; tidak ada tombol mutasi |
| 10 | Ubah `pengurus_id` pada URL sebagai pengurus | **403**, bukan data orang lain |
| 11 | Buka laporan absensi V1 (`admin/admin_laporan_absensi.php`) | Tetap berfungsi seperti sebelumnya |
| 12 | Buka aplikasi guru, ambil absensi | Tetap berfungsi (regresi V1) |
| 13 | `php bin/v2_phase5_cron_check.php` | Keluar 0 |
| 14 | `php bin/notifikasi_worker.php --status` | Menampilkan antrean dan sebaran receipt |

## 6. Mode pemeliharaan (opsional)

Migrasi 009 singkat, tetapi bila ingin aman:

1. Aktifkan halaman pemeliharaan lewat `.htaccess` (kecualikan IP admin).
2. Nonaktifkan sementara baris cron worker.
3. Jalankan migrasi dan verifikasi.
4. Aktifkan kembali cron.
5. Matikan halaman pemeliharaan.

## 7. Feature flag WhatsApp

| Aturan | Status |
| --- | --- |
| Default | **OFF** |
| Dapat dinyalakan? | Hanya setelah pemeriksaan konfigurasi berstatus `Lulus` (ditegakkan `CHECK` pada migrasi 006 **dan** lapisan aplikasi) |
| Boleh dinyalakan sekarang? | **TIDAK.** DITANGGUHKAN oleh keputusan produk 26 Agustus 2026 |
| Penjaga rilis | `bin/v2_phase5_preflight.php` dan `bin/v2_phase5_verify.php` **memblokir** bila menyala; `bin/v2_phase5_cron_check.php` melaporkannya sebagai masalah |
| Syarat aktivasi masa depan | Seluruh tujuh syarat pada `../phase-v2-4/whatsapp-provider-checklist.md` — tanpa pengecualian |

Jangan memilih provider, memasukkan credential, mengirim request nyata, atau
mengaktifkannya di produksi tanpa persetujuan tertulis pemilik produk.
