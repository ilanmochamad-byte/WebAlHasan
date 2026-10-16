# Penilaian Semester dan Rapor Santri Al Hasan — PRD V4

> **Untuk agen AI:** Dokumen ini adalah instruksi implementasi. Jika ada hal yang tidak jelas, jangan menebak—tanyakan kepada pengguna. Ketika keputusan berubah selama implementasi, perbarui dokumen ini agar tetap menjadi sumber kebenaran (*living document*).

**Status:** Rancangan produk dan seluruh asumsi awal disetujui Human Developer pada 16 Oktober 2026. Fase 1 dan Fase 2 telah lulus audit Claude Code (Fase 2 dengan dua koreksi terarah; lihat `docs/phase-v4-2/audit-claude.md`); Fase 3 belum dimulai dan menunggu keputusan Human Developer. Codex ditetapkan sebagai implementator V4 dan Claude Code sebagai auditor; pekerjaan dilakukan bergantian sesuai `AGENTS.md`.

## 1. Gambaran Umum

Nilai semester, sikap, dan kehadiran santri perlu dihimpun dari petugas yang berbeda sebelum menjadi rapor yang benar, konsisten, dan dapat diperiksa kembali. V4 menyatukan pengisian nilai oleh guru pengampu, penilaian sikap oleh pembimbing, koreksi/pengetahuan murobi, verifikasi Bagian Pendidikan, serta penerbitan rapor untuk orang tua pada website dan aplikasi.

Tujuannya adalah mencegah nilai ganda, penerbitan rapor yang belum lengkap, perubahan diam-diam setelah terbit, dan akses lintas santri. Rapor final harus dapat dicetak, diunduh, serta diverifikasi melalui QR/kode tanpa membuka nilai kepada pengunjung umum.

## 2. Pengguna Sasaran & JTBD

1. **Guru pengampu** — Guru dengan penugasan `guru_mapel` aktif ingin mengisi dan mengoreksi nilai mata pelajaran hanya untuk kelas, mapel, dan semester yang ditugaskan; ia juga ingin melihat hasil serta laporan nilainya.
2. **Pembimbing** — Pengurus dengan penugasan pembimbing aktif ingin menilai sikap santri dalam cakupannya pada tiga aspek dan menyertakan catatan singkat.
3. **Murobi** — Guru dengan penugasan murobi aktif ingin mengetahui penilaian sikap santri binaannya atau melakukan koreksi beralasan yang tercatat.
4. **Bagian Pendidikan** — Pengurus dengan penugasan `pendidikan` aktif ingin menyusun daftar mata pelajaran wajib, mengisi nilai sebagai pengganti ketika guru berhalangan, memeriksa kelengkapan, mengembalikan data untuk perbaikan, memfinalisasi, menerbitkan, mengunduh, dan mencetak rapor sesuai cakupannya.
5. **Orang tua/wali** — Pemilik akun dengan relasi `santri_wali` aktif ingin mendapat pemberitahuan, membaca, dan mengunduh rapor yang sudah diterbitkan untuk santrinya melalui website dan aplikasi.
6. **Admin** — Ingin mengelola identitas penanda tangan, mengawasi seluruh proses dan audit, serta melakukan koreksi administratif beralasan bila diperlukan. Admin bukan pengganti diam-diam bagi guru atau Bagian Pendidikan.
7. **Penerima rapor bercetak** — Ingin memeriksa status penerbitan dan kecocokan PDF melalui QR/kode tanpa perlu mengetahui data akademik santri lain.

## 3. Fitur Inti (Ruang Lingkup)

1. **Rencana penilaian semester** — Daftar mata pelajaran wajib per kelas dan semester, guru pengampu, target ketuntasan, serta aturan bobot ditetapkan sebelum nilai dimasukkan. Sistem memakai master `mata_pelajaran`, penugasan `guru_mapel_assignments`, `pendidikan_assignments`, dan `tahun_ajaran` yang sudah ada.
2. **Nilai mata pelajaran** — Guru mencatat satu atau lebih nilai harian/praktik, nilai tengah semester, nilai akhir semester, dan catatan capaian. Bagian Pendidikan dapat memasukkan nilai sebagai pengganti dengan alasan dan identitas pelaku yang berbeda pada audit.
3. **Sikap dan kehadiran** — Pembimbing menilai adab/akhlak, kedisiplinan, dan tanggung jawab. Murobi terkait dapat mengetahui atau mengoreksi beralasan. Kehadiran dihitung dari absensi pengajian semester yang sama; lima status ditampilkan terpisah dan data yang belum dicatat tidak berubah menjadi Alpa.
4. **Verifikasi dan finalisasi** — Bagian Pendidikan melihat daftar kekurangan, mengembalikan isian, memverifikasi, lalu memfinalisasi hanya ketika seluruh mata pelajaran wajib, sikap, dan kehadiran memenuhi syarat. Pembukaan kembali serta koreksi menyimpan alasan dan riwayat.
5. **Rapor versi tetap** — Nilai, predikat, sikap, kehadiran, catatan, kelas, semester, dan identitas penanda tangan disalin menjadi snapshot pada saat terbit. Revisi menghasilkan versi baru tanpa menimpa versi terdahulu.
6. **Cetak, unduh, dan verifikasi** — Rapor memiliki tampilan web, PDF kanonis, gambar tanda tangan, tempat/tanggal, dan QR/kode verifikasi. Orang tua hanya dapat membaca serta mengunduh rapor terbit milik santri yang relasi walinya masih aktif; Bagian Pendidikan dapat mencetak sesuai cakupan.
7. **Aplikasi dan notifikasi** — Alur yang relevan tersedia di aplikasi React Native/Expo dengan API dan aturan server yang sama. Saat rapor terbit, orang tua mendapat notifikasi dalam aplikasi dan push generik: “Rapor semester tersedia”.

## 4. Di Luar Ruang Lingkup V4

- V4 tidak membuat role login baru; `guru_mapel`, Bagian Pendidikan, pembimbing, dan murobi tetap merupakan penugasan.
- V4 tidak membuat peringkat kelas atau daftar nilai publik antarsantri.
- V4 tidak menetapkan keputusan kenaikan kelas atau kelulusan secara otomatis.
- V4 tidak mengubah nilai akademik berdasarkan poin pelanggaran, konseling, atau keputusan perizinan V2/V3.
- V4 tidak menyediakan akun santri mandiri.
- V4 tidak membangun tanda tangan PDF berbasis sertifikat kriptografis; yang dipilih adalah gambar tanda tangan dengan QR/kode verifikasi di website.
- V4 tidak mengaktifkan WhatsApp; kanal tersebut tetap OFF sesuai keputusan produk sebelumnya.
- V4 tidak menyediakan penyuntingan nilai secara offline atau sinkronisasi konflik dari perangkat tanpa jaringan.
- V4 tidak mengerjakan tagihan bulanan V5 atau penerimaan dan pembiayaan PSB V6.

## 5. Batasan Teknis & Keputusan yang Sudah Diambil

### 5.1 Keputusan produk yang tidak boleh diganti diam-diam

- Bobot nilai per mata pelajaran: **40% rata-rata harian/praktik + 30% tengah semester + 30% akhir semester**. Skala komponen 0–100; sedikitnya satu nilai harian/praktik diperlukan. Nilai kosong adalah **Belum lengkap**, bukan 0. **[JANGAN DIUBAH]**
- Perhitungan mempertahankan dua angka desimal secara internal dan menampilkan bilangan bulat pada rapor. Predikat awal: A = 90–100, B = 80–89, C = 70–79, D = di bawah 70. Target ketuntasan awal adalah 70 dan dapat diatur per mata pelajaran/semester sebelum penilaian. **[JANGAN DIUBAH]**
- Semua mata pelajaran wajib pada kelas/semester harus lengkap dan diverifikasi sebelum rapor diterbitkan. Kekurangan ditampilkan kepada Bagian Pendidikan; sistem tidak mengisi nilai yang hilang dengan 0. **[JANGAN DIUBAH]**
- Guru pengampu merupakan penilai utama. Bagian Pendidikan dapat mengisi sebagai pengganti ketika guru berhalangan/tidak mampu, dengan alasan wajib dan audit kapasitas pengganti. **[JANGAN DIUBAH]**
- Pembimbing menilai sikap semester; murobi terkait dapat mengetahui atau mengoreksi beralasan. Aspek: adab/akhlak, kedisiplinan, tanggung jawab. Predikat tiap aspek: Sangat Baik, Baik, Cukup, Perlu Bimbingan. Catatan pembimbing tersedia. **[JANGAN DIUBAH]**
- Rekap kehadiran berasal dari absensi santri pada pertemuan pengajian semester rapor. Hadir, Terlambat, Izin, Sakit, dan Alpa dihitung terpisah; pertemuan dengan absensi belum dicatat ditandai sebagai kekurangan data, bukan Alpa. **[JANGAN DIUBAH]**
- Bagian Pendidikan memverifikasi dan menerbitkan rapor. Orang tua dapat melihat serta mengunduh versi terbit melalui web dan aplikasi; Bagian Pendidikan dapat mencetaknya. **[JANGAN DIUBAH]**
- PDF memuat tempat/tanggal, gambar tanda tangan dan identitas Bagian Pendidikan serta pimpinan lembaga, berikut QR/kode yang memeriksa keaslian/status rapor di website. Tidak ada klaim tanda tangan sertifikat kriptografis. **[JANGAN DIUBAH]**
- Orang tua mendapat notifikasi dalam aplikasi dan push dengan teks generik saat rapor diterbitkan; payload tidak berisi nama santri, nilai, NIS, atau gambar tanda tangan. **[JANGAN DIUBAH]**

### 5.2 Fondasi dan kompatibilitas

- Web tetap menggunakan PHP/MySQL yang sudah berjalan di cPanel; aplikasi tetap React Native/Expo. Tidak memperkenalkan Laravel atau sistem login baru.
- Role dasar tetap `admin`, `guru`, `pengurus`, `orang_tua`. Resolver `Capabilities` dan penugasan pusat yang sudah ada adalah sumber otorisasi; menu aplikasi hanya membantu navigasi, bukan memberi hak.
- `tahun_ajaran` sudah memuat pasangan tahun dan semester; gunakan ID baris itu sebagai referensi semester, bukan menyimpan teks semester kedua yang dapat berbeda.
- Gunakan `mata_pelajaran`, `guru_mapel_assignments`, `pendidikan_assignments`, `pembimbing_assignments`, `murobi_assignments`, `plotting_kelas`, `pertemuan_peserta`, dan `absensi_santri` yang ada. Tabel warisan `mapel` tidak otomatis dipakai sebagai sumber V4.
- Penugasan yang aktif pada tanggal tindakan, cakupan kelas/mapel/jenjang dan relasi wali aktif diperiksa ulang oleh server setiap kali membaca atau menulis. Hak admin sebagai pengawasan/pengganti dicatat dengan kapasitasnya.
- Tambahkan kemampuan V4 secara aditif ke profil/API. Akun, mode, jadwal, absensi, perizinan, dan pembinaan V1–V3 tetap kompatibel.
- Baseline V3 Fase 5 diterima Human Developer dengan keterbatasan yang dicatat di `docs/phase-v3-5/status-penerimaan.md` dan `docs/phase-v3-5/risiko-tertunda.md`. Penerimaan itu bukan bukti bahwa uji V4 telah lulus; residu database uji lama tidak boleh dibersihkan sembarangan agar verifier tampak lulus.
- Codex adalah implementator V4 dan Claude Code adalah auditor V4 berdasarkan keputusan Human Developer 16 Oktober 2026. Masing-masing mengerjakan satu fase secara bergantian sesuai `AGENTS.md`.
- Implementasi memakai branch fitur dari baseline yang diverifikasi, bukan langsung pada `main`; satu fase dikerjakan dan diaudit secara bergantian sesuai `AGENTS.md`.

### 5.3 Data minimum dan aturan hitung

- **Rencana kelas/semester:** tahun ajaran, kelas, mata pelajaran wajib, target ketuntasan, bobot yang disetujui, status, dan jejak perubahan. Bagian Pendidikan mengelola rencana dalam cakupannya; admin dapat mengawasi.
- **Nilai:** santri, tahun ajaran, kelas, mata pelajaran, komponen, nilai numerik, catatan, versi, pelaku, kapasitas pelaku (`guru`/`pendidikan_pengganti`/`admin`), alasan koreksi/pengganti, dan waktu. Riwayat koreksi tidak ditimpa.
- **Sikap:** santri, tahun ajaran, tiga aspek dan predikatnya, catatan pembimbing, identitas pembimbing, tanda diketahui murobi, revisi/koreksi murobi, alasan, versi, dan audit.
- **Kehadiran:** sumber pertemuan dan peserta, lima hitungan status, jumlah pertemuan yang berhak dihitung, jumlah absensi belum tercatat, serta waktu snapshot. Hanya pertemuan berstatus `Selesai` dengan peserta snapshot untuk semester itu masuk rekap.
- **Rapor:** satu identitas bisnis per santri dan semester, versi terbit, snapshot semua baris nilai/predikat/target, sikap, kehadiran, catatan umum, kelas, identitas penerbit/penanda tangan, tempat/tanggal, status (`Draf`, `Perlu Perbaikan`, `Terverifikasi`, `Terbit`, `Diganti`/`Ditarik`), waktu, alasan, dan hash berkas kanonis. Nama status persis boleh disesuaikan dengan pola kode yang ada tanpa mengubah alur.
- **Aset tanda tangan:** pemilik/jabatan, berkas gambar privat, hash, siapa mengunggah/mengaktifkan, masa berlaku, dan riwayat pemakaian. Admin mengelola gambar atas persetujuan penanda tangan di luar sistem; rapor yang sudah terbit menyimpan rujukan/hasil gambar versi saat terbit.
- **Verifikasi:** kode acak tak mudah ditebak, versi, status, hash PDF kanonis, waktu terbit/diganti/ditarik, serta audit akses secukupnya tanpa nilai. Pemindaian QR sendiri menunjukkan status penerbitan; untuk membuktikan kecocokan *berkas tertentu*, halaman verifikasi membandingkan hash PDF yang diunggah sementara tanpa menyimpan berkasnya.
- **Notifikasi:** event penerbitan, penerima wali yang berhak, kanal InApp/Push, deduplication key, status/receipt/retry, dan payload generik. Semua wali berakun dengan relasi aktif menerima notifikasi saat terbit.

Rumus deterministik untuk satu mata pelajaran:

```text
H = rata-rata aritmetika seluruh nilai harian/praktik yang sah
N = (H × 0,40) + (nilai tengah semester × 0,30) + (nilai akhir semester × 0,30)
nilai tampil = pembulatan N ke bilangan bulat terdekat
```

Nilai antara dipertahankan dengan presisi minimal dua desimal. Pembulatan tepat pada pecahan 0,5 ke atas (`half-up`), predikat diturunkan dari nilai tampil, dan rata-rata rapor dihitung dari nilai akhir sebelum pembulatan tampilan. Contoh yang telah disetujui: harian 80 dan 90 → H = 85; tengah semester 78; akhir semester 92 → N = 85,00, nilai tampil 85 (predikat B). Sikap, kehadiran, dan catatan V3 tidak ikut rumus nilai akademik.

Jika santri berpindah kelas di tengah semester sehingga rencana mapel atau penilaiannya ambigu, finalisasi ditahan untuk penyelesaian eksplisit oleh Bagian Pendidikan; sistem tidak memilih kelas atau menggabungkan dua rapor secara otomatis.

### 5.4 Hak akses dan alur status

| Pelaku | Hak V4 | Pembatas utama |
| --- | --- | --- |
| Guru pengampu | Lihat/input/koreksi/kirim nilai sendiri | Penugasan aktif pada pasangan mapel–kelas–semester; koreksi beralasan dan dibatasi status |
| Pembimbing | Input/kirim sikap | Santri dalam cakupan penugasan pembimbing aktif |
| Murobi | Lihat/ketahui/koreksi sikap | Santri dalam cakupan murobi aktif; koreksi beralasan dan berversikan |
| Bagian Pendidikan | Susun rencana, verifikasi/kembalikan, input nilai pengganti, finalisasi, terbitkan, cetak | Cakupan jenjang/unit dan semester penugasan; input pengganti perlu alasan |
| Orang tua | Lihat/unduh rapor terbit | Relasi `santri_wali` aktif pada setiap daftar/detail/unduh; tidak melihat draf atau nilai internal |
| Admin | Pantau/koreksi administratif | Semua tindakan pengganti/koreksi diaudit menurut kapasitas dan alasan |
| Pengunjung kode verifikasi | Lihat status keaslian/versi saja | Tidak dapat memperoleh identitas santri, mata pelajaran, nilai, sikap, atau PDF |

- Murobi boleh menyatakan telah mengetahui tanpa mengubah isi. Koreksi menghasilkan versi penilaian sikap baru dan alasan, tidak menghapus masukan pembimbing.
- Penilaian sikap harus diisi pembimbing lalu ditandai diketahui **atau** dikoreksi beralasan oleh murobi sebelum finalisasi. Jika tidak ada murobi aktif yang sah, Bagian Pendidikan melihat penghalang dan meminta admin memperbaiki penugasan; tidak mengarang persetujuan.
- Bagian Pendidikan dapat mengembalikan nilai/sikap yang belum benar kepada pengisi semula. Perubahan setelah verifikasi membatalkan status verifikasi terkait dan memerlukan pemeriksaan ulang.
- Pembukaan kembali rapor final/terbit dan penarikan membutuhkan alasan, pelaku, waktu, versi lama/baru, serta audit; rapor yang sudah diunduh tidak berubah diam-diam.
- Setelah rapor diganti atau ditarik, wali tetap melihat status bahwa pernah ada penerbitan, tetapi hanya versi yang masih sah ditawarkan sebagai unduhan baru.
- Semua mutasi memeriksa CSRF pada web, autentikasi token pada API, cakupan server, validasi input, idempotensi untuk terbit, dan optimistic version untuk koreksi/finalisasi; request ulang tidak membuat dua nilai, dua rapor, atau dua notifikasi.

### 5.5 Privasi, dokumen, dan operasional

- PDF kanonis dibuat sekali per versi terbit dan disimpan privat; unduh harus melalui otorisasi. Gambar tanda tangan hanya disajikan di dokumen terotorisasi, tidak lewat URL publik.
- Halaman verifikasi publik dibatasi laju permintaannya dan hanya mengembalikan status dokumen, versi, tanggal terbit, serta hasil cocok/tidak cocok untuk PDF yang diperiksa; tidak menampilkan data pribadi/akademik.
- Informasi yang dikirim ke push sebatas pesan generik dan penunjuk rapor. Saat diketuk, aplikasi meminta login dan memeriksa relasi wali ulang sebelum memuat detail.
- Outbox, retry, receipt, dan sakelar Push yang sudah ada dipakai kembali. Saat Push OFF tidak ada request provider; WhatsApp OFF selalu nol request.
- Migrasi MySQL harus aditif dan berversi setelah migrasi terakhir yang benar-benar ada saat implementasi (pada saat PRD ditulis: `019`). Sertakan preflight, manifest, backup, verifier, rollback aman, serta latihan migrasi/rollback pada salinan data.
- Audit mencatat pelaku, kapasitas, tindakan, waktu, versi, alasan yang wajib, dan perubahan nilai menurut kebijakan privasi. Jangan mencatat credential, token QR, gambar tanda tangan, seluruh isi PDF, atau isi catatan sensitif di log umum.
- Website dan aplikasi memakai Bahasa Indonesia, keadaan memuat/kosong/gagal yang jelas, serta tetap dapat dipakai pada lebar 375 px dan ukuran teks besar.

## 6. Persyaratan Bertahap

### Fase 1: Rencana Nilai dan Fondasi Akses V4

**Tujuan:** Admin/Bagian Pendidikan dapat menyiapkan rencana mata pelajaran wajib, target ketuntasan, dan penanda tangan pada semester uji melalui website; data dapat dibaca ulang sebelum pengisian nilai dimulai.

**Persyaratan:**

1. Verifikasi pembagian Codex sebagai implementator dan Claude Code sebagai auditor di `AGENTS.md`; baca status/risiko V3 dan baseline kedua repositori sebelum kerja kode.
2. Inventarisasi semester, kelas, penempatan, master mapel, penugasan, absensi, audit, notifikasi, dan data nilai/rapor lama bila ada; jangan menebak backfill.
3. Buat migrasi aditif untuk rencana kelas–mapel–semester, nilai/riwayat, sikap/riwayat, snapshot rapor/versi, kode verifikasi, serta rujukan aset tanda tangan yang diperlukan.
4. Tambahkan pemeriksaan capability V4 di server dengan fondasi penugasan yang ada; jalur pengganti Bagian Pendidikan harus memiliki hak tindakan khusus dan alasan wajib.
5. Sediakan halaman pengaturan rencana semester dan penanda tangan sesuai cakupan serta endpoint baca yang kompatibel dengan aplikasi lama.
6. Cegah mata pelajaran wajib ganda pada kelas/semester, target di luar 0–100, bobot tidak berjumlah 100%, dan penggunaan aset tanda tangan yang tidak aktif.
7. Sediakan preflight, verifier, panduan rollback, dan pengujian skema pada MySQL/MariaDB uji tanpa mengubah data bisnis V1–V3.

**Kriteria penerimaan:**

- [ ] Admin/Bagian Pendidikan berwenang menyimpan satu rencana kelas–semester berisi dua mapel wajib, membacanya ulang melalui web dan API, serta mengubah target ketuntasan sebelum ada nilai.
- [ ] Rencana mapel yang sama dua kali untuk kelas/semester ditolak tanpa membuat baris tambahan.
- [ ] Pengurus tanpa penugasan Pendidikan aktif, guru tanpa penugasan mapel yang sesuai, dan wali ditolak `403` pada endpoint pengaturan.
- [ ] Migrasi ulang pada database uji tidak menambah tabel/kolom duplikat; verifier menemukan `0` referensi yatim baru dan jumlah baris V1–V3 tetap.
- [ ] Halaman rencana semester dapat dibuka dan diisi pada viewport 375 px; perubahan tercatat pada audit tanpa token atau gambar tanda tangan mentah.

### Fase 2: Pengisian Nilai Guru dan Pengganti Pendidikan (memerlukan Fase 1)

**Tujuan:** Guru pengampu dan Bagian Pendidikan pengganti dapat mencatat, menghitung, mengoreksi, serta menyerahkan nilai mata pelajaran melalui website/API dengan jejak pelaku yang jelas.

**Persyaratan:**

1. Sediakan daftar santri menurut penempatan kelas/semester dan rencana mapel yang sah; nilai lama tidak berpindah kelas mengikuti penempatan saat ini.
2. Guru mencatat satu atau lebih nilai harian/praktik, satu nilai tengah, satu nilai akhir, dan catatan capaian.
3. Bagian Pendidikan mengisi lewat tindakan pengganti yang meminta alasan, mencatat identitas/cakupan, dan tidak mengubah riwayat penugasan guru.
4. Hitung nilai akhir, predikat, target ketuntasan, serta rata-rata secara deterministik; bedakan nilai kosong dari angka 0.
5. Simpan draf, serahkan, dan koreksi beralasan dengan optimistic version dan audit. Revisi tidak menimpa nilai sebelumnya.
6. Batasi satu nilai komponen yang berlaku per santri–mapel–kelas–semester–jenis komponen/urutan; retry dan klik ganda tidak membuat duplikasi.
7. Tambahkan formulir web dan endpoint API yang aman terhadap CSRF, IDOR, XSS, SQL injection, input di luar skala, dan konflik bersamaan.

**Kriteria penerimaan:**

- [ ] Dua nilai harian 80/90, nilai tengah 78, dan nilai akhir 92 menghasilkan nilai akhir 85 serta predikat B pada web dan API.
- [ ] Salah satu komponen kosong menghasilkan status Belum lengkap; nilai 0 yang benar-benar diinput tetap tersimpan sebagai 0.
- [ ] Guru di luar mapel/kelas/semester mendapat `403`; Bagian Pendidikan di luar jenjang/unit mendapat `403`.
- [ ] Input pengganti tanpa alasan ditolak `422`; input beralasan menyimpan pelaku dan kapasitas `pendidikan_pengganti` di riwayat/audit.
- [ ] Dua request identik atau bersamaan tidak menghasilkan nilai berlaku ganda; versi usang mendapat konflik `409` tanpa kehilangan revisi lama.

### Fase 3: Sikap, Kehadiran, dan Verifikasi (memerlukan Fase 2)

**Tujuan:** Pembimbing, murobi, dan Bagian Pendidikan melengkapi serta memeriksa seluruh bahan rapor melalui website/API.

**Persyaratan:**

1. Pembimbing mengisi tiga aspek sikap dan catatan untuk santri dalam cakupannya; murobi terkait dapat membaca, mengetahui, dan mengoreksi beralasan tanpa menghapus riwayat pembimbing.
2. Rekap kehadiran membaca peserta snapshot dan absensi santri pada pertemuan pengajian semester terkait; hitung lima status terpisah dan daftar absensi yang belum dicatat.
3. Bagian Pendidikan melihat matriks kelengkapan setiap santri: mapel wajib, komponen nilai, sikap, pengetahuan murobi, dan absensi.
4. Sediakan alur `Draf → Diserahkan → Perlu Perbaikan/Terverifikasi` untuk setiap bahan, dengan alasan saat pengembalian/koreksi.
5. Perubahan setelah verifikasi memerlukan verifikasi ulang. Finalisasi ditolak jika ada mapel wajib atau data sikap/kehadiran yang belum lengkap.
6. Penugasan dan relasi semester diperiksa ulang pada setiap mutasi; perubahan cakupan tidak mengubah identitas pengisi historis.

**Kriteria penerimaan:**

- [ ] Pembimbing dalam cakupan menyimpan tiga predikat sikap dan catatan; pembimbing lain mendapat `403` pada baca dan mutasi.
- [ ] Murobi terkait dapat menandai diketahui satu kali dan mengoreksi dengan alasan; murobi lain mendapat `403`, sedangkan versi lama tetap dapat ditelusuri oleh petugas berwenang.
- [ ] Satu pertemuan tanpa absensi santri menambah penghitung Belum dicatat tepat 1, tidak menambah Alpa, dan memblokir finalisasi sampai diselesaikan.
- [ ] Lima status absensi yang masing-masing dicatat satu kali menghasilkan lima penghitung bernilai 1 tanpa menggandakan pertemuan.
- [ ] Satu nilai mapel atau sikap yang dikoreksi setelah verifikasi mengembalikan status bahan menjadi perlu verifikasi; percobaan finalisasi sebelum verifikasi ulang ditolak tanpa rapor terbit.

### Fase 4: Rapor, Tanda Tangan, QR, dan Publikasi (memerlukan Fase 3)

**Tujuan:** Bagian Pendidikan dapat menerbitkan rapor versi tetap yang dapat dicetak/diunduh, sementara wali hanya membaca rapor miliknya dan pengunjung dapat memeriksa keaslian secara terbatas.

**Persyaratan:**

1. Bangun pratinjau sebelum finalisasi yang memperlihatkan mapel, nilai/predikat/target, sikap, kehadiran, catatan umum, serta identitas penanda tangan.
2. Buat snapshot dan PDF kanonis sekali per versi dalam transaksi penerbitan yang terhubung dengan audit dan outbox; kegagalan bagian wajib menggulung dampak bisnis atau meninggalkan status aman yang dapat dipulihkan.
3. Tampilkan tempat/tanggal, gambar tanda tangan Bagian Pendidikan dan pimpinan lembaga, serta QR/kode verifikasi pada PDF cetak/unduh.
4. Simpan hash PDF kanonis; halaman verifikasi publik mengembalikan status/version tanpa data santri. Pemeriksaan berkas membandingkan hash sementara tanpa menyimpan unggahan.
5. Izinkan koreksi, penggantian versi, atau penarikan beralasan dengan riwayat; versi yang sudah terbit tidak ditimpa.
6. Buat halaman daftar/detail/unduh wali dengan pengecekan `santri_wali` aktif pada setiap request, termasuk request langsung dengan ID yang ditebak.
7. Saat penerbitan, buat satu notifikasi InApp dan satu outbox Push per wali berakun yang berhak sesuai sakelar; gunakan pesan generik, deduplikasi, retry, dan otorisasi ulang setelah push diketuk.
8. Sediakan cetak/PDF untuk Bagian Pendidikan sesuai cakupan serta admin pengawas; respons privat memakai header tanpa cache publik.

**Kriteria penerimaan:**

- [ ] Rapor dengan satu mapel wajib/kehadiran/sikap belum lengkap tidak dapat diterbitkan dan menghasilkan `0` PDF terbit serta `0` notifikasi.
- [ ] Satu penerbitan sah menghasilkan tepat satu versi, satu PDF kanonis, satu QR/kode, dan satu notifikasi InApp per wali berhak; retry tidak menduplikasi.
- [ ] Unduhan berulang atas versi yang sama menghasilkan hash PDF sama; mengubah satu byte PDF uji membuat pemeriksaan berkas tidak cocok.
- [ ] Kode verifikasi sah menunjukkan status/versi, sedangkan kode acak/tidak sah tidak membuka identitas atau nilai; uji penebakan dibatasi laju permintaan.
- [ ] Wali A hanya melihat rapor santri dengan relasi aktif; wali B, relasi yang dicabut, dan pengguna tanpa akun ditolak pada detail/PDF.
- [ ] Notifikasi Push hanya memuat pesan generik dan penunjuk; Push OFF serta WhatsApp OFF menghasilkan `0` request provider.
- [ ] Penarikan/penggantian versi menyimpan alasan dan riwayat, menandai kode lama sesuai statusnya, serta tidak mengubah PDF lama.

### Fase 5: Aplikasi, Regresi, dan Kesiapan Produksi (memerlukan Fase 4)

**Tujuan:** Alur V4 tersedia pada aplikasi dan terbukti konsisten dengan website sebelum rilis bertahap ke produksi.

**Persyaratan:**

1. Tambahkan menu mobile menurut feature capability tanpa mode login baru; server tetap memutuskan akses.
2. Guru mengisi/meninjau nilai mapelnya, pembimbing mengisi sikap, murobi mengetahui/mengoreksi sikap, dan Bagian Pendidikan memverifikasi atau mengisi pengganti sesuai hak yang sama dengan web. Paritas seluruh tindakan operasional itu tersedia di aplikasi V4.
3. Orang tua membaca serta mengunduh PDF rapor terbit lewat aplikasi, dengan otorisasi ulang pada deep-link notifikasi.
4. Sediakan loading, daftar kosong, validasi, retry, timeout, token kedaluwarsa, akses ditolak, dan konflik versi yang dapat dipahami.
5. Periksa bahwa nilai, catatan sikap, PDF, gambar tanda tangan, dan token verifikasi tidak tersimpan di log, analytics, clipboard otomatis, atau penyimpanan perangkat yang tidak perlu.
6. Jalankan uji MySQL/MariaDB, browser desktop/375 px, aplikasi iOS/Android, regresi V1–V3, keamanan akses silang, klik ganda, kegagalan transaksi, dokumen cetak/PDF, dan notifikasi dengan sakelar kanal.
7. Siapkan manifest migrasi, backup/restore point, hash commit rilis, rollback, cron/worker, serta panduan smoke test cPanel. Catat bukti fisik/produksi yang benar-benar terjadi dan sisanya sebagai tertunda.
8. Minta auditor yang telah ditetapkan di `AGENTS.md` menilai setiap fase; merge ke `main` hanya setelah kriteria wajib fase itu terpenuhi menurut bukti dan keputusan Human Developer.

**Kriteria penerimaan:**

- [ ] Nilai/sikap uji yang dibuat di aplikasi terlihat identik pada website; koreksi versi usang menghasilkan `409` dan tidak menimpa nilai baru.
- [ ] Guru, pembimbing, murobi, Bagian Pendidikan, admin, dan wali yang tidak sesuai cakupan mendapat `403`/hasil kosong pada web dan aplikasi untuk skenario akses silang.
- [ ] Wali membuka notifikasi generik lalu login, melihat rapor miliknya, dan mengunduh PDF dengan hash sama seperti unduhan web; wali lain ditolak.
- [ ] Rangkaian otomatis V1–V4 lulus pada MySQL/MariaDB uji; browser empat peran utama lulus pada desktop dan 375 px; hasil iOS/Android dicatat sesuai perangkat yang benar-benar dipakai.
- [ ] Audit dan inspeksi log/payload uji menemukan `0` credential, nilai, nama santri, atau gambar tanda tangan dalam notifikasi push dan log umum.
- [ ] Manifest, backup terverifikasi, migrasi/verifier, rollback, pengaturan worker, dan hasil smoke produksi tersedia sebelum rilis; tidak ada klaim produksi lulus sebelum dijalankan.
- [ ] Auditor V4 yang ditunjuk menyatakan kriteria wajib terpenuhi berdasarkan bukti; branch fitur bersih dan baru boleh di-merge sesuai `AGENTS.md`.

## 7. Metrik Keberhasilan

- `0` nilai komponen berlaku ganda untuk kombinasi santri–kelas–mapel–semester–komponen/urutan pada uji klik ganda dan konkurensi.
- `100%` rapor terbit memiliki seluruh mapel wajib terverifikasi, tiga aspek sikap, dan rekap kehadiran tanpa status Belum dicatat.
- `0` penerbitan PDF/notifikasi saat salah satu bahan wajib belum lengkap atau transaksi audit/outbox gagal.
- `100%` nilai pengganti Bagian Pendidikan, koreksi murobi, pembukaan kembali, penarikan, dan penggantian versi mempunyai pelaku, kapasitas, alasan, waktu, serta jejak versi sebelumnya.
- `0` akses lintas guru/mapel/kelas, pembimbing, murobi, Bagian Pendidikan, dan wali pada pengujian web/API/aplikasi.
- `100%` unduhan berulang satu versi rapor menghasilkan hash PDF yang sama; berkas yang diubah tidak lolos verifikasi kecocokan.
- `0` nama santri, nilai, NIS, token verifikasi, atau gambar tanda tangan dalam payload push dan halaman verifikasi publik.
- `0` request provider saat Push OFF dan WhatsApp OFF; satu penerbitan tidak menggandakan notifikasi saat diulang.
- Rangkaian regresi V1–V3 tetap lulus sesuai baseline yang disepakati; kegagalan warisan pada database uji dilaporkan terpisah dengan asal dan tidak ditutupi oleh perubahan verifier.
- Pada uji penerimaan pengguna, sedikitnya 95% guru/pembimbing/Bagian Pendidikan/wali menyelesaikan tugas utamanya tanpa bantuan teknis.
