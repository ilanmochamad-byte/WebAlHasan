# Rencana: Koreksi dan Modernisasi "Penempatan Kelas & Kamar Santri"

Keputusan pengguna **6 September 2026**.
Implementer: **Claude**. Audit Codex **tidak dilakukan** untuk pekerjaan ini
atas pilihan eksplisit pengguna; seluruh pengujian dan pemeriksaan mandiri
dilakukan Claude sebelum push.

- Branch: `feat/penempatan-santri`, dicabangkan dari `main` (baseline
  `3b53c1c` — merge PR #15 "feat/pesan-kredensial-akun", yang memuat commit
  `fbed7b2`).
- Tidak ada implementasi langsung pada `main`. Tidak ada merge lokal, tidak ada
  force-push, tidak ada penghapusan branch lain.
- Deployment ke cPanel **tidak** dilakukan agen; pengguna melakukannya sendiri.

---

## 1. Masalah yang diselesaikan

Fitur penempatan sebenarnya masih ada dan berjalan, tetapi pada `admin/admin_santri.php`
dengan sejumlah cacat yang saling menguatkan:

| # | Masalah | Bukti pada kode lama |
|---|---------|----------------------|
| 1 | Tautannya hilang dari navigasi baru | `App\Ui\Navigation` memetakan `admin_santri.php` ke kunci `master.santri_lama` yang **tidak dipakai satu pun grup menu** |
| 2 | Namanya tertukar dengan halaman master data santri | judul "Penempatan Santri" pada `admin_santri.php`, sedangkan master data ada di `admin_master_santri.php` |
| 3 | Tampilannya memakai pola lama | halaman menulis `<html>/<head>/<nav>` sendiri, memuat DataTables + jQuery, tidak memakai `master_header()`/`master_footer()` |
| 4 | Kamar belum memakai layanan/repository terpusat | query `plotting_kamar` ditulis langsung di halaman |
| 5 | Sebagian query belum prepared statement | `mysqli_real_escape_string` + interpolasi string pada `$where` dan seluruh mutasi kamar |
| 6 | Kamar dipasang dengan hapus-lalu-sisip | `DELETE FROM plotting_kamar …` lalu `INSERT INTO plotting_kamar …` — ID penempatan hilang setiap kali santri pindah |
| 7 | Transaksi, audit, dan perlindungan massal tidak konsisten | kelas memakai `MasterDataService` (bertransaksi + audit), kamar tidak memakai keduanya; loop massal menyimpan satu per satu |
| 8 | Perubahan bersamaan membuat kapasitas tidak akurat | kapasitas dihitung sebelum menulis, tanpa kunci baris |
| 9 | Halaman Data Kamar hanya menampilkan penghuni | tidak ada tindakan menuju penempatan |

Tambahan yang ditemukan saat pengerjaan:

**Halaman penempatan lama sudah TIDAK DAPAT MENYIMPAN pada `main`.**
`admin/_guard.php` memeriksa token CSRF untuk setiap POST, sedangkan JavaScript
`admin_santri.php` tidak pernah mengirim token itu — halaman lama menggambar
kerangkanya sendiri sehingga tidak memuat `window.ALHASAN_CSRF` maupun
`jQuery.ajaxSetup` milik `App\Ui\Layout`. Diverifikasi langsung pada baseline
`main` `3b53c1c`: setelah login sebagai admin,

```
POST /admin/admin_santri.php  (action=update_plot, persis seperti JS lama)
→ 419 "Permintaan ditolak karena token keamanan tidak valid."
```

Artinya penempatan kelas maupun kamar lewat halaman lama gagal diam-diam
(dialognya hanya menampilkan galat parse JSON). Paket ini memulihkan fungsinya,
bukan sekadar memperindahnya.

- pada REPEATABLE READ (isolasi bawaan InnoDB), menghitung penghuni setelah
  menunggu kunci baris **tetap** membaca snapshot lama. Ini ditemukan oleh
  pengujian permintaan bersamaan yang ditulis untuk paket ini dan diperbaiki
  dengan menjalankan transaksi penempatan pada READ COMMITTED.

Fitur dan datanya **tidak dihapus**.

Cacat lain yang ditemukan tinjauan adversarial mandiri sebelum push (14 temuan,
seluruhnya diperbaiki) dirinci pada `test-results.md` bagian 7.

---

## 2. Ruang lingkup

### Termasuk

- halaman baru `admin/admin_penempatan_santri.php` memakai kerangka bersama V1–V2;
- layanan dan repository terpusat `App\MasterData\PenempatanService` dan
  `App\MasterData\PenempatanRepository`;
- menu admin **Master Data → Penempatan Kelas & Kamar** (kunci `master.penempatan`);
- tautan menuju penempatan dari Data Santri, Data Kelas, Data Kamar, dan daftar
  penghuni kamar, dengan filter yang sesuai;
- kompatibilitas alamat lama `admin/admin_santri.php`;
- skrip preflight `bin/penempatan_preflight.php`;
- pengujian statis, integrasi, permintaan bersamaan, smoke web, dan browser;
- dokumentasi paket ini.

### Tidak termasuk (sengaja)

- perubahan skema basis data — lihat `migration-and-rollback.md`;
- perubahan pada model riwayat kelas (`plotting_kelas`) yang sudah benar sejak V1;
- perubahan pada snapshot peserta pertemuan/absensi;
- perubahan pada `IzinRouter`, penugasan murobi, atau cakupan pembimbing;
- pembersihan duplikasi data produksi (dilaporkan, tidak diperbaiki otomatis);
- penyeragaman collation tabel warisan (`santri` `utf8mb4_general_ci` vs tabel
  migrasi `utf8mb4_unicode_ci`) — tetap di luar cakupan seperti sebelumnya.

---

## 3. Struktur sebelum dan sesudah

### Sebelum

```
admin/admin_santri.php
  ├─ endpoint AJAX  action=update_plot        (JSON, tanpa transaksi/audit untuk kamar)
  ├─ endpoint AJAX  action=bulk_update_plot   (JSON, loop tanpa transaksi)
  ├─ query kelas    → master_data_service()->assignActiveClass()/endActiveClass()
  ├─ query kamar    → DELETE + INSERT langsung, interpolasi string
  └─ tampilan       → Bootstrap + DataTables sendiri, seluruh santri dimuat ke peramban
```

### Sesudah

```
admin/admin_penempatan_santri.php          (kerangka bersama, POST + CSRF + token sekali pakai)
  └─ penempatan_service()  =  App\MasterData\PenempatanService
        ├─ preview(aksi, santri[], opsi)          → rencana, tanpa mengubah apa pun
        ├─ apply(aksi, santri[], opsi, aktor)     → satu transaksi, atomik
        ├─ listPage()/roomOptions()/classOptions()/summary()
        ├─ kelas  → MasterDataRepository::membershipAssign()/membershipEnd()   (jalur V1, tidak berubah)
        └─ kamar  → App\MasterData\PenempatanRepository (prepared statement, FOR UPDATE)

admin/admin_santri.php                     (alamat lama)
  ├─ GET   → 301 ke halaman baru + pemetaan filter lama
  └─ POST  → 410 Gone, TIDAK dialihkan, tidak mengubah data

bin/penempatan_preflight.php               (laporan konflik, hanya membaca)
```

### Endpoint terdampak

| Alamat | Sebelum | Sesudah |
|--------|---------|---------|
| `GET /admin/admin_santri.php` | halaman penempatan lama | `301` ke `/admin/admin_penempatan_santri.php` (+ `cari`→`q`, `jk`→`jk`, `sekolah`→`sekolah`, `filter_status=no_class`→`status=tanpa_kelas`, `no_room`→`tanpa_kamar`) |
| `POST /admin/admin_santri.php` | `update_plot`, `bulk_update_plot` (JSON) | `410 Gone`, badan teks menjelaskan halaman penggantinya |
| `GET /admin/admin_penempatan_santri.php` | — | daftar + filter + pagination |
| `POST /admin/admin_penempatan_santri.php` | — | `tahap=tinjau` (tanpa perubahan), `tahap=terapkan` (bertoken), `tahap=langsung` (satu santri, penempatan saja) |
| `GET /admin/admin_penempatan_santri.php?action=…` | — | `405 Method Not Allowed` |

Halaman lain yang ditambahi tautan: `admin/admin_master_santri.php`,
`admin/admin_kelas.php`, `admin/admin_kamar.php` (daftar dan daftar penghuni),
`admin/admin_dashboard.php` (aksi cepat).

---

## 4. Keputusan desain dan alasannya

1. **Halaman baru, alamat lama tetap hidup.** Nama `admin_santri.php` sudah
   terlanjur bermakna ganda. Halaman baru diberi nama yang jelas; alamat lama
   hanya mengalihkan GET supaya bookmark tidak rusak.
2. **POST lama dihentikan, bukan dialihkan.** Mengalihkan POST secara buta
   berarti tetap menjalankan mutasi yang sudah dinyatakan tidak aman. `410 Gone`
   menyatakan dengan jujur bahwa endpoint itu tidak ada lagi.
3. **Satu jalur untuk individual dan massal.** Penempatan satu santri adalah
   operasi massal berisi satu santri. Dengan begitu tidak ada dua implementasi
   aturan kapasitas yang bisa berbeda.
4. **Layar konfirmasi dirender server.** Konfirmasi bukan `confirm()` JavaScript
   saja: admin melihat tabel sebelum/sesudah, kapasitas, dan alasan (bila
   mengeluarkan) sebelum menekan "Terapkan perubahan".
5. **Perpindahan kamar memperbarui baris, bukan hapus-lalu-sisip.** ID penempatan
   dipertahankan sehingga penunjuk luar dan jejak audit tetap sahih.
6. **Kunci baris santri, lalu kamar, selalu menurut ID menaik.** Urutan tetap ini
   yang membuat dua operasi massal tidak saling mengunci.
7. **READ COMMITTED untuk transaksi penempatan.** Tanpa ini, kunci baris tidak
   ada gunanya: perhitungan kapasitas tetap membaca snapshot lama.
8. **Tanpa migrasi.** Seluruh aturan integritas, audit, dan transaksi dapat
   dipenuhi dengan struktur saat ini melalui penguncian baris. Lihat
   `migration-and-rollback.md` untuk syarat bila kelak constraint unik hendak
   ditambahkan.

---

## 5. Berkas yang dibuat dan diubah

**Dibuat**

- `app/MasterData/PenempatanRepository.php`
- `app/MasterData/PenempatanService.php`
- `app/MasterData/PenempatanConflictException.php`
- `admin/admin_penempatan_santri.php`
- `bin/penempatan_preflight.php`
- `bin/penempatan_run_all_tests.sh`
- `tests/penempatan_static.php`
- `tests/penempatan_integration.php`
- `tests/penempatan_concurrency.php`
- `tests/penempatan_concurrency_worker.php`
- `tests/penempatan_web_smoke.php`
- `tests/browser/seed-penempatan.php`
- `tests/browser/uji-penempatan.mjs`
- `docs/penempatan-santri/*.md` (berkas ini dan lima lainnya)

**Diubah**

- `app/bootstrap.php` — pabrik `penempatan_service()`
- `app/Ui/Navigation.php` — menu dan pemetaan kunci
- `admin/admin_santri.php` — menjadi kompatibilitas alamat lama
- `admin/admin_master_santri.php`, `admin/admin_kelas.php`,
  `admin/admin_kamar.php`, `admin/admin_dashboard.php` — tautan menuju penempatan

Tidak ada berkas pengujian lama yang diubah atau dilemahkan.
