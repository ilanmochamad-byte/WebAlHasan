# Hasil pengujian: Penempatan Kelas & Kamar Santri

Keputusan pengguna 6 September 2026. Branch `feat/penempatan-santri`,
baseline `main` `3b53c1c`.

Karena pengguna memilih **tidak** memakai audit Codex untuk pekerjaan ini,
seluruh pengujian di bawah adalah gerbang sebelum push dan dijalankan penuh oleh
implementer.

---

## 1. Lingkungan pengujian

| Butir | Nilai |
|-------|-------|
| PHP | 8.4.21 (CLI) |
| Basis data | MariaDB 10.11.14, database `webalhasan_test` |
| Migrasi | 001–010 diterapkan; **paket ini tidak menambah migrasi** |
| Fixture peran | `V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php` |
| Fixture performa | `V2_PHASE5_FIXTURE=1 php bin/v2_phase5_fixture.php --jumlah=1000` |
| Fixture browser | `PENEMPATAN_SEED=1 php tests/browser/seed-penempatan.php` |
| Node | v22 |
| Peramban | Chromium (Playwright), `CHROMIUM_PATH` diarahkan ke Chromium lokal |
| Sumber mobile | `MOBILE_APP_ROOT` menunjuk ke salinan repo `alhasanApps` |

Seluruh pengujian menolak berjalan bila `DB_NAME` tidak berakhiran `_test`.
Data uji fiktif; tidak ada data produksi dan tidak ada permintaan jaringan keluar.

## 2. Cara menjalankan ulang

```bash
# Seluruh pengujian otomatis: regresi V1/V2 + perapihan + kredensial + paket ini
MOBILE_APP_ROOT=/path/ke/alhasanApps \
CHROMIUM_PATH=/path/ke/chromium \
bash bin/penempatan_run_all_tests.sh

# Hanya paket ini
php tests/penempatan_static.php
PENEMPATAN_RUN_INTEGRATION=1 php tests/penempatan_integration.php
PENEMPATAN_RUN_CONCURRENCY=1 php tests/penempatan_concurrency.php
PENEMPATAN_RUN_WEB=1        php tests/penempatan_web_smoke.php

# Laporan konflik data (hanya membaca)
php bin/penempatan_preflight.php

# Uji browser 1440 / 768 / 390 px (dijalankan terpisah)
PENEMPATAN_SEED=1 php tests/browser/seed-penempatan.php
php -S 127.0.0.1:8942 -t . &
BASE_URL=http://127.0.0.1:8942 CHROMIUM_PATH=/path/ke/chromium \
  node tests/browser/uji-penempatan.mjs
```

## 3. Ringkasan hasil

| Rangkaian | Berkas | Pemeriksaan lulus | Gagal |
|-----------|--------|-------------------|-------|
| A. Regresi V1 & V2 | 29 | **2.465** | 0 |
| B. Paket perapihan UI V1–V2 | 4 | **248** | 0 |
| C. Fitur pesan kredensial | 3 | **215** | 0 |
| D. Paket penempatan (baru) | 4 | **257** | 0 |
| E. Uji browser 1440/768/390 px (baru) | 1 | **113** | 0 |
| **Total** | **41** | **3.298** | **0** |

Rincian rangkaian D:

| Berkas | Lulus |
|--------|-------|
| `tests/penempatan_static.php` | 138 |
| `tests/penempatan_integration.php` | 62 |
| `tests/penempatan_concurrency.php` | 10 |
| `tests/penempatan_web_smoke.php` | 47 |

### Perbandingan dengan baseline

Rangkaian yang sama dijalankan pada baseline `main` `3b53c1c` di lingkungan yang
persis sama:

| Rangkaian | Baseline `3b53c1c` | Branch `feat/penempatan-santri` |
|-----------|--------------------|---------------------------------|
| Regresi V1 & V2 | 2.464 lulus, 0 gagal | 2.465 lulus, 0 gagal |
| Paket perapihan | 248 lulus, 0 gagal | 248 lulus, 0 gagal |
| Pesan kredensial | 215 lulus, 0 gagal | 215 lulus, 0 gagal |

Selisih **+1** pada regresi berasal dari `tests/phase1_static.php`, yang memindai
seluruh halaman admin dan kini menambahkan satu pemeriksaan:
`[lulus] admin_penempatan_santri.php memakai guard role admin`. Tidak ada
pemeriksaan lama yang berubah hasilnya.

`bin/penempatan_run_all_tests.sh` selesai dengan kode keluar **0**
("SELURUH PENGUJIAN OTOMATIS LULUS"), dan `bin/penempatan_preflight.php`
melaporkan **tidak ada konflik data** pada basis data uji.

## 4. Lint

`php -l` dijalankan atas seluruh berkas yang dibuat dan diubah sebagai bagian
`tests/penempatan_static.php` (PS-1): 17 berkas, seluruhnya bersih.

## 5. Skenario minimum yang diminta pengguna

| Skenario | Bukti | Hasil |
|----------|-------|-------|
| Tempatkan satu santri ke kelas | PN-1 | LULUS |
| Pindahkan santri ke kelas lain | PN-2 | LULUS |
| Akhiri penempatan kelas | PN-3 | LULUS |
| Tempatkan satu santri ke kamar | PN-4 | LULUS |
| Tempatkan kembali ke kamar yang sama | PN-5 | LULUS (idempoten, tanpa baris baru) |
| Pindahkan ke kamar lain | PN-6 | LULUS (ID penempatan dipertahankan) |
| Keluarkan dari kamar | PN-7 | LULUS (alasan wajib, tercatat pada audit) |
| Tempatkan beberapa santri sekaligus | PN-8 | LULUS |
| Tolak operasi massal saat sisa kapasitas tidak cukup | PN-9 | LULUS |
| Kegagalan santri terakhir tidak meninggalkan perubahan sebelumnya | PN-10 | LULUS |
| Dua admin mengisi tempat terakhir bersamaan; hanya satu berhasil | KP-1a/1b | LULUS |
| Santri, kelas, kamar, atau tahun tidak aktif ditolak | PN-11 | LULUS |
| Relasi tahun ajaran lama tetap tersedia | PN-12 | LULUS |
| Snapshot peserta dan absensi lama tidak berubah | PN-13 | LULUS |
| Routing murobi tetap memakai penempatan aktif yang benar | PN-14 | LULUS |

Tambahan di luar daftar minimum: lima admin memperebutkan dua tempat terakhir
(KP-2, tepat dua berhasil), santri yang sama ditempatkan ke dua kamar berbeda
secara bersamaan (KP-3, tetap satu kamar), dua penempatan kelas bersamaan untuk
santri yang sama (KP-4, tetap satu penempatan aktif).

## 6. Uji browser

Dijalankan pada peramban sungguhan (Chromium) di tiga lebar: **1440 px**,
**768 px**, dan **390 px**. Fixture disiapkan ulang sebelum setiap lebar agar
hasilnya tidak bergantung pada urutan.

| Kelompok | Yang diperiksa | Hasil |
|----------|----------------|-------|
| BP-1 | menu dan breadcrumb | LULUS di 3 lebar |
| BP-2 | identitas semester aktif | LULUS di 3 lebar |
| BP-3 | pencarian dan seluruh filter, termasuk keadaan tanpa hasil | LULUS di 3 lebar |
| BP-4 | penempatan individual | LULUS di 3 lebar |
| BP-5 | penempatan massal dan jumlah terpilih | LULUS di 3 lebar |
| BP-6 | konfirmasi sebelum penerapan | LULUS di 3 lebar |
| BP-7 | pesan kesalahan kapasitas | LULUS di 3 lebar |
| BP-8 | tampilan kapasitas (terisi/kapasitas dan sisa) | LULUS di 3 lebar |
| BP-9 | pagination dan pemeliharaan filter | LULUS di 3 lebar |
| BP-10 | tautan dari Santri, Kelas, dan Kamar | LULUS di 3 lebar |
| BP-11 | alamat lama `admin_santri.php` | LULUS di 3 lebar |
| BP-12 | papan ketik, fokus, dan label | LULUS di 3 lebar |
| BP-13 | halaman tidak melebar | LULUS di 3 lebar |
| BP-14 | konsol bersih, tidak ada permintaan 5xx | LULUS |

Total **113 pemeriksaan browser lulus, 0 gagal**.

Bukti tangkapan layar tersimpan lokal di `tests/browser/tangkapan-penempatan/`
(21 berkas; folder itu diabaikan Git agar repositori tetap ringan — jalankan
ulang perintah pada bagian 2 untuk membuatnya kembali).

Tujuh tangkapan pilihan ikut dikomit sebagai bukti:
`docs/penempatan-santri/bukti-browser/`

| Berkas | Isi |
|--------|-----|
| `01-desktop-daftar.jpg` | daftar penempatan 1440 px: menu aktif, breadcrumb, ringkasan, filter, tabel |
| `02-desktop-konfirmasi.jpg` | layar konfirmasi massal dengan sebelum/sesudah dan kapasitas |
| `03-desktop-kapasitas-penuh.jpg` | penolakan kapasitas: "Kapasitas tidak cukup", tombol terapkan nonaktif |
| `04-desktop-tanpa-hasil.jpg` | keadaan "tidak ada hasil pencarian" |
| `05-tablet-daftar.jpg` | 768 px, tanpa geseran horizontal |
| `06-ponsel-daftar.jpg` | 390 px, tanpa geseran horizontal |
| `07-ponsel-konfirmasi.jpg` | layar konfirmasi pada 390 px |

Seluruh isinya data uji fiktif (`Santri Uji NN`, NIS `BPS01…BPS26`,
`BP Kelas …`, `BP Kamar …`) dan akun uji `bp_admin`; tidak ada data pribadi dan
tidak ada kredensial nyata.

## 7. Cacat yang ditemukan dan diperbaiki selama pengujian

### 7.0 Halaman lama sudah tidak dapat menyimpan pada baseline (diverifikasi)

Sebelum menyentuh kode, perilaku halaman lama diuji pada salinan baseline `main`
`3b53c1c`. Setelah login sebagai admin:

```
GET  /admin/admin_santri.php                                   → 200
POST /admin/admin_santri.php  (action=update_plot, tanpa token) → 419
     "Permintaan ditolak karena token keamanan tidak valid."
```

Penyebabnya: `admin/_guard.php` memeriksa CSRF untuk setiap POST, sedangkan
JavaScript halaman lama tidak pernah mengirim token — halaman itu menggambar
kerangkanya sendiri sehingga tidak memuat `window.ALHASAN_CSRF` milik
`App\Ui\Layout`. Jadi penempatan kelas maupun kamar lewat halaman lama sudah
gagal sebelum paket ini, tanpa pesan yang dapat dimengerti admin.

### 7.1 Kapasitas kamar terlampaui pada permintaan bersamaan (nyata, diperbaiki)

**Gejala.** `tests/penempatan_concurrency.php` KP-2: lima proses PHP nyata
menempatkan santri ke kamar berkapasitas 2 pada detik yang sama — **kelimanya
berhasil**, kamar terisi 5 dari 2. KP-3: santri yang sama memperoleh dua kamar.

**Sebab.** Baris kamar sudah dikunci `FOR UPDATE`, tetapi perhitungan penghuni
memakai `SELECT` biasa. Pada REPEATABLE READ (isolasi bawaan InnoDB), pembacaan
biasa memakai snapshot yang dibuat pada pembacaan pertama transaksi, sehingga
setelah menunggu kunci, transaksi tetap membaca keadaan lama. Kunci baris
menjadi tidak berguna.

**Perbaikan.** Transaksi penempatan dijalankan pada
`SET TRANSACTION ISOLATION LEVEL READ COMMITTED` (berlaku untuk satu transaksi
berikutnya saja, sehingga modul lain tidak terpengaruh).

**Sesudah perbaikan.** KP-1 1 dari 2 berhasil; KP-2 tepat 2 dari 5 berhasil;
KP-3 santri tetap satu kamar; KP-4 tetap satu penempatan kelas aktif.

### 7.2 Halaman menggeser horizontal pada 768 px (nyata, diperbaiki)

**Gejala.** BP-13a/13b gagal pada tablet: `scrollWidth` 778 px pada viewport
768 px.

**Sebab.** `<label class="ah-visually-hidden">` di dalam tabel yang lebih lebar
daripada wadahnya. Kelas itu memakai `position: absolute`, sehingga elemennya
diposisikan relatif terhadap blok penampung awal dan **lolos** dari
`overflow-x: auto` milik `.ah-table-wrap`.

**Perbaikan.** Nama aksesibel pilihan pada baris tabel dipindahkan ke atribut
`aria-label` pada elemen `<select>`-nya sendiri, sehingga tidak ada elemen
berposisi absolut di dalam tabel lebar. `assets/ui/alhasan.css` **tidak** diubah,
sehingga halaman lain tidak terpengaruh.

**Sesudah perbaikan.** BP-13a/13b/13c lulus pada 1440, 768, dan 390 px.

### 7.3 Temuan audit mandiri sebelum push (nyata, seluruhnya diperbaiki)

Karena tidak ada audit Codex, satu tinjauan adversarial terpisah dijalankan atas
seluruh kode baru sebelum commit. Empat belas temuan dilaporkan dan seluruhnya
ditindaklanjuti sebelum push. Yang paling berdampak:

| Temuan | Akibat bila dibiarkan | Perbaikan | Bukti |
|--------|-----------------------|-----------|-------|
| Konflik kunci pada **jalur kelas** melewati penerjemahan galat | admin melihat 500 tanpa penjelasan saat bentrok dengan formulir lama `admin_kelas.php` | `PenempatanService::translateFailure()` membaca `errno` sebelum rollback dan mengubah 1205/1213/1062 menjadi pesan "coba lagi" | PS-8 |
| Nilai balik `SET TRANSACTION` dan `begin_transaction()` diabaikan | transaksi bisa berjalan pada isolasi yang salah, atau tanpa transaksi, tanpa jejak — membatalkan seluruh jaminan kapasitas dan atomisitas | keduanya diperiksa; kegagalan membatalkan operasi sebelum menulis apa pun | PS-8 |
| `binlog_format = STATEMENT` mematikan seluruh fitur (galat 1665) | setiap penempatan gagal 500 di server tertentu, sulit dilacak karena modul lain normal | preflight bagian 0 memeriksanya, galatnya diterjemahkan menjadi pesan konfigurasi server, dan langkah persiapan cPanel menambahkannya | PS-8, preflight |
| Santri yang diarsipkan menahan tempat tidur selamanya | tempat tidur hanya dapat dibebaskan lewat SQL manual | pengeluaran diizinkan untuk santri nonaktif/arsip; filter dan kartu ringkasan baru untuk menemukannya | PN-20 |
| `UPDATE` perpindahan kamar tanpa klausa `id_tahun` | jaring pengaman yang dijanjikan dokumen tidak ada | klausa ditambahkan | PN-23 |
| Tanggal kelas yang tidak dipakai memblokir tindakan kamar | perpindahan kamar gagal karena field yang tidak relevan | validasi tanggal hanya berlaku untuk tindakan kelas | PN-24 |
| Filter kamar melewatkan santri berkonflik | kamar terlihat lebih kosong daripada kenyataannya | filter memakai `EXISTS`, bukan JOIN baris ber-ID terkecil | PN-21 |
| Audit ringkasan massal ditulis walau tidak ada perubahan | jejak audit palsu | ringkasan hanya ditulis bila ada yang benar-benar berubah | PN-22 |
| POST lama tanpa CSRF menerima 419, bukan 410 | klien AJAX lama tidak pernah membaca pesan yang menyebut halaman penggantinya | alamat lama memeriksa peran admin secara langsung dan tidak memeriksa CSRF (berkas itu tidak pernah menulis) | PW-6d/6e/6f, PW-12 |

Sisanya bersifat tampilan atau kejujuran dokumen: baris kamar yatim kini
memperoleh lencana konflik tersendiri (bukan "belum ada kamar"), kamar
berkapasitas 0 tidak dapat dipilih sebagai tujuan, preflight menyatakan bila
daftarnya terpotong, dan tiga klaim dokumen yang terlalu luas dipersempit
(jalur penulisan kelas kedua pada `admin_kelas.php`, cakupan token sekali pakai,
dan perilaku alamat lama terhadap CSRF).

Tinjauan yang sama **tidak** menemukan lubang keamanan: tidak ada SQL injection,
XSS, celah CSRF, IDOR, mutasi lewat GET, transaksi bersarang, atau kebocoran
detail internal pada pesan galat.

### 7.4 Kegagalan bawaan lingkungan (bukan cacat aplikasi)

`tests/v2_phase5_cetak_pdf.php` melaporkan gagal bila paket `playwright`
terpasang tetapi Chromium bawaannya tidak dapat diunduh. Menjalankan rangkaian
dengan `CHROMIUM_PATH` yang menunjuk Chromium lokal membuat berkas ini kembali
LULUS (76 pemeriksaan). Ini perilaku lingkungan yang sudah didokumentasikan pada
`docs/perapihan-v1-v2/panduan-audit-codex.md`, bukan akibat paket ini.

## 8. Perubahan pada pengujian lama

**Tidak ada.** Tidak satu pun berkas pengujian yang sudah ada diubah, dilemahkan,
atau dinonaktifkan. Secara khusus:

- `tests/phase2_static.php` baris 46 tetap berlaku: `admin/admin_santri.php`
  tidak memuat `DELETE FROM plotting_kelas` (sekarang berkas itu hanya
  mengalihkan);
- `tests/v2_phase3_static.php` menghitung jumlah migrasi; jumlahnya tetap 10
  karena paket ini tidak menambah migrasi;
- tidak ada pengujian yang mengandaikan kunci navigasi `master.santri_lama`
  (kunci itu memang tidak dipakai grup menu mana pun sebelumnya).

## 9. Yang BELUM diuji

Dinyatakan terbuka, bukan diklaim lulus:

- **Safari fisik (macOS/iOS).** Tidak tersedia bagi implementer.
- **Pembaca layar nyata (NVDA/JAWS/VoiceOver).** Label dan `aria-label` diuji
  lewat atribut dan urutan fokus, bukan lewat pengalaman pembaca layar.
- **Perangkat ponsel/tablet fisik.** Yang diuji adalah viewport peramban desktop
  pada 768 px dan 390 px.
- **Data produksi nyata.** Seluruh pengujian memakai `webalhasan_test`.
  Jalankan `php bin/penempatan_preflight.php` pada salinan `_test` dari backup
  produksi sebelum rilis.
- **Deployment, cron, dan smoke test cPanel.** Dilakukan pengguna; langkahnya ada
  pada `cpanel-deployment.md`.
