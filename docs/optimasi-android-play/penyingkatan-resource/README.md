# Penyingkatan resource yang dioptimalkan — 1.9.2 (implementasi Claude Code, 22 November 2026)

Laporan implementator, **bukan audit independen**. Menunggu audit Codex sesuai pembagian peran workstream Optimasi Android Google Play.

## 1. Pemicu

Sesudah Human Developer mengunggah 1.9.1 (21) ke Play Console, muncul dua rekomendasi:

| Rekomendasi Play | Tindakan |
| --- | --- |
| R8: "Penyingkatan resource yang dioptimalkan tidak diaktifkan" | **Dikerjakan** di paket ini (1.9.2). |
| R8: "Upgrade plugin Android Gradle ke versi 9.0 atau lebih tinggi" | **Tidak dikerjakan.** React Native 0.86 / Expo SDK 57 terikat AGP 8.12; sudah patch terbaru 8.12.3. Memerlukan upgrade SDK/RN tersendiri. |
| "API atau parameter tata letak layar penuh yang tidak digunakan lagi" | Residual GP-2 (F-1) tetap ada. Koreksi audit Codex 23 November: DEX aktual 1.9.2 memuat 18 instruksi/10 kelas, turun dari 20/11; dua panggilan `MaterialDatePicker` hilang. Lihat [audit independen](AUDIT-CODEX.md). Keputusan O-1/O-2/O-3 tetap pada Human Developer. |

Human Developer memerintahkan "Ya, kerjakan" untuk butir pertama.

## 2. Baseline dan branch

- Mobile: klon bersih `/private/tmp/ors/mobile`, branch `claude/penyingkatan-resource-optimal` dari `origin/main` `5726bfb` (1.9.1), commit `f70eb80`. Checkout `~/alhasanApps` dan edit paket pekerjaan lain tidak disentuh.
- Web: branch `claude/penyingkatan-resource-optimal` dari `origin/main` `400f056`; hanya dokumen, bukti, dan skrip uji manual. Direktori untracked milik pekerjaan lain tidak disentuh.

## 3. Perubahan

- `plugins/with-android-r8-optimize.js`: tambahan `withGradleProperties` yang menulis `android.r8.optimizedResourceShrinking=true` tepat satu kali (idempoten; nilai lama ditimpa). Mode opt-in sejak AGP 8.12, bawaan AGP 9. Hanya bekerja bersama `shrinkResources` yang sudah diaktifkan `expo-build-properties`.
- `tests/optimasi-android.test.ts`: satu uji baru (satu properti, bernilai `true`, idempoten, properti lain tetap).
- `app.json`: versi **1.9.1 → 1.9.2** (`npm run versi:naik patch`; `versi:periksa` lulus). Nomor build tetap diurus EAS remote.
- Tanpa perubahan kode aplikasi, dependensi/lockfile, manifest, aturan keep, hak, API, atau skema. AGP tetap 8.12.3, R8 8.12.22.

Gradle mencetak peringatan bahwa properti ini masih *experimental* di AGP 8.12; itu sebabnya uji native dijalankan ulang.

## 4. Bukti build (lokal, bukan EAS)

Build Release dari sumber yang sama, dibedakan hanya oleh properti (`-Pandroid.r8.optimizedResourceShrinking=false` untuk kontrol), `EXPO_PUBLIC_API_BASE_URL=https://api-uji.invalid/api/v1`, `NODE_ENV=production`.

| | Kontrol (STANDARD) | 1.9.2 (OPTIMIZED) |
| --- | --- | --- |
| `resourceOptimizationStatus` (`android analyze`) | `STANDARD` | **`OPTIMIZED`** |
| Berkas `base/res/` di AAB | 1.041 | **874** (168 terbuang; 1 dipertahankan yang dibuang kontrol: `layout/dev_loading_view.xml` milik RN) |
| `resources.pb` | 2.419.951 B | **1.829.210 B** |
| DEX tak terkompresi | 10.497.352 B | **10.104.488 B** |
| Ukuran AAB | 70.286.065 B | **69.410.647 B** |
| Rasio optimasi/obfuscation/shrinking `r8.json` | 80,18/80,68/80,81% | 79,93/80,53/80,72% |
| `r8DexChecksumStatus` | EXACT_MATCH | EXACT_MATCH |

DEX kontrol 10.497.352 B sama dengan rebuild independen Codex atas 1.9.1 (audit ulang), sehingga kontrol ini mewakili 1.9.1. Rasio `r8.json` turun ±0,2 poin karena penyebutnya berubah; tetap jauh di atas ambang 25%.

Hash SHA-256 (artefak lokal di `/private/tmp/ors/out`, tidak di-commit karena ukuran):

- AAB 1.9.2 `3302f44ecc74aaac1d2460b38c45ec4d4d076512ad14ceaebe13bf405f381417`
- APK 1.9.2 `3d329d631fcc1af8f065dc8c41236b75b7fd1bbd2b6e814be0c184ac5c597a4c`
- mapping 1.9.2 `5ae5641b9e8b724449b5468587b362d89b66e93dace2bed122a8ba02b6e8d6cc`
- AAB kontrol `508e396d68fb33347948e6c50fef726e201f2bd056259283ed6058c56ff6bdaa`
- APK uji (cleartext hanya 127.0.0.1) `c07c7a2d5fda8ceb37b9f7a58c40825208492e7c9967d6a40ce286049e41a96e`; `classes.dex` identik byte dengan APK 1.9.2 (`6167187a…938f`).

Ringkasan analisis: [android-analyze.json](android-analyze.json). Daftar resource terbuang: [resource-dibuang.txt](resource-dibuang.txt).

### Resource yang terbuang

Seluruhnya resource library yang tidak terjangkau kode hidup: layout/drawable Material `mtrl_calendar_*`/`mtrl_picker_*`/`material_timepicker_*`, templat notifikasi lama AndroidX (`notification_template_*`, `notification_bg_*`), `browser_actions_*`, `redbox_*`/`paused_in_debugger_view` (layar debug RN), dan sejenisnya. **Nol** resource aset aplikasi (`assets_*`, 142 → 142; subset `assets_images_*` 116 → 116) terbuang; `splashscreen_logo`, `ic_launcher`, dan font tetap ada.

Pemilih tanggal aplikasi memakai `@expo/ui` (Compose Material 3), bukan Material Components, sehingga terbuangnya layout `mtrl_calendar_*` diuji langsung (§5).

## 5. Uji

- `tsc --noEmit` exit 0, `npm run lint` exit 0, unit **129/0** (sebelumnya 128/0).
- Emulator **Android 16 (API 36, Uji_API36)**, APK uji Release R8 tanpa Metro, `perintah-native.sh`: Fase 1 **71/0**, profil/foto **24/0**, keuangan **25/0**, izin **54/0** — hitungan sama dengan laporan implementasi 1.9.1. Ringkasan: [uji-native-api36.txt](uji-native-api36.txt).
- Uji manual pemilih tanggal (formulir Ajukan izin pengurus, akun sintetis): dialog Compose tampil lengkap, tanggal terisi, tanpa crash di buffer crash logcat — **10/0** ([uji-pemilih-tanggal.txt](uji-pemilih-tanggal.txt), [skrip](uji_tanggal.py), [tangkapan](pemilih-tanggal-api36.png)).

### Kesalahan penyiapan uji yang diulang

Putaran native pertama 0/1 di ketiga suite ("server tidak terjangkau"). Penyebab: tugas Gradle `createBundleReleaseJsAndAssets` berstatus UP-TO-DATE sehingga APK uji masih membawa URL `api-uji.invalid` dari build final. Diperbaiki dengan `--rerun` pada tugas itu; DEX tetap identik byte. Ini kesalahan alat uji, bukan perilaku aplikasi. Putaran pertama skrip tanggal juga gagal pada pencari elemen (label "Mulai: …", bukan "Tanggal …"); aplikasinya sudah benar pada tangkapan.

## 6. Batas

Belum diuji: build EAS 1.9.2, unggahan Play dan apakah kartu rekomendasi hilang (hanya Play yang dapat memastikan), pre-launch report, Android 12/15 untuk paket ini, iOS (tidak terpengaruh: properti hanya Android, tidak ada perubahan kode), perangkat fisik, Push nyata, upgrade 1.9.1→1.9.2 dari Play. Gerbang produksi workstream lama tidak berubah.

## 7. Handoff

Tidak merge, deploy, OTA, atau unggah Play. Langkah berikutnya: audit independen Codex atas mobile `f70eb80` dan dokumen ini, lalu keputusan Human Developer untuk build EAS dan unggah 1.9.2.

**Pembaruan 23 November 2026:** audit independen Codex memverifikasi optimized resource shrinking dan regresi terarah (unit 129/0, native 68/0), tanpa temuan kode baru pada penerapan flag. Koreksi komentar/dokumentasi dan helper uji tercatat di [AUDIT-CODEX.md](AUDIT-CODEX.md). Keseluruhan paket Optimasi Android tetap belum lulus teknis lokal penuh karena residual GP-2 serta batas audit terdahulu; EAS/Play/pre-launch 1.9.2 belum terkonfirmasi.
