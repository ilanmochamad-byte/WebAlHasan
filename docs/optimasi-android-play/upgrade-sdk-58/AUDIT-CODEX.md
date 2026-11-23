# Audit Codex — upgrade Expo SDK 58 (1.10.0)

23 November 2026 · branch `claude/upgrade-expo-sdk-58` · Claude Code implementator, Codex auditor independen.

## Keputusan

**Upgrade ke AGP 9 terverifikasi pada rebuild independen. Paket Optimasi Android keseluruhan belum lulus penuh: F-1/P1 GP-2 tetap 15 instruksi pada 7 kelas, dan batas F-3/P2 Android 12 cutout belum tertutup.** Auditor menutup satu regresi baru penulisan berkas asinkron (F-4/P2). Konfirmasi EAS/Play/pre-launch 1.10.0 dan kesiapan produksi belum tersedia; audit ini tidak menerima residual atas nama Human Developer.

## Sumber, versi dan preservasi

Web baseline `6b0fe24`, handoff `30f393180aa9b9f424a1a961879c88cf235d5010`; mobile baseline `6328517`, handoff `1a915bdca18b3c588483a9019df34f95889a55d6`. Koreksi kode auditor `0e002d5` di klon bersih `/private/tmp/sdk58-codex-audit/mobile`. Tidak worktree/subagen; checkout utama mobile tetap pada branch/HEAD semula dan dua edit paket pekerjaan lain tidak diambil.

Satu kenaikan minor **1.9.2 → 1.10.0** sesuai keputusan upgrade platform. Audit tidak menaikkan versi lagi. EAS tetap `appVersionSource=remote`, `production.autoIncrement=true`; versionCode lokal 1 bukan nomor distribusi EAS. Expo 58.0.26, RN 0.88.1, React 19.3.0, AGP 9.2.1, R8 9.2.14, Gradle 9.4.1, Temurin JDK 17.0.16, Node 26.7.0. Android min/compile/target SDK **24/37/36**; iOS simulator Xcode/SDK 27.2, minimum iOS 16.4 (sama dengan build SDK 57 audit sebelumnya).

API/backend, hak, skema dan aturan bisnis tidak berubah. Diff auditor pada runtime hanya menunggu penulisan dua dokumen, dengan uji regresi. Web hanya helper pengujian, bukti, dan dokumentasi.

## F-4/P2 — ditutup: tunggu File.write sebelum membagikan dokumen

SDK 58 mengubah `File.write()` menjadi Promise. Handoff tidak menyesuaikan dua pemanggil:

- `src/rapor/pdf.ts:20`: dialog PDF rapor dapat dibuka sebelum penulisan selesai; akses yang dicabut selama penulisan tidak ditunggu sebelum share, dan cleanup dapat mendahului selesainya write.
- `src/report/izin-report-document.ts:104`: CSV izin dapat dibagikan saat masih kosong/belum lengkap; kegagalan write tidak diteruskan melalui Promise fungsi ekspor.

Keduanya kini memakai `await`. Tes `tests/async-document-write.test.ts` menjalankan fungsi ekspor asli dengan kontrak native asinkron dan penulisan yang ditahan secara deterministik: urutan tulis→share, penolakan write tanpa share, dan pencabutan akses saat penulisan rapor dengan cleanup. **Kontrol handoff 0 lulus/5 gagal → koreksi 5/0**, test file identik SHA pada kontrol dan final. Semua pemanggilan `.write/.copy/.move/.readBytes/.writeBytes` pada `src/` ditinjau; hanya dua pemanggilan write ini ditemukan. Kontrak dikonfirmasi di tipe terpasang dan [changelog Expo SDK 58](https://expo.dev/changelog/sdk-58).

## Rebuild dan analisis binary

`npm ci --ignore-scripts` dari lock ter-commit; tipe Expo standar disediakan pada `expo-env.d.ts` yang diabaikan Git. Prebuild Android/iOS sukses; tepat satu SceneDelegate. Plugin AGP menghasilkan classpath tanpa versi, mengambil 9.2.1 dari katalog RN. R8 memakai preset optimize, minify dan shrink aktif; properti optimized resource shrinking tepat satu. Konfigurasi efektif 1.686 baris tanpa `-dontoptimize/-dontobfuscate/-dontshrink/-ignorewarnings`, blanket keep atau blanket dontwarn.

Rebuild handoff dan rebuild setelah koreksi diarsipkan terpisah. AAB arm64 auditor bukan binary empat ABI implementator atau distribusi EAS. Final memakai URL tak aktif `https://api-uji.invalid/api/v1`.

| Ukuran/metadata final auditor | Hasil |
| --- | --- |
| AAB SHA-256 | `1700960db1519b36e3005aba22bb591377af50e06eca27c2c5a28303f7d8ce41` |
| DEX tak terkompresi | **10.536.500 B**, satu DEX (baseline 1.9.2: 10.104.488 B) |
| Optimasi / obfuscation / shrinking | **76,89 / 77,46 / 77,68%**, semuanya ≥25% lokal |
| R8 | **9.2.14**, full mode, checksum `EXACT_MATCH` |
| Resource shrinking | **OPTIMIZED** |
| GP-2 | **15 instruksi / 7 kelas** (baseline 18/10) |
| Mapping SHA-256 | `5a2793bf74b18e1a93fe446e71a2b8fc0dc598062ff72a6c3fd7d45e136483ae` |

DEX dan mapping rebuild auditor cocok dengan handoff implementator. Bundel Hermes final berbeda dari handoff dan memuat koreksi auditor. Koreksi JS tidak mengubah DEX. Perbandingan subset 116 resource gambar/aset aplikasi terhadap baseline: tidak ada yang hilang/berubah. Angka ini bukan klaim seluruh resource dependency identik setelah upgrade SDK.

APK final non-debuggable, target SDK 36, tanpa `usesCleartextTraffic`/`networkSecurityConfig`. APK uji terpisah SHA `53f262fa1912c2f13cb819ca217e7a6e82aac4f42b6c71e7610cba477a10d264` hanya membolehkan HTTP **127.0.0.1**, dengan URL fixture lokal. **DEX APK uji identik dengan APK final**. Tidak mengunggah varian ini atau signer debug/versionCode1 ke Play.

## GP-2 tetap terbuka

Scan DEX aktual final mengonfirmasi seluruh 15/7: RN `WindowUtilKt`, outline AndroidX Core, AndroidX SplashScreen, dua host Compose, screens `FormSheetDialog`, dan expo-image-picker `ExpoCropImageActivity`. Mapping/simbol lengkap di `audit-codex/final-dex-scan.json`; hasil cocok dengan implementator. RN 0.88.1 masih mengatur warna bilah pada enableEdgeToEdge; library lain masih memanggil API warna/cutout. Keberadaan guard versi atau UI yang tampak benar tidak menghapus instruksi dari pemindaian. Tidak menambah patch library spekulatif atau menyatakan peringatan Play telah hilang.

## Pengujian independen

TypeScript/lint exit0, Expo dependency check lulus, Expo Doctor **20/20**, unit **135/0** (handoff 130/0), versi minor lulus. Expo web final **35/0**: akun8, izin10, keuangan7, pembinaan4, kesehatan6.

| Lingkungan / cakupan | Hasil final independen |
| --- | --- |
| Android 16 Release: tujuh peran, Beranda/Profil, ←, URI | **44/0** |
| Android 16: gelap+teks 2× dan password sementara | **9/0** |
| Android 16: kalender izin | **10/0** |
| Android 16: profil, foto/picker/crop/unggah, password, teks 1×/2× | **24/0**, satu inspeksi cache privat dilewati |
| Android 16: cetak PDF dan chooser berbagi sistem | **7/0** |
| Android 16: Keuangan / Kesehatan / Izin terlindungi | **4/0 masing-masing** |
| Android 16: tiga tombol+cutout+gelap+2×, keyboard dan lembar bawah | **7/0** |
| Android 16: unduh PDF rapor + chooser native (File.write baru) | **4/0** |
| Android 16: Pembinaan/Rapor, URI dingin, gelap+2× | **17/0** |
| Android 16: Keamanan, guru berpenugasan, admin ditolak | **10/0** |
| Android 16: upgrade 1.9.2→1.10.0, SecureStore/cold start/background/logout, kontrol negatif Masuk | **10/0** |
| Android 15: tiga tombol+cutout+gelap+2×, keyboard/dialog | **7/0** |
| Android 15: kalender | **10/0** |
| Android 12 tanpa cutout emulasi: kalender / upgrade lokal / pengajian-absensi | **10/0 · 9/0 · 13/0** |
| iOS 27.2 Release arm64 simulator, tanpa Metro | **8 alur Maestro lulus** (7 izin/notifikasi/FAB + 1 Keuangan admin/wali), bukan hitungan assertion individual |

Tangkapan/Recents Android Keuangan, Kesehatan dan Izin tidak menampilkan isi terlindungi. BSA menampilkan kartu Recents hitam dengan kontrol sistem; raster Kesehatan/Izin hitam dan hierarchy Recents segar memuat Screenshot/Select. Ini inspeksi snapshot emulator, bukan rekaman transisi perangkat fisik atau bukti iOS Recents. Cetak/bagikan dibatalkan pada dialog sistem; tidak memilih printer atau penerima.

Uji pembaruan menggunakan **dua APK uji dengan kunci debug template yang sama**, versionCode lokal1, dan data aplikasi dipertahankan lewat install-r. Ini membuktikan jalur SecureStore lokal, **bukan** upgrade distribusi Play/App Signing. Metadata APK terpasang cocok dengan hash uji auditor.

**Android 12 profil asli tetap 9/2**, tidak disulap menjadi lulus: label target Kelas/Kamar 2× berada di luar viewport, dan matcher Photo Picker tidak menemukan label pada fallback DocumentsUI. Kontrol lima assertion kartu 2× pada baseline 1.9.2 dan final 1.10.0 sama-sama **4/1**, pada label yang sama. Probe terarah memilih gambar fixture pada DocumentsUI, membuka CROP, lalu mengunggah: action OPEN_DOCUMENT dan package DocumentsUI terlihat di log, tombol CROP terbaca, pesan sukses tampil dan DB foto=1/versi1. Android [mendokumentasikan fallback ACTION_OPEN_DOCUMENT](https://developer.android.com/training/data-storage/shared/photo-picker); pemeriksaan ini tidak mengganti klaim suite asli menjadi 24/0. Bukti [kontrol](audit-codex/profile31-controls.json), [fallback](audit-codex/photo31-fallback.json) dan gambar sintetis ada di folder bukti.

Semua hasil final dan percobaan awal dipisahkan di [native-results.json](audit-codex/native-results.json). Tidak dijumlahkan sebagai pengujian unik; beberapa suite memeriksa login yang sama dalam konteks berbeda.

## Percobaan awal dan batas pembuktian

- Percobaan web sebelum ekspor ulang selesai membaca 404; server kemudian dijalankan ulang setelah ekspor lengkap. Salinan fixture lama juga memuat Probe TL/tagihan dari pengujian terdahulu (409). Pengulangan akun/izin/kesehatan/pembinaan memakai fixture baru; keuangan memakai fixture tarif lengkap dan periode sintetis baru 2025-02, seluruh assertion bisnis tetap. Fixture baru tanpa tarif tidak memenuhi prasyarat preview. Kegagalan awal dan log disimpan, bukan dihitung lulus.
- Menumpuk seed Tahap7/jadwal/Keamanan pada satu fixture menyebabkan penolakan prasyarat/penugasan ganda. Rapor dan jadwal kemudian memakai salinan database fixture khusus, tidak mengubah layanan agar seed lolos.
- Saat emulator kedua dinyalakan, wrapper adb auditor yang belum mengikat serial gagal memilih perangkat. Serial kemudian ditetapkan eksplisit; assertion tidak diubah. Android 16 awal tertutup dialog ANR Digital Wellbeing emulator. Menutup aplikasi sistem tersebut memulihkan pengujian; ini bukan crash aplikasi.
- iOS unsigned+ad-hoc biasa berhasil membuka Masuk tetapi SecureStore menolak Keychain karena entitlement lokal tidak tersedia. Menandatangani setelah link saja juga ditolak simulator. Rebuild Xcode dengan entitlement Keychain sintetis dan signer ad-hoc lokal digunakan untuk pengulangan; tidak mengubah app.json atau entitlement distribusi.

Android 12 dengan tiga tombol+cutout emulasi+2× masih tertahan crash `com.android.systemui` (`ScreenDecorations` / `PrivacyDotViewController.initialize`, `bottomLeft` null). Kalender/foto dilanjutkan setelah overlay dimatikan, bukan dianggap menguji cutout.

- Profil Android 16 pertama **0/1** karena sesi kalender masih aktif. Setelah pm-clear khusus paket emulator, suite asli **24/0**. Cache ImagePicker privat tidak diperiksa karena run-as tidak tersedia pada APK non-debuggable; satu langkah tetap dilewati.
- Upgrade Android 16 awal **6/3** pada penanda identitas yang berada di luar viewport: screenshot menunjukkan Beranda sudah terautentikasi. Helper kini menggulir ke awal sebelum memeriksa **kartu identitas yang sama**, tetap memakai XML segar, menolak Masuk pada kontrol negatif, dan memeriksa versi lama/baru secara eksplisit. Default versi audit awal tetap. Putaran akhir Android 16 **10/0**, Android 12 **9/0** (sebelum penambahan satu kontrol negatif). Ini koreksi navigasi alat, tanpa koreksi sesi aplikasi.
- Pengajian awal memakai manifest nama/tanggal salinan fixture yang tidak cocok dengan DB (2236EA vs505822, kemarin vs hari audit), lalu jadwal lama ternyata sudah mempunyai pertemuan Selesai. ID/nama dicocokkan ulang; jadwal lama dinonaktifkan **hanya dalam DB salinan**, riwayatnya dipertahankan, dan jadwal aktif baru 107–109 dibuat melalui ScheduleService dengan HH:MM. Suite asli pada fixture tersebut **13/0** di Android 12; kasus awal Android 16/Android 12 tetap disimpan sebagai kegagalan prasyarat, tidak dihitung lulus. Tidak mengubah assertion atau layanan agar lolos.

Perangkat fisik, Push nyata/ketukan/cold start Push, EAS/Play/pre-launch 1.10.0, upgrade dengan Play App Signing, iOS Debug/distribusi, MySQL/cPanel/UAT serta gerbang lama tidak diklaim terverifikasi.

## Kriteria dan handoff

| Kriteria | Keputusan |
| --- | --- |
| K-1 | Sumber/lock/hash/toolchain dan preservasi terverifikasi; rebuild lokal arm64 dibedakan dari artefak distribusi. **100/100** berkas kontrol pekerjaan lain tetap identik. |
| K-2 | R8/full mode, shrink/optimized resource shrinking, mapping dan prebuild idempoten terverifikasi. Permission APK sama dengan baseline; tidak ada penambahan/pengurangan uses-permission. |
| K-3 | Ketiga metrik lokal terukur ≥25%, checksum EXACT_MATCH. Ini bukan skor server Play 1.10.0. |
| K-4 | **Belum penuh: F-1/P1 tetap 15/7**, keputusan penerimaan residual tidak diambil auditor. |
| K-5 | Regresi di atas dan F-4 lulus setelah koreksi. **Belum penuh: F-3/P2 konfigurasi Android 12 cutout + 3 tombol + 2× belum tervalidasi**; profil Android 12 asli 9/2 dan kontrol baseline dijelaskan terbuka. Fisik/Push/upgrade Play/iOS distribusi belum diklaim. |
| K-6 | Satu kenaikan minor platform sesuai keputusan O-2; panduan reproduksi/pemulihan, koreksi, bukti dan handoff pada branch fitur. Audit tidak menaikkan versi lagi. |
| K-7 | **Belum tersedia:** EAS/analisis Play/pre-launch 1.10.0 dan kesiapan produksi final. |

Tiga status: implementasi butir **AGP 9 selesai**, paket GP-1+GP-2 **belum selesai penuh**; audit teknis lokal keseluruhan **belum lulus penuh**; konfirmasi Play/kesiapan produksi **belum terkonfirmasi**. Gerbang lama V5/V6/V7/Keamanan tetap mengikuti keputusan masing-masing.

Reproduksi: clone sumber handoff/koreksi, `npm ci --ignore-scripts`, sediakan referensi tipe Expo, `npx tsc --noEmit`, `npm run lint`, `node --experimental-strip-types --test tests/*.test.ts`, `npm run versi:periksa -- --dasar 6328517`; prebuild selesai dahulu, baru jalankan Gradle Release. Jangan menjalankan prebuild saat Gradle bekerja. Pakai JDK17/SDK Android lokal dan `EXPO_PUBLIC_API_BASE_URL=https://api-uji.invalid/api/v1` untuk distribusi rekonstruksi.

Bukti gambar Android 12 seluruhnya sintetis: [System UI cutout](audit-codex/android12-systemui-cutout.png), [DocumentsUI](audit-codex/android12-documents-picker.png), [crop](audit-codex/android12-crop.png), [kartu SDK 57](audit-codex/android12-sdk57-profile-2x.png), [kartu SDK 58](audit-codex/android12-sdk58-profile-2x.png).

Binary/mapping/log penuh tetap privat `/private/tmp/sdk58-codex-audit/`; bukti tersanitasi dan hash di `audit-codex/`. Tidak merge/deploy/OTA/unggah Play/publikasi atau mulai fase lain. Pemulihan distribusi tetap melalui build perbaikan dengan nomor EAS lebih tinggi, bukan mengganti AAB dengan versionCode yang sama. Referensi [R8/optimized shrinking](https://developer.android.com/topic/performance/app-optimization/enable-app-optimization) dan [BuildProperties SDK 58](https://docs.expo.dev/versions/v58.0.0/sdk/build-properties/).
