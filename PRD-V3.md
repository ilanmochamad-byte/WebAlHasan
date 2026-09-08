# Sistem Konseling dan Pelanggaran Santri Al Hasan — PRD V3

> **Untuk agen AI:** Dokumen ini adalah instruksi implementasi. Jika ada hal yang tidak jelas, jangan menebak—tanyakan kepada pengguna. Ketika keputusan berubah selama implementasi, perbarui dokumen ini agar tetap menjadi sumber kebenaran (*living document*). Item bertanda `(assumption)` belum dikonfirmasi pengguna—verifikasi sebelum menjadikannya dasar implementasi.

## 1. Gambaran Umum

Catatan pelanggaran dan konseling santri masih berpotensi tersebar atau dikerjakan secara manual, sehingga riwayat sulit ditelusuri, tindak lanjut tidak selalu jelas, dan pembimbing, murobi, admin, serta orang tua belum memperoleh informasi sesuai kewenangannya. V3 membangun dua modul terpisah yang saling terhubung: pelanggaran sebagai catatan kejadian dan poin, serta konseling sebagai rangkaian tindak lanjut; satu pelanggaran dapat ditautkan ke satu atau beberapa sesi konseling.

Tujuannya adalah menyediakan penanganan yang terukur tetapi tetap manusiawi: sistem membantu mengingatkan berdasarkan akumulasi poin, sedangkan keputusan pembinaan tetap dibuat petugas yang berwenang. Seluruh akses harus mengikuti cakupan penugasan, menjaga kerahasiaan isi konseling, mencegah duplikasi, dan meninggalkan audit yang dapat dipertanggungjawabkan pada website maupun aplikasi perangkat.

## 2. Pengguna Sasaran & JTBD

1. **Pembimbing** — Pengurus dengan penugasan pembimbing aktif. Ketika terjadi pelanggaran atau diperlukan pembinaan, pembimbing ingin mencatat kejadian, memeriksa akumulasi poin, membuat tindak lanjut, menjalankan satu atau beberapa sesi konseling, dan menutup penanganan untuk santri dalam cakupan tugasnya.
2. **Murobi** — Guru dengan penugasan murobi aktif. Ketika santri binaannya memiliki pelanggaran atau konseling, murobi ingin melihat informasi yang diizinkan, memberi catatan atau tanda mengetahui, dan memantau tindak lanjut tanpa mengambil alih pengelolaan pembimbing.
3. **Admin** — Ketika mengawasi operasional, admin ingin mengatur katalog pelanggaran dan ambang poin, melihat seluruh kasus, memperbaiki data secara terkendali, serta memeriksa riwayat tindakan tanpa menghapus catatan lama.
4. **Orang tua/wali** — Ketika pembimbing sengaja menerbitkan informasi, orang tua ingin melihat ringkasan pelanggaran dan tindak lanjut untuk santri yang terhubung dengannya, tanpa memperoleh catatan internal atau isi konseling rahasia.
5. **Pimpinan/pengelola pesantren** — Memerlukan laporan teragregasi untuk evaluasi pembinaan tanpa memperoleh akses operasional baru di luar akun admin yang sah.

## 3. Fitur Inti (Ruang Lingkup)

1. **Fondasi data, capability, dan cakupan** — V3 menggunakan akun, role dasar, relasi santri–wali, tahun ajaran, penempatan kelas/kamar, penugasan pembimbing, penugasan murobi, audit, dan API yang sudah ada. Hak operasional dihitung server-side dari penugasan aktif dan cakupan santri; tidak ada role login baru bernama pembimbing atau murobi.
2. **Katalog pelanggaran, tingkat, poin, dan ambang tindak lanjut** — Admin mengelola klasifikasi pelanggaran dan poin. Akumulasi poin menghasilkan peringatan serta rekomendasi tindak lanjut kepada pembimbing, tetapi tidak menjatuhkan hukuman atau membuat keputusan disiplin otomatis.
3. **Pencatatan pelanggaran** — Pembimbing mencatat kejadian untuk santri dalam cakupannya, termasuk waktu, tempat, kategori, uraian, poin, dan bukti bila ada. Sistem menyimpan snapshot aturan poin yang berlaku, mencegah pengajuan ganda, dan mempertahankan koreksi sebagai riwayat.
4. **Konseling dan tindak lanjut** — Pembimbing membuat kasus konseling yang dapat berdiri sendiri atau ditautkan ke pelanggaran. Satu pelanggaran dapat memiliki beberapa sesi konseling; setiap sesi mencatat jadwal, pelaksanaan, ringkasan internal, tindak lanjut, dan status penyelesaian.
5. **Pengetahuan murobi dan pembagian kepada orang tua** — Murobi terkait dapat melihat dan memberi tanda mengetahui/catatan sesuai cakupan. Orang tua hanya melihat ringkasan serta tindak lanjut yang dipilih dan diterbitkan secara eksplisit; catatan internal dan isi konseling rahasia tidak pernah ikut terbuka.
6. **Notifikasi in-app dan push** — Peristiwa penting menghasilkan notifikasi kepada penerima yang sah dengan payload minimum tanpa rincian sensitif. WhatsApp tetap opsional, default OFF, dikendalikan admin, dan ditangguhkan sampai prasyarat resmi tersedia.
7. **Website, aplikasi, laporan, dan audit** — API menjadi sumber aturan yang sama untuk website serta aplikasi Expo. Pengguna memperoleh tampilan sesuai capability; laporan dapat difilter dan dicetak/diekspor, sementara seluruh perubahan penting dicatat tanpa hard delete.

## 4. Di Luar Ruang Lingkup V3

- V3 tidak menjatuhkan hukuman, skorsing, pengeluaran, atau keputusan disiplin secara otomatis hanya berdasarkan poin.
- V3 tidak membuat papan peringkat pelanggaran santri atau perbandingan publik antarsantri.
- V3 tidak menyediakan chat langsung, panggilan suara/video, atau konseling video.
- V3 tidak mengurangi nilai akademik, mengubah rapor, atau mengubah tagihan berdasarkan pelanggaran.
- V3 tidak mengaktifkan pengiriman WhatsApp nyata; kanal ini tetap ditangguhkan sesuai keputusan produk.
- V3 tidak memberikan orang tua akses ke catatan internal, komentar petugas, lampiran bukti internal, atau isi konseling rahasia.
- V3 tidak menyediakan akun atau portal mandiri untuk santri.
- V3 tidak mengimplementasikan penilaian semester/rapor, pembiayaan bulanan, maupun alur dan pembiayaan PSB.
- V3 tidak mengganti sistem akun, role, penugasan murobi, penugasan pembimbing, notifikasi, atau audit yang sudah berjalan.
- V3 tidak menyediakan mode offline-first atau penyuntingan konflik secara offline pada aplikasi perangkat.
- V3 tidak menggunakan pengenalan wajah, analisis emosi, atau AI untuk menentukan kesalahan maupun rekomendasi hukuman.

## 5. Batasan Teknis & Keputusan yang Sudah Diambil

### 5.1 Keputusan yang tidak boleh dibuka kembali tanpa persetujuan pengguna

- Pelanggaran dan konseling adalah dua modul terpisah yang saling dapat ditautkan; satu pelanggaran dapat memiliki beberapa sesi konseling. **[JANGAN DIUBAH]**
- Pembimbing adalah pengurus dengan penugasan pembimbing aktif dan merupakan pengelola utama pelanggaran serta konseling. **[JANGAN DIUBAH]**
- Murobi adalah guru dengan penugasan murobi aktif; murobi mengetahui, memantau, dan memberi catatan/tanda mengetahui, bukan pengelola utama. **[JANGAN DIUBAH]**
- Orang tua hanya melihat ringkasan dan tindak lanjut yang sengaja dibagikan; isi internal dan konseling rahasia tidak dibuka. **[JANGAN DIUBAH]**
- Kategori, tingkat, poin, dan ambang tindak lanjut dikelola admin; sistem memberi rekomendasi, bukan hukuman otomatis. **[JANGAN DIUBAH]**
- Website dan aplikasi perangkat sama-sama didukung serta dikerjakan bertahap dengan API dan aturan server yang sama. **[JANGAN DIUBAH]**
- Role dasar tetap `admin`, `guru`, `pengurus`, dan `orang_tua`; pembimbing serta murobi bukan role login baru. **[JANGAN DIUBAH]**
- Notifikasi: in-app dan push didukung; WhatsApp opsional serta dikendalikan admin. **[JANGAN DIUBAH]**
- Untuk pekerjaan V3, Codex menjadi implementator dan Claude Code menjadi auditor; pekerjaan dilakukan bergantian, bukan bersamaan pada folder/branch yang sama. **[JANGAN DIUBAH]**

### 5.2 Prasyarat dan kompatibilitas

- Implementasi fondasi penugasan dan capability lintas PRD V3–V6 harus selesai, diuji, dan tersedia pada branch dasar V3 sebelum Fase 1 dimulai. Jika belum selesai, implementator harus berhenti dan meminta arahan; jangan membuat sistem penugasan kedua.
- V3 melanjutkan aplikasi PHP/MySQL yang ada tanpa Laravel, karena pemilik produk memilih pendekatan yang mudah dipahami dan aman bagi kondisi kode saat ini.
- Deployment tetap menargetkan hosting cPanel/MySQL; migrasi harus berversi, tambahan, dapat diperiksa, dan tidak mengubah migrasi produksi lama.
- Aplikasi perangkat tetap menggunakan React Native/Expo pada repository `alhasanApps` dan mengakses REST API yang sama.
- Endpoint serta perilaku V1/V2 harus kompatibel ke belakang; penambahan capability V3 tidak boleh mengubah arti role, mode, `default_mode`, atau menu lama.
- Admin, pembimbing, murobi, dan orang tua menggunakan satu akun masing-masing; tidak ada login kedua untuk V3.
- Semua mutasi menggunakan POST/PUT/PATCH sesuai pola router, CSRF pada web, autentikasi token pada aplikasi, transaksi, validasi server, prepared statement, dan audit.
- Perubahan akses, status, poin, tautan pelanggaran–konseling, publikasi orang tua, koreksi, pembatalan, dan penutupan tidak boleh dilakukan melalui GET.
- Catatan bisnis tidak dihapus permanen. Pembatalan/koreksi menyimpan alasan, pelaku, waktu, nilai sebelum/sesudah, IP, dan user agent sesuai kemampuan audit yang ada.
- Data sensitif tidak boleh ditulis ke URL, log aplikasi, push payload, analytics, screenshot pengujian publik, atau pesan kesalahan.

### 5.2a Data pelanggaran warisan — keputusan 8 September 2026

URL dan menu pelanggaran lama tetap tersedia. Halamannya menjadi baca-saja dengan label **Data warisan**; pencatatan dan penghapusan lama ditutup. Tabel serta seluruh data lama dipertahankan. Keputusan Human Developer ini berlaku pada Fase 1.

### 5.3 Model akses dan cakupan

Perilaku saat cakupan berubah dan pencatatan akses sensitif berikut merupakan rancangan awal.

- Admin dapat mengelola katalog, ambang, seluruh kasus, koreksi administratif, laporan, dan pengaturan; tindakan koreksi wajib beralasan dan diaudit.
- Pembimbing hanya dapat membuat serta mengelola data santri yang termasuk dalam `pembimbing_assignments` aktif pada tanggal kejadian dan tahun ajaran terkait.
- Murobi hanya dapat melihat pelanggaran/konseling santri yang cocok dengan `murobi_assignments` aktif dan memberi tanda mengetahui atau catatan murobi.
- Orang tua hanya dapat membaca publikasi yang terkait dengan `users.wali_id` dan relasi `santri_wali` aktif miliknya.
- Pemeriksaan cakupan harus diterapkan dalam query/server service, bukan mengambil seluruh data lalu menyembunyikannya di UI.
- Bila cakupan pembimbing atau murobi berubah setelah kejadian, riwayat pelaku dan cakupan saat pencatatan tetap dipertahankan; akses berjalan mengikuti kebijakan cakupan aktif serta kebutuhan audit admin.
- Admin dapat melihat data rahasia untuk pengawasan, tetapi pembukaan detail konseling sensitif harus dicatat sebagai peristiwa akses.

### 5.4 Aturan pelanggaran dan poin

Rincian katalog, status, snapshot, periode akumulasi, penyesuaian poin, lampiran, dan mekanisme pembalik berikut merupakan rancangan awal.

- Katalog minimal mempunyai kode unik, nama, kategori, tingkat, poin default, status aktif, masa berlaku, dan pembuat/perubah.
- Tingkat awal menggunakan `Ringan`, `Sedang`, dan `Berat`.
- Perubahan poin katalog tidak mengubah poin catatan lama; pelanggaran menyimpan snapshot kategori, tingkat, dan poin saat diterbitkan.
- Akumulasi poin dihitung per santri dan tahun ajaran, sedangkan riwayat lintas tahun tetap dapat dibaca.
- Ambang poin dapat berbeda per tahun ajaran dan menghasilkan rekomendasi tindak lanjut yang dikonfigurasi admin.
- Pembimbing boleh menyesuaikan poin dari default hanya jika diberi izin khusus dan mengisi alasan; perubahan diaudit.
- Status pelanggaran awal adalah `Draf`, `Dicatat`, `Ditindaklanjuti`, `Selesai`, atau `Dibatalkan`.
- Pelanggaran yang sudah `Dicatat` tidak diedit dengan menimpa nilai historis; koreksi membuat revisi/peristiwa baru dengan alasan.
- Bukti foto/dokumen bersifat opsional, privat, dibatasi jenis/ukuran, disimpan di lokasi nonpublik atau melalui pengunduhan terotorisasi, dan tidak dibagikan kepada orang tua secara otomatis.
- Idempotency key dan fingerprint bisnis mencegah klik ganda/request ulang membuat pelanggaran duplikat.

### 5.5 Aturan konseling

Rincian status, data sesi, kasus mandiri, tautan jamak, dan syarat penutupan berikut merupakan rancangan awal.

- Kasus konseling memiliki pemilik pembimbing, santri, tujuan, status, tingkat kerahasiaan, waktu dibuat, dan opsional satu atau beberapa tautan pelanggaran.
- Satu kasus konseling dapat memiliki beberapa sesi; satu sesi hanya dimiliki satu kasus konseling.
- Status kasus awal adalah `Dibuka`, `Dalam Pendampingan`, `Selesai`, atau `Dibatalkan`; status sesi awal adalah `Dijadwalkan`, `Selesai`, `Tidak Hadir`, `Dijadwalkan Ulang`, atau `Dibatalkan`.
- Sesi menyimpan jadwal, waktu realisasi, pembimbing, ringkasan internal, hasil, rencana tindak lanjut, dan jadwal berikutnya bila ada.
- Kasus dapat berdiri sendiri tanpa pelanggaran, misalnya konseling pencegahan atau kebutuhan pribadi santri.
- Penutupan kasus memerlukan ringkasan hasil dan tidak menghapus sesi sebelumnya.
- Koreksi sesi yang sudah selesai tidak menimpa catatan lama; alasan dan revisinya disimpan.
- Catatan murobi dipisahkan dari catatan internal pembimbing serta dari ringkasan orang tua.

### 5.6 Publikasi orang tua dan privasi

Rincian versi publikasi, poin yang ditampilkan, penarikan, serta pemilihan wali penerima berikut merupakan rancangan awal.

- Informasi orang tua diterbitkan per catatan secara sengaja oleh pembimbing atau admin; tidak ada publikasi otomatis saat kasus selesai.
- Publikasi menyimpan ringkasan yang memang ditujukan kepada orang tua, tindak lanjut, penerbit, waktu terbit, penerima wali, dan versi sumber.
- Orang tua tidak melihat poin internal secara default kecuali poin tersebut dimasukkan secara sengaja ke ringkasan publikasi.
- Publikasi dapat ditarik kembali hanya dengan alasan dan audit; orang tua melihat status bahwa informasi pernah diperbarui/ditarik tanpa memperoleh catatan internal.
- Push kepada orang tua hanya menyatakan bahwa ada pembaruan pembinaan yang dapat dibuka setelah login; push tidak memuat nama santri, jenis pelanggaran, poin, alasan, atau isi konseling.
- Bila satu santri mempunyai beberapa wali aktif, publikasi dikirim kepada seluruh wali aktif yang memiliki akun kecuali pembimbing memilih penerima tertentu dengan alasan.

### 5.7 Data yang disimpan atau ditampilkan

Daftar field minimum dan struktur penyimpanan berikut merupakan rancangan data awal yang harus diselaraskan dengan skema produksi.

- **Katalog:** kode, kategori, tingkat, nama pelanggaran, uraian, poin default, masa berlaku, status, dan audit.
- **Ambang:** tahun ajaran, nilai minimum/maksimum, label, rekomendasi tindak lanjut, status, dan audit.
- **Pelanggaran:** ID, santri, pembimbing, cakupan/penugasan sumber, tahun ajaran, tanggal/waktu/tempat, uraian, saksi opsional, snapshot kategori/tingkat/poin, status, idempotency key, versi, dan audit.
- **Lampiran:** pemilik catatan, nama aman, MIME, ukuran, hash, lokasi privat, pengunggah, dan waktu unggah.
- **Kasus konseling:** santri, pembimbing, tujuan, tingkat kerahasiaan, status, pembukaan/penutupan, tautan pelanggaran, dan audit.
- **Sesi konseling:** kasus, jadwal/realisasi, status, ringkasan internal, hasil, tindak lanjut, jadwal berikut, versi, dan audit.
- **Pengetahuan murobi:** murobi, waktu dilihat/diketahui, catatan murobi, versi catatan sumber, dan audit.
- **Publikasi orang tua:** wali penerima, ringkasan, tindak lanjut, versi sumber, penerbit, waktu terbit/ditarik, alasan, dan audit.
- **Notifikasi:** event, penerima, kanal, deduplication key, status antrean/pengiriman, retry, dan payload minimum.
- **Laporan:** periode, santri, kelas/kamar, kategori, tingkat, status, poin, pembimbing, murobi, publikasi orang tua, dan tindak lanjut.

### 5.8 Integritas, konkurensi, dan migrasi

Pilihan optimistic version, ledger poin, dan transaksi pembalik berikut merupakan rancangan integritas awal.

- Gunakan foreign key dan unique/index yang kompatibel dengan MySQL produksi untuk mencegah referensi yatim dan duplikasi.
- Mutasi multi-tabel—mencatat pelanggaran, menyimpan snapshot poin, memperbarui agregat/peringatan, menautkan konseling, menyelesaikan sesi, menerbitkan ringkasan, dan menulis audit/outbox—harus atomik.
- Gunakan optimistic version untuk koreksi/status agar penyunting bersamaan menghasilkan konflik `409`, bukan saling menimpa.
- Nilai agregat poin harus dapat direkonsiliasi ulang dari ledger/catatan pelanggaran yang sah; agregat bukan satu-satunya sumber kebenaran.
- Pembatalan pelanggaran membuat pembalik poin yang dapat diaudit, bukan menghapus baris atau mengedit total secara langsung.
- Data warisan yang tidak mempunyai pelaku atau tautan lengkap diberi penanda `Data warisan`; sistem tidak mengarang identitas.
- Setiap migrasi menyediakan pre-check, backup/restore point, dry-run bila memungkinkan, post-check jumlah/integritas, dan panduan rollback cPanel.

### 5.9 API, website, aplikasi, notifikasi, dan laporan

Pembagian fungsi per platform, daftar event notifikasi minimum, dan format laporan berikut merupakan rancangan awal.

- API V3 berversi dan memakai envelope, kode kesalahan, idempotensi, pagination, serta otorisasi konsisten dengan API yang ada.
- Website menyediakan halaman responsif untuk katalog, pelanggaran, konseling, antrean/tindak lanjut, publikasi orang tua, detail, dan laporan sesuai capability.
- Aplikasi Expo menyediakan pencatatan operasional pembimbing, pemantauan murobi, dan pembacaan publikasi orang tua; administrasi katalog dan koreksi berat tetap diprioritaskan di website.
- Notifikasi minimum: pelanggaran dicatat untuk murobi terkait, rekomendasi ambang untuk pembimbing, publikasi untuk orang tua, dan koreksi/penarikan publikasi untuk penerima yang terdampak.
- Semua notifikasi menggunakan outbox/deduplikasi/retry yang sudah ada; ketika kanal OFF, tidak ada request ke provider.
- Laporan minimum dapat difilter, dicetak sebagai tampilan ramah cetak/PDF, dan diekspor CSV dengan kolom sesuai hak akses.
- Laporan orang tua hanya berisi publikasi miliknya; laporan pembimbing dan murobi mengikuti cakupan; admin dapat melihat seluruh data.

## 6. Persyaratan Bertahap

### Fase 1: Fondasi Data, Katalog, dan Akses V3

**Tujuan:** Menyediakan skema aman, capability, katalog pelanggaran, ambang poin, dan halaman admin yang dapat dijalankan serta diverifikasi tanpa membangun alur kasus penuh.

**Persyaratan:**

1. Verifikasi fondasi penugasan lintas V3–V6 sudah terpasang; gunakan resolver capability yang sama.
2. Inventarisasi skema produksi/salinan untuk akun, role, guru, pengurus, wali, santri, tahun ajaran, kelas, kamar, penugasan pembimbing/murobi, audit, notifikasi, dan data konseling lama bila ada.
3. Buat migrasi berversi untuk katalog pelanggaran, tingkat/poin, ambang, struktur kasus, sesi, tautan, publikasi, lampiran, idempotensi, versi, dan indeks yang telah disetujui.
4. Jangan menghapus atau mengganti tabel konseling/pelanggaran lama; siapkan strategi backfill konservatif dan penanda data warisan.
5. Tambahkan capability V3 pada resolver server tanpa mengubah role atau mode lama.
6. Buat service/repository terpusat untuk katalog dan evaluasi cakupan.
7. Buat halaman admin untuk menambah, mengubah, mengaktifkan, serta mengakhiri katalog dan ambang; tidak ada hard delete.
8. Tambahkan validasi periode, nilai poin, duplikasi kode, benturan ambang, CSRF, prepared statement, transaksi, dan audit.
9. Tambahkan endpoint baca capability/katalog secara kompatibel ke belakang.
10. Buat pemeriksaan diagnostik kesiapan akun, penugasan, tahun ajaran, dan relasi wali.
11. Dokumentasikan migrasi, rollback, matriks hak, dan kontrak data.

**Kriteria penerimaan:**

- [ ] Semua migrasi Fase 1 lulus pada salinan MySQL produksi dan post-check tidak menemukan foreign key yatim atau kehilangan baris lama.
- [ ] Admin dapat membuat satu kategori, satu jenis pelanggaran, dan satu ambang poin melalui website, lalu membaca kembali nilai yang sama.
- [ ] Kode katalog duplikat dan rentang ambang tumpang tindih ditolak server dengan respons validasi tanpa menambah baris.
- [ ] Pengurus tanpa penugasan pembimbing aktif, guru tanpa penugasan murobi aktif, dan orang tua tanpa relasi wali aktif memperoleh `403` atau hasil kosong sesuai kontrak.
- [ ] Penugasan aktif menghasilkan capability V3 yang benar, dan capability hilang setelah tanggal selesai tanpa menghapus akun.
- [ ] Seluruh mutasi katalog memiliki satu entri audit yang memuat pelaku serta nilai sebelum/sesudah dan tidak memuat credential.
- [ ] Login, profil, mode, jadwal, absensi, laporan, dan perizinan V1/V2 lulus rangkaian regresi yang sudah ada.
- [ ] Halaman katalog dapat dibuka dan digunakan pada lebar viewport 375 px tanpa scroll horizontal pada formulir utama.

### Fase 2: Pelanggaran, Poin, dan Rekomendasi (memerlukan Fase 1)

**Tujuan:** Memungkinkan pembimbing mencatat pelanggaran secara konsisten dan memperoleh akumulasi poin serta rekomendasi tindak lanjut tanpa keputusan otomatis.

**Persyaratan:**

1. Buat daftar, formulir, detail, dan riwayat pelanggaran pada website sesuai capability.
2. Batasi pilihan santri di query berdasarkan cakupan pembimbing aktif.
3. Simpan snapshot katalog/tingkat/poin dalam transaksi dengan ledger/agregat yang dapat direkonsiliasi.
4. Terapkan idempotency key, fingerprint duplikasi, dan optimistic version.
5. Hitung akumulasi poin tahun ajaran dan tampilkan riwayat perubahannya.
6. Ketika ambang tercapai, buat peringatan/rekomendasi untuk pembimbing; jangan membuat hukuman atau keputusan otomatis.
7. Sediakan koreksi dan pembatalan beralasan tanpa menimpa atau menghapus riwayat.
8. Dukung lampiran privat sesuai batas keamanan jika asumsi lampiran dikonfirmasi.
9. Beri murobi terkait akses baca serta tanda mengetahui/catatan; tolak murobi lain.
10. Beri admin akses pengawasan dan koreksi khusus dengan alasan wajib.
11. Tambahkan API web/mobile untuk daftar, detail, pembuatan, koreksi, pembatalan, tanda mengetahui, dan riwayat.
12. Tambahkan audit serta outbox peristiwa tanpa payload sensitif.

**Kriteria penerimaan:**

- [ ] Pembimbing dapat mencatat satu pelanggaran untuk santri dalam cakupannya dan tidak dapat mencatat santri di luar cakupan.
- [ ] Dua request identik dengan idempotency key yang sama menghasilkan satu pelanggaran dan satu dampak poin.
- [ ] Poin catatan lama tidak berubah setelah poin default katalog diubah.
- [ ] Akumulasi poin hasil query sama dengan penjumlahan seluruh ledger pelanggaran sah pada tahun ajaran yang diuji.
- [ ] Ambang yang tercapai menghasilkan satu rekomendasi dan tidak menghasilkan hukuman, konseling, atau perubahan akademik otomatis.
- [ ] Pembatalan membuat pembalik poin dan riwayat audit tanpa menghapus pelanggaran.
- [ ] Dua koreksi dengan versi lama menyebabkan satu request ditolak `409` dan tidak menimpa perubahan yang lebih baru.
- [ ] Murobi terkait dapat memberi satu tanda mengetahui; murobi lain, orang tua, dan pengurus di luar cakupan memperoleh `403`.
- [ ] Push/outbox yang terbentuk tidak memuat nama santri, kategori, uraian, poin, nomor telepon, atau catatan rahasia.
- [ ] Pengujian akses lintas pembimbing, murobi, dan santri menghasilkan nol kebocoran data.

### Fase 3: Konseling dan Tindak Lanjut Terhubung (memerlukan Fase 2)

**Tujuan:** Menyediakan kasus konseling dan beberapa sesi tindak lanjut yang dapat ditautkan ke pelanggaran tanpa membuka catatan rahasia.

**Persyaratan:**

1. Buat daftar, formulir, detail, timeline, dan status kasus konseling pada website pembimbing/admin.
2. Izinkan kasus ditautkan ke satu atau beberapa pelanggaran santri yang sama sesuai aturan yang dikonfirmasi.
3. Izinkan satu kasus mempunyai beberapa sesi dan satu pelanggaran ditindaklanjuti melalui beberapa sesi.
4. Batasi pembuatan/perubahan pada pembimbing dalam cakupan aktif; admin hanya mengoreksi dengan alasan.
5. Pisahkan ringkasan internal, catatan murobi, dan ringkasan publik orang tua pada model maupun serializer.
6. Terapkan status kasus/sesi, validasi transisi, optimistic version, idempotensi, transaksi, dan audit.
7. Tampilkan rekomendasi ambang yang belum ditindaklanjuti dan izinkan pembimbing menghubungkannya secara manual ke kasus.
8. Izinkan murobi terkait membaca informasi yang diizinkan serta memberikan catatan/tanda mengetahui.
9. Sediakan koreksi, penjadwalan ulang, pembatalan, dan penutupan tanpa hard delete.
10. Tambahkan API web/mobile untuk kasus, sesi, tautan, timeline, dan tindakan murobi.
11. Tambahkan tampilan cetak internal sesuai hak akses.
12. Tambahkan pengujian privasi serializer agar field internal tidak pernah masuk respons orang tua.

**Kriteria penerimaan:**

- [ ] Pembimbing dapat membuat satu kasus dan mencatat sedikitnya dua sesi yang tersimpan sebagai baris berbeda dalam satu kasus.
- [ ] Satu pelanggaran dapat ditampilkan bersama seluruh sesi konseling yang menindaklanjutinya tanpa menggandakan pelanggaran.
- [ ] Kasus/sesi untuk santri di luar cakupan pembimbing tidak terlihat dan seluruh mutasinya ditolak `403`.
- [ ] Murobi terkait dapat membaca ringkasan yang diizinkan dan memberi catatan; murobi lain ditolak `403`.
- [ ] Orang tua tidak dapat mengakses endpoint internal kasus, sesi, catatan murobi, maupun lampiran melalui ID yang ditebak.
- [ ] Transisi status tidak sah ditolak `422` dan tidak menulis perubahan parsial.
- [ ] Penutupan kasus mempertahankan seluruh sesi, tautan pelanggaran, poin, dan audit.
- [ ] Koreksi sesi selesai menyimpan revisi/alasan tanpa mengganti nilai historis secara diam-diam.
- [ ] Kegagalan penulisan audit/outbox memicu rollback transaksi bisnis yang terkait.
- [ ] Pengujian otomatis membuktikan field internal tidak ada pada serializer/DTO publik orang tua.

### Fase 4: Publikasi Orang Tua dan Notifikasi (memerlukan Fase 3)

**Tujuan:** Memberikan informasi terbatas kepada orang tua secara sengaja dan mengirim notifikasi aman kepada penerima yang berwenang.

**Persyaratan:**

1. Buat pratinjau publikasi agar pembimbing melihat persis informasi yang akan diterima orang tua.
2. Publikasi memerlukan pilihan ringkasan/tindak lanjut, konfirmasi, penerima wali, dan versi sumber.
3. Pisahkan penyimpanan publikasi dari catatan internal; jangan mengambil isi internal secara dinamis pada saat orang tua membaca.
4. Sediakan daftar/detail publikasi orang tua pada website melalui relasi wali–santri aktif.
5. Sediakan koreksi atau penarikan publikasi dengan alasan dan riwayat.
6. Integrasikan in-app notification dan push melalui outbox yang sudah ada.
7. Terapkan deduplication key, retry, status pengiriman, kanal ON/OFF, dan payload minimum.
8. Ketika push diketuk, deep-link hanya membuka detail setelah autentikasi dan otorisasi ulang.
9. Jangan mengaktifkan WhatsApp atau menganggap adapter uji sebagai bukti pengiriman nyata.
10. Tambahkan API publikasi orang tua yang hanya mengembalikan field yang diizinkan.
11. Tampilkan status dibaca pada publikasi.
12. Audit penerbitan, pembaruan, penarikan, dan akses detail sensitif sesuai aturan yang disetujui.

**Kriteria penerimaan:**

- [ ] Orang tua hanya melihat publikasi untuk santri yang memiliki relasi `santri_wali` aktif dengannya.
- [ ] Respons orang tua tidak memuat ringkasan internal, isi sesi, catatan murobi, saksi, lampiran, poin internal, atau identifier petugas yang tidak diperlukan.
- [ ] Catatan pelanggaran/konseling yang belum diterbitkan menghasilkan nol baris dan nol notifikasi pada portal orang tua.
- [ ] Satu tindakan terbit menghasilkan satu publikasi per penerima yang dipilih dan retry tidak membuat duplikasi.
- [ ] Push fisik Android dan iOS menampilkan pesan generik serta membuka detail yang benar setelah login dan pemeriksaan akses.
- [ ] Wali A tidak dapat membaca publikasi wali B dengan mengganti ID URL/API; server mengembalikan `403` atau `404` aman.
- [ ] Penarikan publikasi memerlukan alasan, tercatat di audit, dan tidak mengungkapkan isi internal kepada orang tua.
- [ ] Ketika push OFF, satu rangkaian pengujian menghasilkan nol request ke Expo/provider push.
- [ ] Ketika WhatsApp OFF, satu rangkaian pengujian menghasilkan nol request ke provider WhatsApp.
- [ ] Log, audit, dan push payload hasil pengujian tidak memuat isi konseling rahasia atau credential.

### Fase 5: Aplikasi, Laporan, Audit Akhir, dan Kesiapan Produksi (memerlukan Fase 4)

**Tujuan:** Menyediakan paritas operasional yang disetujui pada aplikasi, laporan sesuai cakupan, dan bukti kesiapan produksi V3.

**Persyaratan:**

1. Tambahkan menu aplikasi berdasarkan feature capability tanpa membuat mode login baru.
2. Pembimbing dapat melihat santri cakupan, mencatat pelanggaran, melihat rekomendasi, mengelola kasus, dan mencatat sesi melalui aplikasi.
3. Murobi dapat melihat data yang diizinkan dan memberi tanda mengetahui/catatan melalui aplikasi.
4. Orang tua dapat melihat publikasi miliknya melalui aplikasi tanpa akses ke data internal.
5. Terapkan loading, empty, error, retry, timeout, token kedaluwarsa, dan konflik versi yang dapat dipahami pengguna.
6. Cegah penyimpanan catatan rahasia ke log, analytics, clipboard otomatis, atau notifikasi lokal yang tidak diperlukan.
7. Buat laporan website sesuai cakupan dengan filter periode, santri, kelas/kamar, kategori, tingkat, status, poin, pembimbing, murobi, dan tindak lanjut.
8. Sediakan tampilan cetak/PDF dan ekspor CSV dengan escape formula serta kolom yang mengikuti capability.
9. Buat rekonsiliasi poin, referensi yatim, duplikasi, status tidak sah, outbox tertunda, dan publikasi tanpa wali sah.
10. Jalankan seluruh pengujian otomatis V1–V3, pengujian MySQL, browser nyata, perangkat fisik Android/iOS, keamanan, privasi, akses silang, dan kegagalan transaksi.
11. Siapkan migrasi produksi, manifest, backup, hash rilis, rollback, cron/worker, serta smoke test cPanel.
12. Perbarui PRD dan dokumen status hanya berdasarkan bukti yang benar-benar dijalankan.

**Kriteria penerimaan:**

- [ ] Pembimbing menyelesaikan alur pelanggaran → rekomendasi → kasus → dua sesi → selesai pada website dan aplikasi menggunakan data uji yang sama tanpa duplikasi.
- [ ] Murobi terkait melihat serta menandai data yang sama pada website dan aplikasi, sedangkan murobi lain memperoleh `403`/hasil kosong.
- [ ] Orang tua melihat hanya publikasi yang dipilih pada website dan aplikasi; pengujian serializer serta akses silang menunjukkan nol field internal bocor.
- [ ] Laporan pembimbing, murobi, orang tua, dan admin masing-masing hanya memuat data sesuai cakupannya pada HTML, PDF/cetak, dan CSV.
- [ ] Rekonsiliasi seluruh data uji menghasilkan selisih poin `0`, referensi yatim `0`, publikasi duplikat `0`, dan status tidak sah `0`.
- [ ] Seluruh pengujian otomatis V1, V2, dan V3 lulus pada MySQL yang setara produksi tanpa regresi.
- [ ] Smoke test browser nyata lulus untuk admin, pembimbing, murobi, dan orang tua pada viewport desktop serta 375 px.
- [ ] Push fisik Android dan iOS lulus untuk sedikitnya satu peristiwa murobi dan satu publikasi orang tua, dengan payload generik.
- [ ] Uji kegagalan transaksi, retry, klik ganda, optimistic conflict, CSRF, IDOR, XSS, SQL injection, dan file upload berbahaya lulus.
- [ ] Worker/cron produksi berjalan otomatis sesuai interval yang didokumentasikan dan receipt akhir push diperiksa bila infrastruktur mendukungnya.
- [ ] Backup, manifest migrasi, hash commit rilis, panduan rollback, dan hasil smoke test produksi tersimpan tanpa credential atau data santri nyata.
- [ ] Branch V3 telah diaudit Claude Code, worktree bersih, dan tidak di-merge ke `main` sebelum seluruh kriteria wajib terpenuhi.

## 7. Metrik Keberhasilan

- Seluruh skenario akses silang pada pembimbing, murobi, orang tua, dan santri di luar cakupan menghasilkan `0` kebocoran data.
- Setiap request ulang/klik ganda pada operasi create, koreksi, tanda mengetahui, dan publikasi menghasilkan tepat `1` dampak bisnis.
- Rekonsiliasi poin pada data uji dan produksi menghasilkan selisih `0` antara ledger sah dan total yang ditampilkan.
- Setiap perubahan katalog, pelanggaran, poin, kasus, sesi, catatan murobi, dan publikasi memiliki audit pelaku, waktu, alasan bila wajib, serta nilai sebelum/sesudah.
- `100%` catatan konseling internal dan lampiran privat tidak muncul pada respons, laporan, log, atau push milik orang tua.
- `100%` pelanggaran yang mencapai ambang menghasilkan tepat satu rekomendasi aktif untuk ambang tersebut dan `0` hukuman otomatis.
- Alur utama pembimbing, murobi, dan orang tua lulus pada website, Android fisik, serta iOS fisik sebelum produksi.
- Seluruh rangkaian regresi V1 dan V2 tetap lulus setelah migrasi serta implementasi V3.
- Seluruh operasi daftar menggunakan pagination dan query terindeks; respons API daftar dengan 1.000 data uji berada di bawah 2 detik pada lingkungan staging yang setara produksi.
- Sedikitnya `95%` percobaan tugas utama dalam uji penerimaan pengguna—mencatat pelanggaran, membuat sesi, menandai diketahui, dan membaca publikasi—selesai tanpa bantuan teknis.
