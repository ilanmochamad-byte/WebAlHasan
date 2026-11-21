# Implementasi — optimasi Android Google Play (GP-1 DEX/R8, GP-2 API tampilan)

21 November 2026 · Implementator **Claude Code** · Auditor berikutnya **Codex** (belum diaudit)

Laporan ini mengikuti `rencana.md` dan `prompts-implementasi-audit.md` bagian 1. Hash artefak dan lokasi privat ada di `manifest-bukti.md`. Laporan ini **bukan** audit independen.

## 0. Tiga status terpisah

| Status | Nilai |
| --- | --- |
| **Implementasi** | **Selesai** untuk GP-1 (R8 + resource shrinking + metadata). **GP-2 ditangani sejauh yang dapat dilakukan tanpa upgrade SDK/RN; residual library tetap terbuka** dan memerlukan keputusan Human Developer (§4). |
| **Kesiapan audit teknis lokal K-1…K-6** | Bukti implementator tersedia (§7). Penilaian lulus/belum adalah hak auditor Codex. |
| **Konfirmasi Play dan kesiapan produksi K-7** | **Belum tersedia.** Belum ada build EAS 1.9.1, unggahan internal Play, pre-launch report, atau hasil analisis Play atas AAB ini. Peringatan Play **tidak** diklaim tertutup. |

## 1. Baseline, branch, dan preservasi

| Repo | Branch | Dasar | Commit handoff |
| --- | --- | --- | --- |
| Mobile `alhasanApps` | `claude/optimasi-android-play` | `origin/main` `82f156ab98cb62fd5a97259be944f4cfcf75ffd0` (PR #35, memuat dokumen persiapan `79681d9`) | kode `747e55d` (artefak dibangun dari pohon ini), dokumen `4b20d25` |
| Web `WebAlHasan` | `claude/optimasi-android-play` | `origin/main` `e6f57b86a1d965664f95c7fc9d10ffa6991839ab` (PR #124, memuat dokumen `d274b61`) | commit handoff berisi laporan ini (lihat `git log` branch) |

- `git fetch` 21 November 2026: kedua branch dokumen `codex/rencana-optimasi-android-play` sudah digabung ke `main`, sehingga branch fitur dibuat dari `origin/main` yang memuatnya (sesuai rencana §2). Baseline isi kode mobile = `84ddebb` (rilis 19) + dokumen.
- Tidak ada worktree. Tidak ada commit di `main`.
- **Perubahan lokal pekerjaan lain dipertahankan.** Checkout `~/alhasanApps` memiliki edit `package.json`/`package-lock.json` (pembaruan patch Expo 57.0.31/RN 0.86.3 dan lainnya) yang tidak di-commit. SHA-256 sebelum dan sesudah pekerjaan ini: `package.json` `3fb70445…3a57391`, `package-lock.json` `18a57cd5…3513d0f` — tidak berubah. Seluruh perubahan paket pekerjaan ini dibuat pada **klon bersih** `~/optimasi-android-build/impl` dari `82f156a` dengan `npm ci` lock ter-commit, lalu di-commit dan di-push dari klon tersebut (§9). `node_modules` checkout utama juga tidak sinkron dengan lock (Expo 57.0.31 terpasang, `expo-image-picker` hilang) sehingga tidak dipakai untuk build.
- Direktori untracked web (`database/pemulihan/`, `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`, `tests/browser/tangkapan-penugasan-keamanan-f1/`) tidak disentuh atau di-stage.
- Proses Codex desktop terlihat berjalan saat mulai; tidak ada sesi Codex atau Gradle lain yang memakai klon build ini. Human Developer memerintahkan giliran implementasi.

## 2. Keterlacakan rilis 19 dan toolchain

AAB rilis 19 **asli** diperoleh dari akun EAS pemilik (bukan rekonstruksi): build `24ad663e-8f31-444c-b593-9dced22e89bf`, profile `production`, distribution STORE, `appVersion 1.9.0`, `appBuildVersion 19`, commit `84ddebbbdedc022dae42f323cd37ae9fcc471cc2`, SDK 57.0.0, selesai 2026-11-13T14:52:06Z. SHA-256 `015f4ddd…ad553c908`. Status dirty working tree tidak dilaporkan EAS (`null`). Yang belum dibuktikan: bahwa AAB yang aktif di Play adalah unggahan berkas yang sama (memerlukan akses Play Console; hash App Bundle Explorer).

| Komponen | Rilis 19 (metadata AAB) | Build pekerjaan ini |
| --- | --- | --- |
| AGP | 8.12.0 (`app-metadata.properties`) | **8.12.3** (disematkan, §3) |
| Compiler DEX | D8 8.12.14 (`d8.json`), **tanpa R8** | **R8 8.12.22** (`r8.json`) |
| minSdk / target / compile | 24 / — / — | 24 / 36 / 36 |
| Gradle / Kotlin / JDK / NDK / build-tools | (EAS) | 9.3.1 / 2.1.20 / OpenJDK 17.0.16 / 27.1.12297006 / 36.0.0 |
| Expo / RN | SDK 57 (lock ter-commit: expo 57.0.15, RN 0.86.2) | sama (lock ter-commit) + `expo-build-properties` 57.0.23 |

## 3. GP-1 — R8, shrinking, metadata

### Perubahan (mobile)

1. `expo-build-properties` **~57.0.23** ditambahkan lewat `npx expo install` (versi yang direkomendasikan dokumentasi SDK 57; `bundledNativeModules` expo 57.0.15 menulis `~57.0.13`, `expo install` memilih 57.0.23). Transitif ikut naik `@expo/schema-utils` 57.0.2 → 57.0.3 (dependensi plugin itu). Tidak ada paket lain yang berubah di lock.
2. `app.json`: plugin `expo-build-properties` dengan `android.enableMinifyInReleaseBuilds: true` dan `android.enableShrinkResourcesInReleaseBuilds: true`; plugin lokal `./plugins/with-android-r8-optimize`.
3. `plugins/with-android-r8-optimize.js` (config plugin, berjalan di setiap `expo prebuild`, termasuk EAS):
   - mengganti `getDefaultProguardFile("proguard-android.txt")` template menjadi `"proguard-android-optimize.txt"`. Berkas bawaan `proguard-android.txt` memuat `-dontoptimize`; tanpa penggantian ini R8 hanya memangkas dan mengaburkan;
   - menyematkan `classpath('com.android.tools.build:gradle:8.12.3')`. Template RN 0.86 memakai AGP **8.12.0**; Google menyatakan skor diambil dari `r8.json` hanya untuk "latest patch version of AGP 8.10 or higher (e.g. 8.10.1 rather than 8.10.0)". Ini kenaikan **patch** dalam minor yang sama, bukan upgrade mayor/minor;
   - gagal keras bila pola template tidak ditemukan tepat sekali, agar perubahan template tidak diam-diam mengembalikan `-dontoptimize`.
4. Versi `expo.version` 1.9.0 → **1.9.1** dengan `npm run versi:naik -- patch` terhadap `origin/main` (sekali; `versi:periksa -- --kerja` LULUS). Nomor build tetap EAS remote (`appVersionSource: remote`, `autoIncrement`); tidak ada versionCode yang di-hardcode.
5. `tests/optimasi-android.test.ts` (5 uji): penggantian tepat sekali dan idempoten, gagal keras pada pola 0/ganda, penyematan AGP patch 8.12 (bukan 8.12.0) idempoten, app.json mengaktifkan minify/shrink tanpa `-dontobfuscate/-dontoptimize/-dontshrink/keep **` maupun `usesCleartextTraffic`.

Tidak ada aturan keep baru. `android/` dan `ios/` tetap tidak dilacak (CNG); semua perubahan native lewat config plugin sehingga dapat direproduksi oleh EAS prebuild.

### Bukti R8 benar-benar berjalan

- `expo prebuild` menghasilkan `android/gradle.properties` `android.enableMinifyInReleaseBuilds=true`, `android.enableShrinkResourcesInReleaseBuilds=true`; `app/build.gradle` `proguardFiles getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro"`; `build.gradle` `classpath('com.android.tools.build:gradle:8.12.3')`.
- Log Gradle `bundleRelease assembleRelease`: `> Task :app:minifyReleaseWithR8`, `BUILD SUCCESSFUL in 8m 10s`.
- AAB final memuat `BUNDLE-METADATA/com.android.tools/r8.json` (R8 8.12.22) dan `BUNDLE-METADATA/com.android.tools.build.obfuscation/proguard.map` (104 MB, SHA-256 sama dengan `mapping.txt`); `app-metadata.properties` `androidGradlePluginVersion=8.12.3`.
- `r8.json` options: `isOptimizationsEnabled`, `isObfuscationEnabled`, `isShrinkingEnabled` = true; `isProGuardCompatibilityModeEnabled` = false (full mode).

### Aturan keep final (consumer rules)

`mapping/configuration.txt` (1.720 baris) diperiksa: **tidak ada** `-dontoptimize`, `-dontobfuscate`, `-dontshrink`, `-ignorewarnings`, `-dontwarn **`, atau `-keep class ** { *; }`. Satu-satunya `class **` adalah `-keepclassmembers class ** { @androidx.lifecycle.OnLifecycleEvent *; }` dari AndroidX Lifecycle (bersyarat anotasi). Keep paket luas berasal dari consumer rules library dan aturan template: `com.swmansion.reanimated.**`, `com.swmansion.worklets.**`, `com.facebook.react.turbomodule.**`, `com.facebook.react.fabric.**`, `com.facebook.react.bridge.**`, `com.facebook.jni.**`, `expo.modules.notifications.**`, `com.squareup.zstd.**` (JNI/refleksi). `android/app/proguard-rules.pro` template menduplikasi dua aturan consumer Reanimated; dibiarkan (tidak mengubah hasil). Inilah sumber utama ±19% kode yang tidak teroptimasi.

### Angka DEX (AAB, tak terkompresi)

| | Rilis 19 (asli EAS) | Final R8 (AGP 8.12.3) |
| --- | ---: | ---: |
| Jumlah DEX | 5 | **1** |
| Ukuran DEX total | 46.992.180 B (44,8 MiB) | **10.411.972 B (9,93 MiB / 10,41 MB)** |
| Ukuran AAB | 74.355.173 B | 70.104.966 B |
| `base/res` (berkas / byte) | 1.149 / 4.112.264 | 1.041 / 3.986.325 |
| Metadata | `d8.json` saja | `r8.json` + `proguard.map` |
| `r8.json` noObfuscation / noOptimization / noShrinking | — | **19,17% / 19,68% / 19,05%** |
| Turunan: obfuscation / optimization / shrinking | (dilaporkan Play 1% obfuscation) | **±80,8% / ±80,3% / ±81,0%** |

Pembacaan jujur:

- Ambang Google "at least 10 MB of DEX code measured as uncompressed size". DEX final **10.411.972 byte** — di atas 10 MB desimal, di bawah 10 MiB. Karena satuan yang dipakai Play tidak dinyatakan tegas, aplikasi **diperlakukan tetap memenuhi syarat penerapan**; klaim "keluar dari cakupan karena ukuran" **tidak** dibuat.
- Minimum 25% untuk ketiga metrik terlampaui menurut `r8.json` yang menurut dokumentasi Google menjadi sumber skor untuk AGP ≥ 8.10 patch terbaru. Angka turunan (100 − persentase "no…") adalah tafsiran implementator atas skema `r8.json`; **`android analyze <app.aab>` (alat resmi yang angkanya sama dengan Android vitals) tidak tersedia di mesin ini** (perintah `android` tidak terpasang), dan skor final vitals hanya terlihat setelah unggahan Play. Ini dicatat sebagai batas K-3/K-7, bukan dinyatakan lulus Play.
- Pembanding AGP 8.12.0 (R8 8.12.14) menghasilkan DEX berukuran sama dan statistik identik; penyematan 8.12.3 ditujukan pada akurasi pembacaan metadata oleh Play, bukan perubahan skor lokal.

## 4. GP-2 — API tampilan yang ditinggalkan di Android 15

### Peta simbol (AAB rilis 19, d8 tanpa obfuscation — atribusi pasti)

| Kelas pemanggil | Simbol | Library (versi terpaket) | Kondisi OS |
| --- | --- | --- | --- |
| `com.facebook.react.modules.statusbar.StatusBarModule` (+ `$setColor$1`) | `getStatusBarColor`, `setStatusBarColor` | react-android 0.86.2 (prebuilt Maven) | `setColor` keluar lebih dulu bila edge-to-edge aktif (selalu di Android 16; Android 15 kecuali opt-out); getter di konstanta dipanggil tanpa penjaga |
| `com.facebook.react.views.view.WindowUtilKt.enableEdgeToEdge`, `statusBarHide/Show` | `setStatusBarColor`, `setNavigationBarColor`, `layoutInDisplayCutoutMode` | react-android 0.86.2 | tanpa penjaga API 35 (no-op di 35+) |
| `com.google.android.material.bottomsheet.BottomSheetDialog.onCreate/onAttachedToWindow`, `internal.EdgeToEdgeUtils`, `sidesheet.SheetDialog`, `navigation.NavigationView$3` | `setStatusBarColor`, `setNavigationBarColor`, `getNavigationBarColor` | Material **1.13.0** (dibawa react-native-screens, expo-router, @expo/ui) | `BottomSheetDialog.onCreate` memanggil sebelum cek `SDK_INT` |
| `androidx.activity.EdgeToEdgeApi23/26/28/29/30` | warna bilah, `layoutInDisplayCutoutMode` | androidx.activity 1.11.0 | per-API, untuk OS lama |
| `androidx.core.view.WindowCompat.enableEdgeToEdge` (dipakai `ExpoCropImageActivity`, expo-image-picker 57.0.20) | warna bilah, cutout | androidx.core 1.18.0 | |
| `androidx.core.splashscreen.SplashScreen$Impl31` | warna bilah | core-splashscreen 1.2.0 | API 31+ |
| `androidx.compose.ui.window.Api28Impl`, `androidx.browser…TrustedWebActivityDisplayMode` | `layoutInDisplayCutoutMode` | compose-ui 1.11.0-beta02, androidx.browser | |

**Kode aplikasi tidak memanggil satu pun API ini.** `src/` hanya memakai `<StatusBar style="auto" />` (gaya ikon, bukan warna); tidak ada `backgroundColor`/`translucent`/`setBackgroundColorAsync` atau warna bilah navigasi. Edit profil memakai `allowsEditing: true`, sehingga `ExpoCropImageActivity` (expo-image-picker) benar-benar berjalan di runtime.

### Setelah R8 (AAB final; atribusi kelas memakai mapping, kelas inang dapat berbeda karena inlining/merging)

Rujukan turun dari **31 instruksi di 15 kelas** menjadi **20 instruksi di 11 kelas**. Hilang karena tidak terjangkau: `androidx.activity.EdgeToEdgeApi*`, Material `NavigationView`, `SheetDialog`, `TrustedWebActivity…`. Tersisa (inang setelah inlining): `StatusBarModule` (+ lambda), `WindowUtilKt`, `FormSheetDialog` react-native-screens (mewarisi `BottomSheetDialog`), `MaterialDatePicker` (inang `EdgeToEdgeUtils` yang di-inline), `ExpoCropImageActivity` (inang `WindowCompat.enableEdgeToEdge`), `SplashScreen$Impl31` lambda, compose `Api28Impl`, serta dua outline API-model sintetis (cutout).

### Versi upstream yang diperiksa

| Library | Terpaket | Terbaru yang diperiksa | Hasil |
| --- | --- | --- | --- |
| react-native | 0.86.2 | 0.86.3 (patch terbaru 0.86) | `StatusBarModule.kt`/`WindowUtil.kt` **identik** — patch tidak menutup GP-2. RN terbaru 0.88.1 = upgrade minor/SDK, di luar cakupan. |
| Material | 1.13.0 | 1.14.0 (stabil) | 1.14.0 memindahkan panggilan ke `EdgeToEdgeUtils.set/getStatusBarColor/NavigationBarColor` yang **dijaga `SDK_INT < 35`**; rujukan API tetap ada di DEX. |
| androidx.activity | 1.11.0 | 1.13.0 | masih memanggil (termasuk `EdgeToEdgeApi35`). |
| core-splashscreen | 1.2.0 | 1.2.0 | terbaru. |
| expo-image-picker | 57.0.20 | 57.0.20 (patch SDK 57) | memakai `WindowCompat.enableEdgeToEdge` AndroidX. |

### Keputusan implementasi dan residual

- **Tidak** menurunkan target SDK, mematikan edge-to-edge, menghapus modul, menonaktifkan perlindungan layar, mem-patch `node_modules`, atau membangun RN dari sumber.
- **Tidak memaksa Material 1.14.0.** Alasannya: itu kenaikan minor transitif milik react-native-screens/expo-router/@expo/ui yang tidak menghapus rujukan simbol dari DEX (Play memindai rujukan), sementara pemanggil RN core dan AndroidX tetap ada. Manfaatnya hanya membuat jalur Material tidak dieksekusi di Android 15+ — padahal pada 35+ setter tersebut sudah no-op. Ini dicatat sebagai **opsi O-1 bagi Human Developer**.
- **Residual GP-2 TERBUKA**: peringatan Play kemungkinan besar tetap muncul untuk 1.9.1 karena pemanggil berada di RN core 0.86 dan AndroidX/Material. Penutupan memerlukan salah satu: (a) upgrade SDK/RN yang upstream-nya sudah menghapus pemanggil, (b) Human Developer menerima batas ini secara eksplisit, atau (c) bukti Play bahwa peringatan hilang. Tidak ada klaim GP-2 selesai.
- Runtime edge-to-edge diuji di §6 (Android 16, 15, 12; gestur dan 3 tombol; cutout; terang/gelap; teks 2×; lembar bawah, papan ketik, FAB, kepala).

## 5. Build dan cara mengulang

```bash
# Klon bersih (jangan dari checkout yang memiliki edit paket lain / .env.local)
git clone https://github.com/ilanmochamad-byte/alhasanApps.git oap && cd oap
git checkout <SHA handoff mobile>
npm ci
echo '/// <reference types="expo/types" />' > expo-env.d.ts   # berkas gitignore Expo, diperlukan tsc
npx tsc --noEmit && npm run lint && node --experimental-strip-types --test tests/*.test.ts
CI=1 npx expo prebuild --platform android --no-install
cd android
EXPO_PUBLIC_API_BASE_URL=https://api-uji.invalid/api/v1 NODE_ENV=production ./gradlew bundleRelease assembleRelease
unzip -p app/build/outputs/bundle/release/app-release.aab BUNDLE-METADATA/com.android.tools/r8.json
```

- **APK uji** (hanya lokal): salin proyek hasil prebuild, tambah `res/xml/network_security_config_uji.xml` (base `cleartextTrafficPermitted=false`, domain-config `127.0.0.1` saja) dan atribut `android:networkSecurityConfig` di manifest, build `assembleRelease` dengan `EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8971/api/v1`. `classes.dex` APK uji **identik byte** dengan APK final (`37b3ec64…4082`), mapping identik; perbedaan hanya manifest/resource uji dan URL API di bundel. APK/AAB final tidak memuat `usesCleartextTraffic` atau `networkSecurityConfig`.
- **Build distribusi** tetap lewat EAS dari commit handoff: `eas build --platform android --profile production` (tanpa `--auto-submit`). EAS mengambil env dan kredensial dari project; versionCode naik otomatis di atas 19. Tidak dijalankan oleh implementator (lihat K-7).
- **Validasi internal Play (untuk Human Developer)**: unggah AAB EAS ke track internal → App Bundle Explorer: periksa hash/versionCode, bagian "Deobfuscation file" terisi otomatis dari AAB, lalu Android vitals/peringatan pengoptimalan kode dan pre-launch report. Catat hasil dan hash ke laporan audit.
- **Pemulihan**: bila 1.9.1 bermasalah, terbitkan build perbaikan dengan versionCode lebih tinggi (mis. konfigurasi tanpa plugin optimasi atau dengan aturan keep terarah); **mengganti AAB dengan versionCode sama tidak tersedia**, dan menurunkan versionCode tidak mungkin. Untuk crash ter-obfuscate, gunakan `retrace` dengan mapping yang cocok dengan build (Play menyimpan `proguard.map` dari AAB).

## 6. Pengujian

Semua uji native memakai **emulator** dan **APK uji Release R8 1.9.1** (`0a97ed65…f1e`, DEX identik byte dengan APK/AAB final) dengan bundel JavaScript tertanam — **tanpa Metro/dev client** — terhadap API uji `127.0.0.1:8971` (`adb reverse`) dan DB sintetis `test_oap_*_test`. Penjalan: `docs/optimasi-android-play/perintah-native.sh` (suite lama dengan `RILIS=1`; perubahan suite di `perubahan-oracle.txt`). Bukan perangkat fisik, bukan build EAS, bukan Play.

### 6.1 Statis dan unit (klon commit `747e55d`, `npm ci` lock ter-commit)

| Pemeriksaan | Hasil |
| --- | --- |
| `npx tsc --noEmit` | exit 0 (baseline `82f156a` exit 0) |
| `npm run lint` | exit 0 |
| Unit `node --experimental-strip-types --test tests/*.test.ts` | **124 / 0** (baseline 119 / 0; +5 uji `optimasi-android.test.ts`) |
| `npm run versi:periksa` | LULUS 1.9.0 → 1.9.1 (patch) |
| Prebuild ulang dari klon baru `747e55d` vs proyek native yang dibangun | `build.gradle`, `gradle.properties`, `app/build.gradle`, `proguard-rules.pro`, `AndroidManifest.xml`, `settings.gradle` identik |
| Expo web `expo export --platform web` | exit 0 (kode JS tidak berubah) |
| iOS `expo prebuild --platform ios` baseline vs final | exit 0; beda hanya `Info.plist` versi 1.9.1 dan `Podfile.properties.json` (+`ios.forceStaticLinking: []`, `apple.privacyManifestAggregationEnabled: true`, `EXPO_USE_PRECOMPILED_MODULES: true`) yang **sama dengan default efektif Podfile** tanpa properti itu (`ENV['EXPO_USE_PRECOMPILED_MODULES'] ||= '1'`, agregasi aktif kecuali `'false'`). Build/smoke iOS **tidak dijalankan** — konfigurasi efektif iOS dan kode bersama tidak berubah. |

### 6.2 Matriks native Android (emulator)

| Lingkungan | f1 peran/sesi/gelap/teks 1,3×–2,0× | izin V2/V7 + FAB | pengajian/absensi | keuangan/BSA | rapor/pembinaan (t7) | kesehatan (v5) | Keamanan (pk) | profil/foto/password |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| **Android 16 / API 36**, Play image, navigasi gestur | **71/0** | **54/0** | **13/0** | **25/0** | **17/0** | **7/0** ¹ | **10/0** | **24/0** (+1 lewati) ² |
| **Android 16 / API 36**, navigasi **3 tombol** + cutout **tall** | **71/0** | **54/0** | — | — | — | — | — | — |
| **Android 15 / API 35**, google_apis, gestur | **71/0** | **54/0** | — | **25/0** | — | **7/0** ¹ | — | **23/1** ³ (+1 lewati) |
| **Android 12 / API 31**, google_apis, 3 tombol | **67/4** ⁴ | **54/0** | — | **24/1** ⁴ | — | — | — | **9/2** ⁵ |

¹ Fixture V5 Fase 3 tidak memuat kunjungan; satu kunjungan sintetis dibuat lewat REST resmi oleh petugas (HTTP 201) sebelum suite. Putaran pertama Android 16 tanpa langkah ini 0/4 (tautan tanpa ID), sama dengan catatan TL-30.
² Putaran pertama 10/1 karena penanda suite yang tertinggal sejak TL-02/TL-03 (suite Android profil tidak ikut putaran native tindak lanjut); setelah penyesuaian pencari elemen (`perubahan-oracle.txt` §2) 24/0. Pemeriksaan cache `run-as` dilewati karena APK rilis non-debuggable (tidak dihitung lulus).
³ Satu-satunya kegagalan: kalimat Photo Picker OS "This app can only access the photos you select" berbeda dari teks yang diharapkan suite; Photo Picker sistem tetap terbuka tanpa dialog izin, pemotong dan unggah lulus. Varian kalimat ditambahkan sesudah putaran ini.
⁴ Android 12 memakai penskalaan font **linear** (Android 14+ non-linear): pada 2,0× nama terbungkus dan "Ringkasan hari ini"/label strip turun di bawah lipatan layar pertama yang diperiksa suite. Tangkapan setelah digulir menunjukkan tata letak lengkap, kepala di bawah bilah status, tab di atas bilah 3 tombol. Kontrol APK baseline 1.9.0 tanpa R8 gagal pada butir yang sama (§6.4).
⁵ (a) teks 2,0× target "Kelas/Kamar" di bawah lipatan (⁴); (b) image Android 12 tanpa Photo Picker sistem — `expo-image-picker` memakai DocumentsUI (tetap tanpa izin media). Uji manual lanjutan di Android 12: memilih gambar → `ExpoCropImageActivity` (bilah alat di bawah bilah status) → CROP → "Foto profil diperbarui", DB `foto` terisi.

Cakupan yang dibuktikan suite di atas pada binary R8: login/logout tujuh peran, sesi tersimpan, Ganti password wajib/biasa dan password sementara, kepala ← dan tombol kembali Android, tautan `alhasanapps://` saat aplikasi berjalan **dan saat mati** (t7), FAB K-8 terhadap bilah tab (gestur dan 3 tombol), papan ketik, lembar bawah keputusan izin dan foto profil, Photo Picker/DocumentsUI + pemotong + unggah multipart, ketukan ganda setoran → satu transaksi (keuangan), penugasan dicabut saat formulir terbuka, admin hanya baca, `FLAG_SECURE` pada Keuangan dan Kesehatan (tangkapan terlindungi), Keamanan hanya baca/admin ditolak, gelap, teks 1,3×/2,0×, logcat tanpa password/bearer mentah.

### 6.3 Upgrade, cold start, dan sesi (`tests/android/oap_upgrade_sesi.py`, Android 16)

APK uji baseline **1.9.0 tanpa R8** (sumber `82f156a`, 5 DEX, `d1ae867d…5595`) lalu `adb install -r` APK uji **1.9.1 R8** — keduanya kunci debug template yang sama. **9/0**: U-1 masuk di 1.9.0; U-2 pembaruan ke 1.9.1 dan cold start tanpa Metro langsung Beranda (token SecureStore/Keystore lama terbaca kode R8); U-3 kembali dari latar; U-4 Keluar → Masuk → masuk lagi → force-stop + cold start tetap Beranda; U-5 logcat 13.263 baris tanpa password/bearer mentah. **Bukan** bukti upgrade Play/Play App Signing.

### 6.4 Kontrol Android 12

APK uji **baseline 1.9.0 tanpa R8** (`d1ae867d…5595`, sumber `82f156a`) dijalankan di emulator Android 12 yang sama dengan suite dan fixture yang sama: f1 **67/4**, keuangan **24/1** — **daftar kegagalan identik baris demi baris** dengan APK 1.9.1 R8 (`diff` kosong). Jadi kegagalan teks 2,0× Android 12 **sudah ada sebelum paket ini** (tata letak JS dan penskalaan font linear), bukan regresi R8. Dicatat sebagai F-5 untuk keputusan terpisah.

### 6.5 ANR dan crash

- Seluruh putaran matriks di atas: **nol** `am_anr`/`am_crash` untuk paket aplikasi (buffer `events` dibersihkan sebelum tiap putaran). Satu `am_anr` saat uji upgrade milik `com.android.vending` (Play Store), bukan aplikasi.
- **Satu ANR aplikasi tercatat sebelum matriks resmi** (Android 16, emulator baru boot): `Input dispatching timed out … Waited 8297ms for KeyEvent` saat suite mengetik username, bersamaan dengan Gboard menampilkan onboarding "Try out your stylus" yang merebut input dan layanan Google sedang sinkron awal. Setelah handwriting stylus dimatikan (`settings put secure stylus_handwriting_enabled 0`) dan dialog ditutup, pengetikan normal dan tidak ada ANR lagi selama ±5 jam uji pada tiga versi OS. Dinilai artefak lingkungan, tetapi **tidak dibuktikan mustahil**; dicatat agar auditor/pre-launch report memperhatikan ANR input.

### 6.6 Tidak diuji

- Build **EAS** 1.9.1, unggahan **internal Play**, App Bundle Explorer, pre-launch report, skor Android vitals, hilangnya peringatan GP-1/GP-2.
- **Perangkat fisik**, **Push nyata** (registrasi token/ketukan/cold start dari Push: build lokal tanpa `google-services.json`, emulator), **Play App Signing**/upgrade dari Play.
- `android analyze` (tidak terpasang).
- Build/smoke iOS (lihat §6.1), Expo web suite Playwright (kode JS tidak berubah; hanya ekspor).
- MySQL/cPanel/produksi tidak relevan (tanpa perubahan server).

## 7. Pemenuhan kriteria (penilaian implementator, bukan audit)

| ID | Bukti implementator | Catatan terbuka |
| --- | --- | --- |
| K-1 | Rilis 19 ditelusuri ke build EAS + commit + hash; baseline/final, toolchain, artefak dan hash di `manifest-bukti.md`; edit paket pekerjaan lain terjaga (hash tetap) | Bukti bahwa AAB aktif di Play = berkas EAS yang sama memerlukan Play Console |
| K-2 | `minifyReleaseWithR8` di log; `r8.json` + `proguard.map` di AAB; full mode; tanpa aturan keep baru; consumer rules diperiksa | Keep paket luas dari library tetap (±19% tidak teroptimasi) |
| K-3 | DEX 46,99 MB → 10,41 MB; `r8.json` ±80% untuk ketiga metrik (≥25%) | `android analyze` tidak tersedia; skor final vitals hanya dari Play → **blocker klaim kepatuhan terukur Play** sampai K-7 |
| K-4 | Simbol GP-2 dipetakan ke library/versi; aplikasi tidak memanggil; R8 menghapus sebagian; upstream diperiksa; uji edge-to-edge tiga OS | **Residual GP-2 terbuka** (RN core, AndroidX, Material, expo-image-picker); perlu keputusan Human Developer (§8) |
| K-5 | Matriks §6.2–6.5 pada binary R8 tanpa Metro; tanpa regresi yang terkait paket; transport distribusi tidak dilonggarkan (uji hanya salinan terpisah, DEX identik) | Kegagalan Android 12 teks 2,0× identik dengan baseline tanpa R8 (§6.4); fisik/Push belum |
| K-6 | Satu kenaikan patch 1.9.1; panduan reproduksi/pemulihan §5; bukti tersanitasi; commit/push | Audit independen Codex belum |
| K-7 | — | **Belum tersedia**: EAS, internal Play, pre-launch report, hasil analisis Play |

## 8. Temuan dan risiko

| ID | Tingkat | Temuan | Status |
| --- | --- | --- | --- |
| F-1 | Tinggi (rilis) | **Residual GP-2**: pemanggil `Window.set/getStatusBarColor`, `setNavigationBarColor`, `layoutInDisplayCutoutMode` tetap ada di DEX dari react-android 0.86 (`StatusBarModule`, `WindowUtilKt`), AndroidX (splashscreen, core `WindowCompat`, compose), Material 1.13.0 (`BottomSheetDialog` via FormSheet react-native-screens, `EdgeToEdgeUtils`), expo-image-picker (`ExpoCropImageActivity`). Peringatan Play kemungkinan tetap. | Terbuka — opsi: **O-1** paksa Material 1.14.0 (panggilan Material dijaga `SDK_INT < 35`, rujukan tetap; minor transitif, perlu uji FormSheet); **O-2** upgrade SDK Expo/RN (di luar cakupan); **O-3** Human Developer menerima batas sampai SDK berikut. |
| F-2 | Sedang | Ambang "10 MB": DEX final 10.411.972 B berada di antara 10 MB desimal dan 10 MiB; aplikasi diperlakukan tetap tercakup. | Dicatat; skor ±80% memenuhi minimum bila terbaca Play |
| F-3 | Sedang | `android analyze` dan skor vitals tidak tersedia secara lokal. | Perlu Play (K-7) |
| F-4 | Sedang | ANR input sekali saat onboarding stylus Gboard di emulator baru boot (§6.5). | Tidak terulang; pantau pre-launch report |
| F-5 | Rendah | Android 12 pada teks 2,0×: konten penting turun di bawah lipatan layar pertama (penskalaan linear); jam SystemUI terpotong. | **Sudah ada di baseline 1.9.0 tanpa R8** (kontrol §6.4, kegagalan identik); di luar cakupan paket ini |
| F-6 | Rendah | `android/app/proguard-rules.pro` template menduplikasi aturan consumer Reanimated (`com.swmansion.reanimated.**`, `com.facebook.react.turbomodule.**`). | Dibiarkan: tidak mengubah hasil; penghapusan tidak menurunkan keep karena consumer rules tetap |
| F-7 | Info | Proses build EAS akan memakai `expo-build-properties` 57.0.23 dan AGP 8.12.3 (diunduh dari Google Maven saat build). | Perlu diverifikasi di log EAS saat build 1.9.1 |
| F-8 | Info | Checkout `~/alhasanApps` tetap di `82f156a` dengan edit paket pekerjaan lain; branch fitur baru ada di remote. `node_modules` di checkout itu tidak sinkron dengan lock mana pun. | Audit/build sebaiknya dari klon bersih |

## 9. Berkas yang berubah

Mobile (`claude/optimasi-android-play`):

- `app.json` — versi 1.9.1; plugin `expo-build-properties` (minify + shrink release); plugin lokal.
- `package.json`, `package-lock.json` — `expo-build-properties` ~57.0.23 (+ transitif `@expo/schema-utils` 57.0.3).
- `plugins/with-android-r8-optimize.js` — baru.
- `tests/optimasi-android.test.ts` — baru.
- `docs/optimasi-android-play/README.md`, `AGENTS.md` — ringkasan dan status.

Web (`claude/optimasi-android-play`):

- `docs/optimasi-android-play/IMPLEMENTASI.md`, `manifest-bukti.md`, `perubahan-oracle.txt`, `perintah-native.sh` — baru.
- `tests/android/oap_upgrade_sesi.py` — baru.
- `tests/browser/uji-redesain-v2-fase{1,2,3,4}-android.mjs`, `tests/browser/uji-profil-impor-f2-android.mjs` — mode rilis dan pencari elemen (`perubahan-oracle.txt`).
- `AGENTS.md` — status workstream.

Tidak ada perubahan kode aplikasi web, API, skema, atau hak.
