# Penyelesaian kamar dan pagination sebelum push

Keputusan pengguna 30 Agustus 2026 setelah commit `33ceb43`: selesaikan halaman
kamar dan pagination sebelum paket dikirim ke GitHub. Tidak ada izin untuk
push, merge, deploy, mengubah mobile, atau menjalankan migrasi produksi.

## A-11 — P1, pra-ada: mutasi kamar tidak aman

Pembacaan halaman kamar sebelum modernisasi menemukan `?hapus=<id>` menjalankan
DELETE melalui GET, tanpa CSRF; ID edit diinterpolasi langsung ke SQL. Ini kode
warisan yang sudah ada sebelum paket, bukan regresi Claude. Ditemukan ketika
mengerjakan kamar yang sekarang diminta pengguna. Tidak menguji penghapusan
terhadap data lama. Sesuai larangan penghapusan data, jalur GET hapus ditutup
dengan 405; tidak menambahkan jalur penghapusan pengganti.

Tambah/ubah kini melalui prepared statement, validasi ID/nama/kapasitas, guard
admin dan CSRF, transaksi dan audit wajib. Jika audit gagal, perubahan
dibatalkan. ID/relasi/riwayat kamar tetap dipertahankan; tidak ada migrasi baru.

`tests/perapihan_audit_kamar.php`: 15 pemeriksaan awal lulus pada sandbox
`webalhasan_ui_codex_20260830_test`. Mencakup empat login, tiga penolakan peran,
CSRF, input invalid, tambah/edit sah, GET hapus 405, audit, dan jumlah data.
Percobaan pertama menghasilkan satu kegagalan tes baru karena membandingkan
string numerik hasil mysqli dengan integer secara strict; diperbaiki dengan
cast saat membaca hasil, tanpa mengubah ekspektasi 422/tidak ada mutasi.

## A-08 — P2: kamar tertinggal dari kerangka bersama

Halaman kamar sekarang memakai `_master_ui.php` → `App\Ui\Layout` dengan satu
H1, breadcrumb, penanda menu aktif, tombol menu ponsel, label formulir, pesan,
dan tabel yang menggulir dalam wadah. Form tambah/ubah serta daftar penghuni
tetap tersedia; dialog lama diganti halaman/form berlabel. Nama/data di-escape,
nilai form dikembalikan saat gagal validasi, CSRF dirender server. Tombol Hapus
tidak ditampilkan karena penghapusan dilarang oleh batas audit. Tidak ada
perubahan penempatan santri atau skema kamar.

Daftar kamar dan penghuni dibaca 20 per halaman melalui `PageQuery`, dengan
pencarian server dan urutan stabil; jumlah penghuni tetap berdasarkan semester
aktif. Pilihan kapasitas tidak otomatis memindahkan/mengeluarkan santri.

Tes kamar diperluas menjadi **19 lulus** termasuk escaping HTML, isian kembali
saat validasi gagal, kerangka/H1/menu, dan 404 detail tidak ada. Browser nyata
pada 390 px berhasil mencari, berpindah halaman, membuka menu, dan menyimpan
kapasitas kamar fixture 60 → 61; 45 penghuni tetap ada. Pengujian ini hanya
menyentuh fixture buatan auditor, bukan kamar lama/produksi.

## A-10 — P2: daftar besar belum seluruhnya dapat ditelusuri

Pagination server dan pencarian ditambahkan pada kelas, tahun ajaran, penugasan
murobi/pembimbing, serta riwayat pertemuan. Rekonsiliasi kini mempunyai empat tab
dengan pagination masing-masing: duplikasi, relasi belum lengkap, konflik
kolom lama, dan wali tanpa relasi. Hasil di atas batas lama 100 tidak lagi
tersembunyi. Hanya penyajian daftar web yang berubah; operasi merge, penugasan,
snapshot, cakupan guru, serta API lama dipertahankan.

`PageQuery` mengikat parameter pencarian, menghitung total, memakai urutan
stabil, dan membatasi nomor halaman ke rentang valid. Navigasi mempertahankan
query/tab/konteks; pencarian baru kembali ke halaman pertama. Kontrol halaman
dapat membungkus pada layar kecil. Daftar pilihan formulir tetap memakai sumber
lengkapnya: pagination kelas tidak memotong pilihan penempatan santri.

`tests/perapihan_audit_pagination.php` menghasilkan **38 lulus**: delapan daftar
45 data memberi 20/20/5 tanpa duplikat/hilang, page negatif/terlalu besar aman,
duplikasi ke-101 dan wali tanpa relasi ke-201 dapat dicapai, pertemuan guru
105 baris tetap terisolasi dari satu pertemuan guru lain, pencarian palsu tidak
menjadi SQL, guard lima halaman tetap admin-only, dan konteks HTTP bertahan.
Metode daftar lama tetap tersedia untuk konsumen lama dan opsi formulir.

Uji berikutnya menambah tujuh kasus hasil pencarian kosong: pesan tidak boleh
menyimpulkan seluruh data wali sudah bersih hanya karena kata pencarian tidak
cocok. Hasil akhir tes pagination menjadi **45 lulus**. Teks keadaan kosong
dibedakan dari keadaan seluruh daftar kosong; tidak mengubah aturan data.

## Lingkungan, hasil akhir, dan batas bukti

Tanggal eksekusi 30 Agustus 2026. Kode akhir yang diuji: `4c2f987` pada branch
`codex/perapihan-v1-v2-ui`. Commit koreksi: `01e1a97` A-11, `915055b` A-08,
`7e4f307` dan `4c2f987` A-10. Tidak ada pengujian yang dilemahkan atau assertion
historis yang diubah pada tahap kamar/pagination ini; dua berkas tes baru
menambah cakupan perilaku.

Environment: PHP 8.4.14, MariaDB 12.3.2, Node 26.7.0, Playwright 1.62.1,
Chromium cache 1234, Poppler 26.05.0. Database
`webalhasan_ui_codex_20260830_test` dan akun sandbox dari audit sebelumnya.
Environment hanya pada proses, `.env` proyek/produksi tidak diubah. Migrasi
001–010 yang sudah tersedia tetap sama; tidak ada migrasi baru untuk koreksi
kamar/pagination. Main tetap `c65390d`; mobile tetap `ab3f842`, tanpa perubahan.

| Rangkaian dijalankan ulang | Lulus | Gagal | Bukti di `bukti-audit-kamar-pagination/` |
| --- | ---: | ---: | --- |
| Regresi V1/V2, 28 berkas | 2.464 | 0 | `full-final.log` |
| Paket perapihan, 4 berkas | 248 | 0 | `full-final.log` |
| Browser resmi 1440/768/390 | 56 | 0 | `browser-final.log` |
| **Subtotal resmi** | **2.768** | **0** | Sama dengan audit lanjutan sebelumnya |
| Audit keamanan/data/HTTP/API/client sebelumnya, 9 berkas PHP | 171 | 0 | Log redirect, wali, merge, admin, HTTP, report, CSV, API, client |
| Kamar (A-08/A-11) | 19 | 0 | `kamar-final.log` |
| Pagination (A-10) | 45 | 0 | `pagination-final.log` |
| **Subtotal audit tambahan** | **235** | **0** | 171 sebelumnya + 64 baru; bukan bagian subtotal resmi |

Lint 18 berkas PHP yang berubah sejak `33ceb43` lulus. Pemeriksaan PDF 175
benar-benar berjalan di dalam regresi; jangan menjumlahkannya lagi. Selisih
2.768 vs acuan implementer 2.478 tetap +290 seperti penjelasan audit lanjutan
B-1/A-07, bukan kenaikan karena kamar. Angka regresi yang dihitung runner
tetap 28 berkas, bukan klaim awal 29.

Browser interaktif tambahan mencatat **24 pengamatan** (8 tampilan × 3 lebar):
kamar, penghuni, kelas, tahun, murobi, pembimbing, duplikasi wali, dan riwayat
pertemuan. Semua memiliki satu H1, kontrol pagination, dan lebar dokumen tidak
melebihi viewport. Duplikasi dihitung per kelompok: 20 kelompok dengan dua
anggota berarti 40 baris anggota, bukan kegagalan batas 20 kelompok.
Klik halaman berikutnya mempertahankan pencarian, pencarian baru kembali ke
halaman pertama, dan simpan kamar melalui browser berhasil. Pengamatan ini
disimpan sebagai JSON/screenshot, tidak ditambahkan ke angka assertion resmi.

Safari macOS tersedia, tetapi pemeriksaan ketersediaan mendapati jendela yang
sedang digunakan pengguna pada situs produksi. Auditor tidak mengklik kontrol
aplikasi produksi atau menggunakan akun tersebut untuk pengujian. Upaya membuka
jendela sandbox baru dihentikan alat dengan pesan bahwa pengguna telah mengubah
Safari; pemeriksaan lanjutan tidak dipaksakan. **Tidak ada bukti Safari baru
untuk kamar/pagination pada tahap ini.** Bukti Safari parsial dari audit awal
tidak diubah menjadi kelulusan baru; PDF Safari tersimpan/iOS tetap menunggu.
Tidak ada data dari jendela produksi yang disimpan sebagai artefak audit.

Fixture besar hanya buatan tes, dibersihkan setelah uji/browser. Satu kamar
fixture yang diubah kapasitasnya dan auditnya ikut dibersihkan; kamar lama,
penghuni lama, akun, dan riwayat lama tidak dihapus. Server localhost dihentikan
pada akhir audit; database sandbox dasar tetap tersedia. Log disimpan mentah,
termasuk whitespace label tab browser; kode/Markdown lulus pemeriksaan diff.

## Apa arti tiga klaim dan empat belas butir yang tersisa?

**Tidak terverifikasi** berarti ada bukti bahwa klaim implementasi belum benar.
Tiga klaim sebelumnya adalah konsistensi kerangka kamar (K6-03), pagination
daftar besar (K6-10), dan tidak adanya halaman tertinggal dalam inventaris
(K6-17). **Ketiganya kini TERVERIFIKASI** sesuai cakupan paket A/B dan bukti di
atas. Penyelesaiannya berupa perubahan kode, bukan mengurangi ruang lingkup.

**Menunggu verifikasi** berarti bukti belum lengkap, bukan otomatis ada bug.
Keempat belas butir berikut tetap menunggu; sebagian sampel sudah lulus, tetapi
belum cukup untuk menyatakan keseluruhan klaim lulus.

| ID | Yang perlu dibuktikan selanjutnya | Hasil yang diharapkan |
| --- | --- | --- |
| K1-05 | Audit semua tipe perubahan hak/status/reset akun, termasuk kegagalan pencatatan | Setiap mutasi yang berhasil memiliki audit yang benar |
| K2-08 | UI wali bersama dengan daftar panjang santri terdampak | Seluruh nama terdampak terlihat sebelum konfirmasi |
| K5-07 | Semua kombinasi filter laporan dan keluaran cetak/PDF yang relevan | Ringkasan, detail, CSV, dan cetak konsisten |
| K6-01 | Keseragaman seluruh komponen/halaman, bukan hanya sampel | Warna, tombol, tabel, form, dialog, dan pesan mengikuti standar |
| K6-06 | Kejelasan navigasi dan tindakan utama setiap alur | Menu aktif, breadcrumb, judul, dan tujuan tindakan tidak membingungkan |
| K6-07 | Pesan validasi dan label semua formulir A/B | Pesan dekat kolom yang salah dan label jelas |
| K6-08 | Isian kembali setelah validasi gagal pada form lain | Isian aman tidak hilang; password/secret tidak dipantulkan |
| K6-09 | Keadaan kosong/berhasil/gagal/ditolak seluruh modul | Pesan benar dan memberi langkah lanjut yang sesuai |
| K6-12 | Semua tindakan berisiko, termasuk dialog legacy | Dampak terlihat sebelum pengguna mengonfirmasi |
| K6-13 | Makna semua informasi/aksi tanpa warna atau ikon saja | Teks/nama aksesibel tetap menyampaikan makna |
| K6-14 | Keyboard, fokus, pembaca layar, dan kontras menyeluruh | Semua kontrol dapat dipakai dan dibaca dengan jelas |
| K6-15 | Preferensi pengurangan animasi pada perangkat/browser | Animasi berkurang ketika preferensi aktif |
| K6-16 | PDF Safari tersimpan serta cetak lintas browser/perangkat | Tanpa sidebar; margin, pagination, dan nomor halaman benar |
| K6-18 | Seluruh halaman D pada ukuran/mode relevan | CSS bersama tidak merusak halaman yang tidak didesain ulang |

Rekap terbaru **63 TERVERIFIKASI, 0 TIDAK TERVERIFIKASI, 14 MENUNGGU
VERIFIKASI**. [Matriks 77 klaim](penilaian-penerimaan-codex.md) diperbarui tanpa
menghapus riwayat angka sebelumnya. B-2/B-3/B-4 serta prosedur prarilis/produksi
tetap memiliki batas audit awalnya; tidak ada normalisasi data wali otomatis.

Instruksi menyelesaikan kamar/pagination sebelum push sudah dipenuhi.
**Belum ada push, merge, deploy, atau pernyataan paket siap produksi.**
[Panduan GitHub/cPanel](panduan-push-dan-rilis-cpanel.md) tetap memisahkan push
branch untuk review dari persetujuan merilis situs aktif.

## Mengulang tes tambahan

Muat environment sandbox yang sama dengan server lokal. Jalankan berurutan,
bukan bersamaan dengan tes yang mengubah akun. Kedua tes menolak database yang
tidak berakhiran `_test`.

```bash
PERAPIHAN_AUDIT_DB=1 php tests/perapihan_audit_kamar.php
PERAPIHAN_AUDIT_DB=1 AUDIT_FIXTURE_MANIFEST=/path/manifest-audit-dasar.json \
  php tests/perapihan_audit_pagination.php
```

Mode default membersihkan fixture miliknya sendiri. Jika sengaja menahan
fixture untuk browser, pakai `AUDIT_PAGE_KEEP=1` dan `AUDIT_PAGE_MANIFEST` yang
unik. Bersihkan sesudah selesai dengan environment yang sama dan perintah
`php tests/perapihan_audit_pagination.php cleanup`; manifest harus menunjuk
database yang cocok. Jangan menjalankan salah satu mode pada produksi.
