# Audit ulang independen Codex — tindak lanjut optimasi Android Google Play

22 November 2026 · satu paket GP-1/GP-2 · Claude Code implementator, Codex auditor.

## Keputusan

**Paket belum selesai seluruhnya dan audit teknis lokal K-1…K-6 belum lulus.** GP-1 tetap memenuhi ketiga minimum secara lokal. Perbaikan startup iOS F-2 **diterima pada simulator Release iOS 27.2**. **F-1/P1 (residual GP-2) tetap terbuka**; **F-3/P2 (kelengkapan uji Android 12) belum ditutup**. K-7/hasil Play final dan kesiapan produksi belum terkonfirmasi. Tidak ada koreksi kode aplikasi oleh auditor.

Ini hasil audit baru atas tindak lanjut Claude, bukan penggantian bukti historis [audit pertama](AUDIT-CODEX.md). Kelulusan iOS tidak menutup GP-2, pengujian distribusi, fisik, Push, atau gerbang produksi workstream terdahulu.

| Kriteria | Hasil audit ulang |
| --- | --- |
| K-1 | Terpenuhi untuk sumber/artefak lokal dan atribusi metadata EAS; batas arsip sumber unggahan EAS tetap dicatat. Edit pekerjaan lain dipertahankan. |
| K-2 | Terpenuhi: rebuild R8 full mode dan resource shrinking STANDARD, mapping tersedia, tidak ada sakelar penonaktifan optimasi/blanket keep baru. |
| K-3 | Terpenuhi secara lokal pada AAB EAS 20 dan rebuild auditor: optimasi **80,18%**, obfuscation **80,68%**, shrinking **80,81%**; checksum **EXACT_MATCH**. |
| K-4 | **Belum terpenuhi:** DEX final tetap **20 instruksi/11 kelas**. Tidak ada penerimaan residual secara eksplisit dari Human Developer dalam perintah audit ini. |
| K-5 | **Belum terpenuhi sepenuhnya:** iOS F-2 ditutup pada simulator; F-3 Android 12 tetap terbuka. Rincian native dan batas di bawah. |
| K-6 | Patch tetap satu kali **1.9.0 → 1.9.1**, nomor build EAS remote, bukti/panduan dan audit tersedia; handoff pada branch fitur, tanpa perubahan bisnis/server. |
| K-7 | **Belum terkonfirmasi:** AAB EAS 1.9.1 (20) tersedia; saat Play Console diperiksa ulang, pencarian kode 20 memberikan **0 hasil**. Analisis/pre-launch Play untuk final tidak tersedia. |

## 1. Sumber, cakupan, dan preservasi

- Web: handoff `0ee83138893cd2882c10453a682683df658ed4db`, sesudah audit pertama `332f214`. Perubahannya hanya laporan, bukti dan AGENTS.
- Mobile: kode `3705c2f89297ea08098f1c4db18d6745d35e71aa`, handoff dokumen `7621a11614696e0e575bda3a4045f862cc55e46e`, sesudah `e5a88c4`. Seluruh diff kedua commit diperiksa: plugin UIScene, registrasinya, patch dependency SDK 57, empat uji unit, dan dokumen. **Tidak ada diff `src/`, API, skema, atau hak bisnis.**
- `git fetch` remote berhasil setelah pembatasan sandbox awal diatasi. `origin/main` web `ea1bdbfcac05dff3d2a5fefa6fc365c233c2505f`, mobile `231a47e` sudah memuat handoff Claude dan identik secara isi dengan ujung branch yang diaudit. Ini observasi Git, bukan persetujuan audit atau bukti deploy. Auditor tidak melakukan merge.
- Audit mobile memakai klon bersih yang sudah ada, `/private/tmp/oap-codex-audit/mobile`, di branch `claude/optimasi-android-play`; fast-forward ke handoff, lalu `npm ci` memakai lock ter-commit. Tidak membuat worktree. Checkout utama `~/alhasanApps` tetap `82f156a` beserta dua edit paket pekerjaan lain.
- Inventaris privat mencakup **100 berkas** pada dua paket lokal mobile dan lima direktori untracked web yang dilindungi. Pemeriksaan antara menunjukkan **100/100 cocok**; hasil akhir dicatat pada bukti preservasi. Tidak reset/stash/menimpa edit pengguna; binary, mapping, dump fixture, `.env`, credential dan log mentah tidak masuk Git.
- Proses Claude yang terlihat idle tidak dijalankan oleh auditor; tidak ada build implementator bersamaan pada checkout audit. Tidak memakai subagen.

## 2. Dependency dan konfigurasi

`npm ci --ignore-scripts` bersih (lifecycle install tidak dijalankan), TypeScript/lint exit 0, **128/0 unit**, `expo install --check` menyatakan dependency sesuai. Generasi native/codegen kemudian benar-benar berjalan pada prebuild, CocoaPods dan Gradle. Pemeriksaan versi terhadap `82f156a` lulus satu patch. Lock berisi Expo **57.0.33**, RN **0.86.3**, Router **57.0.31**, notifications **57.0.25**, image-picker **57.0.21**, expo-modules-core **57.0.26**, build-properties **57.0.23**. Inventaris **102 perubahan versi dependency transitif/langsung** ada pada bukti; ini bukan sekadar perubahan satu berkas Swift.

Plugin `with-ios-scene-lifecycle.js` diperiksa bersama implementasi Expo terpasang `ExpoAppSceneDelegate`/`SceneEventForwarder`: factory tetap dibuat AppDelegate, jendela dibuat dari UIWindowScene, Linking override dipertahankan, URL cold start disisipkan kembali ke launch options, dan pengiriman ulang Linking dicegah upstream. Prebuild iOS bersih dan prebuild ulang berhasil; **tepat satu referensi SceneDelegate dan satu entri Sources** pada target. Tidak mengadopsi SDK 58 atau patch `node_modules`.

Android tetap min/target/compile **24/36/36**, AGP **8.12.3**, R8 **8.12.22**, JDK Temurin **17.0.16**, Gradle **9.3.1**. Manifest APK lokal non-debuggable; tidak memuat `usesCleartextTraffic` atau `networkSecurityConfig`. Transport distribusi, proteksi layar, edge-to-edge, versi minimum server dan aturan bisnis tidak dilonggarkan.

## 3. GP-1: build dan analisis artefak

Auditor meminta metadata EAS secara baca-saja dan mengunduh ulang arsip build langsung. Build **`1d6ddb37-7b8a-41d0-ac5a-f8ae00c97ffc`**, production/STORE, SDK 57, **1.9.1 (20)**, FINISHED 22 November 08:07:52 UTC, menunjuk commit **`3705c2f`**. SHA-256 unduhan auditor cocok persis laporan Claude:

`6137013cb53851cd004d7f276d3dd574217165214951261bc91e150ba30610c1`

EAS memberi atribusi commit, bukan arsip/hash seluruh sumber unggahan atau bukti working tree unggahan bersih. Rebuild auditor dari checkout/lock bersih menjadi pembuktian independen sumber lokal. EAS memakai environment, versionCode, Firebase client configuration dan signing distribusinya sendiri; auditor tidak menyalin credential ke proyek lokal.

| Artefak | Identitas/hasil |
| --- | --- |
| AAB EAS 20, seluruh ABI | 70.285.517 B; 1 DEX **10.497.352 B**, DEX SHA `e4f4b45873d5c54acc3c53971c1571b2ecc950fe1256f992b497850d6da93dc8`. |
| AAB rebuild auditor, **arm64 saja** | SHA `ada2cd800e903adc9b0364da5417c3196aa74a145159f91ea2023c3322ee9d50`; 30.819.715 B. Ukuran bundle tidak dibandingkan langsung dengan bundle semua ABI. |
| DEX rebuild auditor | **10.497.352 B**, SHA `17c0fb858aa8ae9f20365591f3175aa1b428b569ab3b43fb635523a6b6d0ce85`, cocok hash kandidat lokal Claude. Berbeda dari DEX EAS; tidak dianggap binary yang sama. |
| Mapping | Mapping rebuild lokal **identik byte** dengan mapping AAB EAS 20. Hash lengkap dalam manifest artefak. |
| APK uji auditor | SHA `8414270e6f30fc2670917b2a6f0c70c394d49c58d128bbf1ebf8fbe884caaa9f`; seluruh DEX dan **24 `.so`** identik byte dengan APK lokal konfigurasi distribusi. |

Rebuild Android `bundleRelease assembleRelease -PreactNativeArchitectures=arm64-v8a --no-build-cache --no-daemon --max-workers=3` berhasil **4m38s, 827 task (129 dieksekusi/698 up-to-date)**, meneruskan keluaran build bersih auditor yang sempat dihentikan; bukan klaim semua task diulang atau seluruh ABI dibangun ulang. Dependency/cache dan keluaran sebelumnya berasal dari audit ini, bukan binary implementator. AAB EAS semua ABI dianalisis terpisah.

`android analyze --format=json` resmi menghasilkan angka sama untuk AAB EAS dan rebuild: **80,18/80,68/80,81%** (optimasi/obfuscation/shrinking), checksum **EXACT_MATCH**, full mode, resource optimization **STANDARD**. Ketiganya melampaui minimum 25% secara lokal. DEX turun **77,6615%** dari AAB produksi 19 yang sudah dicocokkan Play/EAS pada audit pertama (46.992.180 B). Tidak memakai pengecualian ambang ukuran, skor keep-rule analyzer, atau mengklaim skor Play final.

Konfigurasi R8 efektif **1.721 baris** tidak memuat `-dontoptimize`, `-dontobfuscate`, `-dontshrink`, `-ignorewarnings`, blanket `-dontwarn **` atau keep semua kelas. Aturan paket luas bawaan JNI/React/Reanimated/Worklets/notifications tetap ada; bukan klaim semua aturan sempit. Gradle generated dan metadata membuktikan minify/shrink berjalan.

APK uji memakai JS/API `http://127.0.0.1:8971/api/v1` dan XML yang hanya mengizinkan cleartext untuk **127.0.0.1**, dengan base-config menolak cleartext. Bukti perbandingan DEX/native dan URL bundel tersedia. Manifest generated dipulihkan sesudah build uji. AAB/APK konfigurasi distribusi sudah diarsipkan sebelum perubahan uji dan tidak dilonggarkan. Lokal menggunakan signer debug template serta versionCode **1**; bukan simulasi Play App Signing atau pengujian endpoint produksi.

## 4. F-1/P1 — GP-2 tetap terbuka

Pemindaian instruksi `dexdump -d` dan mapping build yang cocok mengonfirmasi **20 instruksi/11 kelas** pada AAB EAS 20 maupun rebuild auditor. Lokasi dependency ter-commit: mobile `package-lock.json:10102` (RN), `:10227` (screens), `:6226` (image-picker). Host tetap mencakup StatusBarModule, WindowUtilKt, FormSheetDialog/Material, ExpoCropImageActivity, splashscreen, Compose dan outline cutout. Pemetaan kondisi OS pada audit pertama tetap berlaku; cabang kompatibilitas lama tidak dihapus serampangan.

Auditor mengambil ulang `WindowUtil.kt` resmi RN **v0.86.3**, **v0.88.1** dan **main**. Dua yang terakhir memiliki hash sama `7a9d9861…807d` dan masih menyebut warna bilah/cutout. Auditor juga memindai AAB percobaan SDK 58 milik Claude secara independen: **15 instruksi/7 kelas**, cocok klaim laporan. Ini **analisis ulang artefak percobaan implementator, bukan rebuild SDK 58 oleh auditor**, dan tidak membuktikan tidak ada solusi pada seluruh versi/library yang mungkin.

Tidak ditemukan dependency/artefak yang terbukti menghapus residual pada paket ini. UI yang benar, perubahan source JS StatusBar, maupun berkurangnya jumlah pemanggil tidak menutup pemindaian. Opsi penerimaan residual, upgrade terpisah atau patch native tetap memerlukan keputusan Human Developer sesuai rencana; audit ini tidak menerima residual atas nama pengguna.

## 5. F-2 — iOS UIScene ditutup pada simulator

Build **Release arm64 baru** dari sumber/lock handoff, Xcode SDK/runtime **iOS 27.2**, iPhone 17 Pro simulator `D0478170…6919`, identitas tanda tangan **Sign to Run Locally**. API loopback dan fixture V7 baru; bukan binary Release lama yang diganti bundelnya. Executable SHA `72fcf3c4865b9993234c806cf204201aa5f058c219f887c1d450924ebd793906`, Hermes SHA `363c69f48bf26f92ccf947691cde936961e74b2c03b99b10e1099472a397f3a9`.

- Cold start tanpa Metro menampilkan layar Masuk; assertion UIKit yang menggagalkan startup audit pertama tidak terulang.
- Alur perizinan asli lulus: login, pencarian/keyboard/FAB, paginasi, formulir, geser-tepi, detail dan kembali Beranda.
- Empat peran (admin/pembimbing/murobi/orang tua), wali, notifikasi/detail/perangkat serta **kedua URI saat aplikasi mati** (`notifikasi`, `izin/laporan`) → kembali Beranda lulus.
- Empat variasi **gelap, AX-XL, AX-XXXL, gelap+AX-XXXL** lulus. Jumlah keseluruhan **12 alur asli Maestro**, bukan 12 assertion. Pengukuran FAB **4/0**, termasuk jarak 20 pt dari butir bilah tab. Inspeksi gambar AX-XXXL menunjukkan tombol membungkus dan dapat digulir; tidak memperluas klaim ke audit seluruh desain lama.

Kontrol tambahan foreground **2/0**: akun pembimbing/murobi baca-saja membuka cakupan riwayat kesehatan yang berhak (UI dan HTTP200), aplikasi dilatarbelakangkan dengan membuka Settings tanpa mengubah pengaturan, satu token fixture dicabut, lalu foreground menghasilkan HTTP401 dan layar Masuk; cakupan kesehatan lama hilang. Percobaan pendahuluan memakai DB yang sedang berganti dan penanda klinis yang tidak sesuai peran baca-saja; keduanya dikoreksi sebagai prasyarat uji, bukan regresi aplikasi atau kelulusan. Bukti ini bukan uji fisik/Push/Recents.

Satu peluncuran driver Maestro terputus (`Device became unreachable while processing terminateApp`); bukan dihitung lulus atau disembunyikan. Alur anak asli kemudian dijalankan dalam satu sesi XCTest untuk empat peran/wali/notifikasi/FAB, tanpa perubahan assertion; empat tema memakai runner asli. Seluruh hasil ulang di atas lulus. Fisik, Push nyata/cold start Push, universal link terverifikasi, Debug/dev client serta distribusi iOS belum diuji. URI sintetis tidak disebut Push.

## 6. Android dan F-3/P2

Emulator Android 12/API31 akhirnya berhasil boot (**23,3 detik**) setelah kompilasi besar selesai. Kegagalan boot yang dilaporkan implementator tidak dipakai sebagai alasan melewati seluruh pengujian OS lama.

**Koreksi terarah alat audit:** matcher Beranda pada `tests/android/oap_audit_native.py` memakai awalan `Buka profil`, sedangkan label sumber `src/components/profil-shared.tsx:107` adalah `<nama>. <status>. Buka profil`. XML/screenshot aktual membuktikan login normal sudah berhasil tetapi matcher lama menolaknya. Koreksi memakai akhiran tepat `. Buka profil`; kontrol XML Beranda aktual diterima dan XML layar Masuk baru **tetap ditolak**. Tidak kembali ke penanda “Assalamu” yang ada pada layar Masuk. Source label ini tidak berubah pada paket aplikasi. Hasil lama yang memakai matcher salah tidak dihitung sebagai bukti kegagalan aplikasi.

Helper profil Android juga kini menghapus `/sdcard/ui.xml` sebelum setiap dump dan menolak snapshot yang tidak dibuat ulang. Kontrol kegagalan idle terisolasi menunjukkan helper lama membaca satu node usang, helper baru menolak. **19 baris assertion bisnis profil identik SHA-256 `d266d29f…132`** sebelum/sesudah koreksi. Penguatan transport snapshot bukan perubahan syarat data, password, foto, atau hak.

| Android 12/API31 | Hasil |
| --- | --- |
| Gestur, gelap, teks 2×, matcher diperbaiki | **7/0**, termasuk login beridentitas, kepala/tab/tindakan, keyboard, bottom sheet dan log. |
| 3 tombol + cutout + gelap2×, baseline 1.9.0 / final 1.9.1, matcher sama | **0/1 · 0/1**; keduanya belum mencapai kolom username dengan input/snapshot valid. Tidak disebut layout lulus atau regresi aplikasi terbukti. |
| Profil/foto/crop pada final, font 1×, helper snapshot diperketat | **0/1**, `uiautomator: could not get idle state`; file XML lama sudah dihapus dan file baru tidak tersedia. Picker/crop belum diuji sampai selesai. |

Percobaan awal memakai matcher lama: final gelap2× **0/1** (Beranda tidak dikenali), baseline gelap2× **0/1** (dump gagal), final terang1× **0/1** (Beranda tidak dikenali). Kontrol matcher kemudian membuktikan false negative pada Beranda, bukan memperlakukan hasil-hasil awal itu sebagai lulus. Perbaikan matcher menutup kekeliruan alat, tetapi **tidak menutup F-3/P2** karena kasus 3 tombol/cutout dan foto Android12 tetap belum tervalidasi. Tidak mengubah kode aplikasi untuk menyiasati hasil tersebut.

### Android 16/API36

Rebuild Release arm64 auditor, bundel tertanam tanpa Metro, diuji pada API36. Hasil berikut memisahkan eksekusi awal dan pengulangan terkontrol; exit wrapper orkestrasi tidak dipakai sebagai kelulusan suite.

| Area | Bukti |
| --- | --- |
| Beranda/peran/tema/password wajib | Eksekusi utama mencapai **62/0** lalu dihentikan batas 600 detik (exit124); sembilan kasus yang belum tercakup dijalankan terpisah **9/0**. Total cakupan **71 kasus unik**, bukan klaim satu eksekusi penuh exit0. |
| Profil/password/foto/picker/crop | **24/0** dengan snapshot baru; satu inspeksi cache `run-as` dilewati karena Release non-debuggable. |
| Cetak/berbagi PDF laporan | **7/0**, dialog cetak dan chooser PDF nyata; dibatalkan tanpa memilih printer/tujuan berbagi. |
| BSA/kesehatan/izin wali | Masing-masing **4/0**: screenshot terproteksi, kembali dari latar dan log tanpa password/bearer. Inspeksi visual tiga tangkapan setelah App Switch menunjukkan hitam tanpa isi sensitif; BSA memperlihatkan kartu Recents hitam, dua lainnya tangkapan hitam penuh, sehingga tidak diklaim semua menampilkan panel Recents. |
| 3 tombol + cutout + gelap2× | Awal **0/1** karena fixture password telah diubah suite profil; reset terarah password fixture, pengulangan **7/0**. |
| Upgrade sesi 1.9.0 → 1.9.1 | Awal **3/3** tidak sah sebagai bukti regresi: matcher lama menerima layar Masuk berisian sebagai Beranda baseline. Setelah guard identitas/input diperkuat, kontrol **9/0**, termasuk bertahan setelah upgrade/cold start/latar, logout dan login ulang. APK lama adalah rekonstruksi auditor dengan signer debug/versionCode1, bukan upgrade Play. |
| Pembinaan/rapor | **17/0** pada suite native V2 Fase4. |
| Keamanan | **10/0**. |

Helper upgrade kini mensyaratkan kartu profil beridentitas, memeriksa username lengkap sebelum login, dan menghentikan upgrade jika baseline tidak terautentikasi. **11 label assertion asli tetap identik**. Helper `adbui.find` menolak rectangle kosong: kontrol terisolasi menunjukkan label tersembunyi `[0,0][0,0]` dahulu dipilih `(0,0)`, kini memilih kontrol terlihat atau meminta gulir. Tidak mengubah data/hak bisnis.

Pengajian awal **2/1** memakai fixture pertemuan yang sudah ditutup; fixture baru menghasilkan **12/1**, satu pengukuran tinggi tombol terpotong viewport/bilah Kirim (22 dp). Sumber tombol tetap `minHeight`44 dan tidak berubah dari baseline. Navigasi uji kini menggulir tombol utuh sebelum pengukuran; ambang **43,5 dp** dan **41 baris assertion** tidak berubah. Unduh rapor awal **2/1** juga dipertahankan pada ledger. Setelah helper rectangle kosong diperbaiki dan fixture T7 baru disiapkan, native **4/0**: unduhan aktif `/api/v1/v4/rapor/1/pdf/2` **HTTP200**, chooser menampilkan PDF, log bersih; tanpa memilih tujuan berbagi. Probe diagnostik langsung ke versi historis1 memberikan403 “Versi ini tidak tersedia untuk unduhan baru.” sesuai status versi, bukan bukti kegagalan unduh versi aktif. Kontrol baseline/final pengajian dicatat pada hasil akhir di bawah.

Kontrol pengajian dengan fixture terpisah yang baru dan kandidat/baseline terpasang eksplisit: **baseline1.9.0 13/0, final1.9.1 13/0**, termasuk tombol **64×44 dp**, simpan absensi, gelap dan gelap2×. Tidak ditemukan regresi ukuran tombol pada paket ini. Percobaan pasangan pertama gagal saat seed berulang berbenturan dengan penugasan Keamanan aktif; tidak mencapai assertion UI dan tidak dihitung sebagai kelulusan. Pengulangan memakai satu seed per DB baru.

### Android 15/API35

Kandidat Release yang sama dipasang pada emulator SDK35 terverifikasi. Gestur/terang/teks1× **8/0**; tiga tombol+cutout+gelap/teks2× **7/0**, termasuk header, tab, FAB, keyboard dan aksi bottom sheet di dalam inset serta log bersih. Ini pemeriksaan layout, bukan pengulangan seluruh suite modul/profil pada API35. Emulator/server audit dihentikan setelah pengujian.

## 7. Regresi web, alat dan batas

Ekspor Expo web baru berhasil. Probe asli lima area pada fixture baru: akun **8/0**, izin **10/0**, keuangan **7/0**, pembinaan **4/0**, kesehatan **6/0**, total **35/0**; tidak ada permintaan keluar localhost. Tidak mengubah oracle aplikasi.

Percobaan awal dipertahankan sebagai batas alat, tidak dicampur dengan hasil akhir: lokasi JDK awal salah; dua build native semua arsitektur bersamaan dihentikan karena membebani mesin lalu dilanjutkan terbatas arm64; setup fixture sandbox gagal pada `/dev/fd`; dua rantai setup yang sempat bertumpang tindih dihentikan dan fixture dibuat ulang tunggal; server statis awal belum memetakan rute dinamis; fixture audit lama menghasilkan 409; satu download urllib EAS 403 lalu curl berhasil dengan hash cocok. Pengulangan memakai fixture sintetis baru, server rute dinamis yang benar dan lifecycle proses server terisolasi. Tidak melonggarkan oracle, memakai XML lama sebagai hasil baru, atau menghitung exit wrapper sebagai assertion lulus.

Panduan build/pemulihan pada [rencana](rencana.md) dan [implementasi](IMPLEMENTASI.md) tetap berlaku: distribusi perbaikan membutuhkan versionCode remote lebih tinggi, mapping harus cocok artefak, dan artefak lokal/HTTP uji tidak diunggah ke Play. EAS build yang sah saat ini **20**, bukan artefak lokal versionCode 1. Tidak ada merge, deploy, migrasi produksi, upload Play, OTA, publikasi atau fase/PRD lain oleh auditor.

## 8. Bukti dan handoff

Bukti tersanitasi di `audit-ulang-codex/`: sumber/versi, perubahan dependency, manifest/hash artefak, analisis resmi kedua AAB, pemindaian GP-2, perbandingan varian uji, hasil native/web/unit dan preservasi. Binary, mapping 100 MB, dump fixture, tangkapan/log lengkap tetap privat di `/private/tmp/oap-codex-followup/`; tidak masuk Git. Pointer mobile ada pada direktori dokumentasi yang sama.

Reproduksi pemeriksaan sumber: di klon mobile bersih pada handoff, `npm ci --ignore-scripts`, `npx tsc --noEmit`, `npm run lint`, `node --experimental-strip-types --test tests/*.test.ts`, `npx expo install --check`, kemudian prebuild dan build Release. Perintah Gradle/analisis beserta identitas binary ada di §3; build iOS memakai konfigurasi Release simulator SDK27.2. Probe web memakai ekspor baru dan server yang memetakan `[id].html`, hanya DB sintetis terpisah.

Probe Android: jalankan API fixture loopback8971 sesuai DB setiap kasus, pasang APK uji dengan hash di §3, `adb reverse tcp:8971 tcp:8971`, lalu `DB_NAME=test_…_test python3 tests/android/oap_audit_native.py <mode> <direktori-privat>`; mode `documents`, `rapor`, `bsa`, `kesehatan`, `izin`, `layout`. Layout memakai `OAP_FONT_SCALE=1.0|2.0`, `OAP_DARK=0|1`, opsional transport `OAP_SLOW_INPUT=1`, dan overlay navigasi/cutout emulator yang dicatat. Upgrade memakai `oap_upgrade_sesi.py <APK-baseline> <APK-final> <direktori-privat>`. Suite JS native yang diubah memakai `RILIS=1 METRO=` dan seed baru yang cocok dengan DB. Jangan memakai fixture yang password/pertemuannya sudah dimutasi tanpa menyiapkan ulang prasyarat; jangan menyalin API HTTP uji ke artefak distribusi.

Commit dokumentasi audit mobile **`88f1968`** sudah didorong ke branch fitur. Commit/push audit hanya pada `claude/optimasi-android-play` kedua repo. SHA hasil dilaporkan melalui Git dan jawaban handoff. Berhenti setelah audit ini; keputusan penerimaan residual dan rilis tetap Human Developer.

## Referensi resmi

- [Expo BuildProperties SDK 57](https://docs.expo.dev/versions/v57.0.0/sdk/build-properties/).
- [Android analyze](https://developer.android.com/tools/agents/android-cli/commands/analyze) dan [DEX code optimization](https://developer.android.com/google/play/vitals/code-optimization).
- Source `ExpoAppSceneDelegate.swift`/`SceneEventForwarder.swift` pada paket Expo 57.0.33 yang terpasang dari lock; hasil kompilasi dan prebuild diuji langsung.
- [WindowUtil RN v0.86.3](https://github.com/facebook/react-native/blob/v0.86.3/packages/react-native/ReactAndroid/src/main/java/com/facebook/react/views/view/WindowUtil.kt), [v0.88.1](https://github.com/facebook/react-native/blob/v0.88.1/packages/react-native/ReactAndroid/src/main/java/com/facebook/react/views/view/WindowUtil.kt), dan [main](https://github.com/facebook/react-native/blob/main/packages/react-native/ReactAndroid/src/main/java/com/facebook/react/views/view/WindowUtil.kt), diambil ulang pada sesi audit ini.
