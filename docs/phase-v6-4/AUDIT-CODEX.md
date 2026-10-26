# Audit Codex — PRD V6 Fase 4

26 Oktober 2026. Auditor Codex, setelah handoff implementator Claude Code. Hanya Fase 4; kedua checkout tetap `claude/prd-v6-fase-4`, tanpa worktree.

**Putusan: BELUM LULUS seluruh validasi lintas platform Fase 4.** Temuan kode F-1 telah dikoreksi dan pengujian utama web/API/Expo Fase 4 lulus; runtime iOS terbaru belum dapat diuji karena build simulator gagal. Bukti Android dan batasnya dirinci di bawah. Tidak menyatakan siap produksi, tidak memulai Fase 5, merge, deploy, migrasi produksi, atau merilis aplikasi.

## Baseline dan cakupan

| Repositori | Baseline | Commit fase yang diperiksa |
| --- | --- | --- |
| WebAlHasan | audit Fase 3 `1f2dbca574805954566305789e589540e27348f0` | `9754be56d1d361a3830de5eeac06cb5b787285ac`, `ee582502e42b9a4fbe71e6c2590c40a4108d0bc7`, `ecd89b7fc1d9a385820c03e28e95e7e1c9bb2210`, `269298ccad7cf015310c4165ba50f7fe9e39553f` |
| alhasanApps | `f8ebba0a43493efde50a7a207933e86dc4b941d4` | `950ca355106600c791c34d977be94debf0174fbc`, `12fb04ae54fe3ae2e86afdd344ddb6f633b48e76` |

Hash remote branch fase sama dengan HEAD awal. Remote main web `4502233efefd53ab937c5d1e13edf8ecdad83f71` sudah memuat audit Fase 3. Kedua AGENTS, PRD V6, prompt audit, audit Fase 1–3, README dan bukti Fase 4 dibaca; diff seluruh commit fase diperiksa. Handoff menyatakan implementator berhenti; tidak menjalankan/memanggil Claude. Proses aplikasi desktop Claude yang tetap ada tidak dianggap bukti ada pekerjaan checkout berjalan.

Perubahan `package.json`/`package-lock.json` mobile dan tiga direktori web milik pekerjaan lain dipertahankan di luar commit audit. Seluruh basis data yang ditulis bernama `test_*_test`, berfixture sintetis. Builder fixture asli dijalankan ulang; dump hasil builder disalin ke DB berbeda untuk setiap suite. Tidak memakai database produksi atau data santri nyata.

Salinan mobile tanpa `.env*` dibuat dari berkas HEAD, lalu sumber koreksi disalin ke sana. `node_modules` dari instalasi implementator dengan lock ter-commit dipakai kembali; SHA-256 lock kedua salinan sama, `a874dd8856a59c2724939eb92d6d15b069c95ec56bb5f613c33fc5d9b7361f5a` (Expo 57.0.15). Paket checkout 57.0.31 milik pekerjaan lain tidak diubah. API bundel audit hanya localhost. Dokumentasi [Expo SDK 57](https://docs.expo.dev/versions/v57.0.0/) dan [ScreenCapture](https://docs.expo.dev/versions/v57.0.0/sdk/screen-capture/) dibaca sebelum koreksi mobile.

## F-1 — data dan draf lama tetap tampil sesudah POST ditolak (P2, ditutup)

Pemicu: Petugas BSA membuka formulir/detail, penugasannya dicabut, lalu menekan tindakan pada layar lama. Server mengembalikan 403 dan tidak memutasi uang, tetapi aplikasi hanya menampilkan pesan galat di bawah data lama. Nama santri, saldo/nominal, referensi transfer atau draf masih tampak.

Probe auditor `audit-codex/uji-mutasi-akses.mjs` memeriksa delapan jalur: setoran, pembayaran tunai, bayar dari tabungan, revisi tagihan, verifikasi pembayaran, pembatalan pembayaran, koreksi tabungan, dan verifikasi setoran transfer. **Kode implementator: 8 lulus/8 gagal.** Delapan penolakan tanpa mutasi uang sudah benar; delapan pemeriksaan pembuangan data/draf gagal.

Koreksi mobile:

- `usePrivateResource.reject()` menaikkan generasi pembacaan, membuang data, membersihkan draf melalui callback yang ada, dan memasang pesan galat. Respons GET lama tidak dapat mengisi ulang data setelah penolakan.
- `useKeuanganMutation` tetap memakai penjaga idempotensi yang sama; 401/403/404 dari mutasi juga menutup resource layar induk. Semua tindakan keuangan dan kartu setoran memakai penangan ini; pratinjau penerbitan yang ditolak menutup layar juga.
- Tidak mengubah keputusan saldo/otorisasi di server, perilaku retry jaringan, atau penanganan konflik 409/validasi 422.

**Probe asli sesudah koreksi: 16/0.** Suite Expo web asli 45/0 sebelum dan sesudah koreksi; regresi formulir kesehatan V5 yang memakai hook bersama 19/0; TypeScript/lint/unit lulus. Tidak ada koreksi kode aplikasi web/API oleh auditor.

## Bukti independen

Seluruh keluaran auditor berada di `audit-codex/`, terpisah dari `bukti/` implementator.

| Rangkaian | Hasil |
| --- | --- |
| Statis Fase 4, termasuk lint 14 PHP | 22/0 |
| HTTP web/API Fase 4, empat pekerja PHP | 63/0 |
| Expo web asli, Chromium 375×812 | 45/0 awal dan 45/0 akhir |
| Probe F-1, oracle tidak diubah | 8/8 awal → 16/0 akhir |
| Probe outbox gagal/rollback/replay/otorisasi worker | 9/0 |
| Mobile TypeScript / Expo lint | exit 0 / exit 0, tanpa peringatan |
| Unit mobile | 36/0 |
| V6 Fase 1 integrasi/konkurensi, probe audit | 120/0 · 37/0 |
| V6 Fase 2 integrasi/konkurensi, probe audit | 179/0 · 30/0 |
| V6 Fase 3 integrasi/konkurensi/rollback, probe audit, batas tengah malam | 137/0 · 27/0 · 9/0 |
| Fondasi penugasan, V2 Fase 4 | 161/0 · 124/0 |
| V2 Fase 5 receipt/notifikasi | 138/5 pada fixture asal; kontrol antrean 143/0, lihat bawah |
| V5 Fase 3 notifikasi/worker, V5 formulir dari latar (Expo web) | 82/0 · 19/0 |
| Seluruh 38 suite statis | 19 exit 0 · 17 exit 1 · 2 exit 77; oracle historis tetap dicatat |

### Kriteria dan implikasinya

1. **Aplikasi → website sekali, angka identik.** Expo web mengirim setoran Rp100.000; respons server sengaja dibuang setelah diproses. Dua ketukan awal menghasilkan satu POST, retry memakai kunci yang sama dan menghasilkan satu kredit. Belanja, transfer menunggu/verifikasi, pembayaran tabungan dan tunai, serta penerbitan massal diuji. Website menampilkan setiap kuitansi aplikasi tepat sekali dengan saldo/sisa yang sama. HTTP juga menguji permintaan serentak berkunci sama. Tidak mengklaim seluruh kemungkinan interleaving produksi.
2. **Matriks peran.** HTTP memeriksa sembilan akun: BSA seluruh unit/unit lain, admin/admin+petugas, wali SMP, wali Tsanawi, wali dua anak, wali lain, pengurus tanpa penugasan. Daftar/detail/tagihan/pembayaran/tabungan/kuitansi melewati server. Admin/wali tidak dapat memutasi; ID anak lain ditolak. Expo memeriksa admin tanpa tindakan, wali satu anak, wali dua anak, dan URL anak lain tanpa data.
3. **Pencabutan sesi lama.** Token dan sesi web lama wali dua anak kehilangan tepat satu anak sesudah relasinya diarsipkan; anak lain tetap terbaca. Penerima tercabut tidak mendapat notifikasi baru, Push antre ditolak worker. Penugasan BSA dicabut pada formulir lama → POST 403 tanpa mutasi; F-1 kini membuang layar/draf. Kembali fokus/aktif membaca ulang server.
4. **Konflik/error.** Expo menguji revisi dari versi lama → 409, tombol eksplisit muat versi terbaru, tanpa menimpa versi baru. Saldo tidak cukup → 422, offline → pesan/coba lagi, token dicabut → login. Tidak mengulang konflik secara otomatis.
5. **Privasi.** HTTP memeriksa log tanpa token/referensi transfer dan 62 baris audit BSA tanpa nominal/saldo/referensi. Outbox hanya teks umum dan penunjuk. Expo memeriksa local/sessionStorage tanpa nama/referensi, konsol tanpa token/kata sandi/referensi, dan nol permintaan keluar localhost. Data yang diperlukan untuk autentikasi di SecureStore dibedakan dari cache keuangan yang tidak diperlukan. Bukti native di bawah tidak dianggap pemeriksaan semua lokasi penyimpanan sistem operasi.

Probe outbox memaksa INSERT penerima kedua gagal: respons 503; uang, audit, klaim dan penerima pertama ikut rollback. Retry kunci sama baru satu kali berhasil, lalu replay tidak mengantre ulang. Worker membentuk ulang penunjuk tanpa kunci tambahan; event_key tidak cocok, master wali nonaktif, dan santri nonaktif ditolak. Provider nyata tidak dipanggil.

Kontrol awal probe outbox 8/1 memakai asumsi terlalu ketat bahwa setoran pertama tidak boleh membuat akun bersaldo nol. Fase 1 memang menyiapkan akun nol sebelum transaksi. Tidak ada uang/notifikasi parsial; probe akhir menyiapkan akun nol dahulu untuk mengisolasi rollback uang/outbox. Kontrol awal disimpan, bukan dianggap koreksi backend.

## Android dan iOS

Android emulator `Medium_Phone` / `sdk_gphone16k_arm64`, Android 16. APK Debug dev client lokal 24 Oktober 2026 (versionName 1.0.0, versionCode 1) dipasang ulang ke emulator; Metro memakai sumber koreksi dan paket ter-commit Expo 57.0.15, API/DB native terpisah. **Bukan build native baru atau perangkat fisik.** Enam pemeriksaan BSA lulus: setoran Rp100.000, dua ketukan belanja Rp25.000 menjadi satu debit, wajib persetujuan pembayaran tabungan, pembayaran silang Rp50.000 menjadi saldo Rp25.000/sisa Rp50.000, rujukan debit, dan F-1 POST 403 pada formulir lama. Lima pemeriksaan wali lulus: dua anak terpisah, rincian hanya anak terpilih, pencabutan relasi pada layar lama, FLAG_SECURE di latar, serta pencabutan anak kedua saat aplikasi di latar dan penolakan saat kembali aktif.

Pemeriksaan peran tambahan membuktikan admin tanpa tombol mutasi, wali satu anak, dan wali asing yang ditolak melalui deep link (**tiga assertion lulus**). Pembantu berhenti pada asumsi bahwa login berikutnya kembali ke Beranda; aplikasi sebenarnya mempertahankan tujuan deep link sesuai rancangan. Bagian cache dilanjutkan pada akun BSA yang sudah berhasil masuk ke tujuan yang diizinkan; kegagalan pembantu tersebut disimpan di `android-peran-cache.txt`, bukan dinyatakan regresi aplikasi.

`android-cache.txt`: lima assertion lulus dan satu kontrol cold start gagal mencapai tujuan. Referensi transfer sintetis benar-benar dimuat di UI BSA sebelum scan. **45 berkas** privat aplikasi/cache tanpa penanda referensi, nama santri, nomor kuitansi atau kata sandi yang diuji; nol bearer mentah menurut pola yang diperiksa. **1.364 baris logcat proses** juga tanpa penanda tersebut. Ini pemindaian berkas mentah/direktori aplikasi dan log proses yang tersedia, bukan forensik RAM atau seluruh cache OS. Token autentikasi native tetap diperlukan di SecureStore terenkripsi; token web di sessionStorage adalah penyimpanan autentikasi yang sudah ada, bukan cache keuangan baru. Tidak menuntut penyimpanan autentikasi yang diperlukan kosong.

Gambar `audit-codex/android-recents.png` diperiksa visual: kartu aplikasi hitam tanpa isi keuangan. Setelah force-stop, URI `alhasanapps://keuangan/verifikasi` membuka **dev launcher**, belum memilih proyek Metro; tujuan belum dimuat. Ini batas dev client, bukan bukti cold start build rilis lulus atau cacat cold start pada binary rilis. Tidak memperbaiki/mengganti toolchain atau membuat klaim Push/cold start produksi dari hasil ini. Sesudah proyek Metro dibuka kembali lewat URI dev client, pemeriksaan terpisah membuktikan FLAG_SECURE dilepas saat keluar ke Profil (`android-proteksi-profil.txt`).

Uji Android berlangkah memakai uiautomator/adb. Kontrol penyiapan yang disimpan terpisah mencatat perbaikan pembantu uji, bukan koreksi aplikasi: kutip `&` pada URL adb, asumsi riwayat navigasi setelah deep link, judul layar yang sama dengan label tombol, atribut radio native, dialog `KELUAR` uppercase, dan penanda/waktu pemuatan Beranda, serta tujuan yang sengaja bertahan melewati login. Enam assertion BSA serta lima assertion wali dicatat terpisah; ringkasan parsial pembantu awal tidak digunakan untuk mengklaim langkah wali yang belum dijalankan.

**iOS:** simulator iPhone 17 Pro iOS 26.2 di-boot. Binary tersisa gagal dipasang karena bundle executable tidak ada. Build Debug diulang dari salinan implementator dengan lock ter-commit, Xcode 27.1, `CODE_SIGNING_ALLOWED=NO`; linker kembali gagal pada `RCTPackagerConnection` dan `facebook::react::Sealable`. `audit-codex/ios-build.txt` memuat diagnostik. **Runtime iOS Fase 4 belum diuji**, tidak dianggap lulus. Tidak mengubah konfigurasi build/dependensi milik pekerjaan lain untuk menutupi batas ini.

**Perangkat fisik Android/iOS, Push provider nyata/ketukan Push, dan cold start dari Push pada build non-dev belum diuji.** Provider pada pengujian worker adalah klien palsu; notifikasi InApp dan tautan skema yang diuji tidak menjadi bukti Push fisik.

## Regresi historis dan batas

- **V2 Fase 5:** lima kegagalan hanya KL-9a/b/d/e/f pada receipt sukses. Builder membawa antrean Push sintetis lama yang dikonsumsi lebih dulu oleh worker berbatas batch. Suite asli diulang pada salinan fixture baru yang hanya mengosongkan baris kanal Push bawaan → **143/0**. Hasil awal 138/5 tetap disimpan; tidak mengubah kode atau oracle tes.
- **38 statis:** 36 mempunyai pesan kegagalan literal sama dengan bukti implementator Fase 4. Dua perbedaan (`v6_phase2_static`, `v6_phase3_static`) hanya daftar berkas audit baru pada larangan cakupan fase lama; jumlah/jenis kegagalan tetap 6 dan 2. `perbandingan-statis.json` menyimpan perbandingan lengkap. Oracle lama yang melarang perubahan notifikasi, migrasi setelah fase lama, peta menu dan checkout mobile tidak dilonggarkan. V3 Fase 2/3 exit 77 karena DB bernama tetap tidak disiapkan.
- Tangkapan Expo akhir wali dua anak dan kuitansi pembayaran diperiksa visual pada 375px; tanpa overflow pada suite. Ini bukan pengganti TalkBack/VoiceOver atau seluruh ukuran layar native.
- S-1–S-8 README diterima dalam batas Fase 4: notifikasi umum wali memakai outbox transaksi, pembayaran tabungan satu pemberitahuan, worker memeriksa ulang keadaan, tarif/penyesuaian tetap di website. Pengelolaan/ekspor laporan lengkap dan gerbang rilis tetap Fase 5. Admin mengamati aktor/status/rujukan transaksi di aplikasi; tidak menambah pengelolaan uang untuk admin.
- T-1 dipertahankan sesuai keputusan Human Developer: perlindungan seluruh navigator Keuangan termasuk kuitansi, cetak/PDF melalui website. T-2 privasi wali di daftar/tunggakan diuji HTTP dan konsisten dengan DTO; tidak dikoreksi ulang auditor.
- MySQL/cPanel/produksi, backup/pemulihan hosting, UAT, perangkat fisik dan Push nyata belum diuji. MariaDB lokal 12.3.2 saja. R-10 Fase 3 (saldo santri nonaktif/keluar) dan risiko menu/notifikasi aktif bila branch parsial dideploy tetap terbuka sebelum gerbang akhir.
- Tidak menjalankan ulang seluruh HTTP/migrasi/browser PSB, V1–V5, redesain web atau migrasi/rollback V6. Bukti implementator dan audit fase terdahulu tetap terpisah; tabel di atas hanya eksekusi auditor sesi ini. Kode PSB, API bisnis lama, migrasi dan penugasan tidak diubah auditor.

## Handoff

Commit audit mobile: `87d1c10` (koreksi F-1 dan ringkasan). Commit audit web memuat laporan/bukti ini; hash commit sendiri dilihat melalui riwayat Git.

Commit dan push hasil audit pada kedua branch fase, lalu berhenti. Runtime iOS dan validasi operasional yang belum tersedia harus tetap terbuka; kelulusan uji lokal tidak menjadi izin rilis. Tagihan serta tabungan hanya boleh dirilis bersama setelah gerbang akhir V6 dan keputusan Human Developer. Tidak memulai Fase 5 atau mengubah status rilis V5.
