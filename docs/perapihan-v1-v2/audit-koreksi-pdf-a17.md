# Koreksi PDF A-17 — hasil audit Codex

Tanggal: 30 Agustus 2026. **A-17 telah dikoreksi sesuai izin pengguna.**
Sembilan PDF yang sebelumnya mencatat **42 lulus, 3 gagal**, kini mencatat
**45 lulus, 0 gagal** dengan pengujian yang sama. Seluruh rangkaian yang
dijalankan ulang pada tahap ini: **3.318 lulus, 0 gagal**.

Commit koreksi: `3f76f9c`, branch `codex/perapihan-v1-v2-ui`.
Paket belum dinyatakan lulus menyeluruh atau siap produksi. Tidak dilakukan
push, merge, deploy, migrasi produksi, atau perubahan sumber mobile.

## Mengapa sebelumnya ada tiga kegagalan

Ketiganya berasal dari **satu cacat tampilan P2** pada kolom Status yang
terlalu sempit: kata **Terlambat** membungkus menjadi **Terlamba / t**.
Assertion yang sama gagal pada tiga PDF potret: Santri, Guru, dan Gabungan.
Nilai data tidak hilang; baris laporan, margin, dan nomor halaman pada fixture
tersebut sudah benar. Renderer sebelum koreksi identik baseline `c65390d`,
sehingga cacat pra-ada ini sebelumnya menunggu keputusan pengguna.

Pengguna kemudian secara eksplisit mengizinkan koreksi kolom PDF. Koreksi
hanya pada `app/Report/PrintRenderer.php`: Status **7% → 8%**, Catatan
**16% → 15%**, jumlah seluruh lebar tetap 100%. Tidak mengecilkan huruf atau
mengubah margin. Lebar yang sama dipakai perhitungan tinggi baris dan tabel
hasil cetak, sehingga pembagian halaman tetap memperhitungkan pembungkusan.
Catatan panjang tetap boleh membungkus; jumlah halaman pada data lain dapat
berubah mengikuti kebutuhan ruang, tetapi nomor harus sesuai halaman fisik.

`PrintLayout.php`, renderer perizinan, API/service laporan, dan seluruh berkas
uji tidak berubah pada tahap ini. HTML cetak yang dipakai web dan client mobile
mendapat lebar kolom baru; skema JSON, filter, isi data, serta cakupan gabungan
default API tetap seperti baseline. Tidak perlu perubahan aplikasi mobile.

## Lingkungan dan bukti yang diperoleh sendiri

- macOS; PHP 8.4.14, MariaDB 12.3.2, Node 26.7.0, Chromium 151.0.7922.34
  melalui Playwright terkunci proyek; Poppler dan pdfplumber runtime lokal.
- Database **webalhasan_ui_codex_20260830_test**, akun fixture sandbox;
  server audit `127.0.0.1:8940`. Konfigurasi hanya pada proses audit, bukan
  konfigurasi produksi. Tidak ada dependency yang di-upgrade.
- Matriks membuat dan membersihkan data sintetis miliknya sendiri. Pengujian
  HTTP/DB dijalankan berurutan agar perubahan peran fixture tidak mengganggu
  login pengujian lain. Bukti lama dan data lama tidak dihapus.
- Main tetap `c65390dd03c4da1ddaacf9d3da9adf4293848c40`; mobile tetap
  `ab3f842` dan bersih. Tidak membuat worktree atau salinan proyek.

Semua bukti tahap ini ada pada [bukti-audit-pdf-a17](bukti-audit-pdf-a17/),
dengan `SHA256SUMS`. Log asli disimpan apa adanya, termasuk spasi akhir yang
dihasilkan runner. Ini tidak mengubah log historis `bukti-audit-14`.

| Pengujian ulang | Lulus | Gagal | Bukti |
| --- | ---: | ---: | --- |
| Regresi V1/V2, 28 berkas | 2.464 | 0 | `full-suite.log` |
| Paket perapihan, 4 berkas | 248 | 0 | `full-suite.log` |
| Browser 1440/768/390 px | 56 | 0 | `browser.log` |
| Matriks laporan: layar/CSV/cetak, oracle independen | 432 | 0 | `report-matrix.log` |
| PDF matriks: 9 dokumen, 21 halaman fisik | 45 | 0 | `pdf-matrix.log`, `pdf-matrix/` |
| API dibandingkan snapshot baseline | 13 | 0 | `api_compat.log` |
| Operasi client mobile asli, termasuk laporan/cetak | 18 | 0 | `notifikasi.log` |
| Hak akses dan cakupan laporan web guru | 38 | 0 | `laporan_web.log` |
| CSV 20.000 diterima, 20.001 ditolak | 4 | 0 | `csv_limit.log` |
| **Total tahap koreksi A-17** | **3.318** | **0** | Tanpa hitung ganda |

**175 pemeriksaan PDF lama sudah termasuk 2.464 regresi**, tidak ditambahkan
lagi. Tidak ada pengujian yang dilewati dalam runner penuh. Subtotal resmi
tetap 2.768, yaitu +290 terhadap angka implementer 2.478; penjelasan selisih
B-1 dan guard laporan tetap pada audit sebelumnya. Total 3.318 adalah cakupan
yang benar-benar diulang pada koreksi ini, bukan pengubahan hasil historis
3.892/3 menjadi angka kelulusan baru. Audit tambahan lain pada tahap sebelumnya
tidak diklaim dijalankan ulang di sini.

Sembilan HTML/PDF matriks dihasilkan ulang melalui endpoint cetak dari kode
yang dikoreksi, bukan mengedit HTML/PDF bukti lama. Data Santri 30, Guru 6,
Gabungan 36 sesuai oracle. Tiap mode diuji dengan orientasi CSS, lanskap,
dan potret. Seluruh kata status utuh; data, margin, footer bernomor, serta
ketiadaan sidebar/kontrol layar lulus pemeriksaan.

Pemeriksaan visual: seluruh 21 halaman matriks dirender menjadi PNG dan
ditinjau melalui tiga lembar kontak; halaman potret Gabungan pertama juga
diperiksa pada gambar penuh. Tidak terlihat kata status terpotong atau
menumpuk dengan Catatan. Fixture regresi absensi panjang 400 baris menghasilkan
41 halaman pada masing-masing orientasi; halaman pertama/terakhir ketiganya
ditinjau visual, dan seluruh halaman tetap diperiksa oleh regresi otomatis.
PDF, lembar kontak, serta contoh PNG disertakan sebagai bukti. Nama fixture
regresi yang menyebut Safari tetap merupakan pengujian **Chromium**, bukan
bukti eksekusi Safari nyata.

## Status penerimaan dan yang masih menunggu

Rekap seluruh 77 klaim tetap **75 TERVERIFIKASI, 0 TIDAK TERVERIFIKASI,
2 MENUNGGU VERIFIKASI** pada [matriks penerimaan](penilaian-penerimaan-codex.md).
A-17 selesai pada cakupan Chromium; tidak lagi menunggu persetujuan.

| Klaim tertunda | Langkah lanjutan | Hasil yang harus dibuktikan |
| --- | --- | --- |
| K6-14, pembaca layar | Pada Mac yang sudah dibuka kuncinya dan jendela audit khusus localhost, jalankan VoiceOver untuk login, laci, form invalid, modal, tabel gulir, dan tab. Catat TalkBack/perangkat fisik terpisah bila tersedia. | Nama, peran, status dan error terbaca benar; fokus berurutan, terlihat, dan tidak terjebak. |
| K6-16, PDF Safari nyata | Dari jendela audit Safari khusus, simpan PDF Santri/Guru/Gabungan pada A4 potret dan lanskap dengan data panjang/multipage setelah koreksi `3f76f9c`. Periksa semua halaman fisik. | Kata status utuh; seluruh data sesuai filter; margin aman, tanpa sidebar, nomor sesuai jumlah fisik, tidak ada halaman nol/hantu. |

Izin pengguna sekarang mencakup koreksi kolom PDF. Tidak dianggap sebagai
izin membaca tab Safari pribadi/produksi. Pada tahap sebelumnya Mac terkunci,
dan pemeriksaan luas Safari ditolak auto-review karena risiko tersebut;
pembatasan tidak dilewati. Dua pemeriksaan terarah di atas masih memerlukan
ketersediaan perangkat/jendela audit yang aman. Tidak ada hasil yang dikarang.

Panduan GitHub/cPanel tetap ada pada
[panduan-push-dan-rilis-cpanel.md](panduan-push-dan-rilis-cpanel.md), dengan
rekap terbaru. Penyimpanan commit lokal ini bukan izin push, merge, atau
rilis produksi. Migrasi/restore/cron/smoke produksi serta keputusan data lama
B-2/B-3/B-4 tetap di luar koreksi PDF ini.
