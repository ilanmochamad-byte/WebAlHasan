# Inventarisasi sebelum Fase 1

Tanggal inventarisasi: 16 Agustus 2026. Sumber: kode PHP dan dump `k1807225_webalhasan.sql` yang tersedia di workspace.

## Struktur aplikasi lama

- Website publik berada langsung di root dan memakai include `koneksi.php`.
- Panel admin berada di `admin/` dan menggunakan Bootstrap serta PHP native.
- Terdapat 33 route/script admin selain dua library Excel. Route penting mencakup dashboard, santri, guru, kelas, tahun ajaran, jadwal, PSB, berita, galeri, unduhan, keuangan, perizinan, pelanggaran, dan alumni.
- Query lama masih tersebar di halaman. Fase 1 mempertahankan perilakunya melalui jembatan `$koneksi`; kode baru dipisahkan ke `app/`.

## Skema yang tersedia

Dump memuat 20 tabel: `alumni`, `berita`, `download`, `galeri`, `guru`, `jadwal_ngaji`, `kamar`, `kelas`, `mapel`, `mengajar`, `pelanggaran`, `pembimbing_kamar`, `perizinan`, `plotting_kamar`, `plotting_kelas`, `psb_pembayaran`, `psb_pendaftar`, `santri`, `tahun_ajaran`, dan `users`.

Relasi utama yang ditemukan:

- `jadwal_ngaji.id_tahun` → `tahun_ajaran.id`, `id_kelas` → `kelas.id`, dan `id_guru` → `guru.id` (relasi logis; dump lama belum memasang foreign key).
- `plotting_kelas` menghubungkan santri, kelas, dan tahun ajaran.
- `plotting_kamar` menghubungkan santri, kamar, dan tahun ajaran.
- `users.guru_id` sudah tersedia tetapi sebelum Fase 1 belum unik dan belum memiliki role ternormalisasi.
- `users.username` dan `santri.nis` sudah memiliki unique index; `guru.nip` belum memiliki unique index pada dump lama.

## Autentikasi dan konfigurasi sebelum perubahan

- `admin/cek_login.php` membandingkan username/password literal lalu membuat `$_SESSION['status']`.
- Halaman admin memeriksa penanda sesi tersebut secara tidak konsisten; `proses_berita.php` tidak memiliki pemeriksaan sesi.
- `koneksi.php` menyimpan host, nama pengguna, dan password database langsung di source code.
- Logout hanya menghancurkan sesi tanpa CSRF atau audit.
- Tidak ada role, audit keamanan, pemaksaan ganti password, pencatat migrasi, atau backup otomatis.

## Keputusan kompatibilitas Fase 1

- URL `admin/admin_login.php`, `admin/cek_login.php`, dan `admin/admin_dashboard.php` dipertahankan.
- `koneksi.php` tetap menyediakan `$koneksi`, tetapi nilainya berasal dari bootstrap dan environment.
- Penanda sesi lama tetap diisi setelah autentikasi database agar modul lama tidak putus, sedangkan akses aktual diwajibkan melewati pemeriksaan akun aktif dan role dari database.
- Struktur tabel/kolom lama tidak dihapus pada Fase 1.

