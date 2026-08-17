# Sistem Informasi Pesantren Al Hasan — PRD V1

> **Untuk agen AI:** Dokumen ini adalah instruksi implementasi. Jika ada bagian yang tidak jelas, jangan menebak—tanyakan kepada pengguna. Jika suatu keputusan berubah selama implementasi, perbarui dokumen ini agar tetap menjadi sumber kebenaran (*living document*). Seluruh keputusan dalam versi ini telah dikonfirmasi pengguna pada 16 Agustus 2026; jika asumsi baru ditambahkan kemudian, tandai secara eksplisit dan verifikasi sebelum mengandalkannya.

## 1. Gambaran Umum

Data operasional Pesantren Al Hasan saat ini tersebar, berpotensi ganda, dan sebagian proses jadwal, absensi, laporan, serta pembatasan akses belum terkelola secara konsisten. V1 bertujuan menyediakan fondasi data dan akses yang aman pada website admin, lalu menghubungkannya dengan aplikasi guru agar jadwal dan absensi pengajian dapat dikelola dari satu sumber data tanpa duplikasi.

Website yang sudah berjalan harus tetap dapat digunakan selama pengembangan bertahap. Data MySQL dan kode PHP yang ada menjadi titik awal, bukan dibangun ulang tanpa kebutuhan.

## 2. Pengguna Sasaran & JTBD

1. **Admin** — Ketika mengelola operasional pesantren, admin ingin memelihara data guru, santri, orang tua, pengurus, penugasan murobi, akun, hak akses, tahun ajaran, kelas, jadwal, dan laporan dari satu website agar data selalu konsisten dan hanya dapat diakses oleh pihak yang berwenang.
2. **Guru** — Ketika menjalankan tugas pengajian, guru ingin masuk melalui aplikasi mobile, melihat jadwal yang ditugaskan kepadanya, mencatat kehadiran dirinya dan santri pada setiap pertemuan, serta melihat dan mencetak riwayat absensi tanpa pencatatan manual terpisah.

Santri, orang tua, dan pengurus merupakan data yang dikelola admin pada V1, tetapi belum menjadi pengguna yang dapat masuk ke sistem.

## 3. Fitur Inti (Ruang Lingkup)

1. **Fondasi data terpusat** — Admin dapat mengelola data guru, santri, orang tua, pengurus, serta penugasan guru sebagai murobi. Pola pengelolaannya mencakup pencarian, tambah, detail, ubah, aktif/nonaktif, dan arsip; identitas unik serta relasi antardata dijaga oleh validasi aplikasi dan constraint basis data.

2. **Akun, autentikasi, dan hak akses** — Kredensial admin tidak lagi ditulis langsung di kode. Admin dan guru masuk menggunakan akun pada tabel pengguna; password disimpan dengan `password_hash()` dan diperiksa dengan `password_verify()`. Website menggunakan sesi aman, sedangkan aplikasi mobile menggunakan token API; setiap halaman dan endpoint memeriksa autentikasi serta role di sisi server.

3. **Pengelolaan tahun ajaran, kelas, dan jadwal pengajian** — Admin mengelola tahun ajaran, kelas, dan jadwal; guru hanya melihat jadwal miliknya. Aturannya menggunakan tepat satu tahun ajaran/semester aktif dan jadwal berdasarkan kelas, guru, hari, waktu, fan ilmu, kitab, tempat, serta status aktif.

4. **Absensi per pertemuan** — Guru membuka pertemuan dari jadwal miliknya, mencatat kehadiran guru, lalu mencatat status setiap santri dalam kelas tersebut. Sesuai target tanpa duplikasi, satu guru atau santri hanya memiliki satu catatan untuk satu pertemuan; pengiriman ulang bersifat idempoten dan koreksi memperbarui catatan yang sama serta masuk audit.

5. **Aplikasi mobile guru** — Aplikasi Expo menyediakan jadwal, pengisian absensi guru dan santri, riwayat absensi, dan laporan/cetak sebagaimana dikonfirmasi pengguna. Login, ringkasan jadwal hari ini/mendatang, detail, filter, logout, serta penanganan sesi kedaluwarsa dan kegagalan jaringan menjadi alur pendukung.

6. **Laporan dan cetak absensi** — Admin dapat melihat laporan lintas guru/kelas; guru hanya dapat melihat riwayat yang berkaitan dengan jadwalnya. Filter rentang tanggal, tahun ajaran, guru, kelas, jadwal, dan status serta ekspor CSV digunakan untuk membuat laporan dapat ditindaklanjuti.

7. **Migrasi dan audit data** — Demi menjaga website dan data lama, perubahan skema dilakukan melalui skrip SQL berversi, mempertahankan ID data lama, dan memeriksa duplikasi sebelum constraint unik diterapkan. Perubahan penting pada master data, jadwal, akun, dan absensi menyimpan pelaku serta waktu perubahan.

## 4. Di Luar Ruang Lingkup V1

- V1 tidak menyediakan akun atau portal untuk santri, orang tua, maupun pengurus.
- V1 tidak mengimplementasikan pengajuan dan persetujuan perizinan santri.
- V1 tidak mengimplementasikan pencatatan konseling atau pelanggaran santri sebagai alur baru.
- V1 tidak mengimplementasikan tagihan pembiayaan bulanan atau rekonsiliasi pembayaran.
- V1 tidak mengimplementasikan biaya, pendaftaran, atau alur penerimaan santri baru sebagai fitur baru; fitur lama yang sudah ada tidak boleh dirusak.
- V1 tidak mengimplementasikan penilaian semester atau penerbitan rapor santri.
- V1 tidak memberikan fitur aplikasi mobile khusus pengurus.
- V1 tidak mengganti PHP native dengan Laravel atau framework backend lain.
- V1 tidak melakukan desain ulang penuh terhadap website publik pesantren.
- V1 tidak mengimplementasikan absensi biometrik, pengenalan wajah, pemindaian QR, verifikasi GPS, atau bukti foto.
- V1 tidak mewajibkan mode absensi offline penuh; kegagalan jaringan harus ditampilkan dengan jelas dan pengguna dapat mencoba mengirim ulang tanpa membuat duplikasi.

## 5. Batasan Teknis & Keputusan yang Sudah Diambil

### 5.1 Keputusan yang tidak boleh dibuka kembali tanpa persetujuan pengguna

- **Backend:** pertahankan PHP native dan MySQL, lalu rapikan secara bertahap menjadi struktur modular. **[JANGAN DIUBAH]**
- **Mobile:** pertahankan proyek `/Users/ilanmochamad/alhasanApps` dengan Expo 57, React Native 0.86, Expo Router, React 19, dan TypeScript strict. **[JANGAN DIUBAH]**
- **Migrasi framework:** jangan memigrasikan backend ke Laravel. **[JANGAN DIUBAH]**
- **Pengguna V1:** hanya admin dan guru yang dapat melakukan login. **[JANGAN DIUBAH]**
- **Urutan prioritas:** fondasi data dan akses harus selesai sebelum operasional jadwal dan absensi. **[JANGAN DIUBAH]**
- **Kompatibilitas:** pertahankan fungsi website publik dan modul lama di `/Users/ilanmochamad/Documents/GitHub/webalhasan/WebAlHasan` selama pengembangan agar modernisasi bertahap tidak mengganggu operasional yang sudah berjalan.

### 5.2 Struktur aplikasi dan keamanan

- Pisahkan konfigurasi, koneksi basis data, autentikasi, otorisasi, validasi, akses data/repository, service/domain, API controller, dan view. File baru tidak boleh menggabungkan kredensial, query, aturan bisnis, dan HTML dalam satu blok besar.
- Gunakan `mysqli` atau PDO secara konsisten dengan prepared statement untuk seluruh input baru; jangan menyusun SQL baru melalui interpolasi input pengguna.
- Pindahkan kredensial basis data dan secret token dari source code ke environment/config lokal yang tidak di-commit. Sediakan berkas contoh tanpa nilai rahasia.
- Ganti login admin hard-coded pada `admin/cek_login.php` dengan autentikasi berbasis tabel `users` yang sudah tersedia.
- Password harus menggunakan `password_hash(PASSWORD_DEFAULT)` dan `password_verify`; jangan menyimpan atau mencatat password mentah.
- Website menggunakan session cookie `HttpOnly`, `SameSite=Lax` atau lebih ketat, dan `Secure` saat HTTPS; lakukan regenerasi session ID setelah login.
- Semua form perubahan data memiliki proteksi CSRF, validasi server, pesan kegagalan yang aman, dan otorisasi role.
- API berada di prefix `/api/v1`; respons memakai JSON konsisten yang memuat `success`, `data`, dan `error` bila gagal.
- Aplikasi menggunakan bearer token acak yang hanya disimpan dalam bentuk hash di server, dapat dicabut saat logout, dan memiliki masa berlaku 30 hari.
- Token mobile disimpan melalui penyimpanan aman perangkat, bukan penyimpanan teks biasa.
- Seluruh trafik produksi harus melalui HTTPS.
- API mengembalikan `401` untuk sesi/token tidak valid, `403` untuk role atau kepemilikan yang tidak berhak, `404` untuk sumber daya yang tidak ditemukan, `409` untuk konflik/duplikasi, dan `422` untuk validasi input.

### 5.3 Data yang disimpan atau ditampilkan

- **Guru:** ID lama, NIP, nama, nomor HP, status aktif, dan penanda/penugasan sebagai murobi. NIP unik jika terisi.
- **Santri:** ID lama, NIS, nama, jenis kelamin, tempat/tanggal lahir, alamat berjenjang, sekolah asal/saat ini, foto, status aktif, dan relasi kelas/tahun ajaran. NIS harus unik; status aktif serta riwayat relasi kelas/tahun ajaran merupakan penambahan.
- **Orang tua/wali:** nama, hubungan dengan santri, nomor HP, alamat, serta relasi ke satu atau lebih santri. Data ayah/ibu yang saat ini berada pada tabel `santri` harus dimigrasikan tanpa kehilangan data.
- **Pengurus:** identitas, nomor HP, jabatan, dan status aktif. Pengurus belum mendapatkan akun pada V1.
- **Murobi:** relasi antara guru, kelompok binaan/kamar atau kelas, tahun ajaran, tanggal mulai/selesai, dan status aktif. Alur approval izin belum termasuk V1.
- **Akun dan role:** nama, username, email, nomor HP, password hash, relasi opsional ke guru, status aktif, waktu login terakhir, role, dan token API. Username unik; satu akun guru terhubung ke tepat satu data guru.
- **Tahun ajaran:** tahun, semester, status; hanya satu baris boleh aktif pada satu waktu.
- **Kelas dan keanggotaan:** nama kelas, jenjang, tahun ajaran, santri, tanggal/status keanggotaan.
- **Jadwal pengajian:** tahun ajaran, hari, waktu mulai/selesai, kelas, fan ilmu, kitab, guru, tempat, dan status aktif. Kolom `jam` lama dimigrasikan secara aman ke waktu terstruktur bila nilainya dapat dikenali; nilai yang gagal diparsing harus masuk laporan migrasi dan tidak boleh dibuang.
- **Pertemuan pengajian:** jadwal, tanggal pertemuan, waktu aktual buka/tutup, status, pembuat, dan catatan.
- **Absensi guru:** pertemuan, guru, status, waktu pencatatan, pencatat, catatan, dan waktu perubahan. Status: `Hadir`, `Terlambat`, `Izin`, `Sakit`, atau `Alpa`.
- **Absensi santri:** pertemuan, santri, status, waktu pencatatan, pencatat, catatan, dan waktu perubahan. Status: `Hadir`, `Terlambat`, `Izin`, `Sakit`, atau `Alpa`.
- **Audit:** pelaku, aksi, jenis dan ID entitas, waktu, serta ringkasan nilai sebelum/sesudah tanpa menyimpan password atau token.

### 5.4 Integritas dan migrasi

- Setiap perubahan skema harus berupa file migrasi SQL berurutan dengan pasangan langkah penerapan dan petunjuk pemulihan/rollback.
- Buat backup basis data dan catat jumlah baris per tabel sebelum migrasi produksi.
- Jangan menghapus tabel/kolom lama sampai hasil migrasi diverifikasi dan pengguna menyetujui penghapusan.
- Constraint unik minimum: `users.username`, `santri.nis`, `guru.nip` bila tidak kosong, pasangan akun–guru, pasangan jadwal–tanggal pertemuan, pasangan pertemuan–guru, dan pasangan pertemuan–santri.
- Operasi pembuatan pertemuan dan penyimpanan satu daftar absensi harus memakai transaksi basis data.
- Endpoint penyimpanan absensi harus menerima `idempotency_key` per pengiriman dan mengembalikan hasil yang sama pada pengiriman ulang.

### 5.5 Antarmuka dan aksesibilitas

- Bahasa antarmuka V1 adalah Bahasa Indonesia.
- Website tetap menggunakan pola visual Bootstrap yang sudah ada agar perubahan mudah dipahami pengguna.
- Aplikasi mobile mendukung Android dan iOS sesuai konfigurasi proyek yang sudah tersedia.
- Form, tombol, status, validasi, loading, empty state, dan error harus dapat dibedakan dengan teks, bukan warna saja.
- Tampilan laporan web harus ramah cetak. Aplikasi mobile boleh membuka tampilan cetak/PDF dari server melalui mekanisme cetak atau berbagi yang kompatibel dengan Expo.

## 6. Persyaratan Bertahap

### Fase 1: Fondasi Keamanan, Migrasi, dan Akses

**Tujuan:** Menghasilkan login admin berbasis basis data dan kerangka modular yang berjalan tanpa memutus fitur website lama.

**Persyaratan:**

1. Inventarisasi halaman PHP, tabel, relasi, konfigurasi, dan alur autentikasi yang sudah ada sebelum mengubah skema.
2. Tambahkan konfigurasi environment dan contoh konfigurasi tanpa nilai rahasia.
3. Buat satu bootstrap aplikasi yang menangani konfigurasi, koneksi basis data, session, dan error handling untuk modul baru.
4. Sediakan sistem migrasi SQL sederhana yang mencatat nama migrasi dan waktu penerapan.
5. Buat backup dan laporan pra-migrasi berisi jumlah baris serta duplikasi kunci bisnis.
6. Gunakan tabel `users` yang sudah ada sebagai sumber akun; tambahkan role `admin` dan `guru` melalui tabel role/relasi yang ternormalisasi.
7. Tambahkan relasi unik akun guru ke tabel `guru` dan status aktif akun.
8. Ganti pemeriksaan username/password hard-coded dengan `password_verify()` terhadap akun aktif.
9. Pertahankan URL login admin lama atau berikan redirect kompatibel agar bookmark lama tidak rusak.
10. Terapkan middleware/helper autentikasi dan otorisasi server-side untuk halaman admin dan endpoint API.
11. Tambahkan CSRF pada seluruh form perubahan data yang disentuh dalam V1.
12. Tambahkan audit untuk login berhasil/gagal, logout, serta perubahan akun dan role tanpa merekam credential.
13. Sediakan halaman admin untuk membuat akun guru, mengaktifkan/nonaktifkan akun, menetapkan role, dan mereset password sementara.
14. Password sementara wajib diganti saat login pertama.
15. Sediakan prosedur rollback dan uji pemulihan data untuk migrasi fase ini.

**Kriteria penerimaan:**

- [ ] Tidak ada string password admin aktif di `admin/cek_login.php` atau file PHP lain yang dilacak Git.
- [ ] Akun admin aktif dengan password hash yang valid dapat login melalui URL admin lama dan diarahkan ke dashboard.
- [ ] Password salah menolak login tanpa mengungkap apakah username terdaftar.
- [ ] Session ID berubah setelah login dan session berakhir setelah logout.
- [ ] Pengguna tanpa session menerima redirect ke login pada seluruh halaman admin yang dilindungi.
- [ ] Akun ber-role guru menerima `403` atau redirect aman ketika mencoba membuka fungsi khusus admin.
- [ ] Form perubahan data tanpa token CSRF yang valid ditolak.
- [ ] Query `SELECT username, COUNT(*) FROM users GROUP BY username HAVING COUNT(*) > 1;` mengembalikan 0 baris.
- [ ] Seluruh file PHP yang ditambah atau diubah lolos `php -l`.
- [ ] Backup pra-migrasi, laporan jumlah baris, dan petunjuk rollback tersedia dan dapat dibaca.
- [ ] Website publik dan sedikitnya halaman dashboard, data santri, data guru, serta jadwal lama tetap dapat dibuka tanpa fatal error.

### Fase 2: Master Data Terpusat (memerlukan autentikasi dan migrasi Fase 1)

**Tujuan:** Memberikan admin pengelolaan data inti yang konsisten, dapat dicari, dan terlindung dari duplikasi.

**Persyaratan:**

1. Sediakan daftar, pencarian, filter, pagination, detail, tambah, ubah, aktif/nonaktif, dan arsip untuk guru dan santri.
2. Pertahankan ID guru dan santri yang sudah dirujuk tabel lama.
3. Pisahkan data orang tua/wali ke struktur relasional tanpa menghilangkan nilai ayah/ibu lama.
4. Sediakan pengelolaan orang tua/wali dan relasinya ke satu atau lebih santri.
5. Tambahkan pengelolaan data pengurus tanpa memberikan akun login pada V1.
6. Tambahkan penugasan guru sebagai murobi per tahun ajaran dan kelompok binaan.
7. Sediakan pengelolaan tahun ajaran dan pastikan hanya satu semester aktif.
8. Sediakan pengelolaan kelas dan keanggotaan santri per tahun ajaran.
9. Terapkan validasi format dan normalisasi NIS, NIP, username, email, nomor HP, dan tanggal.
10. Tolak pembuatan duplikasi kunci bisnis dengan pesan yang dapat ditindaklanjuti.
11. Gunakan nonaktif/arsip untuk data yang sudah direferensikan; jangan hapus permanen melalui UI.
12. Catat audit create, update, status change, relasi, dan arsip.
13. Sediakan impor data hanya jika format impor lama dapat divalidasi tanpa menurunkan kualitas data; baris gagal harus dilaporkan dan tidak membatalkan baris valid.
14. Sediakan ekspor CSV untuk daftar guru dan santri sesuai filter.

**Kriteria penerimaan:**

- [ ] Admin dapat membuat, melihat, mengubah, mencari, memfilter, menonaktifkan, dan mengarsipkan satu guru serta satu santri dari UI web.
- [ ] Upaya membuat NIS yang sama dua kali ditolak dan hanya satu baris tersimpan.
- [ ] Upaya membuat NIP non-kosong yang sama dua kali ditolak dan hanya satu baris tersimpan.
- [ ] Menonaktifkan guru atau santri tidak menghapus riwayat dan relasi lama.
- [ ] Satu orang tua/wali dapat dihubungkan ke dua santri dan setiap relasi dapat dibaca kembali.
- [ ] Tepat satu tahun ajaran/semester aktif setelah perubahan status, dibuktikan dengan query yang menghasilkan nilai `1`.
- [ ] Santri dapat ditempatkan ke satu kelas pada tahun ajaran aktif dan keanggotaan historis tetap tersimpan.
- [ ] Guru dapat ditetapkan sebagai murobi tanpa memperoleh akses approval izin pada V1.
- [ ] Ekspor CSV menghasilkan jumlah baris yang sama dengan hasil filter pada UI.
- [ ] Audit menyimpan pelaku dan waktu untuk perubahan master data tanpa menyimpan nilai rahasia.
- [x] Seluruh file PHP yang ditambah atau diubah lolos `php -l`.

### Fase 3: Jadwal dan Pertemuan Pengajian (memerlukan master data Fase 2)

**Tujuan:** Menghasilkan jadwal pengajian terstruktur yang dapat dikelola admin dan menjadi sumber tugas guru.

**Persyaratan:**

1. Pertahankan data pada `jadwal_ngaji` dan relasinya ke `tahun_ajaran`, `kelas`, serta `guru`.
2. Tambahkan hari, waktu mulai, waktu selesai, status aktif, dan metadata audit pada jadwal.
3. Migrasikan nilai `jam` yang dapat dikenali; buat laporan untuk nilai yang tidak dapat diparsing dan pertahankan nilai aslinya.
4. Sediakan daftar, filter, detail, tambah, ubah, aktif/nonaktif, dan arsip jadwal pada website admin.
5. Cegah bentrok guru pada hari dan rentang waktu yang sama di semester aktif.
6. Tampilkan peringatan bentrok kelas dan tempat pada hari dan rentang waktu yang sama.
7. Definisikan jadwal sebagai pola mingguan; pertemuan merupakan kejadian bertanggal dari pola tersebut.
8. Izinkan admin atau guru pemilik jadwal membuka satu pertemuan untuk tanggal yang sesuai jadwal.
9. Cegah lebih dari satu pertemuan untuk kombinasi jadwal dan tanggal yang sama.
10. Bekukan daftar peserta dari keanggotaan kelas saat pertemuan dibuka agar perubahan kelas berikutnya tidak mengubah riwayat.
11. Sediakan status pertemuan `Draf`, `Dibuka`, dan `Selesai`.
12. Catat pembuat, waktu buka, waktu selesai, serta perubahan status pertemuan.

**Kriteria penerimaan:**

- [ ] Seluruh jadwal lama tetap memiliki guru, kelas, tahun ajaran, fan ilmu, kitab, waktu, dan tempat setelah migrasi.
- [ ] Setiap nilai jam yang gagal dimigrasikan tercatat dalam laporan dan nilai asli masih tersedia.
- [ ] Admin dapat membuat dan mengubah jadwal melalui UI, lalu hasilnya muncul pada filter semester aktif.
- [ ] Sistem menolak dua jadwal aktif untuk guru yang sama pada hari dan waktu yang bertabrakan.
- [ ] Sistem menolak pembuatan pertemuan kedua untuk jadwal dan tanggal yang sama.
- [ ] Pertemuan yang dibuka menyimpan snapshot daftar santri kelas pada saat pembukaan.
- [ ] Jadwal nonaktif tidak muncul sebagai tugas aktif guru.
- [ ] Seluruh file PHP yang ditambah atau diubah lolos `php -l`.

### Fase 4: REST API dan Aplikasi Guru (memerlukan jadwal/pertemuan Fase 3)

**Tujuan:** Memungkinkan guru menggunakan aplikasi Expo untuk login, melihat tugas, dan menyimpan absensi per pertemuan secara aman serta idempoten.

**Persyaratan:**

1. Sediakan dokumentasi kontrak `/api/v1` yang mencakup request, response, status HTTP, autentikasi, pagination, filter, dan contoh error.
2. Sediakan endpoint login, profil, refresh/perpanjangan sesi bila dipakai, dan logout untuk akun guru aktif.
3. Sediakan endpoint jadwal guru hari ini, jadwal dalam rentang tanggal, detail jadwal, serta pertemuan.
4. Sediakan endpoint pembukaan pertemuan yang hanya dapat dipanggil guru pemilik jadwal atau admin.
5. Sediakan endpoint untuk membaca daftar santri snapshot dan menyimpan absensi guru serta santri dalam satu transaksi.
6. Terapkan unique constraint dan `idempotency_key` agar retry tidak menambah baris absensi.
7. Guru hanya dapat membaca dan mengubah pertemuan milik jadwalnya; admin memiliki akses lintas jadwal.
8. Setelah pertemuan berstatus `Selesai`, koreksi guru memerlukan alasan dan masuk audit.
9. Aplikasi menyediakan layar login dan menyimpan token di secure storage.
10. Aplikasi menyediakan beranda dengan jadwal hari ini dan jadwal berikutnya.
11. Aplikasi menyediakan daftar/filter jadwal dan detail tugas pengajian.
12. Aplikasi menyediakan alur buka pertemuan, status kehadiran guru, daftar santri, aksi tandai semua hadir, perubahan per santri, catatan, ringkasan, konfirmasi, dan kirim.
13. Tombol kirim dinonaktifkan selama request berlangsung dan retry memakai `idempotency_key` yang sama.
14. Aplikasi menampilkan loading, empty state, validasi, error jaringan, `401`, `403`, `409`, dan `422` secara dapat ditindaklanjuti.
15. Saat menerima `401`, aplikasi menghapus token yang tidak valid dan mengarahkan pengguna ke login.
16. Aplikasi menyediakan logout yang mencabut token server dan membersihkan token perangkat.
17. Status absensi yang telah tersimpan dapat dibuka kembali tanpa membuat catatan baru.
18. Tambahkan pengujian minimal untuk autentikasi API, otorisasi kepemilikan jadwal, idempotensi, constraint unik, dan transaksi absensi.

**Kriteria penerimaan:**

- [ ] Akun guru aktif dapat login melalui aplikasi dan menerima profil tanpa password/token hash.
- [ ] Akun nonaktif atau password salah ditolak dengan `401` dan pesan generik.
- [ ] Guru A menerima `403` ketika meminta detail atau menyimpan absensi jadwal Guru B.
- [ ] Beranda hanya menampilkan jadwal milik guru yang login pada semester aktif.
- [ ] Guru dapat membuka satu pertemuan, mengisi kehadiran dirinya dan seluruh santri, lalu menyimpan hingga berhasil.
- [ ] Mengirim payload yang sama dua kali dengan `idempotency_key` yang sama menghasilkan satu pertemuan dan satu catatan per peserta.
- [ ] Query duplikasi pertemuan–santri dan pertemuan–guru masing-masing mengembalikan 0 baris.
- [ ] Kegagalan di tengah transaksi tidak meninggalkan daftar absensi yang tersimpan sebagian.
- [ ] Absensi tersimpan dapat dibuka kembali dengan nilai yang sama dan koreksi memperbarui baris yang sama.
- [ ] Logout menyebabkan token lama tidak dapat mengakses endpoint terproteksi.
- [ ] `npm run lint` dan `npx tsc --noEmit` lulus pada proyek mobile.
- [ ] Seluruh file PHP yang ditambah atau diubah lolos `php -l`.

### Fase 5: Laporan, Cetak, dan Kesiapan Rilis (memerlukan absensi Fase 4)

**Tujuan:** Menyediakan laporan absensi yang dapat diverifikasi, dicetak, dan digunakan dalam operasional harian.

**Persyaratan:**

1. Sediakan dashboard ringkas jumlah pertemuan dan rekap status absensi pada rentang tanggal.
2. Admin dapat memfilter laporan berdasarkan rentang tanggal, tahun ajaran, guru, kelas, jadwal, dan status.
3. Guru hanya dapat melihat laporan jadwal yang ditugaskan kepadanya.
4. Sediakan laporan riwayat pertemuan, kehadiran guru, dan kehadiran santri.
5. Tampilkan detail pertemuan dan daftar peserta beserta status, catatan, pencatat, dan waktu perubahan.
6. Sediakan halaman HTML ramah cetak dan ekspor CSV dari hasil filter.
7. Sediakan hasil cetak/PDF yang dapat dibuka dari aplikasi guru.
8. Cantumkan judul pesantren, jenis laporan, filter aktif, waktu pembuatan, pembuat, dan nomor halaman pada hasil cetak.
9. Pastikan total pada ringkasan sama dengan jumlah baris detail untuk filter yang sama.
10. Sediakan pagination untuk laporan di UI, tetapi ekspor memuat seluruh hasil yang sesuai filter.
11. Tambahkan indeks basis data untuk query laporan berdasarkan hasil pengukuran query.
12. Uji migrasi pada salinan basis data, uji backup/restore, dan dokumentasikan urutan deploy serta rollback.
13. Sediakan checklist uji penerimaan admin dan guru pada perangkat mobile nyata.
14. Periksa agar log, API, ekspor, dan audit tidak membocorkan password, token, atau data di luar kewenangan pengguna.

**Kriteria penerimaan:**

- [ ] Admin dapat menghasilkan laporan rentang tanggal yang menampilkan guru, kelas, jadwal, jumlah pertemuan, dan rekap status.
- [ ] Guru tidak dapat melihat laporan guru lain melalui UI maupun perubahan parameter API.
- [ ] Jumlah pada ringkasan laporan sama dengan jumlah detail untuk filter yang sama.
- [ ] Ekspor CSV memuat seluruh baris sesuai filter dan header kolom yang terdokumentasi.
- [ ] Halaman cetak dapat dicetak dari browser tanpa menu navigasi dan tanpa memotong kolom utama.
- [ ] Laporan dapat dibuka dari aplikasi dan diteruskan ke dialog cetak/berbagi perangkat.
- [ ] Pengujian pada data dengan sedikitnya 1.000 catatan absensi menghasilkan halaman pertama laporan dalam waktu maksimal 2 detik pada lingkungan uji.
- [ ] Backup dapat dipulihkan pada basis data uji dan jumlah baris tabel inti sama dengan sebelum pemulihan.
- [ ] `npm run lint`, `npx tsc --noEmit`, pemeriksaan sintaks PHP, dan pengujian backend/API yang disediakan semuanya lulus.
- [ ] Uji manual dari login guru hingga cetak laporan selesai tanpa duplikasi catatan absensi.

## 7. Metrik Keberhasilan

- 100% data guru dan santri aktif yang dipakai jadwal memiliki identitas unik serta tidak memiliki duplikasi NIP/NIS yang melanggar aturan.
- 100% akun admin dan guru aktif memakai password hash; tidak ada password aktif yang tertulis langsung di kode.
- 100% jadwal aktif pada semester berjalan terhubung ke satu guru dan satu kelas yang valid.
- Untuk setiap pertemuan, jumlah catatan absensi santri sama dengan jumlah peserta snapshot dan tidak terdapat lebih dari satu catatan per santri.
- Pengiriman ulang payload absensi dengan `idempotency_key` yang sama menghasilkan 0 catatan tambahan.
- Admin dan guru dapat menyelesaikan alur utama yang relevan—login, melihat jadwal, mencatat absensi, melihat riwayat, dan mencetak laporan—tanpa bantuan teknis pada uji penerimaan.
- Sedikitnya 95% pengiriman absensi pada jaringan tersedia berhasil pada percobaan pertama selama masa uji coba.
- 0 akses lintas role atau lintas kepemilikan jadwal pada rangkaian uji otorisasi.
- 0 kehilangan baris pada tabel inti setelah simulasi migrasi dan pemulihan backup.
- 0 duplikasi catatan pertemuan, absensi guru, atau absensi santri setelah uji retry dan pengiriman bersamaan.

## 8. Keputusan yang Telah Dikonfirmasi

Seluruh keputusan berikut telah dikonfirmasi pengguna pada 16 Agustus 2026 dan dapat dijadikan dasar implementasi:

1. Jadwal pengajian merupakan pola mingguan dan memiliki hari serta waktu mulai/selesai terstruktur.
2. Status absensi guru dan santri adalah `Hadir`, `Terlambat`, `Izin`, `Sakit`, dan `Alpa`.
3. Guru dapat membuka pertemuan miliknya dan mengoreksi pertemuan selesai dengan alasan yang diaudit.
4. Daftar peserta dibekukan sebagai snapshot ketika pertemuan dibuka.
5. Orang tua/wali dinormalisasi ke tabel tersendiri dan dapat terhubung ke lebih dari satu santri.
6. Penugasan murobi terkait tahun ajaran dan kelompok binaan berupa kamar atau kelas.
7. Pengurus dikelola sebagai master data tetapi belum memiliki akun pada V1.
8. Aplikasi mendukung Android dan iOS; Bahasa Indonesia menjadi bahasa antarmuka V1.
9. API menggunakan bearer token acak berumur 30 hari dan penyimpanan aman perangkat.
10. Retry absensi didukung saat jaringan kembali tersedia, tetapi mode offline penuh tidak termasuk V1.
11. Cetak menggunakan halaman HTML/PDF dari server dan dapat dibuka atau dibagikan dari aplikasi Expo.
12. Data lama dipertahankan sampai migrasi diverifikasi; penghapusan kolom/tabel lama memerlukan persetujuan pengguna.
13. Arsip/nonaktif digunakan sebagai pengganti penghapusan permanen untuk data yang sudah memiliki riwayat.
14. Impor/ekspor CSV, audit log, password sementara, dan kewajiban mengganti password pada login pertama termasuk dalam implementasi fondasi.
15. Target performa laporan adalah maksimal 2 detik untuk halaman pertama pada data uji 1.000 catatan.
16. Kode baru memakai prepared statement, environment untuk secret, CSRF, session aman, HTTPS, kontrak error HTTP, dan pemeriksaan role/kepemilikan di server.
17. Role disimpan secara ternormalisasi; satu akun guru terkait tepat satu guru dan admin dapat mengelola akun/reset password sementara.
18. Perubahan penting dicatat dalam audit tanpa credential; migrasi memiliki backup, laporan duplikasi, rollback, dan tidak menghapus struktur lama sebelum verifikasi.
19. Pengelolaan master memakai pencarian, pagination, aktif/nonaktif, arsip, serta validasi kunci unik; data yang sudah memiliki riwayat tidak dihapus permanen.
20. Laporan menyediakan filter rentang tanggal/tahun ajaran/guru/kelas/jadwal/status, halaman ramah cetak, dan ekspor CSV seluruh hasil filter.
21. UI web melanjutkan pola Bootstrap lama; loading, empty state, validasi, dan error memakai teks yang jelas serta diuji pada perangkat mobile nyata.
22. URL login admin lama tetap berfungsi atau dialihkan secara kompatibel agar bookmark dan alur lama tidak rusak.
23. Target reliabilitas masa uji adalah sedikitnya 95% pengiriman absensi berhasil pada percobaan pertama ketika jaringan tersedia.
24. API memakai prefix `/api/v1`, format respons JSON konsisten, transaksi untuk penyimpanan daftar absensi, dan `idempotency_key` untuk retry.
