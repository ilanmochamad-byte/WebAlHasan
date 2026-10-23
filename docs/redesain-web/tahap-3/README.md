# Redesain website Tahap 3 — halaman lama ke kerangka baru (23 Oktober 2026)

Status terbaru: **BELUM LULUS audit independen Codex 23 Oktober 2026** atas implementasi Claude `e626313`; dua temuan P2 pada penangguhan worker/retry WhatsApp dan validasi alasan batal lunas di server. Lihat [`AUDIT-CODEX.md`](AUDIT-CODEX.md) dan bukti independen `audit-codex/`. Menunggu koreksi Claude dan audit ulang. Catatan implementasi serta hasil pengujian Claude di bawah dipertahankan sebagai riwayat handoff. Belum di-merge, belum dideploy, tanpa migrasi database. Tahap 4 belum dimulai.

- Branch `claude/redesain-web-tahap-3` dibuat dari `429f9ef` (ujung `claude/redesain-web-tahap-2` yang lulus audit ulang Codex). `origin/main` `bc67e2e` baru memuat Tahap 2 sampai `964e21f` (tanpa koreksi `e9b5b8c` dan audit ulang `429f9ef`), sehingga Tahap 3 dibuat dari commit audit itu, sama seperti pola Tahap 2. Tidak ada worktree.
- Sumber: `AGENTS.md`, `rencana.md`, `keputusan.md`, `cetak-biru.html` (N-2, N-4, N-5, K-5 s.d. K-8, W-1 s.d. W-4, baris Tahap 3 di "Urutan tahap pengerjaan"), `tahap-2/AUDIT-ULANG-CODEX.md`.
- Cakupan keputusan: AH, AI, AL. AG (pratinjau berkas) sengaja **tidak** dipasang sesuai cetak biru ("K-5 s.d. K-8 tanpa pratinjau berkas").
- Kriteria selesai: *tidak ada lagi `sidebar.php` dan `alert()`; daftar berhalaman; WhatsApp tidak dapat dinyalakan.*

## Hasil terhadap kriteria

| Kriteria | Bukti |
| --- | --- |
| Tidak ada lagi `sidebar.php` | `admin/sidebar.php` dihapus beserta `Navigation::activeKey()`; tidak ada kode yang memuatnya (S3-2). Uji browser Tahap 1 atas seluruh menu sembilan akun × tiga lebar tidak lagi mencatat satu pun halaman "belum memakai Layout". |
| Tidak ada lagi `alert()` | Tidak ada `alert(` di kode `admin/`, `portal/`, `app/`, `assets/ui/` (S3-3, di luar komentar dan pustaka `SimpleXLSX*`). Hasil tindakan tampil sebagai pesan/toast (POST/Redirect/GET); ekspor dibuat server; impor memakai wizard. Uji browser memastikan tidak ada `confirm()`/`alert()` bawaan pada alur Tahap 3. |
| Daftar berhalaman | Pendaftar dan pembayaran 20 baris; berita dan unduhan 20; galeri 24 foto; arsip pelanggaran 25; pengiriman gagal 20; riwayat kanal 20 (baru berhalaman). DataTables dibuang. |
| WhatsApp tidak dapat dinyalakan | `NotificationChannel::DITANGGUHKAN = [WHATSAPP]`; `NotificationAdminService::ubahSakelar()` menolak menyalakan (409) dan `kirimPesanUji()` menolak pesan uji, sehingga panel web **dan** API tertutup, bahkan bila pemeriksaan konfigurasi berstatus Lulus. Panel juga tidak menjalankan worker WhatsApp. Kartu tampil "Ditangguhkan" tanpa Nyalakan, Kirim uji, atau Jalankan worker; Periksa konfigurasi tetap di ⋯ (AL). |

## Perubahan per layar

| Layar | Berkas | Isi |
| --- | --- | --- |
| N-2 Kanal notifikasi | `admin/admin_notifikasi.php`, `app/Notification/NotificationChannel.php`, `NotificationAdminService.php`, `SettingsRepository.php` | Di kerangka bersama. Tab Kanal · Pengiriman gagal (lencana jumlah) · Riwayat. Kartu per kanal dengan satu tombol utama, sisanya di ⋯. Mematikan kanal meminta konfirmasi. Pengiriman gagal: saringan kanal, "Coba ulang" per baris, dan "Coba ulang yang dipilih" yang diproses satu per satu dengan bilah kemajuan (`AlHasan.bulk`; tanpa JavaScript server memproses daftar yang sama berurutan, paling banyak 100). Riwayat audit kanal berhalaman. Hanya NAMA environment yang tampil. |
| N-4 Ringkasan administrasi | `admin/admin_dashboard.php`, `app/Beranda/RingkasanAdministrasi.php`, `app/Ui/Chart.php` | Tab Beranda › Ringkasan administrasi dengan segmen PSB · Santri & asrama · Kegiatan. Grafik dirender server tanpa Chart.js (batang, donat `conic-gradient`, garis SVG), warna dari token, setiap grafik punya keadaan kosong. "Santri aktif" kini santri berstatus aktif dan tidak diarsipkan. Kegiatan: ringkasan pertemuan & kehadiran (rentang tanggal, sumber laporan yang sama), kehadiran 10 pekan, izin bulan ini, pelanggaran bulan ini. |
| N-5 Data sistem lama | `admin/admin_pelanggaran.php`, `app/V3/KatalogService.php` | Tetap baca-saja (PRD V3 §5.2a; 405 untuk hapus/catat lama). Ditambah pencarian nama santri dan saringan tahun (parameter terikat), rujukan ke Pembinaan, tabel menjadi kartu di HP. |
| K-5 Pendaftar | `admin/admin_data.php`, `proses_psb_admin.php`, `hapus_siswa.php`, `export_psb.php`, `app/Psb/PsbAdmin.php`, `PsbException.php` | Chip status berangka (Semua · Baru · Diterima · Cadangan · Ditolak · Dimigrasi), saringan (cari nama/no. daftar/NISN, unit, L/P, kecamatan), daftar 20 baris. Satu panel per pendaftar (Biodata · Berkas n/6 · Riwayat) menggantikan modal per baris dan kolom lima ikon. Keputusan di panel: Tolak (dialog merah), Cadangan, Terima; Diterima → Migrasi (dialog). ⋯: Cetak bukti, Hapus pendaftar (dialog **ketik nama**). Tambah pendaftar di panel dengan pesan per isian. Impor Excel tiga langkah: pilih berkas → **diperiksa di server tanpa menyimpan** (baris siap, baris dilewati beserta alasan) → Simpan dengan token sekali pakai. Ekspor .xlsx dibuat server sesuai saringan. Alur status, migrasi, dan pola NIS tidak berubah. |
| K-6 Pembayaran | `admin/admin_pembayaran_psb.php`, `export_pembayaran.php`, `app/Psb/PsbPembayaran.php` | Ringkasan (Belum lunas, Lunas, Kas masuk), chip Belum lunas · Lunas · Semua, saringan, daftar 20 baris dengan tombol Rincian. Panel rincian (biaya pilihan, biaya wajib, total) dengan Tandai lunas (dialog) dan Cetak kwitansi; Batal lunas di ⋯ dengan alasan yang dicatat di `audit_logs`. |
| K-7 Rekap keuangan | `admin/admin_rekap_keuangan.php` | Segmen Lunas · Belum lunas · Semua; matriks komponen × unit × putra/putri dengan kolom komponen lengket; di HP menjadi lipatan per unit. Aturan pengelompokan lama tetap. Ekspor Excel dari server. |
| K-8 Rekap jumlah santri | `admin/admin_rekap_santri.php` | Tab Data santri › Rekap jumlah. Tabel per kelas (PA/PI digabung seperti dulu) dengan baris total tebal, baris "Belum ditempatkan" yang menaut ke Penempatan, grafik santri per jenjang dan rasio L/P. Unduh Excel dari server. |
| W-1 Berita | `admin/admin_berita.php` | Tab Konten website › Berita. Cari judul, saringan bulan, 20 per halaman, gambar mini, menu ⋯ berlabel (Ubah, Lihat di situs, Hapus dengan dialog menyebut judul). Tanpa status dan kategori (AH). |
| W-2 Penyunting berita | `admin/tambah_berita.php`, `edit_berita.php`, `_berita_form.php`, `proses_berita.php` | Di kerangka admin (sebelumnya tanpa menu). Isi di kiri; kartu Simpan (tanggal, Pratinjau, Simpan & tampilkan) dan Gambar sampul di kanan; HP menumpuk dengan tombol menempel di bawah. Pratinjau menampilkan berita seperti di situs sebelum disimpan. Validasi server dengan pesan per isian dan isian dipertahankan. Penyunting Summernote yang sama; tanpa CDN tetap kotak teks biasa. |
| W-3 Galeri | `admin/admin_galeri.php` | Kisi foto terbaru lebih dulu, 24 per halaman, tanpa album (AI). Beberapa foto sekaligus lewat zona tarik; setiap foto tetap satu baris galeri (judul awal dari nama berkas). Klik foto → panel ubah judul/keterangan atau hapus (dialog). |
| W-4 Unduhan | `admin/admin_download.php` | Tabel judul · jenis · ukuran · tanggal unggah, cari, 20 per halaman. Unggah di panel (judul + berkas); ⋯ Unduh dan Hapus (dialog). Judul tab "Admin Galeri" yang keliru hilang bersama kerangka lama. |

Komponen bersama baru (`app/Ui/functions.php`, `app/Ui/Chart.php`, `app/Konten/Unggahan.php`, `assets/ui/alhasan.css`, `alhasan.js`): `ah_stats`, `ah_chips` (chip berangka), `ah_filter_open/close` (saringan sebaris di layar lebar, terlipat di HP kecuali ada saringan aktif, selalu terbuka tanpa JavaScript), `ah_wants_json`/`ah_json`, `ah_xlsx_download` (Excel di server; teks ditulis mentah sehingga tidak dibaca sebagai rumus), `data-confirm-match` (ketik nama untuk konfirmasi), zona unggah `data-ah-drop`, formulir massal `data-ah-bulk`, kisi foto, matriks lengket, lipatan, kartu kanal, tata letak penyunting, langkah wizard.

## Penafsiran dan batas cakupan — mohon dinilai auditor

1. **Chip "Semua" pada Pendaftar = belum dimigrasi**, mempertahankan perilaku daftar lama ("secara default sembunyikan pendaftar yang sudah dimigrasi"); yang sudah dimigrasi dibuka lewat chip Dimigrasi. Gambar K-5 menjumlahkan Dimigrasi ke Semua, tetapi angka gambar hanya contoh. Ekspor kini mengikuti saringan daftar yang sama (ekspor lama selalu menyertakan Dimigrasi dan mengabaikan pencarian).
2. **Penguncian WhatsApp di layanan.** Karena kriteria berbunyi "tidak dapat dinyalakan", kuncinya ditempatkan di `NotificationAdminService` (berlaku untuk panel dan API), bukan hanya di tampilan. Yang tetap boleh: mematikan (termasuk bila basis data lama sudah menyala), pemeriksaan konfigurasi (tidak mengirim), dan uji coba worker tanpa kirim lewat API (kontrak V2 Fase 4 KA-6n). Membuka kunci di kemudian hari cukup mengosongkan `NotificationChannel::DITANGGUHKAN` setelah keputusan Human Developer. Dispatcher/cron tidak diubah.
3. **Hunian asrama putra/putri (N-4).** Kamar belum punya kolom asrama (keputusan AE, Tahap 6), jadi hunian dihitung untuk seluruh kamar dan penghuni dirinci per L/P, ditambah peringatan jumlah kamar terisi di atas 90%. Kehadiran 10 pekan memanggil ringkasan laporan yang sama 10 kali (satu per pekan).
4. **Riwayat pendaftar (K-5).** Tidak ada tabel riwayat status PSB; tab Riwayat menampilkan tanggal daftar, status sekarang, dan tagihan/pembayaran, dengan catatan bahwa perubahan status lama tidak bertanggal.
5. **Berkas pendaftar tanpa pratinjau (AG).** Tab Berkas hanya menautkan berkas di tab baru, sama seperti modal lama; tidak ada `<img>` berkas PSB. Pratinjau menunggu tahap perbaikan keamanan PSB.
6. **Impor diperiksa server (K-5).** Cetak biru meminta "wizard seperti D-4"; pemeriksaan tanpa menyimpan dikerjakan untuk impor PSB saja (keputusan AC untuk impor santri tetap Tahap 6). Berkas .xlsx dibaca `SimpleXLSX` di server (paling besar 5 MB, 1.000 baris), hasil pemeriksaan disimpan di sesi satu jam. Susunan kolom, nomor pendaftaran, NISN sementara, dan aturan "baris gagal tidak menggagalkan baris lain" sama dengan impor lama. `admin/proses_import_psb.php` (titik akhir JSON lama) dibiarkan tanpa perubahan untuk kompatibilitas, tetapi tidak lagi dipanggil antarmuka.
7. **Ketik nama untuk hapus pendaftar** adalah pengaman tampilan; `hapus_siswa.php` tetap menerima POST ber-CSRF yang sama (kontrak perbaikan mutasi V1).
8. **Alasan batal lunas** wajib di dialog dan dicatat di `audit_logs` (`psb.pembayaran.batal_lunas`), tetapi tidak diwajibkan server agar kontrak POST lama tetap berlaku. Ubah status pendaftar dan tandai lunas sengaja tidak menambah baris audit (probe audit Codex mutasi V1 memeriksa bahwa mutasi sah tidak mengubah tabel lain).
9. **Hasil tindakan lewat pengalihan.** `proses_psb_admin.php` (status, migrasi) dan `admin_pembayaran_psb.php` (lunas, batal) kini menjawab 302 ke halaman asal beserta saringannya (kunci di luar daftar dibuang), bukan 200 berisi skrip `alert()`/`window.location`. Galat basis data migrasi dicatat di log server, tidak ditampilkan mentah.
10. **Unggahan dibatasi.** Folder tujuan tetap sama dengan halaman lama. Ditambahkan: daftar jenis yang diizinkan (gambar: JPG/PNG/WEBP/GIF; dokumen: PDF, Office/ODF, TXT, ZIP, JPG, PNG), pemeriksaan isi gambar, batas ukuran (5 MB gambar, 20 MB dokumen), dan nama asli dibersihkan dari karakter jalur. Folder `admin/file_download/` dibuat bila belum ada. Ini dapat menolak jenis berkas yang dulu diterima; mohon dinilai apakah daftar jenis dokumen cukup.
11. **Folder gambar berita** (catatan audit perbaikan mutasi V1) tidak diselaraskan: unggahan tetap ke `gambar/` (folder yang dibaca situs), hapus tetap mencari folder lama. Gambar mini dan sampul di admin kini ditampilkan dari `gambar/` (folder tempat unggahan benar-benar berada). Saat sampul diganti, nama gambar lama diambil dari basis data (bukan dari isian formulir) dan dihapus dari folder yang sama dengan proses hapus.
12. **Galeri:** judul tiap foto diambil dari nama berkas; bila hanya satu foto, judul isian dipakai. Keterangan isian berlaku untuk semua foto dalam satu unggahan.
13. `confirm()` bawaan masih ada di halaman di luar cakupan Tahap 3 (data master, akun, pengajian, tahun ajaran); halaman itu dipindah pada tahapnya masing-masing.
14. Tidak ada perubahan skema/migrasi, hak akses, peta menu, aplikasi mobile, atau situs publik.

## Pengujian yang disesuaikan

Harapan lama yang menyebut berkas/teks yang memang diganti Tahap 3 diperbarui tanpa melemahkan maksudnya:

| Uji | Perubahan |
| --- | --- |
| `perapihan_static`, `redesain_web_tahap2_static`, `v2_phase1_static`, `v2_phase2_static`, `v2_phase4_static` | `admin/sidebar.php` keluar dari daftar lint; asersi "sidebar tanpa guard admin" diganti "sidebar sudah pensiun dan navigasi tidak memuatnya". |
| `get_mutasi_v1_static` | Formulir POST boleh berasal dari menu ⋯ bersama (`ah_menu` dengan butir `action`, yang selalu menyisipkan `Csrf::input()`). Pembacaan `?id=` tampilan di halaman pembayaran/galeri memakai `filter_input` sehingga pola "tanpa `$_GET['id']`" tetap berlaku. |
| `get_mutasi_v1_http`, `get_mutasi_v1_audit` | Tombol keputusan diperiksa di panel `?id=`; status/migrasi/lunas menjawab 302 (PRG), pesan migrasi diperiksa di halaman tujuan. Penolakan GET/HEAD/PUT/DELETE 405, CSRF 419, non-admin 403, injeksi, dan preservasi tabel tidak diubah. |
| `browser/uji-get-mutasi-v1.mjs` | Ditulis ulang untuk panel dan dialog bersama: Batal tidak mengirim POST, Tolak/Migrasi/Hapus/Tandai lunas/Batal lunas lewat dialog, ketik nama salah ditolak, alasan batal ikut terkirim, tidak ada dialog bawaan peramban. |
| `redesain_web_tahap2_http`, `redesain_web_tahap2_audit` | Id tabel daftar pendaftar `daftar-pendaftar`; kartu notifikasi gagal membuka `admin_notifikasi.php?tab=gagal`. |
| `v2_phase4_web_smoke` | Judul kalimat "Kanal notifikasi"; "Audit perubahan kanal" dicari di panel beserta tab Riwayat-nya. |

## Bukti

Lingkungan: PHP 8.4, Node 26, MariaDB 12 lokal, Chromium Playwright; Bootstrap/Font Awesome dari `tests/browser/node_modules` (CDN lain, termasuk jQuery/Summernote, diblokir di uji browser). DB: `tests/redesain_web_tahap3_db.sh` membangun salinan Tahap 2 bernama `test_redesain_t3_test` lalu fixture sintetis `tests/redesain_web_tahap3_seed.php` (30 pendaftar, 25 tagihan, 25 berita, 30 foto, 22 dokumen, 30 pelanggaran lama, 24 pengiriman gagal, 25 audit kanal, isian bertag HTML untuk uji escape), dan salinan kosong `test_redesain_t3_kosong_test`. Fixture tidak membuat berkas di folder unggahan; berkas yang dibuat uji unggah dihapus di akhir.

| Bukti | Hasil | Berkas |
| --- | --- | --- |
| `php tests/redesain_web_tahap3_static.php` | **145/0** | `uji-statis.txt` |
| `php tests/redesain_web_tahap3_http.php` (server sendiri, DB Tahap 3 + kosong) | **157/0** | `uji-server.txt`, `persiapan-db.txt` |
| `node tests/browser/uji-redesain-web-tahap3.mjs` (20 layar × 375/768/1280 + interaksi) | **76/0** | `uji-browser.txt` |
| Regresi di DB sandbox `.env`: 23 suite statis (21 exit 0, 2 gate DB V3 exit 77 seperti baseline), `php -l` 457 berkas, smoke HTTP 593/0 (perapihan 56, alumni 61, kredensial 51, penempatan 47, penugasan 85, V2 Fase 2/4/5 36/46/79, API Fase 4 92, navigasi murobi 40), integrasi V2 Fase 2/4, perapihan, penugasan 94/122/53/161, browser perapihan 56/0, browser Tahap 1 91/0 | lulus | `regresi.txt` |
| Perbaikan mutasi V1 dari salinan sumber di luar Git: HTTP 89/0, probe audit Codex 84/0, browser 35/0; cleanup HTTP/preservasi 156/0 (153/3 sebelum backfill V2, identik dengan baseline `429f9ef` pada DB yang sama) | lulus | `regresi.txt` |
| Tahap 1–2: statis Tahap 2 121/0, HTTP 191/0, probe audit 29/0, browser Tahap 2 89/0; browser Tahap 1 seluruh menu sembilan akun × tiga lebar 91/0 **tanpa satu pun halaman "belum memakai Layout"** | lulus | `regresi.txt` |
| Banding status HTTP 80 alamat menu, baseline `429f9ef` vs branch pada DB yang sama | 0 beda, 0 melebar | `banding-baseline.tsv` |
| Tangkapan layar terpilih (Pendaftar, panel, kanal, ringkasan, rekap, galeri, penyunting; tiga lebar) | — | `tangkapan/` |

## Belum dijalankan / batas

- Hanya Chromium. Safari/iOS, Firefox, perangkat fisik, pembaca layar, dan axe belum dijalankan.
- Penyunting Summernote tidak dimuat di uji browser (CDN diblokir); yang diuji adalah kotak teks cadangan dan Pratinjau. Tampilan Summernote di dalam kerangka baru diperiksa hanya secara kode.
- Unggah berkas besar mendekati batas, impor 1.000 baris, dan kinerja ringkasan pada data produksi belum diukur.
- Tidak ada smoke cPanel/produksi, tidak ada push fisik.

## Handoff ke auditor (Codex)

Mohon audit Tahap 3 terhadap N-2, N-4, N-5, K-5 s.d. K-8 (tanpa pratinjau berkas), W-1 s.d. W-4, keputusan AH, AI, AL, dan kriteria selesai di atas, termasuk butir "Penafsiran dan batas cakupan". Jalankan dari root repo:

```bash
bash tests/redesain_web_tahap3_db.sh
php tests/redesain_web_tahap3_static.php
php tests/redesain_web_tahap3_http.php
bash tests/redesain_web_tahap3_db.sh
DB_NAME=test_redesain_t3_test php -S 127.0.0.1:8951 tests/redesain_web_tahap1_router.php &
BASE_URL=http://127.0.0.1:8951 node tests/browser/uji-redesain-web-tahap3.mjs
BASE_URL=http://127.0.0.1:8951 node tests/browser/uji-redesain-web-tahap1.mjs
```

Tahap 4 tidak dimulai sebelum Tahap 3 lulus audit dan ada instruksi Human Developer.
