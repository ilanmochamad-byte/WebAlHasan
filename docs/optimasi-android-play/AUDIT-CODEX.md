# Audit independen Codex — optimasi Android Google Play

> **Pembaruan:** tindak lanjut Claude `3705c2f`/`7621a11` sudah diperiksa dalam [audit ulang 22 November](AUDIT-ULANG-CODEX.md). F-2 iOS ditutup pada simulator Release; F-1/GP-2, F-3 dan konfirmasi Play mengikuti hasil terbaru di sana. Angka dan status di bawah adalah riwayat audit pertama.

22 November 2026. Cakupan hanya GP-1 (DEX/R8) dan GP-2 (API tampilan), satu tahap pada `claude/optimasi-android-play` di kedua repositori. Claude Code implementator, Codex auditor, sesuai keputusan Human Developer 21 November. Dokumen ini menggantikan penilaian kesiapan audit pada laporan implementator; tidak mengubah keputusan produksi.

## Keputusan

**Implementasi paket belum selesai sepenuhnya:** GP-1 terbukti, residual GP-2 masih terbuka. **Audit teknis lokal K-1…K-6 belum lulus**, karena K-4 belum terpenuhi dan K-5 belum tervalidasi sepenuhnya. **Konfirmasi Play atas artefak final dan kesiapan produksi belum terkonfirmasi.** Bukti Play rilis lama tersedia dan diperiksa langsung; itu bukan validasi 1.9.1. Tidak ada merge, deploy, publikasi, OTA, atau pekerjaan PRD lain.

| Kriteria | Keputusan audit | Dasar / batas |
| --- | --- | --- |
| K-1 | **Terpenuhi untuk keterlacakan lokal** | Baseline, handoff, sumber/lock bersih, environment dan hash final ditelusuri. AAB asli Play/EAS identik; arsip sumber unggahan EAS lama tidak tersedia, atribusi commit tetap metadata EAS. Preservasi edit lokal diperiksa. |
| K-2 | **Terpenuhi** | R8 full mode dan resource shrinking STANDARD terbukti pada Gradle, log, metadata, mapping serta DEX final. Aturan konsumen bawaan diperiksa; tidak menambah blanket keep/dontwarn. |
| K-3 | **Terpenuhi secara lokal** | Analisis resmi final, checksum EXACT_MATCH, skor optimasi/obfuscation/shrinking **80,32/80,83/80,95%**; tidak memakai pengecualian ukuran atau skor Configuration Analyzer. |
| K-4 | **Belum terpenuhi** | F-1: residual **20 instruksi / 11 kelas** tetap di final. Pemetaan dan alasan kompatibilitas tersedia; belum ada koreksi/validasi Play yang menutupnya. |
| K-5 | **Belum terpenuhi sepenuhnya** | Android Release R8 dan web diuji sendiri; rincian keberhasilan dan kegagalan awal dipertahankan. Smoke iOS SDK 27.2 gagal pada final dan kontrol baseline (F-2). F-3 mencatat kekurangan bukti Android12 2×/profil. Fisik, Push nyata dan upgrade distribusi belum diuji. |
| K-6 | **Terpenuhi pada handoff audit** | Patch sekali 1.9.1, remote versioning terjaga, panduan reproduksi/pemulihan dan bukti tersanitasi; commit/push kedua branch fitur, tanpa perubahan aplikasi/API/skema oleh auditor. |
| K-7 | **Belum terkonfirmasi untuk final** | Play hanya menyediakan rilis 19; internal/pre-launch report untuk AAB 1.9.1 final belum tersedia. Tidak menyatakan peringatan produksi hilang atau siap produksi. |

## 1. Sumber, baseline, dan preservasi

| Repo | Baseline main terverifikasi setelah fetch | Handoff yang diaudit |
| --- | --- | --- |
| Web | `e6f57b86a1d965664f95c7fc9d10ffa6991839ab` | `757c15d3e516a8bc271929c4c60ae2781644d045` |
| Mobile | `82f156ab98cb62fd5a97259be944f4cfcf75ffd0` | `747e55d` (kode), `4b20d257574df2a23e38ac623a1f6a4a20aa8af4` (handoff) |

Seluruh commit paket diperiksa: web satu commit `757c15d`; mobile `747e55d` dan `4b20d25`. Pohon kode/config/lock `747e55d` dan `4b20d25` identik. Baseline mobile berbeda dari commit rilis `84ddebb` hanya pada dokumen. `git fetch origin` berhasil di kedua checkout. Tidak ada implementasi pada `main`, reset, stash, atau worktree.

Checkout mobile utama sengaja masih `82f156a`, dengan edit lokal `package.json`/`package-lock.json`. Audit/build/commit mobile dilakukan pada **klon Git bersih terpisah**, `/private/tmp/oap-codex-audit/mobile`, branch fitur yang sama, dari remote handoff. Tidak memakai `node_modules`, folder native, `.env.local`, ataupun binary checkout utama. `npm ci` menggunakan lock ter-commit dan cache npm baru. Cache unduhan dependency Gradle disalin ke `GRADLE_USER_HOME` terpisah; output native baru, `--no-build-cache`, seluruh 863 task build final dieksekusi. Cache dependency bukan cache hasil build aplikasi.

Sebelum audit, hash 93 berkas untracked web dan dua berkas paket lokal mobile dicatat secara privat. Direktori `database/pemulihan/`, panduan/presentasi V4, `output/`, dan tangkapan penugasan Keamanan tidak disentuh atau di-stage. Pemeriksaan ulang di tengah audit: **95/95 hash tetap**. Pemeriksaan akhir sebelum commit: **95/95 hash tetap**, tidak ada edit lokal pekerjaan lain yang masuk staging ([ringkasan](audit-codex/preservation.json)).

Proses Claude desktop/Code masih ada tetapi idle tanpa subprocess implementasi/build; handoff telah dipush. Tidak menjalankan Claude, subagen, atau pekerjaan implementator secara bersamaan. Build hanya pada klon audit sendiri.

## 2. Asal binary dan toolchain

Auditor menjalankan `eas build:view` read-only atas build **`24ad663e-8f31-444c-b593-9dced22e89bf`**, lalu mengunduh ulang artefak EAS. Metadata: FINISHED, production/STORE, 1.9.0 (19), SDK 57, commit `84ddebbbdedc022dae42f323cd37ae9fcc471cc2`, selesai 13 November 2026 14:52:06 UTC. EAS tidak memberikan arsip sumber/lock unggahan atau penanda dirty pada respons ini; commit tersebut adalah atribusi metadata build, bukan bukti kriptografis bahwa unggahan sumber bersih. DEX asli mengonfirmasi RN **0.86.2** melalui `ReactNativeVersion` (major 0, minor 86, patch 2).

Auditor juga membuka Play Console yang sudah tersedia di Safari, secara baca-saja, dan mengunduh **File asli** untuk bundle aktif **19 (1.9.0)**. SHA-256 unduhan Play, unduhan EAS independen, dan bukti implementator **identik**:

`015f4ddd6045c7666cf60a6cf0d3ec7f2761782c379d7bd519b0ad1ad553c908`

Ini **AAB asli produksi**, bukan rekonstruksi baseline. Ukuran 74.355.173 byte; 5 DEX, 46.992.180 byte tak terkompresi. Play memperlihatkan target 36, min API 24, DEX 47 MB dan obfuscation 1%; sesuai hasil analisis independen. Unduhan browser di `~/Downloads/19.aab`; tidak dimasukkan Git. Tidak menyalin akun/email atau data produksi dari tab lain ke bukti.

Build audit: macOS arm64, Node **26.7.0**, npm **11.19.0**, OpenJDK **17.0.16**, Gradle **9.3.1**, AGP **8.12.3**, R8 **8.12.22**, Kotlin **2.1.20**, NDK **27.1.12297006**, build-tools **36.0.0**; min/target/compile **24/36/36**. Dependency bersih: Expo **57.0.15**, RN **0.86.2**, screens **4.26.2**, image-picker **57.0.20**, build-properties **57.0.23**, schema-utils **57.0.3**, Reanimated **4.5.1**, Worklets **0.10.1**. Metadata Google Maven mengonfirmasi 8.12.3 patch stabil terbaru dalam jalur 8.12 saat audit; tidak ada upgrade mayor/minor toolchain dalam kode paket.

Environment final lokal: `NODE_ENV=production`, `EXPO_NO_DOTENV=1`, API placeholder HTTPS `https://api-uji.invalid/api/v1`; tidak ada `.env`/Firebase client file atau credential distribusi yang disalin. Seluruh binary lokal memakai kunci debug template, **1.9.1 (versionCode 1)**, non-debuggable. Ini artefak audit, **bukan binary distribusi** atau simulasi Play App Signing. Sertifikat debug lokal SHA-256 `fac61745dc0903786fb9ede62a962b399f7348f0bb6f899b8332667591033b9c`; penandatangan AAB produksi `398ef65828d766dbc9683cf03c385df51710f3d475b51ec3a8803f2cc883b681` berbeda. Sertifikat di AAB adalah signer unggahan, bukan pembuktian sertifikat APK Play App Signing. Build ID final lokal adalah sumber handoff + log build + hash berikut; tidak ada build ID EAS 1.9.1.

| Artefak | SHA-256 auditor |
| --- | --- |
| AAB final audit | `92229e95e5f718a65e21a590da5e060293609fcca11477177f8658190d68e50d` |
| APK final audit | `f6e6ba5ef3546642979fda6f23f42e71e5e0f14928673eb26633bef92f2b5d74` |
| APK uji Release audit | `df8bc814600a938f9f3427aecbbe97af4cd300a2d979b810a17137da32f49838` |
| Mapping audit | `43799e8cf9bbbe33e6b300b4fba791ccbaf078f9da0db8491d799cb8e5d8ed82` |
| DEX audit, final dan uji | `37b3ec6404b41e6ac7f6568cce1444006336a8ce6fb615d7f23c4d50a9914082` |

Lokasi/ukuran rinci: [manifest artefak audit](audit-codex/artifacts.json). Hash AAB/APK berbeda dari implementator karena rebuild native dan kemasan/signature; jangan mengganti identitas keduanya. **DEX, mapping, metadata R8, dan bundel Hermes final identik byte dengan handoff.** Selisih anggota AAB terhadap handoff adalah native `.so`/debug symbols yang dibangun ulang dan signature. Tidak mengklaim seluruh bundle bit-reproducible.

APK uji dibuat sesudah final diarsipkan. Perbedaannya terhadap APK final: `assets/app.config` (hanya `extra.apiBaseUrl`), bundel JS dengan endpoint loopback, manifest, tabel resource, dan satu XML network-security-config. **Seluruh `.so`, DEX dan mapping identik byte**. XML uji menolak cleartext secara default dan mengizinkan hanya `127.0.0.1`. Manifest generated dipulihkan sesudah build; tidak ada patch `node_modules`/perubahan tracked config untuk tes. AAB/APK final tidak memuat pelonggaran tersebut. Bukti runtime varian uji tidak diklaim sebagai uji end-to-end terhadap endpoint placeholder atau hash distribusi EAS yang belum dibangun.

## 3. GP-1 dan aturan konsumen

Prebuild bersih menghasilkan `android.enableMinifyInReleaseBuilds=true`, `android.enableShrinkResourcesInReleaseBuilds=true`, `getDefaultProguardFile("proguard-android-optimize.txt")`, dan classpath AGP 8.12.3. Build final `bundleRelease assembleRelease --no-build-cache --no-daemon --max-workers=4`: **exit 0, 12m44s, 863/863 task dieksekusi**; log memuat `:app:minifyReleaseWithR8`.

`proguard.map` tertanam dalam AAB dan hash-nya cocok dengan `mapping.txt`. Metadata R8 menunjuk checksum DEX yang benar. R8 full mode, optimasi/obfuscation/shrinking aktif. Resource shrinking adalah **STANDARD**, bukan integrated optimized shrinking; `isOptimizedShrinkingEnabled=false` tidak berarti seluruh resource shrinking mati. Sumber plugin dan properti benar-benar berpengaruh pada binary, bukan hanya pada JSON.

Konfigurasi gabungan 1.720 baris diperiksa independen. Metadata mempertahankan annotation runtime, Signature, InnerClasses/EnclosingMethod; Exceptions/MethodParameters/local-variable/SourceFile/SourceDebugExtension tidak dipertahankan oleh konfigurasi R8 efektif. Mapping terikat hash tersedia untuk deobfuscation; pengujian JNI/refleksi/modul tidak diganti dengan asumsi semua atribut tetap. Tidak ada `-dontoptimize`, `-dontobfuscate`, `-dontshrink`, `-ignorewarnings`, `-dontwarn **`, atau keep seluruh kelas tanpa batas paket. **Ada aturan paket luas bawaan**, bukan berarti semua aturan sempit: keep Reanimated/Worklets, React bridge/fabric/turbomodule/JNI, notifications, dan zstd; serta dontwarn paket AndroidX/support, RN, Okio/OkHttp, anotasi, dan library lain. Asal tiap aturan dan line konfigurasi tersimpan di [consumer-rules.json](audit-codex/consumer-rules.json). Dua aturan keep template menduplikasi consumer Reanimated. Tidak ada aturan baru untuk menutupi kegagalan build. Aturan bawaan tetap membatasi optimasi, tetapi metrik final terukur di bawah tetap memenuhi minimum; tidak menghapus aturan JNI/refleksi tanpa bukti aman.

Analisis resmi menggunakan **Android CLI 1.1.16919734**, diunduh dari situs Google, `--no-metrics analyze --format=json`. Unduhan optimization pack pertama macet dan dihentikan; retry berhasil. Hasil ini **analisis artefak aplikasi final**, bukan skor R8 Configuration Analyzer terhadap keep rules.

| Ukuran/metrik | AAB produksi 19 | AAB handoff dan AAB audit |
| --- | ---: | ---: |
| DEX tak terkompresi | 46.992.180 B / 5 DEX | **10.411.972 B / 1 DEX** |
| Compiler/AGP | D8 8.12.14 / 8.12.0 | R8 8.12.22 / 8.12.3 |
| Optimasi | tidak tersedia | **80,32%** |
| Obfuscation | heuristik lokal **1,3244%**; Play **1%** | **80,83%** |
| Shrinking | tidak tersedia | **80,95%** |
| Checksum metadata R8 | tidak ada R8 | **EXACT_MATCH** |
| Resource optimization | — | **STANDARD** |

Ketiga minimum 25% terpenuhi secara lokal pada AAB yang hash-nya dicatat. Ukuran DEX turun **77,84%**. Final 10,41 MB desimal / 9,93 MiB berada di sekitar ambang “10 MB”; **tidak menggunakan pengecualian ukuran** dan tidak menyamakan 9,93 MiB dengan skor ≥25%. `mappingObfuscationRatio` (~89,35%) dan heuristik DEX (~89,40%) juga bukan angka R8 utama 80,83%; tidak dipertukarkan. Hasil resmi tersanitasi: [final audit](audit-codex/android-analyze-final.json), [kontrol rilis 19/handoff](audit-codex/android-analyze-baseline-handoff.json). Konfirmasi server Play atas 1.9.1 tetap K-7 terpisah.

## 4. GP-2: temuan terbuka F-1

**F-1 — P1 / tinggi, menghalangi kelulusan K-4 dan paket utuh.** Lokasi sumber ter-commit: mobile `package-lock.json:10025` (RN), `:10151` (screens), `:6194` (image-picker); pemanggil tepat pada dependency RN 0.86.2 `ReactAndroid/src/main/java/com/facebook/react/modules/statusbar/StatusBarModule.kt:71,104,107,112` dan `views/view/WindowUtil.kt:134–155,170–205`. `app.json:75–84`/plugin hanya menangani GP-1; tidak menyediakan koreksi GP-2.

Auditor mengekstrak seluruh DEX baseline dan final, menjalankan SDK `dexdump -d`, menghitung **instruksi** invoke/akses field (bukan sekadar string), lalu menghubungkan host final ke mapping build yang cocok. Hasil independen: **31 instruksi / 15 kelas → 20 instruksi / 11 kelas**. Hasil final rebuild sama dengan handoff; [daftar simbol dan host](audit-codex/zip-dex-final.json), [skrip reproduksi](audit-codex/analyze_bundle.py). Inlining/merging dapat memindahkan host; nama host akhir tidak selalu nama pemanggil awal.

| Simbol/pemanggil | Versi ter-resolve dan alasan kompatibilitas | Keputusan |
| --- | --- | --- |
| `getStatusBarColor`, `setStatusBarColor` — StatusBarModule/lambda | RN 0.86.2; getter konstanta tanpa guard API 35. `setColor` mempunyai guard edge-to-edge, bukan penghapusan seluruh rujukan | Residual |
| Warna status/nav — `WindowUtilKt.enableEdgeToEdge` | RN 0.86.2; setter status tanpa guard `<35`, nav bercabang API 29; flag edge-to-edge aktif pada 15/16 | Residual; setter yang no-op di OS baru tetap pemanggil DEX |
| `SHORT_EDGES`, `DEFAULT` / `layoutInDisplayCutoutMode` | RN hide/show pada jalur non-edge-to-edge; cabang lama diperlukan agar OS lama tetap kompatibel. Final juga memuat outline API-model cutout dan Compose `Api28Impl` | Tidak menghapus cabang OS lama secara serampangan; residual scan belum tertutup |
| FormSheet/BottomSheetDialog, EdgeToEdgeUtils (host final termasuk MaterialDatePicker) | screens 4.26.2 → Material **1.13.0**, pemanggil warna/getNavigationBarColor tersisa | Residual |
| ExpoCropImageActivity → WindowCompat | image-picker **57.0.20**, AndroidX core **1.18.0**; crop benar-benar digunakan | Residual |
| SplashScreen Impl31 lambda, Compose Api28Impl | splashscreen **1.2.0**, Compose UI Android **1.11.0-beta02**. Sumber splashscreen membatasi `applyAppSystemUiTheme()` ke **API <33** pada exit listener: ini bukti jalur kompatibilitas lama, bukan pemanggilan runtime pada 35/36 | Rujukan tetap dalam DEX; bedakan jalur lama yang terjaga dari setter RN/WindowCompat/Material tanpa guard `<35` |
| EdgeToEdgeApi23–30, NavigationView/SheetDialog, browser cutout | activity **1.11.0**, browser **1.6.0**; cabang kompatibilitas lama ada pada baseline | Sebagian terhapus oleh R8; tidak membuktikan semua GP-2 selesai |

Versi di atas diperiksa melalui Gradle `releaseRuntimeClasspath`, bukan hanya versi npm. `src/` tidak menambahkan pemanggilan warna/cutout; `<StatusBar style="auto" />` mengatur gaya ikon. UI yang benar tidak meniadakan residual pemindaian Play.

Sumber resmi RN **0.86.2 dan 0.86.3** untuk kedua berkas dibandingkan independen, **identik SHA-256** ([bukti](audit-codex/rn-upstream-comparison.json)); mengambil patch lokal RN pengguna tidak menyelesaikannya. Material 1.14.0 diperiksa sebagai opsi, **tidak diadopsi**: guard API 35 pada jalur Material saja tidak menghilangkan pemanggil RN/AndroidX atau menjamin scan Play bersih. Tidak mengadopsi upgrade SDK/RN mayor atau patch native sementara untuk mendapatkan label lulus. Tidak ditemukan koreksi kompatibel yang telah terbukti menutup seluruh residual SDK 57 dalam audit ini; mengubah Material saja belum cukup. Karena itu tidak membuat patch spekulatif yang belum dapat dibuktikan aman/reproducible. Penutupan F-1 memerlukan koreksi kompatibel dan reproducible yang membuktikan seluruh residual relevan selesai, bukti Play atas final yang sesuai, atau keputusan eksplisit Human Developer atas perubahan cakupan/batas. Sampai itu tersedia, **F-1 tetap terbuka**, bukan otomatis nonblokir.

## 5. Pengujian independen

TypeScript **exit 0**, lint **exit 0**, unit **124 lulus / 0 gagal / 0 lewati**, patch versi **1.9.0 → 1.9.1 lulus**. Percobaan pemeriksa versi pertama gagal karena clone single-branch belum mempunyai `origin/main`; setelah fetch ref main yang terverifikasi, pemeriksaan resmi lulus tanpa perubahan oracle/kode.

Expo web export bersih **exit 0**. Smoke browser **35/0**: akun 8, izin 10, keuangan 7, pembinaan 4, kesehatan 6; tanpa permintaan keluar localhost. Percobaan awal tidak dapat meluncurkan Chromium yang belum terpasang; browser Playwright versi proyek kemudian dipasang dan suite diulang. Tidak menghitung kegagalan alat atau exit 0 wrapper sebagai hasil lulus assertion.

Matriks utama API 36, navigasi gestur: izin **54/0**, pengajian/absensi **13/0**, keuangan/BSA **25/0**, pembinaan/rapor **17/0**, kesehatan **7/0**, Keamanan **10/0**. Peran/sesi/tampilan awal **69/2** (dua assertion ubin setelah satu guliran pada teks 2×; kontrol berpasangan di bawah). Profil awal **5/1**, pengulangan oracle sama **18/1**: penghenti pada locator `Buka profil`, bukan crash; foto/crop/unggah dan pembaruan profil sudah terbukti pada pengulangan. Oracle tampilan **tidak diubah**: kontrol HANYA=C pada baseline rekonstruksi dan final, emulator/fixture sama, masing-masing **24/0** (terang 1×, 1,3×, 2×, gelap 2×). Dua kegagalan awal tidak muncul lagi; penyebab timing/input belum dibuktikan, sehingga tidak dilabeli sebagai bug baseline atau hasil koreksi aplikasi. Kedua hasil awal tetap tersimpan dalam ledger.

**Koreksi helper uji, bukan aplikasi:** `tests/browser/uji-profil-impor-f2-android.mjs:95` (fungsi `bukaProfil`, line final dapat bergeser) sebelumnya mengetuk kartu lagi walau Profil sudah terbuka; pada fixture nama panjang penanda lama di bawah lipatan. Helper kini memverifikasi kepala Profil yang benar dengan whitespace dinormalisasi dan menerima tujuan yang sudah terbuka. Timeout tujuan tetap 8 detik. Kontrol berurutan dengan reseed/clear data: baseline + oracle asli **24/0**, baseline + helper navigasi baru **24/0**, final + helper baru **24/0**; kegagalan awal final 5/1 dan 18/1 tetap dicatat, bukan diklaim direproduksi di baseline. **19 baris assertion bisnis identik hash**, tidak mengganti syarat data/password/foto supaya lulus ([kontrol](audit-codex/profil-oracle-control.json)). Tidak ada perubahan binary, sehingga tidak memasangkan bukti hash lama dengan hasil koreksi aplikasi baru.

Matriks inset tambahan menggunakan `tests/android/oap_audit_native.py` dan frame sistem `dumpsys window`: API36 3 tombol+cutout terang **8/0**, gelap2× **7/0**; API35 gestur terang **8/0**, 3 tombol+cutout gelap2× **7/0**; API31 gestur terang **8/0**. Memeriksa kepala saat istirahat, label tab di atas bilah sistem, tindakan Ajukan izin di atas tab, kolom di atas IME, FAB hilang saat IME pada 1×, dan tindakan bottom sheet di dalam inset. Teks2× memakai alternatif tombol atas, bukan memaksa FAB. Foto/profil pada API35 **24/0**, cache Release tetap dilewati.

**F-3 — P2 / sedang, bukti Android12 belum lengkap (K-5).** Pada API31 3 tombol+cutout gelap2×, probe pertama berhenti **1/1** sebelum Perizinan. Tangkapan menunjukkan password masuk ke username dan aplikasi masih di layar Masuk; assertion awal yang memakai kata “Assalamu” bukan bukti login sah dalam kasus ini. Suite profil API31 kemudian **0/1**, dengan pesan `uiautomator: could not get idle state` dan input nama terpotong. Helper suite lama `tests/browser/uji-profil-impor-f2-android.mjs:32` dapat membaca XML sebelumnya ketika dump gagal; hasil tersebut **tidak dipakai sebagai keberhasilan aplikasi**. Tidak menyimpulkan bahwa R8 merusak input atau menyebutnya regresi terkonfirmasi.

Probe auditor baru diperketat: hapus snapshot XML sebelum dump, pastikan Beranda melalui aksesibilitas `Buka profil` (bukan judul layar Masuk), gulir agar password terjangkau dan sediakan input per karakter. Ini memperkuat oracle, bukan mengabaikan kegagalan autentikasi. Kontrol probe baru pada baseline rekonstruksi dan final masing-masing **0/1**, keduanya `dump gagal` sebelum bisa memvalidasi layout2×; kegagalan alat bukan lulus dan bukan bukti layout2×/crop Android12 benar. Tidak memasukkan perbaikan aplikasi atau menonaktifkan animasi/proteksi demi melewati probe. API31 gestur terang yang sudah lulus tidak menutup kasus gelap2× atau profil yang gagal. F-3 tetap terbuka sebagai kekurangan bukti pengujian; perlu pengujian terarah pada OS lama dengan tooling/perangkat yang menghasilkan input dan snapshot valid, termasuk picker/crop. Tidak mengklaim semua matriks minimum lulus.

**Batas oracle visual:** C menghitung node bernama dan dapat menghitung node tab/label; hasil 24/0 bukan bukti semua ubin menu terlihat sekaligus dalam satu gesture. Tangkapan final memang memperlihatkan kisi tiga kolom tetapi Perizinan lebih bawah. Saat IME aktif, konten atas ikut bergulir/autopan; pengukuran kolom di atas IME bukan klaim kepala fixed selalu terlihat. Tidak mengubah oracle agar menjadi lulus; tangkapan dan catatan batas di [visual-review.json](audit-codex/visual-review.json).

Probe auditor tambahan pada hash APK uji yang sama: cetak PDF/preview sistem, pembatalan dan chooser berbagi **7/0**; unduh PDF rapor lewat native FileSystem dan chooser **4/0**; screenshot/foreground/log pada BSA, kesehatan dan izin terlindungi **masing-masing 4/0**. Recents ketiganya diperiksa visual, **seluruh kartu aplikasi hitam tanpa isi** ([catatan/hash tangkapan](audit-codex/visual-review.json)); bukan sekadar pembacaan FLAG_SECURE. Preview cetak memuat dua halaman laporan sintetis, tanpa memilih printer atau menguji ukuran kertas fisik; chooser menampilkan file PDF, tanpa pengiriman ke tujuan.

Upgrade lokal **9/0** menggunakan **kedua APK buatan auditor sendiri**: rekonstruksi baseline D8 1.9.0 `e37d67edfc9e98a7e11dae798de7b10e01fc86b61017c264d3f79629d3e50754` → APK R8 audit `df8bc814…`, kunci debug kompatibel. Token SecureStore bertahan sesudah penggantian/cold start; logout/login baru, kembali dari latar dan cold start berikutnya lulus. Logcat upgrade 13.584 baris tanpa password/bearer mentah. Kedua versionCode lokal 1; ini replacement/update lokal kompatibel, **bukan** uji kenaikan nomor build atau upgrade APK Play App Signing. Baseline ini **rekonstruksi sumber 82f156a, arm64, loopback**, berbeda dari AAB produksi asli: D8/AGP 8.12.0, DEX 46.992.248 B ([analisis](audit-codex/android-analyze-baseline-reconstruction.json)).

Buffer events matriks utama mencakup **11:24:59–12:14:51 WIB**, 7.125 baris, **0 am_crash / 0 am_anr untuk paket aplikasi**. ANR input sekali yang dilaporkan implementator tidak direproduksi; ini tidak membuktikan ANR mustahil pada fisik/pre-launch. Tangkapan buffer API35/API31 serta kontrol juga tanpa am_crash/am_anr target ([hash, jendela dan hitungan](audit-codex/events.json)); capture dapat tumpang tindih dan tidak dijumlahkan sebagai kejadian unik. Semua data akun/santri/transaksi adalah fixture sintetis lokal.

**F-2 — P2 / sedang, smoke iOS belum lulus.** Build iOS Release independen berhasil (`Xcode 27.2`, build `27B52`, SDK/runtime simulator iOS 27.2, iPhone 17 Pro), executable SHA-256 `1204ce3ed3cc72a5bcb34bf1be040912bd11336f5e033807b50404abaab0a25d`, bundel Hermes `1790a7ddddbb7c216fb0d60b96d14d72b4267bfd02bbe4fc9f31579ef697e0a4`. Cold start tanpa Metro gagal: UIKit melaporkan **“UIScene life cycle is required for apps built with this SDK”**, lalu EXC_BREAKPOINT/SIGTRAP dan kembali ke SpringBoard. Lokasi manifest generated: `ios/alhasanApps/Info.plist` tanpa scene manifest; sumber tracked `app.json:10` (konfigurasi iOS) dan template SDK 57. Jangan menghitung build sukses sebagai smoke lulus. Kontrol baseline **82f156a / 1.9.0** dibangun ulang dari `npm ci`, Release arm64, SDK/runtime yang sama: build juga berhasil tetapi startup juga crash dengan **EXC_BREAKPOINT/SIGTRAP pada fungsi UIKit `___UIApplicationEvaluateRuntimeIssueForNoSceneLifecycleAdoption_block_invoke` yang sama**. [Perbandingan crash tersanitasi](audit-codex/ios-crash-control.json). AppDelegate/Podfile generated identik; Info.plist hanya berbeda versi; tambahan properti plugin sama dengan default efektif Podfile ([kontrol prebuild](audit-codex/ios-prebuild-control.json)). Dengan demikian kegagalan SDK 27.2 ini ada pada baseline, bukan regresi yang diperkenalkan paket R8 Android. Smoke iOS tetap **gagal/belum tervalidasi**, tidak diganti dengan binary lama atau bundel hibrida. Tidak memigrasikan lifecycle iOS atau menaikkan SDK di luar pekerjaan Android ini. Kewajiban scene pada aplikasi yang ditautkan ke SDK 27 sesuai [panduan UIKit resmi Apple](https://developer.apple.com/documentation/uikit/transitioning-to-the-uikit-scene-based-life-cycle).

## 6. Keamanan, versi, dan batas produksi

Diff paket tidak mengubah `src/`, API, payload, hak, database, skema, proteksi tangkapan, target SDK, atau kode transport aplikasi. `eas.json` tetap `appVersionSource: remote` dan `production.autoIncrement: true`; tidak ada versionCode/buildNumber tracked yang dipaksakan. Versi patch tetap **1.9.1**, tidak dinaikkan lagi oleh auditor. `eas build:version:get --platform android --profile production --json` read-only mengonfirmasi remote **versionCode 19**; auditor tidak mengubahnya dan tidak mengasumsikan nomor build berikut. Lock hanya menambah build-properties dan memperbarui schema-utils terkait; edit patch Expo/RN lokal pekerjaan lain tidak diadopsi.

Play Console diperiksa langsung: produksi/alpha **19 (1.9.0)**, internal **4 (1.0.0)**; daftar bundle terbaru tetap 19. Kedua peringatan GP-1/GP-2 masih melekat pada **rilis 19**, bukan hasil analisis 1.9.1. Tidak ada final 1.9.1 yang bisa dicocokkan dengan internal/pre-launch report. **Konfirmasi Play final belum tersedia.** Tidak mengklaim peringatan produksi hilang.

Perangkat fisik, Push nyata (registrasi FCM, ketukan sistem/cold start), Play App Signing/upgrade dari Play, binary distribusi EAS dengan environment produksi, dan UAT tidak dibuktikan oleh emulator, simulator, URI sintetis, atau upgrade kunci debug. Gerbang produksi V5/V6/V7 dan redesain terdahulu tetap mengikuti laporan masing-masing; audit ini tidak menutupnya.

## 7. Reproduksi dan langkah eksternal

Gunakan klon bersih branch fitur/handoff, fetch main untuk pemeriksa versi, `npm ci`, berkas ignored `expo-env.d.ts` berisi referensi `expo/types`, lalu tsc/lint/unit/versi. Prebuild Android `CI=1 EXPO_NO_DOTENV=1 npx expo prebuild --platform android --no-install`; build Release dengan JDK/SDK di §2, API HTTPS uji eksplisit, `NODE_ENV=production`, `--no-build-cache`. Jalankan `android --no-metrics analyze --format=json <AAB>` dan skrip ZIP/DEX di atas. Cocokkan DEX dan mapping ke metadata; jangan memasangkan mapping hash lama dengan binary baru.

Varian loopback hanya untuk fixture lokal sintetis. Perintah suite handoff `perintah-native.sh` diperiksa sebelum dipakai; pemeriksaan `run-as` cache pada Release dilewati secara eksplisit, bukan dihitung lulus. Perubahan locator lama dipertahankan hanya untuk penanda UI yang sudah ada sejak baseline; tidak mengubah assertion bisnis agar lulus. Ledger seluruh percobaan, termasuk gagal/lewati dan hash log, tersedia di [native-results.json](audit-codex/native-results.json). Bukti mentah, binary, mapping dan logcat privat berada di `/private/tmp/oap-codex-audit/` (lokasi sementara; arsipkan ke penyimpanan privat sebelum dibersihkan); hanya ringkasan tersanitasi dalam Git.

Langkah yang masih membutuhkan akses/keputusan eksternal: tutup kekurangan uji F-2/F-3 dengan toolchain/perangkat yang sesuai tanpa memperluas paket sepihak; selesaikan F-1 atau putuskan batasnya secara eksplisit; bangun calon distribusi dengan environment dan signing EAS yang benar serta versionCode lebih tinggi dari 19; catat hash baru dan jalankan ulang analisis serta pengujian relevan; validasi internal Play, metadata deobfuscation, GP-1/GP-2 dan pre-launch report atas **build final yang sama**; lakukan fisik/Push/upgrade Play dan gerbang rilis lama. Pemulihan menggunakan build perbaikan dengan versionCode lebih tinggi; mengganti AAB memakai versionCode sama atau menurunkannya bukan rollback yang tersedia. Semua keputusan produksi tetap Human Developer.

Perintah inti (dari root klon mobile bersih, sesudah toolchain/environment §2 siap):

```sh
npm ci --cache /private/tmp/oap-codex-audit/npm-cache
npx tsc --noEmit
npm run lint
node --experimental-strip-types --test tests/*.test.ts
CI=1 EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=https://api-uji.invalid/api/v1 npx expo prebuild --platform android --no-install
cd android
NODE_ENV=production EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=https://api-uji.invalid/api/v1 ./gradlew bundleRelease assembleRelease --no-build-cache --no-daemon --max-workers=4
```

Native memakai fixture/perintah `docs/optimasi-android-play/perintah-native.sh` di web. Probe baru: `DB_NAME=test_oap_v7_test python3 tests/android/oap_audit_native.py layout <output-privat>` setelah memasang APK uji, `adb reverse tcp:8971 tcp:8971`, dan server fixture lokal siap. Mode `documents` memakai fixture pengajian; `rapor` memakai fixture Tahap7; proteksi BSA/kesehatan/izin memakai fixture masing-masing dari runner handoff. Jangan mengarahkan runner fixture ke layanan produksi. Pengaturan `OAP_FONT_SCALE=2.0 OAP_DARK=1` dan overlay sistem digunakan untuk kasus gelap2×; input lambat `OAP_SLOW_INPUT=1` hanya pada kontrol API31 yang gagal dump. Semua ini pengujian transport/layout, tidak mengubah oracle data bisnis.

## 8. Handoff audit

Audit mobile **`e5a88c4e70cdf9344942f5b6943feb99bda39229`** telah dipush dari klon bersih ke `claude/optimasi-android-play`; isinya tiga berkas dokumentasi saja. Commit web yang memuat laporan ini dan bukti/uji pendukung adalah handoff audit web (SHA dilaporkan pada jawaban akhir dan log Git). Tidak mengubah kode aplikasi mobile, sehingga semua hash dan analisis binary di §2–§5 tetap terikat sumber handoff semula. Koreksi navigasi helper uji web dan probe baru tidak menghasilkan binary aplikasi baru.

Preservasi akhir **95/95**; checkout utama mobile tetap `82f156a` dengan dua edit paket lokal semula. Branch fitur kedua repo dipush; tidak merge, deploy, OTA atau publikasi. Proses API/Metro/emulator milik audit telah dihentikan, tanpa menghentikan proses pengguna/Claude. Handoff berhenti di sini dengan **F-1/F-2/F-3 terbuka** dan K-7 final belum tersedia, untuk keputusan Human Developer.

## Referensi resmi yang dibaca

- [Expo SDK 57 BuildProperties](https://docs.expo.dev/versions/v57.0.0/sdk/build-properties/): plugin CNG dan properti minify/shrink yang dipakai.
- [Android DEX code optimization](https://developer.android.com/google/play/vitals/code-optimization): ambang ukuran, metadata R8, perbedaan Configuration Analyzer dan skor final lokal.
- [android analyze](https://developer.android.com/tools/agents/android-cli/commands/analyze): analisis artefak aplikasi dan validasi checksum DEX.
- [R8 app optimization](https://developer.android.com/topic/performance/app-optimization/enable-app-optimization) dan [AGP 8.12](https://developer.android.com/build/releases/agp-8-12-0-release-notes): DSL sesuai toolchain, ProGuard optimize dan kompatibilitas.
- [Edge-to-edge](https://developer.android.com/develop/ui/views/layout/edge-to-edge) dan [perubahan Android 15](https://developer.android.com/about/versions/15/behavior-changes-15#edge-to-edge): insets, navigasi, dan API tampilan lama.
- Sumber tag resmi [RN 0.86.2](https://github.com/facebook/react-native/tree/v0.86.2/packages/react-native/ReactAndroid/src/main/java/com/facebook/react) / [0.86.3](https://github.com/facebook/react-native/tree/v0.86.3/packages/react-native/ReactAndroid/src/main/java/com/facebook/react), serta source JAR Google Maven AndroidX/Material versi persis di §4.
