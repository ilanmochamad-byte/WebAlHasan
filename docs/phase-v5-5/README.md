# PRD V5 Fase 5 — Laporan, Regresi, dan Kesiapan Rilis

**Status (24 Oktober 2026):** diimplementasikan Claude Code, **menunggu audit independen Codex**. Belum merge, belum deploy, belum migrasi produksi, tidak ada rilis aplikasi. Seluruh bukti di bawah berasal dari salinan DB lokal berfixture sintetis, Chromium, dan Expo **web**. Bukti tidak mencakup hosting cPanel, perangkat fisik, build native baru, maupun Push nyata, sehingga **produksi tidak dinyatakan lulus**.

| Butir | Nilai |
| --- | --- |
| Workstream | PRD V5 Fase 1–5 kesehatan santri — implementator Claude Code, auditor Codex (`AGENTS.md`) |
| Izin | Audit Codex Fase 4 (`40ed077`) **belum meluluskan** seluruh kriteria (runtime iOS terbaru, ketukan Push provider, cold start non-dev). Human Developer sudah merge PR #83/#14 dan pada 24 Oktober 2026 **mengizinkan Fase 5 dimulai dengan butir itu tetap terbuka** (dicatat di `AGENTS.md`). Ini bukan pernyataan Fase 4 lulus. |
| Branch web | `claude/prd-v5-fase-5` dari baseline audit Fase 4 `40ed0770148fd51aeba487380bb1d8ef07808ae0`. Diverifikasi: `origin/main` `5ea50ec` (merge PR #83) dan `git diff 40ed077 origin/main` kosong. Tanpa worktree. |
| Branch mobile | `~/alhasanApps` `claude/prd-v5-fase-5` dari `33522f7` (audit Fase 4). `origin/main` `9f913e2` (merge PR #14), `git diff 33522f7 origin/main` kosong. |
| Migrasi | **Tidak ada migrasi baru** (026 tetap terakhir). Berkas rollback 024–026 mendapat penjaga data SQL. |
| Lingkungan uji | PHP 8.4.14, MariaDB 12.3.2 lokal, Node v26.7.0, Chromium Playwright, Expo SDK 57 web dev server. DB `test_*_test` dengan fixture sintetis (penanda isi klinis `V5F5RHS`). Tidak ada perangkat fisik, tidak ada Push nyata. |

## Yang dikerjakan

### 1. Laporan kunjungan, rujukan, dan tindak lanjut sesuai hak (persyaratan 1)

- `App\Kesehatan\LaporanService` + `LaporanRepository`. Satu layanan dipakai web `portal/kesehatan_laporan.php`, API `GET /api/v1/v5/kesehatan/laporan` (`docs/api-v1.md`), dan layar aplikasi `src/app/kesehatan/laporan.tsx`, sehingga angka ketiganya identik.
- Hak dihitung ulang tiap panggilan lewat `KesehatanService::akses()`:
  - **petugas dan admin**: seluruh santri, termasuk Draf;
  - **pembimbing/murobi terkait**: hanya kunjungan Tercatat santri dalam cakupan penugasan aktif. Penyaring baris yang sama dengan daftar/detail (`KesehatanRepository::saringBinaan`) dipasang pada **setiap** angka, daftar, dan pilihan kelas. Angka Draf dikirim `null` (disembunyikan, bukan nol palsu);
  - **admin** juga mendapat bagian **Pengawasan**: koreksi (jumlah, beralasan, wajib-alasan tanpa alasan yang harus 0), koreksi terbaru (pelaku, waktu, versi sebelum → sesudah, ada/tidak alasan; teks alasan tidak ditampilkan), aktivitas per petugas, akses rincian dari log akses, peristiwa ringkasan wali, dan status antrean notifikasi kesehatan;
  - **wali, pengurus/guru tanpa penugasan, akun nonaktif**: 403. Wali tetap memakai proyeksi ringkasan Fase 3.
- Isi laporan per periode (bawaan awal bulan s.d. hari ini, paling panjang 366 hari) dan kelas saat ini:
  - kunjungan (total, Tercatat, Draf, santri unik, dikoreksi, dengan obat/rujukan/tindak lanjut), pemberian obat aktif, rujukan (menunggu/diketahui), tindak lanjut (terbuka/lewat target/selesai), ringkasan wali (berlaku, ditarik, Tercatat belum diterbitkan);
  - per kelas dan per bulan;
  - **daftar tertunda tanpa batas periode**: rujukan menunggu hasil dan tindak lanjut belum selesai, satu baris per kunjungan, terlama/target terdekat lebih dahulu, paling banyak 50, menaut ke detail kunjungan yang tercatat aksesnya.
- **Tanpa isi klinis.** Repository tidak memilih keluhan, catatan, tindakan, nama obat, petunjuk, tujuan/hasil rujukan, uraian tindak lanjut, teks ringkasan, atau teks alasan. Rincian dihitung lewat `IN`/`EXISTS`, bukan JOIN, sehingga kunjungan dengan beberapa obat tetap dihitung sekali. Laporan hanya membaca: tidak menulis log akses maupun audit.
- Kartu angka web menaut ke daftar kunjungan (`kesehatan.php`) dengan saringan yang sama, sehingga angka dapat ditelusuri. Daftar kunjungan mendapat tombol **Laporan**. Peta menu tidak berubah, sehingga tidak ada oracle menu baru. Halaman berjalan tanpa JavaScript (formulir GET).
- Aplikasi: tombol "Laporan layanan klinik" di pintu kesehatan. Periode Bulan ini/Bulan lalu/30 hari/Atur, kelas, angka, daftar tertunda (ketuk → detail), dan pengawasan admin. Layar berada di navigator kesehatan, sehingga gate tangkapan layar Fase 4 juga berlaku. Respons hanya di memori layar.

### 2. Kesiapan rilis (persyaratan 4)

| Berkas | Isi |
| --- | --- |
| `manifest-migrasi.json` | SHA-256 26 migrasi 001–026, rollback 024–026, `composer.lock`, `package-lock.json`/`app.json` mobile; baseline. Dibuat `php bin/v5_manifest.php --mobile=…`. |
| `bin/v5_preflight.php [manifest-privat] [--rilis=manifest]` | Prasyarat (023, role dasar, tabel rujukan FK/notifikasi), status 024–026 berurutan, tidak ada migrasi > 026, **SHA-256 migrasi dan rollback = manifest rilis**; menulis manifest privat: jumlah baris semua tabel lama + sidik jari (hash) ID/status santri, wali, relasi. |
| `bin/v5_verify.php [manifest-privat]` | Verifier Fase 1–3 + lintas fase: tepat satu audit pencatatan dan (versi − 1) audit koreksi per kunjungan dengan pelaku dan versi sebelum → sesudah; rentang versi rincian; 0 kunjungan/versi/peristiwa/notifikasi efektif ganda; 0 WhatsApp V5; ringkasan hanya dari versi Tercatat; **laporan = kueri sumber**; sidik jari santri/wali/relasi. Hanya membaca. |
| `bin/v5_backup_restore_drill.php` | Latihan backup → restore lewat klien `mariadb` → verifier + manifest → hash isi tabel sumber = pulihan → rollback berisi data ditolak. Hanya DB `_test`, `V5_DRILL=1`, menolak produksi. |
| `runbook-rilis-pemulihan.md` | Urutan rilis, backup/restore point, worker/cron, rollback aman, pemantauan. |
| `checklist-smoke.md` | 18 langkah smoke cPanel/aplikasi dengan data sintetis; **seluruh kolom produksi "Menunggu pelaksanaan"**. |
| `perintah-regresi.sh` | Seluruh regresi V1–V5 berbasis DB salinan, berurutan. |

### 3. Temuan selama Fase 5 (diperbaiki)

| # | Temuan | Perbaikan dan bukti |
| --- | --- | --- |
| F5-1 | **Rollback 024, 025, 026 menjalankan `DROP TABLE` tanpa syarat.** Penjaganya hanya skrip preflight terpisah, sehingga `bin/migrate.php rollback` tanpa preflight akan menghapus catatan klinis, rincian, ringkasan wali, dan jejak aksesnya. Rollback V4 022 sudah berpenjaga SQL. | Setiap berkas rollback V5 kini diawali tabel `TEMPORARY` dengan `CHECK (jumlah = 0)` atas seluruh tabel fasenya (026 juga notifikasi V5). Dengan data, Migrator berhenti sebelum DROP apa pun dan migrasi tetap tercatat; tanpa data, rollback berjalan seperti sebelumnya. Tidak ada tabel penjaga tersisa. Bukti: `bukti/rilis.txt` S-1, S-3, S-4. Uji migrasi lama hanya merollback tabel kosong, jadi perilakunya tetap. |
| F5-2 | `bin/v5_phase1_verify.php` menghitung kunci asing **seluruh** tabel `kesehatan_*` dan mengharap 18, sehingga gagal palsu sejak 025/026 terpasang (46). | Dibatasi pada lima tabel 024 (label dan angka 18 tetap). Verifier gabungan kini lulus pada DB 024–026. |
| F5-3 | Saat ditulis, `bin/v5_preflight.php --rilis` menggabungkan migrasi dan rollback dengan operator `+` larik PHP (kunci angka bertumpuk), sehingga SHA rollback tidak pernah diperiksa. | Ditemukan oleh uji S-2 sebelum commit; diganti `array_merge`. S-2 membuktikan satu SHA rollback yang diubah → preflight gagal. |
| F5-4 | Penanda "lewat" pada rujukan menunggu tidak bermakna (tanggal rujukan bukan target); pilihan kelas mengikuti periode lama sehingga kelas tidak dapat dipilih bersama periode baru. | `lewat` hanya untuk tindak lanjut; pilihan kelas dari kunjungan yang boleh dilihat tanpa batas periode (tetap dalam cakupan binaan). Uji O-1 dan browser G-B. |

## Kriteria penerimaan Fase 5 → bukti

| Kriteria | Bukti | Status |
| --- | --- | --- |
| Laporan kunjungan/rujukan/tindak lanjut = sumber pada fixture sintetis, tanpa menggandakan kunjungan berbanyak obat | `bukti/integrasi.txt` O-1 (nilai fixture: 6 kunjungan, 8 pemberian obat, K1 tiga obat = satu kunjungan, obat dibatalkan tidak dihitung), O-2 (8 angka = kueri sumber terpisah; total = daftar per tujuh peran); `bin/v5_verify.php` (laporan seluruh waktu = sumber) pada setiap DB regresi | Terbukti lokal |
| 0 kebocoran catatan internal/ringkasan wali lain lintas web/API/aplikasi | O-3/O-4/O-5 (binaan, draf, kelas luar cakupan, pencabutan, perpindahan kamar, 0 penanda dari 30 kB keluaran semua peran); `bukti/http.txt` Q-A…Q-D (web/API, sesi & token lama, tautan detail hanya cakupan); `bukti/browser.txt` G-A…G-D; `bukti/aplikasi-expo-web.txt` H-A…H-D; regresi akses Fase 1–4 | Terbukti lokal; aplikasi hanya Expo web |
| Koreksi dan publikasi ulang memiliki pelaku, alasan, waktu, versi sebelum/sesudah; 0 pembaruan tanpa riwayat | O-7 (pengawasan admin), `bin/v5_verify.php` lintas fase (audit per versi dengan pelaku dan versi, Ganti/Tarik beralasan, revisi = jumlah peristiwa) pada DB sesudah suite konkurensi | Terbukti lokal |
| 0 kunjungan, versi, notifikasi efektif ganda pada idempotensi/konkurensi | O-8 (6 proses kunci sama → 1 kunjungan + 5 replay; 6 koreksi versi sama → 1 versi + 5×409; 6 penerbitan → 1 peristiwa, InApp tepat 3 untuk 3 wali), transaksi gagal di tengah → 0 baris berubah dan kunci dapat diulang; verifier pada DB regresi Fase 1–3 sesudah suite konkurensinya (`bukti/verifier-regresi.txt`) | Terbukti lokal |
| Regresi relevan V1–V4 lulus; kegagalan historis/uji belum dijalankan dicatat terpisah | Bagian **Regresi** dan **Oracle historis** di bawah | Lihat tabel |
| Manifest, backup, restore ke salinan, verifier, rollback, worker/cron, checklist smoke tersedia; hasil cPanel diberi status sesuai bukti | `manifest-migrasi.json`, `bukti/rilis.txt` S-1…S-4, `bukti/worker-cron.txt`, runbook, checklist (produksi: **Menunggu pelaksanaan**) | Tersedia; **cPanel belum dijalankan** |
| Auditor menyatakan kriteria wajib terpenuhi sebelum merge | — | **Menunggu audit Codex** |

## Hasil pengujian Fase 5 (lokal, kode final)

| Rangkaian | Hasil | Berkas |
| --- | --- | --- |
| Statis `tests/v5_phase5_static.php` | 58/0 | `bukti/statis.txt` |
| Integrasi + konkurensi + transaksi gagal + verifier `tests/v5_phase5_integration.php` | 95/0 (kontrol mutasi penggandaan obat: 83/12, `bukti/kontrol-mutasi.txt`) | `bukti/integrasi.txt` |
| HTTP web + API `tests/v5_phase5_http.php` | 57/0, log server 0 galat PHP | `bukti/http.txt` |
| Kesiapan rilis: migrasi 024–026 + rollback kosong pada salinan Tahap 7, manifest/`--rilis`, penjaga SQL, latihan backup/restore `tests/v5_phase5_rilis.php` | 22/0 | `bukti/rilis.txt` |
| Browser web 375/768/1280 + tanpa JS `tests/browser/uji-v5-fase5.mjs` | 61/0, log server 0 galat PHP | `bukti/browser.txt`, `tangkapan/laporan-*.png` (diperiksa visual) |
| Aplikasi Expo **web** 375 px + API/DB nyata `tests/browser/uji-v5-fase5-app.mjs` | 36/0, 0 permintaan ke luar localhost | `bukti/aplikasi-expo-web.txt`, `tangkapan/app-laporan-*.png` |
| Mobile TypeScript / lint / unit | exit 0 / exit 0 / 29/0 | repo mobile `docs/phase-v5-5/mobile-*.txt` |
| Worker/cron: `--status`, `--uji-coba`, `--kanal=push` (Push OFF), `v2_phase5_cron_check.php` | 0 request provider; WhatsApp tetap OFF | `bukti/worker-cron.txt` |

Cara mengulang (jalankan skrip DB **berurutan**):

```bash
php tests/v5_phase5_static.php
bash tests/v5_phase5_db.sh && V5_RUN_INTEGRATION=1 DB_NAME=test_v5_f5_test php tests/v5_phase5_integration.php
V5F5_DB=test_v5_f5_http_test bash tests/v5_phase5_db.sh && V5F5_DB=test_v5_f5_http_test php tests/v5_phase5_http.php
V5_RUN_RILIS=1 php tests/v5_phase5_rilis.php
V5F5_DB=test_v5_f5_browser_test bash tests/v5_phase5_db.sh
DB_NAME=test_v5_f5_browser_test php -S 127.0.0.1:8997 tests/redesain_web_tahap1_router.php &
(cd tests/browser && BASE_URL=http://127.0.0.1:8997 OUT_DIR=../../docs/phase-v5-5/tangkapan node uji-v5-fase5.mjs)
bash docs/phase-v5-5/perintah-regresi.sh
```

Aplikasi Expo web: jalankan dari **salinan kerja tanpa `.env.local`** (lihat "Kesalahan penyiapan uji"), dengan `EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8982/api/v1 npx expo start --web --port 8099 --clear`. Pastikan bundel tidak memuat host produksi, lalu jalankan `tests/browser/uji-v5-fase5-app.mjs` (runner memblokir dan mencatat setiap permintaan ke luar localhost).

## Regresi V1–V5

Dijalankan **berurutan** lewat `perintah-regresi.sh` pada DB salinan baru (ringkasan `bukti/regresi/ringkasan.txt`, berkas per suite di `bukti/regresi/`).

| Rangkaian | Hasil | Catatan |
| --- | --- | --- |
| Seluruh 34 suite statis | 22 exit 0 · 10 exit 1 · 2 exit 77 | Seluruh kegagalan adalah oracle historis (tabel di bawah). `v5_phase5_static` 58/0, `v2_phase5_static` 304/0, `navigasi_ikon_static` lulus. |
| V5 Fase 5 integrasi / HTTP / rilis | 95/0 · 57/0 · 22/0 | |
| V5 Fase 4 HTTP (API aplikasi, penugasan, pemilih kelas) | 43/0 | |
| V5 Fase 3 integrasi (konkurensi, worker Push palsu) / HTTP / probe audit Codex / migrasi–rollback 026 | 82/0 · 70/0 · 26/0 · 26/0 | Migrasi 026 lulus dengan penjaga SQL baru (rollback tabel kosong tetap berjalan). |
| V5 Fase 2 integrasi / HTTP / probe audit Codex / migrasi 025 | 134/0 · 87/0 · 28/0 · 23/6 | 23/6 identik baris demi baris dengan bukti Fase 3 (oracle "025 terakhir"). |
| V5 Fase 1 integrasi / HTTP | 71/4 · 79/3 | Identik dengan bukti Fase 2–4 (oracle pembaca binaan lama). |
| Fondasi penugasan | 161/0 | |
| V2 Fase 4 integrasi (notifikasi, worker, backoff) | 124/0 | |
| V2 Fase 5 integrasi (receipt, laporan) / kontrol antrean ditunda | 143/0 · 143/0 | Kali ini lulus tanpa kontrol; kontrol tetap dijalankan. |
| HTTP Tahap 2 (navigasi/peran/Beranda) | 187/4 | Empat oracle menu BB/BC/BD, identik dengan bukti Fase 3. |
| HTTP Tahap 3 (halaman lama ke kerangka) | 166/1 | Oracle historis "AG belum dipasang" sejak Tahap 8. |
| HTTP Tahap 5 (pembinaan V3) | 103/3 pada run utama; 100/6 pada ulangan | **Kontrol baseline:** salinan `git archive 40ed077` dan cabang Fase 5 dijalankan berselang-seling (DB dibangun ulang tiap run, 21.09–21.11 WIB) → keduanya **100/6 dengan enam baris gagal identik** (pengirim tanpa pembimbing, ringkasan admin, CSV, chip status, catat panel, POST murobi). Bergantung waktu/fixture modul pembinaan yang tidak disentuh Fase 5. Bukan regresi Fase 5, tetapi terbuka untuk ditelusuri terpisah. `bukti/regresi/redesain-tahap5-http-kontrol-baseline.txt`. |
| HTTP Tahap 6 sesudah 026 → 025 → 024 dilepas (preflight + penjaga SQL, tabel kosong) | 14/0 · 132/0 | Rantai rollback kosong tetap berjalan dengan penjaga baru. |
| HTTP Tahap 7 (penilaian & rapor V4) | 122/0 | |
| `bin/v5_verify.php` pada 12 DB regresi V5 berisi data sesudah suite konkurensi/transaksi/HTTP-nya | 12 × 20/0 | `bukti/verifier-regresi.txt` (termasuk DB Fase 3 dengan 13 peristiwa ringkasan dan 36 notifikasi V5). |

**Tidak dijalankan ulang** (dicatat, bukan disembunyikan): browser web Fase 1–3, browser kerangka sembilan akun (Tahap 1), uji Android/iOS, suite V3/V4 yang mengunci nama DB tetap (`webalhasan_v3_phase1_test`, `webalhasan_v4_fase2_test`) dan runner V4 Fase 5 yang memakai berkas env privat, suite alumni/penempatan/perapihan/kredensial/keamanan PSB HTTP. Fase 5 hanya menambah tombol **Laporan** pada `portal/kesehatan.php` (diuji browser G-B) dan tidak mengubah kerangka, navigasi, atau modul lain.

## Oracle historis yang gagal (tidak diubah, tidak dilonggarkan)

| Suite | Kegagalan | Sebab |
| --- | --- | --- |
| `v5_phase1_static` 44/4, integrasi 71/4, HTTP 79/3 | 024 terakhir, tanpa koreksi/Draf, pembaca binaan lama | Isi sah Fase 2–3; identik dengan audit Fase 4. |
| `v5_phase2_static` 62/1, migrasi 23/6 | 025 terakhir | Migrasi 026 (Fase 3). Identik dengan audit Fase 4. |
| `v5_phase3_static` 70/1 | Layanan/repository klinis tidak berubah sejak baseline Fase 2 | Pemilih per kelas Fase 4 (permintaan Human Developer); identik dengan audit Fase 4. |
| `v2_phase4_static` 287/1 | Teks literal `router.push({ pathname: '/izin/[id]'` di konteks notifikasi mobile | Gagal sejak commit Fase 4 mobile `38e1850` (ee6621a: ada; 38e1850/33522f7/b593d21: tidak). Deep link izin kini lewat `ruteTujuan()` yang tetap mengembalikan `/izin/[id]`; Fase 5 tidak menyentuh berkas itu. |
| `penugasan_static` 281/2 | Tujuh jenis penugasan | Jenis `kesehatan` (Fase 1). |
| `keamanan_psb_static` 82/1, `redesain_web_tahap3_static` 151/1, HTTP Tahap 3 166/1 | AG pratinjau belum dipasang | Pratinjau dipasang Tahap 8. |
| `redesain_web_tahap4_static` 95/1, `tahap7` 100/2, `tahap8` 78/2 | `database/`, `api/`, `bin/`, `PenugasanJenis` tidak berubah sejak baseline tahap | Migrasi/API/alat V5. |
| HTTP Tahap 2 187/4 | Peta menu admin/pembimbing/murobi/orang tua | Keputusan BB/BC/BD. |
| `v3_phase2/3_static` | exit 77 (dilewati) | Memerlukan DB V3 bernama tetap. |

`redesain_web_tahap6_static` kini lulus seluruhnya, padahal pada bukti Fase 3 gagal 1 ("Navigation dan app/Auth tidak diubah Tahap 6"). Oracle itu membandingkan dengan baseline tahapnya; Fase 5 tidak mengubah `Navigation` atau `app/Auth`. Tidak ada oracle yang diubah atau dilonggarkan.

## Penafsiran yang perlu ditinjau auditor/Human Developer

- **S-1 Hak laporan.** Laporan mengikuti hak baca kesehatan yang ada (petugas/admin seluruh santri, pembimbing/murobi hanya binaan Tercatat). Wali tidak mendapat laporan agregat; ringkasan wali Fase 3 sudah melayani kebutuhannya. Pengawasan (koreksi, akses, publikasi, notifikasi, per petugas) hanya admin, sesuai PRD §3.7 "Admin dapat meninjau aktivitas layanan klinik dan jejak koreksi/akses".
- **S-2 Pembaca binaan melihat angka "Dikoreksi" dan status ringkasan wali** santri binaannya. Nomor versi sudah tampil di daftar Fase 2, dan versi ringkasan berlaku sudah dapat dibaca pembimbing/murobi (R-7 Fase 3). Riwayat versi/alasan tetap tertutup bagi mereka.
- **S-3 Periode dan kelas.** Periode berdasarkan `waktu_kunjungan`; pengawasan berdasarkan waktu peristiwa (koreksi/akses/publikasi) dalam periode. Kelas = penempatan aktif **saat ini** pada tahun ajaran aktif, bukan kelas saat kunjungan (tidak ada riwayat kelas per kunjungan). Daftar tertunda sengaja tidak dibatasi periode.
- **S-4 Tanpa ekspor CSV/PDF.** PRD tidak meminta ekspor; ekspor akan menjadi saluran baru keluarnya data kesehatan. Halaman dapat dicetak lewat browser bila perlu.
- **S-5 Laporan tidak dicatat di log akses** karena tidak membuka rincian klinis; setiap pembukaan detail dari tautan laporan tetap tercatat.
- **S-6 Penjaga SQL rollback (F5-1)** menyentuh berkas rollback fase sebelumnya. Migrasi tidak diubah. Perilaku pada tabel kosong sama; pada tabel berisi kini menolak. SHA rollback baru ada di manifest rilis.

## Batas yang belum diuji / terbuka

- **cPanel/produksi:** tidak ada deploy, migrasi, smoke, cron, atau pemeriksaan `.htaccess`/HTTP di hosting. Status produksi 024–026 tidak diketahui. MariaDB hosting/MySQL lain belum diuji, termasuk penegakan CHECK yang menjadi dasar penjaga rollback.
- **Aplikasi:** layar laporan hanya diuji di Expo **web** (Chromium). Tidak ada build native baru, emulator/simulator, atau perangkat fisik untuk layar ini. Butir Fase 4 yang terbuka (runtime iOS terbaru, ketukan Push provider foreground/background/cold start, cold start non-dev) **tetap terbuka**. Push hanya klien palsu di suite regresi.
- **V3/V4 integrasi bernama tetap** (`webalhasan_v3_phase1_test`, `webalhasan_v4_fase2_test` dan runner V4 Fase 5 yang memakai berkas env privat) tidak dijalankan ulang, sama seperti fase V5 sebelumnya. Cakupan V4 lewat HTTP Tahap 7 (penilaian & rapor) dan regresi lain di tabel.
- Konkurensi diuji dengan 6 proses untuk pencatatan, koreksi, dan penerbitan, bukan seluruh interleaving. Deadlock 1205/1213 dipetakan 409 tetapi tidak direproduksi. Uji beban laporan pada data besar belum dijalankan (kueri memakai indeks `kunjungan_id` dan subkueri per kunjungan).
- UAT 95% (PRD §7) belum dijalankan.

## Kesalahan penyiapan uji (dicatat jujur)

- Pada percobaan pertama aplikasi Expo web, Metro dijalankan dari repo mobile dan cache memuat `.env.local` (alamat API produksi). Satu **skrip debug sementara saya yang tidak memblokir host luar** membuat browser mengirim **satu permintaan pra-penerbangan CORS** ke host produksi saat menekan Masuk dengan akun sintetis. Permintaan diblokir CORS sehingga POST login tidak terkirim, dan tidak ada data yang dibaca/ditulis. Penanganan: Metro dimatikan, Expo dijalankan dari salinan kerja **tanpa `.env.local`** dengan `--clear`, bundel diperiksa tidak memuat host produksi, dan seluruh hasil berasal dari runner resmi yang membatalkan setiap permintaan non-localhost (`permintaan ke luar localhost: 0`). Skrip debug tidak di-commit.
- Penyiapan pertama runner aplikasi (sebelum isolasi di atas) gagal login karena alasan yang sama; tidak ada hasil uji dari percobaan itu yang dipakai.

## Handoff

Commit web: `01694cf` (implementasi) dan commit dokumen/bukti sesudahnya pada `claude/prd-v5-fase-5`; mobile `b593d21` pada `claude/prd-v5-fase-5`. Keduanya didorong ke remote.

Auditor Codex berikutnya: periksa laporan dan hak per peran, penjaga SQL rollback (F5-1) termasuk pada versi DB target, verifier gabungan, latihan backup/restore, dan seluruh penafsiran S-1…S-6. Jalankan ulang pengujian secara independen. Fase 4 yang masih terbuka harus dinilai terpisah. Tidak merge, deploy, migrasi produksi, atau rilis aplikasi dari dokumen ini; merge/deploy tetap keputusan Human Developer. Direktori `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, dan `output/` tidak disentuh.
