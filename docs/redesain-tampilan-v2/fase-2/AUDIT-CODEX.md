# Audit Codex — Redesain tampilan aplikasi gelombang 2 Fase 2

12 November 2026 · Branch `claude/redesain-tampilan-v2-fase-2` pada kedua repositori.

**Keputusan audit — Fase 2 belum lulus penuh.** Paritas permintaan dan regresi fungsi lulus. Letak vertikal FAB di Expo web belum memenuhi K-8 dan beberapa perbedaan tampilan terhadap rencana masih terbuka.

## Baseline dan cakupan

| Repo | Baseline `main` terverifikasi di GitHub | Handoff yang diaudit |
| --- | --- | --- |
| alhasanApps | `707b7b704fad0484170e59286530d724fa656e0f`, pohon identik dengan `8c8e025` (audit Fase 1, PR #30) | `d9aec8f51bb5e4bd1adb424399a236f273061d72` |
| WebAlHasan | `660931c0882c260b2c34b43937d09b7a75f63cbc`, pohon identik dengan `fbf9a2e` (audit Fase 1, PR #119) | `e7cd9708b5f4e420b50061dc7e3f24af3d857b6c` |

Dibaca `AGENTS.md` kedua repo, [rencana §4.2–§4.5, §4.11, K-7, K-8, K-10](../rencana.md), [audit Fase 1](../fase-1/AUDIT-CODEX.md), dan [handoff Fase 2](README.md). Ada satu commit fase per repo. Seluruh diff web berada di pengujian dan dokumentasi. Pemeriksaan AST TypeScript mendapati 74 pemanggilan `api.*`, `izinWali.*`, dan `*.run` penjaga mutasi yang sama pada baseline dan sumber akhir. Klien API, penjaga akses/versi/idempotensi, konfigurasi distribusi, skema, kode server, serta mekanisme perlindungan tangkapan layar tetap identik. Manifest dan lockfile ter-commit identik dengan baseline. Aturan versi lulus untuk satu kenaikan minor `1.5.0 → 1.6.0`. Edit lokal `package.json` dan `package-lock.json` milik pekerjaan lain serta direktori web belum terlacak berada di luar commit.

## Koreksi terarah

| Temuan | Koreksi dan bukti |
| --- | --- |
| Target tanggal Jadwal kurang dari 44 pt | Ketujuh sel pada 375 px terukur selebar **42,7 pt** di handoff. Lebar minimum memakai `TouchTarget` dan deret bisa digeser pada layar sempit. Probe yang sama mengukur lebar **44 pt** untuk ketujuhnya, dengan tinggi sedikitnya 44 pt pada sumber akhir. Pergantian pekan/rentang dan pemuatan tetap memakai alur lama. |
| Judul tab dan bulan menyusut saat skala sistem diperbesar | Pengaturan satu baris dan penyusutan font dihapus. Judul memakai skala sistem dan dapat membungkus. Ini memperbaiki perubahan komponen bersama `ScreenHeader` dan `WeekStrip` yang diperkenalkan pada Fase 2. |
| P2-3, keputusan V2 tidak membuka lembar beralasan | Setujui dan Tolak membuka lembar bawah. Membuka atau menutup lembar tanpa konfirmasi tidak mengirim keputusan. Konfirmasi memakai badan permintaan, pemeriksaan versi, hak admin pengganti, alasan wajib, dan penjaga idempotensi yang sama. Lembar menyesuaikan papan ketik iOS. |
| P2-5, label aksesibilitas FAB wali belum mengikuti K-8 | Teks FAB tetap “Ajukan izin”, dengan label aksesibilitas “Ajukan izin baru” dan teks penuh pada alternatif tombol atas. Syarat server `boleh_mengajukan` tetap dipakai. Jika server menolak pengajuan, pemberitahuan menggantikan tombol nonaktif lama. |
| K-10 saat proteksi native izin wali sedang disiapkan atau gagal | Keadaan tersebut kini memakai kepala stack dengan ←. Isi sensitif tetap baru dipasang setelah proteksi berhasil. Retry dan penggunaan `expo-screen-capture` tetap mengikuti implementasi awal. |

Pemilih pada suite browser jaringan, V7 F4, Android dan Maestro menyesuaikan letak/label tombol yang berubah. Pernyataan bisnis lama pada V7 F4 tetap berlaku. Pengujian jaringan sekarang membandingkan ID di path secara literal.

## Penilaian terhadap rencana

- **Absensi.** Draf dan penghitung memakai seluruh peserta. Pencarian nama/NIS dan tab kelas hanya memilih baris untuk ditampilkan. Operasi “Semua hadir”, simpan, koreksi dan “Perbarui daftar” tetap mencakup daftar peserta yang benar. Penjaga sinkron ketukan ganda, kunci retry, alasan koreksi, dan penyimpanan server diperiksa lewat suite kelas gabungan dan peserta absensi.
- **Hak akses.** Tampilan mengikuti capability dan tindakan dari respons server. Suite membuka rute langsung dan menguji respons server untuk akun yang berbeda, termasuk relasi wali dicabut, wali asing, pembimbing lain, dan murobi sebelum penerusan. Tombol yang tersembunyi hanya menyajikan hasil pemeriksaan server.
- **K-7/K-10.** Kepala tab memakai ← ke Beranda. Kepala stack menempatkan ← dan tindakan sekunder di luar area gulir. Pada teks besar judul stack dapat turun ke baris sendiri. Status memakai ikon dan teks, termasuk pil dengan ikon bawaan sesuai nada. Layar stack yang dibuka dari riwayat kembali ke layar sebelumnya. Tautan/rute langsung tanpa riwayat kembali ke Beranda. Lembar/formulir bertahap punya tindakan kembali ke langkah sebelumnya.
- **K-8.** FAB tetap di kanan bawah dengan ikon + teks, ruang gulir di akhir daftar dan sembunyi saat kotak cari aktif/papan ketik terbuka. Orang tua tidak melihat FAB di Perizinan V2. Wali V7 yang berhak melihat FAB pada layar stack di atas area aman bawah. Penafsiran tombol atas diterima pada skala besar, ketika estimasi lebar melampaui setengah viewport. Di Expo web bilah tab sistem berada di atas isi sejak baseline. FAB terbukti 16 px dari kanan dan dasar viewport dan tidak menutupi daftar. Posisi vertikal “di atas bilah tab” tidak tercapai pada tata letak web ini, dan luapan bilah tab 375 px tetap terlihat sejak Fase 1.
- **Penafsiran lain.** P2-1 diterima sebagai perubahan pemilih UI yang perlu. P2-6–P2-9 dan P2-12–P2-16 mempertahankan makna atau data yang sudah tersedia. P2-2 dan P2-4 memakai panel sukses dalam layar absensi/rincian dan mempertahankan label “Konfirmasi dan kirim”, sehingga rancangan layar sukses terpisah dengan “Lihat pengajuan” belum terwujud. P2-10–P2-11 mempertahankan sebagian tindakan penetapan/pembatalan dalam isi. DTO V2 hanya mengirim nama/NIS santri sehingga kamar/kelas tidak ditambahkan lewat permintaan baru. Perangkat Push masih memakai dua tombol tindakan menurut keadaan, bukan sakelar §4.11. Perubahan presentasi lintas layar yang dicatat pada P2-17 diperiksa melalui regresi terkait.

## Paritas jaringan

Salinan Git baseline dan fase diuji dengan fixture MariaDB 12.3.2 dan API PHP 8.4.14 lokal. Expo SDK 57.0.15 menggunakan dependensi dari lock yang sama, tanpa `.env*`, dengan `EXPO_NO_DOTENV=1` dan `EXPO_OFFLINE=1`. Playwright memblokir semua host selain `127.0.0.1`. Percobaan awal Expo di localhost memilih IPv6. Setelah proses dipindah ke loopback IPv4, pengujian memakai URL IPv4 yang sama dengan konfigurasi suite.

| Alur | Langkah sama / berbeda | Permintaan |
| --- | ---: | ---: |
| Jadwal, buka pertemuan, perbarui peserta, kirim dan koreksi absensi, laporan | **10 / 0** | **32** |
| Buat, tetapkan, setujui, tolak dan laporkan izin V2. Buat, perbaiki dan teruskan izin wali. Buka, tandai dan periksa notifikasi/perangkat | **44 / 0** | **198** |
| **Total** | **54 / 0** | **230** |

Setiap perekaman dimulai dari dump fixture yang sama. Perekam membandingkan multiset per langkah dengan metode, path lengkap termasuk ID, nilai query dan badan JSON kanonik. Rahasia login dan kunci idempotensi acak dinormalkan. Header otorisasi dan preflight CORS tidak dicatat. Pengujian terpisah memeriksa kunci idempotensi aktual dan hasil penyimpanan. [Pengajian](audit-codex/banding-pengajian.txt) · [Izin dan notifikasi](audit-codex/banding-izin.txt).

Probe API V2 orang tua **5/0**. GET daftar dengan parameter mode pengurus kembali ke cakupan orang tua baca-saja dengan isi persis sama, sesuai `IzinService::scopeFor()`. Ekspektasi awal 403 untuk GET itu dikoreksi setelah membandingkan isi dan cakupan. Server memberi 403 untuk pemilih santri pengurus dan dua percobaan POST izin V2 dari akun orang tua. [Probe hak](audit-codex/probe-hak-v2.txt).

## Pengujian independen

TypeScript dan Expo lint lulus pada baseline, handoff, dan sumber akhir. TypeScript dijalankan sesudah Expo membuat deklarasi CSS normal. Unit Node baseline **90/0**, handoff dan akhir **97/0**. Expo web mencakup guru, murobi, pengurus, pembimbing, admin, orang tua V2, serta wali V7. Layar fase diperiksa pada lebar 375, 768, dan 1280 px dalam tema terang dan gelap. Pembatas host melaporkan nol permintaan ke luar loopback, dan server PHP regresi tidak melaporkan galat.

| Suite | Lulus / gagal |
| --- | ---: |
| Expo web Fase 2, pengajian | **68 / 0** |
| Expo web Fase 2, izin dan notifikasi | **145 / 0** |
| Jadwal kelas gabungan | **57 / 0** |
| Pencarian santri pada absensi | **53 / 0** |
| Pencarian santri pada daftar | **24 / 0** |
| Perbaikan peserta absensi | **18 / 0** |
| V7 Fase 4 | **48 / 0** |
| Keamanan Fase 4 | **46 / 0** |
| **Total enam regresi** | **246 / 0** |

Kontrol suite asli peserta absensi dari baseline mendapat **18/0** pada baseline dan **1/2** pada kode fase. Dua kegagalan terkait pencarian tombol langsung dan timeout setelah tombol dipindah ke ⋯. Suite dengan pemilih yang mengikuti menu menghasilkan **18/0** di kedua versi. Kontrol V7 Fase 4 dengan pemilih yang kompatibel juga **48/0** pada baseline. Ketukan ganda dan retry diverifikasi lewat jumlah permintaan, kunci aktual yang sama, dan satu hasil efektif di DB. [Ringkasan uji web](audit-codex/uji-web.txt).

### iOS Simulator

iPhone 17 Pro, iOS 26.2. Pengujian menggunakan host native **Release 1.6.0 arm64** dari handoff dengan bundel Hermes v98 dari sumber auditor, ditandatangani ulang secara ad-hoc. Matriks **17 alur Maestro lulus**, termasuk enam peran yang diminta dan wali V7, tombol kembali OS/geser-tepi, URI saat aplikasi mati, mode gelap, AX-XL, dan AX-XXXL. Pengukuran FAB **4/0**. FAB Perizinan tinggi 56 pt, 16 pt dari kanan dan 20 pt di atas awal butir Liquid Glass. Pilihan tombol atas pada skala besar terbukti dapat diketuk dan isi di bawahnya tetap dapat digulir. Geometri FAB wali iOS tidak diukur karena layar melindungi hierarki saat tidak aktif. Tampil, dapat diketuk, dan geser-tepi terbukti melalui Maestro.

Bundel sumber akhir lulus uji khusus murobi. Papan ketik terbuka saat memasukkan alasan, tombol konfirmasi masih dapat dijangkau, keputusan tersimpan, dan ← dari tautan langsung kembali ke Beranda. Bundel uji terpisah membuat pemasangan proteksi gagal secara sengaja. Layar izin wali tetap menyembunyikan isi dan pengajuan, memperlihatkan ←, serta kembali ke Beranda. Bundel normal dipasang kembali. [Bukti iOS](audit-codex/uji-ios.txt).

### Android Emulator

AVD Medium_Phone, Android 16/API 36, 1080 × 2400 pada 420 dpi. Alur pengajian **13/0** menggunakan dev client **Debug 1.5.0** dari audit sebelumnya dengan JavaScript Fase 2 melalui Metro. Uji izin memakai APK **Debug 1.6.0 arm64** yang dibangun dari arsip Fase 2 dengan `expo prebuild` dan Gradle offline, lalu memuat sumber audit melalui Metro. Dua pustaka prefab yang hilang dari cache Gradle dipulihkan dari AAR lokal dengan checksum terverifikasi. Tidak ada perubahan dependensi untuk build itu.

Putaran izin awal menghasilkan **21 pemeriksaan lulus** sebelum ADB putus pada login pembimbing. Pengulangan bagian peran dan skala teks sempat tertahan oleh dialog System UI emulator. Setelah cold boot dan menutup dialog sistem, bagian lanjutan mendapat **34/0** dengan APK yang sama. Total **54 pemeriksaan unik lulus** setelah mengeluarkan pemeriksaan admin yang terulang. FAB Perizinan setinggi 56 dp dan 16 dp di atas Material 3. Papan ketik menyembunyikan FAB, paginasi dapat dijangkau, dan FAB izin wali berada 16 dp di atas area aman bawah. Pada 1,3× FAB selebar 172 dp masih muat. Pada 2,0× dalam tema terang dan gelap, tombol atas selebar daftar tampil sebelum kotak cari. [Bukti Android](audit-codex/uji-android.txt).

### Batas audit

URI saat aplikasi berhenti di iOS dan rute langsung di Expo web membuktikan navigasi tanpa riwayat. Android Debug menguji URI ketika aplikasi berjalan. Ketukan Push nyata, perangkat fisik, build distribusi EAS, iOS Debug, APK Release, cPanel dan UAT belum diuji. Tindakan dokumen diperiksa di UI dan pada permintaan terkait. Dialog cetak/bagikan sistem tidak dijalankan sampai akhir. Gerbang Fase 1 dicatat pada laporan tersendiri. Hasil audit ini berakhir pada branch fitur Fase 2.
