# Audit independen Codex — GET-mutasi V1

Tanggal: **20 Oktober 2026 (Asia/Jakarta)**. Hasil: **LULUS untuk cakupan yang disepakati, tanpa koreksi kode aplikasi**. Tidak ada temuan penghambat. Ini bukan keputusan merge atau izin deploy.

## Objek dan batas audit

- Branch `claude/fix-get-mutasi-v1`, baseline `6bec08e`, satu commit implementasi di atasnya: `113ee1fabbf0f3a355e63c689c03ec9cb7909244`.
- Membaca `AGENTS.md`, `PRD.md` (khususnya kompatibilitas fitur lama dan §5.2), README workstream, bukti implementator, seluruh diff kode/pemanggil, dan kedua suite implementator.
- Enam berkas mutasi sesuai keputusan Human Developer: hapus pendaftar, status/migrasi PSB, lunas/batal pembayaran PSB, hapus berita, galeri dan download. Bagian tambah/update berita serta unggah tetap di luar cakupan.
- Audit dilakukan Codex secara sekuensial setelah handoff Claude. Tidak membuat worktree, tidak mengubah mobile/main/produksi, dan tidak menyentuh tiga direktori untracked pekerjaan lain.

## Lingkungan dan isolasi

MariaDB **12.3.2** baru dengan `--skip-networking`, datadir/socket privat di `/private/tmp/getmutasi-audit-codex-20261020/`; DB **`getmutasi_audit_codex_test`**. Akun aplikasi `gm_audit` hanya mendapat hak pada DB tersebut. PHP **8.4.14**, Chromium melalui Playwright terpasang, server **`127.0.0.1:8993`**.

Struktur sintetis berasal dari **20 CREATE + 40 ALTER** berkas SQL V1 terlacak; tidak ada INSERT yang diimpor dan AUTO_INCREMENT struktur dinormalisasi ke 1. Migrasi **001–022**, seed SBX, sentinel cleanup dan backfill V2 dijalankan hanya pada instance privat ini. Tidak memakai dump lampiran, `.env` privat, akun, storage atau data produksi. Aplikasi HTTP berjalan dari salinan sumber disposable tanpa `.git` dan `.env`. Dependensi terpasang disalin, tidak dipasang ulang. Permintaan browser ke origin luar diblokir; Bootstrap/font dilayani dari dependensi lokal oleh helper audit.

Direktori privat bermode 0700, konfigurasi/manifest/cookie 0600. Sandbox semula memblokir socket lokal; koneksi DB dan server loopback dijalankan melalui eskalasi alat yang disetujui. Tidak memakai server DB operasional sebagai alternatif.

## Hasil pengujian independen

| Pengujian | Hasil | Bukti |
|---|---|---|
| Jalankan ulang `tests/get_mutasi_v1_http.php` | **89 lulus / 0 gagal**, exit 0 | `audit-http.txt` |
| Probe baru Codex `tests/get_mutasi_v1_audit.php` | **84 lulus / 0 gagal**, exit 0 | `audit-tambahan.txt` |
| Klik Chromium `tests/browser/uji-get-mutasi-v1.mjs` | **32 lulus / 0 gagal**, exit 0 | `audit-browser.txt` |
| Preservasi `tests/cleanup_legacy_http.php` | **156 lulus / 0 gagal**, exit 0 | `audit-preservasi.txt` |
| Regresi tambahan `tests/cleanup_legacy_audit_claude.php` | **127 lulus / 0 gagal**, exit 0 | `audit-regresi-cleanup.txt` |
| Seluruh `tests/*_static.php` | **20 suite exit 0**, termasuk GET-mutasi **28/0** | `audit-statis.txt` |
| Sintaks | **63 PHP admin** dan kedua PHP audit baru tanpa galat; JS browser `node --check` exit 0 | `audit-statis.txt` |

Probe Codex ditulis terpisah dari suite Claude. Delapan kombinasi aksi diuji terhadap GET/HEAD/PUT/PATCH/DELETE/OPTIONS, dengan body, token sah, `_method=POST`, dan header `X-HTTP-Method-Override: POST`: seluruhnya **405 + Allow: POST**. POST dengan token sesi admin lain, token hanya di query string, serta token body salah meskipun header benar: **419**. Sidik jari jumlah dan seluruh kolom/baris **semua tabel, termasuk users/audit_logs/api_tokens**, identik setelah rangkaian penolakan (kedua login telah selesai sebelum snapshot).

POST bertoken header sah menggunakan `act/id/val` dari body meskipun query string bertentangan; `Cadangan` sah sesuai enum asli. Hapus berita/galeri/download mengabaikan `file/gbr` palsu di body maupun query: berkas yang dipilih DB terhapus, berkas umpan tetap ada, dan seluruh tabel lain identik. Suite Claude yang diulang juga membuktikan penolakan anonim/non-admin, injeksi ID/status, traversal nama DB, perubahan baris target, migrasi/NIS, lunas/batal, dan halaman publik.

Browser menguji tombol di lebar **1440 dan 375**: asosiasi tombol terima/tolak dengan form tersembunyi, keberadaan kotak tombol, dan batal konfirmasi tanpa POST. Pada desktop, klik nyata menjalankan terima, tolak, migrasi, hapus pendaftar, lunas/batal, serta hapus ketiga jenis konten. Setiap submit membawa ID target dan CSRF, menampilkan konfirmasi bila sebelumnya ada, lalu kembali ke daftar dengan hasil/tombol yang sesuai. Ini tidak mengklaim seluruh tata letak responsif sudah diaudit visual.

Setup/harness sempat perlu diperbaiki: opt-in/nama DB suite statis V3, dokumen terlacak yang belum ikut salinan, kolom wajib/keunikan fixture, dan selector browser yang juga cocok dengan baris modal. Itu kegagalan persiapan audit, bukan cacat aplikasi; angka tabel di atas hanya hasil eksekusi final yang exit 0. Fixture sintetis DB dipertahankan, tidak ada pembersihan data historis.

## Penilaian kode

- `_guard.php` memeriksa sesi/peran admin dan CSRF sebelum mutasi POST. `admin_require_post()` mengakhiri metode lain dengan 405 dan `Allow: POST`.
- Jalur tulis lama tidak mengambil ID/nama berkas dari GET. ID mutasi dicast int atau diikat sebagai parameter SQL; status PSB memakai daftar putih. `basename()` diterapkan ke nama dari DB sebelum penghapusan berkas.
- Pemanggil menggunakan form POST dan CSRF; atribut `form=` tombol status terbukti bekerja di browser. Tidak ditemukan pemanggil aktif lama di luar suite/bukti.
- SQL/migrasi, situs publik dan fungsi bisnis migrasi PSB tidak diganti oleh commit. Perluasan status `Cadangan` sesuai enum/komentar kode baseline dan bukan penambahan fungsi bisnis.

## Temuan lama / di luar cakupan

1. **Filter GET masih disisipkan langsung ke SQL SELECT**: `admin/admin_pembayaran_psb.php:147–149` (sudah diputuskan di luar cakupan), juga `admin/admin_data.php:183–186` (`jk`, `jenjang`, `kecamatan`, `cari`). Dikonfirmasi lewat tinjauan sumber; tidak menjalankan eksploit pembacaan data dan tidak memperbaikinya dalam audit ini. Memerlukan workstream keamanan terpisah.
2. **Folder gambar berita tidak konsisten sejak baseline**: upload menulis `../gambar/` (`admin/proses_berita.php:19,48`), hapus mencari `gambar/` relatif direktori admin (`:79–80`); situs publik membaca folder root `gambar/`. `git show 6bec08e:admin/proses_berita.php` membuktikan ketidaksesuaian sudah ada sebelum perubahan Claude. Penghapusan baris berhasil, tetapi aset di folder root dapat tertinggal. Fixture HTTP/browser mengikuti folder hapus lama `admin/gambar/`, sehingga hasil lulus tidak membuktikan pembersihan gambar berita hasil upload normal. Tidak diperbaiki karena aturan workstream mempertahankan fungsi lama; koreksi penyimpanan aset perlu keputusan terpisah.
3. Temuan README implementator tentang pembayaran yatim saat hapus PSB, SQL/input unggah/manual PSB dan XSS tersimpan tetap berlaku. Audit ini tidak memberi klaim modul V1 bebas seluruh celah.

Ketiga kelompok temuan tersebut tidak diperkenalkan oleh commit yang diaudit dan tidak menghalangi penutupan jalur GET-mutasi yang diminta.

## Reproduksi tambahan

Ikuti setup struktur kosong, migrasi dan seed di README/perintah cleanup, dengan instance privat baru dan DB berakhiran `_test`. Gunakan salinan sumber disposable, lalu environment privat yang konsisten antara CLI/server:

```sh
export APP_ENV=testing DB_NAME=getmutasi_audit_codex_test
# DB_HOST=localhost, akun DB terbatas, mysqli.default_socket privat,
# APP_URL/GETMUTASI_TEST_URL=http://127.0.0.1:8993; tanpa .env produksi.
export GETMUTASI_RUN_TESTS=1
export GETMUTASI_WEB_ROOT=/private/tmp/<sesi>/web
php tests/get_mutasi_v1_http.php
php tests/get_mutasi_v1_audit.php
export GETMUTASI_BROWSER_FIXTURE=/private/tmp/<sesi>/browser-fixture.json
# Manifest wajib belum ada; seed menambah baris sintetis dan berkas baru.
php tests/get_mutasi_v1_browser_seed.php
BASE_URL=http://127.0.0.1:8993 PERAPIHAN_AUDIT_DB=1 \
  node tests/browser/uji-get-mutasi-v1.mjs
```

Jalankan preservasi cleanup dan audit cleanup sebelum fixture browser (fixture baru juga aman untuk preservasi). Untuk suite statis V3 saja, set `V3_RUN_TESTS=1 DB_NAME=webalhasan_v3_phase1_test`; kedua suite tersebut hanya membaca kode dan tidak membuka koneksi DB. Set `MOBILE_APP_ROOT` eksplisit ke repositori mobile baca-saja. Tidak mengubah guard/assertion suite untuk memaksakan kelulusan.

## Batas dan handoff

Tidak menjalankan ulang eksploit baseline destruktif milik implementator, seluruh integrasi/konkurensi/PDF V1–V4, browser seluruh peran/halaman, mobile, atau smoke cPanel/produksi. Bukti kode lama dalam README tetap **bukti Claude**, bukan eksekusi independen Codex.

Perubahan audit hanya harness dan dokumentasi/bukti. **Workstream lulus audit Codex; berhenti untuk keputusan Human Developer. Tidak merge, deploy, migrasi produksi atau melanjutkan PRD lain.**
