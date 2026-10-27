# Audit Codex — PRD V7 Fase 4

27 Oktober 2026. **Audit teknis lokal selesai setelah dua koreksi terarah. Penerimaan penuh Fase 4 belum tertutup: ketukan Push nyata melalui provider sesudah logout/pencabutan hak belum diuji.** Bukti URI, notifikasi in-app, emulator dan simulator di bawah tidak menggantikan butir itu. Tidak memulai Fase 5, merge, deploy, migrasi produksi atau rilis aplikasi; gerbang akhir V6 tetap terbuka.

## Sumber dan batas pekerjaan

- Branch kedua repositori `claude/prd-v7-fase-4`. Web: baseline audit Fase 3 `2e2acc7c9fd8e1a742e69d081faab47bd95756c5`, implementasi `ba3e1d745bb4548cca32740990d177fcc7f0539d`, handoff `1c49df360a962ac881394dd57e6bb4eafed1a367`. Mobile: baseline `d3a76461c852cc02bceae98963699b5f8f6c7250`, implementasi `7b1ae4d5f829d59f42fb5cc5a92b8eb15c21fd8a`, handoff `b427d0dbda1112f0966c5678a314ed66e89087fe`. `git fetch origin claude/prd-v7-fase-4` pada masing-masing repo mengonfirmasi handoff remote sebelum audit.
- Dibaca AGENTS.md kedua repo, PRD-V7.md, prompt Fase 4, audit Fase 1–3, README, bukti, runner dan seluruh perubahan dua commit fase pada masing-masing repo. Handoff menyatakan implementator berhenti; tidak menjalankan implementator atau subagen. Tidak membuat worktree atau menulis di main.
- PHP 8.4.14, MariaDB 12.3.2, Chromium Playwright, Expo SDK 57 dengan paket lock ter-commit (Expo 57.0.15). Dokumentasi versi tepat dibaca: [SDK 57](https://docs.expo.dev/versions/v57.0.0/), [ScreenCapture](https://docs.expo.dev/versions/v57.0.0/sdk/screen-capture/), [Notifications](https://docs.expo.dev/versions/v57.0.0/sdk/notifications/). Semua DB bernama `test_*_test`, berisi fixture sintetis; hanya localhost, tanpa provider Push/WhatsApp nyata.
- Salinan uji mobile tidak memuat env produksi. `package.json`/`package-lock.json` yang sudah berubah di checkout mobile dipertahankan di luar commit. Direktori web milik pekerjaan lain `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/` tidak disentuh.
- Backend hanya memiliki tambahan aditif `asal` REST V2 dari implementator. Auditor tidak mengubah layanan, hak server, endpoint atau migrasi. Koreksi berada pada mobile; web menyimpan probe dan bukti audit.

## Temuan dan koreksi

### F-1 — P2: draf perbaikan lama mengadopsi versi server baru — ditutup

`perbaiki.tsx` mempertahankan isian saat latar, tetapi mengambil `version` dan isi pembanding dari GET terbaru. Reproduksi: perangkat pertama menyunting versi 2, pergi ke latar; perangkat kedua mengirim perbaikan, pembimbing meminta perbaikan lagi sehingga versi menjadi 4; perangkat pertama kembali. Sumber handoff mengirim draf lama dengan versi 4 dan berhasil menimpa perubahan perangkat kedua.

Draf kini menyimpan isi asal serta versi saat penyuntingan dimulai. GET saat kembali dari latar tetap memeriksa akses dan membaca status terbaru, tetapi tidak menaikkan versi asal draf. Kiriman basi mendapat 409; tombol **Muat versi terbaru** secara eksplisit membuang draf dan memuat isi server. Riwayat/versi server tidak diubah auditor.

### F-2 — P2: layar keputusan V2 mempertahankan isi sesudah hak dicabut — ditutup

V7 meneruskan murobi/admin ke `/izin/[id]`. Layar itu sebelumnya menyimpan detail lama ketika POST keputusan ditolak 403/404, dan tidak memuat ulang pada siklus latar–depan. Probe mencabut seluruh penugasan murobi fixture setelah rincian terbuka: server menolak, nol keputusan tercipta, tetapi nama dan alasan masih terlihat pada sumber handoff. Berpindah ke layar V2 juga tidak menjamin perlindungan native bila layar dibuka langsung.

Layar V2 kini memakai `usePrivateResource` untuk mengosongkan data saat blur/latar, mengabaikan jawaban GET basi, dan membaca ulang saat kembali. Mutasi memakai pembungkus penjaga yang sudah ada untuk segera menutup data/draf pada 401/403/404. Pergantian akun, ID atau mode meremount sesi layar. Pengajuan `asal=Wali` memakai pelindung native yang sama dengan navigator V7, diekstrak ke `izin-wali-protection.tsx`, sebelum isi dirender. Jalur pengurus tetap menggunakan endpoint, versi, idempotensi, kemampuan dan keputusan V2; regresi buat/batal/putusnya diuji dari aplikasi.

Probe **identik**, `tests/browser/uji-v7-fase4-audit.mjs`, pada salinan sumber handoff dan sumber terkoreksi: **2 lulus/3 gagal → 5/0**. Dua pemeriksaan yang sudah lulus pada kontrol membuktikan penolakan server dan nol keputusan; tiga kegagalan membuktikan pengadopsian versi, penimpaan isi, serta tampilan lama. Bukti `audit-codex/probe-asli.txt`, `probe-akhir.txt`. Tidak menyebut kesalahan setup probe sebagai cacat produk.

## Bukti independen

| Uji | Hasil dan cakupan |
| --- | --- |
| Statis Fase 4 | **31/0**; oracle pelindung mengikuti ekstraksi komponen, daftar berkas mengizinkan dua probe auditor. Tidak melonggarkan hak/idempotensi atau oracle historis. |
| HTTP Fase 4 | **54/0**; kontrak, ID tunggal/replay, kunci konflik, wali kedua/asing, pembimbing luar cakupan, murobi sebelum penerusan, penetapan, perbaikan, keputusan V2, pencabutan token/relasi/penugasan, notifikasi dan no-store. |
| Expo web utama, 375 px | **48/0**; buat/perbaiki/teruskan/putus/batal, notifikasi in-app, ID/status/isi sama di API/DB/website; jawaban POST hilang dan retry memakai kunci sama, klik ganda satu POST, konflik 409, offline/pulih, sesi habis, dua wali, pencabutan. Konsol 93 baris tanpa penanda/kredensial yang diuji; nol request di luar localhost dan nol WhatsApp. |
| Expo web tambahan | **7/0**; murobi luar cakupan sesudah penerusan, admin pengganti wajib alasan dan satu keputusan, wali membaca keputusan itu, buat/batal/putus jalur pengurus V2 langsung, relasi pengaju dicabut ketika konfirmasi batal terbuka → data/draf tertutup tanpa mutasi. |
| Probe kontrol F-1/F-2 | **2/3 → 5/0**, API/DB nyata, bukan mock jawaban bisnis. |
| TypeScript, lint, unit mobile | tsc dan lint **exit 0**, unit **42/0** dari paket ter-commit. |
| Android | **8/0**, emulator AVD Medium_Phone Android 16; rincian wali, FLAG_SECURE, logout → URI → login tanpa isi, wali kedua ringkas, relasi dicabut saat latar, dua ketukan pembimbing → satu penerusan, keputusan V2 tetap SECURE, pencabutan murobi lalu kembali dari latar menutup isi dan tidak membuat keputusan. |
| iOS | **6 pemeriksaan substantif lulus**, terbagi cold URI/login (1), draf basi + muat terbaru (2), pembatalan + wali kedua + pencabutan (3). Smoke binary akhir **1/0**. Catatan pengulangan alat dijelaskan di bawah. |

Bukti tersimpan di `audit-codex/`; tangkapan browser auditor memakai fixture sintetis. Angka di atas merupakan eksekusi auditor, terpisah dari `bukti/` implementator.

### Build dan tujuan notifikasi

Android menggunakan APK **Debug dev client** yang sudah memuat ScreenCapture, SHA-256 `f2f443fbeed626360da3821cae8109adb44ad1225246bb559924bd28072fe441`, ditambah JavaScript sumber koreksi melalui Metro dari direktori paket nyata. Tidak membangun APK distribusi baru. Android cold start URI rilis tidak diuji; dev launcher tidak dianggap bukti cold start rilis.

iOS **Release arm64 iphonesimulator**, tanda tangan ad-hoc lokal, dibangun auditor melalui Xcode 27.1 untuk iPhone 17 Pro simulator iOS 26.2. Build akhir `BUILD SUCCEEDED`; bundel Hermes menunjuk API fixture port 8977, nol host produksi dan nol diagnostik sementara. Tidak bergantung pada Metro saat runtime, tidak membuat arsip distribusi. Kegagalan build pertama berasal dari cache kompilasi salinan yang masih mengacu jalur lama; setelah cache dibuang, build berhasil. Ini tidak menguji iOS Debug atau perangkat fisik.

Tujuan foreground diuji melalui notifikasi in-app dan URI; URI saat latar diuji native, cold start URI/login diuji iOS Release. Payload `izin_wali` juga diuji unit dan backend. **Tidak ada pengiriman Expo/FCM/APNs atau ketukan banner Push nyata.** `push-registration.ts` memang berhenti pada `Device.isDevice=false`; auditor tidak mengubah guard untuk membuat klaim Push. Butir penerimaan ketukan Push setelah logout/pencabutan tetap terbuka.

## Regresi dan kegagalan historis

- V7 Fase 3 integrasi **112/0**, HTTP **43/0**, probe laporan **18/0**, probe seluruh peristiwa/audit **49/0**. Fase 2 integrasi **119/0**, HTTP **61/0**, probe auditor **34/0**. Fase 1 HTTP pada fixture Fase 1 **71/0**.
- Penugasan **161/0**. V2 integrasi Fase 1/2/4 **39/0 · 94/0 · 124/0**, konkurensi **20/0**, API Fase 5 **150/0**, web Fase 2/4/5 **36/0 · 46/0 · 79/0**. Fase 5 integrasi **143/0** sesudah suite prasyarat Fase 4 pada DB yang sama.
- V2 API Fase 3 **95/21**, Fase 4 **91/1**: daftar `[gagal]` identik baris per baris dengan bukti implementator Fase 4 dan kontrol historis yang tercatat pada audit Fase 1–3. Tidak dinyatakan hijau.
- Seluruh **43 statis: 18 exit 0, 23 exit 1, dua exit 77**, hitungan sama dengan handoff. Tujuh teks kegagalan daftar berkas historis bertambah nama probe/bukti auditor; jenis/hitungan kegagalannya tetap sama. Oracle lama tidak dilonggarkan.
- Setup awal tiga probe auditor lama keluar 2 karena flag `V7_RUN_AUDIT=1` belum diberikan; pengulangan dengan flag sah menghasilkan 18/0, 49/0, 34/0. HTTP Fase 1 pada fixture gabungan Fase 2 menghasilkan 70/1 karena jumlah anak bertambah; fixture sesuai fasenya 71/0. V2 Fase 5 yang dijalankan sendiri menghasilkan 138/5 pada receipt Push; sesudah prasyarat Fase 4, 143/0. Semua hasil awal tetap disimpan dan tidak diklaim lulus.
- Suite HTTP/browser redesain, DB V3–V6, preservasi/migrasi lintas versi, MySQL/cPanel dan UAT tidak diulang penuh pada audit Fase 4 ini. Tidak ada perubahan backend selain kunci aditif asal; tidak menganggap bukti implementator sebagai eksekusi auditor. T-1 retry bersamaan pengurus V2 (sebagian 500 tanpa baris ganda) tetap temuan lama di luar koreksi ini.

### Rincian iOS dan pengulangan alat

`ios-cold-start-wali.txt` membuktikan URI tanpa sesi → login → rincian. `ios-lanjutan.txt` membuktikan draf versi 2 tetap ditolak setelah versi server 4 dan muat terbaru mengganti isian. Alur ini kemudian berhenti pada pemilih kolom pembatalan milik alat. `ios-pembatalan-akses-akhir.txt` **3/0** memverifikasi aksi pembatalan yang benar-benar sudah dilakukan, satu riwayat pada versi 5, login wali kedua hanya status, serta pencabutan relasi pada sesi lama. Android #2706 tetap `Diajukan` sesudah satu penerusan; iOS #2708 `Dibatalkan`; kedua ID/status cocok dengan web/API (**2/0**, `paritas-native-web-api.txt`).

Percobaan Maestro sempat mengetuk label alih-alih input, memakai persen desimal yang tidak didukung, menemukan gabungan label+placeholder, gagal menyembunyikan keyboard multiline, memilih judul bagian alih-alih tombol, serta menuntut pesan sukses pendek padahal pesan lengkap lebih panjang. Screenshot dan DB menunjukkan pembatalan sebenarnya berhasil; hasil itu kemudian diperiksa terpisah, bukan mengubah keluaran gagal menjadi lulus. Ini bukan klaim satu suite iOS enam skenario langsung hijau. Sesudah pengikatan ID/mode final, binary dibundel ulang dan dipasang lagi; `ios-binary-final.txt` membuktikan cold URI/login serta isi server pada binary final tanpa Metro.

## Privasi, penafsiran dan handoff

Pemindaian akhir (`privasi-akhir.txt`): **Android 633 baris log proses/51 berkas data; iOS 74.550 baris log proses/230 berkas data**; nol penanda sensitif yang diuji dan nol pola bearer mentah pada log. Android juga nol pola bearer mentah pada berkas data. Dua snapshot SplashBoard iOS terbaru, penuh dan downscaled, didekode memakai sips/ImageIO dan diperiksa visual: **hitam seluruhnya**, tanpa nama/alasan. Pengamatan dilakukan setelah isi wali pengaju terlihat lalu aplikasi dibawa ke latar; hash dan PNG di `snapshot-ios.txt`/`tangkapan/`. Ini membuktikan dua snapshot simulator tersebut, bukan seluruh cache OS atau perangkat fisik.

Pemindaian raw log/cache memakai penanda alasan, nama santri dan kata sandi fixture, serta pola bearer mentah; bukan bukti seluruh bentuk cache/OS telah ditafsirkan. Kecocokan awal Android pada `DevLauncherApp-BridgelessReactNativeDevBundle.js` adalah literal kode `Bearer `, **nol pola bearer mentah**, tanpa penanda isi pengajuan. Log mentah perangkat disimpan sementara di luar repositori; ringkasan audit mencatat jumlah dan batasnya. Berkas snapshot biner yang hanya dipindai sebagai byte tidak diklaim bebas isi visual tanpa dekode.

Penafsiran S-1 diterima sebagai langkah teknis memenuhi privasi, konsisten modul V5/V6: pengajuan wali tidak dapat ditangkap layar native; perlindungan sekarang mencakup layar keputusan V2 atas pengajuan wali. S-2/S-3/S-4 diterima: keputusan tetap V2 pada satu ID, `asal` aditif, penunjuk tujuan bertahan melewati login dengan pemeriksaan server. S-5 diterima dengan koreksi F-1: draf boleh berada di memori saat latar, tetapi versi asalnya tidak boleh berubah diam-diam. Semua UI menyembunyikan isi ketika hak ditolak; otorisasi tetap server.

Commit dan push hanya hasil audit/koreksi pada kedua branch Fase 4. Tidak menyertakan perubahan paket atau direktori pekerjaan lain. Berhenti setelah handoff ini; tidak memulai Fase 5 atau menganggap audit ini menutup validasi Push nyata, perangkat fisik, hosting maupun gerbang produksi V7/V6.

Koreksi dan ringkasan mobile dikomit pada `7478f7cb153e4f8af1a1b85791ce843a29733bd2` di branch yang sama. Commit web yang memuat laporan ini menyimpan seluruh bukti auditor; hash commit web dibaca dari Git agar tidak membuat rujukan diri yang keliru.
