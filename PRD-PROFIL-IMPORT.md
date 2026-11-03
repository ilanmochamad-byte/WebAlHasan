# Profil Pengguna Aplikasi dan Impor Guru/Pengurus — PRD

> **Untuk agen AI:** Dokumen ini adalah instruksi implementasi. Jika ada hal yang tidak jelas, tanyakan kepada Human Developer. Ketika keputusan berubah selama implementasi, perbarui dokumen ini agar tetap menjadi sumber kebenaran. Butir bertanda `(assumption)` belum dikonfirmasi pengguna dan harus diperiksa sebelum dijadikan dasar keputusan yang sulit dibalik.

**Status:** Kebutuhan inti dan pembagian tepat dua fase disetujui Human Developer pada 3 November 2026. Claude Code ditetapkan sebagai implementator dan Codex sebagai auditor, bergantian satu fase per giliran. Fase 1 diimplementasikan Claude Code pada `claude/profil-impor-fase-1` (3 November 2026; bukti di `docs/profil-impor/fase-1/`) dan menunggu audit Codex. Izin migrasi/deploy produksi belum ditetapkan. Pengerjaan mengikuti `AGENTS.md`.

**Keputusan Human Developer 3 November 2026 (sebelum Fase 1):** seluruh delapan butir `(assumption)` disetujui dan diberi kode PI. Tambahan:

- **PI-1** Password sementara dari admin: login aplikasi memberi *token terbatas* yang hanya boleh memanggil ganti password (dan logout); endpoint lain menolak `403 PASSWORD_CHANGE_REQUIRED` sampai password diganti.
- **PI-2** Setelah ganti password berhasil (aplikasi **maupun website**), seluruh token aplikasi lain dan sesi website lain akun itu dicabut; sesi yang sedang dipakai tetap berlaku.
- **PI-3** Admin boleh memilih satu pasangan data lama secara eksplisit per baris bila tidak ada kecocokan pasti; sistem hanya menyarankan calon, tidak menggabungkan otomatis, dan memeriksa ulang saat simpan.
- **PI-4** NIK tepat 16 digit disimpan sebagai teks; NIP tidak dipaksa angka.
- **PI-5** Foto opsional JPEG/PNG/WebP ≤ 2 MiB, folder privat tanpa eksekusi skrip, dilayani endpoint berotorisasi (pemilik dan admin); tanpa foto → inisial.
- **PI-6** Impor menerima `.xlsx`/`.xls` (dibaca sebagai nilai seperti impor santri); foto tidak diimpor dari Excel.
- **PI-7** Data pribadi melekat pada identitas guru/pengurus/wali yang terhubung; guru yang merangkap pengurus memakai profil guru; akun tanpa identitas master menyimpannya pada akun.
- **PI-8** Pengurus **baru** dari impor diberi jabatan teks "Pengurus" (dapat diubah admin); jabatan pengurus lama tidak diubah impor; Tugas Excel hanya tampil di pratinjau.
- **PI-9** Kolom lama `pengurus.nomor_identitas` diperlakukan sebagai **NIP/Nomor identitas** dan menerima kolom NIP impor; NIK disimpan pada kolom baru. Nilai lama tidak diubah; keduanya dipakai untuk pencocokan pasti.
- **PI-10** Baris impor Data Pengurus yang cocok dengan identitas pengurus milik guru menjadi **konflik** dengan petunjuk mengubahnya lewat Data Guru (aturan lama: identitas itu hanya diubah dari Data Guru).

**Hubungan dengan PRD lain:** Ini adalah workstream lintas V1–V7, bukan pengganti PRD V8. Hak Guru, Murobi, Pembimbing, Pendidikan, BSA/Keuangan, Kesehatan, Keamanan, Admin, dan Orang Tua tetap bersumber dari akun serta penugasan yang sudah ada. Profil hanya menampilkan penugasan yang sah; perubahan identitas tidak memberikan hak akses.

## 1. Gambaran Umum

Pengguna aplikasi belum dapat melengkapi profilnya sendiri, dan Beranda belum menampilkan identitas serta tugas aktif secara langsung. Admin juga belum dapat mengimpor identitas lengkap guru dan pengurus dari Excel. Fitur ini menyatukan data pribadi tanpa menggandakan guru yang sekaligus menjadi pengurus, memperlihatkan penugasan yang benar, dan mempercepat pemutakhiran data dari berkas yang sudah dimiliki pesantren.

## 2. Pengguna Sasaran dan Kebutuhan

1. **Semua pemilik akun aplikasi** — Setelah masuk, segera mengenali nama, foto, serta status perannya di Beranda; membuka Profil untuk melihat dan mengubah data pribadi, kontak, foto, serta password sendiri. Ini mencakup Admin, Guru, Pengurus, dan Orang Tua.
2. **Guru dengan penugasan aktif** — Melihat tugas Guru, Murobi, mata pelajaran dan kelas yang diampu; bila menjadi Murobi, melihat kamar/kobong atau kelas yang benar-benar tercakup penugasan aktif.
3. **Pengurus dengan penugasan aktif** — Melihat tugas Pembimbing, BSA/Keuangan, Keamanan, Kesehatan, Pendidikan atau tugas lain yang relevan; bila menjadi Pembimbing, melihat kamar/kobong atau kelas binaannya.
4. **Admin pengelola master data** — Mengimpor identitas lengkap guru/pengurus dari Excel, meninjau kecocokan dengan data lama, menangani konflik per baris, dan memutuskan penugasan di menu Penugasan yang sudah ada.
5. **Pemegang satu akun dengan identitas guru dan pengurus** — Melihat satu profil pribadi yang sama pada kedua identitas; pembaruan nama/nomor HP tidak menghasilkan dua nilai yang bertentangan.

## 3. Fitur Inti (Ruang Lingkup)

1. **Kartu identitas di Beranda aplikasi.** Tepat di bagian atas isi Beranda setelah area aman status bar, tampilkan foto profil atau pengganti berbasis inisial, nama lengkap, dan daftar status pengguna yang berasal dari identitas/peran serta penugasan aktif saat permintaan dibaca. Tampilkan nama kamar/kobong untuk penugasan Murobi atau Pembimbing yang bertarget kamar; bila bertarget kelas, tampilkan kelas dengan label yang tepat. Kartu dapat membuka Profil. Tampilan tetap terbaca ketika nama panjang, status banyak, atau ukuran teks sistem diperbesar.
2. **Halaman Profil dan Edit Profil aplikasi.** Halaman Profil yang sudah ada diperluas menjadi pembaca data diri dan penugasan. Pengguna dapat mengubah Nama Lengkap, Tempat Lahir, Tanggal Lahir, Alamat Rumah, Kelurahan/Desa, Kecamatan, Kabupaten/Kota, Provinsi, No. HP, Email, dan foto. Username, peran, tugas, kamar/kobong, mata pelajaran, serta kelas ditampilkan hanya-baca dan tetap diatur admin melalui sistem penugasan. Simpan perubahan hanya ke identitas milik akun yang sedang masuk, dengan validasi server, audit, dan pemutakhiran tampilan tanpa perlu masuk ulang.
3. **Ganti password sendiri.** Pengguna memasukkan password saat ini, password baru, dan konfirmasi; server memeriksa password saat ini serta aturan kekuatan password yang sama dengan website. Jika lupa, admin menerbitkan password sementara melalui alur akun yang sudah ada. (disetujui PI-1) Aplikasi menyediakan jalur terbatas untuk mengganti password sementara pada login pertama sehingga pemegang akun tidak perlu berpindah ke website; jalur ini tidak membuka menu lain sebelum penggantian berhasil.
4. **Impor Excel Guru dan Pengurus di website admin.** Masing-masing halaman master data menyediakan unduh templat, unggah berkas Excel, pemetaan kolom, pratinjau, pemeriksaan server per baris, pilihan tindakan tambah/perbarui/hubungkan ke data lama, lalu konfirmasi simpan. Header sumber yang diberikan pengguna adalah Nama Lengkap, NIK, Tempat Lahir, Tanggal Lahir, Alamat Rumah, Kelurahan/Desa, Kecamatan, Kabupaten/Kota, Provinsi, No. HP, Email, Tugas, Kamar/Kobong, dan Tugas Tambahan; isian identitas boleh memakai NIP atau NIK. Nomor HP dan NIP/NIK diperlakukan sebagai teks agar nol awal tidak hilang. Kolom Tugas, Kamar/Kobong, dan Tugas Tambahan hanya ditampilkan pada pratinjau sebagai bahan pemeriksaan, tanpa membuat atau mengubah penugasan.
5. **Penyatuan identitas dan kontrol konflik.** Guru yang juga menjadi pengurus tetap memakai relasi `pengurus.guru_id` yang sudah ada; satu profil pribadi menjadi sumber data bagi kedua tampilan. Impor dapat menemukan kecocokan pasti lewat ID sistem/NIP/NIK yang unik. Kemiripan nama tidak boleh melakukan penggabungan otomatis; (disetujui PI-3) admin dapat memilih satu pasangan data lama secara eksplisit pada pratinjau. Baris dengan nomor ganda, identitas yang bertentangan, atau pasangan ambigu ditolak sampai diselesaikan. Ulangi berkas yang sama tidak boleh membuat guru/pengurus kedua.

## 4. Di Luar Ruang Lingkup

- Workstream ini tidak membuat akun, username, atau password secara otomatis dari Excel; admin tetap memakai alur Akun & hak akses yang ada.
- Workstream ini tidak menetapkan, memperpanjang, atau mencabut penugasan dari isi Excel; admin tetap menggunakan menu Penugasan.
- Workstream ini tidak mengubah kewenangan perizinan, pengajian, kesehatan, keuangan, keamanan, rapor, atau data orang tua–santri.
- Workstream ini tidak menambahkan pemulihan password mandiri melalui email/SMS; permintaan lupa password tetap melalui admin.
- Workstream ini tidak menghapus kolom, baris, atau identitas historis guru/pengurus/wali yang sudah ada.
- (disetujui PI-6) Workstream ini tidak mengimpor foto dari sel Excel; foto ditambahkan oleh pemilik akun melalui aplikasi setelah akun tersedia.

## 5. Batasan Teknis dan Keputusan Sebelumnya

- **Stack dan kompatibilitas:** Website memakai PHP/MySQL, aplikasi memakai React Native/Expo. Tambahkan migrasi aditif, API terversi secara kompatibel, preflight, rollback yang tidak menghapus data pribadi lama, serta pengujian pada salinan database. `[JANGAN DIUBAH]` Dilarang mengimplementasikan langsung pada `main` atau menjalankan Claude dan Codex bersamaan pada branch/folder yang sama.
- **Otoritas data:** Nama dan HP guru yang juga menjadi pengurus sudah disinkronkan dari data guru; perluasan profil wajib mempertahankan aturan itu. `users.name`, `users.phone`, `users.email`, `guru`, `pengurus`, dan `wali` yang terhubung tidak boleh menyajikan identitas yang saling bertentangan setelah perubahan yang berhasil. Penugasan guru/pengurus dibaca dari tabel penugasan aktif, bukan kolom `guru.status` lama atau teks Excel. `[JANGAN DIUBAH]`
- **Identitas pribadi:** Simpan tempat/tanggal lahir, alamat berjenjang, email, foto, dan NIK/NIP secara aditif. Nama, kontak, serta alamat yang dipakai akun terkait harus diperbarui secara atomik bersama audit. (disetujui PI-7) Untuk akun tanpa identitas guru/pengurus/wali, data pribadi tambahan ditempelkan pada akun; untuk staf dan wali, identitas yang sudah terhubung menjadi jangkar agar tidak muncul salinan profil per akun.
- **Pengenal impor:** NIP dan NIK adalah dua pengenal yang berbeda; keduanya boleh tersedia, dan sedikitnya salah satu wajib ada pada baris impor. NIP guru lama tetap dipertahankan. (disetujui PI-4) NIK divalidasi sebagai 16 digit dan disimpan sebagai teks; NIP tidak dipaksa menjadi angka. Pencocokan persis mendahului pemilihan pasangan manual. Perubahan NIP/NIK yang sudah dipakai akun/data lama memerlukan konfirmasi admin dan audit.
- **Foto dan privasi:** Foto hanya dapat diunggah oleh pemilik akun yang sah dan dibaca oleh pemiliknya atau admin yang berwenang. (disetujui PI-5) Foto bersifat opsional, dibatasi ke JPEG/PNG/WebP maksimal 2 MiB, disimpan di lokasi yang tidak menerima eksekusi skrip, dan dilayani melalui endpoint yang memeriksa otorisasi; bila belum ada foto, aplikasi menampilkan inisial. Pemilihan foto aplikasi memakai pemilih media bawaan sistem sehingga fitur ini tidak meminta akses menyeluruh ke galeri.
- **Password:** Permintaan ganti password wajib memeriksa password saat ini di server, tidak pernah mencatat password/token pada audit atau respons, menerapkan aturan minimal 12 karakter dengan huruf besar, huruf kecil, dan angka seperti website, serta mencabut atau memperbarui sesi lain sesuai kebijakan keamanan yang dicatat pada implementasi. (disetujui PI-2) Sesi lain dicabut setelah password berhasil diganti.
- **Impor:** (disetujui PI-6) Terima `.xlsx` dan `.xls`; sistem memproses data sebagai teks/nilai terstruktur, bukan mengeksekusi rumus atau makro. Ukuran berkas dan jumlah baris dibatasi serta ditampilkan sebelum simpan. Pratinjau dan simpan memakai validasi server yang sama; perubahan data di antara kedua langkah menyebabkan pemeriksaan ulang dan konflik, bukan penimpaan diam-diam. Setiap operasi impor menyimpan ringkasan audit tanpa menyalin seluruh data pribadi mentah ke log.
- **Penugasan pada kartu:** Tampilkan hanya tugas yang masih aktif menurut penugasan, cakupan, tahun ajaran, dan status master saat permintaan. Status Admin/Orang Tua berasal dari akun/relasi yang sah. Label dapat bertumpuk untuk satu orang; tidak ada dropdown “tugas utama” yang dapat diubah sendiri.
- **Data yang ditampilkan/disimpan:** Nama, NIP/NIK bila relevan, tempat/tanggal lahir, alamat rumah dan wilayah, HP, email, foto, username hanya-baca, status/peran, kamar/kobong atau kelas binaan, serta mata pelajaran dan kelas mengajar. Penerapan pada semua akun tidak boleh membuka identitas atau penugasan pengguna lain.

## 6. Persyaratan Bertahap

Tepat dua fase. Fase 2 bergantung pada kontrak data dan API Fase 1. Claude Code mengimplementasikan satu fase, lalu Codex mengauditnya sebelum fase berikutnya dimulai atas instruksi Human Developer. Ketersediaan spesifikasi bukan perintah menjalankan keduanya sekaligus.

### Fase 1: Database, API, dan Website Admin

**Tujuan:** Admin dapat mengimpor/memperbarui identitas guru dan pengurus melalui website, sementara API profil sudah dapat dibaca dan diubah secara aman dengan klien uji.

**Persyaratan:**

1. Periksa `AGENTS.md`, PRD ini, status/commit terbaru kedua repositori, skema dan migrasi terakhir, sinkronisasi guru–pengurus, akun wali, penugasan aktif, serta pola impor santri yang sudah ada; kerjakan pada branch fitur baru.
2. Tambahkan penyimpanan identitas pribadi secara aditif untuk guru, pengurus, wali, dan akun tanpa identitas master; pertahankan seluruh ID/relasi/riwayat lama dan sediakan preflight serta rollback aman.
3. Buat satu layanan baca profil yang mengembalikan data pribadi, username, foto, status/peran aktif, target kamar/kobong atau kelas Murobi/Pembimbing, dan mata pelajaran–kelas guru dari penugasan yang berlaku. Gunakan struktur aditif agar klien lama tetap berjalan.
4. Buat API ubah profil diri, unggah/ganti foto, serta ganti password; semua mutasi memeriksa pemilik akun, validasi server, konsistensi identitas terkait, dan audit. Pastikan masukan klien tidak dapat menulis username, role, penugasan, kamar, mapel, kelas, atau identitas akun lain.
5. Perluas formulir/detail Data Guru dan Data Pengurus agar admin dapat melihat dan memperbaiki field identitas baru, termasuk NIP dan/atau NIK, tanpa mengaktifkan kolom tugas lama sebagai sumber hak.
6. Tambahkan impor Excel terpisah di Data Guru dan Data Pengurus, dengan templat yang mencantumkan semua field identitas; pemetaan header sumber; pratinjau tindakan per baris; pencocokan pasti atau konfirmasi manual; laporan konflik; dan simpan terverifikasi. Tugas, Kamar/Kobong, dan Tugas Tambahan hanya menjadi kolom informasi pada pratinjau.
7. Jangan membuat akun atau penugasan otomatis. Pastikan ulang impor idempoten, tidak menimpa nilai yang lebih baru tanpa konfirmasi, dan guru yang merangkap pengurus hanya mempunyai satu sumber identitas pribadi.
8. Dokumentasikan perubahan kontrak API, langkah migrasi/rollback, hasil uji MySQL pada salinan data atau fixture sintetis, keterbatasan pengujian, serta handoff audit Fase 1.

**Kriteria penerimaan:**

- [ ] Admin mengunggah file berisi sedikitnya satu guru baru, satu guru lama, satu pengurus baru, dan satu pengurus lama; pratinjau menunjukkan tindakan/konflik per baris sebelum perubahan database.
- [ ] Berkas yang sama diimpor dua kali tanpa kenaikan jumlah guru/pengurus setelah impor kedua; ID, NIP, dan relasi lama tetap sama.
- [ ] Dua baris bernomor sama, pasangan nama ambigu, atau NIP/NIK yang mengarah ke dua orang berbeda menghasilkan konflik dan tidak membuat catatan ganda.
- [ ] Nilai Tugas/Kamar/Kobong/Tugas Tambahan dalam Excel tidak mengubah satu pun baris penugasan maupun hak akses.
- [ ] Perubahan identitas guru yang juga menjadi pengurus terlihat konsisten pada kedua identitas dan akun terkait; perubahan yang gagal validasi/audit tidak meninggalkan pembaruan sebagian.
- [ ] API profil untuk akun Admin, Guru, Pengurus, dan Orang Tua mengembalikan hanya data pemilik akun dan penugasan aktif; permintaan menulis username/role/penugasan atau membaca foto akun lain oleh akun tanpa kewenangan ditolak.
- [ ] Ganti password dengan password saat ini yang salah ditolak; password baru yang sah diterima; password/token tidak muncul di audit atau respons.
- [ ] Migrasi dan rollback dijalankan pada salinan MySQL tanpa menghapus data guru, pengurus, wali, akun, atau relasi yang sudah ada.

### Fase 2: Aplikasi — Kartu Beranda dan Profil (memerlukan API Fase 1)

**Tujuan:** Semua pemilik akun aplikasi dapat melihat kartu identitas di Beranda, membuka Profil, mengedit data diri/foto, dan mengganti password melalui aplikasi.

**Persyaratan:**

1. Baca hasil audit Fase 1 dan kontrak API final; kerjakan hanya perubahan aplikasi yang memakai API tersebut pada branch fitur baru, tanpa mengulang desain sumber hak di klien.
2. Tempatkan kartu identitas sebagai komponen pertama isi Beranda setelah area aman status bar. Tampilkan foto/inisial, nama penuh, label seluruh status yang relevan, dan nama kamar/kobong atau kelas bagi Murobi/Pembimbing. Kartu membuka Profil.
3. Perluas tab Profil yang sudah ada menjadi detail identitas pribadi dan penugasan hanya-baca. Tampilkan mapel dan kelas guru dari API, termasuk kombinasi lebih dari satu mapel/kelas; jangan menebak dari jadwal hari ini.
4. Buat Edit Profil dengan formulir seluruh isian pribadi yang disetujui, pemilihan dan penggantian foto melalui pemilih bawaan sistem, validasi pesan kesalahan, serta penyegaran kartu/Profil setelah simpan.
5. Buat Ganti Password dengan password saat ini, password baru, konfirmasi, dan penanganan password sementara dari admin sesuai kontrak Fase 1. Jangan menyimpan password pada penyimpanan lokal, log, atau analytics.
6. Tangani pemuatan, gagal jaringan, sesi kedaluwarsa, foto gagal dimuat, dan akun tanpa penugasan/foto. Fitur lain serta navigasi peran yang sudah berjalan tetap dapat digunakan.
7. Uji tampilan Android dan iOS pada ukuran teks biasa/besar, nama panjang, banyak status, dan seluruh kelompok akun; dokumentasikan hasil nyata serta batas uji sebelum handoff audit.

**Kriteria penerimaan:**

- [ ] Pada akun Admin, Guru, Pengurus, Orang Tua, dan akun dengan lebih dari satu tugas, kartu muncul di bagian paling atas Beranda dan menampilkan nama/foto atau inisial yang benar tanpa memotong teks pada ukuran teks besar.
- [ ] Murobi/Pembimbing melihat target kamar/kobong yang aktif; target kelas diberi label Kelas; akun tanpa target tidak diberi nama kamar rekaan.
- [ ] Guru melihat seluruh pasangan mata pelajaran–kelas aktif; tugas yang dicabut hilang dari kartu/Profil setelah data dimuat ulang.
- [ ] Pengguna mengubah setiap field pribadi yang disetujui, dan nilai baru terlihat pada Profil serta kartu tanpa masuk ulang; username, tugas, kamar, mapel, dan kelas tidak dapat diedit.
- [ ] Penggantian foto melalui pemilih sistem berhasil dan aplikasi tidak meminta izin akses menyeluruh ke seluruh galeri untuk fitur ini.
- [ ] Ganti password berhasil hanya dengan password saat ini yang benar dan password baru yang memenuhi aturan; kasus password sementara dari admin berjalan sesuai kontrak Fase 1.
- [ ] Akses API lintas akun ditolak dan fitur Beranda, pengajian, perizinan, pembinaan, keuangan, kesehatan, serta keamanan yang sudah ada tetap lulus uji regresi relevan.

## 7. Ukuran Keberhasilan

- Satu impor ulang berkas yang sama menghasilkan **0** guru/pengurus baru dan **0** penugasan berubah.
- **100%** mutasi profil yang berhasil memiliki catatan audit; **0** perubahan username/penugasan dari API edit diri.
- Kartu identitas tampil untuk **semua kelompok akun aplikasi** yang memiliki sesi sah, dengan data pribadi dan penugasan dari server.
- **0** pembacaan foto/profil akun lain oleh akun tanpa kewenangan pada uji negatif.
