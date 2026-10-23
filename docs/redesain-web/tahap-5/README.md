# Redesain website Tahap 5 — pembinaan (23 Oktober 2026)

Status: **diimplementasikan Claude, menunggu audit independen Codex.** Belum merge/deploy/migrasi produksi. Tahap 6 belum dimulai.

- Branch `claude/redesain-web-tahap-5` dibuat dari `origin/main` `492fdd3`, yang sudah menggabungkan audit Tahap 4 `2520c48` (isi pohon identik). Tidak ada worktree.
- Sumber: `AGENTS.md`, `rencana.md`, `keputusan.md`, `cetak-biru.html` (V-1 s.d. V-7, M-4, peta menu, baris Tahap 5 "Urutan tahap pengerjaan", temuan keputusan Z), `tahap-4/AUDIT-CODEX.md`.
- Cakupan keputusan: **F** (nama menu orang tua) dan **Z** (hak akses data orang tua: nama pembimbing pengirim).
- Kriteria selesai: *tidak ada isian ID; kasus Rahasia tetap tersembunyi; orang tua hanya bertambah melihat nama pembimbing.*

## Hasil terhadap kriteria

| Kriteria | Bukti |
| --- | --- |
| Tidak ada isian ID | Laporan V-6: "ID santri/kelas/kamar/pembimbing/murobi" diganti pencarian nama santri (`q`) dan pilihan bernama kelas, kamar, pembimbing, murobi (dua terakhir hanya admin). Server H5-D: 18 alamat pada empat akun tanpa `<input>` ber-nama `*_id` yang diketik; browser T5-A memeriksa hal yang sama di 3 lebar. "Santri #id", "wali #id", "Pelanggaran #id", "Kasus #id", "Sesi #id" diganti nama/tanggal. |
| Kasus Rahasia tetap tersembunyi | Server H5-B: pembimbing lain (sbx_pengurus_b, kini juga membina Kamar A) dan murobi tidak membuka detail/cetak (403), tidak melihat kartu/baris di papan, daftar, laporan, atau tindak lanjut detail pelanggaran, dan **angka** papan/laporan/ringkasan tidak menghitungnya; admin dan pembimbing pemilik melihatnya. Pratinjau publikasi kasus Rahasia ditolak server 422 tanpa baris pratinjau; tombol publikasi nonaktif dengan sebab dari layanan. Browser T5-D memeriksa papan empat akun. |
| Orang tua hanya bertambah melihat nama pembimbing | `PublikasiService` menambah **satu** kunci `pengirim` pada DTO orang tua (web dan REST): nama pembimbing pemilik sumber, atau "Pesantren Al Hasan". Server H5-C: kunci REST tepat allowlist lama + `pengirim`, tanpa isi internal/ID pembimbing; fallback diuji; wali lain 403. Judul "Informasi pembinaan · tanggal", nama anak (dari relasi wali akun sendiri), jenis sumber tidak ditampilkan (H5-C, T5-G). |

## Perubahan hak akses dan data (keputusan Z)

- `PublikasiRepository::senderNames()` (baru) membaca **hanya** `pengurus.nama` lewat `pembimbing_id` pelanggaran / kasus / kasus-dari-sesi untuk ID publikasi yang sudah lolos penyaring relasi wali. Penyaring SQL orang tua `parentFrom()`…`parentPage()` tetap tidak membaca tabel sumber (penjaga statis V3 Fase 4 dan S5-3 tetap lulus).
- `PublikasiService::parentRows()` = `dto()` + `pengirim`. `KonselingService::parentSerializer()` (allowlist Fase 3) tidak diubah.
- Nama anak di V-7 dibaca dari `izin_service()->santriInScope(…, orang_tua)` — sumber yang sama dengan Beranda dan Izin anak — bukan kolom baru.
- `parentPage()` menerima saringan `santri_id` dan `baca=belum` yang hanya mempersempit baris milik wali sendiri (REST ikut menerimanya secara aditif).
- Tidak ada perubahan skema/migrasi, kapabilitas, atau penjaga akses lain. Staf: `manage()` menambah `santri_nama`/`wali_nama`, `options()` menambah `santri_nama`/`pengirim`, detail pelanggaran menambah `jenis`/`pencatat`/`ambang` — semuanya aditif, hanya untuk akun yang sudah berhak membaca sumber.

## Perubahan per layar

| Layar | Berkas | Isi |
| --- | --- | --- |
| V-1 Pelanggaran | `portal/v3_pelanggaran.php`, `PelanggaranRepository::page/statusCounts/pageWhere` | Judul "Pembinaan santri", chip status berangka (cakupan + saringan sama), cari santri, periode (Semua waktu/Pekan/Bulan/Semester/Rentang). Tabel Santri · Waktu · Jenis · Tingkat (label berwarna) · Poin · Status, kartu di HP. "Catat pelanggaran" di **panel samping**; jenis dikelompokkan per kategori dan menampilkan tingkat/poin; JS menampilkan "Total poin santri setelah catatan ini" dari angka server (`data-total`). |
| V-2 Detail | `portal/v3_pelanggaran_detail.php` | Judul = jenis; tab Ringkasan · Poin santri · Lampiran n · Konseling n · Riwayat. Kolom kanan: total poin + jumlah catatan berlaku, meter terhadap ambang katalog (nama & rentang dari M-4), rekomendasi (belum/sudah ditindaklanjuti). Tindakan dalam dialog: Koreksi, **Batalkan catatan** (merah, "Poin n akan dikurangkan…"), Tandai mengetahui (murobi). "Agregat · ledger · selisih" hilang; selisih hanya diperingatkan ke admin. Publikasi aktif hanya bila layanan publikasi menerima sumber; selain itu nonaktif dengan sebabnya (mis. bertaut kasus Rahasia). |
| V-3 Konseling | `portal/v3_konseling.php`, `KonselingRepository::statusCounts/pageWhere` | Segmen **Papan/Daftar**. Papan Dibuka · Dalam pendampingan · Selesai (maks. 6 kartu, "+n lainnya"), tanda Rahasia/Internal; tanpa seret-lepas. Daftar dengan chip status. "Buka kasus" = **wizard 2 langkah** di panel: (1) pilih santri (GET), (2) tujuan, kerahasiaan, waktu, tautan pelanggaran/rekomendasi **santri itu saja** (POST). Baris "Rekomendasi belum ditindaklanjuti" (tujuan kartu Beranda AA) punya tombol "Buka kasus" yang mencentang rekomendasinya. |
| V-4 Detail kasus | `portal/v3_konseling_detail.php`, `portal/v3_konseling_cetak.php` | Tab Sesi n · Tautan · Catatan murobi · Riwayat. Setiap sesi = kartu bernomor; "Catat hasil sesi" membuka dialog yang hanya menampilkan isian relevan untuk hasil terpilih (`data-ah-when`): Selesai → waktu, ringkasan, hasil, tindak lanjut; Tidak hadir → waktu; Dijadwalkan ulang → jadwal baru + alasan; Dibatalkan → alasan. Kolom kanan: Jadwalkan sesi, Mulai pendampingan / Tutup kasus, Batalkan kasus, Koreksi kasus (semua dialog beralasan), publikasi (nonaktif + sebab untuk Rahasia), pelanggaran terkait. Cetak tanpa nomor ID. |
| V-5 Publikasi (kelola) | `portal/v3_publikasi_kelola.php`, `PublikasiRepository::managePage`, `portal/partials/v3_publikasi_konten.php` | Tanpa parameter: **daftar** semua publikasi dalam cakupan (Santri · Penerima · Sumber · Status Terbit/Ditarik · Dibaca), chip & cari. Dari sumber: wizard Penerima → Isi → Pratinjau; pratinjau persis seperti dilihat wali (judul, santri, pengirim) dengan "berlaku 30 menit (sampai jam)"; "Kembali ubah isi" dan "Buat pratinjau ulang" memakai isian yang sama. Kelola satu publikasi: isi, riwayat, Tarik (dialog beralasan). |
| V-6 Laporan | `portal/v3_laporan.php`, `LaporanService::build/ringkasan/filterOptions/penempatan` | Segmen Pelanggaran · Kasus konseling · Santri mencapai ambang. Saringan Periode (bawaan Semester ini; alamat lama bertanggal = Rentang sendiri), Kelas, Kamar, Santri (cari nama) + Filter lanjutan (tanggal, status, tindak lanjut, kategori, tingkat, poin, pembimbing/murobi untuk admin). Ringkasan Catatan · Total poin · Kasus berjalan · Santri ≥ ambang dihitung lewat `build()` yang sama dengan daftar. Tabel dengan kolom Kelas/Kamar bernama, kartu di HP; batas ekspor hanya disebut bila terlampaui. Orang tua: rekap informasi dengan nama anak. |
| V-7 Informasi pembinaan | `portal/v3_publikasi.php` | Pilihan anak (chip) bila > 1, tab **Belum dibaca · Semua**; daftar "Informasi pembinaan · tanggal" + "Anak · dari Nama (pembimbing)". Detail: Santri, Dari, Ringkasan, Tindak lanjut di rumah, "Saya sudah membaca". |
| M-4 Katalog & poin | `admin/admin_v3_katalog.php`, `KatalogService::history/recent` | Di bawah Pembinaan › Katalog & poin (admin). Segmen Kategori · Jenis pelanggaran · Ambang poin; tabel Kode · Nama · Kategori · Tingkat · Poin · Masa berlaku · Status; tambah/ubah di panel samping dengan alasan; riwayat per data dan "Riwayat perubahan terbaru" ditulis sebagai kalimat ("Nilai maksimum: — → 29 · Alasan: …", pelaku bernama), bukan JSON. |

Komponen bersama baru: `ah_tingkat()`, `ah_stepper()` (`app/Ui/functions.php`); kamus status Dicatat/Ditindaklanjuti/Dalam pendampingan/Dijadwalkan/Dijadwalkan ulang/Tidak hadir; JS `[data-ah-when]`, `[data-ah-poin-preview]`; CSS `.ah-steps`, `.ah-board`, `.ah-meter`, `.ah-big`, `.ah-pub`, `.ah-check-list`, `.ah-cell-wrap`. Peta menu: orang tua "Informasi pembinaan" bertab Belum dibaca · Semua (rekap laporan di bawahnya); murobi "Pembinaan binaan" bertab Pelanggaran · Konseling · **Laporan**.

## Penafsiran dan batas cakupan — mohon dinilai auditor

1. **Pengirim (Z).** "Pembimbing pemilik sumber" = `pembimbing_id` pada pelanggaran (pencatat) atau kasus (juga untuk publikasi dari sesi). Pelanggaran yang dicatat tanpa pembimbing (mis. data lama) → "Pesantren Al Hasan". Nama diambil dari master pengurus saat dibaca, bukan snapshot saat terbit; bila nama pengurus diubah, informasi lama ikut menampilkan nama baru.
2. **Nama anak (Z).** Dibaca dari daftar anak akun sendiri (relasi wali aktif), sehingga satu-satunya data baru bagi orang tua tetap nama pengirim. Bila daftar anak tidak dapat dibaca, tertulis "Anak Anda".
3. **REST orang tua** ikut memuat `pengirim` (layanan yang sama). Aplikasi mobile tidak diubah dan tidak diuji; kunci aditif ini diabaikan aplikasi lama.
4. **V-1 "Tempat\*"** tetap opsional seperti aturan layanan sekarang (gambar menandainya wajib); tidak mengubah validasi server.
5. **V-1 total poin setelah catatan** = total semester tersimpan + poin jenis, dihitung di peramban dari angka server; tanpa JavaScript petunjuk tidak tampil. Total sebenarnya tetap dihitung layanan saat menyimpan.
6. **V-2 tab.** Tab berupa tautan `?tab=` (tanpa JavaScript tetap berfungsi). "Catatan berlaku" = baris poin positif yang belum dibalik. Meter memakai ambang aktif tahun ajaran catatan; tanpa ambang tertulis "Belum ada ambang poin".
7. **V-2/V-4 ⋯ menu.** Batalkan catatan/kasus memakai tombol dialog merah di kolom Tindakan (pola I-5 Tahap 4), bukan butir di menu ⋯, agar alasan tetap dapat diisi tanpa JavaScript.
8. **V-3 wizard.** Langkah 1 hanya memilih santri (GET, tanpa isi sensitif); tujuan dan kerahasiaan berada di langkah 2 (POST) agar tidak masuk alamat. Gambar menaruh tujuan di langkah 1.
9. **V-3 papan** memuat 25 kasus terbaru per kolom dan menampilkan 6; angka kolom dari hitungan penuh. Kasus Dibatalkan tidak punya kolom; tautannya ke Daftar.
10. **V-4 isian bersyarat** menonaktifkan isian tersembunyi sehingga tidak terkirim; layanan memakai nilai tersimpan untuk isian yang tidak dikirim (aturan K5/K6 lama). Tanpa JavaScript semua isian tampil berlabel kapan dipakai — perilaku sama dengan sebelumnya.
11. **V-5 wizard** Penerima dan Isi berada pada satu formulir (bernomor 1 dan 2); Pratinjau langkah 3. Tidak ada status draf (aturan sekarang).
12. **V-6 Periode bawaan "Semester ini"** untuk staf; orang tua dan laporan ambang bawaan "Semua waktu" (ambang tidak menerima periode). Ringkasan: Catatan & Total poin tanpa yang dibatalkan; Kasus berjalan = Dibuka/Dalam pendampingan; Santri ≥ ambang = semester aktif dan hanya ikut saringan santri/kelas/kamar. Saringan status dan tindak lanjut tidak memengaruhi ringkasan.
13. **V-6 Kelas/Kamar** di tabel = penempatan **tahun ajaran aktif** santri (kolom tampilan), bukan snapshot saat kejadian. CSV dan respons REST `/v3/laporan` **tidak berubah bentuk** (masih memuat kolom ID untuk pengolahan lanjutan); yang dihapus adalah *isian* ID.
14. **V-6 pembimbing/murobi** sebagai pilihan hanya untuk admin; bagi pembimbing/murobi cakupannya sudah dibatasi penugasan. Alamat lama dengan `santri_id`/`kelas_id` tetap berlaku (catatan "dibatasi pada satu santri" + tautan menghapus).
15. **Murobi tab Laporan.** Peta menu cetak biru menulis Pelanggaran · Konseling untuk "Pembinaan binaan", tetapi V-6 menyebut murobi sebagai pengguna laporan; tab Laporan ditambahkan agar halaman yang sudah boleh dibaca murobi punya jalan masuk.
16. **Fixture.** Seed Tahap 5 menambah penugasan pembimbing sbx_pengurus_b atas Kamar A (khusus DB Tahap 5) untuk menguji Rahasia terhadap pembimbing lain dalam cakupan yang sama. Total poin SBX Santri A1 = 15 karena catatan fixture Tahap 2 dimasukkan langsung tanpa baris poin; layanan konsisten (selisih 0).

## Pengujian yang disesuaikan

| Uji | Perubahan |
| --- | --- |
| `v3_phase4_integration` | Allowlist DTO orang tua kini `…,'status','pengirim'` (keputusan Z); tetap memeriksa tanpa isi PRIVATE dan tanpa `pembimbing_id`. |
| `redesain_web_tahap2_http` F-4 | Rekomendasi: baris membuka wizard dengan rekomendasi tercentang (formulir tidak lagi di halaman daftar). Teks jumlah "n baris sesuai saringan"; laporan ambang tetap `Total poin`. |
| `redesain_web_tahap2_audit` (probe Codex) | Sama dengan butir rekomendasi di atas. |

Baseline sebelum Tahap 5: `v3_phase2_static` 68/1 dan `v3_phase3_static` 102/1 gagal pada label navigasi lama ("Data pelanggaran warisan", `'v3.konseling'`) yang sudah diganti sejak Tahap 2; hasil sesudah Tahap 5 identik dan tidak diklaim lulus.

## Bukti

Lingkungan: PHP 8.4, Node 26, MariaDB 12 lokal, Chromium Playwright. DB: `tests/redesain_web_tahap5_db.sh` membangun salinan Tahap 4 bernama `test_redesain_t5_test` + fixture sintetis `tests/redesain_web_tahap5_seed.php` (semua lewat layanan V3: katalog, ambang beralasan, pelanggaran, kasus Internal dan Rahasia, sesi, publikasi terbit/dibaca/ditarik).

| Bukti | Hasil | Berkas |
| --- | --- | --- |
| `php tests/redesain_web_tahap5_static.php` | **96/0** | `uji-statis.txt` |
| `php tests/redesain_web_tahap5_http.php` (server sendiri, REST nyata) | **106/0** | `uji-server.txt`, `persiapan-db.txt` |
| `node tests/browser/uji-redesain-web-tahap5.mjs` (28 layar-akun × 375/768/1280 + interaksi) | **115/0** | `uji-browser.txt`, `tangkapan/` |
| Regresi V3 (13 suite di DB sandbox V3), Tahap 1–4 (HTTP, probe Codex, browser), statis 20 suite, smoke web & kontrak API V2 Fase 5, lint 468 berkas | lulus (kecuali dua baseline V3 di atas) | `regresi.txt` |

## Belum dijalankan / batas

- Hanya Chromium. Safari/iOS, Firefox, perangkat fisik, pembaca layar, dan axe belum dijalankan.
- Aplikasi mobile tidak diuji (REST orang tua bertambah `pengirim`; layar publikasi mobile tidak diubah).
- Tidak diulang: suite migrasi V3 (`v3_phase*_migration`), uji browser lama V3 (`uji-v3-fase*.mjs`, sudah tidak sesuai kerangka sejak Tahap 1–3), integrasi V1/V2/V4 yang tidak menyentuh pembinaan, banding status baseline per alamat.
- Kinerja papan/laporan pada data besar tidak diukur selain `v3_phase5_performance` (6/0). Tidak ada smoke cPanel/produksi.

## Handoff ke auditor (Codex)

Mohon audit Tahap 5 terhadap V-1 s.d. V-7, M-4, keputusan F dan **Z** (perubahan data orang tua — uji server wajib), kriteria selesai di atas, dan butir "Penafsiran dan batas cakupan". Jalankan dari root repo:

```bash
bash tests/redesain_web_tahap5_db.sh
php tests/redesain_web_tahap5_static.php
php tests/redesain_web_tahap5_http.php
bash tests/redesain_web_tahap5_db.sh
DB_NAME=test_redesain_t5_test php -S 127.0.0.1:8981 tests/redesain_web_tahap1_router.php &
BASE_URL=http://127.0.0.1:8981 node tests/browser/uji-redesain-web-tahap5.mjs
DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1 php tests/v3_phase4_integration.php
```

Uji HTTP dan browser mengubah data uji; bangun ulang DB sebelum mengulang. Tahap 6 tidak dimulai sebelum Tahap 5 lulus audit dan ada instruksi Human Developer.
