# Perbaikan keamanan mutasi V1 lewat GET (19 Oktober 2026)

Status: **lulus audit independen Codex pada 20 Oktober 2026, tanpa koreksi kode aplikasi.** Belum di-merge, belum dideploy, tanpa migrasi produksi. Laporan, temuan di luar cakupan, bukti dan batas audit: [AUDIT-CODEX.md](AUDIT-CODEX.md).

## Keputusan Human Developer

- Workstream terpisah dari cleanup V1–V4; menindaklanjuti risiko R-1 di `docs/cleanup-legacy-v1-v4/AUDIT-CLAUDE.md`.
- Implementator **Claude**, auditor **Codex**.
- Branch `claude/fix-get-mutasi-v1` dari `origin/main` `6bec08e`, di folder yang sama (tanpa worktree).
- Cakupan **hanya mutasi**. Injeksi SQL baca-saja pada filter GET `admin/admin_pembayaran_psb.php` (jk/jenjang/status) dicatat sebagai temuan, tidak diperbaiki di sini.
- Fungsi bisnis PSB dan konten publik tetap sama: tidak ada fitur yang dipensiunkan, tabel/kolom/data tidak dihapus, situs publik tidak diubah.

## Perubahan

| Berkas | Sebelum | Sesudah |
|---|---|---|
| `admin/_guard.php` | CSRF divalidasi untuk POST | Tambah `admin_require_post()`: metode selain POST → 405 + `Allow: POST`, tanpa menyentuh data |
| `admin/hapus_siswa.php` | GET, `$_GET['id']` disisipkan langsung ke `DELETE` | Hanya POST + CSRF, `id` di-cast int, prepared statement |
| `admin/proses_psb_admin.php` | `act=status` / `act=migrasi` lewat GET | Hanya POST + CSRF; `val` dibatasi `Diterima`/`Ditolak`/`Cadangan` (sesuai komentar kode asli dan enum), selain itu 422; prepared statement. Logika migrasi/NIS tidak diubah |
| `admin/admin_pembayaran_psb.php` | `?act=lunas` / `?act=batal` lewat GET | Aksi dibaca dari POST; GET dengan `act` → 405. Halaman daftar dan filter GET tetap |
| `admin/proses_berita.php` | `act=hapus` lewat GET, `id` tanpa cast, nama gambar dari `$_GET['gbr']` | Hanya POST, cast int, nama gambar dibaca dari baris `berita` dan dibatasi `basename()`; tambah/update tidak diubah |
| `admin/admin_galeri.php`, `admin/admin_download.php` | `?hapus=&file=` lewat GET, `unlink` memakai nama dari GET (path traversal) | GET `?hapus` → 405; hapus lewat POST, cast int, nama berkas dari DB + `basename()`; unggah tidak diubah |
| `admin/admin_data.php`, `admin_berita.php`, `admin_galeri.php`, `admin_download.php`, `admin_pembayaran_psb.php` | Tautan `<a href>` bermutasi | `<form method="post">` dengan `Csrf::input()`; ikon, kelas tombol, dan teks `confirm()` tetap. Tombol terima/tolak di dalam `btn-group` memakai atribut `form=` ke formulir tersembunyi agar tata letak grup tidak berubah |

Deviasi kecil dari rencana yang disetujui: daftar putih status mencakup `Cadangan` (rencana menyebut Diterima/Ditolak), karena komentar asli dan enum menyatakannya sebagai status sah. Tombol yang ada hanya mengirim Diterima/Ditolak.

## Bukti

Semua uji memakai instance MariaDB privat baru (`--skip-networking`, socket privat) dengan DB `getmutasi_v1_test`: struktur dari `schema.sql` tanpa baris data (diekstrak audit cleanup), migrasi 001–022, seed SBX, sentinel `tests/cleanup_legacy_fixture.php`, dan backfill V2. Server `php -S 127.0.0.1:8991 tests/v4_phase1_router.php` dijalankan dari **salinan sumber** di luar Git agar berkas unggahan sintetis tidak masuk ke checkout. Tidak ada `.env`, dump, atau data produksi yang dipakai.

| Bukti | Hasil | Berkas |
|---|---|---|
| `tests/get_mutasi_v1_static.php` | 28/0 | `uji-statis.txt` |
| `tests/get_mutasi_v1_http.php` (kode baru) | 89/0, exit 0 | `uji-http.txt` |
| Suite baru terhadap kode lama `6bec08e` (DB salinan `getmutasi_old_test`) | statis 6/22, HTTP 35/54: satu GET `hapus_siswa.php?id=0' OR '1'='1` menghapus **seluruh** `psb_pendaftar` (0 tersisa) dan berkas canary di luar folder unggahan ikut terhapus | `bukti-kode-lama.txt` |
| `tests/cleanup_legacy_http.php` (preservasi seluruh tabel) | 156/0 | `regresi.txt` |
| `tests/cleanup_legacy_audit_claude.php` | 127/0 | `regresi.txt` |
| Seluruh `tests/*_static.php` (20 berkas), `php -l admin/*.php` | exit 0 / tanpa galat | `regresi.txt` |

Yang dibuktikan suite HTTP:

- Halaman pemanggil tampil 200 dengan formulir POST + `_csrf` dan tanpa tautan bermutasi; filter GET pembayaran tetap jalan.
- 13 URL GET lama (termasuk muatan injeksi `id`/`val` dan traversal `file`/`gbr`) × GET/HEAD, serta PUT/DELETE, → 405 + `Allow: POST`. Snapshot jumlah + SHA-256 seluruh baris setiap tabel (kecuali `users`, `audit_logs`, `api_tokens` yang berubah karena login) identik sebelum dan sesudah; berkas unggahan dan canary tetap ada.
- POST tanpa CSRF / CSRF salah → 419; non-admin → 403; anonim → login; status di luar daftar atau injeksi → 422; injeksi `id` pada POST diperlakukan sebagai id 0. Semuanya tidak mengubah satu tabel pun.
- POST sah mengubah tepat baris target: hapus pendaftar, terima/tolak, migrasi (satu santri baru dengan NIS prefiks `yy01` seperti sebelumnya, status `Dimigrasi`), lunas/batal lunas, serta hapus berita/galeri/download beserta berkasnya; baris dan berkas lain utuh. Nama berkas `../…` di DB tidak dapat menghapus berkas di luar folder unggahan.
- Situs publik `/`, `/psb.php`, `/berita.php`, `/galeri.php`, `/download.php` tetap 200.

## Temuan nonblokir (tidak diubah)

1. `admin/admin_pembayaran_psb.php` baris filter `jk`/`jenjang`/`status`: `$_GET` disisipkan langsung ke `SELECT` (injeksi baca-saja). Diputuskan di luar cakupan.
2. `admin/hapus_siswa.php` hanya menghapus `psb_pendaftar`; baris `psb_pembayaran` dengan nomor pendaftaran yang sama tertinggal sebagai referensi yatim (perilaku lama, tidak berubah). Fixture uji sengaja tidak memberi tagihan pada pendaftar yang dihapus agar invarian `cleanup_legacy_http.php` tetap berlaku.
3. Unggah galeri/download/berita dan input manual PSB masih memakai SQL interpolasi dan nama berkas dari klien (sudah POST + CSRF). Bukan GET-mutasi; memerlukan tahap terpisah.
4. Halaman V1 menampilkan judul/nama berkas tanpa `htmlspecialchars` (XSS tersimpan oleh admin). Tidak diubah.

## Belum dijalankan oleh implementator

- Browser nyata (klik tombol, dialog `confirm()`, tata letak `btn-group`). Hanya HTML hasil render yang diperiksa lewat HTTP.
- Regresi V1–V4 integrasi/konkurensi/browser/PDF lain, mobile (tidak ada perubahan mobile), smoke cPanel, produksi.

Audit Codex kemudian menjalankan klik browser Chromium (32/0), probe independen tambahan (84/0), mengulang HTTP (89/0), preservasi (156/0), regresi cleanup (127/0), serta 20 suite statis dan lint 63 berkas admin. Browser menguji tombol pada 1440/375 dan alur mutasi desktop; bukan klaim audit visual seluruh halaman. Lihat `AUDIT-CODEX.md` untuk temuan folder gambar berita lama dan batas bukti penghapusan aset.

## Rollback

Revert commit implementasi di branch ini. Tidak ada migrasi atau perubahan data, jadi rollback hanya kode. Revert mengembalikan celah GET-mutasi/injeksi, jadi jangan dipakai kecuali formulir POST bermasalah di produksi.

## Reproduksi

```sh
# env privat: APP_ENV=testing, DB_NAME=getmutasi_v1_test, socket privat lewat PHP_INI_SCAN_DIR, APP_URL=http://127.0.0.1:8991
php bin/migrate.php up
V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php > /private/tmp/<privat>/seed.log
CLEANUP_RUN_TESTS=1 php tests/cleanup_legacy_fixture.php
php bin/v2_phase1_backfill.php
# dari salinan sumber (bukan checkout Git):
php -S 127.0.0.1:8991 tests/v4_phase1_router.php
GETMUTASI_RUN_TESTS=1 GETMUTASI_TEST_URL=http://127.0.0.1:8991 GETMUTASI_WEB_ROOT=<salinan> php tests/get_mutasi_v1_http.php
CLEANUP_RUN_TESTS=1 CLEANUP_TEST_URL=http://127.0.0.1:8991 php tests/cleanup_legacy_http.php
php tests/get_mutasi_v1_static.php
```

Suite HTTP menolak berjalan bila `GETMUTASI_WEB_ROOT` berisi `.git`, bila DB tidak berakhiran `_test`, atau bila URL bukan `127.0.0.1`. Suite ini menambah baris sintetis bertanda `GM-xxxx` dan tidak menghapus fixture DB; berkas sintetis dan cookie sementaranya dihapus di akhir.

## Handoff ke auditor Codex

> Audit perbaikan keamanan GET-mutasi V1 yang dibuat Claude pada branch `claude/fix-get-mutasi-v1`. Baca AGENTS.md dan `docs/perbaikan-get-mutasi-v1/README.md`, periksa commit di atas `6bec08e`, jalankan pengujian secara independen pada DB `_test` sintetis, dan lakukan koreksi terarah bila perlu. Jangan merge/deploy tanpa keputusan Human Developer.
