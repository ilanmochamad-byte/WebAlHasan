# Audit Codex — penyingkatan resource yang dioptimalkan 1.9.2

23 November 2026 · branch `claude/penyingkatan-resource-optimal` · Claude Code implementator, Codex auditor independen.

## Keputusan

**Perubahan optimized resource shrinking terverifikasi secara independen; tidak ditemukan temuan baru yang menghalangi penerapan flag pada kode branch ini.** Koreksi auditor terbatas pada komentar/dokumentasi dan keandalan alat uji. Tidak ada koreksi perilaku aplikasi, dependensi, atau aturan keep.

Tiga status tetap dibedakan sesuai rencana workstream:

1. **Implementasi butir optimized resource shrinking selesai.** Prebuild menghasilkan properti yang benar dan AAB menunjukkan `OPTIMIZED`.
2. **Paket Optimasi Android keseluruhan belum lulus audit teknis lokal penuh.** F-1/P1 GP-2 tetap ada; batas F-3/P2 pada audit sebelumnya belum seluruhnya tertutup. Validasi flag ini tidak menerima residual GP-2 atas nama Human Developer.
3. **Konfirmasi Play/kesiapan produksi 1.9.2 belum tersedia.** Tidak ada klaim kartu rekomendasi Play telah hilang atau persetujuan rilis.

## Sumber dan cakupan

| Repo | Baseline | Handoff yang diperiksa |
| --- | --- | --- |
| Web | `400f05629efeec8ae42816bda36e0732dfc5788d` | `29644da72e2ef7aa1cfebfc08971a12b910de406` |
| Mobile | `5726bfb5b82250e9d4dcf993e3067349463b0ab3` | `f70eb803a700675f61b53dfc945b7b2d0e17edcf` |

Baseline dan handoff diperiksa lewat Git; referensi main serta branch diverifikasi ulang ke remote publik lewat `git ls-remote` saat penutupan audit. Audit mobile memakai klon bersih `/private/tmp/ors-codex-audit/mobile`, `npm ci --ignore-scripts` dari lock ter-commit, tanpa mengambil edit lokal `~/alhasanApps`. Tidak membuat worktree/subagen. Web hanya alat uji/bukti/dokumen; tidak ada perubahan backend, API, hak, skema, atau data produksi.

Perubahan implementator hanya `app.json`, plugin R8, satu unit test, dan dokumen. `src/`, `assets/`, `package.json` serta lock identik dengan baseline. Versi tepat satu patch **1.9.1 → 1.9.2**; versionCode lokal **1**, bukan nomor distribusi EAS. AGP tetap **8.12.3**, R8 **8.12.22**, Gradle **9.3.1**, JDK Temurin **17.0.16**, min/compile/target SDK **24/36/36**. Rekomendasi upgrade AGP 9 tidak dikerjakan sesuai cakupan handoff.

## Rebuild dan kontrol independen

Auditor membangun Release baru dari sumber handoff, lalu kontrol dari sumber yang sama dengan `-Pandroid.r8.optimizedResourceShrinking=false`. Keduanya memakai URL tak aktif `https://api-uji.invalid/api/v1` dan satu ABI **arm64-v8a**. AAB Claude mempunyai empat ABI; ukuran/hash seluruh bundle berbeda dan tidak diklaim sebagai binary EAS yang sama.

| Ukuran/metadata | Kontrol auditor | Final auditor |
| --- | --- | --- |
| `resourceOptimizationStatus` | `STANDARD` | **`OPTIMIZED`** |
| DEX tak terkompresi | 10.497.352 B | **10.104.488 B** |
| Berkas `base/res/` | 1.041 | **874** |
| `resources.pb` | 2.419.951 B | **1.829.210 B** |
| Optimasi / obfuscation / shrinking | 80,18 / 80,68 / 80,81% | **79,93 / 80,53 / 80,72%** |
| Checksum R8 | `EXACT_MATCH` | **`EXACT_MATCH`** |

Android CLI resmi **1.1.16919734**, `android analyze --format=json`, menghasilkan metadata R8 full mode dan ketiga rasio tetap melampaui ambang 25% pada rencana. DEX berkurang **392.864 B**. Ada **168 resource terbuang** dan satu resource tambahan (`layout/dev_loading_view.xml`), sehingga neto 167 berkas; angka 168 bukan selisih total berkas.

DEX final auditor SHA-256 **`6167187a1e91ac754e9503b5784d4a6f7ecd05bd82a647b7c05fe64954af938f`** dan mapping **`5ae5641b9e8b724449b5468587b362d89b66e93dace2bed122a8ba02b6e8d6cc`** identik byte dengan hasil Claude. `resources.pb` dan seluruh nama resource juga cocok. AAB final auditor SHA **`4db00e18a11fa63c5ab697dc6365733b18ac9d7d441bf40ff3cf1ca4d1b6ffa6`**; hash lengkap AAB/APK/kontrol/varian uji ada di [artifacts.json](audit-codex/artifacts.json).

Perbandingan ZIP auditor membuktikan **142 resource `assets_*`** (subset `assets_images_*` **116**), **15 font**, **17 resource launcher**, serta **10 resource splash** tidak hilang dan isinya identik. `base/assets/` juga identik antara kontrol/final. Daftar 168 resource yang dibuang cocok dengan bukti implementator. Konfigurasi R8 efektif kontrol 1.721 baris tidak memuat `-dontoptimize`, `-dontobfuscate`, `-dontshrink`, `-ignorewarnings`, keep semua kelas, atau blanket `-dontwarn **`; tidak ada aturan keep baru dalam diff.

Manifest APK distribusi hasil analisis binary tetap non-debuggable, tanpa `usesCleartextTraffic` maupun `networkSecurityConfig`, dan target SDK 36. APK uji terpisah hanya membolehkan cleartext **127.0.0.1**, memakai API fixture loopback8971; DEX-nya identik dengan final. Hash APK yang ditarik kembali dari Android 16 cocok dengan berkas yang dipasang. Bundel tertanam, tanpa Metro/dev client.

## Regresi independen

| Lingkungan / suite | Hasil akhir |
| --- | --- |
| Android 16/API36, kalender izin | **10/0** |
| Android 16/API36, profil/foto/crop/password, teks 1×/2× | **24/0**, satu inspeksi cache privat dilewati |
| Android 16/API36, cetak dan chooser PDF sistem | **7/0** |
| Android 15/API35, kalender, tiga tombol+cutout, terang, teks 1× | **10/0** |
| Android 15/API35, layout tiga tombol+cutout, gelap, teks 2× | **7/0** |
| Android 12/API31, kalender, gestur/terang, teks 1× | **10/0** |

Total **68 assertion native lulus / 0 gagal pada pengulangan akhir**, bukan klaim seluruh matriks modul lama. API35 membuktikan header, label tab, tombol ajukan, kolom dengan keyboard, dan aksi lembar bawah dalam inset sistem. Hash APK terpasang pada ketiga emulator sama dengan APK uji auditor. API lokal mencatat **nol** PHP warning/notice/fatal/deprecated. Rincian setiap label dan percobaan awal: [native-results.json](audit-codex/native-results.json).

Pemeriksaan TypeScript dan lint lulus; unit **129/0**, pemeriksaan satu patch versi lulus. Prebuild diulang secara berurutan dan menghasilkan tepat satu properti optimized shrinking bernilai `true`, dengan minify/shrink tetap aktif. Cache privat picker/crop tidak diperiksa karena `run-as` tidak tersedia pada APK non-debuggable; satu langkah ini dilewati, tidak dihitung lulus.

Inspeksi visual tangkapan auditor menunjukkan kalender lengkap, layar crop dan foto sintetis, serta pratinjau laporan PDF terisi. Pengujian PDF membatalkan dialog sistem tanpa memilih printer atau tujuan berbagi. Seluruh akun/data `sbx_*` sintetis pada `test_ors_codex_profile_test` dan API 127.0.0.1.

### Percobaan awal dan koreksi alat

- TypeScript awal exit2 karena klon bersih tidak memiliki `expo-env.d.ts` yang diabaikan Git: dua deklarasi import CSS tidak ditemukan. Setelah menyediakan referensi tipe Expo standar `/// <reference types="expo/types" />`, TypeScript exit0. Tidak mengubah tsconfig atau melonggarkan pemeriksaan.
- Build awal gagal pada keluaran source map karena auditor keliru menjalankan prebuild ulang ketika Gradle masih berjalan. Prebuild menghapus native directory. Build diulang setelah prebuild selesai, **berhasil 5m27s**; varian uji dan kontrol berikutnya dibangun berurutan.
- Suite profil pertama **0/1**, karena sesi uji tanggal masih aktif. Sesudah `pm clear` khusus paket emulator, suite asli tanpa perubahan assertion menghasilkan **24/0**. Hasil awal disimpan di penyimpanan privat, tidak disembunyikan atau dihitung lulus.
- Helper tanggal implementator dapat membaca XML usang saat dump gagal dan tidak mengembalikan exit gagal untuk assertion palsu. Auditor menambahkan penghapusan snapshot sebelum dump serta exit1 bila ada assertion gagal. Kontrol terisolasi: helper lama membaca satu node usang, helper baru **0 node**. Sepuluh pemanggilan assertion bisnis identik AST/hash; tidak melemahkan oracle agar uji lulus. [date-oracle-control.json](audit-codex/date-oracle-control.json).

## GP-2 dan kriteria workstream

Pemindaian DEX aktual auditor menghasilkan **18 instruksi pada 10 kelas**, dibanding **20/11** pada baseline 1.9.1 audit sebelumnya. Dua panggilan warna pada `MaterialDatePicker` hilang; host kelas sintetis R8 lainnya dapat berganti karena penggabungan outline sehingga perubahan nama host tidak dianggap pemanggil aplikasi baru. Residual RN, screens/FormSheet, Compose/AndroidX dan crop masih ada. Ini mengoreksi kalimat implementator yang menyebut daftar sama, **bukan menutup F-1/P1**.

| Kriteria rencana awal | Status setelah audit ini |
| --- | --- |
| K-1 | Sumber, baseline, artefak dan preservasi terverifikasi; rebuild arm64 dibedakan dari AAB empat ABI/EAS. |
| K-2 | R8/shrinking/optimized resource shrinking aktif; mapping dan metadata tersedia. |
| K-3 | Rasio lokal terukur untuk ketiga metrik; tidak mengklaim skor server Play 1.9.2. |
| K-4 | **Belum terpenuhi penuh: F-1/P1 GP-2 residual 18/10 tetap terbuka.** |
| K-5 | Regresi terarah di atas lulus; tidak mengulang semua modul atau seluruh matriks lama. **F-3/P2 Android 12 tiga tombol+cutout+2× dan foto/crop Android 12 pada audit terdahulu belum ditutup.** |
| K-6 | Satu patch, reproduksi/bukti/koreksi dokumentasi dan audit ini tersedia pada branch fitur. |
| K-7 | **Belum tersedia:** AAB EAS/analisis Play/pre-launch 1.9.2, perangkat fisik, Push nyata serta upgrade/signing Play. |

F-2 UIScene telah ditutup pada audit sebelumnya; iOS tidak dibangun ulang pada perubahan Android-only ini. GP-2, rekomendasi AGP 9, konfirmasi Play, dan penerimaan residual tetap keputusan Human Developer. Audit ini tidak mengubah status gerbang produksi V5/V6/V7, Keamanan, atau pekerjaan terdahulu.

## Reproduksi dan handoff

Pada klon bersih branch/commit handoff, siapkan tipe Expo, jalankan `npm ci --ignore-scripts`, `npx tsc --noEmit`, `npm run lint`, `node --experimental-strip-types --test tests/*.test.ts`, dan `npm run versi:periksa -- --dasar 5726bfb`. Gunakan JDK17, SDK Android setempat, `NODE_ENV=production` dan API URL `https://api-uji.invalid/api/v1`, lalu prebuild Android **sampai selesai** sebelum Gradle:

```sh
npx expo prebuild --platform android --no-install
cd android
./gradlew :app:bundleRelease :app:assembleRelease -PreactNativeArchitectures=arm64-v8a --no-daemon --max-workers=4
android analyze --format=json app/build/outputs/bundle/release/app-release.aab
```

Arsipkan final sebelum membangun kontrol dengan properti CLI `-Pandroid.r8.optimizedResourceShrinking=false`. Jangan menimpa artefak final dengan keluaran kontrol. Varian HTTP loopback hanya untuk emulator; setelah perubahan URL, paksa tugas `:app:createBundleReleaseJsAndAssets --rerun` agar tidak memakai bundel URL lama. Jangan mengunggah varian HTTP, signer debug, atau versionCode1 ke Play.

Regresi memakai `uji_tanggal.py`, suite asli `tests/browser/uji-profil-impor-f2-android.mjs` (`RILIS=1`, `METRO=`), dan `tests/android/oap_audit_native.py` mode `documents`/`layout`, dengan `ANDROID_SERIAL` serta fixture yang sesuai. Jangan memakai sesi aplikasi dari uji sebelumnya atau password fixture yang sudah dimutasi tanpa menyiapkan ulang prasyarat. Bukti tersanitasi di [audit-codex/](audit-codex/); binary/mapping/log lengkap dan tangkapan tetap privat `/private/tmp/ors-codex-audit/`.

Preservasi akhir **100/100 berkas kontrol cocok**: dua paket mobile yang kotor dan lima direktori untracked web tetap utuh. Cache Python yang dibuat audit sudah dihapus; proses API audit dan emulator dihentikan. Berkas pekerjaan lain tidak di-stage atau di-commit. [preservation.json](audit-codex/preservation.json).

Koreksi komentar dan dokumen: AGP opt-in **8.12**, bukan 8.6; penamaan aset 142 `assets_*` dengan subset 116 `assets_images_*`; GP-2 aktual 18/10. Tidak ada perubahan runtime/config/lock aplikasi yang di-commit oleh auditor; plugin hanya dikoreksi komentarnya. Mobile audit commit **`5586e91`**; web menyimpan laporan lengkap ini beserta bukti dan alat uji. Commit/push hanya branch fitur, lalu berhenti; tidak merge, deploy, migrasi produksi, OTA, build/upload Play atau fase lain.

Referensi: [Android — enable optimized resource shrinking](https://developer.android.com/topic/performance/app-optimization/enable-app-optimization#optimize-resource-shrinking) menyebut opt-in AGP 8.12/8.13 dan bawaan AGP 9; [Expo BuildProperties SDK 57](https://docs.expo.dev/versions/v57.0.0/sdk/build-properties/) menjelaskan konfigurasi melalui prebuild. Dokumen rencana [rencana.md](../rencana.md) dan [audit sebelumnya](../AUDIT-ULANG-CODEX.md) tetap berlaku untuk batas keseluruhan workstream.
