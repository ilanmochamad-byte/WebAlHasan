# Audit Codex — redesain tampilan aplikasi gelombang 2 Fase 1

12 November 2026 · Branch kedua repo `claude/redesain-tampilan-v2-fase-1`

**Keputusan audit: Fase 1 belum lulus penuh.** Koreksi terarah menutup target tombol mata, indikator status akun, angka sebelum data termuat, dan kontras placeholder. Perbedaan dari spesifikasi §4.1–§4.2 masih terbuka.

## Baseline dan cakupan

| Repo | `main` terverifikasi langsung di GitHub | Commit Claude Code yang diaudit |
| --- | --- | --- |
| alhasanApps | `77f6240e3a76f51fafcdcf0ee7f2abfbb568647d` | `bc99c124dc78f31be1480b63b6e10f39f8610b0a` |
| WebAlHasan | `5c88789147e3777f83221ab11222ea82e8837374` | `732910e12d6fc33b78b2b59f1e0460e1f6d8320a` |

Koreksi mobile dicatat pada `8c8e025`. Masing-masing baseline merupakan induk langsung commit fase, dan kedua head handoff cocok dengan remote. Sumber audit adalah AGENTS.md kedua repo, rencana gelombang 1, rencana gelombang 2 §1–§3, §4.1, §4.2 Beranda/Profil, K-1–K-10 dan §6, serta laporan dan bukti handoff.

Diff web terbatas pada alat uji dan dokumen. Pada mobile, diff autentikasi, klien API, model profil, navigasi tab, perlindungan layar, manifest paket, lockfile, dan konfigurasi build native diperiksa terhadap baseline. Satu perubahan `app.json` menaikkan `expo.version` **1.4.0 → 1.5.0**. Pemeriksa versi repo lulus. Perubahan lokal `package.json` dan `package-lock.json` yang telah ada sejak awal dipertahankan di checkout dan dikeluarkan dari salinan uji serta commit.

Perubahan pada navigator akun hanya menyembunyikan kepala native ketika kepala khusus dirender. Penjaga rute, formulir dan pemanggilan mutasi, alur autentikasi/password, idempotensi, serta pemanggilan `expo-screen-capture` tetap seperti baseline. Koreksi auditor pada penyedia notifikasi hanya mengekspos apakah cache hitungan milik akun telah tersedia. Pemanggilan API dan mekanisme cache tetap sama.

## Koreksi dan kontrol

| ID | Kontrol awal | Koreksi dan hasil |
| --- | --- | --- |
| F-1 | Playwright mengukur isian Masuk setinggi 19 pt, target mata Masuk 20×20, dan target mata Ganti password 19×19. `hitSlop` tidak menghasilkan target web yang dapat diuji 44×44. | Tombol mata, isian, dan pil penyaring fondasi memakai ukuran minimum 44 pt. Formulir bersama juga dipakai layar Ganti password wajib. |
| F-2 | Kartu identitas menampilkan status Pengurus tanpa ikon. | Tambahkan ikon orang pada setiap pil status Beranda dan Profil. Label dan sumber status tetap mengikuti profil. |
| F-3 | Dengan respons hitungan belum dibaca HTTP 503, Beranda masih menampilkan 0. | Stat notifikasi baru ditampilkan setelah cache untuk akun aktif terisi. Respons sukses dengan hitungan 0 tetap ditampilkan. |
| F-4 | Placeholder `SearchBar` di `backgroundElement` terang hanya 4,17:1. | `textMuted` terang menjadi `#5F6D64`. Ambang unit untuk placeholder dinaikkan menjadi 4,5:1 dan hasilnya 4,57:1. |

Probe browser yang sama berubah dari **1 lulus / 5 gagal menjadi 6 lulus / 0 gagal**. Tiga tombol mata diuji pada formulir Ganti password normal. Probe memaksa galat hanya pada hitungan notifikasi. Semua permintaan lain memakai API sintetis lokal. [Log sebelum](audit-codex/probe-sebelum.txt) · [Log sesudah](audit-codex/probe-sesudah.txt).

## Keputusan dan temuan yang masih terbuka

- **§4.1 Masuk.** Sapaan yang direncanakan belum muncul di Masuk (P-1). Sebagian suite gelombang pertama menunggu teks `Assalamu` untuk mengenali Beranda. Menambahkan sapaan yang sama ke Masuk memerlukan penanda selesai login yang lebih spesifik agar pengujian tetap membuktikan hasilnya.
- **§4.1 Edit profil dan §4.2 Profil.** Lencana di Edit profil masih membuka pemilih foto langsung dan tombol hapus tetap langsung (P-4). Rencana meminta lembar bawah. Data diri tetap ditampilkan langsung di Profil dan Akun/Pemberitahuan masih satu grup (P-3). Pola ini terikat pada oracle profil yang ada. Perbedaan terhadap rencana tetap terbuka.
- **§4.2 Beranda.** Daftar Jadwal hari ini masih memakai `ScheduleCard` dengan jam di kiri, label status dan penanda Absen seperti gelombang 1 (P-5). `EntityRow` sudah ada dan diuji melalui pratinjau, tetapi pemakaiannya di daftar ini belum sesuai rencana. Kartu sorot non-guru mempertahankan ringkasan cakupan yang tersedia, karena Beranda tidak memuat hitungan antrean izin.
- **Aksesibilitas.** Teks isi mengikuti ukuran sistem. Implementasi masih menetapkan `maxFontSizeMultiplier={1}` pada inisial dekoratif avatar untuk mencegah peluberan ketika avatar ikut membesar. Label nilai grafik pratinjau masih dibatasi satu baris dan dapat terpotong pada lebar sempit dengan skala besar. Bilah tab web lama dapat meluap 33 px pada 375 px untuk empat tab dan dikecualikan secara eksplisit dari oracle luapan isi. Ini adalah batas lama yang dicatat implementator. Label ubin yang terpecah pada skala ekstrem sudah diterima dalam keputusan gelombang 1.
- **Warna status.** Seluruh token yang dikunci K-1 identik dengan baseline. Status selesai di `ScheduleCard` dan nada sukses `StatusPill` memakai kembali token sukses yang dikunci. Ada pemakaian `primary` sebagai warna status pada layar detail laporan lama di luar Fase 1. Detail kehadiran `/reports` menggunakan warna kehadiran pada ikon dan warna teks utama untuk label. Rasio 3,87:1 dan 3,39:1 pada latar krem cukup untuk ikon, sementara pemakaian keduanya sebagai teks biasa akan memerlukan 4,5:1.
- **K-9.** Logo sumber RGBA 2161×761 menjadi 80×28, 159×56, dan 239×84. Resampling ulang dengan `sips -z` dan perbandingan RGBA setelah dekode menghasilkan **nol piksel berbeda** pada ketiga ukuran. Bentuk dan warna identik dengan hasil penskalaan sumber. Ujung lime di atas krem tetap berkontras rendah. Layar Masuk memakai dua aset potret lama yang sama sesuai mode. Ikon aplikasi/splash berada di luar K-9. [Pengukuran aset](audit-codex/logo.txt).

## Kontras independen

| Teks atau ikon / latar | Terang akhir | Gelap akhir |
| --- | ---: | ---: |
| `textMuted` / `backgroundElement` | 4,57 | 4,61 |
| `textMuted` / `background` | 4,95 | 5,49 |
| `textMuted` / `card` | 5,44 | 5,05 |
| `onPrimary` / `primary` | 7,72 | 7,88 |
| `heroText` / `heroSurface` | 9,76 | 10,87 |
| `heroTextSecondary` / `heroSurface` | 7,30 | 8,80 |
| `heroAccent` / `heroSurface` | 6,33 | 7,53 |
| `statusIzin` / `background` | 3,87 | 9,20 |
| `statusTerlambat` / `background` | 3,39 | 8,99 |

Ambang §3.1 adalah 4,5:1 untuk teks biasa dan 3:1 untuk ikon atau teks besar. Aksen emas dan logo berperan sebagai hiasan. Nilai dihitung dari luminans relatif sRGB tanpa membulatkan sebelum pembandingan.

## Pengujian independen

Salinan baseline dan fase berasal dari arsip Git. Dependensi diinstal dari lock ter-commit dengan `npm ci --offline`. Berkas `.env*` disingkirkan dari salinan, Expo dijalankan offline, dan alamat API eksplisit memakai `127.0.0.1`. Suite browser memblokir host lain. Browser dan simulasi native memakai MariaDB lokal dan fixture sintetis. Pratinjau komponen hanya ditambahkan ke salinan Expo web pengujian.

- TypeScript dan Expo lint lulus pada baseline, handoff, dan hasil koreksi setelah Expo membangkitkan deklarasi tipe CSS normalnya. Unit **80/0** baseline, **90/0** handoff dan **90/0** koreksi.
- Suite Expo web Fase 1 **175/0** pada handoff dan **175/0** setelah koreksi. Tujuh akun mencakup guru, pengurus, orang tua, BSA, kesehatan, Keamanan, dan admin. Admin membuktikan empat tab yang dideklarasikan, tanpa tombol +. Aturan lama tetap menampilkan lebih sedikit tab sesuai cakupan akun. Ubin tetap tiga kolom. Jadwal, Perizinan, Laporan dan Notifikasi terbuka dalam pemeriksaan regresi `ScreenHeader`.
- Profil kembali ke Beranda. Edit profil dan Ganti password kembali ke halaman sebelumnya. Pembukaan langsung kedua layar akun di Expo web membuat ← kembali ke Beranda. Masuk dan Ganti password wajib mengikuti pengecualian K-10. Gelap dan lebar 375/768/1280 diuji.

### Paritas permintaan

Kontrol baseline terhadap baseline dan baseline terhadap source akhir masing-masing **23 langkah sama / 0 berbeda, 143 permintaan**. Alur tujuh akun mencakup Masuk → Beranda → Profil → Beranda. Pengurus biasa juga membuka dan menyimpan Edit profil serta mengganti password. Kedua sisi memakai salinan snapshot sintetis yang sama, dipulihkan sebelum pengulangan.

Perekam membandingkan multiset permintaan per langkah. Catatan menyertakan metode, path, nama parameter dan bidang JSON serta HMAC atas path lengkap, nilai query dan badan JSON kanonik. ID instalasi web yang bernilai `web` turut dibandingkan apa adanya. Token autentikasi, password polos dan kunci HMAC tidak ditulis ke bukti. OPTIONS CORS yang dijawab oleh harness tidak masuk hitungan. Perbandingan membuktikan paritas permintaan, sedangkan hasil respons, perubahan sesi dan izin akses diuji pada regresi Profil/Impor. Percobaan awal membandingkan tambahan kode respons dan mengalami pembatalan saat konteks browser ditutup. Pengulangan memakai perbandingan permintaan dan kontrol identik. [Baseline dan kontrol](audit-codex/jaringan/banding-kontrol.txt) · [Baseline dan fase akhir](audit-codex/jaringan/banding-fase.txt) · [Expo web akhir](audit-codex/expo-web-akhir.txt).

### Regresi aplikasi

| Suite Expo web | Lulus / gagal |
| --- | ---: |
| Profil/Impor Fase 2 | 105 / 0 |
| Keamanan Fase 4 | 46 / 0 |
| Jadwal kelas gabungan Fase 2 | 57 / 0 |
| Pencarian absensi | 53 / 0 |
| Pencarian daftar santri | 24 / 0 |
| Perbaikan peserta absensi | 18 / 0 |
| V6 Fase 4 | 45 / 0 |
| V7 Fase 4 | 48 / 0 |
| V5 Fase 4 | 78 / 0 |
| V5 Fase 5 | 36 / 0 |
| **Total** | **510 / 0** |

Regresi luas dijalankan pada koreksi awal. Koreksi lanjutan mengubah ukuran target isian/pil dan warna sukses. Source akhir diverifikasi kembali dengan suite fase penuh, probe, unit, paritas jaringan, uji native, dan Profil/Impor **105/0**. Putaran ulang Profil/Impor tidak ditambahkan ke total 510. Server API regresi melaporkan nol galat PHP. Regresi Profil/Impor menguji foto, validasi dan akses edit, password sementara, ganti password, sesi kedaluwarsa, serta server lama. [Ringkasan regresi](audit-codex/regresi.txt).

### Kontrol suite gelombang pertama

Oracle asli dipertahankan. Hanya port loopback di salinan uji yang disesuaikan. Pada fixture sintetis saat audit, baseline dan handoff sama-sama berhenti pada penanda “Akun ini belum memiliki menu” (suite Fase 1) dan “Belum diabsen” (suite Fase 2). Suite Fase 3 menghasilkan **17 lulus / 4 gagal** pada kedua versi dengan empat pemeriksaan yang sama, yaitu rekap rentang laporan serta label peran di Profil admin, orang tua dan guru. Hasil tersebut tidak dihitung sebagai kelulusan regresi.

### Simulator dan emulator

- **Android** memakai AVD Medium_Phone Android 16/API 36 dan APK **1.5.0 Debug** baru dari `expo prebuild` + `gradlew --offline assembleDebug`, dengan JavaScript dari Metro pada salinan akhir. `dumpsys` mengonfirmasi `versionName=1.5.0`, `versionCode=1` dan flag Debug. Emulator memakai reverse port untuk Metro/API. Percobaan awal dengan binary Debug lama 1.0.0 gagal memuat Metro. Percobaan pertama dengan APK baru sampai ke Masuk, tetapi ADB menjatuhkan sebagian karakter username. Pengulangan memasukkan karakter dengan jeda dan memeriksa username sebelum mengirim. Pemeriksaan produk tetap sama. Tujuh peran **35 lulus / 0 gagal**, dan bagian B/C/D **18 lulus / 0 gagal**. [Log tujuh peran Android](audit-codex/android-tujuh-peran.txt). Bagian B membuktikan tombol ←, tombol kembali Android, dan tautan saat aplikasi berjalan. Bagian C menguji mode gelap pada skala sistem 2,0×, termasuk logo 27,8 dp dan keterjangkauan layar akun. Bagian D menguji Masuk dan Ganti password wajib. Roda gigi mengambang pada tangkapan berasal dari dev client. [Log Android](audit-codex/android-kembali-gelap-2.0-wajib.txt) · [Beranda Android gelap 2,0×](audit-codex/android-beranda-gelap-teks-2.0-gulir.jpg) · [Ganti password Android gelap 2,0×](audit-codex/android-ganti-password-gelap-teks-2.0.jpg).
- **iOS** memakai iPhone 17 Pro simulator iOS 26.2. Auditor mengulang tujuh peran dan alur Kembali pada binary handoff **Release 1.5.0 arm64 simulator**, dengan bundel Hermes tertanam dan API lokal. [Log tujuh peran iOS](audit-codex/ios-tujuh-peran-release-handoff.txt). Alur Kembali menguji geser tepi dan dua tautan ketika aplikasi ditutup. [Log Kembali Release handoff](audit-codex/ios-kembali-release-handoff.txt). Sesudah koreksi, binary Release yang sama disalin dan ditandatangani ulang secara ad-hoc dengan bundel Hermes v98 yang dibangun auditor dari source uji. Pada bundel koreksi awal alur Kembali lulus lagi. Pada bundel source akhir, empat layar Beranda, Profil, Edit profil dan Ganti password dibuka dan diperiksa di mode **gelap + AX-XXXL**. Sapaan Beranda membungkus pada skala ekstrem dan label isi bisa digulir. Alur password wajib pertama mengalami timeout XCTest, kemudian lulus pada bundel source akhir setelah logout melalui UI. [Log password wajib](audit-codex/ios-wajib.txt) · [Log iOS gelap AX-XXXL](audit-codex/ios-gelap-ax-xxxl.txt) · [Beranda](audit-codex/ios-beranda-gelap-ax-xxxl.jpg) · [Profil](audit-codex/ios-profil-gelap-ax-xxxl.jpg) · [Edit profil](audit-codex/ios-edit-profil-gelap-ax-xxxl.jpg) · [Ganti password](audit-codex/ios-ganti-password-gelap-ax-xxxl.jpg).

### Batas audit

Perangkat fisik, build distribusi EAS, iOS Debug, APK rilis, Push nyata, cPanel/produksi, dan UAT berada di luar hasil audit lokal ini. Layar akun Fase 1 diuji lewat rute langsung dan URI. Uji native dari notifikasi Push yang sebenarnya belum dilakukan. Android dev client membuktikan URI ketika aplikasi berjalan. iOS Release handoff membuktikan URI saat aplikasi berhenti. Uji native akhir iOS memakai binary Release handoff dengan bundel akhir, sehingga hasilnya tidak menggantikan build Xcode Release utuh dari source akhir.

K-4–K-8 diperiksa sesuai cakupan Fase 1. Pratinjau membuktikan grafik batang berbasis `View`, inisial santri, kepala stack dan lembar tindakan serta FAB. Masuk langsung ke formulir tanpa onboarding. Jadwal, Perizinan, dan pemakaian FAB untuk pengajuan berada di luar layar Fase 1. Pengecualian jalur berkas §6 mencakup versi `app.json`, helper navigasi/presentasi dan pengujian handoff, serta atribut baca-saja cache notifikasi untuk ringkasan K-3. Tidak ada perluasan perilaku server.

Keputusan lanjut tetap pada Human Developer setelah temuan desain dan batas penerimaan ditinjau. Fase 2, merge, deploy dan rilis belum dimulai.
