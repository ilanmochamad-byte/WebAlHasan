# Sistem Perizinan Santri Al Hasan — PRD V2

> **Untuk agen AI:** Dokumen ini adalah instruksi implementasi. Seluruh keputusan dalam dokumen ini telah dikonfirmasi pengguna. Jika ada bagian yang tidak jelas, jangan menebak—tanyakan kepada pengguna. Jika suatu keputusan berubah selama implementasi, perbarui dokumen ini agar tetap menjadi sumber kebenaran (*living document*).

## 1. Gambaran Umum

Proses izin santri belum memiliki alur digital lengkap dari pengurus kepada murobi: pengajuan belum diarahkan otomatis kepada pihak yang tepat, status dan alasan keputusan sulit dipantau, serta laporan pertanggungjawaban belum memadai. V2 bertujuan membangun satu alur perizinan yang aman, dapat diaudit, dan dapat digunakan melalui website maupun aplikasi oleh pengurus, murobi, admin, dan orang tua tanpa menduplikasi pengajuan atau keputusan.

V2 merupakan pengembangan dari V1 yang telah selesai. Seluruh akun, role, master santri/wali/pengurus, penugasan murobi, audit, REST API, aplikasi Expo, migrasi, dan fungsi lama yang relevan harus dimanfaatkan serta dipertahankan.

## 2. Pengguna Sasaran & JTBD

1. **Pengurus** — Ketika santri membutuhkan izin, pengurus ingin memilih santri dalam lingkup tanggung jawabnya, mencatat rentang waktu dan alasan, mengirim pengajuan kepada murobi yang tepat, serta memantau hasilnya tanpa komunikasi manual terpisah.
2. **Murobi** — Murobi adalah **guru yang mendapat penugasan murobi**. Ketika menerima pengajuan dari pengurus, murobi ingin memeriksa identitas santri dan rincian izin, lalu menyetujui atau menolak dengan alasan yang tercatat.
3. **Admin** — Ketika mengawasi operasional, admin ingin melihat seluruh pengajuan, memperbaiki routing, mengelola akun/pengaturan, dan mengambil keputusan sebagai pengganti murobi jika diperlukan dengan alasan dan audit yang jelas.
4. **Orang tua/wali** — Ketika izin anak diajukan atau diputuskan, orang tua ingin masuk menggunakan username/password untuk melihat status dan riwayat izin hanya bagi santri yang terhubung dengannya.
5. **Pembimbing** — Pembimbing bukan murobi dan bukan identitas guru. Pembimbing merupakan tugas inti atau tugas tambahan dari **pengurus**; cakupan tugas pembimbing digunakan untuk menentukan santri yang dapat dikelola pengurus.

Semua peran di atas dapat menggunakan website dan aplikasi mobile sesuai hak aksesnya.

## 3. Fitur Inti (Ruang Lingkup)

1. **Fondasi akun dan cakupan peran** — Admin dapat membuat atau menghubungkan akun pengurus dan orang tua ke master data yang sudah tersedia. Guru yang mempunyai penugasan murobi aktif memperoleh kemampuan mengambil keputusan tanpa mengubah identitas dasarnya sebagai guru. Pembimbing dikelola sebagai penugasan pengurus, bukan sebagai guru atau murobi.

2. **Pengajuan izin oleh pengurus** — Pengurus memilih santri yang berada dalam cakupan tugasnya, mengisi tanggal mulai, tanggal kembali, alasan, dan catatan, kemudian mengirim pengajuan. Sistem memvalidasi relasi, rentang tanggal, status santri, duplikasi, dan otorisasi sebelum menyimpan.

3. **Routing kepada murobi** — Sistem mencari penugasan murobi aktif berdasarkan tahun ajaran dan kelompok santri. Pengajuan yang memiliki satu murobi valid langsung masuk ke antrean murobi tersebut; kasus tanpa murobi atau dengan lebih dari satu kandidat masuk ke antrean admin untuk penetapan manual.

4. **Keputusan dan pengawasan** — Murobi yang berwenang dapat menyetujui atau menolak satu kali dengan alasan keputusan. Admin dapat menggantikan murobi bila diperlukan, tetapi wajib mencatat alasan penggantian. Koreksi setelah keputusan tidak menimpa riwayat; sistem menyimpan peristiwa perubahan dan pelakunya.

5. **Akses status orang tua** — Orang tua masuk dengan akun yang terhubung ke data wali dan hanya dapat melihat pengajuan, keputusan, serta riwayat santri yang memiliki relasi aktif dengannya. Orang tua tidak dapat membuat, mengubah, menyetujui, atau menolak pengajuan.

6. **Notifikasi multikanal** — Sistem selalu menyediakan notifikasi di dalam aplikasi, mendukung push notification, dan menyediakan WhatsApp opsional. Admin dapat mengaktifkan/menonaktifkan tiap kanal; WhatsApp hanya dapat diaktifkan setelah konfigurasi infrastrukturnya lolos pemeriksaan.

7. **Laporan, cetak, dan audit** — Admin melihat laporan seluruh pengajuan; pengurus, murobi, dan orang tua hanya melihat data sesuai cakupannya. Laporan dapat difilter, dibuka rinciannya, dicetak, dan diekspor. Pengajuan, routing, keputusan, penggantian admin, perubahan pengaturan, dan pengiriman notifikasi tercatat tanpa menyimpan credential atau secret.

## 4. Di Luar Ruang Lingkup V2

- V2 tidak mengizinkan orang tua mengajukan izin; orang tua hanya melihat status dan riwayat.
- V2 tidak menyediakan akun atau portal untuk santri.
- V2 tidak menerapkan persetujuan berjenjang dari murobi lalu admin; keputusan cukup oleh murobi atau admin sebagai pengganti.
- V2 tidak mengimplementasikan konseling atau alur pelanggaran santri.
- V2 tidak mengimplementasikan tagihan, pembayaran, atau rekonsiliasi keuangan.
- V2 tidak mengembangkan alur penerimaan santri baru atau biaya PSB.
- V2 tidak mengimplementasikan penilaian semester atau rapor santri.
- V2 tidak menggunakan GPS, QR, biometrik, pengenalan wajah, bukti foto, atau lampiran dokumen untuk perizinan.
- V2 tidak mengirim WhatsApp jika admin mematikan fitur atau infrastruktur WhatsApp belum dikonfigurasi dan diverifikasi.
- V2 tidak mengganti PHP native dengan Laravel atau framework backend lain.
- V2 tidak melakukan desain ulang penuh terhadap website publik atau aplikasi di luar layar yang diperlukan untuk perizinan.

## 5. Batasan Teknis & Keputusan yang Sudah Diambil

### 5.1 Keputusan yang tidak boleh dibuka kembali tanpa persetujuan pengguna

- **Backend:** pertahankan PHP native modular, MySQL, migrasi SQL berversi, dan REST API yang sudah dibangun pada V1. **[JANGAN DIUBAH]**
- **Mobile:** pertahankan proyek Expo 57, React Native 0.86, Expo Router, dan TypeScript strict di `/Users/ilanmochamad/alhasanApps`. **[JANGAN DIUBAH]**
- **Website:** seluruh peran V2 dapat memakai website; pertahankan pola Bootstrap dan autentikasi V1. **[JANGAN DIUBAH]**
- **Murobi:** murobi adalah guru dengan penugasan murobi aktif. **[JANGAN DIUBAH]**
- **Pembimbing:** pembimbing adalah tugas inti/tambahan pengurus, bukan guru atau murobi. **[JANGAN DIUBAH]**
- **Keputusan:** murobi yang ditugaskan menjadi pemberi keputusan utama; admin dapat menggantikan bila diperlukan. **[JANGAN DIUBAH]**
- **Orang tua:** menggunakan akun username/password yang terhubung ke data wali dan santri. **[JANGAN DIUBAH]**
- **Notifikasi:** in-app dan push didukung; WhatsApp opsional serta dikendalikan admin. **[JANGAN DIUBAH]**
- **Kompatibilitas:** jangan membangun ulang atau merusak fungsi V1, data lama, API, website publik, maupun aplikasi guru. **[JANGAN DIUBAH]**

### 5.2 Model identitas dan otorisasi

- Tambahkan role `pengurus` dan `orang_tua` pada sistem role yang ada; admin tetap `admin`, sedangkan guru tetap `guru`, agar autentikasi dan otorisasi V1 dapat digunakan tanpa sistem akun kedua.
- Hak murobi berasal dari akun guru, role `guru`, dan `murobi_assignments` aktif yang cocok dengan santri; role terpisah `murobi` tidak diperlukan karena pengguna menegaskan murobi adalah guru yang mendapat penugasan.
- Hubungkan akun pengurus ke tepat satu baris `pengurus` aktif dan akun orang tua ke tepat satu baris `wali` aktif melalui foreign key unik.
- Satu wali dapat terhubung ke beberapa santri melalui `santri_wali`; orang tua hanya boleh membaca santri dengan relasi yang aktif.
- Tambahkan penugasan pembimbing yang menghubungkan pengurus dengan kamar atau kelas dan tahun ajaran.
- Pengurus hanya dapat mengajukan izin untuk santri dalam penugasan pembimbing aktifnya; admin dapat mengajukan untuk santri mana pun jika diperlukan dan tindakan tersebut diaudit.
- Semua pemeriksaan cakupan dan role wajib dilakukan di server, bukan hanya dengan menyembunyikan tombol pada UI.

### 5.3 Alur dan aturan perizinan

- Status alur menggunakan `Diajukan`, `Perlu Penetapan Admin`, `Disetujui`, `Ditolak`, dan `Dibatalkan` sebagai himpunan minimum untuk mewakili routing, keputusan, dan pembatalan tanpa workflow berjenjang.
- Saat pengurus mengirim, sistem membuat pengajuan langsung berstatus `Diajukan`; draf lokal/server tidak termasuk V2.
- Routing menilai penugasan murobi yang aktif pada tanggal pengajuan, tahun ajaran aktif, serta kamar/kelas aktif santri.
- Jika kamar dan kelas sama-sama menghasilkan murobi berbeda, pengajuan berstatus `Perlu Penetapan Admin` sampai admin memilih murobi.
- Admin dapat menetapkan ulang murobi selama belum ada keputusan; alasan penetapan ulang wajib diisi.
- Pengurus dapat membatalkan pengajuan hanya sebelum keputusan dan wajib mengisi alasan pembatalan.
- Keputusan `Disetujui` atau `Ditolak` bersifat satu peristiwa atomik; retry request yang sama tidak membuat keputusan tambahan.
- Admin yang mengambil keputusan sebagai pengganti wajib mengisi alasan dan audit menandainya sebagai keputusan pengganti.
- Sistem menolak pengajuan baru untuk santri yang sama jika rentang tanggalnya tumpang tindih dengan pengajuan `Diajukan`, `Perlu Penetapan Admin`, atau `Disetujui`.
- Koreksi keputusan dilakukan melalui peristiwa koreksi yang menyimpan nilai sebelum/sesudah dan alasan, bukan menghapus riwayat.

### 5.4 Data yang disimpan atau ditampilkan

- **Pengajuan:** ID, santri, pengurus pengaju, penugasan pembimbing, murobi tujuan, tahun ajaran, tanggal izin, tanggal kembali, alasan, catatan pengurus, status, waktu pengajuan, dan idempotency key.
- **Keputusan:** pengajuan, hasil, alasan keputusan, pengguna pemberi keputusan, kapasitas pemberi keputusan (`Murobi` atau `Admin Pengganti`), waktu, dan versi.
- **Riwayat status:** status sebelum/sesudah, pelaku, alasan, waktu, IP, serta user agent tanpa credential.
- **Akun pengurus:** relasi user–pengurus, role, status aktif, dan kewajiban ganti password awal.
- **Akun orang tua:** relasi user–wali, role, status aktif, dan daftar santri dari relasi wali aktif.
- **Penugasan pembimbing:** pengurus, tahun ajaran, target kamar/kelas, tanggal mulai/selesai, status, pembuat, dan audit.
- **Notifikasi:** penerima, kanal, tipe peristiwa, judul, isi aman, referensi pengajuan, status baca/kirim, waktu, percobaan, dan error terakhir.
- **Perangkat push:** pengguna, token perangkat terenkripsi/terlindungi, platform, waktu aktif terakhir, dan pencabutan.
- **Pengaturan kanal:** status in-app, push, WhatsApp, provider WhatsApp, serta hasil pemeriksaan konfigurasi; secret tetap berada di environment dan tidak disimpan dalam audit.
- **Laporan:** rentang tanggal, status, santri, pengurus, murobi, kamar/kelas, tahun ajaran, durasi keputusan, dan kanal notifikasi.

### 5.5 Migrasi dan kompatibilitas data lama

- Gunakan tabel `perizinan` lama sebagai sumber migrasi dan pertahankan setiap ID serta nilai `id_santri`, `tgl_izin`, `tgl_kembali`, `alasan`, dan `status`.
- Pemetaan status lama: `Pending` menjadi `Diajukan`, `Disetujui` tetap `Disetujui`, dan `Ditolak` tetap `Ditolak`.
- Data lama yang tidak mempunyai pengurus, murobi, keputusan, atau audit tetap dapat dibaca dengan penanda `Data warisan`; jangan mengarang pelaku.
- Jangan menghapus tabel atau kolom lama sebelum migrasi pada salinan MySQL, perbandingan jumlah baris, smoke test, backup/restore, dan persetujuan pengguna selesai.
- Semua perubahan skema bersifat aditif dan mempunyai migrasi naik, petunjuk rollback, preflight, serta laporan konflik.

### 5.6 API, website, dan aplikasi

- Tambahkan endpoint secara kompatibel di bawah `/api/v1`; jangan mengubah kontrak endpoint V1 yang sudah dipakai aplikasi guru, karena perubahan V2 bersifat aditif dan tidak membutuhkan breaking change.
- Aplikasi menampilkan navigasi berdasarkan kemampuan pengguna: pengurus, murobi/guru, admin, dan orang tua.
- Akun dengan beberapa kemampuan mendapatkan satu sesi dan dapat berpindah menu tanpa login ulang.
- Website menyediakan portal responsif berbasis role dan tetap melindungi seluruh mutasi dengan CSRF.
- Mobile menyimpan token dengan SecureStore serta menggunakan client error/refresh/logout yang sudah ada.
- Setiap create/decision/cancel/reassign menerima `idempotency_key`; server menyimpan hash request untuk membedakan retry dari konflik dan memenuhi target pengguna agar tidak ada pengajuan/keputusan ganda.
- Pagination, filter, envelope JSON, status HTTP, dan pembatasan akses mengikuti konvensi API V1.

### 5.7 Notifikasi

- Notifikasi in-app selalu tersedia sebagai sumber status utama.
- Push memakai `expo-notifications` dan token perangkat per pengguna/perangkat yang dapat dicabut.
- WhatsApp menggunakan adapter provider yang tidak mengunci sistem pada satu vendor.
- WhatsApp default `OFF`; admin hanya dapat mengaktifkannya setelah endpoint pemeriksaan konfigurasi berhasil.
- Pengiriman WhatsApp berjalan melalui outbox dan proses cron yang kompatibel dengan hosting cPanel agar request pengguna tidak menunggu provider eksternal.
- Kegagalan push atau WhatsApp tidak membatalkan pengajuan/keputusan; transaksi perizinan merupakan sumber kebenaran, sedangkan kegagalan kanal dicatat dan dapat dicoba ulang tanpa duplikasi.
- Isi push dan WhatsApp tidak memuat alasan izin lengkap atau data sensitif; penerima membuka aplikasi untuk melihat detail.
- Peristiwa minimum: pengajuan baru kepada murobi/admin, penetapan murobi, keputusan kepada pengurus/orang tua, pembatalan, dan koreksi.

## 6. Persyaratan Bertahap

### Fase 1: Migrasi Perizinan, Akun, dan Otorisasi

**Tujuan:** Menghasilkan fondasi data V2 yang aman serta portal web dasar yang dapat membaca perizinan lama tanpa merusak V1.

**Persyaratan:**

1. Inventarisasi skema produksi/salinan untuk `perizinan`, `users`, `roles`, `pengurus`, `wali`, `santri_wali`, `murobi_assignments`, `plotting_kamar`, dan `plotting_kelas` sebelum migrasi.
2. Buat backup, manifest jumlah baris, laporan relasi yatim, dan laporan pengajuan lama sebelum perubahan.
3. Tambahkan migrasi berversi untuk role/relasi akun pengurus dan orang tua, penugasan pembimbing, pengajuan, keputusan, riwayat status, idempotensi, notifikasi, perangkat, serta pengaturan kanal.
4. Migrasikan data `perizinan` lama secara aditif dengan ID dan nilai bisnis tetap dipertahankan.
5. Tandai kolom pelaku yang tidak diketahui sebagai `NULL`/data warisan; jangan memakai akun admin palsu.
6. Tambahkan UI admin untuk membuat/menghubungkan akun pengurus dan orang tua, menonaktifkan akun, mereset password, serta memeriksa relasi wali–santri.
7. Tambahkan UI admin untuk mengelola penugasan pembimbing pengurus per kamar/kelas dan tahun ajaran.
8. Tambahkan guard role/kemampuan untuk portal pengurus, murobi, admin, dan orang tua pada website.
9. Buat halaman web baca-saja perizinan lama dan baru sesuai scope pengguna.
10. Pertahankan login, API guru, laporan absensi, dan fungsi V1 tanpa perubahan kontrak.
11. Tambahkan audit untuk akun, role, relasi akun, penugasan pembimbing, dan migrasi data.
12. Sediakan preflight, migrasi naik, verifikasi, dan petunjuk rollback/restore.

**Kriteria penerimaan:**

- [ ] Jumlah dan ID pengajuan lama sebelum/sesudah migrasi sama; seluruh nilai bisnis lama masih dapat dibaca.
- [ ] Data lama tanpa pelaku tampil sebagai `Data warisan` dan tidak menunjuk pengguna fiktif.
- [ ] Admin dapat menghubungkan satu akun pengurus dan satu akun orang tua ke master data masing-masing.
- [ ] Satu akun orang tua hanya melihat santri dengan relasi wali aktif.
- [ ] Guru tanpa penugasan murobi aktif tidak mendapatkan kemampuan keputusan izin.
- [ ] Penugasan pembimbing hanya dapat menggunakan pengurus aktif dan target kamar/kelas yang valid.
- [ ] Setiap portal menolak role yang tidak berwenang dengan `403` atau redirect aman.
- [ ] Login dan endpoint V1 guru tetap lulus pengujian regresi.
- [ ] Backup dapat dipulihkan pada database `_test` dan jumlah baris tabel inti cocok dengan manifest.
- [ ] Seluruh file PHP baru/diubah lolos `php -l` dan seluruh tes V1 tetap lulus.

### Fase 2: Pengajuan, Routing, dan Keputusan Web (memerlukan Fase 1)

**Tujuan:** Menyelesaikan alur pengajuan hingga keputusan melalui website dengan routing dan audit yang dapat diverifikasi.

**Persyaratan:**

1. Pengurus dapat melihat santri dalam cakupan penugasan pembimbing aktifnya.
2. Pengurus dapat membuat pengajuan dengan santri, tanggal izin/kembali, alasan, dan catatan.
3. Validasi server menolak santri di luar cakupan, tanggal kembali sebelum tanggal izin, data tidak aktif, dan pengajuan tumpang tindih.
4. Create memakai transaksi dan idempotency key untuk mencegah duplikasi.
5. Routing memilih murobi dari penugasan aktif yang cocok dengan kamar/kelas dan tahun ajaran.
6. Kasus tanpa kandidat atau lebih dari satu kandidat masuk ke antrean penetapan admin.
7. Murobi hanya melihat dan memutus pengajuan yang diarahkan kepadanya.
8. Admin dapat menetapkan/menetapkan ulang murobi dan mengambil keputusan sebagai pengganti dengan alasan wajib.
9. Keputusan memakai transaksi, optimistic version, dan idempotency key; request bersamaan hanya menghasilkan satu keputusan.
10. Pengurus dapat membatalkan pengajuan sebelum keputusan dengan alasan.
11. Orang tua dapat melihat status dan riwayat izin santri yang terhubung dengannya.
12. Semua transisi status, routing, keputusan, pembatalan, dan koreksi tercatat pada riwayat serta audit.
13. Sediakan daftar, detail, pencarian, filter, pagination, dan empty/error state untuk tiap peran.
14. Arsipkan modul `admin/admin_izin.php` lama melalui redirect kompatibel setelah alur baru lolos regresi; jangan menghapus file/data sebelum persetujuan.

**Kriteria penerimaan:**

- [ ] Pengurus hanya dapat memilih santri dalam cakupan pembimbingnya.
- [ ] Pengajuan dengan tanggal kembali sebelum tanggal izin ditolak dengan `422` dan tidak menyimpan baris.
- [ ] Dua request identik dengan idempotency key sama menghasilkan satu pengajuan.
- [ ] Pengajuan tumpang tindih untuk santri dan rentang aktif yang sama ditolak dengan `409`.
- [ ] Pengajuan dengan satu murobi valid muncul pada antrean murobi tersebut.
- [ ] Pengajuan tanpa routing tunggal muncul pada antrean admin dan tidak terlihat oleh murobi yang tidak ditetapkan.
- [ ] Murobi A menerima `403` ketika mencoba memutus pengajuan milik Murobi B.
- [ ] Dua keputusan bersamaan menghasilkan tepat satu keputusan dan satu status akhir.
- [ ] Admin pengganti tidak dapat memutus tanpa alasan; keputusan yang valid menyimpan kapasitas `Admin Pengganti`.
- [ ] Orang tua A menerima `403` untuk pengajuan santri yang tidak terhubung dengannya.
- [ ] Pembatalan/koreksi tidak menghapus keputusan atau riwayat sebelumnya.
- [ ] Seluruh perubahan memiliki pelaku, waktu, dan alasan yang sesuai pada riwayat/audit.

### Fase 3: REST API dan Aplikasi Multi-Peran (memerlukan Fase 2)

**Tujuan:** Menyediakan alur perizinan lengkap pada aplikasi mobile dan portal web responsif untuk seluruh peran.

**Persyaratan:**

1. Dokumentasikan endpoint akun/capability, daftar santri pengurus, pengajuan, antrean murobi/admin, keputusan, pembatalan, status orang tua, dan riwayat.
2. Pertahankan envelope JSON, autentikasi bearer, pagination, filter, status HTTP, dan pencabutan token V1.
3. Tambahkan capability pada profil agar aplikasi membangun navigasi berdasarkan hak aktual.
4. Aplikasi menyediakan menu perizinan untuk pengurus, murobi/guru, admin, dan orang tua.
5. Pengurus dapat mencari santri yang berhak, membuat pengajuan, mengonfirmasi, mengirim, melihat status, dan membatalkan jika masih diizinkan.
6. Murobi dapat melihat antrean, membuka detail, menyetujui/menolak dengan alasan, serta melihat riwayat keputusan.
7. Admin dapat memantau seluruh pengajuan, memperbaiki routing, dan mengambil keputusan pengganti.
8. Orang tua dapat melihat daftar anak, status izin, detail keputusan, dan riwayat tanpa tombol mutasi.
9. Tombol mutasi dinonaktifkan saat request; retry memakai idempotency key yang sama.
10. Aplikasi menangani loading, empty state, offline/network error, `401`, `403`, `409`, dan `422` secara dapat ditindaklanjuti.
11. Website menyediakan fungsi setara untuk setiap peran sesuai haknya.
12. Tambahkan pengujian kontrak API, otorisasi lintas peran, idempotensi, concurrency, dan regresi aplikasi guru.

**Kriteria penerimaan:**

- [ ] Pengurus dapat menyelesaikan alur mobile dari login sampai pengajuan tersimpan dan terbaca kembali.
- [ ] Murobi menerima pengajuan yang ditetapkan, memberi keputusan, dan hasilnya terlihat oleh pengurus serta orang tua.
- [ ] Admin dapat melakukan keputusan pengganti dari website dan aplikasi dengan alasan wajib.
- [ ] Orang tua hanya melihat pengajuan milik santri terhubung pada website dan aplikasi.
- [ ] Perubahan parameter API lintas pengurus, murobi, atau orang tua selalu ditolak server.
- [ ] Retry create dan decision tidak menambah pengajuan atau keputusan.
- [ ] Konflik versi/keputusan kedua mengembalikan `409` tanpa menimpa keputusan pertama.
- [ ] Logout mencabut token dan sesi lama tidak dapat mengakses endpoint V2.
- [ ] `npm run lint`, `npx tsc --noEmit`, pemeriksaan PHP, tes API, dan tes regresi V1 lulus.
- [ ] Alur utama tiap peran lulus pada sedikitnya satu perangkat Android dan satu perangkat iOS.

> **Keputusan penerimaan Fase 3 — 23 Agustus 2026:** pemilik produk menerima
> iPhone fisik dan simulator Android 16 sebagai bukti pengganti untuk gerbang
> Fase 3. Android fisik tidak diuji. Risiko perangkat-spesifik Android diterima
> agar Fase 4 dapat dimulai. Pengecualian ini tidak menghapus kewajiban uji
> Android/iOS yang secara khusus tercantum pada kriteria Fase 4.

### Fase 4: Notifikasi In-App, Push, dan WhatsApp Opsional (memerlukan Fase 3)

**Tujuan:** Memberi tahu pihak terkait tentang perubahan izin melalui kanal yang aman, dapat dikendalikan, dan tidak mengganggu transaksi utama.

**Persyaratan:**

1. Buat notifikasi in-app pada pengajuan, routing admin, penetapan murobi, keputusan, pembatalan, dan koreksi.
2. Sediakan pusat notifikasi web/mobile, jumlah belum dibaca, detail, tandai dibaca, dan pagination.
3. Registrasikan serta cabut token push per pengguna/perangkat menggunakan `expo-notifications`.
4. Kirim push tanpa alasan lengkap atau data sensitif; deep link membuka detail setelah otorisasi.
5. Sediakan halaman admin untuk status kanal, pengujian konfigurasi, dan sakelar on/off.
6. Buat adapter provider WhatsApp dan konfigurasi environment tanpa secret pada database/log/audit.
7. Terapkan outbox dengan unique event/channel/recipient agar retry tidak mengirim duplikat.
8. Jalankan worker/cron cPanel untuk push dan WhatsApp; sediakan perintah manual yang aman untuk pengujian.
9. Catat status `Queued`, `Sent`, `Failed`, jumlah percobaan, error aman, dan waktu terakhir.
10. Sediakan retry terbatas dengan backoff; kegagalan permanen dapat dilihat admin.
11. Jika WhatsApp off/tidak siap, pengajuan tetap berhasil dan in-app/push berjalan sesuai pengaturan.
12. Audit perubahan kanal dan pengujian konfigurasi tanpa menyimpan credential.

**Kriteria penerimaan:**

- [ ] Setiap peristiwa yang ditentukan menghasilkan satu notifikasi in-app untuk penerima yang berhak.
- [ ] Pengguna tidak dapat membaca notifikasi pengguna lain melalui perubahan ID.
- [ ] Push tiba pada perangkat uji Android dan iOS tanpa memuat alasan izin lengkap.
- [ ] Menonaktifkan push menghentikan enqueue baru tanpa mengganggu in-app.
- [ ] WhatsApp tidak dapat diaktifkan jika pemeriksaan konfigurasi gagal.
- [ ] Saat WhatsApp aktif dan provider siap, pesan uji serta satu notifikasi keputusan berhasil dikirim.
- [ ] Saat WhatsApp mati/tidak siap, pengajuan dan keputusan tetap berhasil tanpa request ke provider.
- [ ] Retry event yang sama tidak menghasilkan pesan ganda pada kanal yang sama.
- [ ] Secret provider tidak muncul di respons API, log, audit, database, atau bundle mobile.
- [ ] Status kirim dan error aman dapat dilihat admin; perubahan sakelar tercatat pada audit.

> **Status audit Fase 4 — 23 Agustus 2026:** seluruh gerbang otomatis lulus
> setelah koreksi auditor (23 berkas, 1.594 pemeriksaan, 0 gagal).
> **Delapan dari sepuluh** kriteria di atas terpenuhi dan terbukti. Fase 4
> **belum selesai/belum diterima** sampai dua kriteria manual berikut lulus.
>
> Dua kriteria **BELUM** dinyatakan lulus dan menunggu bukti manusia:
>
> - **Kriteria 3 (push tiba pada perangkat Android dan iOS).** Sandbox audit
>   tidak memiliki perangkat fisik, development build, maupun credential
>   FCM/APNs. Pengecualian simulator pada Fase 3 tidak berlaku di sini.
>   Prosedur: `docs/phase-v2-4/mobile-build-and-smoke-test.md`.
> - **Kriteria 6 (pengiriman WhatsApp nyata).** Penyedia nyata belum dipilih
>   pemilik produk; sistem tidak memilih vendor, membuat akun, atau membeli
>   layanan atas inisiatif sendiri. Kontrak, outbox, retry, dan deduplikasi
>   sudah diverifikasi memakai adapter uji yang tidak mengirim pesan nyata.
>   Prosedur: `docs/phase-v2-4/whatsapp-provider-checklist.md`.
>
> Rincian per kriteria: `docs/phase-v2-4/acceptance-status.md`.
> Hasil pengujian: `docs/phase-v2-4/test-results.md`.

### Fase 5: Laporan, Migrasi Produksi, dan Kesiapan Rilis (memerlukan Fase 4)

**Tujuan:** Menyediakan pertanggungjawaban perizinan yang dapat dicetak serta memastikan V2 aman dirilis tanpa kehilangan data V1.

**Persyaratan:**

1. Admin dapat memfilter laporan berdasarkan tanggal, status, santri, pengurus, murobi, kamar/kelas, tahun ajaran, durasi keputusan, dan kanal notifikasi.
2. Pengurus dan murobi melihat laporan sesuai cakupan; orang tua melihat riwayat santri terhubung.
3. Sediakan ringkasan jumlah pengajuan, disetujui, ditolak, dibatalkan, perlu routing admin, dan median durasi keputusan.
4. Sediakan detail riwayat, halaman HTML ramah cetak, PDF/bagikan dari aplikasi, dan ekspor CSV seluruh hasil filter.
5. Pastikan ringkasan, detail, cetak, dan CSV menggunakan filter/repository yang konsisten.
6. Ukur query dengan fixture minimal 1.000 pengajuan; tambahkan indeks hanya setelah `EXPLAIN`.
7. Jalankan preflight, backup, migrasi, verifikasi data lama, smoke test, serta backup/restore pada salinan MySQL.
8. Uji regresi seluruh alur V1: login, master data, jadwal, absensi, laporan, cetak, API, dan aplikasi guru.
9. Uji alur V2 pada web dan perangkat nyata untuk seluruh peran.
10. Dokumentasikan deployment cPanel, environment, cron, feature flag WhatsApp, rollback, dan respons insiden.
11. Jangan mengaktifkan WhatsApp produksi sebelum admin menyetujui provider, template, credential, dan hasil uji.

**Kriteria penerimaan:**

- [ ] Admin dapat menghasilkan laporan seluruh filter dan total ringkasan sama dengan detail.
- [ ] Pengurus, murobi, dan orang tua tidak dapat melihat laporan di luar cakupannya.
- [ ] CSV memuat seluruh hasil filter, header terdokumentasi, dan formula injection dinetralkan.
- [ ] Halaman cetak/PDF memuat identitas pesantren, filter, pembuat, waktu, keputusan, dan nomor halaman.
- [ ] Halaman pertama laporan selesai maksimal 2 detik pada fixture minimal 1.000 pengajuan.
- [ ] Jumlah dan ID perizinan lama sama sebelum/sesudah migrasi produksi.
- [ ] Backup dipulihkan pada database `_test` dan seluruh jumlah baris inti cocok dengan manifest.
- [ ] Semua tes statis, integrasi, concurrency, lint PHP/TypeScript, dan regresi V1 lulus.
- [ ] Uji manual web serta Android/iOS untuk pengurus, murobi, admin, dan orang tua lulus.
- [ ] WhatsApp off tidak menghasilkan request provider; WhatsApp on hanya dirilis setelah pemeriksaan konfigurasi dan uji admin lulus.

## 7. Metrik Keberhasilan

- 100% pengajuan baru memiliki pengurus pengaju, santri, rentang tanggal, status, routing, serta audit yang valid.
- 100% keputusan mempunyai tepat satu hasil akhir, pemberi keputusan, kapasitas, alasan, dan waktu.
- 0 pengajuan atau keputusan ganda setelah uji retry dan request bersamaan.
- 0 akses lintas cakupan pada pengujian pengurus, murobi, admin, dan orang tua.
- 100% orang tua uji hanya dapat melihat santri dengan relasi wali aktif.
- 100% kasus tanpa satu murobi valid masuk ke antrean admin dan tidak salah diarahkan.
- 100% perubahan status dan pengaturan kanal memiliki audit tanpa credential/secret.
- Sedikitnya 95% notifikasi in-app dan push terkirim/tersedia pada percobaan pertama saat infrastruktur tersedia.
- 0 kegagalan transaksi pengajuan atau keputusan yang disebabkan kanal notifikasi.
- Halaman pertama laporan selesai maksimal 2 detik pada data uji minimal 1.000 pengajuan, mengikuti baseline performa laporan V1 yang sudah terverifikasi.
- 0 kehilangan atau perubahan nilai bisnis pada data perizinan lama setelah migrasi dan restore.
- Seluruh alur pengurus → murobi/admin → orang tua dapat diselesaikan melalui website dan aplikasi.

## 8. Keputusan Implementasi yang Telah Dikonfirmasi

Seluruh keputusan berikut dikonfirmasi pengguna pada 21 Agustus 2026 dan menjadi dasar implementasi V2:

1. Penugasan pembimbing menghubungkan pengurus dengan kamar atau kelas serta tahun ajaran; pengurus hanya dapat mengajukan izin bagi santri dalam cakupan aktifnya.
2. Murobi tetap memakai role `guru`; kemampuan keputusan berasal dari `murobi_assignments` aktif, bukan role baru.
3. Akun pengurus dan orang tua masing-masing terhubung ke tepat satu master `pengurus` atau `wali`.
4. Status V2 adalah `Diajukan`, `Perlu Penetapan Admin`, `Disetujui`, `Ditolak`, dan `Dibatalkan`; V2 tidak memakai draf.
5. Jika routing menemukan nol atau lebih dari satu murobi, admin harus menetapkan murobi secara manual.
6. Routing memakai tahun ajaran serta kamar/kelas aktif santri pada tanggal pengajuan.
7. Pengurus dapat membatalkan pengajuan sebelum keputusan; admin dapat mengoreksi keputusan melalui peristiwa baru yang diaudit.
8. Pengajuan dengan rentang tumpang tindih ditolak jika pengajuan lain masih diajukan, perlu routing, atau disetujui.
9. Data izin lama mempertahankan ID dan nilai; pelaku yang tidak diketahui ditampilkan sebagai `Data warisan`.
10. Endpoint baru tetap menggunakan `/api/v1` secara aditif.
11. Profil API mengirim capability aktual dan aplikasi menampilkan navigasi berdasarkan capability tersebut.
12. Seluruh mutasi memakai idempotency key; keputusan menggunakan transaksi dan optimistic version.
13. Notifikasi in-app selalu tersedia; push menggunakan `expo-notifications`.
14. WhatsApp memakai adapter provider, default mati, dan diproses lewat outbox serta cron cPanel.
15. Kegagalan notifikasi tidak membatalkan transaksi perizinan dan retry tidak boleh mengirim duplikat.
16. Isi push/WhatsApp tidak menampilkan alasan izin lengkap atau data sensitif.
17. Alur tiap peran diuji pada Android dan iOS nyata.
18. Target performa laporan adalah maksimal 2 detik pada sedikitnya 1.000 pengajuan uji.
19. CSV, PDF/cetak, laporan durasi keputusan, dan median waktu keputusan termasuk ruang lingkup V2.
20. Modul `admin/admin_izin.php` lama dialihkan secara kompatibel setelah alur baru lolos regresi, tetapi tidak langsung dihapus.
