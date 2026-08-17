# Migrasi, rollback, dan pemulihan Fase 1

## Persiapan

1. Salin `.env.example` menjadi `.env` di server dan isi nilai sebenarnya. Jangan commit `.env`.
2. Pastikan akun `admin` pada tabel `users` aktif dan kolom `password` berisi hash hasil `password_hash()`.
3. Jalankan backup pra-migrasi: `php bin/preflight.php`. Simpan seluruh direktori hasilnya di lokasi aman di luar web root.
4. Baca `report.md`. Hentikan migrasi bila ada duplikasi `users.username` atau `users.guru_id`; bersihkan data berdasarkan keputusan pemilik data terlebih dahulu.

## Penerapan

1. Lihat status: `php bin/migrate.php status`.
2. Terapkan: `php bin/migrate.php up`.
3. Pastikan migrasi tercatat di `schema_migrations` dan jalankan kembali status.
4. Uji login admin dari URL lama, role guru (harus ditolak dari panel admin), ganti password wajib, logout, dan form akun tanpa CSRF.

Jika password admin pada dump tidak diketahui atau tidak cocok, reset tanpa menaruh password di source code:

`php bin/reset_admin_password.php admin`

Masukkan password sementara dua kali saat diminta. Akun akan diaktifkan, role `admin` dipastikan tersedia, dan pengguna wajib mengganti password setelah login pertama.

Migrasi menambahkan tabel `roles`, `user_roles`, `api_tokens`, dan `audit_logs`, kolom `users.force_password_change`, serta unique index pada `users.guru_id`. Tidak ada tabel atau kolom lama yang dihapus.

## Rollback skema

1. Hentikan sementara perubahan akun.
2. Jalankan `php bin/migrate.php rollback`.
3. Rollback menghapus struktur Fase 1. Audit dan token yang dibuat setelah migrasi akan hilang, jadi ekspor dahulu bila perlu.
4. Kode lama tidak dapat menggunakan login hard-coded lagi. Untuk kembali sepenuhnya ke rilis terdahulu, deploy commit aplikasi terdahulu dan pulihkan rahasia melalui mekanisme server yang aman; jangan menulis password ke Git.

## Uji pemulihan data

1. Buat database staging kosong, arahkan `.env` staging ke database tersebut, lalu impor `database.sql` dari backup pra-migrasi.
2. Jalankan `php bin/verify_restore.php /path/ke/manifest.json`.
3. Uji dinyatakan lulus hanya bila semua tabel berstatus `[sesuai]` dan proses keluar dengan kode `0`.
4. Buka website publik, dashboard, data santri, data guru, dan jadwal pada staging untuk memastikan tidak ada fatal error.
5. Catat tanggal, operator, lokasi backup, dan hasil uji pada tiket/deployment log produksi.

Jika rollback SQL gagal karena migrasi sebelumnya berhenti di tengah, pulihkan backup ke database staging/baru dan alihkan aplikasi hanya setelah hasil verifikasi jumlah baris lulus.
