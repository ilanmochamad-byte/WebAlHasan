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

## Penetapan peran & urutan tahap

Ditetapkan Human Developer 22 Oktober 2026: **implementator Claude, auditor Codex**, delapan tahap berurutan seperti di `rencana.md` dan `AGENTS.md`. Pada keputusan awal, Tahap 7 menunggu rapor Ganjil 2026/2027 terbit (diubah 23 dan 24 Oktober 2026 di bawah); Tahap 8 menunggu tahap perbaikan keamanan PSB.

**Perubahan 23 Oktober 2026 (Human Developer):** Tahap 7 boleh dikerjakan dan diaudit sebelum rapor Ganjil 2026/2027 terbit; **merge ke `main` dan deploy ditahan sampai rapor itu terbit**, sehingga guru tetap tidak berganti alur di tengah masa pengisian nilai. **Penundaan merge/deploy ini kemudian dicabut pada 24 Oktober 2026 (AX).**

## Masih menunggu keputusan

1. ~~Peran untuk tahap perbaikan keamanan PSB~~ — **ditetapkan Human Developer 24 Oktober 2026: implementator Claude, auditor Codex** (`docs/keamanan-psb/`). Workstream ini lulus audit Codex setelah koreksi terarah 24 Oktober 2026 (`../keamanan-psb/AUDIT-CODEX.md`); syarat bahan resmi, biaya MI/RA dan instruksi Tahap 8 tetap berlaku.
2. ~~Pemindahan rincian pembiayaan PSB ke dalam portal pendaftar yang harus masuk (P-7).~~ — diputuskan 24 Oktober 2026 (BA).
3. ~~Isi resmi halaman Profil, tahun berdiri, nomor WhatsApp/telepon resmi, logo dan foto, serta angka statistik Beranda situs.~~ — diputuskan dikosongkan dan diisi manual (AY); isinya sendiri tetap menunggu pesantren.
4. Komponen biaya PSB untuk MI dan RA — belum ditetapkan; sampai ditetapkan MI/RA belum dibuka (AZ).
5. Tahap perbaikan keamanan atas temuan yang disampaikan langsung kepada Human Developer (tidak dirinci di repositori publik). Temuan PSB ditangani workstream keamanan PSB (24 Oktober 2026); temuan di luar PSB tetap menunggu.

## Keputusan Tahap 7 (23 Oktober 2026, sesudah implementasi)

| # | Keputusan |
| --- | --- |
| AT | "Salin dari semester lalu" (R-1) hanya untuk admin; Bagian Pendidikan tidak diberi hak baca rencana semester lalu. |
| AU | Aturan rencana disesuaikan agar efektif dan efisien: mapel tanpa nilai dapat diubah/dihapus walau mapel lain sudah bernilai; target mapel bernilai terkunci dan mapel itu tidak dapat dihapus; guru pengampunya tetap dapat diganti; penanda tangan dan tempat rencana bernilai tetap lewat penggantian beralasan admin (R-7); "Tandai siap" tidak dibuat. **[aturan layanan]** `RencanaService::save()`. |
| AV | Orang tua melihat versi rapor yang sudah diganti hanya sebagai nomor, tanggal terbit, dan tanda "Sudah diganti", tanpa isi atau berkas. |
| AW | Setiap perubahan isian nilai/sikap yang sudah tersimpan tetap koreksi beralasan (aturan layanan V4 yang ada); kisi meminta alasan koreksi. |


## Keputusan merge/deploy Tahap 7 (24 Oktober 2026)

| # | Keputusan |
| --- | --- |
| AX | Human Developer menyatakan pengisian rapor belum dilakukan dan belum ada data isian nilai atau rapor. **Syarat menunggu rapor Ganjil 2026/2027 terbit untuk merge/deploy Tahap 7 dicabut.** Tahap 7 telah lulus audit Codex pada `a04c6e8`; Human Developer akan melakukan merge branch `claude/redesain-web-tahap-7` ke `main` melalui GitHub, lalu deploy ke website produksi melalui cPanel sebelum pengisian dimulai. |

Status pelaksanaan: **merge terlaksana, deploy belum dikonfirmasi.** PR #73 sudah di-merge ke `main` (`7f7a002`, 23 Oktober 2026 18.05 WIB) dan memuat kode hasil audit `a04c6e8`; catatan keputusan AX (`eab04e8`) masuk ke `main` lewat PR #74 (`5b9e002`). Deploy cPanel belum dikonfirmasi. Keterangan belum adanya data berasal dari Human Developer; auditor tidak memeriksa database produksi atau menyatakan smoke cPanel sudah lulus. Keputusan AX menggantikan penundaan 23 Oktober 2026 dan tidak mengubah cakupan, prasyarat, atau penetapan peran Tahap 8/keamanan PSB.

## Status audit keamanan PSB — 24 Oktober 2026

Implementasi Claude `c6014e1` pada `claude/perbaikan-keamanan-psb` dari `a116e0f` **lulus audit independen Codex setelah dua koreksi terarah layanan biaya**. Bukti dan batas di `../keamanan-psb/AUDIT-CODEX.md`; langkah manual `.htaccess` pada README tetap wajib ketika deploy. Belum merge/deploy atau migrasi produksi. Keputusan AG, AJ, AN, AP dan penundaan pemindahan P-7 tetap; Tahap 8 belum dimulai dan tetap menunggu bahan resmi, biaya MI/RA serta instruksi Human Developer.


## Keputusan Tahap 8 (24 Oktober 2026, sebelum implementasi)

Prompt instruksi Tahap 8 memuat tiga butir pilihan (a)/(b) yang artinya tidak tercatat di repositori; Claude menanyakan ulang dan Human Developer menjawab:

| # | Keputusan |
|---|---|
| AY | Bahan resmi situs **dikosongkan dan diisi manual** oleh Human Developer. Semua isi resmi dibaca dari `app/Situs/isi-situs.php`; isian kosong tidak ditampilkan, tanpa teks/nomor/foto contoh. |
| AZ | **MI dan RA belum dibuka**: tampil di pengelompokan jenjang "Unit Al Hasan" tetapi belum dapat dipilih sampai biayanya ditetapkan; ditegakkan server. |
| BA | **Pembiayaan PSB tetap di `biaya_psb.php`** (sudah dilindungi perbaikan keamanan PSB), tampilannya didesain ulang, dan dibuka dari menu "Pembiayaan" Portal Pendaftar. Alasan Human Developer: tidak mengulang pekerjaan keamanan yang sudah lulus audit. |

Implementasi dan bukti: `tahap-8/README.md`.

Status audit 24 Oktober 2026: **Tahap 8 lulus audit independen Codex setelah tiga koreksi terarah tampilan**, tanpa mengubah keputusan AY–BA atau aturan/hak akses keamanan PSB. Laporan, bukti pengujian independen, oracle historis dan batas: `tahap-8/AUDIT-CODEX.md`. Belum merge/deploy; langkah manual `.htaccess` keamanan PSB tetap wajib saat rilis oleh Human Developer.


## Keputusan PRD V5 Fase 1 (24 Oktober 2026, sesudah implementasi)

| # | Keputusan |
|---|---|
| BB | **Menu admin "Kesehatan" dipertahankan** di grup Kegiatan santri sebagai menu ke-18 peta admin. Keputusan ini menambah satu menu pada peta admin 17 menu (A–E) untuk PRD V5 (kesehatan santri); urutan dan isi 17 menu lainnya tidak berubah. Oracle historis Tahap 2 "admin 17 menu" tidak diubah dan dicatat sebagai kegagalan yang diharapkan. |

Rincian: `../phase-v5-1/README.md` (P-1).



## Keputusan PRD V5 Fase 2 (24 Oktober 2026, sesudah implementasi)

| # | Keputusan |
|---|---|
| BC | **Menu "Kesehatan" untuk pembimbing dan murobi dipertahankan**: pembimbing (peran Pengurus) di grup "Santri binaan", murobi (peran Guru) di grup "Murobi", hanya bila penugasan binaan aktifnya ada. Menu hanya-baca; hak baca tetap ditegakkan server per baris (kunjungan Tercatat santri dalam cakupan). Isi dan urutan menu lain pada peta peran F–K tidak berubah. Oracle historis Tahap 2 untuk menu `sbx_murobi_a` dan `sbx_pengurus_a` tidak diubah dan dicatat sebagai kegagalan yang diharapkan. |

Rincian: `../phase-v5-2/README.md` (Q-1).



## Keputusan PRD V5 Fase 3 (24 Oktober 2026, sesudah implementasi)

| # | Keputusan |
|---|---|
| BD | **Menu "Kesehatan anak" untuk orang tua dipertahankan** pada peta peran orang tua (keputusan J–K), sesudah "Informasi pembinaan" dan sebelum "Notifikasi", bagi akun orang tua aktif dengan master wali aktif bila migrasi 026 terpasang (termasuk sebelum ada ringkasan; daftar kosong menjelaskan). Bilah bawah HP orang tua tidak berubah. Menu hanya-baca; hak baca ringkasan tetap ditegakkan server per baris (relasi wali aktif). Isi dan urutan menu lain tidak berubah. Oracle historis Tahap 2 untuk menu `sbx_ortu_a` tidak diubah dan dicatat sebagai kegagalan yang diharapkan. |

Rincian: `../phase-v5-3/README.md` (R-1).



## Keputusan PRD V6 Fase 1 (25 Oktober 2026, sesudah implementasi)

| # | Keputusan |
|---|---|
| BE | **Menu Keuangan dipertahankan untuk admin, petugas BSA, dan orang tua.** Admin: grup baru "Keuangan" (sesudah "Akademik", sebelum "Data pesantren") berisi "Keuangan santri" — hanya baca; peta admin menjadi 19 menu dalam 8 grup. Pengurus dengan penugasan Petugas BSA aktif: grup "Keuangan" berisi "BSA" (juga kandidat terakhir bilah bawah HP pengurus). Orang tua: "Keuangan anak" sesudah "Kesehatan anak" dan sebelum "Notifikasi", bagi akun orang tua aktif dengan master wali aktif bila migrasi 027 terpasang (daftar kosong menjelaskan bila belum ada anak berelasi); bilah bawah HP orang tua tidak berubah. Seluruh menu tidak tampil sebelum migrasi 027/sesudah rollback. Hak baca/mutasi tetap ditegakkan server oleh `BsaService` per santri, bukan oleh menu. Isi dan urutan menu lain tidak berubah. Oracle historis Tahap 2 ("admin 17 menu/7 grup", menu `sbx_ortu_a`, grup menu yang disorot) tidak diubah dan dicatat sebagai kegagalan yang diharapkan. Keputusan ini tidak mengubah larangan rilis parsial: tagihan dan tabungan tetap dirilis bersama sesudah Fase 5. |

Rincian: `../phase-v6-1/README.md` (P-1).
