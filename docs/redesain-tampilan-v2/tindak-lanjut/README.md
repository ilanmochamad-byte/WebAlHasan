# Hasil tindak lanjut audit redesain tampilan aplikasi gelombang 2

13 November 2026 · Branch `claude/tindak-lanjut-redesain-v2` pada kedua repositori

**Pekerjaan ini tidak memiliki auditor independen** (keputusan Human Developer 13 November 2026, khusus pekerjaan ini). Laporan ini adalah pemeriksaan mandiri implementator dengan kendali mutu §7 [rencana](rencana.md), **bukan audit independen**. Batas yang belum terbukti ditulis apa adanya di §6.

## 1. Baseline dan cakupan

| Repo | Baseline `origin/main` | Commit pekerjaan ini |
| --- | --- | --- |
| alhasanApps | `9c1bcd9` (PR #33, versi 1.8.0) | `f896e11`, `ddd193a` → versi **1.9.0** |
| WebAlHasan | `dc4e21d` (PR #122) | `04dcfbc`, `ae960bb`, `bec1354`, `b185a33`, dan commit laporan ini |

Hanya tampilan dan navigasi aplikasi. Endpoint, parameter, payload, hak akses, kerahasiaan, idempotensi, versi optimistik, `expo-screen-capture`, kode server, dan migrasi **tidak berubah**; dibuktikan paritas jaringan §4.3 dan diff. Tanpa dependensi atau modul native baru; manifest dan lockfile ter-commit sama dengan baseline. Perubahan `package.json`/`package-lock.json` mobile serta empat direktori web milik pekerjaan lain tidak disentuh dan tidak di-commit. Kode web tidak berubah: diff web hanya uji, probe, skrip perintah, dan dokumen.

## 2. Keputusan KT yang dipakai

| ID | Keputusan Human Developer | Penerapan |
| --- | --- | --- |
| KT-1 | Suite lama boleh disesuaikan | Hanya pencari elemen dan langkah navigasi; pernyataan bisnis tidak diubah. Daftar lengkap §3 |
| KT-2 | Logo lime dipertahankan | Warna logo tidak diubah |
| KT-3 | Butir yang butuh data server dipertahankan | StatGrid menu petugas Keuangan/Kesehatan, kamar/kelas baris izin V2, sisa tagihan di atas 60 baris |
| KT-4 | Perangkat fisik dan Push nyata | Tidak tersedia pada pekerjaan ini; tetap terbuka (§6) |
| KT-5 | Tanpa auditor independen | Kendali mutu §7 rencana dijalankan; lihat §4 |

## 3. Butir yang dikerjakan

### Selisih desain (§4.1–§4.9 rencana gelombang 2)

| ID | Perubahan | Suite yang disesuaikan (KT-1) |
| --- | --- | --- |
| TL-01 | Layar Masuk menyapa "Assalamu'alaikum" + "Masuk ke akun pesantren Anda" | 11 suite: penanda Beranda `Assalamu` → `/Assalamu.alaikum,/` (sapaan berkoma kartu identitas), agar tidak cocok dengan layar Masuk |
| TL-02 | Ubah/hapus foto profil lewat lembar bawah "Foto profil" | `uji-profil-impor-f2-app`, `-android`: ketuk "Ubah foto" sebelum tombol lama |
| TL-03 | Profil: kelompok Akun dan Pemberitahuan terpisah; "Data diri ›" membuka `akun/data-diri` baru | Penanda Profil termuat → "Perangkat & push" (5 suite); B-D profil membuka Data diri dulu; daftar kelompok rtv2-fase1 mengikuti §4.2; Android F1 memakai daftar penanda Profil yang tidak bergantung posisi gulir |
| TL-04 | Jarak atas ganda Profil iOS diperbaiki (pola P2-16) | — |
| TL-05 | "Jadwal hari ini" Beranda memakai `EntityRow` jam-kiri (`JadwalBaris`); layar Jadwal tetap `ScheduleCard` | — |
| TL-06 | Tombol absensi "Kirim absensi" | 8 suite mengganti label; perekam jaringan menerima kedua label |
| TL-07 | Layar sukses "Pengajuan terkirim" + "Lihat pengajuan" (V2 dan V7; V7 menyebut "Menunggu tinjauan pembimbing") | rtv2-fase2 menunggu layar sukses lalu mengetuk; perekam menerima susunan baseline dan baru |
| TL-08 | Rincian izin: tindakan sekunder (Tetapkan murobi/pembimbing, Batalkan) di ⋯ lembar bawah; "Muat ulang" izin wali jadi ikon kepala; "Tetapkan pembimbing" ke bilah bawah | rtv2-fase2 dan perekam membuka ⋯ bila tombol tidak di badan layar |
| TL-09 | Sakelar "Push di perangkat ini" menggantikan dua tombol | — |
| TL-11 | "Bayar dari tabungan" pindah ke ⋯ rincian tagihan | `uji-v6-fase4-app`, rtv2-fase3-jaringan, rtv2-fase3-android |
| TL-12 | Kartu anak wali: pintasan Tagihan dan Mutasi tabungan → rincian anak pada tab terkait (parameter `bagian`) | — |
| TL-13 | Pratinjau terbit satu santri memakai StatGrid Akan terbit / Sudah ada / Dilewati / Total terbit | — |
| TL-14 | Rincian pembinaan bertab Ringkasan / Sesi / Riwayat | `uji-v3-fase5-mobile`, `uji-form-pembinaan`, rtv2-fase4-jaringan membuka tab Sesi |
| TL-15 | Koreksi nilai dan sikap beralasan lewat lembar bawah (mode pengganti tetap di halaman karena alasan wajib untuk semua tindakan) | — |
| TL-16 | Riwayat versi dan riwayat akses kunjungan pindah ke ⋯; halaman riwayat akses memakai garis waktu | `uji-v5-fase4-app` membuka ⋯ |
| TL-31 | Penanda privat catatan klinik dinaikkan ke atas rincian | — |
| TL-10 | FAB web: peralihan ke tombol atas memakai lebar FAB terukur, bukan hanya `fontScale` | — |

Rute baru `/akun/data-diri` ditambahkan ke matriks K-10 (perluasan daftar, bukan pelonggaran).

### Aksesibilitas

| ID | Perubahan |
| --- | --- |
| TL-20 | Token status kehadiran dikunci untuk ikon dan batang saja; uji sumber menolak pemakaiannya sebagai warna teks biasa |
| TL-22 | Pada teks sistem > 1,35× bilah tindakan ikut digulir di akhir isi (Edit profil, Buat izin, Absensi, Rincian tugas, Rincian izin, kerangka izin wali) — kepala dua baris tidak lagi menghimpit area isi |
| TL-23 | Status pil pada setiap `EntityRow` ikut dibacakan pembaca layar sebagai petunjuk; nama tombol tidak berubah |
| TL-21, TL-24 | Dipertahankan sesuai KT-2 dan keputusan gelombang 1 |

### Navigasi dan data uji

| ID | Hasil |
| --- | --- |
| **TL-30** | **Bukan cacat aplikasi.** APK Debug 1.9.0 dibangun dari sumber akhir. Putaran pertama mereproduksi 14/2 persis seperti audit; uji manual (logcat + dump aksesibilitas + tangkapan) membuktikan tautan `alhasanapps://rapor/{id}` sesudah gelap + 2,0× **membuka rapor dengan benar** — activity memang dibuat ulang ±0,9 detik oleh perubahan `font_scale`, tetapi tautan tidak hilang. Pengulangan suite: rapor **17/0**, kesehatan **7/0** sesudah fixture dilengkapi satu kunjungan |
| TL-36 | Koreksi duplikasi tautan cold start (audit Fase 3) diuji ulang; tidak ada regresi |
| TL-40 | Suite V4 Fase 5 aplikasi memerlukan rantai fixture dari dump produksi dan **tidak dipakai** (privasi). Diganti `tests/browser/uji-rapor-koreksi-app.mjs` di atas fixture Tahap 7: baseline gagal 4 butir TL-15, kode akhir **9/0** |
| TL-41 | Suite V3 mobile dijalankan pada baseline dan kode akhir dengan DB `webalhasan_v3_phase1_test` yang dipulihkan dari dump di antaranya: **keduanya 10 lulus lalu berhenti pada formulir portal web `#case-privacy`** — kegagalan historis di luar aplikasi, identik, bukan regresi |
| TL-42 | Suite gelombang 1 (`uji-redesain-fase1/2/3`) dijalankan pada baseline dan kode akhir: hasil **identik** (Fase 3 16 lulus / 5 gagal pada kedua sisi; Fase 1 dan 2 berhenti pada penanda lama di kedua sisi). Kegagalan historis, tidak dilonggarkan |
| TL-43 | Setiap suite memakai dump fixture sendiri yang dipulihkan sebelum jalan; tidak ada lagi fixture yang bermutasi antar-suite |
| TL-44 | Paritas jaringan seluruh gelombang, §4.3 |

## 4. Bukti

### 4.1 Expo web (kode akhir)

| Suite | Hasil |
| --- | ---: |
| Fase 1 | 166 / 0 (1 dilewati) |
| Fase 2 pengajian · izin | 68 / 0 · 146 / 0 |
| Fase 3 Keuangan | 258 / 0 |
| Fase 4 pembinaan-rapor · kesehatan · Keamanan · BSA | 97 / 0 · 70 / 0 · 60 / 0 · 18 / 0 |
| **K-10 seluruh rute** | **308 / 0** |
| Profil/Impor · kelas gabungan · pencarian absensi · pencarian daftar · peserta absensi | 105 / 0 · 57 / 0 · 53 / 0 · 24 / 0 · 18 / 0 |
| V5 F4 · V5 F5 · V6 F4 · V6 F5 · V7 F4 · Keamanan F4 | 78 / 0 · 20 / 0 · 45 / 0 · 20 / 0 · 48 / 0 · 46 / 0 |
| Tagihan satu santri · probe audit · probe F-1 · V-1 | 27 / 0 · 3 / 0 · 16 / 0 · 5 / 0 |
| Rapor koreksi (uji baru TL-40) | 9 / 0 |

Nol permintaan ke luar `127.0.0.1`; server API uji melaporkan nol galat PHP.

### 4.2 Probe tindak lanjut dan kontrol baseline

`tests/browser/uji-tindak-lanjut-redesain-v2.mjs`, dijalankan pada Expo web baseline 1.8.0 dan kode akhir dengan fixture yang sama:

| Bagian | Baseline (1.8.0) | Kode akhir |
| --- | ---: | ---: |
| akun (TL-01, TL-02, TL-03, TL-09) | 1 / 7 | **8 / 0** |
| izin (TL-07, TL-08, TL-10) | 3 / 7 | **10 / 0** |
| keuangan (TL-11, TL-12, TL-13) | 2 / 5 | **7 / 0** |
| pembinaan (TL-14) | 1 / 1 | **4 / 0** |
| kesehatan (TL-16, TL-31) | 3 / 3 | **6 / 0** |

**Tidak ada satu pun butir TL yang lulus pada baseline**; yang lulus di sana hanya penyiapan fixture dan pemeriksaan "tanpa permintaan ke luar". Dua pemeriksaan yang semula terlalu longgar diperketat setelah ketahuan lulus di baseline (butir ⋯ kini wajib muncul karena menu dibuka).

Uji sumber `tests/tindak-lanjut-v2.test.ts` (mobile): kode akhir lulus, baseline **gagal 5 dari 6** butir.

### 4.3 Paritas jaringan baseline 1.8.0 vs kode akhir

Dump fixture yang sama dipulihkan sebelum setiap perekaman.

| Alur | Langkah sama / berbeda | Permintaan |
| --- | ---: | ---: |
| Akun dan Profil (Fase 1) | 23 / 0 | 143 |
| Pengajian | 10 / 0 | 32 |
| Izin dan notifikasi | 44 / 0 | 198 |
| Keuangan | 38 / 0 | 188 |
| Pembinaan dan Rapor | 34 / 0 | 199 |
| Kesehatan | 20 / 0 | 104 |
| Keamanan | 11 / 0 | 41 |
| **Total** | **180 / 0** | **905** |

Setiap langkah mutasi tetap satu kunci idempotensi dan satu POST.

### 4.4 Unit, TypeScript, lint

`npx tsc --noEmit` dan `npm run lint` exit 0. Unit **119 / 0** (baseline 113 / 0; selisih = uji sumber baru).

### 4.5 Android emulator

AVD Medium_Phone, Android 16 / API 36. **APK Debug 1.9.0 dibangun baru** dari sumber akhir (`expo prebuild` + `gradlew --offline assembleDebug`, `versionName=1.9.0`), JavaScript dari Metro salinan tanpa `.env*`.

| Suite | Hasil |
| --- | ---: |
| Fase 4 pembinaan/rapor (t7), termasuk gelap + 2,0× | **17 / 0** |
| Fase 4 kesehatan (v5), termasuk gelap + 2,0× laporan klinik | **7 / 0** |
| Fase 4 Keamanan (pk) | **10 / 0** |
| Fase 2 pengajian | **13 / 0** |
| Fase 2 izin | **54 / 0** |
| Fase 3 Keuangan | **25 / 0** |

Fase 1 Android (tujuh peran, kembali, password wajib, gelap, 1,3×, 2,0×): **71 / 0**.

Kegagalan pada putaran pertama semuanya terbukti artefak lingkungan atau penanda uji, bukan aplikasi, dan hilang pada pengulangan: fixture Kesehatan belum berisi kunjungan (tautan ber-ID kosong), pengukuran target tombol saat paket hilang sesudah emulator boot ulang, dan dialog "System UI isn't responding" pada emulator yang baru boot. Satu kegagalan Fase 1 Android adalah penanda uji yang tertinggal (§3, TL-03): tangkapan membuktikan layar Profil terbuka benar, hanya penandanya berada di luar layar karena halaman tergulir; sesudah penanda diperluas, suite lulus 71 / 0.

### 4.6 iOS simulator

iPhone 17 Pro, iOS 26.2. Build **Release 1.9.0 arm64 simulator baru** dari sumber akhir (`expo prebuild` + `pod install` + `xcodebuild -configuration Release`, tanda tangan ad-hoc dengan entitlement keychain, API lokal tertanam: nol host produksi di bundel Hermes). Seluruh alur Maestro keempat fase dijalankan pada binary ini.

| Alur | Hasil |
| --- | ---: |
| Fase 1: tujuh peran, kembali, password wajib, tampilan gelap / AX-XL / AX-XXXL / gelap+AX-XXXL | **13 / 13** |
| Fase 2 pengajian: absensi + empat tampilan | **5 / 5** |
| Fase 2 izin: perizinan, geometri FAB, empat peran, wali, notifikasi, empat tampilan | **12 / 12** |
| Fase 3 Keuangan: petugas (ketukan ganda setoran → tepat +1 transaksi), cold start URI, peran, empat tampilan | **7 / 7** |
| Fase 4: pembimbing, rapor wali terang/gelap/gelap+AX-XXXL, kesehatan ×3, Keamanan ×3 | **10 / 10** |

Build pertama tanpa tanda tangan gagal pada `expo-secure-store` (keychain memerlukan entitlement); build ulang ad-hoc memperbaikinya. Tiga alur yang semula gagal adalah penanda uji dan fixture, bukan aplikasi: `kembali.yaml` masih memakai judul kelompok Profil lama (TL-03), `pengajian.yaml` masih memakai label tombol lama (TL-06), dan fixture Keuangan belum memuat tagihan terbit. Sesudah diperbaiki, semuanya lulus.

## 5. Butir yang sengaja dipertahankan

Sesuai KT-3 dan keputusan yang sudah ada: StatGrid menu petugas/admin Keuangan dan Kesehatan (respons akses tidak memuat angka; menambah permintaan dilarang), kamar/kelas pada baris Perizinan V2 (DTO V2 hanya nama/NIS), sisa tagihan di atas batas 60 baris server (tetap "—" dengan keterangan), kerahasiaan pembinaan sebagai dropdown wajib-pilih (PRD V3 §5.5b), serta penafsiran P2-7…P2-16, P3-1…P3-18, P4-1…P4-14 yang sudah diterima audit.

## 6. Batas yang tetap terbuka

- **Perangkat fisik, Push nyata (APNs/FCM) dan ketukan notifikasinya, cold start dari Push, build distribusi EAS, iOS Debug** — memerlukan perangkat dan kredensial Human Developer (KT-4). Navigasi tanpa riwayat dibuktikan lewat alamat langsung (web), tautan saat aplikasi berjalan (Android) dan tautan saat aplikasi mati (iOS), bukan ketukan Push.
- **Kartu pengalih aplikasi** hanya dibuktikan lewat `FLAG_SECURE` dan Recents hitam di Android; cuplikan iOS tidak dapat diperiksa karena perlindungan layar membuat tangkapan hitam.
- **APK Release Android** tidak dapat masuk ke API lokal HTTP (cleartext diblokir, T4-1); fungsi Android diuji lewat Debug baru.
- **MariaDB 10.6 produksi, MySQL, cPanel, UAT** belum diuji; seluruh pengujian memakai PHP 8.4.14 dan MariaDB 12.3.2 lokal dengan fixture sintetis.
- Suite gelombang 1, V3 mobile, dan tiga oracle statis historis tetap gagal sama seperti baseline; tidak dilonggarkan.
- Gerbang produksi V5, V6, V7 dan Keamanan tidak disentuh pekerjaan ini.

## 7. Perintah reproduksi

```sh
# Expo web akhir dan baseline: salinan alhasanApps TANPA .env*, node_modules dari lock ter-commit
EXPO_NO_DOTENV=1 EXPO_OFFLINE=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8971/api/v1 CI=1 npx expo start --web --port <PORT> --clear

# Suite fase dan regresi
APP=http://127.0.0.1:<PORT> bash docs/redesain-tampilan-v2/fase-2/perintah-regresi.sh <keluaran>
APP=http://127.0.0.1:<PORT> bash docs/redesain-tampilan-v2/fase-3/perintah-uji.sh <keluaran> v6f4 v6f5 ss ss-audit probe-f1 probe-v1 fase3
APP=http://127.0.0.1:<PORT> bash docs/redesain-tampilan-v2/fase-4/perintah-uji.sh <keluaran>

# Probe tindak lanjut (jalankan pada baseline DAN kode akhir)
APP=http://127.0.0.1:<PORT> bash docs/redesain-tampilan-v2/tindak-lanjut/perintah-probe.sh <keluaran>

# Uji rapor pengganti suite V4 lama
RTV2_WEB=http://127.0.0.1:8971 RTV2_APP=http://127.0.0.1:<PORT> DB_NAME=<test_*_test> \
  node tests/browser/uji-rapor-koreksi-app.mjs

# Paritas jaringan: dump sama, baseline lalu kode akhir, bandingkan
node tests/browser/uji-redesain-v2-fase4-jaringan.mjs banding <base>/<alur>.json <akhir>/<alur>.json
```

Daftar lengkap perubahan pencari elemen (KT-1) ada di [`perubahan-oracle.txt`](perubahan-oracle.txt).

Metro tidak andal memantau perubahan berkas: setelah mengubah kode, jalankan ulang `expo start --clear` dan periksa bundel sebelum menyimpulkan hasil uji.
