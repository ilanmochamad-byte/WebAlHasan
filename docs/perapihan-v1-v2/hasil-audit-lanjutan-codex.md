# Hasil audit lanjutan Codex — keputusan 30 Agustus 2026

> **Pembaruan verifikasi 14 klaim:** 12 klaim tambahan telah diverifikasi; K6-14 dan K6-16 masih menunggu perangkat/cetak, dan temuan cetak pra-ada A-17 menunggu keputusan. Lihat [audit-14-klaim.md](audit-14-klaim.md) dan matriks penerimaan terkini. Ini bukan izin rilis.

> **Riwayat sebelum penyelesaian kamar/pagination.** Tiga klaim tersisa pada
> dokumen ini kemudian dituntaskan sesuai keputusan pengguna; hasil terkini
> ada di [audit-kamar-pagination.md](audit-kamar-pagination.md). Angka dan status
> awal di bawah dipertahankan sebagai rekam audit.

Lanjutan dari [audit awal](hasil-audit-codex.md), setelah keputusan pengguna atas
B-1, A-06, A-07, dan pertanyaan kompatibilitas API. Audit awal beserta hasil yang
gagal tetap disimpan sebagai riwayat. Dokumen ini menggantikan status terbuka
**empat butir tersebut**, bukan mengesahkan seluruh paket atau produksi.

## 1. Hasil dan batas pekerjaan

| Butir | Tingkat / keadaan awal | Koreksi dan hasil verifikasi | Commit lokal |
| --- | --- | --- | --- |
| B-1 | Pra-ada; tujuh assertion notifikasi gagal pada baseline | Audit fungsi dahulu: daftar, filter, pagination, detail, tandai satu/semua, unread, pergantian akun, dan 401 masih tersedia. Assertion mengikuti layar/header baru dan tetap memeriksa fungsi. 289 statis dan 18 operasi client mobile lulus. | `3253917` (tambahan uji laporan client pada `47e9370`) |
| A-06 | Sedang; CSV 20.001 baris diterima | Maksimal 20.000 setelah filter/cakupan. Lebih dari batas menghasilkan 422 `EXPORT_TOO_LARGE`, tanpa CSV parsial; ukuran halaman tidak dapat melewatinya. Empat uji batas lulus. | `0a75524` |
| A-07 | Sedang; menu guru menuju halaman 403 | Laporan web baca-saja dibuka bagi admin/guru berelasi; guru hanya datanya sendiri. Empat halaman memakai guard laporan khusus; master data tetap admin-only. Menu notifikasi web mengikuti kemampuan perizinan. 38 uji laporan dan 29 uji HTTP lulus. | `afed1bb` |
| A-09 | Sedang; ketidakpastian kontrak akibat metadata API tambahan | Skema API dipulihkan ke baseline. `subject_scope` hanya untuk web; API tetap gabungan. 13 uji API termasuk perbandingan seluruh JSON tanpa parameter dengan snapshot main identik. | `47e9370` |

Pemilihan A-09 adalah rekomendasi auditor untuk kekhawatiran pengguna: aplikasi
yang sudah terpasang tidak perlu memahami field baru, dan rilis web tidak
mengharuskan rilis mobile bersamaan. Client mobile `ab3f842` memang tidak
mengirim/membaca `subject_scope`. Tidak ada perubahan sumber mobile, URL API,
format token, atau dependency. Ini bukan jaminan seluruh fungsi perangkat telah
diuji secara fisik. Rincian keputusan dan perubahan pengujian ada di
[keputusan lanjutan](keputusan-lanjutan-audit.md).

## 2. Lingkungan dan keadaan Git

- Tanggal eksekusi: 30 Agustus 2026, WIB; kode akhir yang diuji `47e9370`.
- Branch `codex/perapihan-v1-v2-ui`, melanjutkan audit awal `01d136e`.
- Main web tetap `c65390dd03c4da1ddaacf9d3da9adf4293848c40`.
- Mobile tetap main `ab3f84224308aabaa56c8300455f6673d5549bde`, bersih.
- PHP 8.4.14, MariaDB 12.3.2, Node 26.7.0, Playwright 1.62.1,
  Chromium revisi cache 1234; Poppler 26.05.0 untuk pemeriksaan PDF.
- Database khusus `webalhasan_ui_codex_20260830_test`, migrasi 001–010 dan
  fixture sintetis audit awal. Akun SQL dibatasi pada database ini.
- Environment proses dari berkas privat di `/tmp`, bukan mengubah `.env`
  proyek/produksi. Server localhost `127.0.0.1:8940`; router audit sementara
  melayani API dan direktori portal sekaligus.
- Tidak membuat worktree/salinan proyek, tidak menjalankan Claude bersamaan,
  tidak push/merge/deploy, tidak menjalankan migrasi produksi, tidak mengaktifkan
  WhatsApp. Data lama dan bukti fase historis tidak dihapus atau diubah.

## 3. Angka pengujian auditor sendiri

| Rangkaian | Lulus | Gagal akhir | Bukti |
| --- | ---: | ---: | --- |
| Regresi V1/V2, 28 berkas | 2.464 | 0 | `bukti-audit-lanjutan/full-final.log` |
| Paket perapihan, 4 berkas | 248 | 0 | log yang sama |
| Browser resmi 1440/768/390 | 56 | 0 | `browser-final.log` |
| **Subtotal rangkaian resmi** | **2.768** | **0** | Bukan putusan siap produksi |
| Redirect A-01 | 36 | 0 | `redirect-final.log` |
| Wali A-02 | 16 | 0 | `wali-final.log` |
| Merge bersamaan A-03 | 4 | 0 | `merge-final.log` |
| Admin: 12 putaran, lima proses, empat variasi + pemulihan fixture | 13 | 0 | `admin-final.log` |
| HTTP guard, alias, form, menu | 29 | 0 | `A-07-http-final.log` |
| Laporan web guru dan isolasi peran | 38 | 0 | `A-07-scope-final.log` |
| Batas CSV 20.000/20.001 | 4 | 0 | `A-06-final.log` |
| Kontrak API dan baseline JSON | 13 | 0 | `A-09-final.log` |
| Client TypeScript mobile asli: notifikasi + laporan | 18 | 0 | `B-1-client-final.log` |
| **Subtotal uji audit tambahan, 9 berkas PHP** | **171** | **0** | Terpisah dari rangkaian resmi |
| Mobile test print-dialog | 6 | 0 | `mobile-print.log` |

Pemeriksaan TypeScript mobile dan lint mobile selesai exit 0. Lint PHP untuk
15 berkas yang berubah sejak audit awal selesai tanpa kesalahan. Tidak ada
perubahan source mobile setelah pemeriksaan tersebut. Peringatan Node mengenai
jenis modul pada test print-dialog tidak mengubah enam hasil tes; dependency
tidak diubah untuk menghilangkan peringatan.

**Selisih terhadap acuan implementer 2.478 adalah +290**, bukan penyesuaian angka
agar sama. Rinciannya: runner kini menghitung seluruh berkas B-1 yang lulus
(289, sebelumnya dikeluarkan dari subtotal karena ada kegagalan), ditambah satu
assertion bersih pada guard laporan Fase 1 (71 → 72). Jumlah berkas regresi yang
benar-benar dijalankan adalah 28, bukan klaim awal 29. Angka 289 statis yang juga
tersedia sebagai log terpisah **tidak dijumlahkan lagi**. Enam print-dialog dan
171 audit tambahan tidak termasuk 2.768.

## 4. Bukti perilaku dan batasnya

- API `/reports` tanpa parameter pada fixture guru yang sama menghasilkan
  gabungan 31 baris dan seluruh envelope/payload identik dengan snapshot
  `main c65390d` yang diambil pada audit awal. Parameter `subject_scope` apa pun
  diabaikan seperti baseline; pilihan filter API tidak menambah field web.
- Client mobile asli dijalankan melalui TypeScript transpile di Node, mengganti
  hanya transport Expo dengan fetch lokal serta penyimpanan token in-memory.
  Ini mengeksekusi metode client/server, **bukan** render React Native, build
  rilis, push fisik, atau Safari/iOS.
- Guru memperoleh 30/1/31 pada layar, CSV, dan cetak untuk scope yang sama;
  `teacher_id` asing + scope guru tetap 403, detail pertemuan guru lain 403,
  jadwal asing tidak membocorkan peserta, POST laporan 405. Pengurus/orang tua
  tetap 403 untuk laporan ini. Master data/JSON wali tetap khusus admin.
- Minimum admin aktif teramati selalu 1 pada 12 putaran lima proses: revoke,
  nonaktif, campuran, serta pencabutan diri bersamaan pencabutan oleh orang
  lain. Ini pengujian proses nyata, bukan pembuktian semua kemungkinan urutan.
- Browser interaktif tambahan: guru membuka menu laporan dan tab Gabungan pada
  390 px; lebar dokumen 390, satu H1, pilihan guru hanya dirinya, tanpa menu
  notifikasi web yang tidak berhak. Bukti `guru-browser-390.json` dan
  `guru-report-390.png`; tidak dihitung sebagai assertion suite resmi tambahan.
- Pemeriksaan PDF 175 dalam regresi benar-benar berjalan dengan Chromium dan
  Poppler. Bukti Safari macOS pada audit awal tetap sebatas yang tercatat di
  sana; tidak ada bukti PDF Safari tersimpan atau perangkat iOS baru.

### Percobaan yang tidak dijadikan kelulusan

Uji browser pertama terhenti pada tab Pengajian ukuran ponsel, ketika uji
concurrency admin masih berlangsung dan dapat mencabut/menonaktifkan akun
fixture. Log `browser-interferensi.log` dipertahankan. Korelasi ini tidak
dianggap pembuktian akar penyebab tunggal. Setelah uji admin selesai dan
fixture dipulihkan, **skrip browser yang sama tanpa perubahan assertion**
dijalankan sendirian: 56/56 lulus. Suite ini mengabaikan galat pemuatan CDN;
hasilnya tidak membuktikan keandalan CDN pada produksi. Screenshot tambahan
menunjukkan CSS ter-render dalam browser interaktif lokal.

Persiapan uji CSV awal pernah salah memakai router API-only, dan dua ekspektasi
baru auditor diperbaiki (fallback filter notifikasi dan pembacaan angka dari
DOM cetak). Rincian ada di keputusan lanjutan; bukan perubahan perilaku aplikasi
agar cocok dengan uji. Upaya mengambil daftar tab browser pengguna ditolak
auto-review karena dapat mengungkap tab pribadi; audit memakai tab sandbox baru
tanpa menginventarisasi tab lain. Tidak ada pekerjaan yang bergantung padanya.

## 5. Penilaian penerimaan dan verifikasi tersisa

Penilaian 77 klaim terkini ada di
[penilaian-penerimaan-codex.md](penilaian-penerimaan-codex.md):
**60 TERVERIFIKASI, 3 TIDAK TERVERIFIKASI, 14 MENUNGGU VERIFIKASI**.
K5-09, K5-10, dan K7-11 berubah menjadi terverifikasi pada cakupan bukti di atas.
A-07 sudah selesai; K6-03 masih tidak terverifikasi secara menyeluruh karena
bagian klaim konsistensi kerangka kamar belum dipenuhi (A-08).

| Sisa | Langkah selanjutnya | Expected result |
| --- | --- | --- |
| K6-03/K6-17: kamar masih legacy, inventaris sudah dikoreksi | Putuskan apakah modernisasi kamar harus dituntaskan dalam paket ini atau ruang lingkup penerimaannya direvisi secara eksplisit; jangan menandai klaim lama lulus | Tidak ada klaim inventaris/kerangka yang melebihi implementasi |
| K6-10: klaim pencarian/pagination seluruh daftar terlalu luas | Putuskan cakupan daftar besar; uji kelas/tahun dan daftar lain dengan data banyak | Navigasi daftar memenuhi ruang lingkup yang benar-benar disepakati |
| 14 klaim parsial | Jalankan langkah pada matriks penerimaan: semua mutasi hak/audit, form/konfirmasi, seluruh filter, aksesibilitas, reduced motion, dan seluruh halaman D | Bukti tiap klaim lengkap, bukan menyimpulkan semua dari satu sampel |
| Safari/iOS/Android nyata | Uji login, navigasi, laporan, cetak/PDF tersimpan; notifikasi in-app dan pergantian akun pada mobile fisik | Tidak ada overflow, kebocoran sesi/data, atau halaman/nomor cetak hilang |
| B-2/B-3/B-4 | Verifikasi collation dan data wali secara baca-saja pada tahap prarilis; identitas konflik diputus admin per kasus | Tidak ada normalisasi skema atau penggabungan identitas otomatis |
| Lingkungan cPanel dan migrasi 010 | Rehearsal pada DB `_test` dengan versi PHP/MySQL setara hosting, backup/restore terverifikasi, lalu persetujuan rilis terpisah | Kolom/ID/data lama utuh, migrasi idempoten, jalur API/mobile tetap bekerja |

**Paket belum dinyatakan lulus atau siap produksi.** Keputusan empat butir
lanjutan tidak otomatis menyelesaikan klaim UI dan kesiapan operasi di atas.
[Panduan push dan rilis](panduan-push-dan-rilis-cpanel.md) memisahkan pengiriman
branch untuk review dari perubahan produksi.

## 6. Reproduksi aman

Ikuti penyiapan di `panduan-audit-codex.md` untuk database `_test`. Environment
server dan pengujian harus sama. Router sementara audit menyalurkan URL
`/api/v1/*` ke `api/v1/index.php` dan mengembalikan `false` untuk berkas/direktori
web lain, sehingga `/portal/` tetap dilayani server PHP. Jangan memakai router
API-only untuk tes login web. Jangan membuka server ini ke jaringan publik.

Jalankan runner resmi dan browser sesuai panduan, **secara bergantian**, bukan
bersamaan dengan tes yang mengubah akun. Kemudian jalankan tes audit dengan
`PERAPIHAN_AUDIT_DB=1`, `MOBILE_APP_ROOT` menuju repo mobile, dan
`AUDIT_FIXTURE_MANIFEST` menuju manifest dari `perapihan_audit_fixture.php`.
Gunakan `AUDIT_CHECK_OPEN_FINDINGS=1` untuk semua pemeriksaan menu HTTP.
`AUDIT_BASELINE_API_SNAPSHOT` opsional menambahkan perbandingan JSON baseline
pada tes API (13 dengan snapshot, 12 tanpa snapshot). Snapshot harus berasal
dari baseline pada data identik; jangan membuatnya dari respons branch audit.

Seluruh log yang dirujuk berada di `bukti-audit-lanjutan/`. Tidak menyertakan
credential, cookie, token, environment privat, atau dump database. Server audit
dihentikan setelah pengujian; database dan fixture sintetis tetap tersedia.
Log browser disimpan mentah, termasuk spasi akhir hasil pembacaan label tab;
peringatan whitespace Git pada log itu bukan kegagalan kode/dokumen. Pemeriksaan
whitespace kode dan Markdown lulus. Hash berkas bukti ada di `ringkasan.json`.
