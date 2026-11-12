# Audit Codex — Redesain aplikasi gelombang 2, Fase 3 Keuangan/BSA

13 November 2026 · Branch kedua repo `claude/redesain-tampilan-v2-fase-3`

**Keputusan audit: belum lulus penuh.** Ketukan notifikasi sistem Keuangan tanpa riwayat dan kartu pengalih iOS masih memerlukan bukti independen. Perbedaan terhadap §4.9 dicatat di bawah.

## Baseline dan ruang lingkup

| Repo | Baseline `origin/main` | Handoff |
| --- | --- | --- |
| WebAlHasan | `1c73af13ee75a90c8885db03dd689ab3c5bb9095` | `7c8a704997db7309815ec46b91420bd536224f99` |
| alhasanApps | `50d2842f18d6b9598fedb9c065195761679eda14` | `7541b8937e155cfdf1a3fc2eb9b06924978f096a` |

Kedua SHA diverifikasi langsung dengan `git ls-remote`. Pohon baseline sama dengan akhir audit Fase 2 (`40be350` web dan `749384a` mobile). Sumber audit mencakup AGENTS.md kedua repo, PRD-V6, [rencana §4.9 dan §5](../rencana.md), [audit Fase 2](../fase-2/AUDIT-CODEX.md), [handoff dan bukti Fase 3](README.md), serta diff satu commit implementasi pada tiap repo. Diff web berisi dokumen dan pengujian. Pemeriksaan sumber memastikan klien API Keuangan, penjaga mutasi, penjaga resource privat, kode server, dan migrasi sama dengan baseline. Manifest dan lockfile mobile ter-commit identik dengan baseline. Versi minor tunggal **1.6.0 → 1.7.0** lulus. Edit paket lokal dan direktori web belum terlacak milik pekerjaan lain berada di luar commit.

## Koreksi dan penilaian

- **K-10 iOS.** Pada iPhone 17 Pro, tautan kuitansi saat aplikasi tertutup membuka kuitansi, tetapi satu ketukan ← gagal mencapai Beranda pada handoff. Tujuan BSA yang tertunda kini menggunakan navigasi yang dapat memakai layar tujuan aktif. Alur identik pada bundel akhir mencapai Beranda dengan satu ketukan. Penyedia notifikasi juga mencegah listener dan hook meneruskan respons ketukan BSA dengan ID sama dua kali.
- **Ikon pembatalan.** Baris pembayaran Dibatalkan dalam invoice memakai silang dan teks status. Kuitansi transfer Menunggu memakai jam, kuitansi sah memakai centang. Tidak ada tindakan Bagikan baru. Tombol Cetak / Simpan PDF tetap tersedia di `portal/bsa_kuitansi.php` dan `portal/bsa_tabungan_kuitansi.php`.
- **Nominal dan peran.** Angka profil, invoice, pembayaran dan laporan yang diperiksa cocok dengan API atau penjumlahan komponen yang ditentukan. Penerimaan kas baru dan perpindahan internal ditampilkan terpisah. Petugas dan admin memperoleh menu tanpa ringkasan angka karena respons akses hanya berisi hak. Strip sisa tagihan menampilkan tanda kosong bila daftar menyentuh batas server 60 baris. Admin baca-saja. Data wali dibatasi relasi aktif dan hilang setelah pemeriksaan ulang ketika relasi dicabut.
- **Penafsiran §4.9.** Diterima untuk audit teknis bahwa setoran transfer diputus pada kartu, kuitansi sukses tetap di atas formulir transaksi, grafik muncul saat ada minimal dua bulan, dan aksi formulir ikut gulir pada skala besar agar bisa dijangkau. Selisih desain yang masih terlihat meliputi tombol **Bayar dari tabungan** yang berada di bilah aksi, ubin **Mutasi tabungan** pada menu wali yang digantikan akses lewat rincian anak, serta pratinjau satu santri berbentuk kartu rincian tarif. Hal tersebut tetap dicatat sebagai selisih terhadap rencana yang disetujui.

## Uji independen

Salinan arsip Git tanpa `.env*`, Expo 57.0.15 dari lockfile melalui `npm ci --offline`, `EXPO_NO_DOTENV=1` dan `EXPO_OFFLINE=1`. Expo web, browser dan API pada `127.0.0.1` dengan fixture sintetis MariaDB. Playwright memblokir host lain. Setup TypeScript awal dari arsip gagal pada deklarasi CSS yang dibuat Expo. TypeScript lulus setelah Expo menghasilkan deklarasi tersebut, dan lint lulus. Unit baseline **97/0**, fase dan koreksi **104/0**.

| Suite | Handoff atau koreksi | Baseline |
| --- | ---: | ---: |
| V6 Fase 4 | 45/0 | 45/0 |
| V6 Fase 5 | 20/0 | 20/0 |
| Penerbitan satu santri, bundel koreksi | 27/0 | 27/0 |
| Penjaga pratinjau satu santri | 3/0 | 3/0 |
| F-1, pencabutan di tengah formulir | 16/0 | 16/0 |
| V-1, respons/pesan basi | 5/0 | 5/0 |
| Matriks tampilan Fase 3, handoff | 258/0 | — |

Alur notifikasi dalam aplikasi membuka tagihan anak yang tepat. Matriks web menguji 19 varian rute, ← ≥ 44 pt, navigasi dengan dan tanpa riwayat, status, peran, nominal, serta lebar 375/768/1280 px dan tema gelap. V6 Fase 4 membuktikan ketukan ganda dan retry setelah jawaban hilang menghasilkan satu kredit dengan kunci yang sama. Uji V6 Fase 4/5 serta satu santri mencakup sesi 401, pencabutan 403 dan ruang lingkup 404. Pada putaran awal penerbitan satu santri, Playwright menutup konteks saat permintaan profil masih berlangsung. Penutupan kini menunggu handler selesai tanpa mengubah assertion. Kode baseline dan bundel koreksi kemudian lulus 27/0.

## Paritas jaringan

Baseline dan bundel akhir memakai dump sintetis yang sama. Hasilnya **38 langkah sama, 0 berbeda, 188 permintaan**, dengan nol permintaan keluar dari `127.0.0.1`. Rekaman baseline, handoff dan bundel akhir juga identik per byte. Pembanding memeriksa metode, path beserta ID literal, query dan badan JSON kanonik per langkah. Kunci idempotensi acak dan sandi uji disamarkan. Preflight dan header otorisasi tidak masuk rekaman.

Perekam mencakup setoran tunai/transfer, penarikan, belanja, verifikasi dan penolakan setoran, pembayaran tunai/transfer/tabungan, verifikasi/penolakan/pembatalan pembayaran, koreksi tabungan, revisi/pembatalan tagihan, penerbitan massal/satu santri, tunggakan, rekonsiliasi dan tiga periode laporan. Masing-masing dari **19 langkah mutasi** memiliki satu kunci dan satu POST. Untuk alur tambahan, DB mengonfirmasi dua pengeluaran berbeda, satu penolakan setoran dan satu penolakan pembayaran. Rekaman gagal awal akibat cache Metro bersama memuat sumber baseline yang bercampur dengan komponen fase. Rekaman akhir diulang dengan dependensi fisik dan cache Metro terpisah. [Perbandingan](audit-codex/banding-jaringan.txt) · [Asersi rekaman](audit-codex/rekam-jaringan.txt) · [Log kanonik](audit-codex/rekaman-jaringan.json).

## Native dan privasi

- **iOS simulator** iPhone 17 Pro / iOS 26.2, host native Release 1.7.0 arm64 dari handoff dengan bundel Hermes masing-masing sumber audit, ditandatangani ulang ad-hoc. Cold start URI gagal sebelum koreksi dan lulus sesudahnya. Kuitansi dan laporan lulus dalam mode gelap dengan Dynamic Type AX-XXXL. Admin hanya membaca, dan wali melihat dua anaknya tanpa nominal transfer menunggu. Satu pemindaian log proses mencakup 36.067 baris dengan nol kecocokan nama/NIS sintetis, nomor kuitansi, referensi, sandi sintetis, atau bearer mentah. Kuitansi dibuktikan tampil sebelum gestur pengalih aplikasi. Tangkapan Maestro hitam. Kartu pengalih aplikasi tidak dapat diperiksa dari bingkai hitam itu. Sistem simulator menerima injeksi notifikasi, tetapi pemberitahuannya tidak ditemukan oleh uji di pusat notifikasi sehingga ketukan sistem belum terbukti. [Cold start handoff](audit-codex/ios-dingin-handoff.txt) · [Cold start koreksi](audit-codex/ios-dingin-koreksi.txt) · [Gelap/AX](audit-codex/ios-gelap-ax.txt) · [Peran](audit-codex/ios-peran.txt) · [Log](audit-codex/ios-log-privasi.txt) · [Tangkapan](audit-codex/ios-gestur-pengalih.png).
- **Android emulator** Medium_Phone / API 36, dev client Debug 1.7.0 arm64 dari handoff dengan Metro loopback. Percobaan awal terhenti pada input login, dialog System UI dan ADB offline. Sesudah cold boot dan Metro bersih, menu BSA tampil dengan ← dan `FLAG_SECURE=1`. Kartu Recents hitam. Log proses 349 baris tanpa kecocokan penanda uji. Percobaan Metro dengan cache bersama menampilkan keadaan proteksi gagal dengan ← dan tanpa menu atau data Keuangan. Matriks auditor mencatat 25 pemeriksaan lulus: 10 langkah A-1 dan 15 langkah A-2–A-5. Putaran A-1 terhenti saat langkah A-2 karena fixture belum memiliki tagihan terbit. Setelah fixture dilengkapi, A-2–A-5 lulus tanpa mengulang setoran A-1. Ini mencakup ketukan ganda dengan satu transaksi, kembali OS dan URI, pencabutan penugasan/relasi, admin hanya baca, dan mode gelap 2,0×. [A-1](audit-codex/android-petugas.txt) · [A-2–A-5](audit-codex/android-lanjutan.txt) · [Log](audit-codex/android-log-privasi.txt) · [Recents](audit-codex/android-recents.png).
- Navigator native menunggu `preventScreenCaptureAsync` dan, pada iOS, `enableAppSwitcherProtectionAsync` sebelum memasang layar Keuangan. Saat proteksi gagal atau masih disiapkan, yang dipasang hanya kerangka dengan tombol kembali. Privasi log web diperiksa oleh suite V6.

Perangkat fisik, APNs/FCM nyata, build distribusi, cPanel/produksi dan UAT berada di luar bukti audit lokal.
