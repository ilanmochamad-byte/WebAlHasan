# Verifikasi lanjutan 14 klaim — Codex

> **Pembaruan A-17:** koreksi kolom PDF telah disetujui pengguna dan selesai; sembilan PDF kini 45 lulus, 0 gagal. Rekap penerimaan tetap 75 terverifikasi dan 2 menunggu pembaca layar/cetak Safari nyata. Lihat [audit-koreksi-pdf-a17.md](audit-koreksi-pdf-a17.md). Ini bukan izin rilis; angka pada isi laporan historis di bawah tetap merupakan hasil saat itu.

**Hasil:** 12 dari 14 klaim tambahan terverifikasi pada sandbox; dua masih
menunggu pembaca layar/cetak Safari. Total pengujian tanpa duplikasi:
**3.892 lulus, 3 gagal**. Kegagalan adalah cacat PDF potret pra-ada A-17 yang
menunggu keputusan pengguna. **Paket belum dinyatakan lulus atau siap produksi.**

Instruksi pengguna: verifikasi seluruh 14 klaim yang masih menunggu, sesudah
`3413895`, pada branch `codex/perapihan-v1-v2-ui`. Tidak ada izin push, merge,
deploy, perubahan mobile, atau perubahan produksi. Dokumen ini dilengkapi
bersama bukti eksekusi; status tidak diubah hanya karena kode telah dibaca.

## A-12 — P1: audit akun tidak atomik dengan perubahan hak/status

K1-05: sebelum koreksi, AccountService dan PerizinanAccountService menyimpan
mutasi lebih dahulu, kemudian mengabaikan hasil boolean AuditLogger::log.
Kegagalan audit dapat meninggalkan akun/role/status/password yang sudah berubah.

Koreksi: audit wajib berhasil di dalam transaksi yang sama. Jalur create guru,
create/link pengurus dan orang tua memanggil audit sebelum commit repository;
grant/revoke/status/reset memakai transaksi layanan. Pencabutan perangkat pada
penonaktifan ikut transaksi. Lock perlindungan admin terakhir dipertahankan,
dan pembacaan role sebelum perubahan dipindah setelah lock.

Tes baru `perapihan_audit_account_log.php` menggunakan penulis audit yang sengaja
tidak tersedia (koneksi terpisah ditutup) serta penulis normal. Memeriksa rollback
akun/role/relasi/perangkat, pelaku, entitas, before/after, dan ketiadaan credential
pada semua jalur. Tidak memodifikasi tabel audit, data lama, atau skema untuk
simulasi kegagalan. Fixture sendiri dibersihkan setelah tes.

## A-13 — P1: pengaman klik ganda membuang tindakan tombol

K6-09/K6-12: handler submit bersama menonaktifkan tombol sebelum browser
membentuk body POST. Tombol bernama `action` tidak ikut terkirim; konfirmasi
yang dibatalkan juga meninggalkan tombol disabled. Reproduksi browser sebelum
koreksi: 2 lulus, 3 gagal (draft/open hilang, cancel mengunci tombol).

Koreksi: salin nama/nilai submitter yang dipilih ke input tersembunyi sebelum
disable; bila event sudah dibatalkan, jangan mengunci form. Guard, CSRF,
konfirmasi, dan validasi server tetap berjalan. Tes browser menangkap request
sebelum mencapai server (tidak memutasi data); sesudah koreksi 5 lulus, 0 gagal.

## A-16 — P1: daftar dampak rekonsiliasi dapat terpotong

K2-08: nama santri pada calon penggabungan dirangkai dengan GROUP_CONCAT.
Pada sesi sandbox `group_concat_max_len=1024`, hanya 11 dari 45 nama fixture
terbaca lengkap; 34 gagal. Konfirmasi dapat diberikan dengan daftar yang tidak
lengkap. Batas diubah hanya dalam sesi tes dan dipulihkan sesudahnya.

Koreksi: ambil relasi sebagai baris biasa, urutkan stabil, lalu bentuk teks
lengkap di PHP. ID anggota kelompok juga dibaca berdasarkan kunci kelompok,
bukan mengandalkan string agregasi yang dapat terpotong. Kontrak tampilan lama
dipertahankan. Tidak mengubah batas agregasi permanen, collation B-2, data lama,
atau keputusan identitas B-3/B-4. `perapihan_audit_wali_long_list.php`: 45 lulus,
0 gagal sesudah koreksi. Fixture terdiri dari 45 relasi sendiri dan dibersihkan
melalui manifest, bukan penghapusan data lama.

## A-14 — P2: kontras dan fokus kerangka bersama belum memadai

Pemeriksaan awal menemukan teks muted/topbar dan tombol kuning Bootstrap
berkontras rendah (contoh 4,39:1; 3,6:1; 1,5:1), tabel bergulir tidak dapat
menerima fokus, serta halaman B tanpa breadcrumb. Laci ponsel juga membiarkan
fokus masuk ke navigasi tersembunyi/latar halaman.

Koreksi pada Layout dan CSS: token kontras lebih kuat, adaptor kartu/tabel/
tombol/modal Bootstrap, tombol kecil 44 px, breadcrumb bawaan, hanya crumb
terakhir bertanda halaman aktif, wilayah tabel dapat digulir dengan keyboard,
fokus masuk/berputar/keluar laci melalui Tab/Shift+Tab/Escape, serta latar inert
selama laci terbuka. Adapter pesan kolom dan konfirmasi digunakan formulir A-15.
Semua tambahan tampilan Bootstrap dibatasi pada body.ah; halaman D tidak
didesain ulang. Makna badge/pesan tetap berupa teks, bukan warna saja.

Bukti akhir gabungan dengan A-15: inventaris 159 observasi A/B/D pada tiga lebar;
120 observasi A/B bebas pelanggaran axe pada aturan WCAG 2 A/AA dan 2.1 AA
yang dapat diautomasi. Ini tidak membuktikan seluruh WCAG atau pembaca layar.
Tes interaksi 60 lulus mencakup keyboard, modal terbuka, daftar wali panjang,
konfirmasi batal, dan prefers-reduced-motion. Pemeriksaan modal menunggu
animasi selesai sebelum membaca kontras/fokus agar hasil bukan warna transisi.

## A-15 — P2: validasi menghilangkan isian dan sebagian label tidak terhubung

Form lama kelas/pengurus/tahun belum menghubungkan label ke kolom. Pengurus,
kelas, tahun, penugasan dan akun menghilangkan isian setelah penolakan. Jadwal
menyimpan old-input tetapi tidak membacanya kembali. Pesan banyak formulir
hanya berupa alert di atas halaman, tidak di dekat kolom yang salah.

Koreksi presentasi: whitelist isian aman per formulir, label for/id, pesan
kolom dari kesalahan server, serta asosiasi aria-invalid/aria-describedby.
Password, token dan persetujuan berbahaya tidak dipulihkan. Filter laporan
dengan rentang terbalik mempertahankan tanggal/mode dan tidak menawarkan
CSV/cetak sampai valid. Pesan konflik lintas data tetap berada pada alert umum.

Portal hanya mendapat pemulihan isian dan tautan koreksi dengan konteks
pencarian/halaman; status HTTP gagal 403/409/422, CSRF, scope, validasi workflow,
idempotensi dan redirect sukses tidak diubah. Pengujian empat jenis aksi
perizinan menggunakan input sengaja invalid dan memastikan 422 sebelum mutasi.
Tidak mengubah layanan/alur perizinan V2 yang di luar paket.

Konfirmasi tindakan status/penugasan pada form A/B kini menjelaskan dampak
pada cakupan, kelayakan, dan pelestarian riwayat. Dialog admin, merge dan timpa
masih memerlukan persetujuan eksplisit server. Cancel tidak mengunci tombol
atau mengirim POST (A-13); tidak ada redirect yang melewati CSRF.

`perapihan_audit_form_feedback.php`: 108 lulus, 0 gagal. Cakupan: santri, wali,
relasi wali, guru, pengurus, kelas/keanggotaan, tahun, kamar, murobi, pembimbing,
jadwal, pertemuan, tiga jenis pembuatan akun, link akun, tiga kegagalan password,
filter laporan, pengajuan izin dan empat aksi detail izin. Kasus positif,
keadaan kosong, dan penolakan hak juga diperiksa ulang melalui regresi paket,
audit HTTP/kamar/pagination/laporan dan browser; tidak mengartikan lint sebagai
bukti keberhasilan transaksi.

### Pengulangan concurrency A-12

Run lanjutan sempat menghasilkan dua kegagalan alasan penolakan pencabutan diri
sendiri: bila orang lain lebih dahulu mencabut role, layanan menjawab "tidak
memiliki role" alih-alih larangan diri sendiri. Minimum admin tetap 1 pada
semua putaran; tidak terjadi nol admin. Pemeriksaan larangan diri dipindahkan
sebelum transaksi, seperti larangan menonaktifkan diri, tanpa melemahkan
assertion. Tiga pengulangan berikutnya masing-masing 13/13 lulus: total 36
putaran, lima proses mutasi (ditambah permintaan diri pada variasi terakhir),
minimum teramati 1. Tes audit atomik 36/36 diulang sesudahnya.

## A-17 — P2, PRA-ADA, MENUNGGU KEPUTUSAN: kata status PDF potret terpecah

Sembilan PDF nyata (Santri/Guru/Gabungan × CSS/lanskap/potret) dibaca kembali
per sel, bukan hanya dihitung barisnya. Isi data seluruh PDF sama dengan oracle
matriks. Akan tetapi ketiga mode potret memecah **Terlambat** menjadi
**Terlamba / t**. Kolom status terlalu sempit. Tidak ada data yang hilang,
sidebar tercetak, margin keluar, atau nomor halaman nol pada fixture ini.

`git diff c65390d -- app/Report/PrintRenderer.php` kosong: renderer ini identik
baseline, bukan regresi paket perapihan. Tidak diubah diam-diam. Pengguna telah
dimintai keputusan apakah mengizinkan koreksi tampilan kolom absensi. Dampaknya
pada layout cetak web dan HTML cetak yang ditampilkan client mobile; skema/data
API tidak perlu berubah, dan source mobile tidak perlu diedit. Jika disetujui,
perbaiki hanya renderer absensi, uji 45 pemeriksaan PDF tambahan dan 175 regresi
lama tanpa pengurangan assertion. Jika ditunda, cetak potret tetap memiliki
cacat tersebut dan paket belum dapat ditutup.

Bukti: `bukti-audit-14/pdf-matrix-final.log` (**42 lulus, 3 gagal**),
`gabungan-potret.pdf`/`potret-first.png`. Teks data dibandingkan setelah hanya
menghapus pemisah baris pada sel status; assertion TERPISAH tetap mewajibkan
kata status tidak terpotong. Pemisahan ini memastikan cacat tampilan tidak
hilang dari hasil meskipun nilai datanya sama. PDF lanskap pembanding:
`gabungan-lanskap.pdf`/`lanskap-last.png`.

## Lingkungan dan batas akses

- macOS 26.6.2 (25G83), PHP 8.4.14, MariaDB 12.3.2, Node 26.7.0,
  Playwright dari lockfile proyek, Chromium 151.0.7922.34.
- Database **webalhasan_ui_codex_20260830_test**, akun database sandbox;
  server localhost `127.0.0.1:8940`. Variabel lingkungan hanya pada proses.
  Tidak menggunakan akun/config produksi atau menjalankan migrasi produksi.
- `npm ci` browser memakai lockfile yang sudah ada; tidak upgrade dependency.
  axe-core 4.13.0 hanya di folder sementara auditor. PDF dibaca dengan
  pdfplumber dan Poppler runtime lokal; dependensi proyek/mobile tidak diubah.
- Aset publik Bootstrap 5.3.0 dan aset lama lain dicache lokal. Tiga URL
  DataTables mengembalikan 403 di lingkungan ini; aset ekuivalen dari paket npm
  **1.13.4** dipakai lokal. URL, sumber dan SHA-256 ada di `aset-uji.json`.
  Font lokal fallback mengikuti paket terkunci browser. Hasil ini tidak
  membuktikan ketersediaan CDN produksi.
- Mac terkunci saat akses Computer Use. Percobaan pemeriksaan Safari berikutnya
  **ditolak auto-review** karena berisiko membaca tab pribadi/produksi. Tidak
  dicari jalan memintasnya. Hanya browser sandbox terisolasi yang dilanjutkan.
  Safari/VoiceOver baru dapat dilanjutkan pada jendela audit localhost khusus
  yang dibuka pengguna, sesudah izin pemeriksaan terarah. Chromium/viewport
  bukan Safari atau perangkat iOS/Android fisik.
- Main tetap `c65390d`, mobile tetap `ab3f842` dan bersih. Pekerjaan hanya pada
  branch `codex/perapihan-v1-v2-ui`; tidak membuat worktree/salinan proyek,
  push, merge, deploy, WA, atau fitur V3.

## Hasil yang diperoleh ulang oleh auditor

Bukti mentah tersimpan di `bukti-audit-14/`; checksum seluruh bukti pada
`SHA256SUMS`. Angka berikut hasil run lanjutan ini, bukan salinan kelulusan lama.

| Rangkaian | Lulus | Gagal | Bukti |
| --- | ---: | ---: | --- |
| Regresi V1/V2, 28 berkas | 2.464 | 0 | `full-final.log` |
| Paket perapihan, 4 berkas | 248 | 0 | `full-final.log` |
| Browser resmi 1440/768/390 | 56 | 0 | `browser-final.log` |
| **Subtotal resmi** | **2.768** | **0** | Tidak berarti siap produksi |
| Audit sebelumnya: 9 berkas keamanan/data/HTTP/API/client | 171 | 0 | `final-redirect/wali/merge/admin/http/laporan_web/csv_limit/api_compat/notifikasi.log` |
| Kamar | 19 | 0 | `final-kamar.log` |
| Pagination | 45 | 0 | `final-pagination.log` |
| Audit transaksi akun A-12 | 36 | 0 | `final-account_log.log` |
| Form dan feedback A-15 | 108 | 0 | `forms-final.log` |
| Wali daftar panjang A-16 | 46 | 0 | `final-wali_long_list.log` |
| Matriks filter laporan, oracle independen | 432 | 0 | `report-matrix.log` |
| Inventaris UI: 159 observasi + nol pageerror | 160 | 0 | `ui-final.log`, `ui-final.json` |
| Interaksi keyboard/dialog/konfirmasi/motion | 60 | 0 | `interaksi.log` |
| Submitter dan cancel A-13 | 5 | 0 | `submit-final.log` |
| PDF matriks tambahan, 9 dokumen | 42 | **3** | `pdf-matrix-final.log`, A-17 |
| **Subtotal audit tambahan** | **1.124** | **3** | 235 lama + 889 tambahan baru |
| **Total tanpa menghitung ulang suite duplikat** | **3.892** | **3** | **Ada kegagalan terbuka; bukan lulus paket** |

175 pemeriksaan PDF lama sudah termasuk regresi 2.464, tidak dihitung lagi.
Tiga pengulangan audit admin masing-masing 13 lulus (36 putaran concurrency)
dihitung sekali pada subtotal 171, bukan ditambahkan tiga kali. Log yang sama
tersedia dalam beberapa nama arsip untuk penelusuran; tidak menggandakan angka.
Enam test print-dialog/tsc/lint mobile dari audit sebelumnya bukan hasil baru
pada tahap ini. Client mobile asli 18 operasi benar-benar dijalankan ulang,
dan source mobile tetap tidak berubah.

Selisih resmi terhadap angka implementer 2.478 tetap **+290**: 289 pemeriksaan
B-1 kini dihitung runner setelah keputusan terdahulu, ditambah satu assertion
guard laporan (71 menjadi 72). Runner sebenarnya memiliki 28 berkas regresi,
bukan 29. Tidak menyesuaikan test untuk memaksakan angka awal.

### Percobaan yang tidak dijadikan kelulusan

- Sebelum A-13: 2 lulus/3 gagal pada pengiriman tombol/cancel; sesudahnya 5/0.
- Sebelum A-16: 11/45 nama lengkap, 34 gagal pada batas 1 KiB.
- Rangkaian pertama menemukan satu assertion struktural audit akun gagal;
  pembungkus wajib A-12 diuji setara, alasan ada pada perubahan-pengujian §9.
- Form jadwal memang kehilangan isian; diperbaiki. Dua request tes awal kurang
  tepat (nilai waktu pelaksanaan dan idempotency key) dilengkapi, bukan
  mengubah validasi aplikasi agar menerima input yang tidak sah.
- Pembacaan matriks awal memakai fixture snapshot kurang lengkap dan parser
  HTML yang memasukkan catatan ke status. Fixture dilengkapi, SQL strict
  diaktifkan, parser memilih sel yang benar; oracle/filter tidak dilonggarkan.
- Pemeriksaan modal terlalu dini menangkap warna transisi/fokus sebelum event
  selesai; menunggu animasi dan pengembalian fokus menyelesaikan false failure.
- Browser resmi sempat dijalankan bersamaan dengan tes admin yang sementara
  mencabut role fixture sandbox, lalu timeout pada tab pengajian. Run itu tidak
  dihitung lulus; setelah concurrency selesai dan role pulih, browser dijalankan
  sendiri, 56/56. Tes yang berbagi fixture mutasi harus **berurutan**.
- Run audit HTTP awal 25 pemeriksaan belum mengaktifkan opt-in empat cek menu
  A-07. Diulang dengan `AUDIT_CHECK_OPEN_FINDINGS=1`: 29 lulus, tidak dihilangkan.
- Pemeriksaan A-17 tetap 3 gagal; tidak ditutupi oleh 175 regresi PDF lama.

## Penutupan 14 klaim dan yang masih harus diverifikasi

**12 dari 14 klaim kini TERVERIFIKASI pada cakupan bukti sandbox.** Seluruh
77 klaim dinilai satu per satu pada `penilaian-penerimaan-codex.md`: **75
TERVERIFIKASI, 0 TIDAK TERVERIFIKASI, 2 MENUNGGU VERIFIKASI**. Cacat A-17
memerlukan keputusan dan dicatat pada klaim cetak yang belum ditutup; angka
nol pada kolom "tidak terverifikasi" bukan berarti tidak ada cacat terbuka.

| Belum ditutup | Langkah lanjutan | Hasil yang wajib dibuktikan |
| --- | --- | --- |
| K6-14, pembaca layar/perangkat | Buka kunci Mac, buka jendela Safari khusus localhost dan izinkan pemeriksaan terarah; aktifkan VoiceOver. Coba login, laci, form invalid, modal admin, tabel gulir, dan peralihan tab. Android/iOS fisik dicatat terpisah bila tersedia. | Nama/role/status/error terbaca, urutan fokus masuk akal, tidak ada fokus terjebak/tersembunyi; tidak membaca tab pribadi/produksi. |
| K6-16, cetak lintas browser | Putuskan A-17; setelah koreksi disetujui, ulangi matriks PDF dan regresi 175. Simpan PDF Safari nyata untuk Santri/Guru/Gabungan pada A4 potret/lanskap dengan data panjang/multipage. | Status terbaca utuh; semua baris sesuai filter, margin aman, tanpa sidebar, jumlah fisik sama dengan nomor halaman, tidak ada halaman nol/hantu. |

Di luar dua klaim itu, migrasi/cron/smoke produksi sengaja tidak dilakukan;
B-2 collation, B-3 penyelesaian identitas data lama, dan B-4 akun wali ganda
masih merupakan keputusan manusia. Tidak ada data lama atau source mobile
"diperbaiki" untuk menutup audit ini.

### Hasil seluruh halaman D

13 rute × tiga lebar = 39 pemeriksaan dampak CSS. Tidak ada pageerror setelah
aset tersedia dan tidak ada overflow baru akibat CSS bersama. `admin_izin.php`
merupakan alias yang sampai ke portal; dinilai sesuai halaman tujuan aktual.
`admin_pelanggaran.php` pada 390 memiliki lebar 526 px dengan DAN tanpa CSS
bersama; sumber halamannya identik baseline. Temuan legacy label/lang/kontras
tersimpan dalam JSON. Itu bukan kelulusan aksesibilitas D dan bukan izin
mendesain ulang PSB/keuangan/konten. Hanya dampak CSS paket yang ditutup.

### Reproduksi aman

1. Ikuti sandbox pada `panduan-audit-codex.md` §1; database wajib `_test`.
   Siapkan fixture audit utama dengan `tests/perapihan_audit_fixture.php`,
   simpan lokasi sebagai `AUDIT_FIXTURE_MANIFEST`. Gunakan akun sandbox saja.
2. Tetapkan `PERAPIHAN_AUDIT_DB=1`, `BASE_URL=http://127.0.0.1:8940`,
   `AUDIT_UI_MANIFEST=/tmp/audit-14-ui.json`, kemudian jalankan
   `php tests/perapihan_audit_ui_fixture.php`. Dibuat 45 santri/relasi sendiri,
   dua wali kandidat, guru/pengurus tanpa akun, dan semester nonaktif.
3. Jalankan rangkaian resmi; lalu audit PHP tambahan satu per satu. Untuk HTTP
   gunakan `AUDIT_CHECK_OPEN_FINDINGS=1`; API kompatibilitas menerima snapshot
   baseline melalui `AUDIT_BASELINE_API_SNAPSHOT`. Concurrency dijalankan
   tersendiri, jangan bersamaan dengan browser atau tes lain pada database sama.
4. Matriks laporan: `AUDIT_REPORT_PDFS=/tmp/audit-14-pdf php tests/perapihan_audit_report_matrix.php`.
   Dengan Python yang memiliki pdfplumber dan Chromium/Playwright tersedia,
   jalankan `tests/perapihan_audit_report_pdf.py` menggunakan folder yang sama.
   Sampai A-17 diputuskan/dikoreksi, expected saat ini 42 lulus/3 gagal;
   expected penerimaan tetap 45 lulus/0 gagal.
5. Browser tambahan: `uji-audit-ui.mjs`, `uji-audit-interaksi.mjs`,
   `uji-audit-submit.mjs`. Atur `AXE_PATH` ke axe-core 4.13.0 yang disiapkan
   terpisah dan `AUDIT_CDN_CACHE` ke folder aset yang manifest/hash-nya mengikuti
   `aset-uji.json`; sumber DataTables tiga file berasal npm 1.13.4. Bootstrap,
   Font Awesome dan Playwright mengikuti lockfile browser, tanpa upgrade.
   Simpan log serta `OUT_DIR` di luar source. Jangan menganggap CDN yang gagal
   dimuat sebagai bukti fungsi JavaScript telah diuji.
6. Sesudah selesai, jalankan `php tests/perapihan_audit_ui_fixture.php cleanup`
   dengan manifest yang sama. Matriks/account/long-list memakai rollback atau
   cleanup miliknya sendiri. Fixture sandbox lama dari rangkaian historis tidak
   dihapus massal. Hentikan server audit sendiri; jangan hentikan proses pengguna.

Dokumen ini tidak mengesahkan produksi. Commit lokal menjadi titik berhenti;
**jangan push, merge, deploy, atau melanjutkan V3** dari hasil ini tanpa instruksi
terpisah dan penyelesaian butir penerimaan yang masih terbuka.

Penutupan lingkungan: fixture tambahan UI pada manifest audit telah dibersihkan
(`cleanup.log`); server PHP localhost milik auditor dihentikan. Fixture sandbox
historis tetap dipertahankan. Lint 32 berkas PHP yang berubah/ditambah lulus;
Pemeriksaan whitespace kode/dokumen bersih. Log browser mentah mempertahankan
sepuluh peringatan trailing whitespace dari teks tab HTML; bukti tidak diedit
untuk menghilangkan peringatan tersebut. Tidak ada diff pada layanan perizinan, notifikasi,
API, migrasi, dependency PHP, atau assertion B-1 selama lanjutan ini.
