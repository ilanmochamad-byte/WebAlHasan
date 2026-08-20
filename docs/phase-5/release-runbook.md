# Runbook Rilis Fase 5

Dokumen ini adalah prosedur staging dan produksi. Menuliskannya tidak memberikan
izin deployment. **Jangan menjalankan migrasi, restore, rollback, build store, atau
deployment produksi tanpa persetujuan eksplisit pemilik sistem.**

## Prasyarat

- PHP 8.3+ dengan `mysqli` dan `mbstring`; MySQL/MariaDB memakai InnoDB.
- HTTPS aktif pada website dan `/api/v1`.
- `.env` produksi tetap berada di luar Git dan tidak ditimpa saat unggah kode.
- Database salinan khusus pengujian dengan nama berakhiran `_test`.
- Perangkat Android dan iOS nyata untuk uji penerimaan guru.
- `EXPO_PUBLIC_API_BASE_URL` mengarah ke `/api/v1` staging melalui HTTPS.
- Persetujuan perubahan, jendela pemeliharaan, penanggung jawab, dan lokasi backup
  telah dicatat.

## Urutan deploy yang aman

1. Catat commit backend dan aplikasi yang akan dirilis. Bekukan perubahan lain.
2. Jalankan seluruh pemeriksaan lokal dan database `_test` pada checklist penerimaan.
3. Pada database `_test`, ambil `EXPLAIN` query halaman pertama **sebelum** menerapkan
   indeks Fase 5. Simpan hasil dan waktu respons.
4. Terapkan migrasi Fase 5 hanya pada `_test`, ulangi `EXPLAIN`, lalu pastikan hasil
   tidak lebih buruk dan halaman pertama tetap di bawah 2 detik pada ≥1.000 absensi.
5. Buat backup database `_test` dengan `php bin/preflight.php`, pulihkan ke database
   restore sementara berakhiran `_test`, lalu jalankan `php bin/verify_restore.php`
   memakai `manifest.json` dari backup.
6. Jalankan smoke test website publik, admin lama, API V1 lama, login aplikasi,
   jadwal, buka pertemuan, dan simpan/baca ulang absensi.
7. Uji laporan admin seluruh kombinasi filter utama, detail, CSV, dan cetak.
8. Uji laporan guru A; ubah `teacher_id`, `schedule_id`, dan ID pertemuan menjadi
   milik guru B. Hasil wajib `403` atau kosong tanpa data guru B.
9. Jalankan aplikasi dengan Expo Go terlebih dahulu. Uji cetak dan berbagi PDF pada
   Android dan iOS nyata. Custom development build hanya dibuat bila Expo Go tidak
   memenuhi kebutuhan perangkat.
10. Setelah pemilik menyetujui deployment, buat backup produksi dan manifest jumlah
    baris di lokasi nonpublik. Verifikasi file dapat dibaca sebelum melanjutkan.
11. Unggah kode PHP tanpa menimpa `.env`, storage backup, atau berkas pengguna.
12. Terapkan hanya migrasi yang berstatus menunggu. Jangan mengimpor ulang migrasi
    001–004 dan jangan menjalankan SQL langsung pada tabel absensi.
13. Ulangi smoke test produksi melalui HTTPS. Pantau error log tanpa mencatat token,
    password, header Authorization, atau isi ekspor.
14. Distribusikan build aplikasi ke jalur internal/TestFlight terlebih dahulu. Build
    produksi dan submit store memerlukan persetujuan terpisah.

Perintah build produksi yang mungkin dipakai setelah persetujuan adalah
`npx eas-cli@latest build --profile production`; perintah tersebut tidak dijalankan
sebagai bagian implementasi ini.

## Backup dan verifikasi pemulihan

1. Jalankan `php bin/preflight.php --output=/lokasi-aman/phase5-TIMESTAMP`.
2. Simpan `database.sql`, `manifest.json`, dan `report.md`; batasi permission lokasi.
3. Buat database kosong sementara yang namanya berakhiran `_test`.
4. Pulihkan `database.sql` ke database tersebut dengan akun khusus pengujian.
5. Arahkan `.env` pengujian ke database hasil restore dan jalankan:

   ```sh
   php bin/verify_restore.php /lokasi-aman/phase5-TIMESTAMP/manifest.json
   ```

6. Semua tabel pada manifest, khususnya `users`, `guru`, `santri`, `jadwal_ngaji`,
   `pertemuan_pengajian`, `pertemuan_peserta`, `absensi_guru`, dan
   `absensi_santri`, harus memiliki jumlah baris yang sama.
7. Hapus database restore sementara hanya setelah hasil disimpan dan target database
   diperiksa ulang. Jangan pernah memakai nama database produksi sebagai target.

## Rollback

- Bila kode baru gagal tetapi migrasi indeks berhasil, kembalikan kode ke commit
  sebelumnya. Indeks aditif boleh tetap ada sementara karena tidak mengubah data.
- Bila indeks terbukti menurunkan performa, jalankan rollback migrasi Fase 5 hanya
  setelah memastikan target adalah database yang benar dan backup tersedia.
- Jangan menjalankan rollback 001–004: rollback tersebut dapat menghapus struktur dan
  data operasional fase sebelumnya.
- Jangan menghapus atau mengubah baris absensi untuk memperbaiki laporan. Perbaikan
  dilakukan pada kode/query lalu diverifikasi terhadap backup/salinan.
- Jika jumlah baris restore berbeda, hentikan rilis. Pertahankan produksi pada versi
  lama, simpan artefak kegagalan, dan investigasi sebelum mencoba lagi.

## Pemeriksaan setelah rilis

- Login admin dan guru tetap bekerja; token lama yang valid tetap diterima.
- Website publik, URL admin lama, dan endpoint Fase 4 tetap kompatibel.
- Ringkasan laporan sama dengan jumlah detail/CSV untuk filter identik.
- Cetak tidak menampilkan navigasi, kolom utama utuh, metadata dan nomor halaman ada.
- API tidak mengembalikan password, hash token, atau laporan di luar kepemilikan.
- Catat waktu halaman pertama, versi PHP/DB, perangkat, OS, dan hasil cetak/berbagi.
