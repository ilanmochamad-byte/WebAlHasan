# Audit independen Codex — PRD V5 Fase 4

**24 Oktober 2026 · BELUM LULUS seluruh kriteria Fase 4.** Koreksi terarah dan pengujian lokal dicatat di bawah. Ketukan Push provider foreground/background/cold start dan runtime iOS dengan kode/native terbaru belum terbukti. Tidak ada izin Fase 5, merge, deploy, migrasi produksi, atau rilis aplikasi.

## Baseline dan cakupan

- Web: seluruh commit fase `8f1282c` (API, pemilih per kelas, pengujian) dan `eb3b62c` (handoff/bukti) di atas audit Fase 3 `ab2b952`; remote fitur sebelum audit `eb3b62cdfec3ca2cc4ddd163363796f8c023b071`, main `b91d5b89521166dfd7a6a30367ea0c46016ddcbf`. Isi main identik dengan baseline audit Fase 3. Cabang kerja `claude/prd-v5-fase-4`.
- Mobile: seluruh commit `38e1850f8dc7c1ef19cb6f965aa2f7c7d0b1a1a0` di atas **`ee6621a420c23605be57427e0353df4814297b80`**, sesuai keputusan Human Developer. Remote main `7ac224e1c5cd2db36deffe25715aa63aecfc78c5` belum memuat audit V4 tersebut. Ancestor dan diff diperiksa. Koreksi deep-link V4 dan tipe snapshot opsional tetap ada; parser tujuan V4 hanya bertambah jenis kesehatan. Tidak ada bukti rilis/deploy V4 baru. Audit V4 lama tetap mempunyai batas perangkat fisik, Push, dan produksi.
- Dibaca AGENTS kedua repo, PRD V5, audit/handoff Fase 3, status dan handoff Fase 4, kontrak `docs/api-v1.md`, seluruh perubahan kode fase kedua repo, suite/pembantu pengujian, serta audit/status V4. Peran Codex auditor; Human Developer mengonfirmasi semua sesi Claude idle sebelum perubahan kode. Tidak ada subagen/worktree atau perubahan direktori pekerjaan lain.
- Tambahan server hanya endpoint penugasan admin melalui `PenugasanService`, pemilih kelas, kunci `kelas` pada baris santri, pengelompokan web dan pemetaan exception penugasan. DTO klinis/ringkasan dan aturan layanan Fase 1–3 tetap; tidak ada perubahan `app/Auth`, `app/Penugasan`, `app/V4`, migrasi atau skema pada fase ini. Koreksi auditor tidak mengubah kode backend.
- Seluruh DB fixture sintetis, melalui rantai skrip resmi: `test_codex_v5_f4_http_test`, `..._app_test`, `..._regresi_test`, `..._probe_test`, `..._http2_test`, `..._http3_test`. Tidak membuka database produksi atau berkas pribadi. Provider Push/WhatsApp dalam regresi selalu palsu.

## Temuan, koreksi dan kontrol

| Temuan | Bukti independen | Koreksi dan status |
| --- | --- | --- |
| F-1 · P1 · Draf lama diam-diam memakai versi baru setelah kembali dari latar | Draf koreksi versi 1 dipertahankan; petugas lain menyimpan versi 2 saat aplikasi di latar. Ketika kembali, aplikasi mengirim draf lama dengan `versi=2`, menerima 200, dan menimpa tindakan petugas lain. Bentuk cacat yang sama memengaruhi hasil rujukan dan revisi ringkasan/penarikan. | Ketiga formulir memegang versi/revisi asal draf sampai dibersihkan secara eksplisit. Pemeriksaan akses tetap dimuat ulang saat fokus/aktif. Memuat ulang membuang draf dan versi bersama. Probe final **tanpa mengubah asersi pada pembanding**: kode implementator **10 lulus/8 gagal**, koreksi **19/0** (satu asersi tambahan dijalankan hanya bila konflik sudah ditolak). Ditutup lokal. |
| F-2 · P2 · Cuplikan pengalih aplikasi Android menyimpan isi kesehatan (T-1 implementator) | Auditor mereproduksi cuplikan berisi nama santri dan ringkasan sintetis, meskipun `AppState` kemudian sudah membuang respons. `android-recents-sebelum.png`. | Tambah `expo-screen-capture ~57.0.4` (SDK 57, lockfile aditif), gate navigator kesehatan sebelum layar/data dimuat, dan pertahankan perlindungan native sepanjang navigator kesehatan terpasang. Android memakai `FLAG_SECURE`; iOS juga meminta perlindungan pengalih aplikasi. Kegagalan pemuatan/perlindungan menutup layar, tanpa memuat data. Build native baru diperlukan. Status Android dan batas iOS dijelaskan di bagian perangkat. |
| B-1 · Bukti belum lengkap · iOS terbaru dan ketukan Push | Build iOS terbaru belum berhasil; binary iOS lama tidak berisi modul privasi baru. URI/InApp bukan ketukan Push dari provider. Tidak ada perangkat fisik. | **Terbuka dan menghalangi putusan lulus penuh.** Selesaikan build/runtime iOS, kemudian buktikan ketukan notifikasi, login ulang/akses berubah, dan cold start pada build yang sesuai. Jangan mengganti bukti ini dengan unit parser, ekspor Hermes, atau hasil implementator pada binary lama. |

Gate privasi membatasi tangkapan/rekaman layar selama navigator kesehatan masih di tumpukan; modul lain tidak memakai gate ini. Penutup JS tetap ada. Isi klinis tetap hanya di memori layar; tidak ditambahkan penyimpanan offline, cache disk, analytics, atau logging isi.

Dokumentasi SDK dibaca sebelum perubahan mobile: [Expo SDK 57](https://docs.expo.dev/versions/v57.0.0/) dan [ScreenCapture SDK 57](https://docs.expo.dev/versions/v57.0.0/sdk/screen-capture/). Tidak menaikkan Expo/React Native, versi aplikasi, atau konfigurasi EAS.

## Bukti pengujian auditor

Seluruh berkas tabel di bawah berada di `bukti/audit-codex/`, kecuali bukti kompilasi/unit mobile yang berada di repo mobile. Bukti implementator tidak dihitung sebagai eksekusi auditor.

| Rangkaian | Hasil | Berkas |
| --- | --- | --- |
| HTTP Fase 4: admin penugasan, peran ditolak, token lama, kelas/optgroup, kontrak/cache/log | **43/0** | `http.txt` |
| Expo web aplikasi + API/DB nyata, sembilan akun, 375 px: data kunjungan/obat/rujukan/koreksi/ringkasan identik web, ketukan ganda/jawaban hilang/replay, konflik 409, peran, sesi/token lama, relasi dan penugasan, storage/konsol | Awal **78/0** (tanpa opsi log PHP); akhir **79/0** (dengan log PHP) | `aplikasi-expo-web.txt`, `aplikasi-final.txt` |
| Probe auditor tiga formulir setelah latar; pembaca murobi/pembimbing positif dan pencabutan pada token lama | Kontrol implementator **10/8**, akhir **19/0** | `probe-kontrol-final.txt`, `probe-final.txt`; sumber `tests/browser/uji-v5-fase4-audit.mjs` |
| Fase 2 integrasi/konkurensi dan probe auditor lama | **134/0**, **28/0** | `fase2-integrasi.txt`, `fase2-probe.txt` |
| Fase 3 integrasi/konkurensi dan probe auditor lama, provider palsu | **82/0**, **26/0** pada DB segar | `fase3-integrasi.txt`, `fase3-probe-bersih.txt` |
| HTTP Fase 2: web/API lima peran, draf, koreksi, pencabutan, paritas | **87/0** | `fase2-http.txt` |
| HTTP Fase 3: web/API publikasi, wali, notifikasi, pencabutan | **70/0** | `fase3-http.txt` |
| Fondasi penugasan | **161/0** | `penugasan.txt` |
| Kontrak HTTP V4 yang dikonsumsi baseline aplikasi: envelope, daftar/detail wali, allowlist, no-store, SHA-256 PDF, wali lain ditolak | **12/0** | `v4-kontrak.txt`; sumber `tests/v5_phase4_v4_contract.mjs` |
| Android emulator Debug baru: mutasi petugas; sesi/tautan/wali/log/cache; probe lima peran/revokasi/FLAG_SECURE | **5/0**, **13/0**, **10/0** | `android-final.txt`, `android-sesi-final.txt`, `android-peran.txt` |
| Statis V5 Fase 1/2/3, oracle tidak dilonggarkan | **44/4**, **62/1**, **70/1**, semuanya kegagalan historis yang dijelaskan handoff | `statis-fase1.txt`, `statis-fase2.txt`, `statis-fase3.txt` |
| Mobile TypeScript, lint, unit (termasuk parser notifikasi/deep-link V4 lama) | exit 0, exit 0, **28/0** | mobile `docs/phase-v5-4/mobile-*-audit.txt` |
| Ekspor Hermes Android/iOS dari salinan tanpa `.env.local` | Kedua bundel berhasil; bukan build native/runtime | Ringkasan mobile `native-audit.txt` |

Oracle historis: Fase 1 masih menuntut 024 terakhir, tidak ada koreksi, hanya Tercatat, dan tidak ada tombol koreksi; Fase 2 menuntut 025 terakhir; Fase 3 melarang perubahan layanan/repository sejak baseline Fase 2, sedangkan permintaan pemilih per kelas Fase 4 memang mengubah keduanya. Tidak ada upaya mengubah oracle agar seluruh suite hijau. Seluruh 33 statis, migrasi/rollback, browser web Fase 1–3, dan regresi V1–V4 lengkap **tidak diulang seluruhnya** dalam audit ini; bagian yang dipilih di atas sesuai perubahan aditif dan koreksi mobile. Baseline V4 diverifikasi lewat ancestry (web `0cf77fc`, mobile `ee6621a`), audit sebelumnya, diff, regresi unit, dan 12 pemeriksaan HTTP kontrak lama termasuk hash PDF; bukan audit ulang rilis V4.

## Perangkat: hasil wajib dipisahkan

| Lingkungan auditor | Yang benar-benar dibuktikan | Batas |
| --- | --- | --- |
| Chromium/Playwright, Expo web dev server, 375×812 | Alur aplikasi seluruh lima peran, paritas terhadap web/API, duplikasi, konflik, pencabutan, storage/konsol; probe latar menggunakan event Page Visibility yang dipakai AppState web | **Browser, bukan native/perangkat fisik.** Ukuran lain fase sebelumnya tidak disebut sebagai pengujian ulang fase ini. |
| Android Emulator Medium_Phone, Android 16, sdk_gphone16k_arm64 | Baseline implementator: native petugas 5/0 dan sesi/wali/log/cache 13/0, direproduksi auditor; cuplikan bocor dikonfirmasi. Dev client Debug baru dibangun lokal dengan modul privasi: **5/0** alur petugas, **13/0** sesi/wali/log/cache, **10/0** probe lima peran. Admin membaca detail/riwayat akses; petugas menulis; pembimbing/murobi terkait membaca lalu kehilangan isi setelah penugasan dicabut; wali ditolak pada URI klinis. Cuplikan pengalih kosong dan `SECURE` aktif. Log 106.546 baris serta 88 berkas aplikasi tidak memuat penanda isi/nama/credential/token yang diuji. Sumber `tests/android/v5_fase4_audit.py`; dua skrip lama hanya mendapat parameter DB/guard error dan penantian kesiapan layar. | **Emulator, bukan fisik.** URI dan InApp tidak dihitung sebagai Push provider. Tangkapan/rekaman kesehatan kini diblokir di build baru. |
| iOS Simulator iPhone 17 Pro, iOS 26.2, UDID 05999342-AE62-44C4-B0E1-1E8504CF8512 | Binary dev client lama dapat memuat layar masuk melalui Metro. Percobaan runner login/gate gagal di penantian Beranda/keyboard; tidak mengamati detail kesehatan atau gate pada iOS. Build dicatat terpisah (`ios-build.txt`). | **Belum ada bukti runtime kesehatan terbaru lulus.** Binary lama dari implementator tidak mendukung modul baru. |
| iOS/Android fisik | **Tidak diuji** | Tidak ada klaim UI, cache/log OS, screenshot, jaringan, atau Push fisik lulus. |
| Expo/FCM/APNs nyata; ketukan foreground/background/cold start | **Tidak diuji** | Tidak ada kredensial/perangkat/provider nyata yang digunakan atau pesan yang dikirim. Cold start URI dev client membuka peluncur pengembangan; build non-dev tetap memerlukan bukti. |

Percobaan iOS: (1) `pod install` default + Xcode 27.1 gagal karena precompiled Expo dibangun Swift 6.3.1, sedangkan compiler lokal Swift 6.4; (2) `EXPO_USE_PRECOMPILED_MODULES=0` masih gagal mengimpor interface JSI; (3) cache JSI dibangun ulang, kemudian linker gagal pada vtable/typeinfo `facebook::react` dari kombinasi sumber/prebuilt RN. Tidak mengubah SDK, sumber dependensi atau konfigurasi tracked untuk memaksa hasil. Pod/native output lokal diabaikan Git. Build yang gagal tidak disebut lulus karena TypeScript atau Hermes lulus.

## Kriteria penerimaan Fase 4

1. **Terbukti pada web/API/Expo web dan alur Android yang tercatat:** data/versi identik dan penyimpanan ulang tidak menggandakan kunjungan/publikasi/notifikasi. F-1 ditutup dengan bukti kontrol.
2. **Terbukti web/API/Expo web:** lima peran dan luar cakupan; admin hanya audit/baca dan mengelola penugasan, petugas menulis, pembimbing/murobi hanya terkait, wali hanya proyeksi. Pencabutan ditolak pada permintaan berikutnya melalui token lama dan ketika layar aktif kembali. Hasil native lima peran juga lulus di Android emulator (10/0), dibatasi tabel perangkat; iOS terbaru belum terbukti.
3. **Belum lengkap:** InApp dan URI meminta login/otorisasi server serta menolak wali lain/dicabut; belum ada ketukan Push provider dan cold start build non-dev terbaru.
4. **Terpenuhi sebagai pencatatan bukti:** simulator/emulator, browser, build, dan perangkat fisik dipisahkan. Pencatatan batas tidak menyulap skenario yang belum dijalankan menjadi lulus.
5. **Terbukti pada permukaan lokal yang tercatat, belum lintas platform lengkap:** audit metadata/outbox tanpa klinis; storage/konsol browser bersih. Cuplikan Android baseline bocor dan memerlukan perlindungan native. Cache/log iOS pada binary terbaru belum dapat diuji.

## Penafsiran dan kesalahan penyiapan uji

- S-1 API penugasan memakai layanan pusat yang sama, dan S-2 pengelompokan kelas tidak membatasi hak petugas: diterima. S-3 konflik harus menolak draf usang; F-1 memperbaiki pelanggarannya. S-4 draf di memori boleh bertahan saat latar **bersama versi asalnya**, bukan memperoleh versi baru otomatis. S-5 ID URI/notifikasi hanya penunjuk, bukan hak. S-6 nama kelas hanya di pemilih petugas: diterima.
- Regresi Fase 2 pertama tidak diberi flag opt-in sehingga berhenti tanpa uji; diulang dengan flag yang benar. Probe Fase 3 sesudah integrasi menghasilkan **24/2**, karena asersi mengasumsikan belum ada ringkasan/notifikasi; **26/0** setelah DB segar, tanpa perubahan oracle. Bukti gagal tetap tersedia.
- Dua penyiapan fixture sempat bertumpang tindih dan salah satunya gagal pada database perantara yang sudah dihapus. Rangkaian tersebut dibatalkan; HTTP Fase 3 dan fixture aplikasi dibangun ulang **berurutan** sebelum hasil final. Tidak ada hasil tes dari DB penyiapan gagal yang dipakai.
- Probe awal ringkasan menguji penarikan dari revisi awal 0 (server menolak 422, aman), padahal konflik penarikan harus berasal dari ringkasan terbit. Probe final memakai publikasi awal lalu penggantian bersamaan; kode implementator dan kode koreksi diuji dengan **sumber probe final yang sama**. `probe-sebelum.txt` hanya diagnosis awal; putusan memakai pasangan kontrol/final. Iterasi diagnostik lain tidak disajikan sebagai hasil final.
- Salinan Expo tidak memuat `.env.local`. Satu startup pembanding sempat diarahkan ke cwd asal, lalu segera dihentikan sebelum browser/uji mengaksesnya; pengujian pembanding dilakukan dari `git archive 38e1850` tanpa `.env.local`. Browser memiliki penjaga host localhost. Tidak ada klaim request ke produksi diizinkan/dijalankan.
- `node_modules` yang disymlink ke luar salinan membuat impor dinamis Metro tidak teresolusi pada native. Ini dibuktikan diagnostik sementara yang hanya mencetak pesan galat modul; diagnostik dibuang. Diganti salinan APFS node_modules lokal, Metro diulang `--clear`, lalu dev client dibuka setelah server siap. Timeout runner sebelum Metro siap dan keyboard iOS tidak dihitung sebagai kelulusan ataupun cacat layanan.

## Handoff

Commit koreksi/bukti mobile: `33522f7` pada `claude/prd-v5-fase-4`. Branch web memuat laporan, status, dan probe; seluruh kode backend implementator dipertahankan.

**Tetap Fase 4.** Lengkapi runtime/build iOS yang memuat modul privasi, ketukan Push dan cold start pada build yang sesuai, serta log/cache terbaru pada perangkat yang benar-benar tersedia. Bukti fisik tetap harus disebut fisik hanya bila dilaksanakan. Auditor berikutnya perlu mengulang bukti yang masih terbuka sebelum menutup seluruh kriteria; Fase 5 belum diizinkan. Tidak merge, deploy, migrasi produksi, mengirim rilis aplikasi, atau mengubah data historis dalam audit ini.

Perubahan auditor hanya koreksi mobile, probe/regresi/pembantu uji, serta bukti/status audit pada branch fitur yang sama di kedua repo. Direktori `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, dan `output/` tidak disentuh atau dimasukkan commit.
