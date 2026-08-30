# Hasil audit Codex — Koreksi dan Modernisasi UI/UX V1–V2

> **Pembaruan verifikasi 14 klaim:** 12 klaim tambahan telah diverifikasi; K6-14 dan K6-16 masih menunggu perangkat/cetak, dan temuan cetak pra-ada A-17 menunggu keputusan. Lihat [audit-14-klaim.md](audit-14-klaim.md) dan matriks penerimaan terkini. Ini bukan izin rilis.

Tanggal: 30 Agustus 2026, WIB. Implementer: Claude. Auditor: Codex.

> **Arsip hasil audit awal.** Keputusan pengguna dan koreksi berikutnya untuk
> B-1/A-06/A-07/API tercatat di [hasil-audit-lanjutan-codex.md](hasil-audit-lanjutan-codex.md).
> Angka dan status di bawah adalah keadaan sebelum keputusan lanjutan, bukan
> status terkini empat butir tersebut. Tidak mengubah hasil pengujian historis.

**Belum dinyatakan lulus atau siap produksi.** Lima cacat telah dikoreksi, satu
inventaris diperbaiki, dan beberapa temuan/keputusan serta verifikasi perangkat
masih terbuka. Hasil ini bukan pengganti penerimaan pengguna atau izin rilis.

Penilaian **seluruh 77 klaim** pada `status-penerimaan.md` ada di
[penilaian-penerimaan-codex.md](penilaian-penerimaan-codex.md). Klaim implementer
asli dipertahankan; hasil audit tidak mengubah bukti kelulusan historis Fase 1–5.

## 1. Batas audit dan lingkungan

- Titik awal implementasi: `afd580f`, branch `codex/perapihan-v1-v2-ui`.
  Baseline web: `c65390dd03c4da1ddaacf9d3da9adf4293848c40`.
- Checkout awal berada pada `main` yang bersih; auditor berpindah ke branch
  tersebut sebelum implementasi. Tidak membuat worktree/salinan proyek.
- Untuk membuktikan B-1 dan membandingkan API, checkout yang sama sementara
  berpindah ke `main` dalam keadaan bersih, hanya untuk uji baca, lalu kembali.
  Tidak ada implementasi/commit pada `main`.
- Mobile: `/Users/ilanmochamad/alhasanApps`, `main`,
  `ab3f84224308aabaa56c8300455f6673d5549bde`, tanpa perubahan sumber.
- PHP 8.4.14; MariaDB 12.3.2; Node 26.7.0; Playwright lokal 1.62.1;
  Chromium cache revisi 1234 (PDF melaporkan Chromium/Skia m151).
- Database **baru** `webalhasan_ui_codex_20260830_test`, hanya struktur dump V1
  tanpa INSERT, migrasi 001–010, fixture peran V2 Fase 3 dan fixture Fase 5 1.000
  baris. Ditambah fixture sintetis audit 30 santri/1 guru. Bukan data produksi.
- Akun SQL audit baru hanya diberi hak database tersebut. `.env` proyek yang
  sudah ada tidak ditulis ulang. Environment proses disimpan di berkas lokal
  mode 600 di `/tmp/webalhasan-ui-audit/env.sh`, **tidak di-commit**.
- HTTP PHP sandbox: `http://127.0.0.1:8940`; akun browser hanya `sbx_*`.
  Tidak memakai akun produksi, tidak mengaktifkan WhatsApp, tidak mengirim
  notifikasi/perubahan ke layanan eksternal.
- Poppler 26.05.0 ditemukan pada runtime bundled Codex. Tautan alat lokal di
  `/tmp/webalhasan-ui-audit/tools` ditambahkan pada PATH pengujian akhir.
  Tidak melakukan upgrade dependency.
- Safari **aplikasi macOS nyata** tersedia dan dipakai melalui UI. Itu berbeda
  dari Chromium serta skenario CSS bernama `safari` pada suite PDF otomatis.
- Lima dokumen handoff dibaca dalam urutan yang diminta, berikut AGENTS.md,
  PRD-V2.md dan riwayat Git. Claude dinyatakan berhenti oleh handoff pengguna;
  auditor tidak menjalankan agen implementer lain secara bersamaan.

## 2. Hasil pengujian yang diperoleh auditor

Bukti yang disimpan berada di [bukti-audit-codex](bukti-audit-codex/). Log adalah
hasil eksekusi audit, bukan salinan angka implementer. File rahasia, cookie,
token, dan berkas pembuatan akun SQL tidak dimasukkan.

| Rangkaian | Hasil akhir | Bukti |
| --- | --- | --- |
| Runner regresi V1/V2 | **2.174 pemeriksaan lulus**, 1 berkas gagal dengan 7 assertion B-1 | `full-final.log` |
| Paket perapihan (4 berkas) | **248 lulus**, 0 gagal | `full-final.log` |
| Browser 1440/768/390 px | **56 lulus**, 0 gagal | `browser-after.log` |
| Subtotal sesuai cara hitung runner implementer | **2.478 lulus**, tetap ada kegagalan B-1 | Bukan pernyataan seluruh suite lulus |
| Audit tambahan: redirect/wali/merge/admin/HTTP/fixture | **103 lulus**, 3 gagal A-07 | Rincian di bawah |
| Audit batas CSV >20.000 | **1 gagal A-06**: HTTP 200 dan 20.001 baris | `csv-limit.log` |
| PDF otomatis | **175 lulus**, sudah termasuk 2.174; tidak dihitung dua kali | `pdf-final.log` |
| Sintaks PHP berubah dari baseline | 77 berkas, 0 galat | `php-lint.txt` |
| Mobile tsc / lint | Keduanya exit 0; tanpa perubahan sumber | `mobile-tsc.log`, `mobile-lint.log` |
| Unit mobile print-dialog | 6 lulus, 0 gagal; terpisah dari subtotal web | `mobile-print.log` |
| Migrasi sandbox | 001–010 diterapkan; pemanggilan `up` lagi tidak menerapkan apa pun | `migrations-final.log` |

**Selisih terhadap handoff:** runner yang diperiksa memanggil **28 berkas
regresi**, bukan 29: 27 berkas lulus dan 1 gagal. Jumlah 2.174 tepat, tetapi
jumlah berkas pada handoff tidak tepat. Runner hanya menjumlahkan assertion pada
berkas dengan exit 0; 279 assertion yang lulus di dalam berkas B-1 pada pengujian
baseline tidak dimasukkan ke subtotal runner. Jangan membaca 2.478 sebagai jumlah
seluruh assertion yang dieksekusi atau sebagai tidak adanya kegagalan.

Dua eksekusi penuh awal menghasilkan **2.075**, yaitu kurang 99 karena alat
Poppler belum berada pada PATH (bagian PDF hanya 76 pemeriksaan dan sisanya
menunggu). Setelah menemukan alat bundled, auditor menjalankan ulang bagian PDF
serta **seluruh runner**: PDF 175 dan regresi 2.174. Tidak ada assertion yang
diubah untuk menyamakan angka. `full-before.log` menyimpan hasil awal tersebut.

Pengujian tambahan yang ditambahkan auditor:

| Berkas | Lulus | Gagal | Cakupan |
| --- | ---: | ---: | --- |
| `tests/perapihan_audit_redirect.php` | 36 | 0 | 2 base path, traversal/encoding/null/backslash/skema/URL panjang, tujuan sah |
| `tests/perapihan_audit_wali.php` | 16 | 0 | Konflik nomor HP, nama berspasi ganda, nilai kosong, audit penimpaan |
| `tests/perapihan_audit_merge.php` | 4 | 0 | Dua merge berlawanan menunggu lock yang sama; tepat satu boleh berhasil |
| `tests/perapihan_audit_admin.php` | 13 | 0 | 12 putaran revoke/nonaktif/campuran/diri sendiri; pemulihan fixture |
| `tests/perapihan_audit_http.php` dengan flag temuan terbuka | 26 | 3 | 403/404/CSRF 419, POST lama, data wali, double-submit, menu A-07 |
| `tests/perapihan_audit_fixture.php` | 8 | 0 | 30/1/31, isolasi guru, relasi bertabrakan, active_guard, kandidat merge, kunci asing |
| `tests/perapihan_audit_csv_limit.php` | 0 | 1 | 20.001 baris CSV nyata; hasil gagal dipertahankan |

Pengujian tambahan tidak disisipkan ke runner lama sehingga angka acuan lama
masih dapat dibandingkan. Flag temuan terbuka sengaja tidak menyamarkan kegagalan.
Tidak semua kombinasi input/perangkat telah diuji; cakupan per klaim dijelaskan
pada matriks audit.

## 3. Temuan dan koreksi

P1 = risiko integritas data tinggi; P2 = fungsi/kontrak penting; P3 = tampilan atau
ketepatan dokumentasi. Tingkat ini tidak menyatakan eksploitasi produksi terjadi.

### A-01 — P2: redirect menerima path tidak kanonik — DIKOREKSI

`SafeRedirect` sebelumnya masih menerima path PHP dengan segmen terenkode,
backslash, atau `./`, misalnya `/admin/%2e%2e/evil.php` dan
`/admin/%00admin_akun.php`. Uji awal: 12 dari 36 pemeriksaan gagal.
Tidak terbukti sebagai pembacaan file atau pengalihan eksternal yang berhasil;
temuannya adalah validasi tujuan yang terlalu longgar.

Koreksi `dbc0e79`: batasi path ke satu nama berkas PHP di `/admin/` atau
`/portal/`; query dan prefix instalasi sah tetap didukung. 36/36 lulus sesudah
koreksi. Otorisasi tetap dilakukan tujuan, bukan oleh sanitizer.
Bukti: `A-01-before.log`, `A-01-final.log`.

### A-02 — P1: nomor HP lama tertimpa tanpa konfirmasi/audit — DIKOREKSI

`MasterDataService::mirrorParent()` sebelumnya langsung menulis nomor HP apabila
nama lama sama dengan nama wali. Wali tanpa nomor HP dapat mengosongkan nomor
lama tanpa persetujuan. Nama lama kosong tetapi nomor lama terisi juga lolos.
Normalisasi nama dapat menghilangkan bentuk nilai lama dari jejak audit.
Uji awal 9 gagal; setelah koreksi 16/16 lulus.

Koreksi `6057c65`: periksa pasangan nama/HP, tuntut konfirmasi bila nilai lama
nonkosong berubah, audit nilai sebelum/sesudah yang sebenarnya, dan rollback bila
audit gagal. POST palsu kolom ayah/ibu pada formulir web ditolak; jalur impor
legacy tetap dipertahankan. Uji HTTP akhir membuktikan POST palsu tidak membuat
santri. Tidak menghapus kolom atau mengganti kebijakan rekonsiliasi data produksi.
Bukti: `A-02-before.log`, `A-02-final.log`, `http-final.log`.

### A-03 — P1: merge wali bersamaan dapat membentuk siklus — DIKOREKSI

Validasi status/akun dilakukan sebelum lock. Dengan request A→B dan B→A yang
keduanya sudah melewati validasi, keduanya dapat berhasil dan mengarsipkan kedua
wali. Uji mengunci pasangan di induk, memastikan kedua worker menunggu lock lewat
processlist, lalu melepasnya: invariant gagal sebelum koreksi.

Koreksi `ac9ac8f`: ambil lock dalam transaksi dahulu, baru baca ulang serta
validasi kedua wali dan keterkaitan akunnya. Sesudah koreksi tepat satu merge
berhasil, satu wali tetap aktif; 4/4 lulus. Kebijakan B-4 tidak dilonggarkan.
Bukti: `A-03-before.log`, `A-03-final.log`.

### A-04 — P2: isian wali hilang dan konfirmasi konflik tersembunyi — DIKOREKSI

Formulir santri hanya memulihkan kolom santri; mode/nama/HP/alamat wali hilang
ketika validasi gagal. Panel konfirmasi timpa bergantung konflik relasi lama,
sehingga pemilihan wali baru/berbeda bisa menuntut konfirmasi yang tidak tersedia.

Koreksi `db2a4d3`: simpan hanya kolom wali yang dikenal, pulihkan mode dan pilihan
termasuk kandidat di luar 200 pertama, tampilkan nomor lama dan panel konfirmasi
untuk mode pilih/baru. Checkbox persetujuan **tidak dicentang kembali otomatis**.
Uji HTTP membuktikan pemulihan; interaksi browser membuktikan panel dapat dibuka.

Catatan transparansi pengujian: `A-04-before.log` juga berisi tiga kegagalan uji
buatan auditor yang semula mengharapkan CSRF 403. Kontrak `Csrf` sebenarnya 419.
Hanya ekspektasi **uji baru auditor** itu dikoreksi ke 419; bukan cacat produk dan
bukan perubahan tes historis. Dua kegagalan isian wali adalah cacat sebenarnya.
Bukti: `A-04-before.log`, `A-04-after.log`, `http-final.log`.

### A-05 — P3: judul H1 ganda pada halaman adaptor B — DIKOREKSI

Adaptor Layout menambah judul padahal isi lama sudah memiliki H1, terlihat pada
halaman master/perizinan/detail. Koreksi `457a388` menambah opsi `show_heading`
dan menonaktifkan judul Layout hanya pada jalur sukses yang sudah punya judul.
Jalur error tetap mendapat heading. Guard dan layanan perizinan tidak diubah.

Pada 390 px, sembilan halaman B yang diuji ulang memiliki tepat satu H1 dan tidak
melebar. Dua halaman detail juga dibuka dengan fixture nyata. Bukti DOM:
`B-D-390.json`, `A-05-after.json`. Ini bukan klaim seluruh variasi halaman sudah
lulus aksesibilitas.

### A-06 — P2: CSV absensi tidak menolak 20.001 baris — TERBUKA, KEPUTUSAN PENGGUNA

Uji HTTP mengembalikan **200 text/csv dengan 20.001 baris data**, bukan 422
`EXPORT_TOO_LARGE`. Baris tambahan dibuat dengan tag audit dan dibersihkan oleh
uji; tidak menyentuh data lama. Pemeriksaan kode baseline menunjukkan
`ReportService::exportRows()` sudah tidak mempunyai batas ini pada `c65390d`.
Batas 20.000 yang diuji suite Fase 5 terdapat pada **laporan perizinan**.
Tidak mengklaim sudah menjalankan ulang fixture 20.001 pada baseline.

Karena ini ketidaksesuaian klaim terhadap perilaku pra-ada, perubahan perilaku
belum dilakukan. Pilihan: (a) setujui penerapan batas 20.000 juga untuk absensi,
dengan 20.000 diterima dan 20.001 ditolak; atau (b) pisahkan sebagai pekerjaan
lanjutan, dan klaim penerimaan batas ekspor paket ini tetap tidak terverifikasi.
Bukti: `csv-limit.log`, uji `perapihan_audit_csv_limit.php` (commit `6e4209a`).

### A-07 — P2: menu guru tidak sesuai guard tujuan — TERBUKA, KEPUTUSAN PENGGUNA

HTTP nyata membuktikan:

| Akun | Menu terlihat | Tujuan | Aktual |
| --- | --- | --- | --- |
| Guru non-murobi | Ya | Laporan Kehadiran | 403 |
| Murobi | Ya | Laporan Kehadiran | 403 |
| Guru non-murobi | Ya | Notifikasi Saya | 403 |
| Murobi | Ya | Notifikasi Saya | 200 |

`Navigation` menawarkan laporan kepada guru, tetapi halaman laporan/detail/CSV/
cetak web tetap memakai guard admin. Matriks implementer menyebut laporan guru
milik sendiri dapat diakses; itu tidak sesuai kode maupun hasil HTTP. Guard admin
laporan berasal dari baseline, sedangkan penawaran menu berasal dari paket ini.
Tidak ditemukan pembukaan data guru lain melalui masalah ini.

Pilihan: (a) pertahankan hak baseline, tampilkan menu hanya bila tujuan benar-benar
diizinkan serta koreksi matriks; atau (b) pengguna mengesahkan akses web laporan
baca-saja bagi guru dengan cakupan sendiri, lalu seluruh list/detail/CSV/cetak
harus diaudit ulang bersama. Notifikasi tetap mengikuti kemampuan perizinan,
kecuali ada keputusan terpisah untuk memperluasnya. Auditor tidak melonggarkan
guard secara sepihak. Bukti: `http-final.log`, commit uji `e473354`.

### A-08 — P3: inventaris dan jumlah berkas uji tidak tepat — DOKUMENTASI DIKOREKSI

`admin_kamar.php` disebut memakai `_master_ui.php`, padahal masih memakai markup
lama dan `sidebar.php`. Inventaris dikoreksi melalui `da0675e`; modernisasi kamar
belum terbukti. Pada 390 px halaman tidak melebar, tetapi bukan berarti menu
ponselnya setara dengan Layout baru. Selain itu, hitungan 29 berkas regresi pada
handoff berbeda dari 28 panggilan runner; rincian hitungan ada di §2.

Klaim menyeluruh lain juga terlalu luas: keberadaan `ah_old_keep` bukan bukti
semua form mempertahankan input, dan keberadaan helper pagination bukan bukti
semua daftar memakainya (misalnya kelas/tahun memakai daftar penuh). Matriks
penerimaan membatasi kesimpulan sesuai bukti, bukan mengesahkan klaim statis itu.

## 4. Pemeriksaan risiko lain

### Pengganti guard yang dihapus

| Asal | Tujuan/pengganti | Bukti perilaku |
| --- | --- | --- |
| `admin_jadwal_ngaji.php` | `admin_pengajian.php`: sesi admin/guru; POST tab jadwal hanya admin; CSRF | Guru POST jadwal 403, jumlah jadwal tetap; POST lama CSRF salah 419 tanpa redirect |
| `pertemuan_pengajian.php` | Modul pengajian + pembatasan cakupan layanan | Regresi pertemuan dan detail jadwal asing 403 |
| `admin_akun_perizinan.php` | `admin_akun.php` + `_guard.php` | POST lama CSRF salah 419 tanpa redirect; akun tetap admin-only |
| `admin/sidebar.php` | Pemanggil legacy mempertahankan `_guard.php` | Navigasi bukan penentu otorisasi; guard pada halaman tujuan |
| Isi perizinan di `portal/index.php` | Dipindah `izin_ringkasan.php` → `_ui.php` → `_guard.php` | Beranda guru 200; fungsi perizinan guru non-murobi tetap 403 |

Pencarian penggunaan `Navigation::` hanya menemukan Layout/sidebar, bukan layanan
hak akses. `get_wali_json.php` 403 untuk guru, murobi, pengurus dan orang tua.
Tiga partial `AH_PARTIAL` 404 baik anonim maupun admin yang mengakses langsung.
Tujuan redirect internal yang tidak berhak tetap ditolak guard setelah login/
pergantian akun (PM-9). Rantai alamat lama diuji PM-8/PM-10, tanpa loop.

### Admin terakhir

Uji concurrency paket (7 pemeriksaan) diulang pada tiga eksekusi penuh. Uji
tambahan menjalankan tiga putaran masing-masing revoke, nonaktif, campuran, dan
pencabutan diri sendiri bersama pencabutan oleh akun lain, dengan lima target
admin (varian terakhir menambahkan worker diri sendiri). Dalam 12 putaran,
minimum teramati dan hasil akhir selalu **1**, tidak pernah 0; penolakan tindakan
diri sendiri tetap berlaku. Polling tiap 10 ms ditambah hasil akhir bukan bukti
matematis semua interleaving, tetapi menguji invariant dengan proses nyata dan
menguatkan pemeriksaan transaksi/lock. Fixture admin dipulihkan setelah uji.

### Data wali dan laporan

- Merge relasi santri/hubungan sama: satu relasi diarsipkan, relasi lain dipindah;
  ID relasi dan generated `active_guard` pada relasi aktif tetap konsisten.
- Wali yang sudah digabungkan tidak muncul pada pencarian kandidat.
- Kunci wali tak dikenal tidak membuat relasi; kunci tambahan tidak dipakai untuk
  memperluas hak. Kolom ayah/ibu lama tetap ada dan jalur impor lama tetap diuji.
- Fixture 30 santri/1 guru menghasilkan ringkasan/detail/ekspor 30/1/31.
- API `/api/v1/reports` tanpa parameter pada **database yang sama** di branch
  audit dan baseline menghasilkan summary/items/pagination/schedules identik,
  total 31. `teacher_id` orang lain ditambah `subject_scope=guru` tetap 403.
- Ada perbedaan skema aditif yang harus disebut terang: `filters.subject_scope`
  dan `active_filters.Penyajian` muncul di respons branch; kode opsi juga menambah
  `subject_scopes`. Jadi default lama tidak berubah, tetapi pernyataan literal
  “subject_scope sama sekali tidak masuk kontrak API” **tidak terbukti benar**.
  Rencana implementer memang menyebut aditif. Jika batas pengguna dimaksudkan
  sebagai kesamaan skema persis, perlu keputusan untuk mengisolasi fitur ini dari
  respons API; jangan menyebut hasil perbandingan ini identik seluruh payload.
  Bukti ringkas: `api-comparison.json`; snapshot lokal di `/tmp/webalhasan-ui-audit`.

### Tampilan, PDF, dan mobile

Browser otomatis 56 pemeriksaan pada 1440/768/390 px lulus. Pemeriksaan tambahan
memprioritaskan B: pengurus, kelas, tahun, pembimbing, izin/antrean/buat/laporan/
notifikasi, lalu kedua detail. Sampel D: dashboard, data pendaftar, rekap keuangan,
dan pengecualian kamar. Data DOM 390 px tidak menunjukkan overflow halaman pada
sampel tersebut. Seluruh halaman D dan seluruh jenis formulir belum diuji.

PDF otomatis benar-benar dirender Chromium dan dibaca Poppler: 175 pemeriksaan.
Sampel halaman pertama PDF absensi 41 halaman juga diperiksa visual: tanpa
sidebar, tabel terbaca, footer “Halaman 1 dari 41”. Ini bukan pemeriksaan visual
manual semua halaman/PDF dan bukan bukti Safari.

Safari macOS nyata: login sandbox, beranda dan perpindahan Jadwal/Pertemuan
berfungsi; cetak laporan fixture gabungan membuka pratinjau **4 halaman**, tanpa
sidebar. Dialog Save PDF tidak merespons simpan/batal melalui alat UI; berkas PDF
Safari tidak terbentuk. Pengguna diberi tahu agar menutup dialog manual bila
masih terlihat. Margin, footer dan seluruh halaman PDF Safari tetap menunggu.
Tidak mengendalikan atau mengubah tab produksi yang kebetulan sudah terbuka.

Mobile hanya pemeriksaan kompatibilitas API, tsc, lint, dan enam unit test print.
Tidak ada pengujian aplikasi pada perangkat fisik, build rilis, atau perubahan
sumber mobile. Ini tidak membuktikan notifikasi perangkat berfungsi kembali.

## 5. B-1 sampai B-4: tidak diperbaiki diam-diam

- **B-1:** tujuh kegagalan benar-benar direproduksi pada `main c65390d` dengan
  mobile `ab3f842` yang sama (`B-1-main.log`). Layar baru terdapat di
  `src/app/notifikasi/index.tsx`; assertions masih menunjuk path lama. Pengguna
  perlu memilih pembaruan assertion web dengan cakupan setara, atau audit fungsi
  mobile terpisah sebelum perubahan. Auditor tidak mengubah assertion B-1.
- **B-2:** perbedaan collation lama tetap dicatat; tidak melakukan ALTER untuk
  menyeragamkan produksi. Uji migrasi/rekonsiliasi dengan kolasi target pada
  sandbox yang disiapkan manusia masih diperlukan.
- **B-3:** kandidat rekonsiliasi data lama bukan identitas yang otomatis boleh
  digabung. Admin manusia perlu memverifikasi hubungan/identitas satu per satu.
- **B-4:** pasangan duplikat yang melibatkan akun sumber tetap diblokir oleh
  kebijakan merge. Migrasi akun/hubungan login harus mendapat keputusan pengguna,
  bukan konsekuensi otomatis rekonsiliasi. Koreksi A-03 hanya memperbaiki urutan
  lock dan validasi; tidak mengubah kebijakan ini.

## 6. MENUNGGU VERIFIKASI dan langkah lanjut

| Butir | Langkah lanjut | Hasil yang diharapkan |
| --- | --- | --- |
| B-1 | Putuskan path assertion vs audit fungsi mobile; jalankan ulang suite terkait | 7 assertion memiliki pengganti perilaku setara, tanpa menghilangkan coverage |
| A-06 | Putuskan batas absensi; uji 20.000/20.001 pada web dan kontrak yang disepakati | 20.001 → 422 EXPORT_TOO_LARGE bila batas disetujui; semua filter tetap sepakat |
| A-07 | Putuskan batas hak laporan guru; uji seluruh menu lima peran dan akun multi-peran | Tidak ada menu operasional 403; tidak ada perluasan akses di luar keputusan |
| Skema API aditif | Tegaskan apakah default kompatibel cukup atau skema harus persis baseline | Jika harus persis, scope web tidak menambah field/parameter API; jumlah tetap gabungan |
| Safari macOS lengkap | Tutup dialog tertahan, cetak ulang A4 portrait/landscape, simpan PDF lokal | Semua baris/kolom terbaca, footer/margin/nomor halaman benar, tanpa sidebar |
| Safari iOS & browser Android fisik | Login fixture di perangkat pada sandbox yang aman; form/tabel/menu/cetak | Tanpa overflow, keyboard tidak menutup aksi penting, cetak konsisten |
| Aksesibilitas | Axe/kontras serta VoiceOver/TalkBack dan keyboard manual pada login/A/B/403/dialog | Label/fokus/urutan baca dan kontras memenuhi standar yang disepakati |
| Form dan pagination menyeluruh | Uji validasi gagal setiap form; isi daftar besar pada semua halaman relevan | Isian aman kembali; pencarian/pagination tersedia dan dapat dipakai |
| Kelompok D dan kamar | Periksa semua halaman inventaris, breakpoint dan menu, tanpa merombak alur PSB/keuangan | CSS/menu bersama tidak merusak fungsi atau navigasi lama |
| B-2/B-3/B-4 | Pengguna/admin tentukan penanganan collation/identitas/akun pada sandbox | Data dan riwayat utuh; tidak ada perluasan akses tanpa konfirmasi |
| Migrasi produksi/rollback | Hanya manusia setelah persetujuan terpisah; dry-run pada DB *_test dengan struktur target | Backup dapat dipulihkan, migrasi aditif, smoke test lolos; audit ini tidak mengeksekusi produksi |

## 7. Mengulang pengujian audit

Environment lokal audit disiapkan terpisah dari `.env`; jangan menyalin credential
ke dokumentasi. Semua uji DB tambahan menuntut opt-in dan nama database `_test`.
Server lokal harus memakai environment yang sama dengan proses pengujian.
Server audit milik sesi ini dihentikan setelah pengujian; database dan fixture
sintetis dipertahankan. Untuk uji HTTP/browser ulang, buka terminal terpisah,
muat environment audit lalu jalankan `php -S 127.0.0.1:8940 -t .` dari root web.

```bash
source /tmp/webalhasan-ui-audit/env.sh
export PATH="/tmp/webalhasan-ui-audit/tools:$PATH"
bash bin/perapihan_run_all_tests.sh
BASE_URL=http://127.0.0.1:8940 node tests/browser/uji-perapihan.mjs

export PERAPIHAN_AUDIT_DB=1
export AUDIT_FIXTURE_MANIFEST=/tmp/webalhasan-ui-audit/fixture.json
php tests/perapihan_audit_redirect.php
php tests/perapihan_audit_wali.php
php tests/perapihan_audit_merge.php
php tests/perapihan_audit_admin.php
# Fixture dijalankan hanya sekali; manifest yang ada mencegah duplikasi.
php tests/perapihan_audit_fixture.php
AUDIT_CHECK_OPEN_FINDINGS=1 php tests/perapihan_audit_http.php
php tests/perapihan_audit_csv_limit.php
```

Expected saat dokumen ini ditulis: runner exit 1 karena B-1; uji HTTP dengan flag
terbuka exit 1 karena tiga A-07; uji CSV exit 1 karena A-06. Jangan mengubah
expected result menjadi 200 hanya agar rangkaian tampak lulus. Uji fixture akan
melewati pembuatan bila manifest sudah tersedia; delapan assertion tercatat saat
pembuatan pertama, bukan ketika manifest digunakan kembali.

Audit dihentikan setelah commit lokal dokumentasi. Tidak merge, push, deploy,
memulai PRD V3, atau menyatakan paket siap produksi. Temuan yang menuntut keputusan
pengguna sengaja tetap terbuka.
