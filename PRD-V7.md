# Pengajuan Izin Orang Tua melalui Pembimbing — PRD V7

> **Untuk agen AI:** Dokumen ini adalah instruksi implementasi. Jika ada hal yang tidak jelas, jangan menebak—tanyakan kepada Human Developer. Ketika keputusan berubah selama implementasi, perbarui dokumen ini agar tetap menjadi sumber kebenaran.

**Status:** Kebutuhan, alur, lima fase, dan seluruh asumsi V7 disetujui Human Developer pada 27 Oktober 2026. Claude Code ditetapkan sebagai implementator dan Codex sebagai auditor V7, bergantian satu fase per giliran; prompt ada di `docs/prd-v7/prompts-implementasi-audit.md`. Dokumen ini bukan perintah implementasi semua fase, merge, migrasi/deploy produksi, atau rilis aplikasi. Audit teknis lokal V6 Fase 5 sudah lulus, tetapi gerbang akhir/produksi V6 masih terbuka menurut `docs/phase-v6-5/AUDIT-CODEX.md`; perencanaan V7 tidak mengubah status itu.

**Hubungan dengan V2:** V7 menambah **jalur orang tua → pembimbing → murobi**. Jalur V2 **pengurus → murobi** tetap tersedia. Keputusan akhir izin yang diteruskan tetap oleh guru yang mendapat penugasan murobi, atau admin sebagai pengganti beralasan. V7 mengganti batas V2 yang dahulu membuat orang tua hanya-baca **khusus untuk pengajuan yang dibuat orang tua**; aturan V2 lain dipertahankan kecuali dinyatakan berubah di bawah.

## 1. Gambaran Umum

Orang tua saat ini dapat melihat status izin anak, tetapi tidak dapat mengajukannya sendiri melalui sistem. V7 memberi orang tua jalur pengajuan yang ditinjau pembimbing pengurus sebelum sampai kepada murobi guru, dengan status, notifikasi, dan jejak keputusan yang dapat ditelusuri. Alur baru harus mencegah pengajuan atau keputusan ganda dan tetap menjaga hak akses lintas santri serta fungsi perizinan V2 yang sudah berjalan.

## 2. Pengguna Sasaran dan Kebutuhan

1. **Orang tua pengaju** — Pemilik akun wali dengan relasi aktif ke santri memilih anaknya, mengisi tanggal izin/kembali, alasan, dan catatan; melihat status, memperbaiki ketika diminta pembimbing, atau membatalkan beralasan sebelum keputusan akhir.
2. **Wali lain pada santri yang sama** — Dapat melihat status ringkas pengajuan yang dibuat wali pengaju, tanpa alasan atau catatan pribadi, dan tidak dapat memperbaiki atau membatalkannya.
3. **Pembimbing terkait** — Pengurus dengan penugasan pembimbing aktif menerima pengajuan wali dalam cakupannya, meninjau, meminta perbaikan, menolak pada tahap pembimbing, atau meneruskan pengajuan yang layak kepada murobi.
4. **Murobi terkait** — Guru dengan penugasan murobi aktif menerima hanya pengajuan wali yang sudah diteruskan pembimbing dan memutuskan izin sesuai aturan V2.
5. **Admin** — Mengawasi seluruh tahap, menetapkan pembimbing saat tidak ada tepat satu kandidat sah, memperbaiki penugasan/routing dengan alasan, dan tetap dapat memutus sebagai pengganti murobi sesuai V2. Admin tidak otomatis menggantikan tindakan peninjauan pembimbing.
6. **Pengurus pengaju V2** — Tetap dapat membuat pengajuan langsung ke murobi dalam cakupan yang sudah berlaku, tanpa wajib melewati antrean wali/pembimbing baru.

## 3. Fitur Inti (Ruang Lingkup)

1. **Pengajuan wali dan akses anak.** Wali yang berakun aktif mengajukan izin hanya untuk santri dengan relasi `santri_wali` aktif melalui website dan aplikasi. Formulir memakai tanggal izin, tanggal kembali, alasan, serta catatan seperti V2; tidak ada lampiran. Permintaan ulang/klik ganda tidak membuat baris kedua.
2. **Routing dan antrean pembimbing.** Sistem memeriksa penugasan pembimbing aktif dan cakupan santri. Tepat satu pembimbing sah menerima antrean. Nol atau lebih dari satu kandidat masuk antrean admin untuk penetapan pembimbing yang sah, dan belum boleh masuk antrean murobi.
3. **Peninjauan pembimbing.** Pembimbing dapat meminta perbaikan dengan catatan, menolak dengan alasan, atau meneruskan permohonan yang layak. Hanya wali pengaju dapat memperbaiki isi dan mengirim ulang; setiap versi, tindakan, alasan, dan pelaku tercatat. Penolakan pembimbing menutup pengajuan sebelum keputusan murobi dan dibedakan dari penolakan izin oleh murobi.
4. **Keputusan murobi dan pembatalan wali.** Setelah diteruskan, routing murobi dan keputusan mengikuti V2, termasuk antrean admin bila murobi tidak tunggal serta admin pengganti beralasan. Wali pengaju boleh membatalkan beralasan sampai sebelum keputusan murobi/admin pengganti; keputusan dan pembatalan yang bersaing harus berakhir pada tepat satu hasil efektif.
5. **Status dan notifikasi.** Pembimbing diberi notifikasi saat pengajuan masuk; wali pengaju saat dikembalikan, ditolak pembimbing, diteruskan, atau diputus; murobi saat diteruskan. Notifikasi in-app dan push memakai infrastruktur V2 dengan pesan umum serta pemeriksaan hak ulang saat tujuan dibuka. WhatsApp tetap OFF dan ditangguhkan sesuai keputusan produk yang ada.
6. **Paritas web dan aplikasi, laporan, audit.** Orang tua, pembimbing, murobi, dan admin menjalankan bagian masing-masing melalui website serta aplikasi. Laporan memisahkan asal pengajuan wali/pengurus dan tahap yang sedang berlangsung. Seluruh perubahan status, versi, routing, keputusan, pembatalan, dan notifikasi tercatat tanpa membuka data anak lain.

## 4. Di Luar Ruang Lingkup V7

- V7 tidak menghentikan atau mewajibkan alur pengurus V2 melewati tahap pembimbing baru.
- V7 tidak mengubah identitas murobi sebagai guru berpenugasan atau pembimbing sebagai pengurus berpenugasan.
- V7 tidak membuat role login baru untuk wali, pembimbing, atau murobi.
- V7 tidak menambahkan lampiran foto/dokumen pada pengajuan orang tua.
- V7 tidak mengaktifkan WhatsApp atau mensyaratkan penyedia WhatsApp untuk kelulusan.
- V7 tidak memberi wali lain hak memperbaiki atau membatalkan pengajuan wali pengaju.
- V7 tidak membuat izin otomatis disetujui karena pembimbing meneruskan; keputusan akhir tetap murobi/admin pengganti.
- V7 tidak membuat permohonan offline yang disinkronkan kemudian.
- V7 tidak mengubah modul BSA V6, kesehatan V5, pembinaan V3, atau penilaian V4.

## 5. Batasan Teknis, Data, dan Keputusan Produk

### 5.1 Keputusan Human Developer

- Wali berelasi aktif dapat mengajukan izin melalui pembimbing yang ditugaskan; pembimbing meninjau, meminta perbaikan, menolak, atau meneruskan kepada murobi. **[JANGAN DIUBAH]**
- Bila santri tidak memiliki tepat satu pembimbing aktif, admin menetapkan pembimbing dahulu; pengajuan belum dikirim ke murobi. **[JANGAN DIUBAH]**
- Hanya wali pengaju yang memperbaiki permintaan setelah catatan pembimbing dan yang membatalkan beralasan sebelum keputusan akhir. Wali lain yang berelasi hanya melihat status. **[JANGAN DIUBAH]**
- Formulir V7 memakai tanggal, alasan, dan catatan V2 tanpa lampiran. **[JANGAN DIUBAH]**
- Jalur langsung pengurus V2 tetap tersedia. Murobi yang ditugaskan memberi keputusan akhir; admin dapat menggantikan bila diperlukan dengan alasan/audit sesuai V2. **[JANGAN DIUBAH]**
- Pembimbing mendapat notifikasi saat diajukan; wali mendapat notifikasi saat dikembalikan, ditolak, diteruskan, atau diputus; murobi saat diteruskan. Seluruh alur V7 tersedia pada website dan aplikasi. **[JANGAN DIUBAH]**
- V7 hanya diterima jika alur web/aplikasi dan pembatasan peran lulus, tanpa pengajuan/keputusan ganda, akses lintas santri/peran, atau perubahan tanpa audit. **[JANGAN DIUBAH]**
- WhatsApp tetap OFF, benturan tanggal V2 berlaku bagi kedua jalur, dan tidak ada role login baru; ketiganya termasuk ringkasan asumsi yang disetujui Human Developer pada 27 Oktober 2026. **[JANGAN DIUBAH]**

### 5.2 Fondasi V2 dan kompatibilitas

- Backend tetap PHP native modular dan MySQL/MariaDB pada cPanel, API kompatibel di bawah `/api/v1`, aplikasi Expo/React Native/TypeScript pada `alhasanApps`. Gunakan autentikasi, CSRF web, token mobile, capability server, notifikasi in-app/push, outbox, serta laporan yang sudah ada.
- Periksa implementasi nyata `izin_pengajuan`, `izin_keputusan`, `izin_riwayat_status`, `izin_idempotency_keys`, `pembimbing_assignments`, `murobi_assignments`, `users`, `wali`, dan `santri_wali` sebelum memutuskan migrasi. `perizinan` warisan, ID, keputusan, dan riwayat V2 harus tetap terbaca.
- Satu pengajuan V7 memiliki **satu identitas kanonis sejak dibuat wali sampai diputus**, melalui perluasan aditif `izin_pengajuan` dan status/alur yang kompatibel. Penerusan pembimbing tidak boleh membuat pengajuan kedua. Rincian migrasi, indeks, serta kompatibilitas ENUM/status V2 wajib dibuktikan pada salinan database sebelum implementasi.
- Periksa pembimbing aktif menurut tanggal, tahun ajaran, kelas/kamar, dan kondisi santri; admin hanya menetapkan calon yang sah. Periksa ulang penugasan dan akun pada setiap permintaan; bila penugasan dicabut sebelum tindakan, sesi/token lama tidak memberi hak. Aturan persis perpindahan cakupan saat permohonan sedang berjalan memerlukan penanganan audit/rerouting tanpa menghapus jejak.
- Pengajuan wali dan pengurus berbagi pemeriksaan benturan waktu V2. Status wali yang masih menunggu pembimbing, perbaikan, atau penetapan admin termasuk pengajuan aktif yang menahan pengajuan lain pada rentang sama; status ditolak pembimbing/dibatalkan melepaskan rentang.
- Perubahan skema aditif dan berversi setelah migrasi terakhir yang benar-benar ada. Sediakan preflight, backup, rollback aman, dan verifier; uji pada salinan MySQL/MariaDB serta lindungi data identitas santri/wali dan riwayat V1–V6. Jangan menjalankan migrasi produksi dalam fase implementasi tanpa keputusan Human Developer.
- Pembagian peran V7 tercatat di `AGENTS.md`: Claude Code mengimplementasikan dan Codex mengaudit secara independen, bergantian satu fase per giliran. Fase 1 tetap memerlukan instruksi Human Developer tersendiri. Status gerbang produksi V6 yang masih terbuka tidak boleh dinyatakan selesai hanya karena V7 dirancang.

### 5.3 Status, tindakan, dan batas akses

- Status atau tahap V7 perlu membedakan minimal: menunggu pembimbing, perlu penetapan pembimbing oleh admin, perlu perbaikan wali, ditolak pembimbing, diteruskan ke murobi/perlu penetapan murobi, disetujui, ditolak murobi/admin pengganti, dan dibatalkan. Label teknis boleh menyesuaikan kode sepanjang perbedaan tahap tetap jelas; jangan memetakan penolakan pembimbing sebagai keputusan murobi.
- Wali pengaju dapat memperbaiki tanggal/alasan/catatan hanya ketika pembimbing secara eksplisit meminta perbaikan; saat mengirim ulang, versi sebelumnya tetap diaudit. Sesudah diteruskan, pengubahan isi oleh wali ditutup.
- Pembimbing hanya dapat bertindak untuk pengajuan wali yang dialamatkan kepadanya dan masih berada dalam cakupan aktif. Murobi tidak melihat atau memutus sebelum penerusan; admin melihat antrean pengecualian dan memantau seluruh alur.
- Wali lain dengan relasi aktif memperoleh status ringkas tanpa catatan perbaikan, alasan pribadi, atau hak mutasi.
- Semua mutasi wajib idempotency key dan versi optimistis; transisi status, audit, serta item outbox terjadi atomik atau dalam keadaan pulih-ulang yang aman. Retry dengan isi sama mengembalikan hasil sama; kunci sama dengan isi berbeda atau versi lama ditolak `409` tanpa perubahan.
- Pembatalan sebelum keputusan murobi/admin pengganti berlaku pada setiap tahap wali, termasuk sesudah penerusan; pembimbing/murobi yang terdampak diberi status terkini. Pembatalan setelah keputusan ditolak; koreksi keputusan tetap memakai mekanisme V2.
- Pesan push tidak memuat nama santri, alasan izin, tanggal rinci, nomor telepon, atau isi catatan; pembukaan tujuan selalu meminta autentikasi/otorisasi ulang.

### 5.4 Data minimum

- **Pengajuan:** ID baru dalam ruang ID `izin_pengajuan` yang sama tanpa mengubah ID V2 lama, asal `Wali` atau `Pengurus`, santri, wali pengaju/user, tanggal, alasan, catatan, status/tahap, versi, penugasan pembimbing, murobi tujuan, serta kunci idempotensi. Penanda asal dan hubungan wali ditambahkan secara aditif.
- **Peninjauan pembimbing:** tindakan minta perbaikan/tolak/teruskan, pengurus dan penugasan, waktu, alasan atau catatan, versi sebelum/sesudah, serta snapshot minimal yang dibutuhkan audit.
- **Penetapan admin:** kandidat dan pembimbing yang ditetapkan, alasan, pelaku, waktu, serta riwayat penetapan ulang. Penetapan tidak memberi hak kepada akun di luar penugasan sah.
- **Keputusan akhir:** satu keputusan efektif dari murobi yang ditugaskan atau admin pengganti, berikut alasan, kapasitas, waktu, dan versi sesuai V2.
- **Pembatalan/perbaikan:** pelaku wali pengaju, alasan pembatalan atau isi perbaikan, versi asal/baru, serta waktu. Riwayat lama tidak ditimpa.
- **Notifikasi:** peristiwa, penerima yang masih berhak, kanal in-app/push, deduplikasi, status kirim, serta tautan aman ke pengajuan; tidak menyimpan detail sensitif di payload push.
- **Laporan:** asal pengajuan, tahap pembimbing/murobi, tanggal, unit/kamar/kelas, lama tiap tahap, hasil, dan hitungan; daftar dan angka memakai filter cakupan yang sama.

## 6. Persyaratan Bertahap

Urutan lima fase berikut telah disetujui. Setiap fase menghasilkan alur yang dapat dicoba manual pada lingkungan uji; fase parsial bukan izin rilis. Implementasi baru dimulai setelah Human Developer memerintahkan satu fase tertentu.

### Fase 1: Fondasi Pengajuan Wali dan Routing Pembimbing

**Tujuan:** Wali berelasi aktif dapat membuat satu pengajuan, melihat statusnya, dan pembimbing/admin melihat antrean awal di web/API pada database uji.

**Persyaratan:**

1. Baca `AGENTS.md`, PRD V2/V7, status V6, commit terbaru web/mobile, layanan izin V2, akun wali, penugasan pembimbing, notifikasi, dan migrasi terakhir.
2. Tambahkan skema/indeks/status secara aditif untuk asal wali, identitas pengaju, tahap pembimbing, versi, dan audit tanpa mengubah ID/data V2 serta `perizinan` warisan.
3. Tambahkan pembuatan pengajuan wali pada layanan yang memeriksa relasi wali–santri aktif, santri aktif, tahun ajaran, tanggal, dan benturan dengan **kedua jalur** di dalam transaksi.
4. Routing awal mengirim tepat satu pembimbing sah ke antrean; nol/lebih dari satu kandidat masuk admin. Tidak ada routing murobi sebelum pembimbing meneruskan.
5. Sediakan UI web dan endpoint API minimum bagi wali untuk membuat/membaca, pembimbing untuk melihat antrean, dan admin untuk memantau/menetapkan pembimbing.
6. Terapkan idempotensi, audit metadata, otorisasi setiap pembacaan dan mutasi, migrasi/rollback aman, fixture sintetis, serta pengujian salinan MySQL/MariaDB.

**Kriteria penerimaan:**

- [ ] Wali A membuat satu pengajuan untuk anak A di web/API; wali A dapat membacanya, pembimbing A melihat satu item, dan murobi melihat nol item sebelum penerusan.
- [ ] Dua kiriman identik dengan kunci sama menghasilkan tepat satu ID; kunci sama dengan isi berbeda menghasilkan `409` dan tidak membuat baris baru.
- [ ] Wali B tanpa relasi anak A memperoleh `403`/`404` atau hasil kosong pada buat, daftar, detail, dan ID tebakan; wali kedua yang berelasi hanya melihat status tanpa tombol mutasi.
- [ ] Nol/dua kandidat pembimbing menghasilkan satu item antrean admin dan nol item antrean murobi; admin hanya dapat menetapkan pembimbing dengan penugasan aktif yang cocok.
- [ ] Dua pengajuan wali/pengurus untuk santri dan rentang bertumpang tindih menghasilkan paling banyak satu pengajuan aktif; data V2/warisan dan ID santri–wali tetap utuh sesudah migrasi/rollback uji.

### Fase 2: Tinjauan Pembimbing, Perbaikan, dan Keputusan Akhir (memerlukan Fase 1)

**Tujuan:** Satu pengajuan wali dapat ditinjau pembimbing, diperbaiki atau ditolak, diteruskan, lalu diputus murobi/admin pengganti pada web/API.

**Persyaratan:**

1. Sediakan tindakan pembimbing minta perbaikan, tolak, dan teruskan dengan alasan/catatan sesuai keputusan; setiap tindakan memeriksa penugasan aktif serta versi.
2. Wali pengaju memperbaiki dan mengirim ulang hanya saat diminta; wali lain tidak dapat menyunting. Wali pengaju dapat membatalkan beralasan sebelum keputusan akhir pada tahap mana pun.
3. Saat diteruskan, jalankan routing murobi V2; satu murobi valid menerima antrean, sedangkan routing murobi tidak tunggal masuk admin. Keputusan akhir memakai pengaman V2 tanpa membuat pengajuan atau keputusan baru yang ganda.
4. Lindungi pembatalan versus penerusan/keputusan bersamaan, retry, status kedaluwarsa, dan perubahan penugasan/cakupan pada sesi lama.
5. Sediakan detail dan riwayat yang sesuai peran di web/API, termasuk perbedaan penolakan pembimbing dan penolakan murobi.

**Kriteria penerimaan:**

- [ ] Permintaan perbaikan menampilkan catatan kepada wali pengaju; hanya akun itu dapat memperbaiki dan mengirim ulang, dengan versi sebelum/sesudah tercatat.
- [ ] Penolakan pembimbing menutup pengajuan tanpa baris `izin_keputusan` murobi; orang tua melihat hasil tahap pembimbing, dan pengajuan baru tidak diblokir oleh rentang yang telah ditolak.
- [ ] Penerusan membuat tepat satu antrean murobi atau antrean admin jika routing murobi ambigu; murobi di luar penugasan mendapat `403` dan tidak dapat memutus.
- [ ] Wali pengaju membatalkan sebelum keputusan dengan alasan; pembatalan dan keputusan bersamaan menghasilkan tepat satu status terminal dan paling banyak satu keputusan efektif.
- [ ] Jalur pengurus V2 tetap dapat membuat, membatalkan, dan memutus sesuai cakupan semula tanpa melewati tahap pembimbing baru.

### Fase 3: Notifikasi, Laporan, dan Audit (memerlukan Fase 1–2)

**Tujuan:** Seluruh peralihan tahap V7 memberi tahu pihak yang tepat dan dapat dilaporkan tanpa kehilangan riwayat atau membocorkan isi izin.

**Persyaratan:**

1. Gunakan outbox/in-app/push V2 untuk peristiwa: wali mengajukan → pembimbing/admin; minta perbaikan/tolak/teruskan/keputusan → wali pengaju; penerusan → murobi atau admin routing.
2. Penerima, penugasan, dan relasi wali diperiksa ulang sebelum pengiriman serta saat membuka tujuan; retry tidak menggandakan pesan efektif.
3. Buat laporan terfilter menurut asal pengajuan, tahap, hasil, tanggal, santri, pembimbing, murobi, dan durasi; pembaca hanya melihat cakupan yang sah.
4. Catat seluruh penetapan pembimbing, perubahan isi, penolakan/penerusan, keputusan, pembatalan, koreksi, dan pengiriman notifikasi dengan pelaku, alasan, waktu, serta versi, tanpa credential atau alasan izin di push/log umum.
5. Pastikan WhatsApp tetap OFF dan nol request provider; kegagalan notifikasi tidak membatalkan transaksi izin.

**Kriteria penerimaan:**

- [ ] Setiap peristiwa pada fixture menghasilkan satu notifikasi in-app per penerima berhak dan paling banyak satu push sesuai sakelar; retry tidak menggandakan.
- [ ] Push OFF/WhatsApp OFF menghasilkan nol request provider; pengajuan dan keputusan tetap berhasil.
- [ ] Setelah relasi wali/penugasan pembimbing dicabut, sesi/token lama dan tautan notifikasi tidak membuka pengajuan di luar haknya.
- [ ] Laporan pada fixture menghitung pengajuan wali dan pengurus tepat sekali; jumlah kartu, daftar, ekspor, dan detail yang terlihat per peran konsisten.
- [ ] Seluruh perubahan fixture mempunyai pelaku, waktu, alasan bila diperlukan, dan versi; payload push/log umum memuat nol alasan izin, nama santri, nomor telepon, atau token mentah.

### Fase 4: Paritas Aplikasi Mobile (memerlukan Fase 1–3)

**Tujuan:** Orang tua, pembimbing, murobi, dan admin dapat menyelesaikan alur V7 di aplikasi dengan hasil dan pembatasan yang sama seperti website.

**Persyaratan:**

1. Verifikasi baseline nyata kedua repositori serta kontrak API dan versi Expo sebelum menulis mobile; gunakan endpoint V7 aditif, tanpa memutus layar V2.
2. Sediakan layar wali untuk memilih anak, membuat, melihat status, memperbaiki, mengirim ulang, serta membatalkan; layar pembimbing untuk antrean/detail/tindakan; layar murobi/admin untuk peran yang berlaku.
3. Sediakan keadaan memuat, kosong, gagal, konflik versi, koneksi terputus, sesi kedaluwarsa, serta hak dicabut dengan pesan Bahasa Indonesia yang jelas.
4. Tangani ketukan notifikasi foreground/background/cold start; buka pengajuan hanya setelah autentikasi dan pemeriksaan hak server.
5. Jangan menyimpan alasan izin, data anak lain, token, atau payload sensitif dalam log/cache perangkat tanpa keperluan.

**Kriteria penerimaan:**

- [ ] Pengajuan wali di aplikasi muncul sekali pada web/API; rangkaian perbaikan, penerusan, keputusan, dan pembatalan menghasilkan status yang sama pada ketiga permukaan.
- [ ] Dua wali pada anak sama diuji: pengaju dapat memperbaiki/membatalkan, wali kedua hanya melihat status; wali anak lain dan pembimbing/murobi di luar cakupan ditolak server.
- [ ] Retry jaringan/ketukan ganda dari aplikasi menghasilkan paling banyak satu pengajuan dan satu keputusan efektif.
- [ ] Android dan iOS diuji dengan jenis build/perangkat dicatat; simulator/emulator tidak diklaim sebagai bukti perangkat fisik.
- [ ] Ketukan push setelah logout meminta login; setelah relasi/penugasan dicabut, tujuan tidak menampilkan isi pengajuan.

### Fase 5: Regresi, Keamanan, dan Kesiapan Rilis (memerlukan Fase 1–4)

**Tujuan:** Jalur wali dan jalur pengurus V2 sama-sama aman, konsisten, dan memiliki bukti rilis/pemulihan sebelum keputusan produksi.

**Persyaratan:**

1. Jalankan audit independen atas seluruh fase oleh auditor V7 yang ditetapkan dan tindak lanjuti koreksi terarah serta regresinya.
2. Uji alur end-to-end pada web/API/aplikasi, termasuk satu/tidak ada/lebih dari satu pembimbing/murobi, dua wali, perubahan relasi/penugasan, benturan tanggal, retry, dan aksi bersamaan.
3. Uji preservasi `izin_pengajuan`/keputusan/riwayat V2 serta data V1–V6, termasuk laporan/cetak lama, pada salinan MySQL/MariaDB dan browser 375/768/1280 px.
4. Sediakan manifest migrasi, preflight, verifier, backup/restore ke salinan, rollback aman, panduan cPanel, dan smoke test dengan fixture sintetis.
5. Pisahkan bukti lokal dari perangkat fisik, Push nyata, hosting/cPanel, dan UAT yang benar-benar dilaksanakan. Merge/deploy dan keputusan rilis tetap milik Human Developer setelah seluruh gerbang wajib lulus.

**Kriteria penerimaan:**

- [ ] Seluruh jalur wali → pembimbing → murobi/admin pengganti selesai di web dan aplikasi; jalur pengurus V2 tetap lulus regresi.
- [ ] Skenario klik ganda, retry, dan konkurensi menghasilkan nol pengajuan, penerusan, keputusan, pembatalan, atau notifikasi efektif ganda.
- [ ] Matriks wali pengaju/wali lain/wali asing/pembimbing/murobi/admin pada daftar, detail, mutasi, laporan, dan tautan push menghasilkan nol akses lintas santri/peran.
- [ ] Setiap perubahan pengajuan fixture memiliki pelaku, waktu, alasan bila wajib, serta versi sebelum/sesudah; tidak ada mutasi tanpa audit.
- [ ] Jumlah dan ID pengajuan V2/warisan, keputusan, serta riwayat tetap sama pada uji migrasi/rollback salinan; verifier menemukan nol referensi yatim baru.
- [ ] Bukti backup/restore, migrasi, verifier, rollback, smoke cPanel, dan uji perangkat dinyatakan sesuai pelaksanaan nyata; uji yang belum dilakukan tetap berstatus terbuka.
- [ ] Auditor yang ditetapkan menyatakan seluruh kriteria wajib V7 terpenuhi sebelum Human Developer mempertimbangkan merge/deploy/rilis.

## 7. Metrik Keberhasilan

- **Nol pengajuan atau keputusan efektif ganda** pada fixture retry, klik ganda, dan konkurensi lintas jalur wali/pengurus.
- **Nol akses lintas santri/peran** pada daftar, detail, mutasi, laporan, ekspor, dan tujuan notifikasi.
- **100% perubahan yang diuji memiliki riwayat** pelaku, waktu, alasan bila wajib, dan versi yang dapat ditelusuri.
- **100% kasus tanpa tepat satu pembimbing sah** tertahan pada antrean admin dan nol dikirim prematur ke murobi.
- **Regresi V2 lulus** terhadap baseline yang disepakati tanpa kehilangan ID, data warisan, atau jalur pengurus.
