# Status penerimaan: Penempatan Kelas & Kamar Santri

Keputusan pengguna 6 September 2026. Branch `feat/penempatan-santri`, baseline
`main` `3b53c1c`.

Setiap klaim di bawah disertai buktinya. Klaim tanpa bukti ditulis sebagai
**MENUNGGU VERIFIKASI**, bukan TERPENUHI. Audit Codex tidak dilakukan atas
pilihan pengguna; ini adalah penilaian implementer sendiri dan harus dibaca
demikian.

Singkatan bukti:
`PS` = `tests/penempatan_static.php`,
`PN` = `tests/penempatan_integration.php`,
`KP` = `tests/penempatan_concurrency.php`,
`PW` = `tests/penempatan_web_smoke.php`,
`BP` = `tests/browser/uji-penempatan.mjs`.

---

## A. Navigasi dan alamat

| # | Klaim | Status | Bukti |
|---|-------|--------|-------|
| A-1 | Menu admin **Master Data → Penempatan Kelas & Kamar** tersedia | TERPENUHI | PS-5, PW-2d, BP-1a |
| A-2 | Kunci navigasi baru `master.penempatan` dipakai halaman dan pemetaan skrip | TERPENUHI | PS-5, PS-4 |
| A-3 | Menu aktif dan breadcrumb tampil benar | TERPENUHI | PW-2d, PW-2e, BP-1b, BP-1c |
| A-4 | Tombol menuju penempatan dari Data Santri | TERPENUHI | PS-14, PW-10e, BP-10e |
| A-5 | Tombol menuju penempatan dari Data Kelas, dengan filter kelas | TERPENUHI | PS-14, PW-10b/10d, BP-10d |
| A-6 | Tombol menuju penempatan dari Data Kamar dan daftar penghuni, dengan filter kamar | TERPENUHI | PS-14, PW-10a/10c, BP-10a/10b/10c |
| A-7 | Alamat lama `admin/admin_santri.php` tetap berfungsi untuk GET | TERPENUHI | PS-6, PW-5a/5b, BP-11a/11b |
| A-8 | Filter lama (`cari`, `jk`, `sekolah`, `filter_status`) dipetakan ke parameter baru | TERPENUHI | PS-6, PW-5b, BP-11b |
| A-9 | POST lama **tidak** dialihkan secara buta | TERPENUHI | PS-6, PW-6a/6b/6c |

## B. Tampilan dan fungsi halaman

| # | Klaim | Status | Bukti |
|---|-------|--------|-------|
| B-1 | Memakai `master_header()`/`master_footer()` dan komponen bersama | TERPENUHI | PS-4 |
| B-2 | Identitas tahun ajaran/semester aktif tampil | TERPENUHI | BP-2 |
| B-3 | Pencarian nama atau NIS | TERPENUHI | PS-12, PW-2f, BP-3a |
| B-4 | Filter jenis kelamin | TERPENUHI | PS-12, BP-3d |
| B-5 | Filter unit sekolah | TERPENUHI | PS-12, BP-12a (kontrol ada dan dapat dicapai) |
| B-6 | Filter kelas | TERPENUHI | PS-12, PW-10b, BP-10c |
| B-7 | Filter kamar | TERPENUHI | PS-12, PW-10a, BP-10c |
| B-8 | Filter belum mempunyai kelas | TERPENUHI | PS-12, BP-3b |
| B-9 | Filter belum mempunyai kamar | TERPENUHI | PS-12, BP-3c, PN-19 |
| B-9b | Filter "nonaktif/arsip tetapi masih berkamar" | TERPENUHI | PN-20 |
| B-10 | Pagination server (bukan seluruh santri dimuat ke peramban) | TERPENUHI | PS-12, BP-9a/9b |
| B-11 | Penempatan individual | TERPENUHI | PN-1, PN-4, BP-4a/4b |
| B-12 | Pemilihan beberapa santri | TERPENUHI | BP-5a |
| B-13 | Penempatan massal | TERPENUHI | PN-8, PW-7e/7f, BP-5c |
| B-14 | Jumlah santri terpilih ditampilkan | TERPENUHI | PS-12, BP-5a |
| B-15 | Kapasitas kamar saat ini dan sisa tempat ditampilkan | TERPENUHI | PS-12, BP-8, BP-6c |
| B-16 | Konfirmasi sebelum perpindahan massal | TERPENUHI | PW-7a, BP-6a/6b |
| B-17 | Hasil berhasil/gagal jelas | TERPENUHI | PW-7g, PW-9b, BP-4a, BP-7a/7b |
| B-18 | Keadaan kosong dan keadaan "tidak ada hasil pencarian" | TERPENUHI | PS-12, BP-3e |
| B-19 | Pilihan lintas halaman berperilaku jelas; santri tersembunyi tidak pernah ikut | TERPENUHI | PS-12, BP-5b |
| B-20 | Seluruh keluaran data di-escape | TERPENUHI | PS-11 |
| B-21 | Formulir dan tindakan dapat dipakai dengan papan ketik dan berlabel jelas | TERPENUHI | PS-12, BP-12a/12b/12c/12d |
| B-22 | Responsif desktop, tablet, dan ponsel | TERPENUHI | BP-13a/13b/13c pada 1440/768/390 px |

## C. Aturan penempatan kelas

| # | Klaim | Status | Bukti |
|---|-------|--------|-------|
| C-1 | Satu penempatan kelas aktif per santri per tahun ajaran | TERPENUHI | PN-17 (+ `UNIQUE` basis data sejak migrasi 002), KP-4 |
| C-2 | Perpindahan menyelesaikan penempatan lama dan membuat yang baru | TERPENUHI | PN-2 |
| C-3 | Tanggal mulai dan tanggal selesai tersimpan | TERPENUHI | PN-1, PN-2 |
| C-4 | Memakai `MasterDataService`/repository terpusat | TERPENUHI | kode: `PenempatanService::terapkanKelas()` memanggil `MasterDataRepository::membershipAssign()`/`membershipEnd()` |
| C-5 | ID dan riwayat lama dipertahankan | TERPENUHI | PN-2, PN-3 |
| C-6 | Hanya santri, kelas, dan tahun aktif serta tidak diarsipkan | TERPENUHI | PN-11 |
| C-7 | Mengeluarkan dari kelas berkonfirmasi dan beraudit (alasan wajib) | TERPENUHI | PN-3, PN-7 (alasan), PS-9 |
| C-8 | Operasi massal atomik | TERPENUHI | PN-10 |
| C-9 | Snapshot peserta pertemuan yang sudah dibuka tidak berubah | TERPENUHI | PN-13 |

## D. Aturan penempatan kamar

| # | Klaim | Status | Bukti |
|---|-------|--------|-------|
| D-1 | Seluruh logika kamar pindah ke service/repository terpusat | TERPENUHI | PS-2, PS-3 |
| D-2 | Prepared statement untuk seluruh query | TERPENUHI | PS-3 |
| D-3 | ID divalidasi sebagai bilangan bulat positif | TERPENUHI | PN-11, PN-19 |
| D-4 | Santri, kamar, dan tahun ajaran harus tersedia serta aktif untuk penempatan | TERPENUHI | PN-11 |
| D-4b | Santri nonaktif/arsip tetap dapat dikeluarkan dari kamar | TERPENUHI | PN-20 |
| D-5 | Satu kamar per santri per tahun ajaran | TERPENUHI (aplikasi) | KP-3, PN-18. **Catatan:** ditegakkan penguncian baris, bukan constraint basis data — lihat Risiko R-1 |
| D-6 | Penempatan ke kamar yang sama idempoten, tanpa baris baru | TERPENUHI | PN-5, PN-17 |
| D-7 | Perpindahan mencatat nilai sebelum dan sesudah pada audit | TERPENUHI | PN-6 |
| D-8 | Pengeluaran diaudit dan membutuhkan konfirmasi (alasan wajib) | TERPENUHI | PN-7, PW-7a (layar konfirmasi) |
| D-9 | ID penempatan dipertahankan bila dapat diperbarui dengan aman | TERPENUHI | PN-6 |
| D-10 | Penempatan tahun ajaran sebelumnya tidak dihapus | TERPENUHI | PN-12, PS-10 |
| D-11 | Tidak ada pembersihan duplikasi produksi otomatis | TERPENUHI | PN-18, PS-10 |
| D-12 | Konflik data menghasilkan laporan preflight dan menghentikan operasi terkait | TERPENUHI | PN-18, `bin/penempatan_preflight.php` |

## E. Perlindungan kapasitas dan konkurensi

| # | Klaim | Status | Bukti |
|---|-------|--------|-------|
| E-1 | Transaksi dimulai sebelum perubahan apa pun | TERPENUHI | PS-8 |
| E-2 | Baris kamar dikunci sebelum kapasitas dihitung | TERPENUHI | PS-8, KP-1, KP-2 |
| E-3 | Penghuni dihitung ulang di dalam transaksi | TERPENUHI | PS-8, KP-2 |
| E-4 | Santri yang sudah di kamar tujuan tidak dihitung sebagai tambahan | TERPENUHI | PN-9 |
| E-5 | Seluruh operasi ditolak bila kapasitas tidak cukup | TERPENUHI | PN-9, PW-9a/9c, BP-7a/7c |
| E-6 | Perubahan dan audit disimpan dalam transaksi yang sama | TERPENUHI | PN-16 |
| E-7 | Commit hanya setelah seluruh penempatan berhasil | TERPENUHI | PN-10 |
| E-8 | Urutan kunci konsisten untuk mengurangi deadlock | TERPENUHI | PS-8 (santri menaik, lalu kamar menaik) |
| E-9 | Konflik/deadlock me-rollback seluruh operasi dan menampilkan pesan "coba lagi" | TERPENUHI | PS-8 (termasuk jalur kelas lewat `translateFailure()`), KP-1c, KP-5 |
| E-10 | Tidak pernah menyimpan sebagian santri saat operasi massal gagal | TERPENUHI | PN-10, PW-9c |
| E-11 | Dua admin mengisi tempat terakhir: hanya satu berhasil | TERPENUHI | KP-1a/1b |
| E-12 | Lima admin memperebutkan dua tempat: tepat dua berhasil | TERPENUHI | KP-2a/2b |

## F. Audit

| # | Klaim | Status | Bukti |
|---|-------|--------|-------|
| F-1 | Mencatat admin pelaku | TERPENUHI | PN-6 (`actor_user_id`) |
| F-2 | Mencatat jenis tindakan | TERPENUHI | PN-6, PN-7, PS-9 |
| F-3 | Mencatat santri | TERPENUHI | PN-15 |
| F-4 | Mencatat tahun ajaran | TERPENUHI | PN-15 |
| F-5 | Mencatat kelas/kamar sebelum dan sesudah | TERPENUHI | PN-6, PN-7 |
| F-6 | Mencatat waktu | TERPENUHI | kolom `audit_logs.created_at` |
| F-7 | Menandai tindakan individual atau massal | TERPENUHI | PN-15 (`mode`) |
| F-8 | Mencatat jumlah santri pada tindakan massal | TERPENUHI | PN-8, PN-15 |
| F-9 | Mencatat alasan pengeluaran | TERPENUHI | PN-7 |
| F-10 | Tidak mencatat password, token, atau data sensitif yang tidak perlu | TERPENUHI | PN-15, PS-9 |
| F-11 | Audit gagal ⇒ perubahan penempatan ikut rollback | TERPENUHI | PN-16 |

## G. Keamanan

| # | Klaim | Status | Bukti |
|---|-------|--------|-------|
| G-1 | Halaman hanya untuk admin | TERPENUHI | PS-7, PW-1 |
| G-2 | Pemeriksaan CSRF dipertahankan | TERPENUHI | PS-7, PW-3a/3b |
| G-3 | Endpoint mutasi hanya menerima POST | TERPENUHI | PS-7, PW-4 |
| G-4 | Metode yang tidak sesuai ditolak | TERPENUHI | PW-4 (405), PW-6a (410) |
| G-5 | Tidak ada nilai GET/POST mentah dalam SQL | TERPENUHI | PS-3 |
| G-6 | Dropdown tidak dijadikan validasi | TERPENUHI | PS-13, PN-11 |
| G-7 | IDOR dicegah dengan validasi seluruh entitas di server | TERPENUHI | PN-11, PN-19 |
| G-8 | Respons konsisten dan tidak membocorkan query atau detail internal | TERPENUHI | PW-11, KP-5 |
| G-9 | Tindakan ganda/retry tidak menghasilkan relasi ganda | TERPENUHI | PN-17, PW-8a/8b/8c |
| G-11 | Alamat lama menjawab 410 dengan penjelasan, juga tanpa token CSRF, dan tetap tertutup bagi tamu | TERPENUHI | PW-6d/6e/6f, PW-12, PS-6 |
| G-10 | Tidak ada keputusan keamanan dari JavaScript saja | TERPENUHI | PS-13 |

## H. Migrasi

| # | Klaim | Status | Bukti |
|---|-------|--------|-------|
| H-1 | Tidak ada perubahan skema; deployment hanya pembaruan kode | TERPENUHI | PS-15, `migration-and-rollback.md` |
| H-2 | Preflight tersedia untuk seluruh jenis konflik yang diminta | TERPENUHI | `bin/penempatan_preflight.php`, PN-18 |
| H-3 | Preflight hanya membaca, tidak pernah menulis | TERPENUHI | PS-10 |

---

## Risiko residual (diterima, bukan diperbaiki di sini)

| # | Risiko | Dampak | Mengapa diterima |
|---|--------|--------|------------------|
| R-1 | `plotting_kamar` belum punya constraint unik `(id_santri, id_tahun)` | Penulisan **di luar** `PenempatanService` (skrip manual, impor, phpMyAdmin) masih dapat membuat baris ganda | Instruksi pengguna mengutamakan implementasi tanpa perubahan skema; seluruh aturan dapat dipenuhi lewat penguncian baris. Prosedur menambahkan constraint kelak sudah disiapkan pada `migration-and-rollback.md` bagian 4 |
| R-2 | Duplikasi kamar yang sudah ada pada data produksi | Santri terkait tidak dapat ditempatkan sampai konfliknya diselesaikan admin | Sesuai instruksi: sistem tidak boleh memperbaiki data produksi otomatis. Halaman menandai baris berkonflik dan menyuruh menjalankan preflight |
| R-3 | Mengeluarkan santri dari kamar menghapus baris tahun berjalan | Tidak ada "riwayat kamar" seperti riwayat kelas | `plotting_kamar` tidak punya kolom status; menambahkannya berarti perubahan skema **dan** perubahan `IzinRouter`. Nilai sebelum penghapusan selalu tersimpan di `audit_logs` |
| R-4 | Kamar tidak punya kolom `is_active`/`archived_at` | "Kamar tidak aktif" tidak dapat dinyatakan; hanya kapasitas ≥ 1 yang diperiksa | Struktur warisan V1; di luar cakupan paket ini |
| R-5b | `binlog_format = STATEMENT` membuat seluruh penempatan gagal | Transaksi READ COMMITTED tidak dapat menulis InnoDB pada mode itu (galat 1665) | Diperiksa `bin/penempatan_preflight.php` bagian 0 dan didokumentasikan pada langkah persiapan cPanel; galatnya diterjemahkan menjadi pesan konfigurasi server, bukan galat mentah |
| R-5c | Jalur penulisan kelas kedua pada `admin/admin_kelas.php` | Formulir lama itu tidak mengunci baris santri dan tidak meminta alasan | Fitur lama tidak dihapus atas prinsip "fitur dan datanya tidak boleh dihilangkan"; konflik yang muncul diterjemahkan menjadi pesan "coba lagi", dan halaman itu kini menautkan halaman penempatan sebagai jalur yang dianjurkan |
| R-5 | Batas 200 santri per operasi massal | Penempatan sangat besar harus dibagi beberapa tahap | Disengaja: satu transaksi tidak boleh menahan kunci terlalu lama |
| R-6 | Safari fisik (macOS/iOS) belum diuji | Kemungkinan perbedaan tampilan kecil | Sama seperti paket-paket sebelumnya; perangkat fisik tidak tersedia bagi implementer |
| R-7 | Pembaca layar nyata (NVDA/JAWS/VoiceOver) belum diuji | Nama aksesibel diuji lewat atribut, bukan pengalaman nyata | Perangkat/pembaca layar tidak tersedia; struktur label dan `aria-label` diuji otomatis |

## Audit mandiri sebelum push

Karena tidak ada audit Codex, satu tinjauan adversarial terpisah dijalankan atas
seluruh kode baru sebelum commit. Empat belas temuan dilaporkan; seluruhnya
ditindaklanjuti:

| Temuan | Tindakan |
|--------|----------|
| Konflik kunci pada jalur kelas menjadi galat 500 | diperbaiki: `translateFailure()` |
| Nilai balik `SET TRANSACTION`/`begin_transaction` tidak diperiksa | diperbaiki: operasi dibatalkan bila gagal |
| `binlog_format=STATEMENT` mematikan fitur tanpa peringatan | diperbaiki: preflight bagian 0 + pesan galat khusus + langkah cPanel |
| Santri arsip menahan tempat tidur selamanya | diperbaiki: pengeluaran diizinkan + filter + kartu ringkasan |
| `UPDATE` perpindahan kamar tanpa klausa `id_tahun` | diperbaiki |
| Tanggal kelas yang tidak dipakai memblokir tindakan kamar | diperbaiki |
| Baris kamar yatim tampil sebagai "belum ada kamar" | diperbaiki: lencana konflik tersendiri |
| Filter kamar melewatkan santri berkonflik | diperbaiki: memakai `EXISTS` |
| Kamar berkapasitas 0 dapat dipilih lalu ditolak server | diperbaiki: pilihannya dinonaktifkan |
| Preflight melaporkan jumlah terpotong tanpa keterangan | diperbaiki |
| Klaim "SATU pintu masuk" tidak akurat | diperbaiki: dokumen dan komentar menyebut jalur kedua |
| POST lama tanpa CSRF menerima 419, bukan 410 | diperbaiki: guard admin langsung, tanpa pemeriksaan CSRF pada berkas yang tidak menulis |
| Audit ringkasan massal ditulis walau tidak ada perubahan | diperbaiki |
| Klaim token sekali pakai mencakup jalur cepat | diperbaiki: dokumen menyatakan cakupan sebenarnya |

Tidak ditemukan lubang keamanan (SQL injection, XSS, CSRF, IDOR, kebocoran galat)
maupun mutasi lewat GET.

## Yang **tidak** diklaim

- Paket ini **belum** diaudit pihak kedua. Pengguna memilih tidak memakai audit
  Codex untuk pekerjaan ini.
- Paket ini **belum** dirilis ke produksi dan **tidak** dinyatakan "siap
  produksi" oleh implementer. Persetujuan merge dan rilis ada pada pengguna.
- Tidak ada klaim tentang perilaku pada data produksi nyata; seluruh pengujian
  berjalan pada `webalhasan_test` dengan data fiktif.
