# V2 Fase 5 — Definisi Filter dan Ekspor

Dokumen ini adalah rujukan tunggal untuk arti setiap filter laporan dan setiap
kolom ekspor. Ia sengaja dijaga sinkron dengan kode oleh pengujian:
`tests/v2_phase5_static.php` menolak kolom CSV yang tidak terdokumentasi
**dan** dokumentasi untuk kolom yang tidak ada.

## 1. Prinsip: satu definisi untuk empat permukaan

Ringkasan, detail berhalaman, halaman cetak/PDF, dan ekspor CSV **wajib**
menghasilkan total yang sama untuk filter yang sama (PRD Fase 5 §5). Itu
dijamin secara struktural, bukan dengan kehati-hatian:

| Lapisan | Berkas | Jaminan |
| --- | --- | --- |
| Definisi filter | `app/Report/IzinReportFilter.php` | Objek **immutable** dengan konstruktor privat. Satu-satunya cara membangun kriteria laporan. |
| Klausa SQL | `app/Report/IzinReportRepository.php` | **Tepat satu** `conditions()` dan **tepat satu** `fromClause()`, dipakai `summary()`, `decisionDuration()`, `page()`, `allRows()`, dan `explain()`. |
| Orkestrasi | `app/Report/IzinReportService.php` | Cetak dan CSV sama-sama berangkat dari `document()`. |
| Bukti | `IzinReportFilter::criteriaKey()` | Sidik jari SHA-256 kriteria **tanpa** `page`/`per_page`. Keempat permukaan wajib menghasilkan nilai yang sama; diperiksa KL-1, KR-4c. |

Konsekuensi penting: **pagination tidak pernah mengubah himpunan baris**.
`page`/`per_page` sengaja dikeluarkan dari sidik jari, dan ekspor memakai
`allRows()` yang mengabaikan pagination sepenuhnya.

## 2. Filter yang tersedia

Seluruh nilai divalidasi ketat. Nilai yang tidak dikenal ditolak `422`, **tidak**
diabaikan diam-diam — filter yang diabaikan diam-diam adalah cara termudah
membuat total ringkasan dan detail berbeda.

| Parameter | Nilai | Arti |
| --- | --- | --- |
| `date_from`, `date_to` | `YYYY-MM-DD` | Rentang tanggal. `date_to` lebih awal dari `date_from` → `422`. Default: awal bulan berjalan s.d. hari ini. |
| `basis_tanggal` | `izin` (default), `pengajuan`, `keputusan` | Kolom yang dipakai rentang tanggal. `izin` = pengajuan yang rentang izinnya **bersinggungan** dengan filter (semantik sama dengan daftar perizinan Fase 1–2). `pengajuan` = `diajukan_pada`. `keputusan` = `diputus_pada`, sehingga hanya memuat pengajuan yang sudah diputus. |
| `status` | `Diajukan`, `Perlu Penetapan Admin`, `Disetujui`, `Ditolak`, `Dibatalkan` | Status pengajuan. |
| `santri_id` | bilangan bulat positif | Satu santri. |
| `pengurus_id` | bilangan bulat positif | Pengurus pengaju. Untuk cakupan pengurus, nilai selain miliknya → `403`. |
| `murobi_guru_id` | bilangan bulat positif | Murobi tujuan. Untuk cakupan murobi, nilai selain miliknya → `403`. |
| `kamar_id` | bilangan bulat positif | Santri terplot pada kamar tersebut untuk tahun ajaran pengajuan (atau tahun ajaran aktif bila pengajuan warisan tidak punya tahun). |
| `kelas_id` | bilangan bulat positif | Sama seperti kamar, dengan syarat plotting kelas berstatus `Aktif`. **Tidak boleh** dipakai bersamaan dengan `kamar_id` → `422`. |
| `tahun_ajaran_id` | bilangan bulat positif | Tahun ajaran pengajuan. |
| `durasi_min_jam` | 0–999999 | Durasi keputusan **minimal**, dalam jam. Hanya pengajuan yang punya waktu pengajuan **dan** waktu keputusan. |
| `durasi_maks_jam` | 0–999999 | Durasi keputusan **maksimal**. Lebih kecil dari minimum → `422`. |
| `kanal` | `InApp`, `Push`, `WhatsApp` | Pengajuan yang **pernah** memiliki baris notifikasi pada kanal tersebut. |
| `sumber` | `legacy`, `v2` | `legacy` = hasil migrasi V1 (`is_legacy = 1`); `v2` = pengajuan alur V2. |
| `q` | maks. 100 karakter | Pencarian pada nama santri, NIS, atau alasan izin. |
| `page`, `per_page` | `per_page` maks. **100** | Hanya mempengaruhi tampilan daftar. Batas dipaksa server, bukan dipercaya dari klien. |
| `mode` | `admin`, `pengurus`, `murobi`, `orang_tua` | **Preferensi tampilan, bukan hak akses.** Server hanya menerimanya bila akun benar-benar memiliki kemampuan tersebut; nilai lain diabaikan dan cakupan sah tetap dipakai. |

### Durasi keputusan

Durasi = `diputus_pada − diajukan_pada`. Pengajuan yang tidak memiliki salah
satu waktu tersebut **tidak pernah** ikut dihitung dan **tidak** dianggap nol.
Data warisan V1 tidak memiliki keduanya, sehingga tidak pernah mengarang durasi.

Ketika tidak ada satu pun keputusan yang dapat dihitung, median dilaporkan
sebagai `Tidak tersedia` — bukan `0 jam`.

### Median

Median dihitung dengan dua query `LIMIT 1 OFFSET n`, bukan window function.
Alasannya kompatibilitas: MySQL 5.7 yang masih umum pada cPanel tidak memiliki
`PERCENTILE_CONT` maupun `ROW_NUMBER()`. Untuk jumlah ganjil diambil nilai
tengah; untuk jumlah genap diambil rata-rata dua nilai tengah.

## 3. Ekspor CSV

| Sifat | Nilai |
| --- | --- |
| Encoding | UTF-8 dengan BOM (`EF BB BF`) agar Excel Windows membaca huruf beraksen dengan benar |
| Pemisah | koma |
| Cakupan baris | **SELURUH hasil filter sampai maksimum 20.000 baris**, bukan halaman yang sedang terlihat |
| Batas produk | `MAX_EXPORT_ROWS` = 20.000 baris per permintaan (keputusan pemilik produk 29 Agustus 2026) |
| Hasil di atas batas | Ditolak dengan HTTP `422` dan kode `EXPORT_TOO_LARGE`; pengguna diminta mempersempit filter dan tidak menerima CSV parsial |
| Header HTTP web | `text/csv`, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, `X-Laporan-Jumlah-Baris`, `X-Laporan-Kriteria` |
| Nama berkas | `laporan-perizinan-<cakupan>-<dari>-sd-<sampai>-<waktu>.csv` |

Batas 20.000 adalah kontrak produk, bukan pagination tersembunyi. Sampai batas
tersebut jumlah baris CSV wajib sama dengan ringkasan untuk filter yang sama.
Di atas batas, layanan wajib gagal eksplisit sebelum membuat berkas. Ekspor
streaming/chunking lebih dari 20.000 baris dapat dipertimbangkan pada fase
mendatang, tetapi bukan kriteria penerimaan Fase 5.

### Perlindungan formula injection (CWE-1236)

Sel yang diawali `=`, `+`, `-`, `@`, TAB (0x09), atau CR (0x0D) diberi awalan
kutip tunggal sehingga aplikasi spreadsheet menampilkannya sebagai **teks** dan
tidak pernah mengeksekusinya.

Pemeriksaan dilakukan pada karakter pertama **mentah** *dan* pada karakter
pertama setelah spasi/kontrol dibuang, karena beberapa versi Excel memangkas
spasi awal lebih dulu lalu mengevaluasi karakter berikutnya. Nilai `null`
menjadi string kosong, bukan teks `"null"`.

Diuji terhadap data sungguhan: fixture memuat santri bernama
`=HYPERLINK("http://jahat.example","klik")`, NIS berawalan `=CMD-`, dan alasan
izin berawalan `=SUM(1+1)`. Pengujian memindai **setiap sel** pada berkas hasil
dan mensyaratkan **nol** sel yang masih diawali karakter formula.

### Kolom CSV

| 1 | `ID Pengajuan` | ID pengajuan pada tabel `izin_pengajuan`. Untuk data warisan, ID ini sama dengan ID `perizinan` V1. |
| 2 | `Sumber Data` | `Data warisan` untuk baris hasil migrasi V1, `V2` untuk pengajuan yang dibuat alur V2. |
| 3 | `ID Perizinan Lama` | Nilai `perizinan.id` asal. Kosong untuk pengajuan V2 asli. |
| 4 | `NIS` | Nomor induk santri saat laporan dibuat. |
| 5 | `Nama Santri` | Nama santri pada master data saat laporan dibuat. |
| 6 | `Kamar` | Kamar santri pada tahun ajaran pengajuan; memakai tahun ajaran aktif bila pengajuan tidak memiliki tahun ajaran (data warisan). |
| 7 | `Kelas` | Kelas aktif santri pada tahun ajaran pengajuan; aturan tahun ajaran sama dengan kolom Kamar. |
| 8 | `Tahun Ajaran` | Tahun ajaran dan semester pengajuan. Kosong untuk data warisan. |
| 9 | `Tanggal Izin` | Tanggal mulai izin (YYYY-MM-DD). |
| 10 | `Tanggal Kembali` | Tanggal santri kembali (YYYY-MM-DD). |
| 11 | `Alasan Izin` | Alasan yang diisi pengurus saat mengajukan. |
| 12 | `Catatan Pengurus` | Catatan tambahan pengurus. Kosong bila tidak diisi. |
| 13 | `Status` | Salah satu dari: Diajukan, Perlu Penetapan Admin, Disetujui, Ditolak, Dibatalkan. |
| 14 | `Pengurus Pengaju` | Nama pengurus pengaju. `Data warisan` bila pengajuan berasal dari V1 dan pelakunya tidak tercatat. |
| 15 | `Murobi Tujuan` | Nama murobi tujuan routing. `Belum ditetapkan` bila menunggu penetapan admin. |
| 16 | `Jumlah Kandidat Routing` | Jumlah murobi kandidat saat routing dijalankan. 0 dan lebih dari 1 sama-sama masuk antrean admin. |
| 17 | `Catatan Routing` | Penjelasan singkat hasil routing yang disimpan sistem. |
| 18 | `Waktu Pengajuan` | Waktu pengajuan dikirim (YYYY-MM-DD HH:MM:SS). Kosong untuk data warisan. |
| 19 | `Hasil Keputusan` | `Disetujui` atau `Ditolak`. Kosong bila belum ada keputusan. |
| 20 | `Kapasitas Pemberi Keputusan` | `Murobi` atau `Admin Pengganti`. |
| 21 | `Pemberi Keputusan` | Nama akun pemberi keputusan. Kosong untuk data warisan yang pelakunya tidak tercatat. |
| 22 | `Alasan Keputusan` | Alasan yang diisi saat menyetujui atau menolak. |
| 23 | `Alasan Penggantian` | Alasan wajib ketika admin memutus menggantikan murobi. |
| 24 | `Waktu Keputusan` | Waktu keputusan dicatat (YYYY-MM-DD HH:MM:SS). |
| 25 | `Durasi Keputusan (jam)` | Selisih waktu pengajuan sampai waktu keputusan, dalam jam dengan dua desimal. Kosong bila salah satu waktu tidak tersedia. |
| 26 | `Jumlah Koreksi` | Berapa kali keputusan dikoreksi. Koreksi tidak menghapus riwayat sebelumnya. |
| 27 | `Waktu Koreksi Terakhir` | Waktu koreksi keputusan terakhir, bila ada. |
| 28 | `Alasan Pembatalan` | Alasan pembatalan oleh pengurus, bila pengajuan dibatalkan sebelum keputusan. |
| 29 | `Waktu Pembatalan` | Waktu pembatalan dicatat. |
| 30 | `Kanal Notifikasi` | Kanal notifikasi yang pernah diantrekan untuk pengajuan ini (InApp, Push, WhatsApp), dipisahkan koma. |

Menambah kolom **wajib** disertai penambahan pada `IzinCsvExport::DOKUMENTASI`
dan pada `IzinCsvExport::row()`; pengujian statis menolak bila salah satunya
tertinggal.

## 4. Halaman cetak / PDF

Halaman cetak dibangun `app/Report/IzinPrintRenderer.php` dan memuat seluruh
unsur yang diwajibkan kriteria penerimaan:

| Unsur wajib | Wujud |
| --- | --- |
| Identitas pesantren | Judul `Pesantren Al Hasan` pada kepala dokumen |
| Filter aktif | Blok `filter_aktif` — cakupan, basis tanggal + rentang, status, sumber, santri, pengurus, murobi, kamar, kelas, tahun ajaran, durasi, kanal, kata kunci (bernama, bukan sekadar ID, bila tersedia dalam cakupan) |
| Pembuat laporan | `Dibuat oleh: <nama akun>` |
| Waktu pembuatan | `Waktu pembuatan: YYYY-MM-DD HH:MM:SS <zona>` |
| Keputusan | Kolom hasil, kapasitas pemberi keputusan, waktu, dan alasan keputusan |
| Nomor halaman | `@page { @bottom-center }` untuk mesin Paged Media (dipakai `expo-print`/WebKit) **dan** elemen `.page-footer` dengan `counter(page)` untuk Chrome/Firefox |

Dokumen yang sama dipakai web dan aplikasi: aplikasi mengambil HTML dari
`/izin/laporan/cetak` lalu mengubahnya menjadi PDF dengan `expo-print`, sehingga
hasil cetak web dan PDF aplikasi identik untuk filter yang sama.

Seluruh nilai di-escape dengan `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE)`.
Halaman dikirim dengan `Cache-Control: private, no-store` karena memuat data
pribadi santri.

## 5. Ekspor pada aplikasi

| Tindakan | API Expo SDK 57 | Catatan |
| --- | --- | --- |
| Cetak | `Print.printAsync({ html })` | Pada web membuka dialog cetak peramban |
| Bagikan PDF | `Print.printToFileAsync` + `Sharing.shareAsync` | Pada web kembali ke dialog cetak (dari sana pengguna dapat memilih "Simpan sebagai PDF") |
| Bagikan CSV | `new File(Paths.cache, nama)` + `Sharing.shareAsync` | Ditulis ke direktori **cache aplikasi**, bukan penyimpanan bersama, sehingga data santri tidak dapat dibaca aplikasi lain. Menolak hasil yang `terpotong` dan berkas di atas 8 MB. |

Aplikasi **tidak** menyusun laporannya sendiri dan **tidak** menyaring baris di
sisi klien; seluruh angka berasal dari endpoint yang sama dengan website.
