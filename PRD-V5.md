# Kesehatan Santri Al Hasan — PRD V5

> **Untuk agen AI:** Dokumen ini adalah instruksi implementasi. Jika ada hal yang tidak jelas, jangan menebak—tanyakan kepada pengguna. Ketika keputusan berubah selama implementasi, perbarui dokumen ini agar tetap menjadi sumber kebenaran (*living document*).

**Status:** Kebutuhan inti, rancangan lengkap, dan seluruh asumsi V5 disetujui Human Developer pada 24 Oktober 2026. Claude Code ditetapkan sebagai implementator dan Codex sebagai auditor untuk Fase 1–5; pekerjaan dilakukan bergantian, satu fase per giliran. Belum ada implementasi V5, migrasi, merge, atau deploy berdasarkan dokumen ini.

**Penomoran resmi baru:** V5 = kesehatan santri; V6 = keuangan santri (tagihan bulanan, tabungan, pengeluaran); V7 = pengajuan izin orang tua melalui pembimbing ke murobi; V8 = pengembangan PSB yang sudah ada. Keputusan Human Developer 24 Oktober 2026 ini menggantikan **rujukan prospektif** V5/V6 lama di dokumen terdahulu; riwayat implementasi dan nama berkas lama tidak diubah hanya karena penomoran bergeser.

## 1. Gambaran Umum

Ketika santri berobat di klinik pesantren, catatan kunjungan, obat yang diberikan, rujukan, dan tindak lanjut perlu tersimpan sebagai riwayat yang utuh, dapat ditelusuri, dan tidak tersebar di catatan terpisah. V5 memberi petugas kesehatan satu alur pencatatan di website dan aplikasi, memberi pembimbing/murobi terkait visibilitas baca, serta menyampaikan hanya ringkasan yang sengaja diterbitkan kepada orang tua. Pembatasan akses, versi koreksi, dan audit diperlukan karena informasi kesehatan bersifat sensitif.

## 2. Pengguna Sasaran & Kebutuhan

1. **Petugas kesehatan** — Pengurus dengan penugasan kesehatan aktif ingin mencatat kunjungan klinik, obat yang benar-benar diberikan, rujukan ke luar klinik, hasil yang diketahui, dan tindak lanjut; ia dapat mengoreksi beralasan tanpa menghilangkan versi sebelumnya.
2. **Admin** — Ingin mengangkat/mengakhiri penugasan kesehatan, mengawasi seluruh catatan dan riwayat akses/perubahan, serta membaca rincian klinis bila diperlukan untuk pengawasan; admin tidak mengubah isi klinis.
3. **Pembimbing terkait** — Pengurus dengan penugasan pembimbing aktif ingin membaca riwayat kesehatan santri dalam cakupannya tanpa hak mengubah.
4. **Murobi terkait** — Guru dengan penugasan murobi aktif ingin membaca riwayat kesehatan santri dalam cakupannya tanpa hak mengubah.
5. **Orang tua/wali** — Pemilik akun dengan relasi wali–santri aktif ingin membaca ringkasan kunjungan, tindakan umum, serta tindak lanjut yang memang ditujukan kepadanya, melalui website dan aplikasi; ia tidak melihat catatan klinis rinci.

## 3. Fitur Inti (Ruang Lingkup)

1. **Penugasan kesehatan dan akses** — Admin menugaskan pengurus sebagai petugas klinik/bagian kesehatan melalui fondasi penugasan pusat. Penugasan aktif memberi hak menulis catatan klinik; pembimbing dan murobi hanya memperoleh hak baca untuk santri terkait; akun orang tua memperoleh proyeksi ringkasan milik anaknya. Pemeriksaan identitas dan cakupan dilakukan ulang di server pada setiap daftar, detail, mutasi, dan API.
2. **Kunjungan dan pengobatan** — Petugas mencatat satu peristiwa kunjungan untuk satu santri, waktu, catatan klinis, tindakan yang benar-benar dilakukan, obat yang benar-benar diberikan, dan petugas pencatat. Sistem tidak menyarankan diagnosis atau pengobatan secara otomatis. Formulir memuat kolom keluhan serta rincian obat (misalnya nama, jumlah, dan petunjuk yang ditulis petugas); format operasionalnya mengikuti kebutuhan klinik nyata.
3. **Rujukan dan tindak lanjut** — Petugas dapat mencatat bahwa santri dirujuk, tujuan rujukan, serta hasil dan tindak lanjut ketika informasi itu tersedia. Catatan kunjungan tetap berguna walaupun hasil rujukan belum diketahui. Tidak ada integrasi data dengan fasilitas kesehatan di luar pesantren.
4. **Koreksi berversikan** — Petugas kesehatan dapat mengoreksi catatan dengan alasan wajib. Sistem mempertahankan nilai/versi sebelumnya, pelaku, waktu, dan alasan; pembacaan normal menampilkan versi terbaru dengan jejak riwayat bagi pihak berwenang. Tidak ada penghapusan diam-diam.
5. **Ringkasan orang tua** — Petugas secara sengaja menerbitkan ringkasan yang terdiri dari kunjungan, tindakan umum, dan tindak lanjut untuk orang tua; catatan klinis rinci tidak menjadi sumber tampilan orang tua secara langsung. Versi ringkasan yang telah diterbitkan tidak berubah diam-diam ketika catatan internal dikoreksi; perubahan untuk orang tua memerlukan penerbitan versi ringkasan baru.
6. **Notifikasi dan aplikasi** — Ketika ringkasan diterbitkan atau ada tindak lanjut yang memerlukan perhatian orang tua, sistem membuat notifikasi in-app dan push sesuai pengaturan kanal yang ada. Isi push bersifat umum dan hanya membawa penunjuk aman; setelah diketuk aplikasi meminta autentikasi/otorisasi ulang. Semua alur sesuai peran tersedia pada website dan aplikasi dengan keputusan akses yang sama dari server.
7. **Laporan dan audit** — Admin dapat meninjau aktivitas layanan klinik dan jejak koreksi/akses; petugas kesehatan dapat melihat riwayat serta tindak lanjut yang belum selesai. Laporan tidak membuka rincian klinis kepada peran yang tidak berhak.

## 4. Di Luar Ruang Lingkup V5

- V5 tidak mengelola stok, pembelian, kedaluwarsa, atau perhitungan sisa obat.
- V5 tidak terhubung langsung ke rumah sakit, apotek, laboratorium, atau fasilitas kesehatan luar.
- V5 tidak memberi diagnosis, rekomendasi obat, perhitungan dosis, atau keputusan medis otomatis.
- V5 tidak membuat role login baru; petugas kesehatan adalah pengurus dengan penugasan fungsional.
- V5 tidak membuat tagihan klinik atau mengubah saldo keuangan santri; keuangan direncanakan pada V6.
- V5 tidak mengubah alur perizinan V2; pengajuan oleh orang tua direncanakan pada V7.
- V5 tidak mengaktifkan WhatsApp atau memasukkan informasi kesehatan ke pesan push; WhatsApp tetap ditangguhkan sesuai keputusan produk terdahulu.
- V5 tidak menyediakan penyuntingan offline atau sinkronisasi catatan klinis ketika perangkat tidak terhubung.
- V5 tidak menghapus atau menggabungkan data santri, wali, riwayat klinik, maupun data modul terdahulu.

## 5. Batasan Teknis & Keputusan yang Sudah Diambil

### 5.1 Keputusan produk

- Hanya pengurus dengan penugasan kesehatan aktif yang dapat mencatat/mengoreksi isi klinis. Admin mengelola penugasan, mengaudit, dan dapat membaca rincian untuk pengawasan, **tetapi tidak mengubah isi klinis**. Akses admin ke rincian tercatat. **[JANGAN DIUBAH]**
- Pembimbing dan murobi terkait hanya membaca catatan untuk santri dalam cakupan penugasan aktif masing-masing; tidak ada tombol atau endpoint mutasi bagi keduanya. **[JANGAN DIUBAH]**
- Orang tua hanya melihat ringkasan kunjungan, tindakan umum, dan tindak lanjut yang ditujukan kepadanya; tidak membaca rincian klinis internal. **[JANGAN DIUBAH]**
- V5 mencatat obat yang diberikan tetapi tidak menghitung stok. Rujukan, tujuan, hasil yang diketahui, dan tindak lanjut dicatat tanpa integrasi fasilitas luar. **[JANGAN DIUBAH]**
- Koreksi memerlukan alasan dan mempertahankan versi sebelumnya dalam audit. **[JANGAN DIUBAH]**
- Website **dan** aplikasi tersedia untuk semua peran V5 sesuai hak masing-masing. **[JANGAN DIUBAH]**
- Notifikasi orang tua dikirim saat ringkasan diterbitkan atau tindak lanjut untuk mereka tersedia; pesan push tetap umum tanpa rincian kesehatan. **[JANGAN DIUBAH]**
- V5 diterima hanya ketika alur kunjungan, obat, rujukan, koreksi, ringkasan, notifikasi, serta hak akses terbukti berjalan di website dan aplikasi, tanpa catatan ganda atau kebocoran lintas santri/peran. **[JANGAN DIUBAH]**

### 5.2 Fondasi dan kompatibilitas

- Backend tetap PHP native modular + MySQL/MariaDB di cPanel; API tetap kompatibel di bawah `/api/v1`. Aplikasi tetap Expo/React Native/TypeScript pada repositori `alhasanApps`. Tidak memperkenalkan Laravel atau sistem akun kedua.
- Gunakan `users`, `pengurus`, `guru`, `santri`, `wali`, `santri_wali`, `pembimbing_assignments`, `murobi_assignments`, fondasi `App\Penugasan\PenugasanJenis` dan `App\Auth\Capabilities` yang ada. Tambahkan jenis penugasan kesehatan/capability secara aditif; jangan menyulap jenis bendahara bulanan lama menjadi petugas kesehatan karena penomoran V5 berubah.
- Admin memperoleh beberapa feature capability lama sebagai pengawasan; implementasi V5 harus membedakan **hak baca/audit admin** dari **hak tulis klinis petugas** di layanan server, bukan hanya pada menu.
- Penugasan kesehatan aktif mencakup seluruh santri aktif yang dapat datang ke klinik pesantren; pembatasan menurut unit/jenjang hanya ditambahkan bila Human Developer menetapkannya. Admin dapat mengakhiri penugasan; sesi lama kehilangan hak pada pemeriksaan berikutnya.
- Pembimbing dan murobi terkait dapat membaca rincian internal, bukan hanya ringkasan orang tua, selama penugasan dan cakupan santri masih aktif. Mereka tetap tidak dapat membaca catatan santri di luar cakupannya.
- Relasi `santri_wali` dan status akun/wali aktif diperiksa ulang pada setiap daftar/detail ringkasan orang tua dan ketika membuka tujuan notifikasi. Tautan/ID yang ditebak tidak boleh membuka data anak lain.
- Migrasi SQL baru aditif dan berversi setelah migrasi terakhir yang benar-benar ada saat implementasi (saat PRD ditulis: `023`). Data historis, ID santri/wali, arsip pelanggaran, PSB, V2, V3, dan V4 tidak dibersihkan. Sertakan preflight, verifier, backup, rollback aman, dan pengujian pada salinan database.
- Fondasi push/in-app dan outbox yang ada dipakai kembali, termasuk deduplikasi, retry, pemeriksaan status kanal, dan receipt bila tersedia. Push OFF dan WhatsApp OFF tidak menghasilkan request provider.
- Pelaksanaan V5 harus mengikuti branch fitur dan kolaborasi bergantian `AGENTS.md`: Claude Code mengimplementasikan, Codex mengaudit setiap fase secara independen. Sebelum fase mobile, cocokkan baseline web/mobile yang sebenarnya dan status rilis V4; audit lokal V4 tidak boleh disebut bukti deploy produksi.

### 5.3 Data minimum dan privasi

- **Penugasan kesehatan:** pengurus, masa berlaku, status, pembuat/pengakhir, waktu, serta jejak perubahan; relasi ke akun pengurus aktif.
- **Kunjungan:** ID tunggal, santri, waktu kejadian dan pencatatan, petugas, catatan klinis internal, tindakan, status, dan versi. Satu kunjungan dapat memuat lebih dari satu obat dan lebih dari satu tindak lanjut; model tabel tidak boleh menggandakan baris kunjungan saat daftar ditampilkan.
- **Obat diberikan:** rujukan kunjungan, rincian obat yang dicatat petugas, waktu pemberian dan identitas pencatat. Sistem menyimpan fakta yang dimasukkan petugas, tidak menghasilkan saran medis. Jumlah, satuan, dan petunjuk yang ditulis petugas disediakan dalam formulir sesuai kebutuhan klinik.
- **Rujukan:** rujukan kunjungan, tujuan, tanggal, alasan/catatan petugas, hasil bila diketahui, tindak lanjut, identitas serta waktu pembaruan. Kolom klinis tetap privat.
- **Koreksi:** ID kunjungan, nomor versi sebelum/sesudah, pelaku, alasan, waktu, dan snapshot/perubahan sebelum–sesudah yang dapat diaudit; jangan menaruh rincian kesehatan di audit log umum.
- **Ringkasan wali:** ID kunjungan dan versi sumber, versi publikasi, teks aman yang disusun petugas, penerima yang berhak, waktu terbit, dan tindak lanjut untuk orang tua. Ringkasan yang sudah terbit tetap sebagai snapshot; jika keliru, petugas mengganti/menarik dengan alasan, bukan menimpa diam-diam.
- **Notifikasi:** event publikasi/tindak lanjut, penerima berakun yang masih berhak, kanal InApp/Push, kunci deduplikasi, status pengiriman, dan penunjuk aman ke ringkasan. Isi push tidak memuat nama santri, keluhan, obat, rujukan, nomor telepon, atau isi ringkasan.
- **Audit akses:** admin membuka rincian → catat siapa, kunjungan, waktu, dan tindakan baca tanpa menyalin isi klinis ke log umum. Pembacaan rincian oleh pembimbing/murobi/petugas juga dicatat dengan metadata minimal.
- Detail kesehatan tidak dipublikasikan lewat URL langsung, CDN, analytics, log umum, cache publik, clipboard otomatis, atau payload push. Respons privat memakai kontrol cache yang sesuai; data aplikasi disimpan hanya selama diperlukan untuk tampilan.

### 5.4 Alur status dan otorisasi

1. Petugas berpenugasan aktif memilih santri, mencatat kunjungan, obat, dan/atau rujukan. Satu kiriman ulang karena jaringan/klik ganda tidak menciptakan kunjungan kedua; idempotency key atau penanda transaksi unik diperlukan.
2. Status internal minimum `Draf` dan `Tercatat`. Draf tidak tampil pada pembimbing/murobi/orang tua; kunjungan tercatat dapat dibaca pembimbing/murobi terkait. Istilah status boleh menyesuaikan kode tanpa mengubah batas akses.
3. Petugas mengoreksi dengan alasan dan optimistic version. Permintaan menggunakan versi lama ditolak sebagai konflik dan tidak menghapus versi baru; admin hanya membaca riwayat.
4. Petugas menyusun lalu menerbitkan ringkasan orang tua secara eksplisit. Publikasi dan outbox notifikasi dilakukan atomik atau dalam keadaan aman yang dapat dipulihkan; retry tidak menerbitkan versi atau pesan ganda.
5. Ketika hasil rujukan/tindak lanjut tersedia, petugas memperbarui catatan beralasan bila mengubah versi tercatat. Jika ada tindak lanjut untuk orang tua, penerbitan/pembaruan ringkasan mengirim notifikasi generik sekali per peristiwa.
6. Seluruh permintaan web memakai autentikasi, otorisasi server dan CSRF untuk mutasi; API memakai token dan otorisasi server yang sama. Pembaca lintas peran/anak/penugasan ditolak walaupun memanggil endpoint langsung atau memakai sesi/token lama.

## 6. Persyaratan Bertahap

Urutan lima fase di bawah disetujui Human Developer agar setiap fase menghasilkan sistem yang dapat dijalankan dan diuji.

### Fase 1: Penugasan Kesehatan dan Kunjungan Dasar

**Tujuan:** Admin dapat menugaskan petugas kesehatan, dan petugas tersebut dapat menyimpan serta membaca satu kunjungan santri melalui website/API pada database uji.

**Persyaratan:**

1. Periksa `AGENTS.md`, baseline branch web/mobile, migrasi terakhir, master santri/wali, fondasi penugasan, capability, audit, dan sistem notifikasi sebelum menulis kode. Pastikan Claude Code bertugas sebagai implementator dan Codex sebagai auditor sesuai keputusan Human Developer.
2. Buat migrasi aditif untuk penugasan kesehatan dan catatan kunjungan/versi yang diperlukan, tanpa mengubah tabel/ID santri dan wali yang sudah ada.
3. Tambahkan capability petugas kesehatan yang berasal dari pengurus aktif + akun/role dasar + penugasan aktif; pisahkan kemampuan baca admin dari mutasi klinis.
4. Sediakan UI admin untuk membuat/mengakhiri penugasan dan UI petugas untuk daftar, membuat, serta membaca kunjungan dasar. Sediakan endpoint API baca/tulis yang setara sebagai fondasi aplikasi.
5. Pastikan penyimpanan atomik, validasi santri/petugas/tanggal, idempotensi kiriman ulang, dan audit metadata tanpa isi klinis di log umum.
6. Sediakan preflight, verifier, rollback aman, fixture sintetis, dan tes MySQL/MariaDB uji. Fase ini harus dapat dicoba manual tanpa menunggu fitur rujukan atau aplikasi.

**Kriteria penerimaan:**

- [ ] Admin menugaskan satu pengurus kesehatan aktif; pengurus itu menyimpan dan membaca tepat satu kunjungan santri aktif di website/API, lalu admin dapat membaca detail dan audit aksesnya.
- [ ] Pengurus tanpa penugasan, guru non-murobi, wali, dan akun nonaktif ditolak pada mutasi; admin ditolak pada mutasi klinis meskipun dapat membaca.
- [ ] Mengulang request dengan kunci idempotensi sama dan klik ganda menghasilkan tepat satu kunjungan; request berbeda dengan kunci sama ditolak tanpa mengubah data.
- [ ] Penugasan diakhiri ketika sesi petugas masih aktif: request tulis berikutnya ditolak tanpa baris baru.
- [ ] Migrasi dan rollback pada salinan database uji menjaga seluruh baris/ID/relasi santri–wali serta modul V1–V4; verifier tidak menemukan referensi yatim baru.
- [ ] Pada viewport 375 px, daftar/detail/formulir kunjungan tidak memiliki gulir horizontal dan satu kunjungan dapat dikirim serta dibaca ulang; log umum dan audit metadata memuat `0` isi catatan klinis.

### Fase 2: Obat, Rujukan, Koreksi, dan Pembaca Internal (memerlukan Fase 1)

**Tujuan:** Riwayat klinik yang lebih lengkap dapat dicatat/dikoreksi, sementara pembimbing dan murobi terkait hanya membaca menurut cakupan masing-masing di website/API.

**Persyaratan:**

1. Tambahkan rincian obat yang diberikan, rujukan ke luar klinik, hasil yang diketahui, serta tindak lanjut pada kunjungan yang sama; tidak ada mutasi stok.
2. Tampilkan riwayat per santri secara urut, dengan penanda rujukan/tindak lanjut yang belum selesai, tanpa menggandakan kunjungan karena beberapa rincian anak.
3. Implementasikan koreksi wajib alasan, versi monoton, optimistic version, dan pembacaan riwayat versi hanya untuk pihak berwenang.
4. Periksa cakupan penugasan pembimbing/murobi aktif pada setiap daftar/detail dan setelah pencabutan, perpindahan kamar/kelas, atau penonaktifan akun.
5. Sediakan penyaring/laporan operasional internal yang tidak mengurangi pemeriksaan hak per baris. Penyaring awal meliputi santri, tanggal, status rujukan, dan tindak lanjut.

**Kriteria penerimaan:**

- [ ] Satu kunjungan dengan dua obat dan satu rujukan muncul sebagai satu baris daftar, dua obat pada detail, dan satu rujukan yang dapat diperbarui hasilnya.
- [ ] Koreksi tanpa alasan ditolak; koreksi sah menghasilkan versi baru dan riwayat sebelum/sesudah, sedangkan dua koreksi bersamaan dari versi sama menghasilkan satu sukses dan satu konflik tanpa kehilangan data.
- [ ] Pembimbing/murobi terkait dapat membaca tetapi seluruh percobaan tulisnya ditolak; petugas lain tanpa penugasan dan pembimbing/murobi di luar cakupan ditolak/hasil kosong.
- [ ] Perubahan cakupan atau pencabutan penugasan pada sesi lama segera menutup detail kunjungan santri yang tidak lagi berhak dilihat.
- [ ] Tidak ada perubahan jumlah stok atau tabel PSB/keuangan/izin akibat menyimpan kunjungan, obat, rujukan, atau koreksi.

### Fase 3: Ringkasan Orang Tua dan Notifikasi (memerlukan Fase 2)

**Tujuan:** Petugas menerbitkan ringkasan aman untuk orang tua dan sistem memberi tahu wali yang berhak tanpa membocorkan data klinis di notifikasi.

**Persyaratan:**

1. Pisahkan penyusunan ringkasan wali dari catatan klinis internal; tampilkan pratinjau tepat sebelum publikasi.
2. Tampilkan hanya ringkasan terbit untuk anak dengan relasi wali aktif di website dan API; versi internal/draf tidak boleh terlihat.
3. Terapkan versi publikasi, koreksi/penarikan beralasan, dan penanganan publikasi ulang yang tidak mengubah snapshot lama diam-diam.
4. Integrasikan notifikasi InApp/Push generik dengan pengaturan kanal, deduplikasi, outbox/retry/receipt, dan otorisasi ulang pada tujuan.
5. Pastikan admin/petugas/pembimbing/murobi melihat tampilan internal sesuai haknya, sedangkan tampilan wali berasal dari proyeksi khusus tanpa kolom klinis rinci.

**Kriteria penerimaan:**

- [ ] Sebelum terbit, wali mendapat hasil kosong/ditolak untuk daftar, detail, API, dan ID yang ditebak; sesudah terbit wali berelasi aktif melihat hanya ringkasan anaknya.
- [ ] Wali kedua yang juga berelasi aktif menerima ringkasannya sendiri; wali lain atau relasi yang dicabut tidak dapat membuka ringkasan melalui sesi/token lama ataupun tautan notifikasi.
- [ ] Satu publikasi menghasilkan tepat satu peristiwa dan satu notifikasi InApp per wali berakun yang berhak, serta paling banyak satu item Push per wali sesuai sakelar; retry tidak menggandakan.
- [ ] Payload Push, log umum, dan daftar notifikasi memuat `0` nama santri, keluhan, obat, rujukan, nomor telepon, dan rincian klinis.
- [ ] Push OFF dan WhatsApp OFF menghasilkan `0` request ke provider; klik notifikasi sesudah logout meminta login dan tetap memeriksa relasi wali.
- [ ] Koreksi klinis tidak mengubah teks ringkasan terbit secara diam-diam; bila ringkasan diganti, versi lama dan alasan tetap dapat ditelusuri oleh petugas berwenang.

### Fase 4: Paritas Aplikasi Mobile (memerlukan Fase 3)

**Tujuan:** Seluruh alur V5 sesuai peran tersedia di aplikasi React Native/Expo dengan hasil dan pembatasan akses identik dengan website.

**Persyaratan:**

1. Sebelum perubahan mobile, verifikasi baseline branch/rilis V4 pada kedua repositori; gunakan API V5 secara aditif tanpa mengubah kontrak aplikasi lama.
2. Petugas kesehatan dapat membuat, membaca, melengkapi obat/rujukan, mengoreksi beralasan, dan menerbitkan ringkasan dari aplikasi.
3. Admin dapat mengelola penugasan, membaca rincian, dan meninjau audit sesuai batas produk; pembimbing/murobi hanya membaca santri terkait; wali hanya membaca ringkasan terbit dan tindak lanjutnya.
4. Sediakan keadaan memuat/kosong/gagal, validasi, konflik versi, koneksi terputus, sesi kedaluwarsa, dan akses dicabut dengan pesan Bahasa Indonesia yang jelas.
5. Tangani ketukan notifikasi pada aplikasi foreground/background/cold start; minta autentikasi bila perlu, lalu cek hak server sebelum membuka ringkasan.
6. Jangan menyimpan isi klinis atau token di log, analytics, clipboard otomatis, ataupun cache perangkat yang tidak diperlukan.

**Kriteria penerimaan:**

- [ ] Kunjungan, obat, rujukan, koreksi, dan publikasi yang dibuat di aplikasi tampil identik pada website; penyimpanan ulang dari aplikasi tidak menggandakan kunjungan/notifikasi.
- [ ] Hak admin, petugas, pembimbing, murobi, dan wali diuji silang di web/API/aplikasi: pengguna di luar cakupan mendapat `403`/hasil kosong untuk daftar, detail, dan mutasi.
- [ ] Wali membuka push generik, login bila diperlukan, melihat ringkasan anaknya; wali yang haknya dicabut tidak dapat membukanya meskipun membawa tautan lama.
- [ ] Skenario perangkat iOS dan Android yang benar-benar diuji beserta jenis build/perangkat dicatat; emulator tidak dinyatakan sebagai uji perangkat fisik.
- [ ] Pemeriksaan log/cache aplikasi menemukan `0` isi klinis, nama santri, credential, atau token push yang tercetak tanpa keperluan.

### Fase 5: Laporan, Regresi, dan Kesiapan Rilis (memerlukan Fase 4)

**Tujuan:** V5 mempunyai laporan operasional, bukti keselamatan data, dan jalur rilis/pemulihan yang dapat diperiksa sebelum masuk produksi.

**Persyaratan:**

1. Sediakan laporan kunjungan, rujukan, dan tindak lanjut sesuai hak; ringkasan agregat tidak membuka data klinis kepada wali lain atau staf di luar cakupan.
2. Jalankan pengujian independen sesuai auditor V5 yang ditetapkan; uji akses silang, klik ganda, konkurensi koreksi, transaksi gagal, publikasi ulang, retry notifikasi, pencabutan penugasan/relasi, dan regresi V1–V4.
3. Uji browser desktop/tablet/375 px, website tanpa JavaScript untuk tindakan penting bila didukung, serta aplikasi iOS/Android; pisahkan bukti simulasi dari perangkat fisik.
4. Siapkan manifest migrasi, backup/restore teruji, verifier, rollback aman, pengaturan worker/cron, panduan deploy cPanel, dan smoke test dengan data uji yang tidak membuka informasi klinis nyata.
5. Catat semua hasil yang belum dapat diuji sebagai terbuka; jangan menyatakan produksi lulus hanya dari uji lokal. Merge/deploy tetap keputusan Human Developer setelah audit sesuai `AGENTS.md`.

**Kriteria penerimaan:**

- [ ] Laporan kunjungan/rujukan/tindak lanjut menghasilkan hitungan yang sama dengan sumber pada fixture sintetis dan tidak menggandakan kunjungan yang memiliki beberapa obat.
- [ ] Seluruh skenario akses silang di web/API/aplikasi menghasilkan `0` kebocoran catatan internal maupun ringkasan wali anak lain.
- [ ] Seluruh koreksi dan publikasi ulang pada fixture memiliki pelaku, alasan, waktu, versi sebelum/sesudah; `0` pembaruan tanpa riwayat.
- [ ] Pengujian idempotensi/konkurensi menghasilkan `0` kunjungan, versi, dan notifikasi efektif ganda.
- [ ] Regresi relevan V1–V4 lulus terhadap baseline yang disepakati; kegagalan historis atau uji yang belum dijalankan dicatat terpisah, bukan disembunyikan.
- [ ] Manifest, backup, restore ke salinan, verifier, rollback, worker/cron, dan checklist smoke tersedia; hasil cPanel/produksi diberi status sesuai bukti pelaksanaan nyata.
- [ ] Auditor V5 yang ditetapkan menyatakan kriteria wajib terpenuhi berdasarkan bukti sebelum branch dipertimbangkan untuk merge; tidak ada deploy otomatis dari dokumen ini.

## 7. Metrik Keberhasilan

- `0` kunjungan efektif ganda dari klik ganda, retry, atau permintaan bersamaan dengan kunci idempotensi sama.
- `100%` koreksi klinis yang diterima memiliki alasan, pelaku, waktu, dan versi sebelumnya yang dapat dibaca pihak berwenang.
- `0` mutasi klinis oleh admin, pembimbing, murobi, orang tua, atau pengurus tanpa penugasan kesehatan aktif pada uji web/API/aplikasi.
- `0` akses rincian klinis lintas santri/cakupan serta `0` akses ringkasan oleh wali tanpa relasi aktif pada pengujian lintas peran dan sesi lama.
- `0` isi kesehatan atau identitas santri dalam payload Push dan log umum; `0` request provider ketika Push/WhatsApp OFF.
- `100%` notifikasi in-app yang berhak terhubung ke ringkasan terbit yang masih dapat diotorisasi; pengulangan event tidak membuat pesan efektif ganda.
- `100%` data santri/wali dan relasinya tetap utuh setelah migrasi, rollback uji, dan regresi V1–V4 pada salinan database.
- Pada uji penerimaan pengguna, sedikitnya 95% petugas kesehatan dan wali dapat menyelesaikan tugas utamanya di website maupun aplikasi tanpa bantuan teknis.
