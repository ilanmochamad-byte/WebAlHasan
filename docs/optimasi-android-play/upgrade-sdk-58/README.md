# Upgrade Expo SDK 58: AGP 9 dan sisa GP-2 (23 November 2026)

Laporan implementator Claude Code, **bukan audit independen**. Workstream Optimasi Android Google Play: Claude Code implementator, Codex auditor.

## Keputusan dan cakupan

Human Developer memilih menyelesaikan rekomendasi Play "upgrade ke AGP 9" bersama sisa GP-2 lewat upgrade Expo SDK yang mendukung AGP 9 (opsi O-2). Expo SDK 58 kini stabil (`expo` 58.0.26 = `latest` di npm) dan memakai React Native 0.88.1, yang katalognya menetapkan **AGP 9.2.1** (patch terbaru jalur 9.2 di Google Maven saat diuji).

- Mobile: `claude/upgrade-expo-sdk-58` commit `1a915bd` dari `origin/main` `6328517` (1.9.2), klon bersih. Versi **1.9.2 → 1.10.0** (minor: upgrade platform, bukan koreksi kecil). Checkout `~/alhasanApps` dan dua edit paket pekerjaan lain tidak disentuh.
- Web: branch sama nama dari `origin/main` `6b0fe24`, hanya dokumen dan bukti.
- API, hak, skema, aturan bisnis, `expo-screen-capture`, dan transport tidak berubah.

## Hasil singkat

| Butir | Sebelum (1.9.2, SDK 57) | Sesudah (1.10.0, SDK 58) |
| --- | --- | --- |
| AGP pada AAB (`app-metadata.properties`) | 8.12.3 | **9.2.1** |
| R8 (`r8.json`) | 8.12.22 | **9.2.14**, full mode, `EXACT_MATCH` |
| Optimasi / obfuscation / shrinking (`android analyze`) | ±80% | **76,9 / 77,5 / 77,7%** (ambang rencana 25%) |
| `resourceOptimizationStatus` | OPTIMIZED | **OPTIMIZED** |
| DEX (rebuild arm64 lokal) | 10.104.488 B | 10.536.500 B, 1 DEX |
| Residual GP-2 (instruksi / kelas host) | 18 / 10 | **15 / 7** |

**AGP 9: selesai** pada build lokal. **GP-2: belum nol.** Sisa 15 instruksi ada di kode library pihak ketiga, bukan kode aplikasi:

| Host | API | Asal |
| --- | --- | --- |
| `com.facebook.react.views.view.WindowUtilKt` | set status/navigation bar color | React Native 0.88.1 (`enableEdgeToEdge`); `main` RN masih sama |
| `androidx.core.view.WindowInsetsCompat$Impl28` (outline) | `layoutInDisplayCutoutMode` | AndroidX Core |
| `androidx.core.splashscreen.SplashScreen$Impl31` | set status/navigation bar color | AndroidX SplashScreen |
| `androidx.compose.ui.window.Api28Impl`, `…PlatformSelectionBehaviorsImpl…` | `layoutInDisplayCutoutMode` | Jetpack Compose (lewat `@expo/ui`) |
| `com.swmansion.rnscreens.gamma.modals.formsheet.FormSheetDialog` | get/set bar color | react-native-screens 4.30.1 |
| `expo.modules.imagepicker.ExpoCropImageActivity` | set bar color | expo-image-picker 58.0.10 |

Perubahan dibanding 1.9.2: kelas `StatusBarModule` RN dan cabang AndroidX/Material lainnya hilang. Menghapus sisanya memerlukan rilis upstream (RN, AndroidX, react-native-screens, expo-image-picker) atau patch native lokal pada library; keduanya tidak dikerjakan di sini. Rekomendasi: terima sisa ini sebagai residual library dan tinjau ulang pada SDK berikutnya — keputusan tetap Human Developer.

## Perubahan kode mobile

- `package.json`/`package-lock.json`: seluruh paket Expo ke ~58, React 19.3.0, RN 0.88.1, reanimated 4.7.1, worklets 0.13.0, screens 4.30.1, safe-area 5.11.1, gesture-handler 3.3.0 (tidak dipakai langsung oleh kode aplikasi), `@types/react` 19.3, `eslint-config-expo` 58. Lockfile dibuat ulang karena `npm install` menolak resolusi di atas lock SDK 57 (ERESOLVE). `expo install --check` dan `expo-doctor` 20/20 lulus.
- `plugins/with-android-r8-optimize.js`: tidak lagi menyematkan AGP 8.12.3. Fungsi baru `pakaiAgpReactNative` membuang versi eksplisit agar AGP selalu dari katalog RN (9.2.1). Template SDK 58 sudah memakai `proguard-android-optimize.txt`, sehingga penggantian ProGuard menjadi no-op (tetap dijaga). Properti `android.r8.optimizedResourceShrinking=true` tetap ditulis walau sudah bawaan AGP 9.
- Penyesuaian tipe RN 0.88: `useColorScheme()` kini `'light' | 'dark' | null` (tanpa `'unspecified'`) di `app-tabs.tsx` dan `use-theme.ts`; ref `ScrollViewInstance`/`TextInputInstance` di `keyboard-aware-scroll-view.tsx`; ref `Pressable` pada `app-tabs.web.tsx` (tipe expo-router masih `RefAttributes<View>`).
- `push-registration.ts`: enum notifikasi diimpor bernama (lint `import/namespace` tidak membaca build SWC expo-notifications 58). Perilaku sama.
- `app-tabs.tsx`: `expo-router/unstable-native-tabs` (usang di 58) → `expo-router/native-tabs`, implementasi yang sama.
- iOS: plugin UIScene tetap; prebuild SDK 58 menghasilkan satu `SceneDelegate`.
- Uji: `tests/optimasi-android.test.ts` (oracle AGP 8.12 diganti: AGP dari katalog RN, katalog ≥ 9) dan `tests/ios-scene-lifecycle.test.ts` (perbandingan versi kini memeriksa major; 58.0.x sebelumnya salah dianggap < 57.0.33).

## Bukti

| Pemeriksaan | Hasil |
| --- | --- |
| `tsc --noEmit`, `expo lint` | exit 0 |
| Unit `node --test tests/*.test.ts` | **130/0** (baseline 129/0; +1 uji katalog AGP) |
| `expo-doctor` | 20/20 |
| `bundleRelease` + `assembleRelease` R8 | sukses, AGP 9.2.1 ([analisis](android-analyze.json), [pindai GP-2](gp2-scan-sdk58.json)) |
| Expo web probe tindak lanjut redesain v2 (akun, izin, keuangan, pembinaan, kesehatan) | **35/0** ([web-*.txt](.)) |
| Android 16 emulator, APK uji Release R8 tanpa Metro: f1 / profil / izin / pengajian / keuangan | **70/1 · 24/0 · 54/0 · 13/0 · 25/0** |
| iOS 26.2 simulator, build Release baru SDK 58 | cold start ke layar Masuk tanpa crash ([tangkapan](ios-release-masuk.png)) |

Satu kegagalan f1 adalah "ubin Beranda tampil" pada teks 2,0×. Pada pengulangan, kasus yang gagal berpindah (teks-2.0 lulus, gelap-teks-2.0 gagal); tangkapan menunjukkan tata letak sama dengan baseline SDK 57, hanya jarak gulir gestur emulator yang lebih pendek sehingga ubin berada di bawah layar. Pengulangan: teks-2.0 + gelap-teks-2.0 **58/1** (kini gelap-teks-2.0 yang gagal, [log](f1-api36-ulang1.txt)), lalu gelap-teks-2.0 saja **53/0** ([log](f1-api36-ulang2.txt)). Dinilai ketidakstabilan langkah gulir alat uji, bukan regresi aplikasi; oracle tidak diubah.

APK uji = APK hasil build yang sama ditambah `network_security_config_uji.xml` (cleartext hanya 127.0.0.1) dan `EXPO_PUBLIC_API_BASE_URL` lokal; AAB/APK final tidak memuatnya. AAB yang dianalisis dibangun sebelum penggantian impor `native-tabs` (perubahan JS saja; DEX tidak terpengaruh). Build iOS juga sebelum penggantian itu.

## Batas (belum diuji)

EAS build 1.10.0 dan skor/rekomendasi Play Console, pre-launch report, Android 12/15, iOS Debug, perangkat fisik, Push nyata, upgrade 1.9.2 → 1.10.0 dengan sesi tersimpan, suite native lain (rapor, kesehatan, Keamanan, pemilih tanggal), MySQL/cPanel, UAT. Binary native baru wajib (bukan OTA). Tidak merge, deploy, OTA, atau unggah Play. Audit independen Codex berikutnya.


## Audit independen Codex (23 November 2026)

Lihat [AUDIT-CODEX.md](AUDIT-CODEX.md). AGP 9 terverifikasi pada rebuild independen; koreksi File.write asinkron menutup F-4/P2. GP-2 tetap 15/7 dan batas Android 12 cutout belum tertutup, sehingga paket keseluruhan belum lulus penuh. Play/kesiapan produksi 1.10.0 belum terkonfirmasi; tidak ada penerimaan residual atau izin rilis dari auditor.
