# Panduan deployment cPanel: Penempatan Kelas & Kamar Santri

Keputusan pengguna 6 September 2026.

> **Agen tidak melakukan deployment.** Berkas ini adalah panduan untuk operator
> manusia. Penggabungan ke `main` dan rilis ke cPanel adalah keputusan dan
> tindakan pengguna.

**Ringkasan singkat:** paket ini **tidak memerlukan migrasi basis data**.
Deployment cukup memperbarui kode ke commit hasil merge, lalu menjalankan smoke
test. Lihat `migration-and-rollback.md` bagian 1.

---

## 0. Prasyarat

- Pull request `feat/penempatan-santri` → `main` sudah ditinjau dan disetujui
  pengguna, lalu digabungkan **lewat GitHub** (bukan merge lokal).
- Catat commit hasil merge pada `main`; itulah commit yang ditarik cPanel.
- Baseline `main` sebelum paket ini: `3b53c1c`. Simpan nilai ini untuk rollback.

## 1. Persiapan (sebelum menyentuh produksi)

1. **Verifikasi konfigurasi hosting.** Pastikan domain, document root, lokasi
   repository cPanel, branch aktif, dan commit yang sedang live. Jangan menebak
   konfigurasi hosting dari repo lokal.
2. **Catat versi runtime.** PHP CLI dan PHP web, serta MySQL/MariaDB. Dokumen
   fase sebelumnya merekam `/opt/alt/php83/usr/bin/php` dan
   `/DATA/k1807225/public_html`; **verifikasi ulang**, jangan diandalkan begitu saja.
3. **Backup.** Basis data, berkas aplikasi, unggahan/media, dan konfigurasi
   privat di luar web root. Catat commit yang sedang live.
   Uji pemulihannya ke database berakhiran `_test`. Backup yang belum diuji
   pemulihannya belum cukup untuk rilis.
4. **Manifest jumlah baris.** Jalankan blok SQL pada
   `migration-and-rollback.md` bagian 7 dan simpan hasilnya.
5. **Laporan konflik data produksi.** Jalankan preflight pada salinan `_test`
   dari backup produksi:

   ```bash
   php bin/penempatan_preflight.php
   ```

   Bila ada temuan pada bagian 1, 2a, 2b, atau 5, **hentikan** dan putuskan
   penyelesaiannya lebih dahulu. Halaman baru akan menolak bekerja pada santri
   yang datanya berkonflik — itu perilaku yang disengaja, bukan cacat, tetapi
   admin perlu tahu sebelum rilis.
6. **Periksa `binlog_format`.** Preflight bagian 0 melaporkannya. Nilainya harus
   `ROW` atau `MIXED`. Pada `STATEMENT`, MariaDB menolak menulis tabel InnoDB di
   dalam transaksi READ COMMITTED yang dipakai penempatan (galat 1665) dan
   **setiap operasi penempatan akan gagal** meskipun modul lain berjalan normal.
   Minta pengelola server mengubahnya sebelum rilis.
7. **Pertahankan konfigurasi produksi.** `.env`, `API_TOKEN_HASH_SECRET`,
   `PUSH_TOKEN_KEY`, unggahan, dan konfigurasi cron yang sudah disetujui.
   **Jangan** menyalin `.env` sandbox, fixture, dump uji, atau folder
   `tests/browser/node_modules` ke produksi. WhatsApp tetap OFF/DITANGGUHKAN.
8. **Status migrasi.** Periksa `php bin/migrate.php status`. Paket ini tidak
   menambah migrasi; bila ada migrasi lama yang **belum** diterapkan (misalnya
   010), berhenti dan susun rencana terpisah. Jangan menjalankan seluruh migrasi
   tertunda tanpa meninjaunya.
9. **Jendela pemeliharaan.** Karena tidak ada perubahan skema, paket ini tidak
   memerlukan penghentian lalu lintas. Tetap pilih jam sepi agar admin tidak
   sedang melakukan penempatan saat kode berganti.

## 2. Menarik kode di cPanel

Gunakan **Git Version Control → Manage → Pull or Deploy → Update from Remote**
(fast-forward) pada repository cPanel.

- Tombol **Deploy HEAD Commit** memerlukan `.cpanel.yml`; repo ini belum
  memilikinya. Jangan mengarangnya saat rilis.
- Bila repository cPanel berada **di document root**, `Update from Remote` sudah
  cukup.
- Bila repository **terpisah** dari document root, ikuti prosedur penyalinan yang
  sudah pernah diuji pada rilis sebelumnya. **Jangan** membuat perintah salin
  massal atau `rsync --delete` berdasarkan tebakan.
- Bila belum ada prosedur yang teruji untuk kondisi hosting ini: **berhenti** dan
  siapkan prosedurnya lebih dahulu.

Berkas yang harus ikut terpasang:

```
app/MasterData/PenempatanRepository.php
app/MasterData/PenempatanService.php
app/MasterData/PenempatanConflictException.php
app/bootstrap.php
app/Ui/Navigation.php
admin/admin_penempatan_santri.php
admin/admin_santri.php
admin/admin_master_santri.php
admin/admin_kelas.php
admin/admin_kamar.php
admin/admin_dashboard.php
bin/penempatan_preflight.php
```

`bin/`, `tests/`, dan `docs/` tidak boleh dapat diunduh publik. Periksa aturan
`.htaccess` yang sudah ada masih berlaku setelah pull.

## 3. Migrasi

**Tidak ada.** Jangan menjalankan `php bin/migrate.php up` untuk paket ini.
Bila perintah itu melaporkan ada migrasi tertunda, berarti ada pekerjaan lain
yang belum selesai — hentikan rilis dan periksa.

## 4. Smoke test setelah rilis

Lakukan sebagai admin sungguhan pada produksi, dengan **satu santri uji** yang
sudah disepakati, bukan pada data massal.

1. **Masuk** sebagai admin. Buka **Master Data → Penempatan Kelas & Kamar**.
   Menu harus ada dan tersorot; breadcrumb menunjukkan
   `Beranda / Master Data / Penempatan Kelas & Kamar`.
2. **Identitas semester** aktif tampil di bagian atas dan benar.
3. **Pencarian dan filter**: cari satu NIS; coba filter jenis kelamin, unit
   sekolah, kelas, kamar, "belum mempunyai kelas", "belum mempunyai kamar".
   Daftar harus menyusut sesuai filter dan pagination tetap bekerja.
4. **Penempatan individual**: pilih kelas pada satu baris → *Simpan kelas*.
   Pesan berhasil muncul; baris menampilkan kelas barunya.
5. **Penempatan massal**: centang dua santri → pilih kamar → *Tinjau penempatan
   kamar*. Layar konfirmasi menampilkan jumlah, sebelum/sesudah, dan kapasitas.
   Tekan *Terapkan perubahan*; hasilnya dilaporkan.
6. **Kapasitas**: coba tempatkan ke kamar yang sudah penuh. Harus ditolak dengan
   pesan yang menyebut sisa tempat, dan **tidak ada** data yang berubah.
7. **Keluarkan santri**: pilih santri → *Keluarkan dari kamar…* → isi alasan →
   terapkan. Periksa `audit_logs` memuat kamar sebelumnya dan alasannya.
8. **Alamat lama**: buka `/admin/admin_santri.php?cari=<nis>&filter_status=no_room`.
   Harus mengalihkan ke halaman baru dengan filter yang sama.
9. **Riwayat**: buka Data Santri → detail santri yang tadi dipindahkan. Riwayat
   kelas lama masih ada dengan status `Selesai` dan tanggal selesai.
10. **Absensi lama** pada pertemuan yang sudah dibuka tidak berubah.
11. **Perizinan**: buat satu pengajuan uji untuk santri yang kamarnya baru
    berubah; periksa routing murobi mengarah ke murobi kamar yang benar.
12. **Ponsel**: ulangi langkah 1–5 pada layar ponsel. Halaman tidak boleh
    menggeser horizontal.
13. **Tidak ada 500** pada seluruh langkah. `.env`, SQL, backup, `bin`, `tests`,
    dan direktori internal tidak dapat diunduh publik.
14. **Santri nonaktif yang masih berkamar**: buka filter "Nonaktif/arsip tetapi
    masih berkamar". Bila ada isinya, keluarkan mereka agar tempatnya kembali
    tersedia (kartu ringkasan menampilkan jumlahnya).
15. Jalankan `php bin/penempatan_preflight.php` sekali lagi; kode keluar harus 0.

## 5. Rollback

### Kode

Kembalikan kode ke commit sebelum paket ini (`3b53c1c`) lewat mekanisme deploy
yang sudah diuji. Setelah itu halaman lama `admin/admin_santri.php` kembali
berfungsi seperti semula.

### Basis data

**Tidak ada langkah rollback basis data.** Paket ini tidak mengubah skema, dan
data penempatan yang dibuat lewat halaman baru tetap terbaca halaman lama karena
strukturnya sama.

Jangan menimpa basis data dengan backup lama setelah ada transaksi baru tanpa
rekonsiliasi dan keputusan operator. Jangan menghapus baris `audit_logs`.

### Bila terjadi kegagalan kritis

Hentikan akses tulis sesuai prosedur insiden, kembalikan kode, lalu laporkan
temuannya. Jangan menghapus penempatan, riwayat, atau audit untuk "membersihkan
keadaan".
