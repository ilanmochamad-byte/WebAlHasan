# Panduan admin dan smoke test

1. Buka **Master Data → Katalog & Ambang V3**.
2. Pada tab **Kategori**, isi kode/nama, tanggal mulai, dan status; simpan.
3. Pada tab **Jenis pelanggaran**, pilih kategori, tingkat Ringan/Sedang/Berat, poin default, uraian dan masa berlaku; simpan.
4. Pada tab **Ambang poin**, pilih tahun ajaran, nilai minimum/maksimum, label dan rekomendasi. Maksimum kosong berarti tanpa batas atas. Rentang yang beririsan pada periode/tahun sama ditolak.
5. Gunakan filter status/kategori/tingkat/tahun dan pagination. Klik **Ubah / riwayat** untuk membuka data dan 50 audit terakhir.
6. Nonaktifkan dengan memilih status Nonaktif dan mengisi alasan. Aktifkan kembali atau isi tanggal selesai untuk mengakhiri, selalu dengan alasan. Tanggal akhir berlaku inklusif. Tidak ada tombol hapus.
7. Jika muncul konflik versi, muat ulang agar tidak menimpa perubahan admin lain. Jika validasi gagal, input aman tetap ada pada formulir.

**Pelanggaran → Data warisan** tetap menampilkan catatan lama. Pencatatan dan penghapusan lama sudah ditutup atas keputusan pengguna. ID santri yang tidak ditemukan tidak menghilangkan baris dari daftar. Tidak ada backfill otomatis, hukuman otomatis, atau pencatatan kasus V3 pada fase ini.

## Smoke pada salinan MySQL/MariaDB cPanel

Status checklist berikut **BELUM DIJALANKAN** pada hosting. Jalankan pada salinan terpisah, setelah backup/restore terverifikasi dan migrasi 013:

- [ ] Pre-check dan post-check exit 0, jumlah/hash tabel lama identik.
- [ ] Buat satu kategori, jenis pelanggaran, dan ambang melalui web; buka kembali nilainya.
- [ ] Duplikasi kode, rentang overlap, poin/tanggal tidak valid ditolak tanpa baris tambahan.
- [ ] Nonaktif/aktif/akhiri dengan alasan tercatat di audit; tidak ada hard delete.
- [ ] Dua admin menyimpan rentang sama: tepat satu berhasil; konflik aman pada pihak kedua.
- [ ] Guru, pengurus, dan orang tua tidak bisa membuka admin; token/role palsu tidak memberi akses.
- [ ] Pengurus/guru tanpa penugasan serta orang tua tanpa relasi kehilangan capability yang sesuai.
- [ ] CSRF hilang/palsu ditolak; hapus GET/pencatatan warisan ditolak.
- [ ] Desktop, tablet, 375 px: formulir, validasi, tab, filter, pagination dan riwayat dapat digunakan.
- [ ] API login/profil/jadwal/perizinan lama tetap bekerja; aplikasi lama tidak menampilkan fitur operasional V3.
- [ ] WhatsApp tetap OFF dan tidak ada request provider baru.

Tidak ada deployment atau migrasi produksi dalam pekerjaan implementator ini.
