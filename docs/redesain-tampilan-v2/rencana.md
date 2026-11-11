# Rencana redesain tampilan aplikasi alhasanApps (gelombang 2) — 7 November 2026

Status: **rencana disetujui Human Developer 12 November 2026** (seluruh usulan K-1…K-7 dan tambahan K-8, K-9, dan K-10, lihat §5). Belum ada perubahan kode, branch implementasi, commit kode, atau penetapan peran implementator/auditor. Persetujuan rencana bukan perintah memulai fase.

Sumber inspirasi: sepuluh lembar contoh desain "Academy Finance" (30 layar) yang dikirim Human Developer 7 November 2026. Contoh itu adalah aplikasi keuangan akademi berbahasa Inggris dengan dolar; rencana ini **mengambil bahasa visualnya**, bukan isi, istilah, atau fiturnya.

Dasar yang tetap berlaku: redesain gelombang 1 (`docs/redesain-tampilan/rencana.md`, lulus audit dan digabung 15 Oktober 2026) dan seluruh keputusan Human Developer di sana, kecuali yang secara eksplisit diusulkan untuk dibuka kembali di bagian **Keputusan yang diperlukan**.

## 1. Tujuan dan batas

- Aplikasi terasa lebih hangat, lapang, dan konsisten di semua modul (Jadwal, Absensi, Perizinan, Izin wali, Pembinaan, Rapor, Kesehatan, Keuangan/BSA, Keamanan, Notifikasi, Profil).
- **Hanya tampilan.** Tidak ada perubahan endpoint, hak akses, aturan bisnis, idempotensi, versi optimistik, atau perlindungan layar (`expo-screen-capture`). Menyembunyikan tombol bukan kontrol akses; server tetap memutuskan.
- Angka di layar hanya dari data yang sudah dikirim server. **Tidak ada angka karangan** seperti "↑ 12%" bila API tidak mengirim pembanding.
- Aturan aksesibilitas gelombang 1 tetap: ukuran teks ikut sistem tanpa batas, target sentuh ≥ 44 pt, status selalu ikon + teks (bukan warna saja), mode gelap wajib, versi web (`*.web.tsx`) ikut.

## 2. Bahasa visual yang diambil dari contoh

| Unsur contoh | Penerapan di alhasanApps |
| --- | --- |
| Latar krem hangat, kartu putih, bayangan sangat tipis | Latar `background` menjadi krem netral; kartu tetap `card` putih dengan garis tipis + bayangan lembut (tingkat `Elevation` yang ada) |
| Hijau hutan tua sebagai warna utama, tombol penuh membulat | Hijau tetap identitas Al Hasan; diusulkan sedikit lebih tua untuk kartu sorot dan tombol utama (lihat K-1). Tombol utama setinggi 52 pt, sudut pil |
| Aksen jingga/peach untuk pengeluaran, menunggu, peringatan | Dipetakan ke token `warning`/`warningSoft` yang ada (Menunggu, Perlu tindakan, Tunggakan, Penarikan) |
| Judul besar tebal + subjudul abu di layar akar | Layar akar tab dan layar menu modul memakai judul besar kiri + satu baris penjelas |
| Header bertingkat: ← kiri, judul tengah, ⋯ kanan | Layar stack (rincian, formulir) memakai judul tengah; ⋯ hanya bila ada lebih dari satu tindakan sekunder (cetak, bagikan, riwayat versi) |
| Kartu statistik 2 kolom: ikon dalam lingkaran, angka besar, label | Komponen baru **StatCard** untuk ringkasan modul (jumlah antrean, saldo, sisa tagihan, kunjungan) |
| Kartu sorot hijau tua dengan angka besar | Komponen **HeroCard** (pengembangan `HeroSession`): sesi berikutnya, saldo tabungan, total sisa tagihan |
| Kolom cari membulat + tombol penyaring kotak | Komponen **SearchBar** dengan tombol penyaring yang membuka lembar bawah (tanggal, status, unit) |
| Pil penyaring (terpilih hijau penuh, lainnya krem) | `Chip` yang ada diperluas menjadi **FilterPills** yang dapat digeser ke samping |
| Baris daftar: avatar/ikon bulat, judul, subjudul, nilai kanan, › | Komponen **EntityRow**: avatar inisial santri atau ikon jenis, nominal/status di kanan, chevron |
| Pil status lunak (Active, Pending, Overdue) | Komponen **StatusPill** dengan ikon + teks, warna dari token status |
| Profil: avatar besar tengah, nama, ID • kelas, pil status, strip 3 angka, tab segmen | Pola **profil entitas** untuk rincian santri (Keuangan, Kesehatan, Keamanan) dan Profil akun |
| Baris info ikon – label – nilai | Komponen **InfoRow** untuk semua layar rincian |
| Formulir: kolom bersudut besar dengan ikon depan | `AppField`/`DropdownField`/`DateField` mendapat ikon depan opsional dan tinggi 52 pt |
| Layar sukses: lingkaran centang besar + kartu kuitansi bergerigi | Komponen **SuccessPanel** + **ReceiptCard** sesudah transaksi BSA, kirim absensi, kirim izin |
| Grafik garis/batang/donat | Hanya **batang** berbasis `View` tanpa dependensi baru (lihat K-4). Pengembangan `StatusRecap` yang ada |
| Daftar pengaturan berkelompok dengan ikon | `ListRow`/`RowGroup` yang ada, diselaraskan warnanya |

### Yang tidak diambil

- **Daftar akun, masuk dengan Google/Apple/Microsoft, Remember me, Lupa password mandiri.** Akun dibuat admin, username ditetapkan admin, lupa password ditangani admin (PRD-PROFIL-IMPORT PI-1). Teks "Lupa password? Hubungi admin pesantren…" dipertahankan.
- **Onboarding tiga slide.** Pengguna adalah guru, pengurus, petugas, dan orang tua yang menerima akun dari pesantren; layar pembuka menambah satu ketukan tanpa manfaat (lihat K-6).
- **Kursus, langganan, gaji guru, laba bersih, pendapatan vs pengeluaran.** Tidak ada di aplikasi. Istilah BSA tetap: penerimaan kas baru, setoran, penarikan/belanja, perpindahan internal, tunggakan.
- **Two-factor, nonaktifkan akun, bahasa, cadangan data, hapus cache, sakelar mode gelap.** Tidak ada fiturnya; tema tetap mengikuti sistem.
- **Tombol + di tengah bilah bawah** (K-2). Tombol mengambang hanya dipakai untuk pengajuan izin, di kanan bawah (K-8).
- **Foto santri.** API tidak menyediakan foto santri; avatar santri memakai inisial (lihat K-5). Foto hanya untuk akun sendiri (Profil).
- Dolar → **Rupiah** dengan pemisah titik (`Rp 300.000`), tanggal format Indonesia (`Kamis, 5 Nov 2026`).

## 3. Fondasi (berlaku di semua halaman)

### 3.1 Token warna (usulan, menunggu K-1)

| Token | Sekarang (terang) | Usulan terang | Catatan |
| --- | --- | --- | --- |
| `background` | `#F4F7F4` | `#F6F4EE` krem | Latar layar |
| `backgroundElement` | `#E9EFEA` | `#EEEBE2` | Pil tidak terpilih, kolom cari |
| `card` | `#FFFFFF` | `#FFFFFF` | Tetap |
| `border` | `#DFE7E1` | `#E7E2D6` | Garis kartu hangat |
| `primary` | `#176B3A` | `#1B5E3F` | Tombol, pil terpilih; kontras teks putih tetap ≥ 7:1 |
| `heroSurface` | `#176B3A` | `#174D36` | Kartu sorot lebih tua seperti contoh |
| `heroAccent` | `#A8DCBB` | `#A8DCBB` | Tetap |
| `accentGold` (baru) | — | `#C9962B` | Hanya hiasan (garis/ikon logo), tidak membawa makna status |
| Status kehadiran, `warning`, `danger`, `info`, `violet` | — | **tidak berubah** | Sudah lolos uji buta warna gelombang 1 |

Mode gelap: latar tetap hijau sangat gelap (`#0D1711`), kartu `#132019`; hanya `heroSurface` dan `border` diselaraskan. Setiap pasangan teks/latar baru wajib diuji kontras (≥ 4,5:1 teks biasa, ≥ 3:1 ikon/teks besar) sebelum dipakai.

### 3.2 Tipografi, jarak, sudut

- Huruf tetap Plus Jakarta Sans (500/600/700/800). Skala: judul layar akar 28/800, judul stack 18/700, angka besar 32/800, angka kartu 22/800, isi 15/500, label kecil 13/600.
- Sudut: kartu 20 pt (`Radius.xxl`), kolom & pil 999/16, tombol pil. Jarak tepi layar 20 pt, antar-kartu 12 pt, antar-bagian 24 pt.
- Ikon tetap dari `AppIcon` (garis 24 pt). Ikon dalam lingkaran lunak 44 pt untuk StatCard/EntityRow.

### 3.3 Komponen baru/diubah

| Komponen | Status | Dipakai di |
| --- | --- | --- |
| `ScreenHeader` | diubah: varian `beranda` (logo "Alhasan apps" + lonceng, tanpa ←), `akar` (← ke Beranda, lalu judul besar kiri + subjudul) dan `stack` (← · judul tengah · ⋯); tombol ← wajib di semua varian kecuali `beranda` (K-10) | semua layar |
| `HeroCard` | baru (generalisasi `HeroSession`/`HeroPerizinan`) | Beranda, Keuangan, Kesehatan wali, Laporan |
| `StatCard` + `StatGrid` (2 kolom, turun ke 1 kolom bila teks > 1,35×) | baru | menu modul, laporan |
| `SearchBar` + tombol penyaring → lembar bawah | baru | semua daftar |
| `FilterPills` | perluasan `Chip` | semua daftar |
| `EntityRow` (avatar inisial/ikon, judul, subjudul, nilai, pil, ›) | baru, menggantikan kartu daftar yang berbeda-beda | daftar izin, santri, pembayaran, mutasi, kunjungan, kasus |
| `AvatarInisial` | baru (logika inisial dari `izin-card.tsx`) | semua baris santri |
| `StatusPill` | baru, ikon + teks | semua status |
| `InfoRow` (ikon · label · nilai, nilai boleh multi-baris) | baru | semua rincian |
| `ProfileHeader` (avatar besar, nama, NIS • kelas, pil status, strip 3 angka) | baru | rincian santri, Profil |
| `SegmentTabs` | perluasan `Segmented` | rincian santri, laporan |
| `SuccessPanel` + `ReceiptCard` (garis putus, kop Al Hasan) | baru | hasil transaksi BSA, kirim izin, kirim absensi |
| `BarChart` (batang `View`, label, nilai di atas batang terpilih) | baru, tanpa dependensi | laporan kehadiran, laporan BSA, laporan klinik |
| `ActionBar` (tombol tetap di bawah, di atas bilah tab) | diselaraskan | formulir, rincian bertindakan |
| `Fab` (tombol mengambang ikon + teks, kanan bawah, di atas bilah tab) | baru (K-8) | Perizinan, Izin melalui pembimbing |
| `MenuTile` | diselaraskan warna/sudut; tetap 3 kolom | Beranda, menu modul |
| `EmptyState`, `LoadingState`, galat | diselaraskan (ilustrasi ikon besar dalam lingkaran krem) | semua |

### 3.4 Pola halaman

Setiap halaman di bagian 4 memakai salah satu pola ini:

- **P-Akar** — layar tab Jadwal, Izin, Profil: baris kepala dengan tombol ← yang kembali ke tab Beranda (K-10), lalu judul besar + subjudul, isi bergulir. Beranda tidak memakai ← (K-9).
- **P-Menu** — menu modul: judul besar, HeroCard atau StatGrid ringkasan, lalu kisi `MenuTile` 3 kolom atau daftar `ListRow` berketerangan.
- **P-Daftar** — header stack, SearchBar + penyaring, FilterPills, ringkasan "N data", daftar `EntityRow`, paginasi "Muat lebih banyak"/Sebelumnya–Berikutnya yang ada.
- **P-Rincian** — header stack + ⋯, kartu kepala (ProfileHeader atau kartu jenis dengan StatusPill), kelompok `InfoRow`, bagian riwayat, ActionBar untuk tindakan yang diizinkan server.
- **P-Form** — header stack, kartu konteks (santri/tagihan terpilih) di atas, kolom berikon, ringkasan sebelum kirim (pola "Tinjau" yang sudah ada), tombol utama tetap di bawah.
- **P-Sukses** — SuccessPanel + ReceiptCard + tombol "Selesai" dan tindakan lanjutan (Bagikan/Buka kuitansi bila sudah ada).
- **P-Laporan** — header stack, FilterPills periode, HeroCard angka utama, StatGrid, BarChart, daftar rincian, Cetak/Bagikan.

## 4. Rencana per halaman

Kolom "Contoh" merujuk lembar contoh: L1 masuk, L2 dasbor, L3 siswa, L4 kursus, L5 pembayaran, L6 invoice, L7 guru, L8 profil/pengaturan, L9 pendapatan, L10 laporan.

### 4.1 Masuk dan akun

| Halaman (rute) | Pola · Contoh | Rencana |
| --- | --- | --- |
| Masuk (`login`) | khusus · L1 tengah | Latar krem dengan gelombang hijau pucat di bawah; logo Al Hasan yang sekarang (bukan topi wisuda contoh dan bukan logo teks K-9) di tengah; judul "Assalamu'alaikum" + "Masuk ke akun pesantren Anda"; kolom Username (ikon orang) dan Password (ikon gembok + mata); tombol pil "Masuk"; teks "Lupa password? Hubungi admin pesantren". Tanpa Remember me, sosial, daftar. Galat di kartu `dangerSoft` di atas tombol |
| Ganti password wajib (`ganti-password-wajib`) | P-Form · L8 kanan | Ikon gembok besar dalam lingkaran, judul "Buat password baru", penjelas password sementara; tiga kolom password bermata; tombol utama; "Keluar" sebagai tombol garis |
| Ganti password (`akun/ganti-password`) | P-Form · L8 kanan | Sama dengan di atas, header stack "Ganti password", keterangan sesi perangkat lain akan keluar di kartu `infoSoft` |
| Edit profil (`akun/edit-profil`) | P-Form · L3 kanan, L8 kiri | Avatar besar dengan lencana kamera (ubah/hapus foto lewat lembar bawah); kolom berikon dikelompokkan "Data diri", "Kontak", "Alamat"; isian yang tidak `dapat_diubah` tampil sebagai InfoRow terkunci berikon gembok; tombol tetap "Simpan perubahan" |

### 4.2 Tab utama

| Halaman | Pola · Contoh | Rencana |
| --- | --- | --- |
| Beranda (`(home)/index`) | khusus · L2 kiri | Kepala: **logo teks "Alhasan apps"** kiri (K-9), lonceng kanan, tanpa tombol kembali. **Kartu identitas** (foto/inisial, nama, peran, tanggal) tetap isi pertama. **HeroCard** hijau tua: sesi berikutnya + tombol "Buka absensi", atau untuk non-guru ringkasan antrean perizinan. **Ringkasan hari ini** StatGrid 2 kolom hanya dari data yang sudah dimuat Beranda (mis. sesi hari ini, sesi belum diabsen; lihat K-3). **Menu** `MenuTile` 3 kolom (keputusan gelombang 1). "Jadwal hari ini" berupa EntityRow jam-kiri |
| Jadwal (`(schedules)/schedules`) | P-Akar · L10 kanan (pil periode) | ← ke Beranda; judul "Jadwal" + "Sesi mengajar pekan ini"; pil Per pekan / Rentang kustom; `WeekStrip` dengan sel tanggal terpilih hijau penuh; kartu sesi: blok jam di kiri, kitab + kelas (semua kelas gabungan), StatusPill, penanda "Absen" hari ini |
| Perizinan (`(izin)/perizinan`) | P-Akar + P-Daftar · L5 kiri | ← ke Beranda; judul "Perizinan" + penjelas cakupan; SearchBar; FilterPills Perlu tindakan · N / Semua / Disetujui / Ditolak; EntityRow: avatar inisial, nama + kamar/kelas, rentang tanggal, StatusPill, petunjuk "Tinjau & putuskan"; **FAB "+ Ajukan izin" di kanan bawah, di atas bilah tab** (K-8), dengan syarat tampil yang sama seperti tombol sekarang |
| Profil (`(profil)/profil`) | P-Akar · L8 kiri | ← ke Beranda; judul "Profil"; ProfileHeader: foto besar + lencana kamera, nama, username, pil peran; strip 3 angka hanya bila ada datanya (jumlah penugasan aktif, mapel, kelas). Kelompok baris: Data diri › (membuka rincian), Penugasan & identitas, Akun (Ganti password, Cakupan perizinan), Pemberitahuan (Perangkat & push, Riwayat notifikasi), Tampilan (Tema: ikut sistem, Ukuran teks), Tentang (versi). Tombol garis merah "Keluar" |

### 4.3 Pengajian: jadwal, absensi, laporan

| Halaman | Pola · Contoh | Rencana |
| --- | --- | --- |
| Rincian tugas (`schedule/[id]`) | P-Rincian · L4 tengah | Kartu kepala hijau pucat: kitab, jam, StatusPill; InfoRow Guru, Kelas (chip per kelas gabungan), Tempat, Tahun ajaran; kolom catatan pertemuan; tombol utama "Buka pertemuan" |
| Absensi pertemuan (`meeting/[id]`) | P-Daftar + P-Form · L3 kiri | Kepala ringkas (kitab, tanggal, kelas) + StatGrid mini Hadir/Izin/Sakit/Alpa yang ikut draf; SearchBar nama/NIS; FilterPills Semua / per kelas asal (PA/PI) / Kelas belum tercatat; baris santri: avatar inisial, nama, NIS · kelas, pemilih status 5 tombol ikon+huruf; "Semua hadir (N)" sebagai tombol garis; "Perbarui daftar" di ⋯; ActionBar "Kirim absensi" → P-Sukses ringkas |
| Laporan kehadiran (`reports`) | P-Laporan · L10 | Pil rentang (7 hari / 30 hari / Kustom) + penyaring jadwal; HeroCard "Persentase hadir" dari ringkasan server; BarChart rekap per status dengan legenda ikon; ringkasan per jadwal sebagai EntityRow; ⋯: Cetak PDF, Bagikan |
| Rincian laporan pertemuan (`report/[id]`) | P-Rincian · L6 tengah | Kepala pertemuan; StatGrid status; SearchBar + tab kelas asal; daftar santri dengan StatusPill |

### 4.4 Perizinan pengurus/murobi (V2)

| Halaman | Pola · Contoh | Rencana |
| --- | --- | --- |
| Buat izin (`izin/buat`) | P-Form langkah · L5 tengah | Langkah 1 pilih santri: SearchBar + EntityRow; langkah 2 kartu santri terpilih di atas (seperti "Ahmed Mohamed ›" di contoh), kolom rentang tanggal berikon kalender, alasan (penghitung karakter seperti "0/200"), catatan; Tinjau → Kirim → P-Sukses "Pengajuan terkirim" + "Lihat pengajuan" |
| Rincian izin (`izin/[id]`) | P-Rincian · L6 tengah | Kartu kepala: avatar, nama, kamar/kelas, StatusPill besar (seperti stempel "PAID" tetapi datar, ikon+teks); InfoRow Rentang, Alasan, Catatan, Pengaju, Murobi; garis waktu riwayat (titik + tanggal); ActionBar sesuai `aksi` server: Setujui/Tolak (membuka lembar beralasan), Tetapkan murobi, Batalkan |
| Cetak izin (`izin/laporan`) | P-Laporan · L6 kiri | SearchBar + penyaring tanggal; StatGrid total per status; EntityRow; tombol Cetak, Bagikan PDF, Bagikan CSV di ActionBar |

### 4.5 Izin melalui pembimbing (V7)

| Halaman | Pola · Contoh | Rencana |
| --- | --- | --- |
| Daftar (`izin-wali/index`) | P-Daftar · L6 kiri | Wali: pemilih anak sebagai pil (Semua anak / nama anak), EntityRow pengajuan dengan StatusPill tahap; pembimbing/admin: FilterPills Antrean / Riwayat; untuk wali FAB "+ Ajukan izin" kanan bawah seperti Perizinan (K-8) |
| Buat (`izin-wali/buat`) | P-Form · L5 tengah | Kartu anak terpilih, kolom tanggal & alasan berikon, Tinjau → Kirim → P-Sukses dengan keterangan "Menunggu tinjauan pembimbing" |
| Rincian (`izin-wali/[id]`) | P-Rincian · L6 tengah | Kepala + StatusPill tahap; **garis waktu tahap** Wali → Pembimbing → Murobi dengan titik terisi sesuai status; InfoRow; ActionBar sesuai peran (Teruskan / Minta perbaikan / Tolak, Tetapkan pembimbing, Batalkan) |
| Perbaiki (`izin-wali/perbaiki`) | P-Form | Kartu `warningSoft` berisi catatan pembimbing di atas; kolom terisi isi lama; "Kirim perbaikan" |

### 4.6 Pembinaan (V3)

| Halaman | Pola · Contoh | Rencana |
| --- | --- | --- |
| Menu (`pembinaan/index`) | P-Menu · L8 kiri | Judul + penjelas; daftar `ListRow` berikon dalam lingkaran: Pelanggaran & poin, Kasus & sesi konseling, Rekomendasi tindak lanjut, Informasi untuk keluarga; tombol "Catat pelanggaran"/"Buka kasus" sebagai tombol cepat 2 kolom (pola "Quick Actions" L2) |
| Daftar (`pembinaan/daftar`) | P-Daftar · L3 kiri | SearchBar, FilterPills jenis/status, EntityRow (avatar, nama, jenis/poin, StatusPill, pil kerahasiaan berikon gembok bila Internal) |
| Rincian (`pembinaan/detail`) | P-Rincian · L3 tengah | Kepala santri; SegmentTabs Ringkasan / Sesi / Riwayat; tindakan (Mulai pendampingan, Tambahkan sesi, Tandai mengetahui, Selesaikan) di ActionBar atau ⋯ sesuai jumlahnya |
| Catat (`pembinaan/buat`) | P-Form · L3 kanan | Pemilih santri, Dropdown jenis berikon, pilihan kerahasiaan sebagai kartu radio (pola "Subscription Options" L4) agar wajib-pilih jelas |
| Publikasi (`publikasi/[id]`) | P-Rincian | Kartu artikel: pengirim, tanggal, isi; "Tandai dibaca" |

### 4.7 Rapor (V4)

| Halaman | Pola · Contoh | Rencana |
| --- | --- | --- |
| Menu (`rapor/index`) | P-Menu | Orang tua: EntityRow rapor terbit per anak/semester (ikon dokumen hijau pucat seperti invoice L6). Petugas: `ListRow` Nilai mapel, Nilai pengganti, Sikap murobi/pembimbing, Verifikasi & penerbitan |
| Lihat rapor (`rapor/[id]`) | P-Rincian · L6 tengah | Kartu kop Al Hasan, identitas santri, ringkasan rata-rata (bila ada di respons), "Versi sebelumnya"; ActionBar "Unduh PDF rapor" |
| Nilai (`rapor/nilai`), Sikap (`rapor/sikap`) | P-Form · L7 kanan | Pemilih kelas/semester/mapel di kartu atas; daftar santri dengan kolom nilai ringkas per baris; ActionBar dua tombol "Simpan draf" (garis) + "Serahkan" (penuh); koreksi beralasan di lembar bawah |
| Verifikasi (`rapor/verifikasi`) | P-Rincian | Kartu status bahan per komponen dengan StatusPill; pratinjau; tindakan Terbitkan / Kembalikan / Tarik beralasan |

### 4.8 Kesehatan (V5)

| Halaman | Pola · Contoh | Rencana |
| --- | --- | --- |
| Menu (`kesehatan/index`) | P-Menu · L2 kiri | Petugas: StatGrid (kunjungan hari ini, rujukan menunggu hasil, tindak lanjut terbuka — hanya bila server sudah mengirim angka tersebut), tombol utama "Catat kunjungan", lalu `ListRow` Daftar kunjungan, Laporan klinik, Penugasan (admin) |
| Daftar kunjungan (`kesehatan/daftar`) | P-Daftar · L5 kiri | SearchBar + penyaring tanggal; FilterPills Semua / Rujukan menunggu / Tindak lanjut terbuka / Draf; EntityRow: avatar, nama, keluhan singkat, waktu, StatusPill |
| Catat (`kesehatan/catat`) | P-Form · L5 tengah | Kartu santri terpilih; kelompok Keluhan, Tindakan, Obat (baris berulang Jumlah/Satuan), Rujukan, Tindak lanjut; ActionBar Simpan draf / Simpan |
| Rincian kunjungan (`kesehatan/kunjungan/[id]`) | P-Rincian · L3 tengah | ProfileHeader santri; InfoRow waktu, keluhan, catatan klinis (penanda privat), petugas; bagian Ringkasan untuk wali dengan StatusPill Berlaku/Ditarik; ⋯: Riwayat versi, Riwayat akses |
| Hasil rujukan, Ringkasan wali, Versi, Akses (`hasil-rujukan`, `ringkasan`, `versi`, `akses`) | P-Form / P-Rincian | Kolom berikon, pratinjau ringkasan sebagai kartu "seperti dilihat orang tua"; riwayat versi/akses sebagai garis waktu |
| Laporan klinik (`kesehatan/laporan`) | P-Laporan · L10 | Pil periode; HeroCard jumlah kunjungan; StatGrid Rujukan, Dengan obat, Koreksi, Draf; BarChart per kelas; tanpa angka pembanding karangan |
| Penugasan (`kesehatan/penugasan`) | P-Daftar + P-Form | EntityRow petugas dengan StatusPill Aktif/Berakhir; tambah petugas di lembar bawah |
| Wali: daftar & rincian (`kesehatan/wali/*`) | P-Daftar / P-Rincian · L6 kanan | Pil anak; EntityRow dengan pil "Perlu perhatian"; rincian berupa kartu ringkasan dengan bagian "Penanganan di klinik" dan "Yang perlu diperhatikan orang tua" |

### 4.9 Keuangan santri / BSA (V6) — paling dekat dengan contoh

| Halaman | Pola · Contoh | Rencana |
| --- | --- | --- |
| Menu (`keuangan/index`) | P-Menu · L2 kiri, L9 kiri | **Petugas BSA:** StatGrid dari data yang sudah ada di respons (mis. transfer menunggu verifikasi, jumlah tunggakan); kisi `MenuTile` Santri, Verifikasi, Pembayaran, Mutasi tabungan, Tunggakan, Penerbitan, Rekonsiliasi, Laporan. **Admin:** sama, hanya baca. **Orang tua:** per anak HeroCard hijau "Saldo tabungan" + kartu "Sisa tagihan", lalu Riwayat pembayaran, Mutasi tabungan, Laporan anak |
| Daftar santri (`keuangan/santri/index`) | P-Daftar · L3 kiri | SearchBar; EntityRow: avatar, nama, NIS • kelas, saldo di kanan, pil "Tunggakan" bila ada |
| Rincian santri (`keuangan/santri/[id]`) | P-Rincian · L3 tengah | ProfileHeader: avatar, nama, NIS • kelas, StatusPill Aktif/Tidak aktif; strip 3 angka **Saldo · Sisa tagihan · Transfer menunggu**; SegmentTabs Ringkasan / Tagihan / Tabungan; tombol cepat Setoran, Penarikan/belanja, Terbitkan tagihan (sesuai hak) |
| Rincian tagihan (`keuangan/tagihan/[id]`) | P-Rincian · L6 tengah | Kartu gaya invoice: kop Al Hasan, periode, "Untuk: nama santri", tabel komponen (Keterangan · Nominal), Total / Dibayar / Sisa dengan baris sisa disorot; StatusPill Lunas/Sebagian/Belum dibayar; ActionBar "Catat pembayaran" + ⋯ Bayar dari tabungan, Revisi/batalkan |
| Catat pembayaran (`keuangan/bayar`) | P-Form · L5 tengah | Kartu tagihan terpilih (nama, periode, sisa) ›; Nominal dengan awalan "Rp"; pil Tunai/Transfer; Tanggal; Referensi (wajib untuk transfer); catatan dengan penghitung → P-Sukses |
| Bayar dari tabungan (`keuangan/bayar-tabungan`) | P-Form | Kartu dua kolom Saldo tabungan / Sisa tagihan (pola "Paid/Due" L3); nominal; persetujuan eksplisit; → P-Sukses |
| Setoran/penarikan (`keuangan/transaksi`, `keuangan-setoran`) | P-Form · L7 kanan | Kartu santri + saldo kini; pil Setoran/Penarikan/Belanja; nominal Rp; pil Tunai/Transfer; tujuan/referensi → P-Sukses (transfer: "Menunggu verifikasi", ikon jam jingga, bukan centang) |
| Verifikasi (`keuangan/verifikasi`) | P-Daftar · L5 kiri | FilterPills Setoran / Pembayaran; EntityRow nominal + referensi, pil "Menunggu"; rincian membuka tombol "Dana sudah diperiksa — verifikasi" / Tolak beralasan |
| Pembayaran: daftar & kuitansi (`keuangan/pembayaran/*`) | P-Daftar / P-Sukses · L6 kiri & kanan | Daftar gaya "Invoices & Receipts"; kuitansi: SuccessPanel bila Disahkan + ReceiptCard (No. kuitansi, santri, tagihan, cara, nominal, tanggal); cetak/PDF tetap dari website (keputusan T-1 V6) — tombol "Bagikan" tidak ditambahkan |
| Mutasi tabungan: daftar & rincian (`keuangan/tabungan/*`) | P-Daftar / P-Rincian · L5 kiri, L9 kanan | EntityRow: ikon setoran (hijau, panah masuk) / penarikan (jingga, panah keluar), nominal bertanda +/−, tanggal; pil "Sudah dikoreksi"; rincian dengan tautan transaksi asal, koreksi beralasan di lembar bawah |
| Tunggakan (`keuangan/tunggakan`) | P-Daftar | HeroCard "Total sisa"; FilterPills Semua / Lewat jatuh tempo + unit; EntityRow sisa di kanan warna `danger` dengan ikon |
| Penerbitan & terbit satu santri (`keuangan/penerbitan`, `terbit-santri`) | P-Form + pratinjau · L4 tengah | Periode + jatuh tempo; pratinjau sebagai StatGrid Akan terbit / Sudah ada / Dilewati + total; tombol "Terbitkan tagihan" → P-Sukses |
| Ubah tagihan (`keuangan/ubah-tagihan`) | P-Form | Pil Revisi / Batalkan; baris penyesuaian; alasan wajib |
| Rekonsiliasi (`keuangan/rekonsiliasi`) | P-Laporan · L9 tengah | Pemilih tanggal; kartu Awal hari → Akhir hari; StatGrid Penerimaan kas baru, Keluar, Mutasi bersih; daftar perpindahan internal dipisah dengan judul jelas |
| Laporan (`keuangan/laporan`) | P-Laporan · L9, L10 | Pil periode; HeroCard "Penerimaan kas baru" (bukan "pendapatan"); StatGrid Setoran, Pembayaran tunai/transfer, Penarikan & belanja, Koreksi; BarChart per bulan bila respons memuat seri; Saldo awal/akhir/selisih |

### 4.10 Keamanan (hanya baca)

| Halaman | Pola · Contoh | Rencana |
| --- | --- | --- |
| Menu (`keamanan/index`) | P-Menu | Judul + "Hanya melihat"; `ListRow` berikon: Perizinan, Pelanggaran, Konseling, Kehadiran pengajian |
| Empat daftar (`keamanan/*/index`) | P-Daftar | SearchBar nama/NIS + penyaring tanggal/status; EntityRow dengan StatusPill; tanpa tombol tindakan |
| Empat rincian (`keamanan/*/[id]`) | P-Rincian | Kepala + StatusPill; InfoRow lengkap (alasan, catatan, keputusan); garis waktu riwayat; kehadiran memakai tab kelas asal |

### 4.11 Notifikasi

| Halaman | Pola · Contoh | Rencana |
| --- | --- | --- |
| Daftar (`notifikasi/index`) | P-Daftar · L5 kiri | FilterPills Semua / Belum dibaca; EntityRow: ikon modul dalam lingkaran berwarna (izin, kesehatan, keuangan, rapor), judul generik, waktu relatif, titik belum dibaca; "Tandai semua dibaca" di ⋯ |
| Rincian (`notifikasi/[id]`) | P-Rincian | Kartu pesan + tombol "Buka …" sesuai tujuan |
| Perangkat (`notifikasi/perangkat`) | P-Rincian · L8 tengah | Kelompok baris dengan sakelar "Push di perangkat ini"; daftar perangkat dengan "Hapus" |

## 5. Keputusan Human Developer (12 November 2026)

Human Developer menyetujui seluruh usulan K-1…K-7 dan menambahkan K-8, K-9, dan K-10.

| No | Pertanyaan | Keputusan |
| --- | --- | --- |
| K-1 | Latar krem dan hijau lebih tua seperti contoh, atau palet sekarang? | Krem + hijau lebih tua (§3.1); warna status tidak berubah |
| K-2 | Bilah bawah buatan sendiri dengan tombol + di tengah? | Tidak. Tetap bilah sistem 4 tab (Liquid Glass/Material 3), tanpa tombol + di tengah bilah |
| K-3 | Ringkasan angka di Beranda? | Ya, **hanya** dari data yang sudah dimuat Beranda; tidak ada permintaan API baru; ubin menu tetap 3 kolom (keputusan gelombang 1 nomor 1 dan 5) |
| K-4 | Grafik garis/donat yang butuh `react-native-svg`? | Tidak. Hanya grafik batang berbasis `View`, tanpa dependensi baru |
| K-5 | Foto santri? | Tidak; avatar inisial. Foto santri di luar cakupan |
| K-6 | Layar pembuka (onboarding)? | Tidak dibuat |
| K-7 | Header stack judul tengah + ⋯? | Ya untuk layar stack; layar tab Jadwal/Izin/Profil tetap judul besar kiri, dengan ← di atasnya (K-10) |
| K-9 | Logo kepala aplikasi (tambahan Human Developer) | Logo teks "Alhasan apps" (huruf hijau gradasi lime → hijau, "apps" hijau di kanan bawah) disimpan di kepala halaman **Beranda saja**. Layar Masuk tidak diubah oleh K-9. Rincian di bawah |
| K-10 | Tombol kembali (tambahan Human Developer) | **Semua halaman selain Beranda memiliki tombol kembali**, termasuk tab Jadwal, Izin, dan Profil. Rincian di bawah |
| K-8 | Letak tombol pengajuan izin (tambahan Human Developer) | **FAB di kanan bawah, di atas bilah tab.** Alternatif yang juga diterima: tombol di atas, sebelum kotak pencarian. Rincian di bawah |

### K-8 — tombol "Ajukan izin" mengambang

K-8 mengubah keputusan gelombang 1 nomor 4 ("tanpa tombol + mengambang") **hanya untuk tombol pengajuan izin**: Perizinan (`(izin)/perizinan`) dan, agar seragam, daftar Izin melalui pembimbing untuk wali (`izin-wali/index`). Layar lain tetap tanpa FAB.

- **Bentuk:** tombol pil hijau `primary` setinggi 56 pt, ikon + dan teks "Ajukan izin" (bukan ikon saja), label aksesibilitas "Ajukan izin baru", bayangan `Elevation` tingkat tertinggi yang ada.
- **Letak:** kanan bawah, 16 pt dari tepi kanan ditambah area aman, 16 pt di atas bilah tab. Tinggi bilah tab diambil dari sistem, bukan angka tetap, karena bilah Liquid Glass iOS 26, Material 3 Android, dan bilah versi web berbeda tinggi.
- **Syarat tampil:** sama dengan tombol sekarang (berhak membuat pengajuan); orang tua di Perizinan V2 tetap tanpa tombol. Menyembunyikan tombol bukan kontrol akses.
- **Tidak menutupi isi:** daftar mendapat ruang kosong di bawah setinggi FAB + 16 pt sehingga kartu terakhir dan tombol paginasi tetap dapat diketuk. FAB disembunyikan saat papan ketik terbuka (kotak cari aktif).
- **Teks besar:** pada skala teks > 1,35× teks tetap tampil dan tombol boleh melebar; bila lebarnya melewati setengah layar atau menutupi kartu, pakai alternatif di bawah.
- **Alternatif yang disetujui:** tombol pil lebar penuh "Ajukan izin baru" di bawah judul, sebelum kotak pencarian. Implementator memakainya bila uji iOS, Android, atau web menunjukkan FAB bertabrakan dengan bilah tab atau menutupi isi yang tidak dapat diatasi dengan ruang bawah. Pilihan akhir dan buktinya dicatat dalam dokumen fase.

### K-9 — logo "Alhasan apps" di kepala Beranda

- **Letak:** hanya di kepala halaman Beranda, kiri atas, setinggi ±28 pt (lebar mengikuti rasio ±2,8 : 1); lonceng tetap di kanan. Kartu identitas tetap isi pertama sesudah kepala.
- **Tidak dipakai di layar lain.** Layar Masuk tetap memakai logo Al Hasan yang sekarang; layar tab memakai judul besar dan layar stack memakai judul tengah (K-7).
- **Aset:** PNG latar transparan dalam tiga kerapatan (1x/2x/3x) di `assets/images/brand/`, ditampilkan dengan `expo-image` yang sudah terpasang; tidak memakai SVG karena butuh dependensi baru (K-4). Berkas sumber berkualitas tinggi (PNG transparan lebar ≥ 2000 px, atau berkas vektor bila ada) disediakan Human Developer; yang dikirim lewat percakapan 12 November 2026 belum tersimpan di repositori.
- **Aksesibilitas:** gambar berlabel "Alhasan apps" dengan peran gambar; tidak ikut skala teks sistem.
- **Mode gelap:** logo yang sama di atas latar gelap. Di mode terang, bagian lime pada latar krem diperiksa keterbacaannya saat implementasi; warna logo **tidak diubah** tanpa persetujuan Human Developer.
- Ikon aplikasi dan layar splash **tidak termasuk** K-9 (butuh build native baru).

### K-10 — tombol kembali di semua halaman selain Beranda

- **Layar tab Jadwal, Izin, Profil:** tombol ← di kiri atas, di atas judul besar. Tombol ini membuka tab Beranda, karena layar tab tidak punya halaman sebelumnya. Bilah tab tetap ada.
- **Layar stack** (semua rincian, formulir, menu modul, laporan, notifikasi, akun): tombol ← di kiri kepala (varian `stack`, K-7). Kembali ke halaman sebelumnya.
- **Dibuka langsung tanpa riwayat** (dari ketukan notifikasi, tautan `alhasanapps://`, atau dimuat ulang di web): bila tidak ada halaman sebelumnya, ← membuka Beranda, bukan diam atau menutup aplikasi.
- **Langkah dalam satu halaman** (mis. pilih santri → isi data izin, Tinjau → Ubah isian): ← kembali ke langkah sebelumnya dulu, sesuai perilaku tombol "Kembali" yang sudah ada.
- **Draf yang belum disimpan:** perilaku perlindungan draf yang sudah ada tidak berubah; ← tidak menambah konfirmasi baru dan tidak membuang draf diam-diam di luar perilaku sekarang.
- **Bentuk:** target sentuh ≥ 44 pt, ikon panah dengan label aksesibilitas "Kembali" (atau "Kembali ke Beranda" pada layar tab). Tombol kembali Android dan geser-tepi iOS tetap bekerja.
- **Pengecualian karena tidak ada halaman sebelumnya:** layar Masuk dan Ganti password wajib (`ganti-password-wajib`), yang menyediakan tombol "Keluar".
- **Uji penerimaan:** setiap rute selain Beranda, Masuk, dan Ganti password wajib menampilkan ← yang dapat diketuk dan membawa ke tujuan di atas, termasuk saat dibuka langsung dari notifikasi.

## 6. Fase usulan

Satu fase per giliran, implementator lalu auditor, sesuai SOP `AGENTS.md`. Peran belum ditetapkan.

1. **Fondasi + Masuk + Beranda + Profil/Akun** — token, tipografi, seluruh komponen §3.3, `ScreenHeader` dua varian, layar 4.1 dan 4.2 (kecuali Jadwal/Perizinan).
2. **Pengajian, Perizinan, Izin wali, Notifikasi** — 4.2 Jadwal/Perizinan, 4.3, 4.4, 4.5, 4.11.
3. **Keuangan/BSA** — 4.9.
4. **Kesehatan, Pembinaan, Rapor, Keamanan + uji menyeluruh** — 4.6, 4.7, 4.8, 4.10; regresi semua peran.

Kriteria penerimaan tiap fase: `npx tsc --noEmit`, `npm run lint`, unit test, dan suite Expo web yang menyentuh layar fase lulus tanpa melonggarkan oracle; tidak ada perubahan berkas di luar `src/components`, `src/constants`, layar fase, dan aset; tidak ada perubahan permintaan API (dibuktikan dengan perbandingan log jaringan sebelum/sesudah untuk alur yang sama); mode gelap, teks 2,0× Android dan AX-XXXL iOS diperiksa; iOS simulator dan Android emulator; versi `expo.version` naik minor sekali per paket rilis (`npm run versi:naik`), tidak menurunkan versi baseline terbaru.

Batas: perangkat fisik, build distribusi, dan UAT tetap di luar audit lokal dan harus dicatat terbuka.
