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
