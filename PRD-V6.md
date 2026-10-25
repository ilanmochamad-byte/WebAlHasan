# Keuangan Santri dan BSA Al Hasan — PRD V6

> **Untuk agen AI:** Dokumen ini adalah instruksi implementasi. Jika ada hal yang tidak jelas, jangan menebak—tanyakan kepada Human Developer. Ketika keputusan berubah selama implementasi, perbarui dokumen ini agar tetap menjadi sumber kebenaran.

**Status:** Kebutuhan, rancangan, dan seluruh asumsi V6 disetujui Human Developer pada 25 Oktober 2026. Claude Code ditetapkan sebagai implementator dan Codex sebagai auditor Fase 1–5, bergantian satu fase per giliran. Ketersediaan PRD dan prompt handoff belum merupakan perintah memulai implementasi, migrasi produksi, atau rilis; setiap fase memerlukan instruksi Human Developer tersendiri. `AGENTS.md` mengatur kolaborasi dan branch.

**Penomoran resmi:** V5 kesehatan santri; **V6 keuangan santri**; V7 pengajuan izin orang tua; V8 pengembangan PSB. Nama capability atau dokumen historis yang menyebut V5 sebagai pembiayaan bulanan tidak otomatis diubah.

## 1. Gambaran Umum

Tagihan bulanan, tabungan, dan pengeluaran dari tabungan santri perlu tercatat dalam satu alur yang dapat diperiksa oleh BSA (Bank Santri Al Hasan), admin, dan orang tua yang berhak. V6 menyediakan saldo serta riwayat yang konsisten, pembayaran tagihan yang dapat ditelusuri, dan transaksi BSA yang tidak menghasilkan penggandaan atau saldo negatif. Tagihan dan tabungan **harus siap dirilis bersama**, meskipun implementasi dan audit dikerjakan bertahap.

## 2. Pengguna Sasaran dan Kebutuhan

1. **Petugas BSA** — Pengurus dengan satu penugasan BSA aktif mengatur tarif/tagihan bulanan, mencatat dan memverifikasi pembayaran atau setoran tunai/transfer, serta mencatat dan menyetujui penarikan atau belanja dari tabungan santri. Petugas pencatat boleh menyetujui transaksinya sendiri; tindakan itu tetap memiliki identitas, waktu, bukti, dan audit.
2. **Admin** — Mengangkat dan mengakhiri penugasan BSA, mengawasi tagihan, saldo, transaksi, dan jejak audit di seluruh cakupan. Admin membaca dan mengaudit transaksi keuangan tanpa mengubahnya.
3. **Orang tua/wali** — Melihat tagihan, pembayaran, saldo tabungan, serta riwayat masuk dan keluar hanya untuk anak dengan relasi wali aktif. Orang tua tidak mengajukan transaksi dari aplikasi pada V6.
4. **Santri** — Saldo dan kewajiban dicatat atas identitas santri yang sudah ada. Akun santri langsung tidak ditambahkan pada V6.

## 3. Fitur Inti (Ruang Lingkup)

1. **Satu penugasan BSA dan akses per santri.** Admin memberi satu jenis penugasan fungsional kepada pengurus untuk seluruh alur keuangan V6. Penugasan dapat mempunyai cakupan unit/jenjang; pemeriksaan hak dilakukan ulang di server pada setiap daftar, detail, mutasi, cetak, dan API. Wali hanya membaca data anaknya melalui relasi `santri_wali` yang masih berlaku.
2. **Tagihan bulanan.** BSA menetapkan tarif menurut unit/jenjang, lalu menerbitkan tagihan berkala per santri. Nominal per santri boleh disesuaikan dengan alasan yang tersimpan. Tagihan, perubahan tarif, penyesuaian, pembayaran tunai/transfer, sisa kewajiban, kuitansi, dan riwayatnya dapat ditelusuri. Periode awal memakai bulan kalender, pembayaran sebagian diperbolehkan, dan tidak ada denda otomatis.
3. **Tabungan dan pengeluaran BSA.** BSA mencatat setoran tunai/transfer dan penarikan atau belanja yang mengambil saldo tabungan. Setiap transaksi mencatat tujuan, nominal, cara penerimaan/pengeluaran, petugas, waktu, status verifikasi, serta bukti atau referensi bila ada. Petugas yang mencatat boleh langsung menyetujui penarikan; saldo efektif hanya berubah satu kali saat transaksi sah. Saldo awal semua santri adalah nol karena Human Developer menyatakan belum ada data lama yang perlu dipindahkan.
4. **Pembayaran tagihan dari tabungan.** BSA dapat memakai saldo tabungan untuk melunasi sebagian atau seluruh tagihan melalui tindakan eksplisit yang disetujui dan diaudit. Satu tindakan membukukan pengurangan saldo dan pembayaran tagihan secara atomik, dengan satu referensi silang. Tagihan terbit tidak memotong tabungan secara otomatis.
5. **Tampilan dan laporan lintas perangkat.** Petugas BSA, admin, dan orang tua menggunakan website serta aplikasi sesuai haknya. Daftar dan detail memisahkan tagihan dari saldo tabungan, tetapi menunjukkan pembayaran yang bersumber dari tabungan pada kedua riwayat. BSA dan admin memperoleh laporan tagihan, tunggakan, setoran, penarikan, dan rekonsiliasi; orang tua hanya memperoleh laporan anaknya.
6. **Koreksi, audit, dan pemberitahuan.** Transaksi uang tidak dihapus diam-diam; koreksi dibuat sebagai pembatalan atau transaksi penyeimbang dengan alasan serta hubungan ke transaksi asal. Pemberitahuan umum dalam aplikasi dan push dikirim ketika tagihan diterbitkan atau transaksi selesai; isi push tidak memuat nominal atau nama santri.

## 4. Di Luar Ruang Lingkup V6

- V6 tidak mengintegrasikan payment gateway atau menarik dana otomatis dari rekening bank. Tunai dan transfer dicatat serta diverifikasi oleh BSA sesuai keputusan Human Developer.
- V6 tidak memotong tabungan otomatis ketika tagihan bulanan diterbitkan.
- V6 tidak mencampur tagihan atau pembayaran PSB V8 dengan tagihan rutin dan tabungan santri. Kode dan data PSB yang sudah ada tetap dipertahankan.
- V6 tidak memindahkan saldo atau tunggakan historis karena Human Developer menyatakan belum ada data lama yang perlu dipindahkan.
- V6 tidak membuat akun login baru untuk BSA; petugas tetap pengurus dengan penugasan fungsional.
- V6 tidak menyediakan pinjaman, bunga, investasi, transfer antar-santri, atau pembukuan umum seluruh pesantren.
- V6 tidak menyediakan permintaan penarikan oleh orang tua atau santri; orang tua hanya melihat hasil transaksi.
- V6 tidak mengaktifkan WhatsApp, mengubah alur PSB, atau menghubungkan biaya klinik V5 secara otomatis.

## 5. Batasan Teknis, Data, dan Keputusan Produk

### 5.1 Keputusan Human Developer

- **BSA mengelola seluruh keuangan V6 melalui satu penugasan**: tagihan bulanan, tabungan, pengeluaran, dan pembayaran tagihan dari tabungan. Admin mengawasi; orang tua hanya melihat anaknya. **[JANGAN DIUBAH]**
- **Pengeluaran berarti penarikan atau belanja yang mengambil tabungan santri**, bukan komponen tagihan pesantren. **[JANGAN DIUBAH]**
- **Tarif menurut unit/jenjang dapat disesuaikan per santri dengan alasan**. **[JANGAN DIUBAH]**
- **Tunai dan transfer diverifikasi BSA; tidak ada payment gateway**. Petugas BSA pencatat boleh menyetujui penarikan sendiri. **[JANGAN DIUBAH]**
- **Tabungan boleh dipakai membayar tagihan hanya melalui transaksi BSA yang disetujui dan tercatat; tidak ada pemotongan otomatis**. **[JANGAN DIUBAH]**
- **BSA, admin, dan orang tua memakai website serta aplikasi. Tagihan dan tabungan harus dirilis bersama.** **[JANGAN DIUBAH]**
- **Tidak ada saldo tabungan atau tunggakan lama yang harus diimpor**. **[JANGAN DIUBAH]**
- BSA adalah pengelola keuangan internal pesantren dalam ruang lingkup sistem ini; PRD tidak mengasumsikan layanan bank publik atau izin usaha tertentu. **[JANGAN DIUBAH]**

### 5.2 Sistem yang sudah ada

- Backend tetap PHP native modular, MySQL/MariaDB pada hosting cPanel, API `/api/v1`, dan aplikasi Expo/React Native/TypeScript pada repositori `alhasanApps`. Gunakan identitas `users`, `pengurus`, `santri`, `wali`, `santri_wali`, tahun ajaran, serta autentikasi dan capability yang sudah ada. Sistem akun atau framework baru tidak diperlukan.
- Fondasi `bendahara_bulanan_assignments` dan capability `pembiayaan_bulanan.*` sudah ada, tetapi modul bisnis tagihan/tabungan belum ada. Katalog penugasan itu menjadi titik awal satu penugasan BSA; implementasi boleh memperluas label dan capability secara aditif sambil mempertahankan data serta kontrak lama. Cara tepat memperluasnya perlu dibuktikan oleh audit kode sebelum migrasi.
- Migrasi SQL harus aditif, berversi setelah migrasi terakhir yang benar-benar ada, dengan pemeriksaan sebelum/sesudah, backup, rollback aman, dan uji pada salinan basis data. Jangan menghapus tabel/baris/kolom identitas santri, wali, PSB, atau modul V1–V5.
- Penugasan BSA, akun pengurus, status santri, dan relasi wali harus diperiksa ulang pada setiap permintaan. Ketika penugasan atau relasi dicabut, sesi dan token lama tidak boleh mempertahankan akses.
- Claude Code adalah implementator dan Codex auditor Fase 1–5 V6 sesuai keputusan Human Developer 25 Oktober 2026 dan `AGENTS.md`. Dokumen ini bukan izin memulai seluruh fase sekaligus atau rilis produksi.

### 5.3 Aturan nominal dan transaksi

- Seluruh nominal dalam rupiah bulat, disimpan sebagai tipe numerik eksak; tidak ada perhitungan saldo dengan float. Saldo efektif berasal dari transaksi sah dan **tidak boleh negatif**, sesuai ringkasan yang telah disetujui.
- Mutasi saldo dan pembayaran menggunakan transaksi basis data InnoDB, kunci unik/idempotensi, dan penguncian atau pembaruan bersyarat pada saldo santri yang sama. Gunakan satu urutan penguncian yang konsisten agar dua penarikan bersamaan tidak membelanjakan uang yang sama. Ketentuan penguncian perlu disesuaikan dengan versi MySQL/MariaDB hosting. `FOR UPDATE` harus berada dalam transaksi agar penguncian berlaku menurut [MariaDB](https://mariadb.com/docs/server/reference/sql-statements/data-manipulation/selecting-data/for-update) dan [MySQL](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html).
- Untuk satu santri dan satu periode, terbit paling banyak satu tagihan bulanan yang memuat satu atau beberapa komponen; penyesuaian berikutnya tercatat sebagai revisi, bukan penerbitan ganda. Periode dan tarif yang berlaku disalin ke snapshot tagihan agar perubahan tarif masa depan tidak mengubah tagihan terbit.
- Pencatatan transfer memakai referensi/bukti yang dapat diperiksa petugas; menandai "terverifikasi" tanpa pemeriksaan di luar sistem bukan bukti dana benar-benar tiba. Rekonsiliasi rekening bank tetap pekerjaan BSA.
- Pembatalan dan koreksi transaksi efektif memakai entri pembalik/penyeimbang beralasan, bukan UPDATE nominal atau DELETE pada transaksi yang sudah sah. Semua versi/tindakan menyimpan aktor, waktu, alasan, dan hubungan asal–koreksi.
- Pembayaran tagihan dari tabungan merupakan satu operasi: verifikasi saldo dan sisa tagihan, catat debit tabungan serta kredit pembayaran, lalu commit keduanya atau rollback keduanya. Kiriman ulang dengan kunci sama mengembalikan hasil sama, tidak membuat pembayaran kedua.

### 5.4 Data minimum dan batas privasi

- **Penugasan:** ID pengurus, cakupan unit/jenjang, masa berlaku, status, pembuat/pengakhir, dan alasan perubahan.
- **Tarif:** periode berlaku, unit/jenjang, komponen/nama biaya, nominal, pembuat, waktu, dan alasan penyesuaian. Komponen biaya dapat lebih dari satu per tagihan.
- **Tagihan:** santri, periode, snapshot tarif/penyesuaian, tanggal terbit/jatuh tempo, total, sisa, status turunan, versi, penerbit, dan jejak audit. Jatuh tempo diatur BSA per periode.
- **Pembayaran:** tagihan, nominal, sumber tunai/transfer/tabungan, tanggal transaksi dan verifikasi, petugas, referensi/bukti, nomor kuitansi unik, serta hubungan pembatalan.
- **Tabungan:** satu akun saldo per santri aktif, entri setoran/penarikan/belanja/pembayaran tagihan/pembalik, nominal, tujuan, metode, status, idempotency key, pelaku, waktu, dan referensi silang. Akun saldo adalah pencerminan ledger, bukan sumber kedua yang dapat diedit bebas.
- **Akses wali:** hanya data anak dengan relasi dan akun aktif; ID yang ditebak, ekspor, kuitansi, serta tautan push wajib melewati otorisasi server yang sama. Nomor rekening, bukti transfer, dan data keuangan tidak masuk payload push atau log umum.
- **Notifikasi dan laporan:** bila notifikasi diaktifkan, outbox/idempotensi dan pengaturan kanal yang ada dipakai kembali; laporan membedakan tagihan terbit, pembayaran terverifikasi, uang tunai, transfer, dan perpindahan internal dari tabungan agar satu rupiah tidak dihitung dua kali.

## 6. Persyaratan Bertahap

Seluruh fase dikembangkan dan diuji pada branch fitur. Setiap fase menghasilkan keadaan yang dapat dicoba manual di lingkungan uji; **rilis produksi tagihan dan tabungan hanya boleh bersamaan** setelah seluruh kriteria wajib V6, audit, dan keputusan Human Developer terpenuhi. Urutan lima fase telah disetujui; Claude Code dan Codex bekerja bergantian sesuai peran masing-masing.

### Fase 1: Fondasi BSA dan Transaksi Minimum

**Tujuan:** Admin dapat menugaskan satu petugas BSA dan petugas itu dapat menerbitkan satu tagihan serta mencatat satu setoran/penarikan pada santri uji melalui website/API.

**Persyaratan:**

1. Baca `AGENTS.md`, PRD V6, baseline web/mobile, penugasan bendahara lama, data santri/wali, dan migrasi terakhir. Pastikan peran implementator/auditor V6 sudah ditetapkan Human Developer.
2. Sediakan migrasi aditif untuk tagihan, pembayaran, akun tabungan, transaksi, audit, dan kunci idempotensi minimum; jaga seluruh ID/relasi serta data PSB dan V1–V5.
3. Sediakan admin UI untuk menugaskan/mengakhiri BSA dan web/API untuk BSA membuat serta membaca satu tagihan, mencatat setoran, mencatat penarikan, dan membaca saldo santri.
4. BSA hanya boleh mutasi dalam cakupan aktif; admin membaca/audit; wali membaca data anaknya. Otorisasi ditolak di server bila akun atau penugasan tidak sah.
5. Simpan nominal secara eksak, cegah saldo negatif, buat audit aktor/waktu/alasan, dan tolak kiriman ulang maupun dua penarikan bersamaan yang melampaui saldo.
6. Sediakan preflight, verifier, rollback aman, dan fixture sintetis; uji MySQL/MariaDB pada salinan basis data. Fase ini belum menjadi izin rilis parsial.

**Kriteria penerimaan:**

- [ ] Admin menugaskan satu pengurus BSA; pengurus tersebut menerbitkan tepat satu tagihan uji dan mencatat setoran Rp100.000 serta penarikan Rp30.000; saldo santri menjadi Rp70.000 di web/API.
- [ ] Dua request setoran dengan kunci idempotensi sama menghasilkan tepat satu kredit; kunci sama dengan nominal berbeda ditolak tanpa mutasi.
- [ ] Dua penarikan Rp80.000 yang bersamaan terhadap saldo Rp100.000 menghasilkan paling banyak satu keberhasilan dan tidak ada saldo negatif.
- [ ] Admin tidak dapat melakukan mutasi; wali tidak dapat melakukan mutasi; petugas tanpa penugasan, pengurus di luar cakupan, wali anak lain, dan sesi dengan penugasan dicabut mendapat `403`/`404` atau daftar kosong tanpa mutasi maupun data santri lain.
- [ ] Migrasi dan rollback pada salinan basis data mempertahankan jumlah serta ID santri/wali dan baris PSB/V1–V5; verifier menghasilkan nol referensi yatim baru.

### Fase 2: Tagihan Bulanan Lengkap (memerlukan fondasi Fase 1)

**Tujuan:** BSA dapat menetapkan tarif unit/jenjang, menerbitkan tagihan periode, menerima pembayaran tunai/transfer, dan melihat tunggakan secara tepat.

**Persyaratan:**

1. Kelola tarif dan periode berlaku; terbitkan tagihan santri aktif menurut unit/jenjang dengan penyesuaian per santri beralasan.
2. Sediakan pratinjau, penerbitan idempoten untuk banyak santri, snapshot tarif, aturan jatuh tempo, dan penanganan santri yang pindah unit atau tidak aktif.
3. Catat pembayaran tunai/transfer, verifikasi BSA, pembayaran sebagian, sisa tagihan, kuitansi, serta pembatalan/koreksi beralasan.
4. Sediakan daftar tunggakan dan riwayat pembayaran untuk BSA/admin serta proyeksi hanya-baca untuk wali anaknya di website/API.
5. Uji kegagalan transaksi, kiriman ganda, dua pembayaran bersamaan, perubahan tarif setelah terbit, dan pencabutan penugasan pada sesi lama.

**Kriteria penerimaan:**

- [ ] Tarif unit yang sama menghasilkan tagihan sesuai santri sasaran; satu penyesuaian per santri beralasan mengubah hanya tagihan santri tersebut.
- [ ] Menjalankan penerbitan periode yang sama dua kali tidak membuat tagihan kedua atau mengubah snapshot tagihan pertama.
- [ ] Pembayaran Rp40.000 lalu Rp60.000 atas tagihan Rp100.000 menghasilkan sisa Rp0 dan dua transaksi terverifikasi; percobaan membayar lebih dari sisa ditolak.
- [ ] Perubahan tarif setelah terbit tidak mengubah nominal atau kuitansi tagihan lama.
- [ ] Wali lain tidak dapat membaca tagihan, kuitansi, atau API anak yang bukan relasinya, termasuk melalui ID yang ditebak.

### Fase 3: Tabungan Lengkap dan Pembayaran Silang (memerlukan Fase 1–2)

**Tujuan:** BSA dapat mencatat setoran dan pengeluaran lengkap serta membayar tagihan dari tabungan tanpa kehilangan konsistensi kedua riwayat.

**Persyaratan:**

1. Lengkapi alur setoran tunai/transfer, verifikasi, penarikan/belanja, tujuan, referensi, bukti, kuitansi, dan koreksi beralasan.
2. Tampilkan saldo, mutasi, dan riwayat per santri secara urut; status belum terverifikasi tidak dihitung sebagai saldo efektif.
3. Tambahkan tindakan eksplisit pembayaran tagihan dari tabungan, termasuk verifikasi saldo/sisa tagihan serta dua pencatatan atomik yang terhubung.
4. Sediakan rekonsiliasi harian BSA antara kas/transfer yang diverifikasi, debit tabungan, dan pembayaran tagihan; perpindahan internal tidak dihitung sebagai penerimaan kas baru.
5. Uji kiriman ulang, rollback pada kegagalan di tengah operasi, dua penarikan atau pembayaran silang bersamaan, dan koreksi pada transaksi yang sudah digunakan untuk membayar tagihan.

**Kriteria penerimaan:**

- [ ] Setoran Rp100.000 dan belanja Rp25.000 menghasilkan saldo Rp75.000; dua belanja Rp60.000 bersamaan menghasilkan tepat satu sukses.
- [ ] Pembayaran tagihan Rp50.000 dari saldo Rp75.000 menurunkan saldo menjadi Rp25.000 dan sisa tagihan tepat Rp50.000; ada satu referensi silang yang dapat dibaca di kedua riwayat.
- [ ] Jika pencatatan pembayaran tagihan gagal setelah debit disiapkan, rollback menyisakan saldo serta sisa tagihan semula dan nol transaksi efektif yatim.
- [ ] Menekan tindakan pembayaran tabungan dua kali atau mengulang request dengan kunci sama menghasilkan paling banyak satu debit dan satu pembayaran.
- [ ] Laporan penerimaan kas tidak bertambah karena pembayaran internal dari tabungan yang telah diterima sebelumnya.

### Fase 4: Paritas Aplikasi Mobile dan Orang Tua (memerlukan Fase 1–3)

**Tujuan:** Alur BSA/admin dan pembacaan orang tua di aplikasi menghasilkan hasil dan hak yang sama dengan website.

**Persyaratan:**

1. Verifikasi baseline web/mobile serta versi API aktif sebelum perubahan. Tambahkan layar Expo untuk daftar, detail, transaksi BSA, tagihan, tabungan, riwayat, dan laporan sesuai kemampuan per peran.
2. BSA dapat menyiapkan dan menyetujui transaksi, memverifikasi pembayaran serta setoran, dan menggunakan tabungan untuk tagihan dari aplikasi; server tetap menjadi sumber keputusan dan saldo.
3. Admin dapat mengawasi semua transaksi dan audit; orang tua hanya melihat anaknya, termasuk ketika mempunyai lebih dari satu anak.
4. Tangani keadaan memuat/kosong/gagal, koneksi terputus, klik ganda, konflik versi, sesi kedaluwarsa, dan pencabutan hak dengan pesan Bahasa Indonesia yang jelas.
5. Bila pemberitahuan diaktifkan, jalur in-app/push umum meminta autentikasi dan otorisasi ulang pada tujuan; jangan simpan bukti atau data keuangan sensitif dalam log/cache yang tidak diperlukan.

**Kriteria penerimaan:**

- [ ] Transaksi yang dibuat di aplikasi terlihat sekali dan dengan angka identik di website; pengulangan karena jaringan tidak menggandakan tagihan, setoran, penarikan, atau pembayaran.
- [ ] Matriks BSA/admin/wali dan dua wali dari santri berbeda menghasilkan nol akses lintas cakupan pada web, API, dan aplikasi.
- [ ] Wali dengan dua anak melihat dua himpunan data terpisah; relasi yang dicabut menutup akses meskipun sesi dan layar aplikasi lama masih terbuka.
- [ ] Uji Android dan iOS mencatat jenis build/perangkat; emulator dan perangkat fisik dibedakan, tanpa menyatakan uji fisik lulus bila belum dijalankan.
- [ ] Log dan cache yang diperiksa memuat nol token, nomor rekening, bukti transfer, atau riwayat anak lain tanpa keperluan.

### Fase 5: Laporan, Audit, dan Kesiapan Rilis Bersama (memerlukan Fase 1–4)

**Tujuan:** Tagihan dan tabungan lolos audit keuangan serta keamanan dan siap diputuskan sebagai satu rilis V6.

**Persyaratan:**

1. Sediakan laporan per periode dan per santri: tagihan terbit, dibayar, tunggakan, setoran, penarikan/belanja, saldo akhir, pembayaran dari tabungan, pembatalan, serta selisih rekonsiliasi.
2. Uji invarians total per santri dan agregat terhadap ledger serta tagihan pada fixture sintetis; pisahkan kas/transfer nyata dari perpindahan internal.
3. Jalankan audit independen oleh auditor V6 yang ditetapkan; uji konkurensi, idempotensi, koreksi, cakupan peran, relasi wali, regresi PSB dan V1–V5, serta web dan aplikasi.
4. Siapkan manifest migrasi, preflight, backup/restore ke salinan, verifier, rollback aman, panduan deploy cPanel, dan smoke test dengan data sintetis.
5. Catat uji produksi, perangkat fisik, dan rekonsiliasi yang belum terlaksana sebagai terbuka. Human Developer memutuskan merge/deploy hanya setelah kedua modul siap sekaligus dan seluruh kriteria wajib lulus.

**Kriteria penerimaan:**

- [ ] Untuk setiap santri uji, saldo akhir sama dengan jumlah setoran sah dikurangi penarikan/belanja, pembayaran tagihan dari tabungan, dan pembalik yang sah; selisih verifier Rp0.
- [ ] Untuk setiap tagihan uji, total tagihan dikurangi seluruh pembayaran sah sama dengan sisa; tidak ada pembayaran melebihi sisa atau tagihan ganda.
- [ ] Rekonsiliasi kas/transfer dan perpindahan tabungan pada fixture menghasilkan selisih Rp0 serta tidak menghitung pembayaran internal sebagai kas baru.
- [ ] Seluruh transaksi pembatalan/koreksi memiliki aktor, waktu, alasan, dan referensi asal; nol mutasi efektif tanpa jejak.
- [ ] Uji lintas peran dan lintas santri pada web/API/aplikasi menghasilkan nol kebocoran atau mutasi tidak sah; regresi PSB dan V1–V5 dilaporkan jujur terhadap baseline.
- [ ] Backup/restore ke salinan, migrasi, verifier, rollback, dan smoke cPanel mempunyai bukti terpisah; hasil lokal tidak diberi label lulus produksi.
- [ ] Auditor V6 yang ditetapkan menyatakan tagihan **dan** tabungan memenuhi kriteria wajib sebelum Human Developer mempertimbangkan rilis bersama.

## 7. Metrik Keberhasilan

- **Nol penggandaan efektif** tagihan per santri–periode, setoran, penarikan, pembayaran, atau kuitansi akibat klik ganda/retry pada skenario uji.
- **Nol saldo negatif** dan **Rp0 selisih** antara saldo, ledger, pembayaran tagihan, serta laporan pada fixture dan smoke yang disetujui.
- **Nol akses lintas santri/peran** pada daftar, detail, ekspor, kuitansi, dan API setelah pencabutan hak diuji.
- **100% koreksi keuangan yang diuji** mempunyai aktor, waktu, alasan, dan referensi ke transaksi asal; tidak ada penghapusan transaksi sah tanpa riwayat.
- **Rilis bersama**: status produksi V6 hanya dapat dinyatakan setelah tagihan dan tabungan sama-sama lulus audit, smoke cPanel, serta keputusan Human Developer.
