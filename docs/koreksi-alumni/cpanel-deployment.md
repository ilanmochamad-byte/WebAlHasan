# Panduan deployment cPanel: Koreksi Pengelolaan Alumni

Keputusan pengguna 6 September 2026. Branch `feat/koreksi-alumni`.

> **Agen tidak melakukan deployment dan tidak menjalankan migrasi produksi.**
> Berkas ini adalah panduan untuk operator manusia. Penggabungan ke `main` dan
> rilis ke cPanel adalah keputusan dan tindakan Anda.

**Perbedaan penting dari paket penempatan:** paket ini **MEMERLUKAN MIGRASI
BASIS DATA** (`011_koreksi_alumni.sql`). Deployment tidak cukup dengan
memperbarui kode.

---

## 0. Prasyarat

- Pull request `feat/koreksi-alumni` → `main` sudah ditinjau dan disetujui, lalu
  digabungkan **lewat GitHub** (bukan merge lokal).
- Catat commit hasil merge pada `main`; itulah commit yang ditarik cPanel.
- Baseline `main` sebelum paket ini: **`1bc18a2`**. Simpan nilai ini untuk
  rollback kode.

## 1. Persiapan (sebelum menyentuh produksi)

1. **Verifikasi konfigurasi hosting.** Domain, document root, lokasi repository
   cPanel, branch aktif, dan commit yang sedang live. Jangan menebak konfigurasi
   hosting dari repo lokal.
2. **Catat versi runtime.** PHP CLI dan PHP web, serta MySQL/MariaDB. Dokumen
   fase sebelumnya merekam `/opt/alt/php83/usr/bin/php` dan
   `/DATA/k1807225/public_html`; **verifikasi ulang**.
3. **Backup, lalu UJI PEMULIHANNYA.** Basis data, berkas aplikasi, dan
   **folder `gambar_galeri/`** (foto alumni ada di sana). Pulihkan backup itu ke
   database berakhiran `_test` dan pastikan berhasil. Backup yang belum diuji
   pemulihannya belum cukup untuk rilis — apalagi rilis yang mengubah indeks.
4. **Manifest jumlah baris.** Jalankan blok SQL pada
   `migrasi-dan-rollback.md` §4 dan **simpan hasilnya**. Nilai ini yang
   dibandingkan setelah migrasi.
5. **Preflight pada salinan `_test`, bukan produksi:**

   ```bash
   php bin/alumni_preflight.php
   ```

   - **Bagian 1 (NIS ganda) WAJIB kosong.** Bila ada temuan, **hentikan**:
     kunci unik pengganti tidak akan dapat dipasang.
   - **Bagian 7 (`binlog_format`) harus `ROW` atau `MIXED`.** Pada `STATEMENT`,
     setiap proses alumni akan gagal dengan galat 1665 meskipun modul lain
     berjalan normal. Hubungi pengelola server sebelum rilis.
   - Bagian 2–6 tidak menghalangi migrasi, tetapi **catat angkanya** — itulah
     gambaran kondisi data warisan Anda.

6. **Uji seluruh rangkaian pada salinan `_test` dari backup produksi:**

   ```bash
   php bin/migrate.php up
   php bin/alumni_verify.php --sebelum=<jumlah baris dari langkah 4>
   bash bin/alumni_run_all_tests.sh
   ```

   Inilah kesempatan pertama melihat perilaku paket ini pada **data alumni
   sungguhan** — tabel `alumni` pada dump repositori kosong, sehingga hal itu
   belum pernah terjadi. Lihat `test-results.md` §7.

## 2. Rilis kode

Tarik commit merge `main` lewat cPanel → Git Version Control → Update from
Remote (atau alur yang biasa Anda pakai). **Jangan** deploy dari branch fitur.

Berkas yang berubah pada paket ini seluruhnya berada di `admin/`, `app/`,
`bin/`, `database/`, `docs/`, dan `tests/`. Tidak ada perubahan pada `.htaccess`,
konfigurasi PHP, cron, maupun aset publik.

## 3. Menjalankan migrasi pada produksi

Setelah kode ter-update dan backup terverifikasi:

```bash
cd /DATA/k1807225/public_html      # verifikasi ulang path-nya
php bin/migrate.php status         # 011 harus [menunggu]
php bin/migrate.php up
php bin/migrate.php status         # 011 harus [diterapkan]
php bin/alumni_verify.php --sebelum=<jumlah baris dari langkah 1.4>
```

**Perkiraan durasi.** `ALTER TABLE` menambahkan dua kolom generated STORED,
sehingga MySQL menulis ulang tabel `alumni`. Pada tabel beberapa ribu baris ini
hitungan detik; pada tabel sangat besar, jalankan di luar jam sibuk. Selama
`ALTER` berjalan, halaman alumni tidak dapat diakses.

**Bila migrasi gagal di tengah.** MySQL tidak mendukung DDL transaksional, jadi
sebagian pernyataan mungkin sudah diterapkan. Migrasi ini **idempoten**:
jalankan ulang `php bin/migrate.php up` setelah menyelesaikan penyebabnya.
Setiap pernyataan yang sudah berhasil akan dilewati.

## 4. Backfill (opsional, setelah migrasi)

```bash
php bin/alumni_backfill.php              # LAPORAN SAJA — tidak menulis apa pun
```

Baca laporannya. Bila daftar “PASTI” sudah Anda setujui:

```bash
php bin/alumni_backfill.php --terapkan
```

Data AMBIGU **tidak** dipasangkan dan **tidak** boleh dipasangkan otomatis.
Hubungkan satu per satu dari halaman Data Alumni → Detail →
“Hubungkan ke santri sumber”.

Fitur alumni berjalan normal tanpa backfill. Langkah ini boleh ditunda.

---

## 5. Smoke test setelah menarik branch di cPanel

Lakukan sebagai admin, pada peramban sungguhan. Ceklis ini disusun agar setiap
langkah **dapat diverifikasi**, bukan sekadar “kelihatannya jalan”.

### 5.1 Halaman dan navigasi

- [ ] Sidebar menampilkan **Lain-lain → Data Alumni** dan
      **Lain-lain → Kelulusan & Mutasi Keluar**.
- [ ] `admin/admin_alumni.php` terbuka, memakai sidebar dan breadcrumb yang sama
      dengan halaman admin lain (bukan tampilan lama bergaya sendiri).
- [ ] Kartu ringkasan menampilkan tiga angka: catatan aktif, diarsipkan, dan
      tanpa referensi santri.
- [ ] Data alumni lama Anda **tampil seluruhnya**. Bandingkan jumlahnya dengan
      manifest langkah 1.4.
- [ ] Pada layar ponsel (atau jendela sempit), tabel dapat digulir mendatar dan
      halaman tidak melebar keluar layar.

### 5.2 Filter dan pencarian

- [ ] Cari nama alumni lama → ketemu.
- [ ] Cari NIS alumni lama → ketemu.
- [ ] Filter **Status keluar** = Lulus / Pindah / Berhenti → hasilnya menyempit.
- [ ] Filter **Tahun angkatan** → dropdown berisi tahun yang benar-benar ada.
- [ ] Filter **Tingkat** = Ibtida / Tsanawi → hasilnya menyempit.
- [ ] Filter **Status catatan** = Diarsipkan → kosong (belum ada yang diarsipkan).
- [ ] Filter **Referensi santri** = Belum terhubung → menampilkan data warisan.
- [ ] Tombol **Bersihkan** mengembalikan daftar penuh.

### 5.3 Keamanan (WAJIB — ini menggantikan lubang lama)

- [ ] Buka `admin/admin_alumni.php?hapus=1` di address bar.
      **Harus dijawab 405** dengan pesan yang menyebut tindakan Arsipkan, dan
      **tidak ada catatan yang hilang**. Periksa ulang jumlah barisnya.
- [ ] Buka `admin/proses_mutasi_alumni.php` → **dialihkan** ke halaman
      Kelulusan & Mutasi Keluar.
- [ ] Keluar dari sesi, lalu buka `admin/admin_alumni.php` → **tidak terbuka**.
- [ ] Masuk sebagai akun **guru** (bukan admin), buka `admin/admin_alumni.php`
      → **ditolak** (403), bukan sekadar menunya tersembunyi.

### 5.4 Alur individual

Gunakan **satu santri uji** yang boleh diproses, atau siapkan satu santri fiktif.

- [ ] Buka Master Data Santri, cari santri itu. Baris santri **aktif** memiliki
      tombol **“Luluskan / Mutasi keluar”**.
- [ ] Klik tombolnya. Halaman menampilkan NIS, nama, unit, **kelas aktif**, dan
      **kamar aktif** santri itu.
- [ ] Isi status `Lulus`, tanggal keluar, tahun angkatan, tingkat, catatan.
- [ ] Klik **Tinjau sebelum memproses**. Layar konfirmasi muncul.
      **Sebelum melanjutkan**, buka tab lain dan pastikan santri itu **belum**
      berubah statusnya — tinjauan tidak boleh mengubah apa pun.
- [ ] Klik **Proses 1 santri menjadi alumni**, setujui dialog konfirmasi.
- [ ] Anda diarahkan ke **detail catatan alumni** yang baru. Periksa:
      kelas terakhir, kamar terakhir, unit terakhir, **diproses oleh** (nama
      Anda), dan **waktu proses** terisi.
- [ ] Kembali ke Master Data Santri: santri itu **hilang dari daftar aktif**,
      tetapi **muncul kembali** dengan filter Status = **Arsip**. Barisnya tidak
      hilang.
- [ ] Buka detail santri itu: **riwayat kelasnya masih ada**, dengan penempatan
      terakhir berstatus `Selesai` bertanggal tanggal keluar.
- [ ] Buka detail santri itu: **relasi walinya masih ada dan masih aktif**.
- [ ] Buka Akun & Hak Akses: **akun wali santri itu masih aktif**.
- [ ] Buka Penempatan Kelas & Kamar: **kamar yang tadi ditempatinya kini punya
      satu tempat lebih banyak**.
- [ ] Kembali ke halaman Kelulusan untuk santri yang sama
      (`?santri_id=…`): ia kini muncul pada tabel **“Dikecualikan dari proses”**
      dengan alasan yang jelas dan tautan ke catatan alumninya.

### 5.5 Alur massal

Gunakan **kelas uji** yang isinya boleh diproses.

- [ ] Buka Kelulusan & Mutasi Keluar, pilih kelas → daftar santrinya muncul
      beserta jumlahnya.
- [ ] Santri yang sudah menjadi alumni tampil terpisah pada
      **“Dikecualikan dari proses”**.
- [ ] Isi formulir, klik Tinjau. Layar konfirmasi memuat peringatan
      *“memengaruhi SELURUH N santri”*.
- [ ] Terapkan. Pesan sukses menyebut jumlah yang diproses.
- [ ] Buka Penempatan Kelas & Kamar: **kelas itu kini kosong dari santri aktif**.
- [ ] Buka Data Alumni: seluruh santri itu ada, dengan tanggal dan status yang
      sama.

### 5.6 Klik ganda dan refresh

- [ ] Ulangi alur individual sampai layar konfirmasi, lalu **klik tombol
      terapkan dua kali cepat**. Hasilnya tetap **satu** catatan alumni.
- [ ] Setelah berhasil, tekan **tombol kembali** peramban lalu kirim ulang
      formulirnya. Muncul pesan *“Tinjauan ini sudah pernah diterapkan”* dan
      **tidak ada catatan kedua**.

### 5.7 Koreksi, arsip, pemulihan, pembatalan

- [ ] Buka detail satu catatan alumni → **Koreksi**. Ubah tingkat atau catatan,
      simpan. Nilainya berubah.
- [ ] **Arsipkan** dengan alasan kosong → **ditolak**.
- [ ] **Arsipkan** dengan alasan yang wajar → berhasil. Catatan ditandai
      “Diarsipkan”, alasannya tampil, dan **fotonya masih ada**.
- [ ] Dengan filter Status catatan = **Aktif**, catatan itu **tidak muncul**.
      Dengan filter **Diarsipkan**, ia **muncul**.
- [ ] Periksa Master Data Santri: **status santrinya tidak berubah** akibat
      pengarsipan.
- [ ] **Pulihkan** dengan alasan → catatan kembali aktif, dan **status santri
      tetap tidak berubah**.
- [ ] **Batalkan kelulusan** dengan alasan → catatan ditandai “Dibatalkan”, dan
      santrinya **kembali aktif** di Master Data Santri.
- [ ] Periksa Penempatan Kelas & Kamar: santri itu **tidak** mendapat kelas atau
      kamar baru secara otomatis (memang begitu yang diinginkan).
- [ ] Proses ulang santri itu menjadi alumni → **berhasil**, dan catatan lamanya
      **tetap ada** sebagai arsip.

### 5.8 Audit

- [ ] Periksa `audit_logs` (lewat phpMyAdmin) untuk aksi `alumni.proses`,
      `alumni.massal`, `alumni.arsip`, `alumni.pulihkan`, `alumni.batalkan`.
      Setiap baris memuat `actor_user_id` Anda, `before_json`, dan `after_json`.

### 5.9 Regresi halaman tetangga

- [ ] Master Data Santri: tambah/ubah santri masih berjalan.
- [ ] Penempatan Kelas & Kamar: menempatkan dan mengeluarkan masih berjalan.
- [ ] Orang Tua / Wali dan Rekonsiliasi Wali: masih berjalan.
- [ ] Akun & Hak Akses: masih berjalan.
- [ ] Laporan Kehadiran dan Perizinan: masih berjalan.

---

## 6. Rollback

### 6.1 Rollback kode saja

Bila ada masalah tampilan tetapi datanya sehat, kembalikan kode ke commit
sebelum paket ini (`1bc18a2`). **Skema boleh dibiarkan**: kolom tambahan
migrasi 011 tidak mengganggu kode lama — kecuali satu hal, lihat peringatan
di bawah.

> **PERINGATAN.** Kode lama `admin_alumni.php` menyertakan
> `?hapus=ID` yang **menghapus permanen**. Mengembalikan kode berarti
> mengembalikan lubang itu. Bila Anda melakukannya, perlakukan sebagai keadaan
> darurat sementara dan jangan biarkan berlarut.

### 6.2 Rollback skema

Baca `migrasi-dan-rollback.md` §8 **seluruhnya** sebelum menjalankan ini —
termasuk pemeriksaan NIS ganda dan daftar data yang sengaja hilang.

Urutan yang benar:

1. kembalikan kode ke `1bc18a2`;
2. baru `php bin/migrate.php rollback`.

Membalik urutannya membuat halaman alumni gagal, karena kode paket ini
membutuhkan kolom migrasi 011.

---

## 7. Yang JANGAN dilakukan

- Jangan menjalankan `php bin/alumni_backfill.php --terapkan` sebelum membaca
  laporannya.
- Jangan memasangkan data AMBIGU secara manual di basis data. Gunakan tindakan
  “Hubungkan ke santri sumber” supaya tercatat pada audit.
- Jangan menghapus baris `alumni` lewat phpMyAdmin. Gunakan tindakan Arsipkan.
- Jangan menghapus berkas di `gambar_galeri/` untuk “membersihkan” alumni yang
  diarsipkan. Fotonya masih dirujuk.
- Jangan menjalankan migrasi tanpa backup yang **sudah diuji pemulihannya**.
