# Status penerimaan Fase 1

## Terverifikasi di workspace

- Login hard-coded dan kredensial database telah dihapus dari file PHP.
- URL login admin lama dipertahankan dan diarahkan ke autentikasi `users` dengan `password_verify()`.
- Regenerasi session ID, cookie `HttpOnly`/`SameSite=Lax`/`Secure` saat HTTPS, logout dengan penghancuran sesi, serta pesan login generik sudah diterapkan.
- Semua route admin operasional memakai guard akun aktif + role `admin`; proses lama yang sebelumnya tidak memiliki guard juga terlindungi.
- Seluruh request POST admin ditolak tanpa CSRF valid. Form lama mendapat token dari layout admin; request import/AJAX mengirim token/header yang sama.
- Audit login berhasil/gagal, logout, perubahan password, pembuatan akun, status akun, role, dan reset password tersedia tanpa merekam password/token.
- UI akun guru mendukung pembuatan, aktif/nonaktif, penetapan role, reset password sementara, dan kewajiban ganti password saat login pertama.
- Migrasi naik/turun, backup native SQL, laporan jumlah baris/duplikasi, manifest, serta pembanding hasil restore tersedia.
- Pengujian `php tests/phase1_static.php` lulus dan semua file PHP lulus `php -l` pada PHP 8.4.14.

## Wajib diverifikasi pada staging/produksi

Hal berikut tidak dijalankan di workspace karena tidak tersedia service/database MySQL yang terkonfigurasi:

1. Jalankan `php bin/preflight.php` terhadap database tujuan dan arsipkan hasilnya.
2. Pastikan laporan duplikasi bersih, terutama `users.username` dan `users.guru_id`.
3. Terapkan `php bin/migrate.php up`.
4. Uji login akun admin aktif dengan hash yang sudah ada, password salah, role guru, pemaksaan ganti password, dan logout.
5. Jalankan query penerimaan duplikasi username.
6. Smoke test website publik, dashboard, data santri, data guru, dan jadwal lama.
7. Pulihkan backup ke database staging terpisah dan jalankan `php bin/verify_restore.php /path/manifest.json`.

Fase 1 belum boleh dianggap terpasang di produksi sampai tujuh langkah tersebut lulus dan dicatat.

