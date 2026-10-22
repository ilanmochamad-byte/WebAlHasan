# Log keputusan redesain tampilan website — 22 Oktober 2026

Seluruh keputusan di bawah diambil Human Developer pada 22 Oktober 2026 saat meninjau cetak biru `cetak-biru.html` bersama Claude. Kode layar (mis. `I-4`) merujuk ke layar di cetak biru; buka berkas itu di browser lalu cari kodenya.

Penanda **[hak akses]**, **[database]**, dan **[fitur baru]** menandai keputusan yang bukan sekadar perubahan tampilan dan wajib diperlakukan sebagai cakupan implementasi serta diuji tersendiri.

## Peta menu admin

| # | Keputusan |
| --- | --- |
| A | PSB menjadi grup sidebar tersendiri dengan 3 menu langsung: Pendaftar, Pembayaran, Rekap keuangan. |
| B | Pembinaan tetap di grup Kegiatan santri. |
| C | Penugasan pindah ke grup Pengaturan. "Data sekolah" dibubarkan: orang (asatidz, pengurus) dipisah dari tempat (kelas, kobong); tahun ajaran ke Pengaturan; mata pelajaran ke Penilaian & rapor; katalog pelanggaran ke Pembinaan. |
| D | Rekonsiliasi wali menjadi tombol di halaman Orang tua/wali, bukan tab atau menu. |
| E | Nama: "Publikasi ke orang tua", "Data sistem lama", grup "Data pesantren", menu "Asatidz & pengurus" dan "Kelas & kobong". |

Hasilnya 17 menu dalam 7 grup: Beranda · Kegiatan santri (Perizinan, Pengajian, Pembinaan) · Akademik (Penilaian & rapor) · Data pesantren (Data santri, Asatidz & pengurus, Kelas & kobong) · PSB (Pendaftar, Pembayaran, Rekap keuangan) · Website (Konten website) · Pengaturan (Tahun ajaran, Penugasan, Akun & hak akses, Kanal notifikasi, Data sistem lama).

## Peta menu peran lain

| # | Keputusan |
| --- | --- |
| F | Orang tua melihat menu "Informasi pembinaan"; staf tetap menyebutnya "Publikasi ke orang tua". |
| G | Murobi mendapat tiga menu terpisah di grup Murobi: Antrean izin, Sikap binaan, Pembinaan binaan. |
| H | **[hak akses]** Bagian Pendidikan dapat melihat laporan kehadiran sebagai menu "Laporan absensi pengajian" (J-6), hanya-baca, untuk seluruh jadwal. Perlu mengubah `admin/_laporan_guard.php` dan cakupan layanan laporan. |
| I | Panitia PSB, bendahara PSB, dan bendahara bulanan belum diberi peta menu; dirancang saat PRD V5–V6. |
| J | Pengurus yang bukan pembimbing hanya melihat Beranda dan Perizinan. |
| K | Notifikasi untuk staf lewat lonceng topbar; untuk orang tua juga tampil sebagai menu. |

## Beranda & Perizinan

| # | Keputusan |
| --- | --- |
| L | "Perlu tindakan" admin (B-1) berupa daftar prioritas 4 tempat. Urutan: izin menunggu penetapan murobi, pendaftar PSB baru, pembayaran PSB belum lunas, notifikasi gagal, lalu izin belum diputus > 1 hari, bahan rapor perlu perbaikan, santri tanpa kelas/kamar, rekomendasi pembinaan belum ditindaklanjuti (ditambah keputusan AA). Kartu bernilai 0 disembunyikan dan tempatnya diisi kartu berikutnya; kelebihan disebut di "+n lainnya · Lihat semua"; bila semua 0 tampil "Tidak ada yang perlu ditindaklanjuti". |
| M | "Alasan cepat" (Pulang, Berobat, Acara keluarga, Lainnya) hanya mengisi awal teks alasan; tanpa kolom database. Grafik "Alasan izin" tidak dibuat. |
| N | Antrean murobi menampilkan pesan hasil dan tombol "Berikutnya", tidak pindah otomatis. |
| O | Layar baru I-7 "Izin anak" untuk orang tua: kartu per izin, tab Aktif dan Riwayat. |
| P | Batas tertunda 1 hari. |
| Q | Jumlah izin santri semester ini boleh dilihat pengurus. |

## Pengajian

| # | Keputusan |
| --- | --- |
| R | Absensi tidak dicatat di website. Halaman pertemuan web (J-2) menampilkan kehadiran dari aplikasi, ditambah buka/selesaikan pertemuan seperti sekarang. |
| S | Santri yang sedang izin diberi label saja; status tidak diisi otomatis. Label di aplikasi HP di luar cakupan (fitur lanjutan). |
| T | Hari ditulis "Ahad"; minggu dimulai Senin. |

## Penilaian & rapor

| # | Keputusan |
| --- | --- |
| U | Kisi nilai dan sikap memakai tombol "Simpan draf" dengan peringatan perubahan belum disimpan. |
| V | **[fitur baru]** Tindakan massal disetujui: serahkan yang lengkap (R-2/R-4), verifikasi semua yang diserahkan (R-5), terbitkan yang siap (R-6). Diproses satu per satu dengan bilah kemajuan; penerbitan berurutan dari peramban agar tidak melewati batas waktu cPanel. Layanan V4 sekarang bekerja per santri. |
| W | Impor nilai dari Excel dihapus dari rancangan. |
| X | Diserahkan ke perancang: "Salin dari semester lalu" (R-1) dipertahankan sebagai isi-awal formulir memakai pembacaan rencana yang ada, tanpa layanan baru. |
| Y | Orang tua melihat versi rapor terbaru saja dengan catatan "Menggantikan versi 1"; versi lama lewat tautan "Versi sebelumnya". |

## Pembinaan

| # | Keputusan |
| --- | --- |
| Z | **[hak akses]** Orang tua melihat judul "Informasi pembinaan · tanggal", nama anak, dan nama pembimbing sebagai pengirim; jenis sumber tidak ditampilkan. Nama pembimbing adalah data baru bagi orang tua; penyaring data orang tua di `app/V3/PublikasiService.php` perlu satu kolom tambahan. |
| AA | Angka "Santri mencapai ambang" di Beranda pengurus (B-3) dan kartu prioritas ke-8 di Beranda admin. |

## Data santri

| # | Keputusan |
| --- | --- |
| AB | **[hak akses]** Pembimbing dan murobi dapat membuka profil santri binaannya (D-10), hanya-baca, tab Ringkasan · Izin · Sikap · Pembinaan. Data santri sekarang khusus admin (`admin/_guard.php`). |
| AC | **[fitur baru]** Impor santri diperiksa di server tanpa menyimpan sebelum dikonfirmasi (D-4). |
| AD | **[database]** Tombol "Bukan ganda" pada rekonsiliasi wali menyimpan tanda pasangan (D-7). |

## Data master & Penugasan

| # | Keputusan |
| --- | --- |
| AE | **[database]** Kamar mendapat kolom Asrama putra/putri. Penempatan yang tidak sesuai memunculkan peringatan dan hanya dilanjutkan dengan konfirmasi beralasan (M-2, D-5). |
| AF | Jenis penugasan bendahara bulanan, panitia PSB, bendahara PSB tetap tampil, dipisah di bawah "Disiapkan untuk modul mendatang" (G-1). |

## PSB & Konten website

| # | Keputusan |
| --- | --- |
| AG | Pratinjau berkas di panel pendaftar dipertahankan, disajikan lewat jalur yang memeriksa hak akses, setelah tahap perbaikan keamanan PSB (K-5). |
| AH | Berita tanpa status dan kategori; langsung tampil seperti sekarang. |
| AI | Galeri tanpa album. |
| AJ | Aturan biaya wajib PSB tetap seperti sekarang: pendaftar memilih komponen yang dibayar (P-7). |

## Pengaturan, situs publik & PSB

| # | Keputusan |
| --- | --- |
| AK | **[hak akses]** Semua akun boleh membuka notifikasinya sendiri (N-3); penjaga khusus perizinan di `portal/notifikasi.php` diganti. |
| AL | Kartu WhatsApp tampil sebagai "Ditangguhkan" tanpa tombol Nyalakan (N-2). |
| AM | Tautan "Masuk" tampil di navbar dan bilah pintasan situs. |
| AN | MI Daar el-Qolam ditambahkan ke pilihan jenjang PSB; lima sekolah negeri dikelompokkan sebagai "Sekolah Mitra". Komponen biaya MI (dan RA) perlu ditetapkan panitia, karena `biaya_psb.php` hanya mengenal SMP dan SMK Terpadu. |
| AO | Persetujuan kebijakan privasi di PSB berupa kotak centang wajib, tidak disimpan. |
| AP | Login Portal Pendaftar tetap NISN + No. HP wali; perlindungannya diperbaiki di tahap keamanan PSB. |

## Kerangka, akun & cetak

| # | Keputusan |
| --- | --- |
| AQ | Pencarian topbar hanya untuk menu (di peramban). Pencarian santri dan nomor izin dicatat sebagai fitur lanjutan. |
| AR | Peran aktif disimpan di sesi sampai keluar, tanpa perubahan database. |
| AS | Laporan cetak perizinan dan kehadiran diberi satu tanda tangan "Bagian Pendidikan" dengan garis nama kosong untuk diisi manual. |

## Masih menunggu keputusan

1. Implementator, auditor, dan urutan tahap workstream ini (lihat `AGENTS.md`).
2. Pemindahan rincian pembiayaan PSB ke dalam portal pendaftar yang harus masuk (P-7).
3. Isi resmi halaman Profil, tahun berdiri, nomor WhatsApp/telepon resmi, logo dan foto, serta angka statistik Beranda situs.
4. Komponen biaya PSB untuk MI dan RA.
5. Tahap perbaikan keamanan atas temuan yang disampaikan langsung kepada Human Developer (tidak dirinci di repositori publik).
