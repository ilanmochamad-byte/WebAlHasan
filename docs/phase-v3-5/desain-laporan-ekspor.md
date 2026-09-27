# Laporan dan ekspor

Halaman `/portal/v3_laporan.php`, navigasi Laporan pembinaan. Format `html` (default), `cetak` (browser Print / Simpan PDF), `csv`. Cetak dan CSV memakai seluruh hasil filter, bukan halaman aktif. API laporan hanya daftar HTML 25 baris.

| Jenis | Kolom |
| --- | --- |
| Pelanggaran | ID, ID santri, nama sintetis/santri yang berhak dilihat, periode, kategori, tingkat, poin snapshot, status |
| Kasus | ID, ID santri, nama santri, dibuka, status, kerahasiaan, jumlah sesi terkini |
| Publikasi keluarga | ID publikasi, ID santri, diterbitkan, status, ringkasan snapshot, tindak lanjut snapshot |

Isi konseling/lampiran/internal tidak muncul pada laporan. Publikasi ditarik hanya menampilkan pemberitahuan penarikan. Query EXISTS cakupan/filter/tautan mencegah penggandaan akibat join penugasan/sesi. Revisi pelanggaran lama tidak masuk daftar terkini. Filter tindak_lanjut internal menunjukkan adanya kasus/sesi yang boleh dilihat; bukan isi rencana konseling. Filter kategori/poin kasus memakai tautan aktif. Kelas/kamar merujuk plotting pada tahun data, sedangkan cakupan tetap penugasan aktif.

Urutan ID DESC, 25 per halaman. Count dan pengambilan rows berada dalam transaksi repeatable-read yang sama. Masing-masing permintaan membaca snapshot baru; perubahan bisnis antarpermintaan dapat mengubah posisi halaman. Uji 40 halaman pada 1.000 data tetap membuktikan 1.000 ID unik tanpa kehilangan. Untuk snapshot lengkap gunakan ekspor.

Batas eksplisit **10.000 baris** untuk cetak/PDF/CSV. Count melebihi batas menghasilkan 422 sebelum berkas dibuat; tidak ada ekspor parsial. Peringatan terlihat di HTML. Persempit filter untuk batas ini. Tidak ada download PDF biner server baru: pengguna memakai dialog cetak browser; pengujian menggunakan PDF Chromium nyata.

Semua sel HTML/cetak di-escape `ah_e`. CSV menggunakan fputcsv dan menambah apostrof untuk formula =,+,-,@ termasuk didahului whitespace/control serta tab/newline awal. MIME/no-sniff/no-store diberlakukan. Pengujian XSS snapshot sungguhan dan formula CSV tidak menjalankan script/formula. Tidak ada file ekspor tersimpan otomatis di server.
