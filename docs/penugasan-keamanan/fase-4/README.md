# Penugasan lintas guru/pengurus — Fase 4: aplikasi (Keamanan hanya baca dan akun dua identitas)

**Status (31 Oktober 2026): LULUS audit teknis lokal Codex setelah satu koreksi terarah P3; lihat [`AUDIT-CODEX.md`](AUDIT-CODEX.md).** Belum merge, belum deploy, belum rilis aplikasi. Fase 5 belum dimulai. Runtime iOS, perangkat fisik dan produksi belum dinyatakan lulus.

| Butir | Nilai |
| --- | --- |
| Workstream | Penugasan lintas guru/pengurus dan Keamanan Fase 1–5 — implementator Claude Code, auditor Codex (`AGENTS.md`, keputusan 30 Oktober 2026) |
| Rancangan | [`../rancangan.md`](../rancangan.md) §4 Fase 4, PK-1, PK-T2, PK-T3, PK-T4 |
| Branch | `claude/penugasan-keamanan-fase-4` di **WebAlHasan** dan **alhasanApps** |
| Baseline web | Commit audit Fase 3 `6ebdc24` (= `origin/claude/penugasan-keamanan-fase-3`). `origin/main` `fcfbd93` (PR #105) memuatnya; `git diff 6ebdc24 origin/main` kosong. Branch dibuat dari `6ebdc24`, bukan `main`, tanpa worktree. |
| Baseline mobile | `origin/main` `367ae5d` (PR #22, memuat audit tagihan satu santri `6e50bd9`; `git diff 6e50bd9 origin/main` kosong). |
| Migrasi | **Tidak ada.** Memakai 032/033 dari Fase 1/3. |
| Dependensi/modul native baru | **Tidak ada.** `expo-screen-capture ~57.0.4` sudah terpasang sejak V5 Fase 4. |
| Lingkungan uji | PHP 8.4.14, MariaDB 12.3.2 lokal, Node, Chromium Playwright, Android emulator AVD Medium_Phone (dev client Debug). Aplikasi diuji dari salinan tanpa `.env*` dengan `package.json`/`package-lock.json` ter-commit (Expo 57.0.15) dan `node_modules` direktori nyata. Perubahan `package.json`/`package-lock.json` mobile yang belum di-commit milik pekerjaan lain **tidak disentuh dan tidak di-commit**; direktori web milik pekerjaan lain (`docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`, `tests/browser/tangkapan-penugasan-keamanan-f1/`) tidak disentuh. |

## Yang dikerjakan

Prinsip: **aplikasi Keamanan hanya membaca, lewat layanan yang sama dengan halaman web Fase 3.** Tidak ada lapisan data kedua dan tidak ada jalur tulis baru.

### Web (WebAlHasan)

1. **REST baca-saja `/api/v1/keamanan/*`** (`api/v1/index.php`, `app/Keamanan/KeamananApiService.php`, factory `keamanan_api_service()`):

   | Endpoint | Layanan yang dipakai |
   | --- | --- |
   | `GET /keamanan/akses` | `KeamananAkses::sah` → `{pantau, terpasang, bagian, cetak:"website", csv:false}`; tanpa penugasan = `pantau:false` (200) |
   | `GET /keamanan/izin`, `/keamanan/izin/{id}` | `IzinLaporanService` cakupan `keamanan` (daftar, tanpa alasan); `KeamananService::izinDetail` (isi lengkap, audit wajib) + label status riwayat |
   | `GET /keamanan/pelanggaran`, `/{id}` | `PelanggaranService` mode `keamanan` (daftar, angka chip, rincian, audit wajib) |
   | `GET /keamanan/konseling`, `/{id}` | `KonselingService` mode `keamanan` (penyaring kerahasiaan murobi; Rahasia = 404) |
   | `GET /keamanan/kehadiran`, `/kehadiran/pertemuan/{id}` | `ReportService` dengan penanda baca seluruh jadwal + santri aktif saja (pola keputusan H, koreksi audit Fase 3 F-1); rincian wajib berjejak `keamanan.kehadiran.dilihat` (F-2) |

   - Metode selain GET pada `/keamanan` dan `/keamanan/*` → **405 `Allow: GET` sebelum layanan dibuat**. `Cache-Control: private, no-store`.
   - Hak dihitung ulang setiap permintaan dari penugasan Keamanan **milik sendiri**; admin (pengawasan), orang tua, dan pengurus tanpa penugasan → 403 pada data.
   - Respons = isi yang ditampilkan halaman web Keamanan. Kunci layanan yang tidak ditampilkan web (rekomendasi, rekonsiliasi poin, peringatan konfigurasi, catatan murobi kasus, path/sha256 lampiran) tidak dikirim.
   - Hanya kunci saringan yang dikenal diteruskan ke layanan (`peran`, `santri_id`, `pembimbing_id`, … diabaikan).
   - ID tidak ada, santri nonaktif, dan kasus Rahasia: **404** dengan pesan yang sama. Audit rincian gagal → **503** tanpa isi.
   - `KeamananException` ditangkap router (aditif).
2. **Identitas akun dua identitas (PK-1):** endpoint aditif `GET /profile/identitas` = `{guru, pengurus:{id,nama,jabatan,milik_guru}, penugasan:[{jenis,label,identitas}]}` (`ApiAuthService::identitasPayload`, `ApiAuthRepository::pengurusIdentitas`). Identitas pengurus hanya dari `Capabilities::linkedPengurusId` (guru aktif, identitas milik guru akun sendiri); penugasan = jenis aktif milik akun, bukan pengawasan admin. **`/profile` dan respons login tidak berubah** — percobaan pertama menaruh kunci `identitas` di profil, tetapi regresi `penugasan_integration` FI-19 (oracle "field lama + satu field aditif") gagal 160/1, sehingga identitas dipindah ke endpoint terpisah dan oracle tidak dilonggarkan.
3. **`docs/api-v1.md`** — bagian "Penugasan Keamanan — REST baca-saja untuk aplikasi".
4. **Uji**: `tests/penugasan_keamanan_f4_static.php`, `tests/penugasan_keamanan_f4_db.sh` (rantai DB Fase 3, tanpa fixture baru), `tests/penugasan_keamanan_f4_http.php`, `tests/browser/uji-penugasan-keamanan-f4-app.mjs`, `perintah-regresi.sh`.

**Tidak diubah:** `app/Auth`, `app/Penugasan`, `app/V3`, `app/Izin`, `app/Report`, `app/Keamanan/KeamananService.php`/`KeamananAkses.php`, `portal/`, `admin/`, `database/`, endpoint REST lama (termasuk `/reports`, `/v3/*`, `/v7/*`).

### Aplikasi (alhasanApps)

| Berkas | Isi |
| --- | --- |
| `src/api/keamanan.ts` | Klien sembilan GET `/keamanan/*`, tanpa metode tulis, tanpa cache disk. |
| `src/keamanan/format.ts` | Pembantu murni: query string, validasi rentang tanggal, nada lencana, label status, pengelompokan penugasan per identitas. |
| `src/components/keamanan-shared.tsx` | `useKeamananAkses` (404/503 = modul tidak tersedia), `KeamananProtection` (pola navigator kesehatan/keuangan/izin wali: `expo-screen-capture` sebelum layar mana pun dimuat, gagal tertutup), `Halaman` (isi ditutup saat aplikasi tidak aktif), catatan "hanya baca". |
| `src/app/keamanan/_layout.tsx` + `index.tsx` | Navigator berpelindung; pintu "Pantauan santri" dengan empat bagian. |
| `src/app/keamanan/izin/{index,[id]}.tsx` | Daftar lintas asal (chip asal/hasil, rentang tanggal), rincian lengkap (alasan, catatan, keputusan, koreksi, riwayat). |
| `src/app/keamanan/pelanggaran/{index,[id]}.tsx` | Chip status berangka, cari santri; rincian, poin, riwayat, tanda murobi, lampiran (metadata, unduh di website), kasus Internal tertaut. |
| `src/app/keamanan/konseling/{index,[id]}.tsx` | Kasus Internal (chip, cari); rincian tujuan, sesi, tautan, linimasa, revisi. |
| `src/app/keamanan/kehadiran/{index,[id]}.tsx` | Laporan kehadiran (rentang, status), rincian pertemuan dengan penanda "Sedang izin". |
| `src/app/_layout.tsx`, `src/app/(app)/(home)/index.tsx` | Rute `keamanan` di balik sesi; ubin Beranda "Keamanan" dari `/keamanan/akses`. |
| `src/api/akun.ts`, `src/app/(app)/(profil)/profil.tsx`, `src/api/types.ts` | Bagian "Identitas & penugasan" dari `GET /profile/identitas`: "Sebagai guru" dan "Sebagai pengurus" (dibentuk dari data guru) dengan penugasan aktif per identitas; server lama (404) → bagian tidak tampil. |
| `tests/keamanan.test.ts` | Unit pembantu murni. |

Tidak ada formulir mutasi, idempotensi, unduhan, berbagi, cetak, atau `console` di layar Keamanan; satu-satunya isian adalah kolom "Cari santri".

## Penafsiran implementator (menunggu tinjauan auditor)

- **PF4-1** Prefiks REST `/keamanan/*` (bukan `/vN/`) karena workstream ini tidak bernomor PRD; konsisten dengan nama halaman web `portal/keamanan_*`.
- **PF4-2** Cetak/PDF (PK-T2) tetap halaman cetak website; aplikasi hanya menyebut "Cetak/PDF tersedia di website" (pola kuitansi keuangan V6 T-1). Respons rincian membawa `cetak_web` sebagai penunjuk, tidak dibuka aplikasi.
- **PF4-3** Lampiran pelanggaran di aplikasi hanya metadata; unduhan tetap lewat `portal/keamanan_lampiran.php` yang mengaudit setiap unduhan. Tidak ada endpoint unduhan REST baru.
- **PF4-4** Perlindungan `expo-screen-capture` diterapkan pada navigator Keamanan (pola V5/V6/V7). Belum ada keputusan Human Developer khusus Keamanan; mengikuti keputusan T-1 kesehatan/keuangan karena isi (alasan izin, pelanggaran, konseling) setara sensitif.
- **PF4-5** Jalur `/keamanan/kehadiran` selalu dalam cakupan Keamanan bagi akun berpenugasan, termasuk akun guru: pada web, guru+Keamanan membaca seluruh jadwal hanya saat peran aktif Pengurus; di aplikasi tidak ada peran aktif, sehingga **jalurnya sendiri** yang memilih cakupan (`/keamanan/*` = Keamanan, `/reports` = jadwal sendiri, tidak berubah).
- **PF4-6** Admin yang memegang penugasan Keamanan sendiri (sumber `keduanya`) dapat memakai REST Keamanan; pengawasan admin saja tidak (PF3-1).
- **PF4-7** "Tampilan akun dua identitas" = informasi di Profil dari endpoint tersendiri `GET /profile/identitas`, bukan pemilih peran baru dan bukan perubahan bentuk `/profile`. Menu aplikasi tetap dari capability server; tidak ada role login atau sakelar baru (rancangan §3.1).
- **PF4-8** `/keamanan/akses` menjawab 200 `pantau:false` untuk akun tanpa penugasan (bukan 403) agar Beranda dapat memutuskan ubin tanpa galat; seluruh rute data tetap 403.

## Hasil pengujian implementator (lokal)

| Rangkaian | Hasil | Berkas |
| --- | --- | --- |
| Statis `tests/penugasan_keamanan_f4_static.php` S-1…S-6: router 405 sebelum layanan dan sembilan rute; layanan tanpa SQL tulis/CSV/unduhan, hak di setiap metode; `/profile`/login tidak berubah dan identitas lewat endpoint sendiri; batas berkas web terhadap `6ebdc24`; aplikasi (klien GET saja, perlindungan, tanpa mutasi/unduhan/console/selectable, ubin, rute, profil); lint | **35/0** | `bukti/statis.txt` |
| HTTP REST seperti aplikasi `tests/penugasan_keamanan_f4_http.php` R-1…R-10: akses per akun; 36 permintaan mutasi (4 metode × 9 rute) → 405 tanpa perubahan data; daftar izin/pelanggaran/konseling/kehadiran = layanan web + oracle SQL; rincian lengkap + audit tanpa isi; nonaktif/Rahasia/tidak ada = 404 (pesan Rahasia = tidak ada); trigger audit gagal → 503 tanpa isi (4 rincian); `/reports` lama tidak melebar (K 403, G jadwal sendiri); pencabutan penugasan, guru nonaktif, penugasan berakhir → token lama ditolak; `GET /profile/identitas` (G dua identitas, K, wali, admin, guru nonaktif, 401), bentuk `/profile` dan login tetap; log server bersih | **66/0** | `bukti/http.txt` |
| Aplikasi **Expo web** 375 px `uji-penugasan-keamanan-f4-app.mjs` A-A…A-J (Chromium, bukan perangkat): ubin K/G, bukan N/admin/wali; N langsung → ditolak; pintu empat bagian tanpa tombol mutasi; izin daftar = API, rincian lengkap + satu jejak audit, nonaktif ditolak; chip pelanggaran = API, lampiran metadata tanpa tautan unduh; konseling tanpa Rahasia (daftar, cari, rincian = tidak ada); kehadiran = API, rincian berjejak; profil G dua identitas, K pengurus; pencabutan saat layar terbuka (latar → kembali) menutup isi dan menghapus ubin; nol non-GET ke `/keamanan`, nol permintaan luar; penyimpanan/konsol tanpa isi; tanpa gulir horizontal (kecuali catatan bilah tab guru di bawah) | **46/0**, bundel host produksi 0 | `bukti/aplikasi-expo-web.txt` |
| Mobile tsc / lint / unit | tsc 0, lint 0, unit **47/0** (43 lama + 4 baru) | `bukti/mobile-tsc-lint-unit.txt` |
| **Android emulator** (dev client Debug + Metro) N-1…N-9, ulangan N-9 sesudah endpoint identitas | 9 + 1 skenario lulus | `bukti/android-emulator.txt` |

Perintah: `docs/penugasan-keamanan/fase-4/perintah-uji.sh` (salinan mobile wajib tanpa `.env*`, `node_modules` direktori nyata).

### Kesalahan uji yang diperbaiki selama implementasi (bukan perubahan perilaku aplikasi)

- Oracle SQL pelanggaran awal memakai kolom `digantikan_oleh_id` yang hanya ada di proyeksi; diganti syarat repositori (`NOT EXISTS revisi_dari_id`).
- Asersi "guru nonaktif → `pengurus` null" awalnya memakai `?? 'x'` (PHP memperlakukan `null` sebagai tidak ada); server sudah benar (`pengurus: null`), asersi diperbaiki.
- Uji aplikasi awalnya mengharapkan teks 403 dari server; aplikasi (sejak V2) menampilkan pesan 403 generik "Informasi tidak dapat diakses…". Asersi disesuaikan.
- Pencarian kolom "Cari santri" gagal karena kolom belum berlabel aksesibilitas — **diperbaiki di aplikasi** (`accessibilityLabel`), lalu Metro di-restart `--clear` karena watcher menyajikan bundel lama.
- Token sesi Expo web memang disimpan di `sessionStorage` `alhasan_teacher_api_token` (desain lama; native: SecureStore). Asersi dipersempit ke "tanpa token di luar kunci sesi".

### Kontrol pada kode baseline `6ebdc24`

- Statis Fase 4 pada klon `6ebdc24`: **13/15** (`bukti/kontrol-baseline/f4_statis.txt`; bagian aplikasi lulus karena membaca checkout mobile yang sama, dan batas berkas gagal karena arsip bukan repo Git).
- HTTP Fase 4 pada klon: **4/9 lalu berhenti** (R-3 `array_column(null)` karena `/keamanan/*` belum ada → 404) (`bukti/kontrol-baseline/f4_http.txt`).

## Regresi

`perintah-regresi.sh` (turunan skrip Fase 3, ditambah suite Fase 3 dan REST aplikasi V5/V6 Fase 4) dijalankan pada **kode akhir** (`bukti/regresi/`) dan pada **klon `6ebdc24`** (`bukti/kontrol-baseline/regresi/`) dengan suite, urutan, dan fixture yang sama. Perbandingan per suite: `bukti/perbandingan-regresi.txt`.

**Seluruh 49 suite non-statis menghasilkan exit dan hitungan identik dengan baseline:**

| Kelompok | Kini = baseline |
| --- | --- |
| Penugasan Fase 1: integrasi / probe audit / probe token / HTTP | 70/0 · 20/0 · 10/0 · 34/0 |
| Penugasan Fase 2: integrasi / HTTP | 53/0 · 28/0 |
| Penugasan Fase 3: integrasi / HTTP / migrasi 033 / probe audit Codex | 44/0 · 44/0 · 19/0 · 26/0 |
| REST aplikasi V5 Fase 4 / V6 Fase 4 / V7 Fase 4 | 43/0 · 63/0 · 54/0 |
| V7 Fase 5: E2E / HTTP / probe cakupan / probe pencabutan | 82/1 · 40/0 · 6/0 · 24/0 |
| V7 Fase 3 integrasi / notifikasi / HTTP; Fase 2 integrasi / HTTP / probe; Fase 1 integrasi / HTTP | 112/0 · 49/0 · 43/0; 119/0 · 61/0 · 34/0; 73/2 · 71/0 |
| Fondasi penugasan (termasuk FI-19 bentuk profil); V2 integrasi Fase 1/2/4/5; konkurensi | 161/0; 39/0 · 94/0 · 124/0 · 145/0; 20/0 |
| V2 API Fase 3/4/5 (login, `/profile`); web Fase 2/4/5 | 95/21 · 91/1 · 150/0; 36/0 · 46/0 · 79/0 |
| V3 integrasi Fase 2/3/4/5; akses Fase 5 | 67/0 · 121/0 · 65/0 · 43/0; 5/0 |
| HTTP redesain Tahap 2 / 4 / 5 / 6 / 7 | 185/6 · 132/0 · 106/0 · 125/7 · 122/0 |

Kegagalan identik dengan baseline (oracle historis, sama dengan catatan Fase 3, tidak dilonggarkan): V7 Fase 5 E2E 82/1, V7 Fase 1 73/2, V2 API Fase 3/4 95/21 · 91/1, Tahap 2 HTTP 185/6, Tahap 6 HTTP 125/7.

**Percobaan pertama (didokumentasikan):** sebelum identitas dipindah ke `GET /profile/identitas`, regresi kode kini menghasilkan `penugasan-integrasi` **160/1** (FI-19 "profil memuat field lama pada urutan lama + satu field aditif"; baseline 161/0) — `bukti/diagnostik-fi19-percobaan1-identitas-di-profil.txt`. Kode diubah (bukan oracle), lalu seluruh regresi kode kini diulang: 161/0.

**Probe audit Codex Fase 2** pada skrip regresi berhenti pada penyiapan di **kedua sisi** (5/0 tanpa `PKF2_BASELINE`; probe V4 0/0 pada DB tanpa integrasi Fase 2) — kesalahan penyiapan yang sama dengan catatan Fase 3. Diulang dengan penyiapan benar (DB sesudah integrasi Fase 2, arsip `464d9a1`): **24/0** dan **9/0** (`bukti/regresi/pk-fase2-probe-{audit,v4}-ulang.txt`), sama dengan Fase 3.

### Statis (49 suite kini)

Kini **18 exit 0, 29 exit 1, 2 exit 77** (termasuk suite baru Fase 4 35/0). Klon `git archive` tidak memiliki `.git`, sehingga statis baseline juga dijalankan pada **klon Git penuh** `6ebdc24` (`git clone`, bukan worktree; `bukti/kontrol-baseline/statis-klon-git/`, perbandingan `bukti/perbandingan-statis.txt`). Perbedaan terhadap baseline, seluruhnya oracle historis yang mengunci batas fase lama; tidak dilonggarkan:

| Suite | Baseline → kini | Label kegagalan baru/berubah |
| --- | --- | --- |
| Penugasan Fase 1 | 111/4 → 117/4 | daftar berkas "Hanya berkas Fase 1" kini juga memuat `api/v1/index.php`, `app/Api/*` (+lint berkas baru) |
| Penugasan Fase 2 | 93/2 → 99/2 | sama, daftar berkas Fase 2 |
| Penugasan Fase 3 | 79/1 → 83/3 | "Capability/penanda Keamanan hanya dipakai di guard baca, layanan baca, katalog, dan menu" (kini juga `KeamananApiService`, jalur REST PK-T3); "REST … tidak diubah" (REST memang Fase 4); batas berkas Fase 3. Kegagalan baseline `vendor` hanya karena symlink di klon. |
| Redesain Tahap 4 | 95/1 → 94/2 | "Penanda baca-semua hanya dipasang di `admin/_laporan_guard.php`": `KeamananApiService::pembacaKehadiran` juga memasangnya **sesudah** `KeamananAkses::sah`, hanya pada `/keamanan/kehadiran*`. `/reports` tidak berubah (HTTP R-7: K 403, G hanya jadwal sendiri). **Mohon ditinjau auditor** (keputusan H). |
| Tagihan satu santri; V7 Fase 4; V7 Fase 5 | 19/5 → 18/6; 24/7 → 23/8; 50/6 → 49/7 | "`api/` tidak berubah" (router REST bertambah blok Keamanan dan `/profile/identitas`) |
| Tahap 6 | 139/2 → 140/1 | lebih baik di pohon kerja (kegagalan baseline akibat lingkungan klon) |

`phase5_static` dan V2 Fase 3/4/5 berbeda hanya karena lingkungan (klon tanpa repo mobile tetangga, dan perubahan paket mobile yang belum di-commit milik pekerjaan lain di pohon kerja; angka pohon kerja = catatan Fase 3). Selebihnya identik.

## Temuan di luar cakupan

- **TF4-1 (tampilan, sudah ada sejak baseline mobile `367ae5d`)**: di Expo web 375 px, akun **guru** (empat tab: Beranda, Jadwal, Izin, Profil) meluap horizontal **33 px** pada semua tab (Beranda, Profil, Jadwal) karena bilah tab web. Kontrol pada salinan `367ae5d`: 33/33/33 px. Akun pengurus 0 px. Bagian identitas baru berada di dalam 375 px (tepi kanan 359 px). Tidak diperbaiki di Fase 4; native tidak memakai bilah tab web ini.

## Batas

- **Perangkat fisik, iOS (simulator maupun fisik), build rilis Android, Push nyata, MySQL/cPanel/produksi (MariaDB 10.6.28), dan UAT belum diuji.** Android hanya emulator dengan dev client Debug.
- Fase 4 bukan izin rilis: aplikasi dengan layar Keamanan memerlukan server yang memuat 032/033 dan REST Fase 4; server lama menjawab `/keamanan/akses` 404 → ubin tidak tampil (modul tidak tersedia), dan `/profile/identitas` 404 → bagian identitas tidak tampil.
- Gerbang produksi V5/V6/V7 yang masih terbuka tidak berubah.

## Handoff

Implementasi Fase 4 selesai pada kedua branch `claude/penugasan-keamanan-fase-4`. Untuk auditor Codex:

- Fokus: (1) REST tidak dapat dipakai menulis atau memperluas hak (405 sebelum layanan; penanda dipasang hanya sesudah `sah()`; kunci saringan asing diabaikan); (2) kerahasiaan Rahasia dan santri nonaktif di REST dan aplikasi; (3) jejak audit rincian dari aplikasi tanpa isi, 503 bila gagal; (4) `/reports` dan REST lama tidak melebar; (5) `GET /profile/identitas` tidak membocorkan identitas yang tidak sah dan `/profile`/login tidak berubah; (6) perlindungan layar native; (7) penafsiran PF4-1…PF4-8.
- Tidak merge, deploy, migrasi produksi, rilis aplikasi, atau memulai Fase 5.
