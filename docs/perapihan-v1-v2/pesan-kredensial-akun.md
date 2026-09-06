# Pesan Kredensial Akun Siap Salin

Keputusan pengguna **6 September 2026**. Branch `feat/pesan-kredensial-akun`,
baseline `main` `1382d6a`. Belum di-merge, belum di-push, belum dirilis, dan
tidak dinyatakan siap produksi. **Menunggu audit Codex.**

---

## 1. Keputusan produk

Saat admin membuat akun **guru**, **pengurus**, atau **orang tua**, sistem sudah
menghasilkan password sementara acak, menyimpan hash-nya saja, menampilkan
password itu satu kali, dan mewajibkan penggantian pada login pertama. **Seluruh
perilaku itu dipertahankan.**

Yang ditambahkan: setelah akun berhasil dibuat, halaman menampilkan **panel
sukses berisi pesan informasi login yang siap disalin admin** dan ditempelkan
sendiri ke email pengguna.

**Sistem tidak mengirim email.** Tidak ada tombol kirim, tidak ada integrasi
SMTP, tidak ada `mailto:` otomatis, dan tidak ada permintaan ke layanan email
mana pun. Pengiriman tetap tindakan manual admin.

### Yang tidak termasuk

Pengiriman email otomatis; template email yang dapat diedit admin; histori pesan
kredensial; pembukaan kembali password lama; pendaftaran akun mandiri oleh
pengguna; perubahan autentikasi API atau aplikasi mobile; penambahan jalur
pembuatan akun admin baru. **Alur reset password tidak diubah** — ia tetap
menampilkan password sementara seperti sebelumnya dan TIDAK membuat pesan
kredensial.

---

## 2. Isi panel dan teks pesan

Panel sukses memuat: nama pengguna, alamat email tujuan (bila ada), username,
password sementara, alamat masuk `https://alhasan.co.id/portal/`, instruksi
bahwa password wajib diganti pada login pertama, peringatan agar username dan
password tidak dibagikan kepada pihak lain, tombol **Salin pesan**, dan
peringatan agar papan klip dibersihkan setelah email terkirim.

Bila akun tidak memiliki email, panel menyatakannya secara eksplisit dan
menyarankan saluran lain. **Email tetap opsional** seperti sebelumnya; pekerjaan
ini tidak mengubahnya menjadi wajib.

### Teks baku

Disusun oleh `App\Account\CredentialMessage::text()` sebagai **teks biasa**,
tanpa markup:

```
Assalamu’alaikum.

Yth. [NAMA PENGGUNA],

Akun Anda pada Sistem Al Hasan telah dibuat.

Alamat masuk:
https://alhasan.co.id/portal/

Username: [USERNAME]
Password sementara: [PASSWORD SEMENTARA]

Pada login pertama, Anda akan diminta membuat password baru. Setelah password baru berhasil dibuat, password sementara di atas tidak dapat digunakan kembali.

Mohon simpan informasi akun ini dengan aman dan jangan membagikannya kepada pihak lain.

Wassalamu’alaikum.
```

Email **tidak** ikut di dalam badan pesan; ia hanya ditampilkan pada ringkasan
panel sebagai alamat tujuan yang perlu diketik admin.

### Sumber nilai

Nama, username, dan email TIDAK diambil dari nilai mentah formulir. Setelah
penyimpanan berhasil, kedua service membaca ulang baris akun dari server dan
mengembalikannya pada kunci `account`:

| Jalur | Service | Pembacaan ulang |
| --- | --- | --- |
| Guru | `AccountService::createTeacher()` | `AccountRepository::find($id)` |
| Pengurus | `PerizinanAccountService::create()` | `AccountService::find($id)` |
| Orang tua | `PerizinanAccountService::create()` | `AccountService::find($id)` |

Bila akun tersimpan tetapi tidak dapat dibaca kembali, kedua service melempar
`RuntimeException` — lebih baik gagal daripada menampilkan pesan yang tidak
sesuai isi basis data. `CredentialMessage::forSavedAccount()` menolak muatan
tanpa id, nama, username, atau password, sehingga **pembuatan akun yang gagal
tidak dapat menghasilkan pesan palsu**.

---

## 3. Perilaku satu kali tampil

| Mekanisme | Berkas |
| --- | --- |
| Flash sesi terstruktur (bukan HTML), dihapus saat dibaca | `app/Account/CredentialFlash.php` |
| Panel yang meng-escape seluruh nilai | `app/Ui/CredentialPanel.php` |
| Pengambilan sekali + header no-store | `admin/admin_akun.php` |

Alurnya:

1. POST berhasil → `CredentialFlash::set()` menyimpan **data terstruktur**
   (bukan potongan HTML) ke `$_SESSION['_ah_kredensial_akun']`.
2. Halaman menjawab **redirect** (pola POST → redirect → GET). Password tidak
   pernah masuk URL, query string, maupun header `Location`.
3. Pada GET berikutnya, `CredentialFlash::take()` dipanggil **sebelum keluaran
   halaman dimulai**; ia mengembalikan muatan sekaligus **menghapusnya** dari
   sesi. Tidak ada pembacaan kedua.
4. Karena muatan ada, `CredentialPanel::noStore()` mengirim
   `Cache-Control: private, no-store, max-age=0` (plus `Pragma: no-cache` dan
   `Expires: 0`) sebelum satu byte pun HTML dikirim.
5. Panel dirender. Memuat ulang halaman, membuka alamat yang sama, atau menekan
   tombol kembali peramban tidak memunculkannya lagi — sesi sudah kosong dan
   respons tidak boleh disimpan cache.

Pembersihan tambahan: `CredentialFlash::forget()` dipanggil **di awal setiap
POST** (agar sisa permintaan sebelumnya tidak terbawa) dan **pada blok `catch`**
(agar kegagalan tidak meninggalkan pesan).

Retry formulir tidak menghasilkan akun atau pesan kedua: pola redirect membuat
muat ulang tidak mengirim ulang POST, dan bila POST benar-benar diulang, kunci
unik `users.username`/`users.email` serta pemeriksaan "master sudah memiliki
akun" menolaknya. Kegagalan itu berakhir pada `catch` yang membuang pesan.

---

## 4. Model ancaman dan pengamanan password

| Ancaman | Pengamanan | Bukti uji |
| --- | --- | --- |
| Password tersimpan di basis data | Hanya `password_hash(..., PASSWORD_DEFAULT)` yang disimpan; pemindaian **seluruh kolom teks** basis data tidak menemukan nilai aslinya | KR-1g, KR-2c, KR-3e, KR-5a/b, KR-14l |
| Password bocor lewat audit | Audit hanya menyimpan id akun, nama, username, role, waktu, dan pelaku | PK-14f/g, KR-6a/b/c |
| Password bocor lewat log aplikasi | Tidak ada `error_log()` yang menyentuh password; log uji diperiksa isinya | PK-14c, KR-7a |
| Password bocor lewat konsol/analytics | Skrip panel tidak memakai `console.*`, tidak ada analytics | PK-14d, BK-9 |
| Password bocor lewat URL/query string | Pola POST → redirect; seluruh alamat yang dikunjungi diperiksa | PK-12e, PW-5b, PW-10b |
| Password bocor lewat cookie/localStorage/sessionStorage | Tidak ada penggunaan storage peramban; isi cookie jar diperiksa | PK-14e, PW-5a |
| Password bocor lewat atribut HTML | Password hanya menjadi **teks** di dalam elemen; seluruh atribut dipindai | PK-7a/b/c/d |
| Password tampil lagi lewat cache/tombol kembali | `Cache-Control: private, no-store`; flash terhapus saat dibaca | PW-2, PW-4a/b/c, BK-6, BK-7 |
| Password muncul di daftar akun, API, ekspor, cetak | Password tidak pernah dibaca ulang dari basis data (hanya hash yang ada); panel diberi kelas `ah-no-print` | PW-10a, PK-14h |
| XSS lewat nama/username/email | Seluruh nilai di-escape `htmlspecialchars(..., ENT_QUOTES)`; tidak ada HTML mentah dibangun dari data pengguna | PK-5a–e, KR-13b |
| Isi salinan berbeda dari yang terlihat | Tombol menyalin `textContent` elemen yang terlihat, bukan string terpisah | PK-6b/d, PW-9, BK-3a |
| Pengiriman ke pihak ketiga | Tidak ada `mailto:`, SMTP, `fetch`, `XMLHttpRequest`, atau `sendBeacon` | PK-10a/b |

Password sementara hanya berada di **memori proses** dan **flash sesi satu
kali** selama satu redirect. Itu satu-satunya tempat nilai aslinya pernah
bertahan, dan ia dihapus pada pembacaan pertama.

### Tombol salin

`Salin pesan` adalah `<button type="button">` di luar formulir mana pun, jadi
kegagalan menyalin tidak dapat membuat akun atau password baru. Ia memakai
`navigator.clipboard.writeText()` dengan isi `textContent` kotak pesan. Berhasil
→ area `role="status" aria-live="polite"` mengumumkan **"Pesan berhasil
disalin"**. Gagal atau Clipboard API tidak tersedia → teks pesan disorot otomatis
dan status menjelaskan cara menyalin manual (Ctrl+C / Cmd+C). Tanpa JavaScript,
tombol tetap tersembunyi dan petunjuk salin manual tetap tampil di bawah kotak.

---

## 5. Berkas yang diubah

**Baru**

- `app/Account/CredentialMessage.php` — teks baku dan penyusunan muatan pesan.
- `app/Account/CredentialFlash.php` — flash sesi satu kali.
- `app/Ui/CredentialPanel.php` — panel sukses, tombol salin, header no-store.
- `tests/kredensial_static.php`, `tests/kredensial_integration.php`,
  `tests/kredensial_web_smoke.php`, `tests/browser/uji-kredensial.mjs`,
  `tests/browser/seed-kredensial.php`, `bin/kredensial_run_all_tests.sh`.

**Diubah**

- `app/Account/AccountService.php` — `createTeacher()` mengembalikan `account`.
- `app/Account/PerizinanAccountService.php` — `create()` mengembalikan `account`.
- `admin/admin_akun.php` — mengisi, mengambil, dan membuang flash kredensial;
  merender panel; mengirim header no-store.
- `assets/ui/alhasan.css` — gaya `.ah-kredensial*`, termasuk ponsel dan cetak.

---

## 6. Hasil pengujian

Dijalankan pada PHP 8.4.21 dan MariaDB 10.11.14, database `webalhasan_test`
dengan migrasi 001–010, fixture sandbox peran dan fixture performa 1.000
pengajuan, serta Chromium Playwright. Log mentah: `bukti-pesan-kredensial/`.

| Rangkaian | Perintah | Hasil |
| --- | --- | --- |
| Statis fitur ini | `php tests/kredensial_static.php` | **105 lulus, 0 gagal** |
| Integrasi fitur ini | `KREDENSIAL_RUN_INTEGRATION=1 php tests/kredensial_integration.php` | **59 lulus, 0 gagal** |
| Smoke test web fitur ini | `KREDENSIAL_RUN_WEB=1 php tests/kredensial_web_smoke.php` | **51 lulus, 0 gagal** |
| Browser desktop 1440 px + ponsel 390 px | `node tests/browser/uji-kredensial.mjs` | **55 lulus, 0 gagal** |
| Regresi V1/V2 | bagian A `bin/perapihan_run_all_tests.sh` | **2.463 lulus, 0 gagal** |
| Paket perapihan V1–V2 | bagian B `bin/perapihan_run_all_tests.sh` | **247 lulus, 0 gagal** |

Total **2.980 pemeriksaan otomatis lulus, 0 gagal.** Menjalankan semuanya
sekaligus:

```bash
MOBILE_APP_ROOT=/path/ke/alhasanApps bash bin/kredensial_run_all_tests.sh
```

Uji browser dijalankan terpisah:

```bash
KREDENSIAL_SEED=1 php tests/browser/seed-kredensial.php
php -S 127.0.0.1:8942 -t . &
BASE_URL=http://127.0.0.1:8942 node tests/browser/uji-kredensial.mjs
KREDENSIAL_SEED=1 php tests/browser/seed-kredensial.php --bersihkan
```

### Cakupan pengujian terhadap kriteria penerimaan

1. Panel guru, pengurus, orang tua — KR-1, KR-2, KR-3, PW-1, BK-1, BK-10.
2. Satu kali tampil, muat ulang, tombol kembali — KR-4, PW-4, BK-6, BK-7.
3. Isi salinan dan cadangan papan klip — PK-6, PW-9, BK-3, BK-5.
4. Escaping nama, username, email — PK-5, KR-13.
5. Tidak ada plaintext di DB, audit, log, API, URL, storage — KR-5, KR-6, KR-7,
   PK-7, PK-14, PW-5, PW-10.
6. Kegagalan transaksi dan retry — KR-8, KR-9, PW-7, PW-8.
7. Wajib ganti password pada login pertama — KR-1f, KR-10.
8. Login setelah password diganti — KR-11.
9. Regresi role, relasi master, admin terakhir, multi-peran, status, reset
   password — KR-12, KR-14, ditambah `perapihan_*` (247 pemeriksaan).
10. Desktop dan ponsel — BK-8, tangkapan layar `bukti-pesan-kredensial/`.

### Data uji

Seluruh akun, guru, pengurus, wali, dan santri pada pengujian ini **fiktif**,
dibuat dengan akhiran acak atau awalan `bk_`/`BK`, dan dihapus kembali pada blok
`finally` maupun oleh `seed-kredensial.php --bersihkan`. Password pada tangkapan
layar adalah nilai acak milik akun sandbox yang sudah tidak ada. Tidak ada
kredensial nyata pada repositori.

---

## 7. Pengujian yang MENUNGGU VERIFIKASI

Butir berikut **belum diuji** dan tidak boleh dinyatakan lulus.

| Butir | Status | Cara verifikasi |
| --- | --- | --- |
| Safari fisik macOS/iOS: perilaku `navigator.clipboard.writeText()` di luar gestur pengguna, dan pemilihan teks pada `<pre>` | **MENUNGGU VERIFIKASI** | Buka panel pada Safari nyata, tekan `Salin pesan`, tempel ke aplikasi lain, lalu ulangi dengan izin papan klip ditolak. Harapan: berhasil menyalin, atau teks tersorot beserta petunjuk Cmd+C |
| Pembaca layar nyata (VoiceOver, NVDA, JAWS) mengumumkan "Pesan berhasil disalin" | **MENUNGGU VERIFIKASI** | Aktifkan pembaca layar, tekan tombol salin, dengarkan pengumuman dari area `aria-live` |
| Perilaku `bfcache` pada Safari dan Firefox saat tombol kembali ditekan | **MENUNGGU VERIFIKASI** | Chromium sudah diuji (BK-7). Ulangi pada Safari dan Firefox; harapan: panel dan password tidak muncul |
| Penempelan pesan ke klien email nyata (Gmail, Outlook) tanpa merusak baris | **MENUNGGU VERIFIKASI** | Salin lalu tempel ke jendela tulis email; harapan: seluruh 17 baris utuh sebagai teks biasa |
| Perilaku pada cPanel produksi, termasuk apakah proxy/CDN menghormati `no-store` | **MENUNGGU VERIFIKASI** | Setelah rilis, periksa header respons `admin/admin_akun.php` dengan alat pengembang |
| Ponsel fisik (Android/iOS) menyalin dari peramban seluler | **MENUNGGU VERIFIKASI** | Uji lebar 390 px sudah lulus pada Chromium emulasi; ulangi pada perangkat nyata |

### Temuan baseline (BUKAN akibat pekerjaan ini)

Pada `admin/admin_akun.php` baseline `main` `1382d6a` terdapat tiga kolom
username dengan `pattern="[a-z0-9._-]+"`. Peramban berbasis Chromium terbaru
mengurai atribut `pattern` dengan mesin regexp mode `v`, yang menuntut tanda
hubung literal di-escape. Akibatnya konsol mencatat
`Pattern attribute value ... is not a valid regular expression` dan **validasi
sisi klien pada kolom username menjadi tidak aktif**. Validasi server tetap
berjalan penuh, sehingga tidak ada lubang keamanan.

Temuan ini **sengaja tidak diperbaiki** di sini karena berada di luar cakupan
fitur, dan **dicatat terbuka** oleh `tests/browser/uji-kredensial.mjs` sebagai
temuan baseline. Ia membutuhkan keputusan pengguna sebelum disentuh.

---

## 8. Rollback

Fitur ini **tidak menambah migrasi basis data** dan tidak mengubah skema apa
pun, sehingga tidak ada rollback SQL yang diperlukan.

Rollback penuh:

```bash
git checkout main            # kembali ke baseline 1382d6a
```

Rollback selektif bila fitur sudah terlanjur di-merge — kembalikan commit fitur
saja:

```bash
git revert <hash-commit-fitur>
```

Setelah rollback, halaman `admin/admin_akun.php` kembali menampilkan password
sementara pada pesan flash lama (`<code>` di dalam `master_flash`), persis
seperti sebelum pekerjaan ini. Tidak ada data yang perlu dipulihkan: password
sementara memang tidak pernah tersimpan dalam bentuk asli, dan akun yang sudah
dibuat tidak terpengaruh.

Rollback sebagian pada tingkat perilaku (mempertahankan panel tetapi mematikan
tombol salin) tidak disediakan dan tidak disarankan: petunjuk salin manual sudah
menjadi jalur cadangan yang selalu tersedia.
