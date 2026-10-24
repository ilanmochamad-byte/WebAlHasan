# PRD V5 Fase 4 — Paritas Aplikasi Mobile

**Status (24 Oktober 2026):** diimplementasikan Claude Code, menunggu audit independen Codex. Belum merge, belum deploy, belum migrasi produksi, tidak ada rilis aplikasi. Fase 5 belum dimulai.

| Butir | Nilai |
| --- | --- |
| Workstream | PRD V5 Fase 1–5 kesehatan santri — implementator Claude Code, auditor Codex (`AGENTS.md`) |
| Branch web | `claude/prd-v5-fase-4` dari `ab2b952` (audit Fase 3; `origin/main` `b91d5b8` = merge PR #82 yang memuatnya), tanpa worktree |
| Branch mobile | `~/alhasanApps` `claude/prd-v5-fase-4` dari `ee6621a` — **keputusan Human Developer 24 Oktober 2026** (lihat Baseline mobile) |
| Migrasi | Tidak ada. API hanya bertambah rute; satu kunci aditif pada baris pemilih santri |
| Lingkungan uji | PHP 8.4.14, MariaDB 12.3.2 lokal, Node v26.7.0, Chromium Playwright, Expo SDK 57 (web dev server), Android Emulator `Medium_Phone` (Android 16, `sdk_gphone16k_arm64`), iOS Simulator iPhone 17 Pro (iOS 26.2). Seluruh DB `test_*_test` dengan fixture sintetis. **Tidak ada perangkat fisik dan tidak ada Push nyata.** |

## Hasil audit independen Codex (24 Oktober 2026)

**BELUM LULUS seluruh kriteria Fase 4.** Auditor mengoreksi versi/revisi asal draf yang sebelumnya diganti otomatis saat kembali dari latar (dapat menimpa perubahan petugas lain), serta menambah perlindungan native khusus navigator kesehatan untuk menutup cuplikan pengalih aplikasi Android. Eksekusi auditor dan hasil implementator di bawah dibedakan. Build Android baru diuji; build/runtime iOS terbaru, ketukan Push provider dan cold start non-dev belum terbukti. Bukti, kontrol sebelum/sesudah, hitungan, penafsiran dan handoff ada di [AUDIT-CODEX.md](AUDIT-CODEX.md). Fase 5 tidak diizinkan.

## Baseline mobile (diverifikasi sebelum perubahan)

- `origin/main` mobile `7ac224e` = merge PR #13 yang memuat implementasi V4 Fase 5 (`c969374`, `dc943ed`) **tetapi tidak memuat commit audit V4 `ee6621a`** (koreksi deep-link ganda + tipe `Snapshot.rata_rata`), yang di-push ke `origin/codex/prd-v4-fase-5` 45 menit sesudah merge.
- Tidak ada rilis aplikasi V4: tanpa tag, versi `1.0.0`, status V4 "belum siap rilis" (iOS runtime, perangkat fisik, Push nyata, smoke produksi menunggu).
- Karena baseline tidak jelas, pemilihan dihentikan dan Human Developer memutuskan (24 Oktober 2026): **branch dari `ee6621a`** (V4 hasil audit lengkap). Akibatnya branch mobile memuat `ee6621a` yang belum ada di `main` mobile; merge branch ini ke `main` sekaligus membawa koreksi audit V4 tersebut.

## Yang dikerjakan

### Aplikasi (`alhasanApps`)

| Peran | Layar/alur | Rute |
| --- | --- | --- |
| Semua | Ubin Beranda "Kesehatan" (pembaca internal) atau "Kesehatan anak" (wali saja), dari `/v5/kesehatan/akses` + `/wali/akses`; modul belum dipasang (503) = ubin tidak tampil | `(home)` |
| Petugas | Catat kunjungan: pemilih santri **per kelas** (kelas dulu dengan jumlah, santri dimuat saat kelas dibuka; pencarian nama/NIS dikelompokkan per kelas), waktu, keluhan, catatan klinis, tindakan, obat (≤20), rujukan (≤5), tindak lanjut (≤10), simpan Tercatat/Draf | `/kesehatan/catat` |
| Petugas | Koreksi beralasan / lanjutkan draf (keadaan lengkap + versi yang dilihat), hasil rujukan beralasan, riwayat versi | `/kesehatan/catat?id=`, `/kesehatan/hasil-rujukan`, `/kesehatan/versi` |
| Petugas | Ringkasan wali: susun → pratinjau server (proyeksi wali) → konfirmasi → terbit; ganti/terbit ulang beralasan; tarik beralasan | `/kesehatan/ringkasan` |
| Admin | Daftar + detail hanya baca, riwayat versi, **riwayat akses**, riwayat ringkasan; **penugasan petugas kesehatan** (tambah, akhiri, nonaktifkan, aktifkan — beralasan) | `/kesehatan/daftar`, `/kesehatan/kunjungan/[id]`, `/kesehatan/akses`, `/kesehatan/penugasan` |
| Pembimbing/murobi | Riwayat kesehatan santri binaan + detail hanya baca + ringkasan berlaku | sama, tanpa tombol mutasi |
| Wali | Daftar ringkasan anak (pilih anak), detail proyeksi wali; tujuan notifikasi `kesehatan_ringkasan` dan tautan `alhasanapps://kesehatan/wali/{id}` | `/kesehatan/wali`, `/kesehatan/wali/[id]` |

Prinsip yang dipakai seluruh layar: hak hanya dari server (tombol disembunyikan bukan kontrol akses); setiap buka, fokus kembali, dan kembali dari latar memanggil server (`usePrivateResource`) sehingga sesi/penugasan/relasi yang dicabut langsung tertutup; 401 → token dibersihkan → layar masuk; 403/404 → isi lama dibuang; mutasi memakai `useMutationGuard` (satu kunci idempotensi per isi, dipakai ulang saat jaringan putus → server memutar ulang, tidak menggandakan); 409 tidak pernah dikirim ulang otomatis — pengguna memuat versi terbaru (isian dibuang); tidak ada cache disk, `console.*`, analytics, atau salin otomatis untuk isi klinis; kolom isian memakai `PrivateField` (tanpa autofill/autocorrect). Notifikasi: `notificationTarget` menerima `{tipe: kesehatan_ringkasan, ringkasan_id}`; penunjuk ringkasan (seperti rapor V4) bertahan melewati 401/login dan dilepas hanya sesudah server mengizinkan.

### Web (`WebAlHasan`) — aditif

1. **API penugasan kesehatan untuk aplikasi** — `App\Kesehatan\PenugasanKesehatanApi` + rute `GET/POST /v5/kesehatan/penugasan`, `POST …/{id}/akhiri|nonaktifkan|aktifkan`. Hanya jenis `kesehatan`, gerbang baca admin (= `akses()['riwayat_akses']`), seluruh mutasi lewat `PenugasanService` yang sama dengan Pusat Penugasan web (transaksi, tumpang tindih 409, audit `penugasan.*`, penolakan admin di layanan). DTO tanpa kolom akun sensitif. `PenugasanException` dipetakan ke JSON 403/404/409/422 di router API.
2. **Pemilih santri per kelas** (permintaan Human Developer di tengah sesi) — `KesehatanRepository::santriPilihan()` bergabung ke `plotting_kelas` aktif tahun ajaran aktif (paling banyak satu baris per santri, dijaga indeks unik), penyaring `kelas_id` (angka/`tanpa`), urut kelas lalu nama; `kelasPilihan()` + `GET /v5/kesehatan/santri/kelas`. `portal/kesehatan_buat.php` mengelompokkan `<select>` dengan `<optgroup>` per kelas (penyaring opsi JS lama tetap bekerja). Hak tetap hanya petugas.
3. Dokumentasi `docs/api-v1.md` bagian "Kesehatan santri V5 Fase 4".

## Kriteria penerimaan → bukti

| Kriteria Fase 4 | Bukti | Status |
| --- | --- | --- |
| Kunjungan, obat, rujukan, koreksi, publikasi dari aplikasi tampil identik di web; simpan ulang tidak menggandakan kunjungan/notifikasi | `bukti/aplikasi-expo-web.txt` A (ketukan ganda + jawaban hilang → 1 kunjungan, replay 200; 7 teks identik app–web–API), B (koreksi, konflik 409 tidak menimpa, hasil rujukan, riwayat versi web memuat alasan aplikasi), C (ketukan ganda Terbitkan → 1 peristiwa, InApp = jumlah wali berakun); `bukti/android-petugas.txt` (native: ketukan ganda Simpan → 1, koreksi v2, ketukan ganda Terbitkan → 1 peristiwa) | Lulus (Expo web + Android emulator) |
| Hak admin/petugas/pembimbing/murobi/wali diuji silang web/API/aplikasi; luar cakupan 403/kosong | `bukti/aplikasi-expo-web.txt` D, E, F; `bukti/http.txt` N-A (6 akun × 3 endpoint penugasan 403), N-C | Lulus |
| Wali membuka push generik, masuk bila perlu, melihat ringkasan anaknya; relasi dicabut tidak dapat membuka dengan tautan lama | Notifikasi InApp → tombol → ringkasan (Expo web D); tautan skema tanpa sesi → masuk → ringkasan (Android, iOS); relasi dicabut → 403 tanpa isi (Expo web, Android, iOS); wali lain dengan tautan sama 403 (Expo web, Android) | Lulus untuk InApp dan tautan skema; **Push nyata tidak diuji** |
| Perangkat iOS/Android yang benar-benar diuji dicatat; emulator ≠ fisik | Tabel "Perangkat yang diuji" di bawah | Dicatat; tidak ada perangkat fisik |
| Log/cache aplikasi: 0 isi klinis, nama santri, credential, token push | Expo web: localStorage/sessionStorage 4 akun bersih, konsol tanpa isi/token/kata sandi, 0 permintaan ke luar 127.0.0.1; Android: 63 berkas data aplikasi bersih, logcat aplikasi bersih; iOS: 55 berkas kontainer + 9.532 baris log proses bersih | Lulus — **kecuali cuplikan pengalih aplikasi Android** (temuan T-1) |

## Perangkat dan lingkungan yang benar-benar diuji

| Lingkungan | Jenis | Yang diuji | Bukti |
| --- | --- | --- | --- |
| Chromium Playwright 375×812 (Expo web dev server) | Browser, bukan perangkat | Seluruh peran, seluruh alur mutasi, paritas web/API, konflik, sesi kedaluwarsa, relasi/penugasan dicabut, privasi penyimpanan/konsol | `bukti/aplikasi-expo-web.txt` |
| Android Emulator `Medium_Phone`, Android 16 (`sdk_gphone16k_arm64`), dev client debug 17 Oktober 2026 + Metro | **Emulator**, bukan fisik | Petugas native (catat, koreksi, terbit, ketukan ganda); wali: tautan tanpa sesi → masuk, tautan dari latar dan depan, relasi dicabut, akun lain; sesi kedaluwarsa saat di latar; data aplikasi + logcat | `bukti/android-*.txt`, `tangkapan/android-*.png` |
| iOS Simulator iPhone 17 Pro, iOS 26.2, dev client Debug 15 Oktober 2026 + Metro | **Simulator**, bukan fisik | Wali: tautan tanpa sesi → masuk → ringkasan, pengalih aplikasi, cold start dengan sesi, relasi dicabut/dipulihkan; kontainer + log | `bukti/ios-simulator.txt`, `tangkapan/ios-*.png` |
| Perangkat fisik iOS/Android | — | **Tidak diuji** | — |
| Push nyata (Expo/FCM/APNs) foreground/background/cold start | — | **Tidak diuji**; tidak ada request ke provider | — |

## Temuan dan penafsiran (untuk ditinjau auditor)

- **T-1 (terbuka, butuh keputusan Human Developer): cuplikan pengalih aplikasi Android memuat isi kesehatan.** Android mengambil cuplikan sebelum JS menerima perubahan `AppState`, sehingga penutup privasi dan pembuangan data tidak sempat dirender (`tangkapan/android-recents-petugas.png`). iOS tidak terdampak pada uji simulator. Perlindungan andal memerlukan `FLAG_SECURE` (mis. `expo-screen-capture`): modul native baru + build ulang dev/rilis, dan juga memblokir tangkapan layar pengguna di seluruh aplikasi atau layar tertentu. Tidak dikerjakan tanpa keputusan karena mengubah perilaku produk dan dependensi native.
- **T-2 (lingkungan uji): dev server Expo web memuat `.env.local` sebagai modul konteks Metro walau `EXPO_NO_DOTENV=1`.** `.env.local` di checkout mobile menunjuk API produksi; pada percobaan pertama aplikasi mencoba ke host produksi dan permintaan itu **diblokir Playwright sebelum keluar mesin** (penjaga host 127.0.0.1). Seluruh uji kemudian dijalankan dari clone APFS tanpa `.env.local`, dan bundel diperiksa 0 kemunculan host produksi sebelum tiap putaran. Auditor sebaiknya memakai cara yang sama.
- **S-1** Admin mengelola penugasan di aplikasi lewat API admin baru yang membungkus `PenugasanService` (bukan membuka web di aplikasi); hanya jenis kesehatan, tanpa ubah cakupan/arsip (tidak relevan untuk cakupan seluruh santri).
- **S-2** Pemilih santri per kelas memakai penempatan kelas aktif tahun ajaran aktif (`plotting_kelas`); santri tanpa penempatan dikelompokkan "Belum ditempatkan di kelas". Ini pengelompokan tampilan, bukan pembatasan hak (petugas tetap mencakup seluruh santri aktif sesuai PRD).
- **S-3** Konflik 409 tidak dikirim ulang dan tidak menggabungkan isian; pengguna memuat versi terbaru dan isian dibuang (sama dengan pola V4).
- **S-4** Draf formulir koreksi/ringkasan tetap di memori ketika aplikasi ke latar (tidak ke disk) dan dibuang saat layar ditinggalkan; data server dibuang saat aplikasi tidak aktif.
- **S-5** Tautan skema `alhasanapps://kesehatan/wali/{id}` ditambahkan setara rapor V4; penunjuk ringkasan bertahan sampai server mengizinkan (akun salah → 403, lalu masuk akun benar → terbuka).
- **S-6** Teks pemilih santri memuat nama kelas setelah dipilih (`Nama · Kelas`) hanya di layar petugas.

## Hasil pengujian (lokal)

| Rangkaian | Hasil | Bukti |
| --- | --- | --- |
| HTTP web/API Fase 4 `tests/v5_phase4_http.php` (penugasan admin, pemilih per kelas, `<optgroup>` web, kontrak lama, log) | 43/0 | `bukti/http.txt` |
| Aplikasi Expo web + API/DB nyata, 375 px, sembilan akun `tests/browser/uji-v5-fase4-app.mjs` | 79/0 | `bukti/aplikasi-expo-web.txt`, `tangkapan/app-*.png` (diperiksa visual) |
| Android emulator — petugas `tests/android/v5_fase4_a.py` | 5/0 | `bukti/android-petugas.txt` |
| Android emulator — sesi, tautan, relasi, akun lain, data/logcat `tests/android/v5_fase4_b.py` | 13/0 | `bukti/android-wali-sesi-privasi.txt` |
| iOS simulator (manual) | 8 diamati lulus, 2 batas dev client, 2 tidak diuji | `bukti/ios-simulator.txt`, `tangkapan/ios-*.png` |
| Aplikasi: `tsc`, `expo lint`, unit 28/0, ekspor bundel Hermes iOS+Android | exit 0 semua | repo mobile `docs/phase-v5-4/` |
| Regresi V5 Fase 2 integrasi / HTTP / browser | 134/0 · 87/0 · 58/0 | `bukti/regresi/` |
| Regresi V5 Fase 3 integrasi / HTTP / browser | 82/0 · 70/0 · 62/0 | `bukti/regresi/` |
| Regresi V5 Fase 1 integrasi / HTTP / browser | 71/4 · 79/3 · 35/0 — identik dengan bukti Fase 2–3 (oracle "pembimbing/murobi ditolak membaca" yang digantikan Fase 2) | `bukti/regresi/` |
| Integrasi fondasi penugasan (`PENUGASAN_RUN_INTEGRATION=1`) | 161/0 | `bukti/regresi/penugasan.txt` |
| 33 suite statis, dibandingkan dengan baseline `ab2b952` pada checkout yang sama | 21 exit 0 · 10 exit 1 · 2 exit 77; baseline 22 · 9 · 2 | `bukti/regresi/statis-fase4.txt`, `statis-baseline-ab2b952.txt` |

Perbedaan statis terhadap baseline (oracle tidak diubah):

- `v5_phase3_static` 70/1 (baseline 71/0): oracle "app/Auth, app/Penugasan, AuditLogger, dan layanan/repositori klinis tidak berubah dari baseline audit Fase 2". Fase 4 sengaja mengubah `KesehatanRepository::santriPilihan()`/`KesehatanService::santriPilihan()` (pengelompokan kelas, permintaan Human Developer) dan menambah `kelasPilihan()`; `app/Auth`, `app/Penugasan`, dan `AuditLogger` tidak berubah. Kegagalan yang diharapkan.
- `v5_phase2_static` 62/1 (baseline 61/2): oracle "Respons tulis hanya {id, versi, replayed}; kunci dari header/badan" **kini lulus secara kebetulan** — oracle menghitung baris idempotensi dalam 4.200 karakter pertama blok `/v5/kesehatan`; blok penugasan baru menggeser baris ringkasan Fase 3 keluar jendela. Bukan perbaikan; kegagalan sisanya ("025 migrasi terakhir") tetap historis.
- Delapan suite exit 1 lain (`keamanan_psb`, `penugasan`, Tahap 3/4/7/8, `v2_phase4`, `v5_phase1`) memiliki baris `[gagal]` identik dengan baseline.

Regresi yang **tidak** dijalankan ulang pada Fase 4: migrasi–rollback (tidak ada migrasi baru), Tahap 2/5/6/7 HTTP dan browser kerangka sembilan akun (menu, hak, dan kerangka web tidak berubah; satu-satunya halaman web yang berubah, `portal/kesehatan_buat.php`, diuji lewat browser Fase 1–3 dan HTTP Fase 4), V2 Fase 4/5 (notifikasi tidak berubah).

## Cara mengulang

```bash
# Web
php -l app/Kesehatan/PenugasanKesehatanApi.php
V5F3_DB=test_v5_f4_http_test bash tests/v5_phase3_db.sh && V5F4_DB=test_v5_f4_http_test php tests/v5_phase4_http.php
# Aplikasi Expo web — dari clone tanpa .env.local
cp -cR ~/alhasanApps /tmp/f4-mobile && rm -f /tmp/f4-mobile/.env.local
V5F3_DB=test_v5_f4_app_test bash tests/v5_phase3_db.sh
DB_NAME=test_v5_f4_app_test php -S 127.0.0.1:8982 tests/redesain_web_tahap1_router.php &
(cd /tmp/f4-mobile && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8982/api/v1 npx expo start --web --port 8099 --clear &)
(cd tests/browser && DB_NAME=test_v5_f4_app_test V5_WEB=http://127.0.0.1:8982 V5_APP=http://127.0.0.1:8099 OUT_DIR=/tmp/f4shots node uji-v5-fase4-app.mjs)
```

Metro menyajikan bundel lama setelah sumber berubah (watcher tidak andal): selalu restart `expo start --clear` sesudah menyalin sumber. Skrip Android memakai `adb` + `uiautomator` (pembantu di luar repo, bukan bagian bukti otomatis); langkahnya tercatat pada berkas bukti Android.
