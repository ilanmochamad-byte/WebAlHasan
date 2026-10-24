# Perbaikan keamanan PSB (24 Oktober 2026)

Status: **LULUS audit independen Codex setelah dua koreksi terarah, 24 Oktober 2026** (`AUDIT-CODEX.md`). Implementasi Claude `c6014e1`; koreksi auditor pada konsistensi pembayaran bersamaan dan kategori tagihan, dengan bukti terpisah di `audit-codex/`. Belum di-merge, belum dideploy, tanpa migrasi database. Prasyarat redesain website Tahap 8; Tahap 8 belum dimulai.

## Keputusan Human Developer

- Penetapan 24 Oktober 2026: **implementator Claude, auditor Codex** untuk workstream keamanan PSB (dicatat di `AGENTS.md` dan `docs/redesain-web/keputusan.md`).
- Branch `claude/perbaikan-keamanan-psb` dari `origin/main` `a116e0f`. Baseline itu sudah memuat audit Tahap 7 `a04c6e8` (PR #73) dan catatan AX (PR #74/#75). Merge Tahap 7 terbukti dari riwayat `origin/main`; **deploy cPanel tidak dapat diperiksa dari repositori dan tidak dianggap terlaksana**.
- Login Portal Pendaftar tetap NISN + No. HP wali (AP). Jalur berkas berpenjaga disiapkan untuk pratinjau Tahap 8 (AG) tanpa redesain.
- Dipertahankan: data historis, aturan penerimaan, komponen dan katalog biaya, alamat lama. Tidak dikerjakan: biaya MI/RA (AN), pemindahan pembiayaan ke portal (P-7), mobile, modul rapor, migrasi.

Repositori ini publik. Rincian teknis temuan (parameter, pola nama berkas, langkah reproduksi) **tidak** ditulis di sini; rinciannya sudah disampaikan langsung kepada Human Developer pada 22 Oktober 2026.

## Masalah yang terbukti dan ditangani

Dibuktikan dengan pemeriksaan kode baseline `a116e0f` dan dicocokkan dengan daftar temuan privat 22 Oktober. Kelompok umum:

| # | Kelompok | Ditangani di |
|---|---|---|
| 1 | Halaman rincian biaya, kwitansi, dan bukti pendaftaran tidak memeriksa siapa yang membukanya (isolasi antarpendaftar) | `biaya_psb.php`, `cetak_biaya_psb.php`, `cetak_bukti.php`, `App\Psb\PsbAkses` |
| 2 | Penyimpanan rincian biaya mempercayai nilai dari peramban, belum memeriksa hak, dan belum terkunci setelah lunas | `biaya_psb.php`, `App\Psb\BiayaPsb` |
| 3 | Masuk portal belum memiliki CSRF, regenerasi sesi, pembatasan percobaan, dan penolakan kredensial tidak lengkap | `auth_pendaftar.php`, `login_pendaftar.php`, `App\Psb\PendaftarSesi` |
| 4 | Sesi pendaftar tidak diperiksa ulang terhadap data terkini | `App\Psb\PendaftarSesi::aktif()` dipakai semua halaman pendaftar |
| 5 | Unggahan berkas belum memeriksa sesi dan isi berkas, batas ukuran belum ditegakkan server, dan berkas disimpan di folder web | `proses_upload_berkas.php`, `App\Psb\BerkasPsb` |
| 6 | Folder berkas pendaftar belum dilindungi dari akses langsung | `.htaccess`, `berkas_psb/.htaccess`, `lihat_berkas_psb.php` |
| 7 | Data pendaftar belum di-escape pada halaman PSB publik | halaman PSB publik |
| 8 | Mutasi web PSB publik belum ber-CSRF/POST, belum divalidasi server, dan belum memakai query terikat | `proses_psb.php`, `psb.php`, `portal_pendaftar.php`, `logout_pendaftar.php`, `App\Psb\PendaftarData` |

Sisi admin PSB (status, migrasi, hapus, pembayaran, ekspor, impor) **sudah** POST + CSRF + query terikat sejak perbaikan GET-mutasi V1 dan redesain Tahap 3; diperiksa ulang dan tidak diubah, kecuali tautan berkas di panel Pendaftar yang kini memakai jalur berpenjaga. Regresi keduanya dijalankan ulang (lihat Pengujian).

## Perubahan

| Berkas | Perubahan |
|---|---|
| `app/Psb/PendaftarSesi.php` (baru) | Masuk NISN + No. HP: query terikat, perbandingan waktu-tetap, No. HP kosong tidak pernah cocok, `session_regenerate_id(true)`. Pembatasan percobaan gagal 8 per NISN dan 20 per IP dalam 15 menit, dihitung dari `audit_logs` (aksi `psb.login_failed`, NISN hanya disimpan sebagai hash). Sidik sesi (id + NISN + No. HP) diperiksa ulang setiap permintaan; umur sesi maksimum 12 jam. Hak cetak bukti bagi peramban yang baru mendaftar (6 jam, satu pendaftaran). Kunci sesi lama dipertahankan. |
| `app/Psb/PsbAkses.php` (baru) | Hak membuka data satu pendaftar: admin (tanpa kewajiban ganti password), pendaftar pemilik yang masuk, atau — khusus bukti — peramban pendaftar baru. Akses ditolak 403 tanpa membedakan ada/tidaknya nomor bagi non-admin. |
| `app/Psb/BerkasPsb.php` (baru) | Unggahan baru: ekstensi JPG/PNG/PDF, ukuran 1 B–2 MB, jenis isi `finfo`, gambar sah (`getimagesize`) atau PDF berawalan `%PDF-`; disimpan di `storage/private/psb/` bernama `<jenis>_<32 hex acak>.<ext>` (0600), folder dibuat 0700 dengan `.htaccess` penolak. Pembacaan: nama dari kolom basis data, `basename` + pola tetap + `realpath` di dalam folder privat atau folder lama `berkas_psb/`. |
| `app/Psb/BiayaPsb.php` (baru) | Katalog biaya **disalin apa adanya** dari `biaya_psb.php` (paritas 36 kombinasi diuji terhadap baseline). Simpan menghitung kategori, harga wajib, dan total di server; pendaftar tetap memilih komponen wajib (AJ) dan nominal pilihan (0–100 juta). Tagihan Lunas tidak diubah dari halaman ini. |
| `app/Psb/PendaftarData.php` (baru) | Validasi server pendaftaran dan ubah biodata (wajib, panjang kolom, L/P, unit dari daftar tetap `PsbAdmin::UNIT`, tanggal sah tidak di masa depan, NISN/NIK angka, No. HP 8–15 angka, NISN unik) dan query terikat; nomor `PSB-tahun-acak` dengan coba ulang bila bertabrakan. Ubah biodata hanya baris milik sesi. |
| `lihat_berkas_psb.php` (baru) | Jalur berkas berpenjaga, GET/HEAD saja: pendaftar pemilik (`?jenis=`) atau admin (`?id=&jenis=`); anonim 401, staf lain/pendaftar lain 403. Header `no-store`, `nosniff`, `no-referrer`; isi di luar JPG/PNG/PDF hanya sebagai lampiran `application/octet-stream`; `&unduh=1` untuk lampiran. Siap dipakai pratinjau Tahap 8. |
| `auth_pendaftar.php`, `login_pendaftar.php`, `logout_pendaftar.php` | POST + CSRF; pesan dari daftar tetap; keluar lewat POST (GET menampilkan tombol konfirmasi). |
| `portal_pendaftar.php`, `proses_upload_berkas.php` | Penjaga sesi per permintaan, CSRF, `PendaftarData`/`BerkasPsb`, keluaran di-escape. Pesan tetap kotak alert seperti sebelumnya (redesain Tahap 8). |
| `biaya_psb.php`, `cetak_biaya_psb.php`, `cetak_bukti.php` | Hak akses `PsbAkses`, query terikat, escape, PRG ke kwitansi (303). Alamat dan tampilan tetap; kotak cari hanya untuk admin. |
| `psb.php`, `proses_psb.php` | Sesi aplikasi sebelum keluaran, CSRF, validasi server, pesan galat per isian, nomor di alamat tidak dicetak mentah. |
| `admin/admin_data.php` | Tautan "Lihat" berkas → `lihat_berkas_psb.php`. Pratinjau gambar (AG) tetap belum dipasang. |
| `.htaccess`, `berkas_psb/.htaccess` (baru) | Folder `berkas_psb/` ditolak web (aturan akar + lapis kedua di folder). |
| `.gitignore`, `storage/private/psb/.gitignore` (baru) | Berkas pendaftar baru tidak dapat ikut repositori. |
| `tests/keamanan_psb_*`, `tests/browser/uji-keamanan-psb.mjs` (baru) | Uji statis, HTTP, browser, dan server Apache + php-fpm uji. |

Tidak ada perubahan skema, `app/Auth`, REST API, mobile, atau modul rapor.

## Penafsiran untuk auditor

1. **Rincian biaya hanya untuk admin dan pendaftar pemilik.** Halaman tetap di `biaya_psb.php` (bukan dipindah ke portal, P-7 belum diputuskan). Panitia yang dulu membuka halaman ini tanpa masuk kini perlu masuk sebagai admin, karena peran panitia PSB belum diberi menu (keputusan I).
2. **Tagihan Lunas terkunci** dari halaman biaya; koreksi lewat "Batal lunas" beralasan yang sudah ada di admin.
3. **Validasi server** dapat menolak nilai lama yang tidak berbentuk angka (mis. NIK `-` hasil impor) saat pendaftar menyimpan biodata; formulir lama memang sudah mewajibkan angka di peramban.
4. **Sesi pendaftar kini memakai cookie sesi aplikasi** (`SESSION_NAME`, `HttpOnly`, `SameSite=Lax`), bukan `PHPSESSID` dari `session_start()` mentah. Pendaftar yang sedang masuk saat deploy perlu masuk ulang.
5. **Berkas lama tidak dipindah atau dihapus**; unggahan pengganti tidak menghapus berkas sebelumnya (perilaku lama).
6. **Pembatasan per IP** dapat mengenai banyak pendaftar di balik satu jaringan (NAT) bila ada 20 kegagalan dalam 15 menit; sama dengan pola login staf.

## Langkah rilis manual (wajib saat deploy)

`bin/deploy-cpanel.sh` **tidak menyalin** `.htaccess` akar maupun isi `berkas_psb/`. Tanpa langkah 1–2, berkas pendaftar lama di hosting tetap dapat dibuka langsung walaupun kode baru sudah terpasang.

1. Tambahkan aturan `RewriteRule ^berkas_psb(?:/|$) - [F,L,NC]` ke `.htaccess` akar produksi (lihat `.htaccess` repositori), dan pastikan aturan `storage` yang sudah ada tetap ada.
2. Salin `berkas_psb/.htaccess` ke folder `berkas_psb/` produksi.
3. Pastikan PHP dapat menulis `storage/private/` (folder `psb/` dibuat otomatis, 0700, dengan `.htaccess` penolak).
4. Uji: membuka satu berkas lama langsung di `/berkas_psb/...` harus 403; tombol "Lihat" di PSB › Pendaftar harus membuka berkas; satu pendaftar uji dapat masuk, mengunggah, dan membuka rincian biayanya.
5. Sampaikan kepada pendaftar yang sedang aktif bahwa mereka perlu masuk ulang.

Enam berkas pendaftar yang sudah terlacak di `berkas_psb/` (sejak sebelum workstream ini) **tidak dihapus**. Menghapusnya dari pohon kerja dan riwayat Git tetap keputusan Human Developer; penghapusan riwayat tidak dapat dibatalkan. `.gitignore` kini mencegah berkas baru ikut terlacak.

## Pengujian

DB uji terpisah `test_keamanan_psb_test` dan `test_kpsb_gm_test` (salinan sandbox `.env` + migrasi 001–023 + fixture sintetis; tanpa data produksi). Aplikasi dilayani **Apache 2.4 + php-fpm 8.4** lokal dari salinan sumber di luar checkout (`tests/keamanan_psb_server.sh`), sehingga `.htaccess` ikut diuji. Seluruh pendaftar uji sintetis (NISN `99000000xx`/`98…`).

| Bukti | Hasil | Berkas |
|---|---|---|
| `tests/keamanan_psb_static.php` (termasuk paritas katalog biaya 36 kombinasi terhadap `a116e0f`) | 82/0 | `uji-statis.txt` |
| `tests/keamanan_psb_http.php` (A anonim, B masuk, C pembatasan, D isolasi, E sesi lama, F unggahan, G jalur berkas, H biaya, I pendaftaran, J admin/escape, K log) | 94/0 | `uji-http.txt` |
| Kontrol mutasi: aturan blokir folder dihapus dan pemeriksaan pemilik dimatikan pada salinan | 4 uji terkait gagal seperti seharusnya; salinan dipulihkan | — |
| `tests/browser/uji-keamanan-psb.mjs`, Chromium 375/768/1280 px | 42/0 | `uji-browser.txt`, `tangkapan/` |
| Regresi GET-mutasi V1 HTTP + audit Codex | 91/0, 84/0 | `regresi.txt` |
| Preservasi cleanup + audit cleanup (sebelum dan sesudah GET-mutasi) | 157/0, 128/0, 157/0 | `regresi.txt` |
| Redesain Tahap 3 HTTP (PSB admin, pembayaran, rekap) | 167/0 | `regresi.txt` |
| Seluruh `tests/*_static.php` | lulus kecuali 3 baseline lama yang sudah tercatat | `regresi.txt` |
| Log galat PHP kedua server uji | kosong | — |

## Batas pengujian (jujur)

- Bukan hosting cPanel: `admin/.htaccess` memakai handler PHP cPanel yang tidak ada di Apache lokal, sehingga server uji memakai handler php-fpm untuk folder itu; `TraceEnable off` diatur di konfigurasi uji. Smoke cPanel/produksi belum dijalankan.
- Masalah dibuktikan dengan pemeriksaan kode dan temuan privat, bukan dengan menjalankan percobaan serangan terhadap kode baseline.
- Umur sesi 12 jam dan hak bukti 6 jam diuji lewat kode, tidak lewat perjalanan waktu. Pembatasan per IP diuji dengan baris audit sintetis.
- Browser: pustaka CDN (flatpickr, AOS, ikon web font) diganti stub/aset lokal dan API wilayah memakai fixture; hanya Chromium.
- Tidak diuji: mobile (tidak berubah), modul rapor, unggahan sangat besar di atas `post_max_size` hosting.
- Di luar cakupan dan tetap terbuka: temuan situs publik non-PSB dari daftar privat 22 Oktober, catatan lama audit GET-mutasi (tagihan yatim saat hapus pendaftar, folder gambar berita), dan berkas data lama yang terlacak di repositori. Perlu keputusan/tahap terpisah.
- Repositori publik: diff branch ini memperlihatkan bagian yang diperbaiki sebelum produksi terpasang. Deploy (dengan langkah manual di atas) sebaiknya tidak ditunda lama setelah merge.

## Pemulihan

Revert commit implementasi; tidak ada migrasi atau perubahan skema. Catatan:

- Unggahan setelah deploy tersimpan di `storage/private/psb/` dan kolomnya berisi nama baru. Kode lama tidak dapat menampilkannya (tautan lama mengarah ke `berkas_psb/`). Jangan hapus folder itu saat revert; bila perlu kode lama, salin berkasnya ke `berkas_psb/` dengan nama yang sama.
- Aturan `.htaccess` manual dapat dibiarkan; kode lama di admin tidak lagi dapat membuka berkas lama selama aturan aktif, jadi cabut aturan hanya bila benar-benar kembali ke kode lama — dengan konsekuensi folder berkas kembali terbuka.
- Revert mengembalikan seluruh celah di atas; gunakan hanya bila ada gangguan serius.

## Reproduksi

```sh
# DB uji (wajib test_*_test): salinan sandbox .env, migrasi
mysql -e 'CREATE DATABASE test_keamanan_psb_test'   # lalu impor dump sandbox _test
DB_NAME=test_keamanan_psb_test php bin/migrate.php up
# Server Apache + php-fpm dari salinan sumber (folder privat di luar checkout)
KPSB_DIR=/private/tmp/<privat> bash tests/keamanan_psb_server.sh start
php tests/keamanan_psb_static.php
KEAMANAN_PSB_RUN_TESTS=1 KPSB_URL=http://127.0.0.1:8971 KPSB_WEB_ROOT=/private/tmp/<privat>/web php tests/keamanan_psb_http.php
BASE_URL=http://127.0.0.1:8971 KPSB_BROWSER=1 node tests/browser/uji-keamanan-psb.mjs
KPSB_DIR=/private/tmp/<privat> bash tests/keamanan_psb_server.sh stop
```

Regresi GET-mutasi/cleanup: DB kedua dengan `tests/cleanup_legacy_fixture.php` dan `bin/v2_phase1_backfill.php`, server kedua (`KPSB_PORT=8975 KPSB_DB=… IZIN_LEGACY_ENABLED=true`), lalu suite sesuai `docs/perbaikan-get-mutasi-v1/README.md`. Suite HTTP menolak berjalan bila URL bukan `127.0.0.1`, salinan berisi `.git`, atau DB tidak bernama `test_*_test`; baris sintetis dibiarkan di DB uji, berkas sintetis di salinan dihapus.

## Handoff ke auditor Codex

> Audit implementasi perbaikan keamanan PSB yang dibuat Claude pada branch `claude/perbaikan-keamanan-psb`. Baca AGENTS.md, `docs/keamanan-psb/README.md`, dan `docs/redesain-web/` (AG, AJ, AN, AP, P-7), periksa seluruh commit di atas `a116e0f`, jalankan pengujian secara independen pada DB `test_*_test` sintetis termasuk tampilan 375px, 768px, dan 1280px, dan lakukan koreksi terarah jika diperlukan. Commit dan push hasil audit, lalu berhenti. Jangan merge, deploy, menjalankan migrasi produksi, atau memulai Tahap 8.
