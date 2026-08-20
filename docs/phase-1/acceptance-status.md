# Status penerimaan Fase 1

Tanggal penutupan verifikasi: 20 Agustus 2026.

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

## Verifikasi staging/produksi selesai

Seluruh langkah berikut telah dijalankan dan dinyatakan lulus pada lingkungan tujuan:

1. [x] `php bin/preflight.php` dijalankan terhadap database tujuan dan hasilnya diperiksa.
2. [x] Laporan duplikasi, termasuk `users.username` dan `users.guru_id`, dinyatakan bersih.
3. [x] Migrasi Fase 1 diterapkan pada database tujuan.
4. [x] Login admin aktif, password salah, role guru, kewajiban ganti password, dan logout diuji.
5. [x] Query penerimaan duplikasi username lulus.
6. [x] Website publik, dashboard, data santri, data guru, dan jadwal lama lulus smoke test.
7. [x] Backup dipulihkan dan jumlah baris hasil restore diverifikasi.

Fase 1 telah terpasang dan terverifikasi di produksi. Hasil implementasi telah di-commit sebagai bagian dari riwayat fase proyek.
