# Checklist Uji Penerimaan Fase 5

Gunakan akun dan database staging/salinan `_test`. Jangan memakai data atau database
produksi untuk membuat fixture performa.

## Admin web

- [x] Login melalui URL admin lama dan buka **Laporan Absensi**.
- [x] Filter rentang tanggal saja; catat jumlah pertemuan dan detail.
- [x] Ulangi dengan tahun ajaran, guru, kelas, jadwal, dan masing-masing status.
- [x] Pastikan jumlah lima status sama dengan jumlah baris detail.
- [x] Buka detail pertemuan dan cocokkan peserta, status, catatan, pencatat, serta waktu.
- [x] Ekspor CSV; pastikan jumlah data (tanpa header) sama dengan detail seluruh filter,
      bukan hanya halaman UI yang terlihat.
- [x] Buka CSV di LibreOffice/Excel/Google Sheets dan pastikan aksara/kolom terbaca.
- [x] Buka halaman cetak; pastikan identitas pesantren, jenis laporan, filter, waktu,
      pembuat, nomor halaman, dan kolom utama ada.
- [x] Cetak/simpan PDF; navigasi admin tidak boleh ikut tercetak.

## Guru dan otorisasi

- [x] Login sebagai Guru A di aplikasi dan buka tab **Laporan**.
- [x] Terapkan tanggal, status, dan satu jadwal milik Guru A.
- [x] Buka detail pertemuan; cocokkan kehadiran guru dan seluruh santri snapshot.
- [x] Ubah request API `teacher_id` menjadi Guru B: wajib `403`.
- [x] Ubah `schedule_id` dan ID detail pertemuan menjadi milik Guru B: tidak boleh ada
      satu pun data Guru B; detail wajib `403`.
- [x] Ketuk **Cetak / buka PDF** dan lanjutkan sampai dialog cetak perangkat tampil.
- [x] Ketuk **Bagikan PDF** dan pastikan lembar berbagi menampilkan berkas PDF.
- [x] Ulangi pada satu perangkat Android dan satu perangkat iOS nyata.

## Regresi dan keamanan

- [x] Login, profil, logout, jadwal hari ini/rentang, buka pertemuan, simpan dan koreksi
      absensi Fase 4 tetap bekerja.
- [x] Membuka/mengekspor/mencetak laporan tidak mengubah jumlah atau nilai absensi.
- [x] Password, token, hash token, dan header Authorization tidak muncul pada UI,
      respons laporan, CSV, HTML cetak, audit, atau log.
- [x] Website publik dan halaman admin lama tidak mengalami fatal error.
- [x] `php -l`, seluruh `tests/phase*_static.php`, dan integration test tersedia lulus.
- [x] `npm run lint` dan `npx tsc --noEmit` lulus.

## Performa, backup, dan restore

- [x] Fixture sintetis berisi sedikitnya 1.000 catatan absensi pada database `_test`.
- [x] `EXPLAIN` sebelum indeks disimpan; indeks hanya ditambah bila pola query nyata
      mendukungnya; `EXPLAIN` setelah indeks juga disimpan.
- [x] Halaman pertama laporan selesai maksimal 2 detik; catat waktu dan lingkungan.
- [x] Backup SQL dan manifest jumlah baris dibuat dari database `_test`.
- [x] Backup dipulihkan ke database restore `_test`; jumlah baris seluruh tabel sama.
- [x] Uji manual login sampai cetak selesai tanpa membuat duplikasi absensi.

Hanya kriteria yang benar-benar diuji yang boleh ditandai selesai di `PRD.md`.

Hasil terukur dan batas verifikasi perangkat dicatat di `acceptance-results.md`.
