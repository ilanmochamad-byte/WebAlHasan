# V2 Fase 5 — Rekap Pengujian Manual dan Risiko Residual

> Dokumen ini membedakan hasil manusia yang benar-benar **LULUS** dari skenario
> yang tidak diulang. Pada 29 Agustus 2026 pemilik produk menutup Fase 5 dan
> menerima skenario tersisa sebagai risiko residual pascarilis. Butir kosong
> tetap **tidak dinyatakan lulus**.

## Pembaruan bukti 29 Agustus 2026

Bukti yang diberikan penguji menutup checklist singkat berbasis risiko:

- halaman produksi web tampil dalam cakupan admin, pengurus, murobi, dan orang
  tua; filter admin menghasilkan total 4 (semua), 3 (Diajukan), dan 1
  (Disetujui) secara konsisten;
- pada aplikasi iOS dalam cakupan murobi, filter, lembar berbagi PDF, lembar
  berbagi CSV, dan dialog cetak A4 berhasil terbuka;
- dua CSV produksi memuat 4 dan 1 hasil sesuai filter, masing-masing 30 kolom,
  BOM UTF-8, dan nol sel formula berbahaya;
- PDF fisik Android/iOS A4 lanskap memiliki margin sekitar 1 cm, nomor halaman
  benar, dan baris lengkap;
- PR mobile #6 sudah masuk `main` pada commit merge `f604149`;
- pembatalan dialog cetak iOS sudah dikoreksi agar menjadi hasil normal; enam
  uji terarah lulus dan kegagalan printer nyata tetap diteruskan;
- cron produksi berakhir sehat: perangkat aktif 1, antrean push 0, receipt
  Menunggu 0, dan tiga receipt nyata berpindah ke `Terkirim`;
- pengguna mengonfirmasi notifikasi nyata tiba sebelum 15 menit.

Yang tetap terbuka dan diterima sebagai risiko residual: matriks empat peran
pada kedua sistem operasi, akun multi-peran, Dynamic Type, offline, seluruh
skenario deep-link, penghapusan aplikasi untuk `DeviceNotRegistered`, serta uji
ulang fisik pembatalan cetak pada build berikutnya.

## 1. Push pada perangkat fisik — laporan Fase 5

Fase 4 sudah membuktikan push **tiba** pada Xiaomi 2409BRN2CY dan iPhone 17 Pro
(24 Agustus 2026). Yang belum diuji adalah bagian **baru** Fase 5: rekonsiliasi
receipt akhir terhadap Expo yang sungguhan.

**Prasyarat:** migrasi 009 sudah diterapkan, cron `--receipts` sudah dipasang,
push aktif, sedikitnya satu perangkat terdaftar.

| # | Langkah | Harapan | Hasil | Tanggal | Penguji |
| --- | --- | --- | --- | --- | --- |
| 1.1 | Buat satu pengajuan sehingga push terkirim ke murobi | `notifikasi_worker.php --status` menampilkan receipt `Menunggu` bertambah | **LULUS** | 29-08-2026 | Pemilik produk |
| 1.2 | Tunggu ≥15 menit, jalankan `php bin/notifikasi_worker.php --receipts` | Baris berpindah dari `Menunggu` ke `Terkirim` | **LULUS — 3/3 Terkirim** | 29-08-2026 | Pemilik produk |
| 1.3 | Bandingkan dengan kenyataan di perangkat | Notifikasi memang muncul di perangkat yang sama | **LULUS — dikonfirmasi tiba sebelum 15 menit** | 29-08-2026 | Pemilik produk |
| 1.4 | Hapus aplikasi dari perangkat, kirim push lagi, lalu ambil receipt | `receipt_status` = `Gagal` dengan kode `DeviceNotRegistered`; token tercabut | **TIDAK DIJALANKAN — RISIKO DITERIMA** | 29-08-2026 | Pemilik produk |
| 1.5 | Periksa panel admin | Sebaran receipt terlihat oleh admin | **TIDAK DIULANG — NON-BLOCKING** | 29-08-2026 | Pemilik produk |
| 1.6 | Periksa isi push di layar kunci | **Tidak** memuat nama santri, alasan izin, nomor telepon, atau token | **LULUS berdasarkan bukti layar kunci Fase 4** | 29-08-2026 | Pemilik produk |

Catatan penting untuk 1.4: token hanya dicabut otomatis dari receipt bila
penerima memiliki **tepat satu** perangkat aktif. Bila lebih dari satu, token
mati akan tercabut pada pengiriman berikutnya lewat tiket, bukan lewat receipt.
Ini disengaja agar perangkat yang sehat tidak ikut dimatikan.

## 2. Deep-link push — temuan terbuka Fase 4 nomor 3

Belum lengkap sejak Fase 4. Seluruh butir §2 dipindahkan menjadi regresi
pascarilis dan **tidak dinyatakan lulus**. Otorisasi server tetap dihitung ulang
ketika detail dibuka dan telah lulus pengujian lintas cakupan.

**Prasyarat:** development build atau release build EAS (push jarak jauh **tidak**
berfungsi di Expo Go sejak SDK 53), koneksi stabil, `adb reverse` tidak
diperlukan pada release build.

### Android

| # | Keadaan | Langkah | Harapan | Hasil | Tanggal |
| --- | --- | --- | --- | --- | --- |
| 2.1 | **Foreground** | Aplikasi terbuka di layar lain, kirim push, ketuk | Membuka detail pengajuan yang benar | ☐ | |
| 2.2 | **Background** | Tekan Home, kirim push, ketuk | Aplikasi kembali dan membuka detail yang benar | ☐ | |
| 2.3 | **Cold start** | Tutup aplikasi dari recent apps, kirim push, ketuk | Aplikasi terbuka dari nol dan membuka detail yang benar | ☐ | |
| 2.4 | **Cold start, tidak berhak** | Ketuk push lama untuk pengajuan di luar cakupan akun yang sedang masuk | Ditolak dengan pesan jelas, **bukan** menampilkan data | ☐ | |
| 2.5 | **Akun berganti** | Keluar, masuk sebagai akun lain, ketuk push lama | Tidak membuka data akun sebelumnya | ☐ | |

### iOS

| # | Keadaan | Langkah | Harapan | Hasil | Tanggal |
| --- | --- | --- | --- | --- | --- |
| 2.6 | **Foreground** | sama seperti 2.1 | sama | ☐ | |
| 2.7 | **Background** | sama seperti 2.2 | sama | ☐ | |
| 2.8 | **Cold start** | sama seperti 2.3 | sama | ☐ | |
| 2.9 | **Cold start, tidak berhak** | sama seperti 2.4 | sama | ☐ | |

Bila salah satu gagal, catat: keadaan aplikasi, versi build, versi OS, dan
apakah koneksi ke Metro sedang aktif. Fase 4 mencatat kegagalan yang ternyata
disebabkan `adb reverse` terputus — bedakan kegagalan lingkungan pengembangan
dari cacat aplikasi.

## 3. Laporan pada perangkat fisik — kriteria penerimaan Fase 5 nomor 9

Matriks lengkap berikut tidak dijalankan seluruhnya. Bukti singkat lintas web,
PDF, CSV, Android, dan iOS dinilai memadai untuk penutupan berbasis risiko;
kotak kosong berikut tetap menjadi regresi pascarilis, bukan klaim lulus.

| # | Peran | Langkah | Harapan | Hasil | Tanggal |
| --- | --- | --- | --- | --- | --- |
| 3.1 | Pengurus | Buka Perizinan → **Laporan perizinan** | Ringkasan, median durasi, dan daftar tampil; hanya pengajuan miliknya | ☐ | |
| 3.2 | Pengurus | Terapkan filter tanggal dan status | Total berubah sesuai filter | ☐ | |
| 3.3 | Pengurus | Ketuk **Cetak** | Dialog cetak sistem terbuka dengan dokumen yang benar | ☐ | |
| 3.4 | Pengurus | Ketuk **Bagikan PDF** | Lembar berbagi terbuka; PDF memuat identitas pesantren, filter, pembuat, waktu, dan nomor halaman | ☐ | |
| 3.5 | Pengurus | Ketuk **Bagikan CSV** | Lembar berbagi terbuka; pesan menyebut jumlah baris; berkas dapat dibuka di aplikasi spreadsheet | ☐ | |
| 3.6 | Pengurus | Buka CSV hasil 3.5 | Tidak ada sel yang dieksekusi sebagai formula | ☐ | |
| 3.7 | Murobi | Ulangi 3.1–3.5 | Hanya pengajuan yang diarahkan kepadanya | ☐ | |
| 3.8 | Admin | Ulangi 3.1–3.5 | Seluruh pengajuan; filter pengurus/murobi tersedia | ☐ | |
| 3.9 | Orang tua | Ulangi 3.1–3.5 | Hanya santri yang terhubung; tidak ada tombol mutasi | ☐ | |
| 3.10 | Akun multi-peran | Ganti cakupan lewat pemilih peran | Angka berubah sesuai cakupan tanpa login ulang | ☐ | |
| 3.11 | Semua | Bandingkan total di aplikasi dengan total di website untuk filter yang sama | **Sama persis** | ☐ | |
| 3.12 | Semua | Aktifkan Dynamic Type / ukuran teks besar | Layar laporan tetap terbaca, tidak terpotong | ☐ | |
| 3.13 | Semua | Matikan koneksi, buka laporan | Pesan offline yang dapat ditindaklanjuti, bukan layar kosong | ☐ | |

Uji pada sedikitnya **satu perangkat Android** dan **satu perangkat iOS**.

## 4. Toolchain aplikasi

Dijalankan dari akar repositori `alhasanApps` pada mesin pengembang.

| # | Perintah | Status saat ini | Catatan |
| --- | --- | --- | --- |
| 4.1 | `npx tsc --noEmit` | **BERSIH** (sudah dijalankan) | — |
| 4.2 | `npx expo lint` | **BERSIH** (sudah dijalankan) | — |
| 4.3 | `npx expo export -p web` | **LULUS — audit Codex 28 Agustus 2026** | Menghasilkan 31 rute statis termasuk `/izin/laporan`. |
| 4.4 | `npm ci` pada instalasi bersih | **LULUS — audit Codex 28 Agustus 2026** | `expo-file-system@~57.0.5` terpasang dari lockfile; dilanjutkan `tsc`, lint, dan export web yang lulus. |
| 4.5 | Build EAS development Android | **LULUS — aplikasi fisik diuji** | Skenario deep-link lengkap tetap residual |
| 4.6 | Build EAS development iOS | **LULUS — aplikasi fisik diuji** | Entitlement push diperbaiki pada `2016bf8` |

## 5. Migrasi, restore, dan cron pada cPanel

| # | Langkah | Harapan | Hasil | Tanggal |
| --- | --- | --- | --- | --- |
| 5.1 | `php -l` seluruh berkas PHP baru/diubah dengan versi PHP cPanel | Tidak ada galat sintaks | **LULUS melalui eksekusi produksi** | 29-08-2026 |
| 5.2 | `php bin/v2_phase5_preflight.php` pada produksi | Keluar 0; backup + manifest tersimpan | **LULUS — backup 47 tabel** | 28-08-2026 |
| 5.3 | Restore backup ke database `_test` cPanel | Jumlah baris cocok manifest | **LULUS** | 28-08-2026 |
| 5.4 | Migrasi 009 pada salinan `_test` cPanel | Berhasil pada MySQL versi cPanel | **LULUS** | 28-08-2026 |
| 5.5 | `php bin/v2_phase5_verify.php` pada salinan | 22 pemeriksaan lulus | **LULUS 22/22** | 28-08-2026 |
| 5.6 | Migrasi 009 pada produksi | Berhasil | **LULUS** | 28-08-2026 |
| 5.7 | `php bin/v2_phase5_verify.php` pada produksi | 22 pemeriksaan lulus; **ID dan jumlah perizinan lama tidak berubah** | **LULUS 22/22** | 28-08-2026 |
| 5.8 | Pasang cron worker push (setiap menit) | Tersimpan | **LULUS** | 29-08-2026 |
| 5.9 | Pasang cron receipt (setiap 15 menit) | Tersimpan | **LULUS** | 29-08-2026 |
| 5.10 | Tunggu 30 menit, `php bin/v2_phase5_cron_check.php` | Keluar 0; ada jejak sewa worker; tidak ada antrean tertahan | **LULUS 6/6** | 29-08-2026 |
| 5.11 | Smoke test produksi 14 langkah | Seluruhnya lulus | **DITUTUP berdasarkan bukti checklist singkat dan penerimaan risiko** | 29-08-2026 |

## 6. WhatsApp

**DITANGGUHKAN. Tidak ada butir uji yang boleh dijalankan.**

Jangan memilih provider, memasukkan credential, mengirim request nyata, atau
mengaktifkannya. Bila suatu saat pemilik produk mencabut penangguhan, seluruh
tujuh syarat pada `../phase-v2-4/whatsapp-provider-checklist.md` wajib dipenuhi
lebih dulu — tanpa pengecualian.

## 7. Cara mencatat hasil

Butir kosong dapat dijalankan pada regresi pascarilis. **Jangan** mengubahnya
menjadi `LULUS` berdasarkan dugaan; catat perangkat, versi build, tanggal, dan
hasil nyata. Penutupan berbasis risiko pada dokumen ini tidak menghapus nilai
checklist tersebut untuk rilis berikutnya.
