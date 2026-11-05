# Implementasi — pencarian santri, pemisahan IBTIDA PA/PI, dan aturan versi aplikasi

**Implementator:** Claude Code, 5 November 2026. **Auditor berikutnya:** Codex. Satu fase sesuai `docs/pencarian-santri-aplikasi/rencana.md` dan `prompts.md`.

| Repo | Branch | Baseline | Commit fase |
| --- | --- | --- | --- |
| alhasanApps | `claude/pencarian-santri-absensi-ibtida` | `origin/main` `bd7833f` (`expo.version` 1.2.0, diverifikasi di GitHub) | `e4a0642` |
| WebAlHasan | `claude/pencarian-santri-absensi-ibtida` | `origin/main` `1c652e3` (PR #115, memuat rencana `1fb9ea5`/`21ed77e`) | commit berikutnya pada branch ini |

Tanpa worktree; tidak menulis di `main`. Perubahan `package.json`/`package-lock.json` salinan kerja aplikasi dan empat direktori web belum terlacak milik pekerjaan lain tidak disentuh/di-commit (commit `package.json` = HEAD + tiga skrip `versi:*`). Tanpa migrasi. **Tidak merge, deploy, migrasi produksi, atau rilis aplikasi.**

## Perubahan

### Aplikasi (alhasanApps)

- **Absensi `src/app/meeting/[id].tsx`:** kolom cari nama/NIS di atas daftar peserta, disaring lokal dari data pertemuan (tanpa permintaan per ketikan), tidak membedakan huruf besar/kecil, diakritik, maupun urutan kata; tombol "Hapus pencarian"; hasil kosong → "Tampilkan semua santri". Pertemuan dengan lebih dari satu kelas menampilkan tab `Semua · n`, satu tab per kelas pertemuan, dan `Kelas belum tercatat · n` (hanya bila ada). Pada tampilan Semua tiap kelas berjudul pemisah. Nomor urut stabil terhadap cari/tab. Draf status/catatan tetap per `student_id`; ringkasan, progres, dan payload simpan tetap seluruh `meeting.students`. Tombol "Semua hadir (N)" + keterangan dan `accessibilityHint` bahwa berlaku untuk seluruh N santri termasuk yang tidak tampil.
- **Pembantu murni `src/lib/peserta-saring.ts`:** `cocokSantri`, `tabKelas`, `saringPeserta`, `nomorUrut`, `labelPendekKelas`. Komponen `src/components/peserta-saring.tsx`.
- **Rincian laporan `report/[id]` dan rincian kehadiran Keamanan:** cari + tab yang sama, hanya baca.
- **Daftar izin Keamanan:** kolom "Cari santri" → `GET /keamanan/izin?q=` (server, seluruh halaman, kembali ke halaman 1). **Pelanggaran/konseling Keamanan:** placeholder "Nama atau NIS santri". **Daftar pembinaan (pelanggaran, kasus):** kolom cari → `?q=` ke `/v3/pelanggaran` dan `/v3/konseling/kasus`; kolom tetap terpasang selama memuat.
- **`Chip`:** `aria-selected` (tab) / `aria-checked` (radio). Sebelumnya React Native Web tidak memancarkan status terpilih ke DOM sama sekali; iOS kini membacakan "radio button, checked".
- **Versi:** `scripts/versi-aplikasi.mjs`, `npm run versi:naik -- <tingkat>`, `versi:periksa [-- --kerja | --dasar <ref>]`, `versi:tampil`. `expo.version` 1.2.0 → **1.3.0** (minor). Aturan dicatat di `AGENTS.md` kedua repo.

### Web (API minimal)

- `app/Izin/IzinLaporanService.php` + `IzinLaporanRepository.php`: saringan aditif `q` (string, ≤ 100 karakter, `%`/`_` harfiah) `(s.nama_santri LIKE ? OR s.nis LIKE ?)` di **pembangun WHERE tunggal** yang sama untuk halaman, ringkasan, dan ekspor, sehingga tetap di dalam cakupan peran. Audit ekspor V7 menyimpan `q: true`, bukan kata kunci (dapat berisi nama santri).
- `app/Keamanan/KeamananApiService.php`: `q` masuk daftar parameter `GET /keamanan/izin` yang diteruskan.
- `app/V3/PelanggaranRepository.php`, `KonselingRepository.php`: pencarian yang sudah ada (nama) kini juga NIS. Berlaku untuk halaman web, REST pembinaan, dan Keamanan; penyaring kerahasiaan/cakupan tidak berubah.

## Inventaris pemilih/daftar santri

| Layar aplikasi | Keadaan | Tindakan |
| --- | --- | --- |
| Absensi pertemuan | tanpa cari, kelompok kelas tanpa tab | **cari lokal + tab** |
| Rincian laporan pertemuan (guru) | tanpa cari | **cari lokal + tab** |
| Rincian kehadiran Keamanan | tanpa cari | **cari lokal + tab** |
| Daftar izin Keamanan (berhalaman) | tanpa cari | **`q` server baru** |
| Pelanggaran/konseling Keamanan (berhalaman) | cari nama (server) | **+ NIS di server** |
| Daftar pembinaan pelanggaran/kasus (berhalaman) | tanpa cari; server sudah punya `q` nama | **kolom cari + NIS di server** |
| Pengajuan izin pengurus, perizinan, laporan izin, catat kesehatan, daftar kesehatan, keuangan (santri/tabungan/pembayaran/tunggakan/laporan), pemilih rapor nilai/sikap/verifikasi, pembinaan buat | sudah ada | dipertahankan |
| Laporan kehadiran Keamanan (berhalaman, baris per santri per pertemuan) | tanpa cari | **tidak diubah** — `ReportFilter` dipakai bersama laporan V1 web/CSV/REST; penambahan `q` di sana perlu keputusan tersendiri |
| Rekomendasi tindak lanjut, informasi untuk keluarga, antrean/riwayat izin wali pembimbing | tanpa cari | tidak diubah — daftar pendek per cakupan; endpoint tanpa `q` |

## Pemeriksaan perbedaan Android/iOS

Laporan Human Developer tentang perbedaan tampilan PA/PI **tidak dapat direproduksi** pada lingkungan uji: Android emulator dan iOS simulator dengan sumber yang sama menampilkan pengelompokan, tab, hitungan, pencarian, dan hasil simpan yang sama. Penyebab pada perangkat pengguna **tidak dinyatakan** — perangkat fisik, build terpasang di perangkat pengguna, akun, dan data produksi tidak tersedia. Catatan: sebelum paket ini pengelompokan hanya bergantung pada `task.classes` (>1) dari server; build lama atau server tanpa `classes` menampilkan satu daftar. Audit kelas gabungan Fase 2 mencatat build iOS Release baru saat itu crash (F-1); build iOS Release baru paket ini **berjalan** (lihat Native).

Kontrak diverifikasi: respons `GET /meetings/{id}` pada kedua platform (dicatat proksi, tanpa nama) memuat `task.classes = [PA, PI]` dan pasangan `student_id`–`class.id` identik `[[1722,340],[1723,340],[1724,340],[1725,341],[1726,341]]`. SHA-256 badan respons berbeda antarplatform; field yang berbeda tidak diurai (dugaan cap waktu pembukaan tiap putaran, belum diverifikasi).

## Bukti (`bukti/`)

Lingkungan: `bukti/lingkungan.txt` (PHP 8.4.14, MariaDB 12.3.2, Node 26.7.0, Maestro 2.11.0, Xcode 27.2). Semua data sintetis lokal.

| Uji | Hasil | Berkas |
| --- | --- | --- |
| Unit aplikasi (termasuk `peserta-saring` 7, `versi-aplikasi` 6) | **77/0** | `mobile-unit.txt` |
| TypeScript, lint Expo (salinan bersih, lock ter-commit, tanpa `.env*`) | exit 0, exit 0 | `mobile-tsc.txt`, `mobile-lint.txt` |
| `npm run versi:periksa` pada `e4a0642` | LULUS 1.2.0 → 1.3.0 (minor) | `versi-periksa.txt` |
| Kontrak server `tests/pencarian_santri_http.php` C-1…C-6 | **26/0**; kontrol kode `1c652e3` **10/16** | `regresi-web/pencarian-santri-http.txt`, `kontrol-baseline/kontrak-http-baseline.txt` |
| Expo web absensi `uji-pencarian-santri-absensi-app.mjs` A-1…A-12 | **53/0** (Metro `--clear`); kontrol aplikasi `bd7833f` **1/2** (tab tidak ada) | `expo-web/expo-absensi.txt`, `kontrol-baseline/expo-absensi-baseline.txt` |
| Expo web daftar berhalaman `uji-pencarian-santri-daftar-app.mjs` D-1…D-5 | **24/0** | `expo-web/expo-daftar.txt` |
| Native Android & iOS `tests/android/pcs_native.py` (alur Maestro identik) | **7/0 · 7/0** | `native/android.txt`, `native/ios.txt` |
| Regresi Expo: kelas gabungan F2 57/0, Keamanan F4 46/0 | sama dengan audit sebelumnya | `expo-web/` |
| Regresi server: Keamanan F4 HTTP 66/0, F3 HTTP 44/0, F3 integrasi 44/0, V7 F3 integrasi 112/0 + HTTP 43/0, Tahap 5 HTTP 106/0 | lulus | `regresi-web/` |
| Statis: Tahap 5 96/0, V3 F4 exit 0; Keamanan F3 135/3, V7 F3 41/3 | kegagalan identik baseline `1c652e3` (134/3, 41/3; selisih = lint berkas uji baru) | `regresi-web/`, `kontrol-baseline/` |
| Statis Keamanan F4 | 93/3 vs baseline 89/2 | lihat oracle di bawah |
| Lint PHP 6 berkas; log server tanpa galat PHP | 6/6; 0 | `regresi-web/lint.txt` |

**Oracle historis yang kini gagal karena penambahan sah:** `penugasan_keamanan_f4_static` "Satu-satunya isian: kolom cari santri (pelanggaran, konseling)" menghitung tepat dua `<Field ` di layar Keamanan aplikasi; daftar izin Keamanan kini mempunyai kolom cari ketiga. Dua kegagalan lain identik baseline. Oracle tidak dilonggarkan.

**Yang dicakup Expo web:** tab dan hitungan = API; tiap tab hanya kelompoknya; cari nama huruf besar/potongan NIS/kosong/hapus; nama sama NIS berbeda (fixture mengganti nama santri PI kedua); draf PA/PI bertahan lintas tab/cari; "Semua hadir" dari tab PA mengubah santri PI tersembunyi dan tidak menyentuh catatan; simpan dari tab PI dengan kata kunci aktif → payload lima `student_id` unik tepat sekali, DB satu absensi guru + lima santri dengan status/catatan per ID; muat ulang; kelas asal hilang/di luar daftar → `Kelas belum tercatat · 2`; gabungan non-IBTIDA → nama kelas lengkap; server lama tanpa `classes`/`class` → tanpa tab, cari bekerja, koreksi menyimpan seluruh peserta; satu kelas → tanpa tab; rincian laporan dan Keamanan; 375 px; tanpa permintaan keluar 127.0.0.1. Daftar: izin santri dari halaman ≥ 3 ditemukan setelah berada di halaman 2 (q dikirim, halaman 1), galat 503 berulang → Coba lagi, pencabutan Keamanan → ditolak tanpa data, pelanggaran NIS, pembinaan murobi NIS/nama/tanpa hasil, santri di luar cakupan murobi → 0.

**Kontrak server (C-1…C-6):** untuk `GET /keamanan/izin?q=` (1040 baris, 42 halaman) dan `/keamanan/pelanggaran|konseling`, `/v3/pelanggaran`, `/v3/konseling/kasus` (admin dan murobi), hasil `q` = saringan lokal atas **seluruh halaman tanpa q milik akun yang sama**, sehingga terbukti menjangkau halaman lain tanpa memperlebar cakupan; santri nonaktif dan kasus Rahasia tidak terbuka; `q` array/101 karakter → 422; `%`/`_` harfiah; admin tanpa penugasan Keamanan tetap 403.

### Native

| | Android | iOS |
| --- | --- | --- |
| Perangkat | emulator `sdk_gphone16k_arm64` Android 16/API 36 | simulator iPhone 17 Pro iOS 26.2 |
| Build | **APK Release baru** dari sumber `e4a0642` + lock ter-commit, `versionName 1.3.0`, tanda tangan debug, SHA-256 `842b193e…de11` | **Release arm64 simulator baru** dari sumber yang sama, `CFBundleShortVersionString 1.3.0`, ad-hoc Xcode (`CODE_SIGN_IDENTITY=-`), executable `1d7410f9…`, `main.jsbundle` `d0f001e2…` |
| Bundel | API `127.0.0.1:8962` = 1, host produksi = 0 | sama |
| Hasil | 7/0 | 7/0 |

Alur identik: login `sbx_guru_biasa` → tautan jadwal gabungan → Buka pertemuan → tab Semua 5/PA 3/PI 2 → PA hanya PA → PI hanya PI, PI1 Izin → cari NIS PA1 pada tab PI → kosong → Semua → PA1 Sakit → simpan dengan pencarian aktif → DB pertemuan 722: satu guru, lima santri unik, PI1 Izin, PA1 Sakit, tiga Hadir; proksi: satu PUT lima peserta unik, muatan identik antarplatform → cold start + tautan pertemuan → tab PI, Izin PI1 terpilih dari server. Fixture dipulihkan dari snapshot yang sama sebelum tiap platform (ID sama). Tangkapan `native/tangkapan/{android,ios}-01…08.png`.

**Batas build native (penting):** `ios/` dan `android/` tidak dilacak Git. Build uji memakai proyek native prebuild lokal (iOS dari salinan audit profil Fase 2 yang memuat `expo-image-picker`; Android dari prebuild lokal lama yang belum menerapkan `blockedPermissions`), dengan versi native disetel 1.3.0 secara manual dan **`usesCleartextTraffic` hanya pada manifest release salinan uji** agar APK Release dapat menjangkau API `http://127.0.0.1`. Ini bukan build distribusi; izin APK uji tidak mencerminkan konfigurasi `app.json`. Build distribusi tetap melalui EAS (prebuild dari `app.json`). Upaya iOS pertama tanpa tanda tangan (`CODE_SIGNING_ALLOWED=NO`) gagal login karena entitlement Keychain tidak ada (masalah penandatanganan uji, sesuai catatan validasi V6); build yang diuji ditandatangani Xcode ad-hoc.

Kesalahan alat uji selama penyusunan (diperbaiki di alat, bukan aplikasi): pembacaan `aria-checked` sebelum Chip memancarkannya; uji meminta 1× 503 padahal klien mengulang GET 5xx dua kali; balapan teks lama saat pencarian pembinaan (sekaligus UX diperbaiki: kolom cari tetap terpasang saat memuat); selektor Maestro mengetuk judul panel "Buka pertemuan"; `hideKeyboard` Maestro tidak andal di iOS (diganti Enter); cache Metro tidak memuat ulang perubahan Chip sampai `--clear`.

## Penafsiran (menunggu tinjauan auditor)

- **P-1** Tab tampil untuk **setiap** pertemuan dengan lebih dari satu kelas, bukan hanya IBTIDA. Label dipendekkan menjadi PA/PI (atau Putra/Putri) hanya bila nama kelas sama persis kecuali satu kata bagian itu; selain itu nama kelas lengkap.
- **P-2** Peserta yang kelas asalnya ada tetapi **di luar** `task.classes` masuk `Kelas belum tercatat` (rencana: "hilang/di luar daftar"); kartunya tetap menampilkan nama kelas asal dari server. Ini mengubah perilaku lama Fase 2 yang membuat kelompok bernama kelas itu.
- **P-3** "Semua hadir" tetap mengubah seluruh peserta (sesuai label baru), bukan subset terlihat; catatan tidak diubah.
- **P-4** Pencarian lokal mencocokkan setiap kata pada nama atau NIS; pencarian server memakai satu potongan (`LIKE`) sesuai kontrak V3 yang ada.
- **P-5** `versi:periksa` menganggap perubahan di luar `docs/`, `tests/`, `scripts/`, `*.md`, dan beberapa berkas konfigurasi pengembang sebagai perubahan aplikasi (termasuk `package.json`/`app.json`); hanya satu kenaikan tepat (`major|minor|patch` dari baseline) yang lulus.
- **P-6** NIS ditambahkan ke pencarian V3 yang sudah ada (berdampak juga pada kolom cari halaman web pembinaan) — aditif, cakupan tidak berubah.

## Batas dan yang belum diuji

Perangkat fisik Android/iOS, build distribusi EAS (AAB/IPA/TestFlight), iOS Debug/dev client, Push, MariaDB 10.6 produksi/MySQL/cPanel, dan UAT **belum diuji**. Paritas produksi tidak dinyatakan dari emulator/simulator. Laporan kehadiran Keamanan berhalaman belum dapat dicari (lihat inventaris). Gerbang produksi terdahulu (V5/V6/V7, kelas gabungan) tidak berubah.

## Reproduksi

`docs/pencarian-santri-aplikasi/implementasi/perintah-uji.sh` (unit/tsc/lint/versi, kontrak, dua suite Expo web). Native: build Release seperti di atas, proksi `python3 tests/android/pcs_proksi.py 8962 8961 <dir>`, `adb reverse tcp:8962 tcp:8962` untuk Android, lalu `DB_NAME=test_pcs_app_test python3 tests/android/pcs_native.py --platform android|ios --device <id> --ids <ids.json> --proksi <dir> --out <dir>` setelah memulihkan fixture kelas gabungan yang sama.

## Handoff ke Codex

Audit satu fase ini terhadap kriteria `rencana.md` pada kedua branch `claude/pencarian-santri-absensi-ibtida`. Tinjau P-1…P-6, oracle F4 statis, batas build native, dan celah inventaris yang tidak diubah. Jangan merge, deploy, atau merilis aplikasi.
