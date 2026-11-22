# Tindak lanjut Claude Code — GP-2, K-5, dan Play final

22 November 2026. Implementator Claude Code menindaklanjuti tiga kekurangan audit Codex (`AUDIT-CODEX.md`: F-1, F-2/F-3, K-7) atas perintah Human Developer. GP-1 dipertahankan. Ini laporan implementator, **bukan audit independen**; kelulusan tetap menunggu audit Codex dan konfirmasi Play.

Mobile: branch `claude/optimasi-android-play`, commit `3705c2f89297ea08098f1c4db18d6745d35e71aa` di atas `e5a88c4` (isi sama dengan `origin/main` `1996071`). Dikerjakan di klon bersih; checkout `~/alhasanApps` beserta edit paket pekerjaan lain tidak disentuh. Versi tetap **1.9.1** (belum pernah dirilis, tidak dinaikkan lagi).

## Ringkasan

| Butir | Hasil | Status |
| --- | --- | --- |
| GP-2 (F-1) | Tidak ada versi dependency yang menghapus seluruh pemanggil. Upgrade mayor SDK 58 / RN 0.88.1 diuji pada build sungguhan: residual **20 instruksi/11 kelas → 15/7**, bukan nol. RN `main` masih memanggil API yang sama. Upgrade mayor **tidak diadopsi** (perlu perluasan cakupan dan tetap tidak menutup GP-2). | **Terbuka** — perlu keputusan Human Developer |
| K-5 iOS (F-2) | Plugin UIScene + patch SDK 57 (expo 57.0.33 memuat `ExpoAppSceneDelegate`). iOS 27.2 Release: cold start lulus, 13 alur Maestro + ukur FAB 4/0, tautan saat aplikasi mati lulus, tanpa crash. | **Diperbaiki** (simulator) |
| K-5 Android 12 (F-3) | Emulator API31 di mesin ini macet saat boot pada tiga konfigurasi. Tidak ada perangkat/emulator Android 12 yang stabil. | **Belum teruji** |
| Play final (K-7) | AAB EAS 1.9.1 (20) dibangun dan dianalisis. `eas submit` ditolak: project EAS tidak memiliki Google Service Account Key. | **Belum diunggah** — perlu Human Developer |

## 1. GP-2 — pencarian perbaikan dependency

**Patch dalam SDK 57.** Seluruh dependency diselaraskan dengan `npx expo install --fix` (expo 57.0.15 → 57.0.33, react-native 0.86.2 → 0.86.3, expo-image-picker 57.0.21, expo-splash-screen 57.0.11, dst.; `eslint-config-expo` 57.0.2). `expo install --check`: *Dependencies are up to date*. Pemindaian DEX kandidat lokal tetap **20/11** ([bukti](tindak-lanjut/gp2-scan-kandidat-lokal.json)). Saran plugin opsional baru `expo-image`/`expo-sharing`/`expo-web-browser` tidak ditambahkan (tidak diperlukan; menambah konfigurasi native).

**Upgrade mayor SDK 58 (percobaan, tidak di-commit).** Klon terpisah, `expo@58.0.26` + `expo install --fix` (RN **0.88.1**, AGP 9.2.1, react-native-screens 4.30.1, expo-image-picker 58.0.10, expo-splash-screen 58.0.14), prebuild bersih, `bundleRelease` R8. Hasil pemindaian ([bukti](tindak-lanjut/gp2-scan-sdk58-percobaan.json)): **15 instruksi / 7 kelas**. Yang hilang hanya `StatusBarModule` RN (warna status bar dihapus di 0.88), `MaterialDatePicker`, dan cabang AccessibilityNodeInfoCompat/expo-application. Yang tersisa:

| Host | Simbol | Asal |
| --- | --- | --- |
| `com.facebook.react.views.view.WindowUtilKt` (`enableEdgeToEdge`) | set status/navigation bar color | RN 0.88.1 |
| `rnscreens…FormSheetDialog` | set status, get navigation bar color | screens 4.30.1 (Material 1.13.0) |
| `expo.modules.imagepicker.ExpoCropImageActivity` | set status/navigation bar color | image-picker 58 |
| `androidx.core.splashscreen.SplashScreen$Impl31` | set status/navigation bar color | splashscreen 1.2.0 (jalur API < 33) |
| `androidx.compose.ui.window.Api28Impl`, `WindowInsetsCompat$Impl28`, Compose text | `layoutInDisplayCutoutMode` | AndroidX |

**Upstream RN.** `WindowUtil.kt` pada tag 0.88.1 dan cabang `main` identik SHA-256 dan masih menetapkan `statusBarColor`/`navigationBarColor`/`layoutInDisplayCutoutMode` ([bukti](tindak-lanjut/rn-upstream-gp2.json)). Belum ada rilis RN yang menghapusnya.

**Kesimpulan.** Tidak ada versi Expo/RN — termasuk upgrade mayor terbaru — yang terbukti menutup GP-2. Pemanggil tersisa ada di RN inti, react-native-screens, expo-image-picker, dan AndroidX; sebagian berada pada cabang OS lama yang memang diperlukan. Pilihan yang tersisa memerlukan keputusan Human Developer:

1. **Terima residual GP-2** sebagai rekomendasi Play yang tidak memblokir rilis (UI edge-to-edge sudah benar pada Android 15/16 menurut uji audit), dicatat untuk ditinjau kembali saat RN/AndroidX menghapus pemanggil tersebut.
2. **Upgrade SDK 58** sebagai pekerjaan terpisah dengan perluasan cakupan (mengurangi 20 → 15, AGP 9, regresi penuh), tetap tidak menutup GP-2.
3. **Patch native** (`patch-package`) RN/screens/image-picker — tidak disarankan: pemeliharaan tinggi dan AndroidX tetap tersisa.

Tidak ada patch spekulatif atau penyembunyian pemanggilan yang dibuat.

## 2. K-5 iOS — siklus hidup UIScene (F-2)

Penyebab: aplikasi yang ditautkan ke SDK iOS 27 wajib memakai siklus hidup scene; template prebuild SDK 57 masih membuat `UIWindow` di AppDelegate tanpa `UIApplicationSceneManifest`.

Perbaikan (mobile `3705c2f`), tanpa upgrade mayor:

- `expo` **57.0.33** sudah menyediakan `ExpoAppSceneDelegate` dan `SceneEventForwarder` (meneruskan URL, user activity, quick action, dan siklus hidup ke AppDelegate serta `RCTLinkingManager`, dengan pencegahan notifikasi ganda; URL cold start dimasukkan kembali ke launch options agar `Linking.getInitialURL()` tetap bekerja).
- `plugins/with-ios-scene-lifecycle.js` menyusun proyek seperti template resmi SDK 58 (`expo-template-bare-minimum` 58.0.35): `SceneDelegate.swift` (`class SceneDelegate: ExpoAppSceneDelegate`) dimasukkan ke target, `UIApplicationSceneManifest` satu scene, AppDelegate mematuhi `ExpoReactNativeFactoryProvider` dan tidak lagi membuat jendela. Override Linking AppDelegate dipertahankan. Plugin gagal keras bila pola template berubah.
- `tests/ios-scene-lifecycle.test.ts` 4/0. Seluruh unit **128/0** (124 lama + 4), `tsc` dan `lint` exit 0.

Uji simulator iPhone 17 Pro, **iOS 27.2** (runtime yang sama dengan crash audit), build **Release arm64** dari sumber `3705c2f` dengan API lokal `127.0.0.1:8971` (fixture sintetis `test_oap_v7_test`), tanda tangan "run locally" Xcode:

- Cold start tanpa Metro: layar Masuk tampil, tanpa laporan crash (`DiagnosticReports` tanpa entri baru).
- `tests/maestro/redesain-v2-fase2/jalankan-ios.sh izin`: perizinan, FAB pengurus, empat peran, wali, notifikasi, dan empat tampilan (gelap, AX-XL, AX-XXXL, gelap+AX-XXXL) **seluruhnya exit 0**; ukur FAB **4/0**. Termasuk `alhasanapps://notifikasi` dan `alhasanapps://izin/laporan` saat aplikasi mati → ← kembali ke Beranda (jalur URL cold start yang berubah karena scene).

Kegagalan selama pengujian yang dicatat apa adanya: (a) build pertama `CODE_SIGNING_ALLOWED=NO` gagal login karena keychain tanpa entitlement — sama dengan catatan tindak lanjut redesain; (b) tanda tangan ulang ad-hoc manual membuat aplikasi gagal bootstrap, lalu diganti tanda tangan "run locally" Xcode; (c) dua alur pertama tersangkut dialog sistem iOS "Open in alhasanApps?" untuk skema URL pada aplikasi yang baru dipasang; setelah disetujui sekali, alur notifikasi (51 langkah) dan tampilan diulang dan lulus. Tidak ada oracle yang diubah.

Batas: simulator, bukan perangkat fisik; iOS Debug/dev client, Push nyata, dan build distribusi iOS belum diuji.

## 3. K-5 Android 12 (F-3)

APK uji Release R8 dari sumber `3705c2f` dibangun (cleartext hanya `127.0.0.1`, `EXPO_PUBLIC_API_BASE_URL` lokal). `classes.dex` identik byte dengan AAB kandidat lokal (`17c0fb85…ce85`). Emulator `Uji_API31` (system image android-31 google_apis arm64, emulator 36.2.12, macOS 27) **tidak dapat boot**: thread utama QEMU menggantung dengan CPU 0% pada konfigurasi Vulkan/host, swiftshader, dan wipe-data (log menyimpan "detected a hanging thread 'QEMU2 main loop'"). Tidak ada perangkat Android 12 fisik. Kasus gelap 2× / profil / picker+crop Android 12 **belum teruji**; tidak diklaim lulus.

Jalan keluar: perangkat fisik Android 12, laporan pre-launch Play (biasanya memuat perangkat Android 12), atau system image API31/32 lain (unduhan SDK perlu persetujuan Human Developer).

## 4. Play final (K-7)

| Item | Nilai |
| --- | --- |
| EAS build | `1d6ddb37-7b8a-41d0-ac5a-f8ae00c97ffc`, profil `production`, versi **1.9.1**, versionCode **20** (remote autoIncrement dari 19), commit `3705c2f`, SDK 57, selesai 2026-11-22 08:07 UTC |
| AAB SHA-256 | `6137013cb53851cd004d7f276d3dd574217165214951261bc91e150ba30610c1` (70.285.517 B) |
| DEX | 1 berkas, 10.497.352 B, SHA-256 `e4f4b45873d5c54acc3c53971c1571b2ecc950fe1256f992b497850d6da93dc8` |
| R8 (`r8.json`) | R8 8.12.22, optimasi/obfuscation/shrinking aktif; bagian tanpa obfuscation/optimasi/shrinking 19,32/19,82/19,19% (±80% teroptimasi); AGP 8.12.3 |
| Mapping | `proguard.map` di AAB, SHA-256 berawalan `fdedb1a924c39da2` |
| Manifest | tanpa `usesCleartextTraffic`/`networkSecurityConfig` |
| GP-2 lokal | 20 instruksi / 11 kelas ([bukti](tindak-lanjut/gp2-scan-eas-20.json)) |

DEX EAS berbeda dari kandidat lokal karena konfigurasi build EAS (env, versionCode, google-services); metrik R8 dan residual GP-2 sama.

**Unggahan internal testing belum terlaksana.** `eas submit --platform android --profile production --id 1d6ddb37…` berhenti: *Google Service Account Keys cannot be set up in --non-interactive mode* — project EAS tidak memiliki kunci akun layanan Play. Browser bawaan juga belum masuk ke Play Console. Kunci/kredensial tidak dimasukkan oleh agen. Langkah Human Developer:

1. Unduh AAB dari halaman build EAS di atas (atau unggah kunci akun layanan ke EAS lalu jalankan `eas submit` tanpa `--non-interactive`).
2. Play Console → Testing → Internal testing → rilis baru → unggah AAB; pastikan App Bundle Explorer menampilkan versionCode 20 dan hash di atas.
3. Setelah diproses: periksa peringatan pengoptimalan kode (GP-1), peringatan API edge-to-edge (GP-2, diperkirakan masih muncul), dan Pre-launch report (termasuk perangkat Android 12 bila tersedia) untuk build yang sama. Catat hasilnya di laporan ini atau audit berikutnya.

## 5. Status akhir dan yang belum

- GP-1 dipertahankan dan terbukti pada AAB distribusi EAS.
- F-2 iOS UIScene diperbaiki dan lulus smoke simulator iOS 27.2.
- F-1/GP-2 tetap terbuka; perlu keputusan Human Developer atas pilihan di §1.
- F-3 Android 12 dan K-7 Play internal/pre-launch belum tersedia.
- Tidak merge, deploy, OTA, atau publikasi produksi. Audit independen Codex berikutnya atas commit mobile `3705c2f`.
