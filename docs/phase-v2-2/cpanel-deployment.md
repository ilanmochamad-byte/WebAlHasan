# Pemasangan V2 Fase 2 di cPanel

Dokumen ini hanya mencakup Fase 2 (pengajuan, routing, keputusan web).
Fase 3 (API/mobile) dan Fase 4 (notifikasi) belum termasuk.

> **Jangan** menjalankan migrasi destruktif, rollback, atau penghapusan tabel lama
> di produksi. Tabel `perizinan` V1 tetap dipertahankan.

## 0. Prasyarat

- V2 Fase 1 (migrasi `006`) sudah terpasang dan terverifikasi di produksi.
- Akses cPanel: File Manager, MySQL Databases, dan Terminal (atau phpMyAdmin).
- PHP ≥ 8.1 dengan ekstensi `mysqli` dan `mbstring`.
- Satu database salinan berakhiran `_test` untuk pengujian.
- Akun uji: satu pengurus (dengan penugasan pembimbing aktif), satu guru dengan
  penugasan murobi aktif, satu admin, satu orang tua dengan relasi wali aktif.
- Jendela perubahan yang disepakati.

Jangan memasukkan kredensial atau secret ke repository, chat, tangkapan layar,
maupun berkas yang dapat diunduh publik.

## 1. Kesiapan data sebelum rilis

Alur Fase 2 bergantung pada master data yang sudah benar. Periksa lebih dulu lewat
panel admin:

| Yang diperiksa | Menu | Bila kosong |
|---|---|---|
| Tepat satu tahun ajaran berstatus `Aktif` | Tahun Ajaran | routing tidak menemukan kandidat |
| Penugasan murobi aktif untuk kamar/kelas yang dipakai | Penugasan Murobi | semua pengajuan masuk antrean admin |
| Penugasan pembimbing aktif untuk tiap pengurus | Penugasan Pembimbing | pengurus tidak dapat mengajukan apa pun |
| Akun pengurus dan orang tua terhubung ke master | Akun Pengurus & Orang Tua | peran tidak memperoleh kemampuan |

`php bin/v2_phase2_preflight.php` melaporkan keempat angka ini secara otomatis.

## 2. Uji pada salinan `_test` terlebih dahulu (wajib)

```bash
# .env terpisah yang menunjuk database *_test
php bin/v2_phase2_preflight.php
php bin/migrate.php up
php bin/v2_phase2_verify.php storage/backups/v2-phase2/<timestamp>/manifest.json

php tests/v2_phase2_static.php
V2_PHASE2_RUN_INTEGRATION=1 php tests/v2_phase2_integration.php
V2_PHASE2_RUN_WEB=1        php tests/v2_phase2_web_smoke.php
V2_PHASE2_RUN_NAV=1        php tests/v2_phase2_navigasi_murobi.php
V2_PHASE1_RUN_INTEGRATION=1 php tests/v2_phase1_integration.php
PHASE4_RUN_INTEGRATION=1 php tests/phase4_integration.php
PHASE5_RUN_INTEGRATION=1 php tests/phase5_integration.php
```

Lanjutkan ke produksi hanya bila seluruhnya lulus.

## 3. Urutan pemasangan produksi

1. Aktifkan mode pemeliharaan atau pilih jendela perubahan.
2. **Backup produksi:**
   ```bash
   php bin/v2_phase2_preflight.php
   ```
   Simpan `database.sql`, `manifest.json`, `conflicts.json` di lokasi yang **tidak
   dapat diakses web**. Keluar dengan kode `3` berarti ada konflik memblokir —
   hentikan dan selesaikan dahulu.
3. Unggah berkas Fase 2 **tanpa menimpa `.env` produksi**:

   | Baru | Diubah |
   |---|---|
   | `app/Izin/IzinRouter.php` | `app/bootstrap.php` |
   | `app/Izin/IzinIdempotency.php` | `app/Izin/IzinException.php` |
   | `app/Izin/IzinWriteRepository.php` | `app/Izin/IzinRepository.php` |
   | `app/Izin/IzinWorkflowService.php` | `app/Izin/IzinService.php` |
   | `portal/izin_buat.php` | `portal/_ui.php` |
   | `portal/izin_aksi.php` | `portal/index.php` |
   | `portal/izin_antrean.php` | `portal/izin.php` |
   | `bin/v2_phase2_preflight.php` | `portal/izin_detail.php` |
   | `bin/v2_phase2_verify.php` | `admin/admin_izin.php` |
   | `database/migrations/007_…sql` | `admin/sidebar.php` |
   | `database/rollbacks/007_…sql` | `.env.example` |
   | `tests/v2_phase2_*.php` | |
   | `app/Auth/LandingRouter.php` | `admin/cek_login.php` |
   | | `admin/admin_login.php` |
   | | `admin/ubah_password.php` |
   | | `admin/pertemuan_pengajian.php` |

   Empat berkas `admin/*` terakhir dan `app/Auth/LandingRouter.php` berasal dari
   hotfix navigasi murobi; keduanya tidak menyentuh basis data sehingga dapat
   diunggah tanpa migrasi tambahan.

   Direktori `tests/` dan `bin/` sebaiknya tidak dapat diakses dari web (skrip
   `bin/` sudah menolak akses non-CLI, tetapi pembatasan `.htaccess` tetap dianjurkan).
4. Tambahkan satu baris berikut ke `.env` produksi (nilai bawaan aman):
   ```dotenv
   IZIN_LEGACY_ENABLED=false
   ```
   Tanpa baris ini, nilainya tetap dianggap `false`.
5. Terapkan migrasi yang masih menunggu:
   ```bash
   php bin/migrate.php status
   php bin/migrate.php up
   ```
   Bila Terminal tidak tersedia, impor
   `database/migrations/007_v2_phase2_pengajuan_routing_keputusan.sql` lewat
   phpMyAdmin, lalu catat namanya pada `schema_migrations` **hanya setelah** seluruh
   perintah berhasil. Berkasnya idempoten sehingga aman diimpor ulang bila terputus.
6. Verifikasi:
   ```bash
   php bin/v2_phase2_verify.php /path/manifest.json
   ```
   Seluruh baris harus `[sesuai]`.
7. Nonaktifkan mode pemeliharaan.

## 4. Uji asap pasca-deploy (dikerjakan manusia)

| # | Langkah | Harapan |
|---:|---|---|
| 1 | Login admin | mendarat di `admin_dashboard.php` seperti sebelumnya |
| 2 | Buka `admin/admin_izin.php` | dialihkan ke `/portal/izin.php` |
| 3 | Buka tautan lama `admin/admin_izin.php?id=<id lama>` | dialihkan ke detail pengajuan dengan ID sama |
| 4 | Login pengurus → **Buat Pengajuan** | hanya santri binaannya yang tampil |
| 5 | Kirim pengajuan | tersimpan; pesan menyebut hasil routing |
| 6 | Klik **Kirim** dua kali / refresh POST | tetap satu pengajuan |
| 7 | Kirim pengajuan dengan tanggal kembali lebih awal | ditolak **422** dengan pesan jelas |
| 8 | Kirim pengajuan tumpang tindih | ditolak **409** dengan nomor pengajuan bentrok |
| 9 | Login murobi tujuan | **langsung mendarat di antrean keputusan** (`/portal/izin_antrean.php?mode=murobi`); pengajuan muncul dan tombol keputusan tersedia |
| 9b | Dari antrean klik **Jadwal Mengajar** | halaman jadwal terbuka tanpa login ulang |
| 9c | Dari jadwal klik **Antrean Perizinan** | kembali ke antrean tanpa login ulang |
| 9d | Login guru **tanpa** penugasan murobi | mendarat di jadwal mengajar; tidak ada tombol Antrean Perizinan; mengetik URL portal tetap **403** |
| 10 | Setujui dengan alasan | status berubah; riwayat bertambah |
| 11 | Coba putuskan lagi | ditolak **409** |
| 12 | Login murobi lain, buka ID pengajuan tadi | **403** |
| 13 | Login admin → **Antrean Penetapan Admin** | pengajuan tanpa routing tunggal muncul |
| 14 | Tetapkan murobi tanpa alasan | ditolak **422** |
| 15 | Tetapkan murobi dengan alasan | masuk antrean murobi tersebut |
| 16 | Putuskan sebagai admin tanpa alasan penggantian | ditolak **422** |
| 17 | Putuskan sebagai admin dengan alasan penggantian | tersimpan, kapasitas `Admin Pengganti` |
| 18 | Koreksi keputusan sebagai admin | keputusan lama + riwayat tetap terlihat |
| 19 | Login orang tua | hanya izin anaknya, tanpa tombol mutasi |
| 20 | Login orang tua lain, buka ID pengajuan tadi | **403** |
| 21 | Regresi V1: absensi, jadwal, laporan, cetak, aplikasi guru | tidak berubah |

## 5. Rollback darurat

1. Kembalikan berkas aplikasi ke commit sebelum Fase 2.
2. `php bin/migrate.php rollback` (melepas 007 saja).
3. Bila perlu, pulihkan `database.sql` dari preflight — **hanya** setelah
   diverifikasi di salinan `_test` dan disetujui pemilik sistem.

Rincian: `docs/phase-v2-2/migration-and-rollback.md`.

## 6. Catatan modul lama

`admin/admin_izin.php` **tidak dihapus**. Ia mengalihkan ke portal baru dan hanya
dapat dibuka kembali dengan `IZIN_LEGACY_ENABLED=true` **dan** parameter `?legacy=1`.
Gunakan hanya untuk pemulihan darurat, lalu matikan lagi: penulisan lewat modul lama
tidak melewati routing, keputusan, riwayat, maupun audit V2.
