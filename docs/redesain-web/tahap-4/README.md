# Redesain website Tahap 4 — perizinan, pengajian & cetak (23 Oktober 2026)

Status: **lulus audit independen Codex setelah koreksi terarah J-1 (23 Oktober 2026).** Belum merge/deploy/migrasi produksi. Tahap 5 belum dimulai.

- Audit dan bukti independen: [`AUDIT-CODEX.md`](AUDIT-CODEX.md). Auditor menutup pemotongan kisi mingguan pada 100 jadwal; uji akhir statis 96/0, HTTP 132/0, PDF 60/0, browser Tahap 4 121/0, Tahap 1 91/0, dan probe akses H/preservasi/101 jadwal 34/0. Kegagalan receipt fixture dan batas nonblokir dicatat terbuka dalam laporan audit.
- Branch `claude/redesain-web-tahap-4` dibuat dari `11ed4ba` (ujung `claude/redesain-web-tahap-3` yang lulus audit ulang Codex). `origin/main` `24f10f9` baru menggabungkan Tahap 3 sampai `e626313`, tanpa koreksi `549c7e6` dan audit ulang `11ed4ba`, sehingga Tahap 4 dibuat dari commit audit itu — pola yang sama dengan Tahap 2 dan 3. Tidak ada worktree.
- Sumber: `AGENTS.md`, `rencana.md`, `keputusan.md`, `cetak-biru.html` (I-1 s.d. I-7, J-1 s.d. J-6, X-1, peta menu, baris Tahap 4 "Urutan tahap pengerjaan"), `tahap-3/AUDIT-ULANG-CODEX.md`.
- Cakupan keputusan: **H** (hak akses), M, N, O, Q, R, S, T, AS.
- Kriteria selesai: *pengurus mengajukan izin dari HP tanpa geser horizontal; Bagian Pendidikan membaca laporan absensi seluruh jadwal; laporan cetak bertanda tangan Bagian Pendidikan.*

## Hasil terhadap kriteria

| Kriteria | Bukti |
| --- | --- |
| Pengurus mengajukan izin dari HP tanpa geser horizontal | Browser T4-B (375px): saring + pilih santri, panel "Akan diteruskan ke" terisi dari server, durasi, alasan cepat, kirim → detail tampil; geser horizontal 0 di setiap langkah, tombol Kirim ≥ 44 px. T4-A memeriksa I-1…I-7 di 375/768/1280px tanpa melebar. |
| Bagian Pendidikan membaca laporan absensi seluruh jadwal | Server H4-B: angka laporan Bagian Pendidikan = admin (2 pertemuan dari dua guru), guru tetap hanya miliknya, saringan guru mana pun boleh, detail pertemuan guru lain terbuka tanpa formulir, CSV = CSV admin, cetak terbuka; POST 405; modul jadwal/pertemuan tetap 403; pengurus tanpa penugasan Pendidikan dan orang tua 403; penanda tidak dapat disuntik lewat alamat; REST API (`apiReport`) tetap 403. Browser T4-G: menu "Laporan absensi pengajian" → laporan seluruh jadwal tanpa tab Jadwal/Pertemuan. |
| Laporan cetak bertanda tangan Bagian Pendidikan | Server H4-I dan PDF: cetak perizinan dan kehadiran memuat SATU kolom tanda tangan "Bagian Pendidikan" (ruang tanda tangan + garis nama kosong) di lembar terakhir, kode laporan di kaki setiap lembar. `tests/redesain_web_tahap4_cetak.php`: 15 dokumen × 3 orientasi — jumlah halaman PDF Chromium = jumlah lembar server (60/0), tanda tangan tidak pernah berdiri sendirian. |

## Perubahan hak akses (keputusan H)

- `admin/_laporan_guard.php`: menerima admin, guru (jadwal sendiri), dan **Bagian Pendidikan** = role `pengurus` + penugasan Pendidikan aktif MILIK SENDIRI (`featureSource(CAP_RAPOR_VERIFIKASI)` = penugasan/keduanya; pengawasan admin tidak dihitung). Tetap GET/HEAD saja.
- Guard memasang `$currentUser[ReportFilter::BACA_SEMUA_JADWAL] = true` untuk Bagian Pendidikan non-admin. Untuk akun yang juga guru, penanda hanya dipasang saat peran aktifnya Bagian Pendidikan (peran hanya mempersempit). Penanda ini satu-satunya cara selain admin untuk `ReportFilter::bacaSemua()`; dipakai `ReportFilter::forUser()`, `ReportService::options()`, dan `ReportService::meeting()`. Data akun dari basis data dan token API tidak memiliki kunci ini, sehingga **REST API dan aplikasi guru tidak berubah**. Uji statis S4-3 memastikan hanya guard yang memasangnya.
- `App\Ui\Navigation::pendidikan()`: grup **Laporan › Laporan absensi pengajian** (`admin_laporan_absensi.php` + detail/cetak/CSV) hanya bila penugasan Pendidikan sendiri. Menu tetap bukan kontrol akses.
- Modul jadwal & pertemuan (`admin/admin_pengajian.php`) tidak berubah haknya: admin/guru saja.

## Perubahan per layar

| Layar | Berkas | Isi |
| --- | --- | --- |
| I-1 Ringkasan | `portal/izin_ringkasan.php` | Judul "Perizinan santri", tombol Ajukan izin (pengurus/admin). Kartu angka per status menjadi tautan ke tab Pengajuan berstatus itu. "Pengajuan terbaru" (4 kolom, kartu di HP) + kartu "Alur izin" menggantikan catatan panjang. |
| I-2 Pengajuan | `portal/izin.php` | Chip status berangka (Semua · Diajukan · Perlu penetapan · Disetujui · Ditolak · Dibatalkan); saringan cari/tanggal; **Filter lanjutan**: Asal data (Sistem baru / Arsip sistem lama) dan "Menunggu lebih dari 1 hari". Tabel 10 → 7 kolom (Alasan & Pengurus disembunyikan di tablet), kartu di HP, tanda kuning "Menunggu n hari". Label "V2"/"Data warisan" hilang dari layar. |
| I-3 Antrean | `portal/izin_antrean.php`, `portal/izin_aksi.php` | Daftar di kiri, detail di kanan (≥768px); HP: detail menggantikan daftar dengan tombol "Kembali ke antrean". Detail memuat rentang dengan nama hari, alasan, catatan, pengurus, **izin semester ini** (Q), lama menunggu, dan riwayat izin santri dalam cakupan akun. Murobi memutus dengan alasan + Tolak/Setujui (konfirmasi dialog, bilah menempel di HP). **N:** POST `kembali=antrean` kembali (303) ke antrean dengan detail yang SAMA, pesan "Izin #x disetujui…" dan tombol "Berikutnya: #y ›"; tidak pindah otomatis. Nilai `kembali` lain diabaikan. |
| I-4 Ajukan izin | `portal/izin_buat.php`, `IzinWorkflowService::routingPreview()`, `IzinService::santriSemesterCount()` | Satu kotak pilih santri yang dapat disaring (pencarian server bila > 100); **M:** tombol alasan cepat Pulang/Berobat/Acara keluarga/Lainnya hanya mengganti awalan teks (tanpa isian/kolom baru); "Catatan untuk murobi". Panel **Akan diteruskan ke**: murobi tujuan (routing yang sama dengan pengajuan), durasi, jumlah izin semester ini — dibaca `?info=1` (JSON) yang memeriksa cakupan seperti pengajuan; santri luar binaan 403. Pita kuning mode admin. Kirim menempel di HP. |
| I-5 Detail | `portal/izin_detail.php` | Kolom kanan "Tindakan" hanya berisi tindakan yang boleh (`actionsFor`); setiap tombol membuka **dialog** berisi isian yang dibutuhkan saja (Putuskan / Putuskan sebagai admin pengganti / Tetapkan murobi / Koreksi / Batalkan). Riwayat status + koreksi = satu linimasa dengan nama peristiwa manusiawi. Versi data, kandidat routing, ID lama di **⋯ › Info teknis** (admin). Tombol Cetak (cetak peramban). HP: tindakan utama di bilah bawah. Setelah validasi gagal dialog terbuka lagi dengan isian dan pesan. |
| I-6 Laporan | `portal/laporan.php`, `IzinReportRepository::chartData()`, `IzinReportService::charts()` | Empat saringan utama (**Periode** cepat, Status, Kelas, Kamar) + Filter lanjutan (tanggal sendiri, basis tanggal, santri, pengurus/murobi, durasi, kanal, asal data, tahun ajaran, cari). Ringkasan Total · Disetujui · Ditolak · Median waktu putus · Terlama. Grafik **Pengajuan per pekan** (Senin–Ahad) dan **per kelas** dari filter yang sama (jumlah batang = total). Tabel 7 kolom, kartu di HP. Kode laporan. |
| I-7 Izin anak | `portal/izin.php` (mode orang tua), `IzinRepository` filter `aktif` | Pilihan anak (chip) bila > 1 anak, tab **Aktif** dan **Riwayat**, satu kartu per izin (rentang, alasan, status, keputusan murobi: nama, waktu, catatan). Hanya-baca; ketuk → detail dengan linimasa. Tautan laporan (cetak/CSV). |
| J-1 Jadwal | `admin/admin_pengajian.php`, `admin/_pengajian_jadwal.php` | Segmen **Mingguan/Daftar**. Kisi mingguan: baris waktu, kolom **Senin…Ahad** (T), blok kitab · kelas · jam · guru, blok bentrok guru/kelas ditandai kuning, hari ini ditandai. <992px: pilih hari lalu daftar per waktu. Tambah/ubah/detail di **panel samping**; Daftar dengan saringan dan menu ⋯ (Nonaktifkan/Arsipkan lewat dialog bersama — `confirm()` lama hilang). |
| J-3 Daftar pertemuan | `_pengajian_pertemuan.php` | Tabel Tanggal · Jadwal · Kelas · Guru · Peserta · Status (kartu di HP), pencarian. "Buka pertemuan" di panel samping (Simpan draf / Buka & bekukan). |
| J-2 Pertemuan & kehadiran | `_pengajian_pertemuan.php`, `IzinService::approvedCovering()` | Langkah Draf → Dibuka → Selesai; **R:** kehadiran guru dan santri hanya-baca dari aplikasi (`report_service()->meeting()`), tanpa isian status; **S:** label "Sedang izin #n · rentang" pada santri yang izinnya disetujui pada tanggal pertemuan — status tidak diisi otomatis. Ringkasan hadir/terlambat/izin/sakit/alpa/belum dicatat, cari santri, catatan pertemuan, bilah Buka/Selesaikan (dialog) menempel di HP. |
| J-4 / J-6 Kehadiran | `admin/admin_laporan_absensi.php` | Segmen Santri/Guru/Gabungan, saringan Periode/Kelas/Guru + lanjutan, ringkasan persen, bilah komposisi per jadwal, tabel per jadwal (Terlambat/Izin/Sakit disembunyikan di tablet), rincian baris terlipat. J-6 untuk Bagian Pendidikan: judul "Laporan absensi pengajian", "Seluruh jadwal pengajian · hanya-baca", tanpa tab Jadwal/Pertemuan, periode bawaan Semester ini. |
| J-5 Detail pertemuan | `admin/laporan_absensi_detail.php` | Di kerangka dengan jalur halaman, kartu Pertemuan, tabel peserta (kartu di HP) dengan label izin dan waktu perubahan terakhir; tanpa tombol ubah. |
| X-1 Templat cetak | `app/Report/PrintLayout.php`, `IzinPrintRenderer.php`, `PrintRenderer.php`, `portal/v3_konseling_cetak.php` | `PrintLayout::kop()` sama untuk laporan perizinan, kehadiran, dan cetak konseling. **AS:** `PrintLayout::tandaTangan('Bagian Pendidikan')` di kanan bawah lembar terakhir; tingginya (26 mm) dianggarkan di `pecahLembarKolom()` — bila tidak muat, baris terakhir ikut pindah bersama tanda tangan. Kode laporan di kaki. Pemecahan lembar, petunjuk orientasi, dan "Halaman x dari y" server tetap. |

Komponen bersama baru: `ah_hari`, `ah_tanggal`, `ah_rentang`, `ah_waktu`, `ah_seg`, `ah_timeline`, `ah_action_dialog_open/close` (`app/Ui/functions.php`); `App\Ui\Periode`; JS `details[data-ah-dialog]`, `[data-ah-prefix]`, `[data-ah-option-filter]`, `[data-ah-izin-info]`, `[data-ah-list-filter]`, `[data-ah-print]`; CSS `.ah-split`, `.ah-seg`, `.ah-tl`, `.ah-vsteps`, `.ah-act`, `.ah-qlist`, `.ah-icard`, `.ah-week`, `.ah-block`, `.ah-daypick`, `.ah-people`, `.ah-actbar`, `.ah-stack`, `.ah-filter__more`, `.ah-ribbon`. Kamus status: Izin/Sakit biru, Alpa merah. Peta menu: Pengajian bertab **Jadwal · Pertemuan · Kehadiran**; Izin anak bertab **Aktif · Riwayat**.

## Penafsiran dan batas cakupan — mohon dinilai auditor

1. **Siapa Bagian Pendidikan (H).** Pengurus dengan penugasan Pendidikan aktif milik sendiri; penugasan yang dibatasi satu jenjang tetap membaca seluruh jadwal, karena keputusan H menyebut "untuk seluruh jadwal". Akun Pendidikan yang juga guru membaca seluruh jadwal hanya saat peran aktifnya Bagian Pendidikan.
2. **Halaman perizinan untuk akun Pendidikan.** `sbx_pendidikan` (pengurus + Pendidikan) berperan aktif Bagian Pendidikan; halaman perizinan milik peran Pengurus-nya tetap ditahan dengan pilihan "Buka sebagai Pengurus" (A-2, perilaku Tahap 2). Uji H4-A mengharapkan 403 itu.
3. **I-1 "Total bulan ini".** Angka kartu = seluruh pengajuan dalam cakupan (ringkasan yang sudah ada), bukan bulan berjalan; angka gambar hanya contoh. Laporan (I-6) memakai periode.
4. **Chip I-2** menghitung seluruh cakupan per status (tidak ikut saringan tanggal/cari), sama dengan ringkasan.
5. **I-3 alasan keputusan** tetap wajib untuk setuju maupun tolak (aturan server tidak diubah); bantuan menyebut "terutama bila menolak". Pesan hasil tidak menulis "Wali sudah diberi tahu" karena pengiriman bergantung kanal (WhatsApp ditangguhkan); tertulis "Pengurus dan wali santri menerima notifikasi keputusan sesuai kanal yang aktif". Antrean admin/pengurus menampilkan detail dengan tautan ke detail lengkap (Tetapkan murobi membuka dialognya langsung).
6. **Jumlah izin semester ini (Q)** = pengajuan santri pada tahun ajaran/semester aktif, tanpa yang dibatalkan, ditambah jumlah disetujui. Murobi hanya dapat membacanya untuk santri yang pengajuannya pernah diarahkan kepadanya; orang tua untuk anaknya; pengurus untuk binaan aktif.
7. **I-4 pilihan santri.** Paling banyak 100 pilihan per halaman; bila lebih (admin), pencarian server tetap tersedia. Tanpa JavaScript panel tujuan terisi bila `santri_id` ada di alamat (mis. sesudah "Perbaiki isian"); memilih santri tidak memuat ulang halaman.
8. **I-5 Cetak** = cetak halaman detail lewat peramban (tidak ada templat cetak satu izin). `?id=` tidak sah kini 404; sebelumnya pengajuan pertama dalam cakupan ikut terbuka (bukan kebocoran, tetapi keliru).
9. **Periode "Semester ini".** Tahun ajaran tidak menyimpan tanggal; Ganjil = 1 Juli–31 Desember tahun pertama, Genap = 1 Januari–30 Juni tahun kedua dari teks "2026/2027". Alamat lama bertanggal tanpa `periode` tetap memakai tanggalnya ("Rentang sendiri").
10. **Grafik I-6** dibaca lewat metode web terpisah (`IzinReportService::charts`), sehingga bentuk respons REST API laporan tidak berubah. Kolom tambahan `keputusan_alasan`/`keputusan_oleh` pada pembacaan daftar perizinan bersifat aditif; presenter API memakai daftar kolom tetap.
11. **I-7 Aktif** = belum diputus, atau disetujui dan tanggal kembali belum lewat; Ditolak/Dibatalkan masuk Riwayat. Pilihan anak diingat di sesi. Ringkasan dan laporan lama tetap terbuka untuk orang tua dan ditempatkan di bawah menu Izin anak.
12. **J-1 bentrok "langsung di panel".** Bentrok guru/kelas di antara jadwal yang sudah ada ditandai di kisi dan di panel ubah/detail; saat menyimpan, server tetap menolak guru beririsan dan memperingatkan kelas/tempat (aturan lama). Belum ada pemeriksaan langsung saat mengetik (perlu layanan baru). Kisi memuat jadwal aktif dan nonaktif (tanpa arsip) semester terpilih; waktu di luar empat waktu sholat masuk baris "Lainnya"; jadwal tanpa hari/jam terstruktur di lipatan tersendiri.
13. **J-2 catatan pertemuan** tetap diisi saat draf/buka seperti sebelumnya ("seperti sekarang"); tidak ditambah mutasi ubah catatan. Label "Sedang izin" menaut ke detail izin hanya untuk admin; peran lain melihat labelnya saja.
14. **J-3 saringan** berupa satu pencarian (tanggal, guru, kelas, kitab, status) memakai `PageQuery` yang ada; pilihan Tanggal/Guru/Status terpisah pada gambar tidak dibuat agar tidak menambah layanan.
15. **J-5 riwayat perubahan per santri** belum ada tabel riwayatnya di basis data; yang ditampilkan adalah waktu perubahan terakhir dengan ikon jam.
16. **Dokumen PSB (X-1).** `cetak_bukti.php` dan `cetak_biaya_psb.php` adalah halaman publik PSB yang menjadi cakupan Tahap 8 (menunggu tahap perbaikan keamanan PSB), sehingga kopnya **belum** diseragamkan; penanda tangannya memang tetap. Kop bersama sudah dipakai laporan perizinan, kehadiran, dan cetak konseling.
17. **Tanda tangan di PDF aplikasi.** Renderer cetak yang sama dipakai aplikasi mobile lewat API (`expo-print`), sehingga PDF dari aplikasi juga memuat kolom tanda tangan. Kontrak API tidak berubah (tetap string HTML); tampilan PDF aplikasi berubah sesuai AS. Mobile tidak diuji ulang.
18. Tidak ada perubahan skema/migrasi, mobile, situs publik, atau API bisnis. Nilai hari "Minggu" di basis data tetap; hanya tampilan "Ahad".

## Pengujian yang disesuaikan

Harapan lama yang menyebut tampilan yang memang diganti Tahap 4 diperbarui tanpa melemahkan maksudnya:

| Uji | Perubahan |
| --- | --- |
| `perapihan_static` | "Tab dirender lewat kerangka bersama": tab Pengajian kini tab modul dari `Navigation::pengajian()`. |
| `phase5_static` | Detail pertemuan: pemeriksaan pembaca seluruh jadwal kini `ReportFilter::bacaSemua($user)` (admin atau penanda web Pendidikan); pemeriksaan kepemilikan guru tetap. |
| `redesain_web_tahap2_static`, `redesain_web_tahap2_http` | H yang dulu "ditunda ke Tahap 4" kini diharapkan terpasang; peta menu `sbx_pendidikan` + grup Laporan; teks keterangan daftar "— n pengajuan" tanpa titik di ujung. |
| `v2_phase2_static` | Kunci idempotensi keempat formulir detail kini dari satu pembuat isian `$hidden` (tetap kunci baru per formulir). |
| `v2_phase2_web_smoke` WEB-14/22 | Panel keputusan dicari lewat tombol/dialog baru (`aksi-putuskan`, "Putuskan sebagai admin pengganti", isian `alasan_penggantian`). |
| `v2_phase5_web_smoke` WL-2b/d/e/g | Judul huruf kalimat "Laporan perizinan"; label ringkasan "Median waktu putus"/"Total"; tautan laporan orang tua ada di halaman Izin anak. |
| `v2_phase5_cetak_pdf` A24b/c | Data produksi Safari 36 baris kini 10/13/12/1 lembar (tanda tangan dianggarkan; lanjutan tetap ≤ 13 baris; tanda tangan sekali di lembar terakhir bersama satu baris). |
| `browser/uji-perapihan.mjs` B-4/B-5 | Tab Jadwal/Pertemuan dicari di tab modul; penyajian laporan dibaca dari segmen. |
| `admin/_pengajian_jadwal.php` | Label pencarian daftar jadwal tetap "Pencarian" (diperiksa `phase3_static`). |

## Bukti

Lingkungan: PHP 8.4, Node 26, MariaDB 12 lokal, Chromium Playwright. DB: `tests/redesain_web_tahap4_db.sh` membangun salinan Tahap 3 bernama `test_redesain_t4_test` + fixture sintetis `tests/redesain_web_tahap4_seed.php` (pertemuan Dibuka berpeserta dengan kehadiran dari "aplikasi", pertemuan guru lain, izin disetujui yang mencakup hari ini, tiga pengajuan ke murobi C — satu > 1 hari, satu beralasan bertag HTML), dan salinan kosong `test_redesain_t4_kosong_test`.

| Bukti | Hasil | Berkas |
| --- | --- | --- |
| `php tests/redesain_web_tahap4_static.php` | **96/0** | `uji-statis.txt` |
| `php tests/redesain_web_tahap4_http.php` (server sendiri, DB Tahap 4 + kosong) | **132/0** | `uji-server.txt`, `persiapan-db.txt` |
| `node tests/browser/uji-redesain-web-tahap4.mjs` (27 layar-akun × 375/768/1280 + interaksi) | **121/0** | `uji-browser.txt`, `tangkapan/` |
| `php tests/redesain_web_tahap4_cetak.php` (PDF Chromium css/lanskap/potret) | **60/0** | `uji-cetak-pdf.txt` |
| Regresi: 24 suite statis (22 exit 0, 2 gate DB V3 exit 77 seperti baseline), `php -l` 464 berkas, smoke HTTP & kontrak 11 suite, integrasi V2 Fase 2/4/5, perapihan, penugasan, Fase 2–5 jadwal/laporan, cetak PDF Bagian A 76/0 | lulus | `regresi.txt` |
| Tahap 1–3: HTTP Tahap 2 191/0, probe audit Tahap 2 29/0, browser Tahap 2 89/0, HTTP Tahap 3 167/0, probe Codex Tahap 3 13/0 dan audit ulang 13/0, browser Tahap 3 76/0, browser Tahap 1 sembilan akun × tiga lebar 91/0 (DB Tahap 4), browser perapihan 56/0 | lulus | `regresi.txt` |

## Belum dijalankan / batas

- Hanya Chromium. Safari/iOS, Firefox, perangkat fisik, pembaca layar, dan axe belum dijalankan.
- `tests/v2_phase5_cetak_pdf.php` Bagian B (ekstraksi teks PDF) MENUNGGU karena `pdftotext`/`pdfinfo` tidak terpasang; jumlah halaman fisik Tahap 4 dibuktikan terpisah lewat MediaBox (`uji-cetak-pdf.txt`).
- Tidak diulang: uji perbaikan mutasi V1 (`get_mutasi_v1_*`) dan cleanup legacy (halamannya tidak disentuh Tahap 4), integrasi/konkurensi V3–V4, banding status baseline per alamat, aplikasi mobile (termasuk PDF laporan dari aplikasi yang kini memuat tanda tangan).
- Tidak ada smoke cPanel/produksi.

## Handoff ke auditor (Codex)

Mohon audit Tahap 4 terhadap I-1 s.d. I-7, J-1 s.d. J-6, X-1, keputusan H, M, N, O, Q, R, S, T, AS, kriteria selesai di atas, dan butir "Penafsiran dan batas cakupan" — terutama perubahan hak akses H (uji server wajib). Jalankan dari root repo:

```bash
bash tests/redesain_web_tahap4_db.sh
php tests/redesain_web_tahap4_static.php
php tests/redesain_web_tahap4_http.php
php tests/redesain_web_tahap4_cetak.php
bash tests/redesain_web_tahap4_db.sh
DB_NAME=test_redesain_t4_test php -S 127.0.0.1:8961 tests/redesain_web_tahap1_router.php &
BASE_URL=http://127.0.0.1:8961 node tests/browser/uji-redesain-web-tahap4.mjs
BASE_URL=http://127.0.0.1:8961 node tests/browser/uji-redesain-web-tahap1.mjs
```

Uji browser Tahap 4 mengubah data uji (satu pengajuan, satu keputusan); bangun ulang DB sebelum mengulang. Tahap 5 tidak dimulai sebelum Tahap 4 lulus audit dan ada instruksi Human Developer.
