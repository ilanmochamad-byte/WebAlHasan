# V2 Fase 5 — Hasil Pengujian

Dijalankan 26 Agustus 2026 pada sandbox `webalhasan_test`.
Dua putaran berturut-turut memberi hasil identik (**2.230 pemeriksaan, 0 gagal**),
sehingga hasil ini stabil dan tidak bergantung pada urutan berkas uji.

## 1. Lingkungan

| Komponen | Versi | Catatan kesesuaian cPanel |
| --- | --- | --- |
| PHP | 8.4.21 (CLI, NTS) | Ulangi `php -l` dengan versi PHP cPanel sebelum rilis |
| MariaDB | 10.11.14 | Migrasi 009 memakai `INFORMATION_SCHEMA` + `ALTER`, aman pada MySQL 5.7/8 dan MariaDB |
| Node.js | 22.23.2 | Minimum SDK 57 adalah Node 22.13.x |
| Expo SDK | 57 (`expo ~57.0.15`) | **Tidak** di-upgrade |
| React Native | 0.86.2 | **Tidak** di-upgrade |
| TypeScript | ~6.0.3 | Tidak diubah |

Median durasi laporan sengaja **tidak** memakai window function agar tetap
berjalan pada MySQL 5.7 yang masih umum pada cPanel.

## 2. Ringkasan seluruh berkas uji

| Berkas | Hasil | Pemeriksaan |
| --- | --- | --- |
| `tests/phase1_static.php` | LULUS | 64 |
| `tests/phase2_static.php` | LULUS | 46 |
| `tests/phase3_static.php` | LULUS | 34 |
| `tests/phase4_static.php` | LULUS | 38 |
| `tests/phase5_static.php` | LULUS | 36 |
| `tests/v2_phase1_static.php` | LULUS | 126 |
| `tests/v2_phase2_static.php` | LULUS | 169 |
| `tests/v2_phase3_static.php` | LULUS | 147 |
| `tests/v2_phase4_static.php` | LULUS | 286 |
| `tests/v2_phase5_static.php` | LULUS | **248** |
| `tests/phase2_integration.php` | LULUS | 12 |
| `tests/phase3_integration.php` | LULUS | 10 |
| `tests/phase4_integration.php` | LULUS | 14 |
| `tests/phase5_integration.php` | LULUS | 20 |
| `tests/v2_phase1_integration.php` | LULUS | 39 |
| `tests/v2_phase2_integration.php` | LULUS | 94 |
| `tests/v2_phase2_navigasi_murobi.php` | LULUS | 32 |
| `tests/v2_phase2_web_smoke.php` | LULUS | 35 |
| `tests/v2_phase3_api_contract.php` | LULUS | 116 |
| `tests/v2_phase4_integration.php` | LULUS | 122 |
| `tests/v2_phase4_api_contract.php` | LULUS | 92 |
| `tests/v2_phase4_concurrency.php` | LULUS | 20 |
| `tests/v2_phase4_web_smoke.php` | LULUS | 46 |
| `tests/v2_phase5_integration.php` | LULUS | **143** |
| `tests/v2_phase5_api_contract.php` | LULUS | **150** |
| `tests/v2_phase5_web_smoke.php` | LULUS | **79** |
| `tests/v2_phase5_performance.php` | LULUS | **12** |
| **Total** | **28 berkas, 0 gagal** | **2.230** |

Fase 5 menyumbang **632** pemeriksaan baru. Kenaikan pada
`tests/v2_phase3_static.php` (146 → 147) dan `tests/v2_phase4_static.php`
(283 → 286) berasal dari berkas PHP baru yang otomatis ikut di-`php -l` dan
dari pembaruan pagar ruang lingkup, bukan dari pelonggaran pemeriksaan.

## 3. Fase 5 — statis (248 pemeriksaan)

Tanpa basis data. Yang dijaga:

1. **Migrasi 009 aditif dan berpasangan** — tidak ada `DROP TABLE`,
   `DROP COLUMN`, `TRUNCATE`, atau `DELETE`; setiap perubahan dibungkus
   `INFORMATION_SCHEMA` sehingga idempoten; rollback tidak menghapus satu baris
   data pun; migrasi tidak menyentuh `perizinan` maupun tabel inti V2.
2. **Satu definisi filter/repository** — konstruktor `IzinReportFilter` privat,
   seluruh kriteria `readonly`, `conditions()` dan `fromClause()` masing-masing
   ada **tepat satu**, dan `summary()`/`decisionDuration()`/`page()`/`allRows()`/
   `explain()` seluruhnya memakainya.
3. **Cakupan di server** — `scopeConditions()` tunggal, cakupan tak dikenal
   menghasilkan `1 = 0`, halaman portal tidak memuat SQL sendiri.
4. **CSV** — setiap kolom punya dokumentasi (dan sebaliknya, tidak ada
   dokumentasi tanpa kolom); 11 vektor formula injection diuji langsung
   (`=`, `+`, `-`, `@`, TAB, CR, spasi-lalu-`=`, `=HYPERLINK`, teks biasa,
   tanggal, string kosong) plus `null`.
5. **Cetak** — identitas, filter aktif, pembuat, waktu, keputusan, nomor
   halaman, dan `htmlspecialchars` pada seluruh nilai.
6. **Endpoint aditif** — 5 rute laporan baru ada; 6 rute lama (`/reports`,
   `/reports/filters`, `/reports/print`, `/izin/pengajuan`, `/izin/antrean`,
   `/notifikasi`) tetap ada.
7. **Receipt push** — kontrak `PushClient` Fase 4 **tidak berubah** (klien
   tiruan Fase 4 tetap sah); endpoint receipt sesuai dokumentasi Expo;
   rekonsiliasi berhenti sebelum permintaan apa pun saat push mati; receipt
   gagal tidak memicu pengiriman ulang.
8. **WhatsApp** — kalimat prinsip PRD utuh, ≥9 penanda `[JANGAN DIUBAH]` tetap
   ada, tidak ada endpoint/credential penyedia nyata di repositori.
9. **Perkakas** — fixture, pengukuran, dan latihan restore hanya CLI, hanya
   `*_test`, memerlukan penanda lingkungan, dan menolak `APP_ENV=production`.
10. **Kebersihan** — tidak ada credential tertanam; token push hanya boleh
    muncul bila jelas bertanda uji (pemeriksaan ini sendiri diverifikasi
    menangkap token tanpa penanda).
11. **Aplikasi** — Expo tetap `~57.0.15`, React Native tetap `0.86.2`,
    `expo-file-system` memakai API SDK 57 (`Paths`/`File`), aplikasi memanggil
    `/izin/laporan` dan tidak menyaring cakupan di sisi klien.
12. **`php -l`** pada seluruh berkas baru/diubah.

## 4. Fase 5 — integrasi (143 pemeriksaan)

Fixture bertanda unik per putaran (`F5<suffix>`) yang ikut menjadi filter
pencarian, sehingga jumlah yang diharapkan **tidak** terpengaruh berkas uji lain
yang berjalan lebih dulu. Fixture dibersihkan pada blok `finally`.

| Kelompok | Isi | Hasil |
| --- | --- | --- |
| KL-1 | Konsistensi ringkasan/detail/cetak/CSV pada 6 kombinasi filter; halaman sengaja dibatasi 2 baris untuk membuktikan ekspor tidak mengikuti pagination | LULUS |
| KL-2 | Isolasi cakupan admin(6)/pengurus A(5)/pengurus B(1)/murobi A(5)/murobi B(1)/ortu A(2)/ortu B(1), termasuk isi baris dan isi CSV/cetak | LULUS |
| KL-3 | Parameter memperluas cakupan → 403 pada daftar, CSV, dan cetak; `mode` palsu tidak memberi hak; orang tua memfilter santri lain → hasil kosong | LULUS |
| KL-4 | 21 pemeriksaan filter: status, santri, pengurus, murobi, kamar, kelas, tahun ajaran, sumber, durasi min/maks/rentang, pencarian, basis tanggal, kanal notifikasi, rentang tanggal | LULUS |
| KL-5 | Median ganjil (120 menit), median genap (150 menit), min/maks, dan "Tidak tersedia" saat tidak ada keputusan (bukan 0) | LULUS |
| KL-6 | BOM, jumlah baris, header sama dengan konstanta, injeksi pada nama santri/alasan/NIS, **0 sel** masih diawali karakter formula, ekspor mengabaikan pagination | LULUS |
| KL-7 | Identitas, judul, pembuat, waktu, nomor halaman, rentang filter, median, seluruh baris, escaping HTML | LULUS |
| KL-8 | 8 bentuk input tidak valid → 422; `per_page` dibatasi server | LULUS |
| KL-9 | Receipt sukses/gagal/belum tersedia/batas percobaan; **tidak ada pengiriman ulang**; kode receipt aman; rekonsiliasi idempoten | LULUS |
| KL-10 | Push mati → worker dan rekonsiliasi berhenti; **0 panggilan** send dan **0 panggilan** receipt; laporan tetap berfungsi | LULUS |

Bukti median yang bermakna (bukan sekadar "ada angka"): cakupan murobi A berisi
keputusan 60/120/180 menit → median **120 menit**; cakupan admin berisi
60/120/180/600 menit (genap) → median **150 menit**, yaitu rata-rata dua nilai
tengah.

## 5. Fase 5 — kontrak API (150 pemeriksaan)

Dijalankan lewat server HTTP sungguhan (`php -S` pada port bebas) dengan token
bearer per peran.

| Kelompok | Isi | Hasil |
| --- | --- | --- |
| KR-1 | Envelope `success/data/error`, kunci respons, pagination gaya V1, 404 untuk rute tak dikenal | LULUS |
| KR-2 | Cakupan tiap peran dikenali server; cakupan non-admin tidak pernah melebihi admin; baris orang tua hanya santri terhubung; CSV orang tua lebih sedikit dari CSV admin | LULUS |
| KR-3 | 5 kombinasi rute×peran memperluas cakupan → 403 `FORBIDDEN`; `mode=admin` dari orang tua tidak memberi hak | LULUS |
| KR-4 | Konsistensi total ringkasan = cetak = CSV untuk 4 peran, dengan sidik jari kriteria identik | LULUS |
| KR-5 | CSV lewat API: BOM, jumlah baris cocok, header cocok, 0 sel berbahaya, nama berkas, tidak terpotong, mengabaikan pagination; HTML cetak memuat penanda wajib | LULUS |
| KR-6 | 8 bentuk input tidak valid → 422 `VALIDATION_FAILED` tanpa data | LULUS |
| KR-7 | Tanpa token dan token palsu → 401 pada 5 rute; token setelah logout → 401 | LULUS |
| KR-8 | `EXPLAIN` 200 untuk admin, 403 untuk 3 peran lain | LULUS |
| KR-8d…f | **Regresi:** akun tanpa kemampuan perizinan → **403, bukan 500** (lihat §7) | LULUS |
| KR-9 | `/`, `/profile`, `/me/capabilities`, `/izin/pengajuan`, `/izin/antrean`, `/notifikasi`, `/notifikasi/belum-dibaca`, `/reports`, `/reports/filters`, `/reports/print` tetap berfungsi; kontrak `/reports` V1 tidak tercampur bentuk V2 | LULUS |
| KR-10 | Respons laporan tidak memuat token push, secret hash token API, password basis data, atau nama field credential | LULUS |

## 6. Fase 5 — smoke test web (79 pemeriksaan)

Server PHP lokal, sesi login sungguhan, cookie jar terpisah per peran.

| Kelompok | Isi | Hasil |
| --- | --- | --- |
| WL-1 | Anonim diarahkan ke halaman masuk dari 3 halaman laporan tanpa membocorkan data | LULUS |
| WL-2 | 4 peran membuka laporan dengan label cakupan benar, ringkasan total dan median tampil, tanpa galat PHP, navigasi memuat tautan laporan | LULUS |
| WL-3 | Nama santri di luar cakupan tidak muncul pada HTML, CSV, maupun halaman cetak | LULUS |
| WL-4 | Halaman cetak memuat 7 penanda wajib dan dikirim `no-store` | LULUS |
| WL-5 | `text/csv`, `attachment`, `nosniff`, header jumlah baris cocok dengan isi berkas, BOM, 0 sel berbahaya, pagination diabaikan | LULUS |
| WL-6 | Perluasan cakupan → 403 pada halaman, CSV (tanpa mengirim berkas), dan cetak | LULUS |
| WL-7 | 3 filter tidak valid → 422 tanpa membocorkan `Fatal error`/`SQLSTATE`/`mysqli`, dengan jalan keluar "Bersihkan filter" | LULUS |
| WL-8 | `portal/index.php`, `portal/izin.php`, `portal/izin_antrean.php`, `portal/notifikasi.php`, panel kanal Fase 4, dan laporan absensi V1 tetap terbuka | LULUS |

## 7. Cacat yang ditemukan pengujian dan diperbaiki

Dicatat apa adanya karena inilah nilai sesungguhnya dari pengujian ini.

| # | Cacat | Ditemukan oleh | Perbaikan |
| --- | --- | --- | --- |
| 1 | `IzinService::scopeFor()` melempar `IzinException`, sedangkan `api/v1/index.php` hanya menangani `ApiException`. Akibatnya akun **tanpa kemampuan perizinan** menerima `500 SERVER_ERROR` alih-alih `403` pada SELURUH endpoint laporan — membocorkan galat internal dan menyembunyikan penolakan yang benar. | `tests/v2_phase5_api_contract.php` (KR-8c) | `IzinReportService::filterFor()` menerjemahkan `IzinException` → `ApiException` beserta statusnya; `explain()` melempar `ApiException` langsung. Regresi dikunci oleh KR-8d…KR-8f. |
| 2 | Latihan restore memakai `multi_query()` dan gagal senyap pada backup besar. | `bin/v2_phase5_backup_restore_drill.php` | Diganti klien baris perintah (`mariadb`/`mysql < backup.sql`) — jalur yang sama dengan pemulihan sungguhan di cPanel. |
| 3 | Credential pada berkas opsi MySQL terpotong diam-diam karena `#` pada password memulai komentar, menghasilkan `Access denied` yang membingungkan. | latihan restore | Seluruh nilai pada berkas opsi dikutip dan di-escape; `protocol=TCP` disetel eksplisit. |
| 4 | Pengujian integrasi bergantung pada rentang tanggal saja untuk isolasi, sehingga jumlah yang diharapkan berubah bila berkas uji lain berjalan lebih dulu. | putaran suite lengkap | Fixture diberi penanda unik yang ikut menjadi filter pencarian. |

## 8. Aplikasi mobile

Dijalankan dari repositori `alhasanApps` pada branch `prd-v2-fase-5`:

| Perintah | Hasil |
| --- | --- |
| `npx tsc --noEmit` | **BERSIH** (0 galat) |
| `npx expo lint` | **BERSIH** (0 galat, 0 peringatan) |
| `npx expo export -p web` | **TIDAK DAPAT DIJALANKAN** pada lingkungan ini |

`expo export -p web` gagal dengan `TypeError: _ws(...).WebSocketServer is not a
constructor` saat Metro dimulai — sebelum satu baris kode aplikasi dibaca.
Penyebabnya `node_modules` terpasang untuk macOS sedangkan lingkungan kerja ini
Linux. **Dibuktikan pre-existing:** dengan seluruh perubahan Fase 5 di-`git
stash`, baseline yang tidak diubah gagal dengan galat yang sama persis. Karena
itu ini **bukan regresi Fase 5**, tetapi tetap ditandai MENUNGGU VERIFIKASI dan
wajib dijalankan ulang pada mesin pengembang macOS.

Ketergantungan yang ditambahkan: `expo-file-system@~57.0.5` — versi yang **sudah
ada** pada `package-lock.json` sebagai dependensi transitif Expo. Perbandingan
lockfile sebelum/sesudah: **0 paket berubah versi**; `expo` tetap 57.0.15 dan
`react-native` tetap 0.86.2. Deklarasi ini hanya membuat dependensi yang sudah
terpasang menjadi eksplisit.

## 9. Yang TIDAK diuji

| Tidak diuji | Alasan | Tindak lanjut |
| --- | --- | --- |
| Push dan deep link pada perangkat Android/iOS NYATA | Perangkat fisik tidak tersedia | `uji-manual-tertunda.md` §1–§2 |
| Receipt akhir terhadap Expo NYATA | Tidak ada trafik keluar pada pengujian | Aktifkan cron receipt lalu bandingkan sebaran status |
| Pengiriman WhatsApp oleh penyedia NYATA | DITANGGUHKAN | `../phase-v2-4/whatsapp-provider-checklist.md` |
| Migrasi/restore pada database PRODUKSI | Dilarang pada pekerjaan ini | `migration-and-rollback.md` |
| Cron pada cPanel produksi | Memerlukan izin pengguna | `cpanel-deployment.md` §4 |
| PHP versi cPanel | Sandbox memakai PHP 8.4 | Ulangi `php -l` pada staging cPanel |
| Data produksi dan performa nyata | Dilarang | Staging dengan salinan tersamar |
