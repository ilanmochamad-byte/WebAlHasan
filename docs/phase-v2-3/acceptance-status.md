# V2 Fase 3 — Status Kriteria Penerimaan

Tanggal pengujian sandbox: **22 Agustus 2026** (audit ulang Codex)
Branch: `prd-v2-fase-3` (WebAlHasan) dan `prd-v2-fase-3` (alhasanApps)
Baseline: commit rilis Fase 2 `f2f674d`, tergabung pada produksi lewat merge `c30add9`

Legenda: **LULUS** = diverifikasi otomatis atau manual pada lingkungan yang
dicatat · **SIMULATOR** = bukti pendukung, bukan pengganti perangkat nyata ·
**MENUNGGU** = masih memerlukan tindakan manusia/perangkat nyata.

## 1. Ringkasan hasil pengujian otomatis

Lingkungan: PHP 8.4.14 (CLI), MariaDB 12.3.2, Node.js 26.7.0, npm 11.19.0,
TypeScript 6.0.3, Expo SDK 57. Database `webalhasan_test` dibuat **dari kondisi
bersih** (struktur tanpa satu baris data produksi), migrasi 001–007 dijalankan
dari nol, lalu diisi fixture sintetis `SBX`. Prosedur lengkap:
`testing-sandbox.md`.

### Backend (WebAlHasan)

| Berkas pengujian | Jenis | Hasil | Pemeriksaan |
| --- | --- | --- | --- |
| `tests/phase1_static.php` | statis V1 | LULUS | 63 |
| `tests/phase2_static.php` | statis V1 | LULUS | 46 |
| `tests/phase3_static.php` | statis V1 | LULUS | 34 |
| `tests/phase4_static.php` | statis V1 | LULUS | 38 |
| `tests/phase5_static.php` | statis V1 | LULUS | 36 |
| `tests/v2_phase1_static.php` | statis V2 Fase 1 | LULUS | 126 |
| `tests/v2_phase2_static.php` | statis V2 Fase 2 | LULUS | 169 |
| `tests/v2_phase3_static.php` | statis V2 Fase 3 | LULUS | 144 |
| `tests/phase2_integration.php` | integrasi V1 | LULUS | 12 |
| `tests/phase3_integration.php` | integrasi V1 | LULUS | 10 |
| `tests/phase4_integration.php` | integrasi V1 (API guru) | LULUS | 14 |
| `tests/phase5_integration.php` | integrasi V1 (laporan, backup/restore) | LULUS | 20 |
| `tests/v2_phase1_integration.php` | integrasi V2 Fase 1 | LULUS | 39 |
| `tests/v2_phase2_integration.php` | integrasi V2 Fase 2 | LULUS | 94 |
| `tests/v2_phase2_navigasi_murobi.php` | navigasi murobi | LULUS | 32 |
| `tests/v2_phase2_web_smoke.php` | smoke web per peran | LULUS | 35 |
| `tests/v2_phase3_api_contract.php` | **kontrak REST API Fase 3 (HTTP)** | LULUS | 116 |

Total: **1.028 pemeriksaan, 0 gagal.** Perintah tunggal untuk mengulang seluruh tes PHP:
`MOBILE_APP_ROOT=/path/ke/alhasanApps bash bin/v2_phase3_run_all_tests.sh`.

`php -l` dijalankan pada seluruh berkas PHP baru/diubah (bagian 8 dari
`tests/v2_phase3_static.php`) dan lulus.

### Aplikasi mobile (alhasanApps)

| Perintah | Hasil |
| --- | --- |
| `npm ci` | LULUS |
| `npx expo lint` (= `npm run lint`) | LULUS, 0 error 0 warning |
| `npx tsc --noEmit` | LULUS, 0 error |
| `npx expo export -p web` | LULUS; rute `/perizinan`, `/izin/[id]`, `/izin/buat` ter-bundle |
| Smoke web + API satu origin (`tests/phase5_web_router.php`) | LULUS (`/`, `/api/v1/`, `/perizinan` → 200) |

## 2. Status kriteria penerimaan PRD Fase 3

| # | Kriteria | Status | Bukti |
| --- | --- | --- | --- |
| 1 | Pengurus menyelesaikan alur mobile dari login sampai pengajuan tersimpan dan terbaca kembali | **LULUS (API + simulator)** / **MENUNGGU (perangkat nyata)** | Kontrak API lulus. Pada Android 16 simulator, pengurus berhasil login, mencari santri sesuai cakupan, memvalidasi tanggal, mengirim pengajuan, melihat detail, mencegah duplikat ketuk-ganda, menangani offline, dan membatalkan dengan alasan. Belum dijalankan pada Android/iOS nyata. |
| 2 | Murobi menerima pengajuan yang ditetapkan, memberi keputusan, hasilnya terlihat pengurus & orang tua | **LULUS** | Kontrak API: antrean murobi memuat pengajuan tujuan; keputusan `201`; hasil terbaca oleh pengurus dan orang tua terhubung. |
| 3 | Admin dapat memberi keputusan pengganti dari website dan aplikasi dengan alasan wajib | **LULUS (web + API + simulator)** / **MENUNGGU (perangkat nyata)** | Web: `tests/v2_phase2_web_smoke.php`. API dan Android simulator: tanpa `alasan_penggantian` ditolak; dengan alasan berhasil sebagai `Admin Pengganti`. Mode Admin dan seluruh tab juga diverifikasi pada iOS simulator. |
| 4 | Orang tua hanya melihat pengajuan santri terhubung, di website dan aplikasi | **LULUS** | `GET /izin/anak` hanya memuat santri berelasi aktif; orang tua lain menerima `403`; daftar izin orang tua hanya memuat santrinya; web smoke Fase 2 menegaskan hal sama. |
| 5 | Manipulasi parameter lintas pengurus, murobi, dan orang tua selalu ditolak server | **LULUS** | 12 pemeriksaan otorisasi lintas peran, termasuk `mode=admin` yang dikirim akun pengurus tetap dilayani sebagai cakupan pengurus. |
| 6 | Retry create dan decision tidak membuat data tambahan | **LULUS** | Retry create → `200`, `idempotent_replay: true`, jumlah baris tidak bertambah. Retry keputusan → `200`, jumlah `izin_keputusan` tetap 1. |
| 7 | Konflik versi atau keputusan kedua menghasilkan `409` tanpa menimpa keputusan pertama | **LULUS** | Keputusan kedua → `409`; versi kedaluwarsa pada penetapan → `409`; dua proses PHP yang benar-benar bersamaan menghasilkan tepat `201` + `409` dengan satu baris keputusan. |
| 8 | Logout mencabut token dan token lama tidak dapat digunakan lagi | **LULUS** | Setelah `POST /auth/logout`, token yang sama menerima `401` pada endpoint V2 maupun V1. |
| 9 | `npm run lint`, `npx tsc --noEmit`, pemeriksaan PHP, tes API, dan tes regresi V1 lulus | **LULUS (sandbox + lint cPanel)** | Lihat tabel bagian 1. Tujuh berkas PHP Fase 3 diulang lint pada PHP cPanel 8.3.33 dan seluruhnya lulus. |
| 10 | Alur utama tiap peran lulus pada ≥1 perangkat Android dan ≥1 perangkat iOS | **MENUNGGU** | Perangkat nyata tidak tersedia di sandbox. Checklist rinci: `mobile-build-and-smoke-test.md`. **Fase 3 tidak boleh dinyatakan selesai sebelum ini dijalankan.** |

## 3. Status persyaratan Fase 3 (PRD §6)

| # | Persyaratan | Status |
| --- | --- | --- |
| 1 | Dokumentasi endpoint akun/capability, santri pengurus, pengajuan, antrean murobi/admin, keputusan, pembatalan, status orang tua, riwayat | LULUS — `endpoint-inventory.md` + `docs/api-v1.md` |
| 2 | Envelope JSON, bearer, pagination, filter, status HTTP, pencabutan token V1 dipertahankan | LULUS — 14 rute V1 diverifikasi statis + regresi HTTP |
| 3 | Capability pada profil; navigasi berbasis hak aktual | LULUS — `profile.capabilities`, `GET /me/capabilities`, tab aplikasi memakai `capabilities.list` |
| 4 | Menu perizinan untuk pengurus, murobi/guru, admin, orang tua | LULUS — tab Perizinan + pemilih mode |
| 5 | Alur pengurus: cari, buat, konfirmasi, kirim, lihat status, batalkan | LULUS (API + layar) / MENUNGGU perangkat |
| 6 | Alur murobi: antrean, detail, setujui/tolak dengan alasan, riwayat keputusan | LULUS (API + layar) / MENUNGGU perangkat |
| 7 | Alur admin: pantau, perbaiki routing, keputusan pengganti | LULUS (API + layar) / MENUNGGU perangkat |
| 8 | Orang tua: daftar anak, status, detail keputusan, riwayat, tanpa tombol mutasi | LULUS |
| 9 | Tombol mutasi dinonaktifkan saat request; retry memakai kunci idempotensi sama | LULUS (statis + `useMutationGuard`; fingerprint payload memastikan retry identik memakai kunci lama dan payload berubah memakai kunci baru) / MENUNGGU verifikasi ketuk-ganda pada perangkat |
| 10 | Penanganan loading, empty, offline, `401`, `403`, `409`, `422` | LULUS (statis + kontrak API) / MENUNGGU verifikasi mode pesawat pada perangkat |
| 11 | Website menyediakan fungsi setara per peran | LULUS — portal Fase 2 sudah lengkap; ditambah penonaktifan tombol saat submit |
| 12 | Pengujian kontrak API, otorisasi lintas peran, idempotensi, concurrency, regresi aplikasi guru | LULUS — 116 pemeriksaan kontrak + regresi V1 |

## 4. Batasan yang dipatuhi

- Tidak ada notifikasi in-app, push, WhatsApp, outbox, atau worker Fase 4
  (ditegakkan oleh pemeriksaan statis Fase 3 bagian 4).
- Tidak ada konversi ke Laravel; backend tetap PHP native modular.
- Tidak ada upgrade Expo SDK; tetap SDK 57 / RN 0.86.
- Tidak ada perubahan arsitektur aplikasi (Expo Router + `expo/fetch` tetap).
- Tidak ada breaking change kontrak API V1.
- Tidak ada modul atau data lama yang dihapus.
- Tidak ada migrasi baru, apalagi migrasi destruktif (lihat `migration-and-rollback.md`).
- Tidak ada deployment produksi otomatis.
- Tidak ada secret, token, password, credential, atau data produksi masuk repo.

## 5. Risiko dan pekerjaan tertunda

| # | Risiko / pekerjaan | Dampak | Mitigasi / tindak lanjut |
| --- | --- | --- | --- |
| R-1 | Smoke test Android & iOS pada perangkat nyata **belum dijalankan** | Kriteria 10 belum terpenuhi; Fase 3 belum boleh dinyatakan selesai | Jalankan `mobile-build-and-smoke-test.md` pada ≥1 Android dan ≥1 iOS, lalu perbarui dokumen ini |
| R-2 | Sandbox memakai PHP 8.4 dan MariaDB 12.3; cPanel memakai PHP 8.3.33 dan MariaDB client 10.6.27 | Perbedaan runtime telah diuji sebagian | Lint tujuh berkas, status migrasi 001–007, `v2_phase2_verify.php`, konfigurasi, hash rilis, dan smoke API baca-saja lulus di cPanel. Kontrak API yang menulis data tetap hanya dijalankan pada database `_test`, bukan produksi. |
| R-3 | Uji dilakukan pada fixture sintetis kecil | Perilaku pada volume data nyata belum terukur | Uji ulang pada staging dengan salinan data yang disamarkan; baseline performa laporan sudah ada dari Fase 5 V1 |
| R-4 | Akun `pengurus`/`orang_tua` kini dapat login ke API | Permukaan serangan bertambah | Login tetap menolak akun tanpa relasi master aktif; seluruh endpoint V2 memaksakan cakupan di server; endpoint V1 tetap menolak peran ini dengan `403` |
| R-5 | `expo export -p web` menghasilkan `dist/`, `.expo/types` diregenerasi | Berkas build lokal | Keduanya sudah masuk `.gitignore` dan tidak ikut commit |
| R-6 | Ketuk-ganda dan kondisi offline telah diuji pada Android simulator; retry pengiriman gagal dengan kunci sama belum diulang pada perangkat nyata | Perilaku perangkat nyata belum dibuktikan penuh | Ulangi A-06, A-07, dan khususnya A-08 pada Android/iOS nyata |
| R-7 | Notifikasi (Fase 4) belum ada | Perubahan status hanya terlihat saat pengguna membuka aplikasi/portal | Sesuai ruang lingkup; dikerjakan pada Fase 4 |
| R-8 | `npm ci` melaporkan 17 advisory dependency (12 moderat, 5 tinggi) pada dependency baseline; Fase 3 tidak mengubah dependency | Risiko supply-chain tetap ada meski lint/tsc/bundle lulus | Audit dependency terpisah sebelum rilis; jangan menjalankan `npm audit fix --force` pada branch fase ini karena dapat membawa breaking change |
| R-9 | Dynamic Type maksimum standar iOS tidak memotong teks kartu, tetapi kategori aksesibilitas yang sangat besar membuat teks halaman berimpitan | Aksesibilitas pada ukuran ekstrem belum optimal | Koreksi layout responsif lalu ulangi I-04 pada iPhone nyata, termasuk kategori accessibility |
| R-10 | Backup Fase 2 beserta manifest tersedia di cPanel, tetapi manifest pra-migrasi secara wajar berbeda dari data produksi saat ini (migrasi 007 dan data penugasan/akun sudah bertambah) | Perbandingan manifest ke produksi sekarang bukan bukti restore | Pulihkan backup ke database `_test` terisolasi dan jalankan `verify_restore.php` terhadap hasil restore sebelum rilis berikutnya; jangan menguji restore pada database produksi |

## 6. Hasil pengujian manual

| Jenis | Status | Catatan |
| --- | --- | --- |
| Portal web per peran (otomatis, headless) | LULUS | `tests/v2_phase2_web_smoke.php`, 35 pemeriksaan |
| Web publik dan halaman login portal (manual) | LULUS | Halaman utama tampil normal; `/portal/` mengarahkan ke login pengguna tanpa fatal error. Alur portal terautentikasi per peran tetap dicakup tes otomatis. |
| Android simulator | LULUS DENGAN CATATAN | Android 16: matriks menu per peran, pengajuan, routing admin, keputusan murobi/admin, pembatalan, orang tua baca-saja, offline, ketuk-ganda, dan logout diuji langsung. Ini bukan pengganti Android nyata. |
| iOS simulator | LULUS DENGAN CATATAN | iPhone 17 Pro simulator / iOS 26.5: build 0 error, menu Murobi/Admin/Orang Tua, baca-saja orang tua, persistensi SecureStore, mode terang/gelap, dan Dynamic Type maksimum standar diuji. Kategori aksesibilitas sangat besar masih berimpitan. |
| Android nyata | MENUNGGU | `mobile-build-and-smoke-test.md` §5 |
| iOS nyata | MENUNGGU | `mobile-build-and-smoke-test.md` §6 |
| Regresi aplikasi guru pada perangkat | MENUNGGU | `mobile-build-and-smoke-test.md` §7 |
| Smoke test cPanel baca-saja | LULUS DENGAN CATATAN | PHP 8.3.33; MariaDB client 10.6.27; migrasi 001–007 diterapkan; verifikasi V2 Fase 2 lulus; 7/7 lint PHP lulus; hash 7 berkas identik dengan kode audit; `APP_DEBUG=false`; `IZIN_LEGACY_ENABLED=false`; secret 64 karakter; API root `200` dengan user-agent browser dan endpoint tanpa token `401`; log terbaru tidak memuat PHP fatal/warning. `curl` default diblokir WAF `403`, tetapi user-agent browser normal menerima `200`. |

## 7. Kesimpulan

Seluruh kriteria penerimaan Fase 3 yang dapat diverifikasi tanpa perangkat nyata
**LULUS**, termasuk pemeriksaan baca-saja pada cPanel. Kriteria nomor 10 tetap
**MENUNGGU** karena checklist PRD mensyaratkan sedikitnya satu Android dan satu
iOS nyata. Karena itu **Fase 3 belum dapat dinyatakan selesai**, walaupun bukti
simulator Android/iOS sudah tersedia. Jangan melanjutkan ke Fase 4 sebelum uji
perangkat nyata selesai dan temuan Dynamic Type ditutup.
