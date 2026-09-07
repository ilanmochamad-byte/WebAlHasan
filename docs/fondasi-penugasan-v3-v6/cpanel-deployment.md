# Panduan migrasi dan deployment cPanel: Fondasi Penugasan V3–V6

Keputusan pengguna 7 September 2026. Branch `feat/fondasi-penugasan-v3-v6`.

> **Agen tidak melakukan deployment dan tidak menjalankan migrasi produksi.**
> Berkas ini adalah panduan untuk operator manusia. Penggabungan ke `main` dan
> rilis ke cPanel adalah keputusan dan tindakan Anda, setelah audit Codex.

Paket ini **MEMERLUKAN MIGRASI BASIS DATA** (`012_fondasi_penugasan_v3_v6.sql`).
Aplikasi perangkat **tidak perlu diperbarui**.

---

## 1. Backup database dan berkas aplikasi

1. Backup basis data (phpMyAdmin → Export, atau `mysqldump`) dan berkas
   aplikasi (`public_html`).
2. **Uji pemulihannya** ke database berakhiran `_test`. Backup yang belum
   diuji pemulihannya belum cukup untuk rilis yang mengubah skema.

## 2. Pencatatan hash commit sebelum penerapan

```bash
cd /DATA/k1807225/public_html      # verifikasi ulang path-nya
git rev-parse HEAD                 # simpan; baseline main sebelum paket ini: 1653ac4
php bin/migrate.php status         # simpan keluarannya
```

## 3. Pemeriksaan versi MySQL

```sql
SELECT VERSION();
SELECT @@binlog_format;
```

Migrasi memakai kolom generated STORED dan CHECK — pola yang sudah terpasang
sejak migrasi 002/006 di produksi, tetapi dukungan dan penegakan CHECK tetap wajib dibuktikan pada versi
MySQL hosting yang sebenarnya. Preflight tidak menggantikan uji constraint.

## 4. Pre-check tabel dan constraint

Pada **salinan `_test` dari backup produksi**:

```bash
php bin/penugasan_preflight.php
```

Harus berakhir `TIDAK ADA PENGHALANG`. Simpan bagian **4. Manifest jumlah
baris** (murobi, pembimbing, guru, pengurus, roles = 4).

## 5. Urutan migrasi

Hanya satu migrasi baru: **012**, setelah 011. `php bin/migrate.php up`
menerapkan yang belum tercatat secara berurutan.

## 6. Perintah migrasi resmi proyek

Uji dahulu pada salinan `_test`:

```bash
php bin/migrate.php up
php bin/penugasan_verify.php --murobi=<N> --pembimbing=<N>
bash bin/penugasan_run_all_tests.sh
```

Lalu pada produksi, setelah kode `main` hasil merge ditarik lewat cPanel →
Git Version Control → Update from Remote:

```bash
php bin/migrate.php status         # 012 harus [menunggu]
php bin/migrate.php up
php bin/migrate.php status         # 012 harus [diterapkan]
```

## 7. Post-check struktur

```bash
php bin/penugasan_verify.php --murobi=<N> --pembimbing=<N>
```

Harus berakhir `SELURUH PEMERIKSAAN LULUS`.

## 8. Query pemeriksaan jumlah data

```sql
SELECT COUNT(*) FROM murobi_assignments;        -- = manifest
SELECT COUNT(*) FROM pembimbing_assignments;    -- = manifest
SELECT COUNT(*) FROM roles;                     -- 4
SELECT COUNT(*) FROM guru_mapel_assignments;    -- 0
SELECT COUNT(*) FROM pendidikan_assignments;    -- 0
SELECT COUNT(*) FROM bendahara_bulanan_assignments;  -- 0
SELECT COUNT(*) FROM panitia_psb_assignments;   -- 0
SELECT COUNT(*) FROM bendahara_psb_assignments; -- 0
SELECT COUNT(*) FROM mata_pelajaran;            -- 0
SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM user_roles;  -- tidak berubah
```

## 9. Smoke test login dan penugasan (peramban sungguhan)

### 9.1 Login dan regresi dasar

- [ ] Login **admin**, **guru**, **pengurus**, **orang tua** lewat `/portal/`
      masing-masing berhasil dan mendarat di beranda.
- [ ] Guru: Jadwal & Pertemuan dan Laporan Kehadiran terbuka seperti biasa.
- [ ] Pengurus/murobi/orang tua: modul Perizinan terbuka seperti biasa.
- [ ] Aplikasi perangkat yang **sudah terpasang** (tanpa update): login, profil,
      pilih mode, jadwal, absensi, laporan, perizinan tetap berjalan.

### 9.2 Pusat Penugasan

- [ ] Sidebar admin menampilkan **Penugasan → Pusat Penugasan**, **Penugasan
      Murobi**, **Penugasan Pembimbing**.
- [ ] `admin/admin_penugasan.php` terbuka; delapan tab tampil; tidak ada galat.
- [ ] Tab Murobi dan Pembimbing menampilkan **seluruh penugasan lama** dengan
      status yang benar (bandingkan jumlahnya dengan manifest).
- [ ] Tab Mata Pelajaran: tambah satu mata pelajaran uji → tersimpan.
- [ ] Tab Guru Mata Pelajaran: buat penugasan untuk guru yang **punya akun**
      → kolom Capability efektif menampilkan `nilai.input` dst.
- [ ] Buat penugasan untuk guru yang **belum punya akun** → tersimpan, ditandai
      "Belum punya akun" dengan penjelasan.
- [ ] Ulangi penugasan yang sama → ditolak dengan pesan tumpang tindih; tidak
      ada baris ganda.
- [ ] Tab Panitia PSB: buat untuk seorang pengurus → Capability efektif memuat
      `psb.*` dan **tidak** memuat `psb_keuangan.*`.
- [ ] **Ubah** salah satu penugasan tanpa alasan → ditolak; dengan alasan →
      tersimpan.
- [ ] **Akhiri** dengan tanggal hari ini dan alasan → baris tetap ada, tercatat
      "Diakhiri oleh …".
- [ ] **Nonaktifkan** → status Dinonaktifkan; **Aktifkan** → kembali Aktif.
- [ ] Filter status Aktif / Akan Datang / Berakhir / Dinonaktifkan bekerja.

### 9.3 Keamanan

- [ ] Keluar; buka `admin/admin_penugasan.php` → diarahkan ke pintu masuk.
- [ ] Masuk sebagai **guru**, buka `admin/admin_penugasan.php` → **403**.
- [ ] Buka `admin/admin_penugasan.php?jenis=guru_mapel&action=nonaktifkan&id=1`
      sebagai admin → halaman biasa, **tidak ada perubahan data**.

### 9.4 Halaman lama dan akun

- [ ] `admin/admin_murobi.php` dan `admin/admin_pembimbing.php` terbuka, memuat
      tautan ke Pusat Penugasan, dan formulir lamanya menyimpan melalui PenugasanService. Uji juga
      duplikasi/overlap dari URL lama, aktivasi dan pemulihan arsip yang bentrok.
- [ ] Akun & Hak Akses: baris akun menampilkan **Penugasan efektif** dan tautan
      "Kelola di Pusat Penugasan"; tombol role dasar tidak memuat penugasan.

### 9.5 Audit

- [ ] `audit_logs` memuat `penugasan.buat`, `penugasan.ubah`,
      `penugasan.akhiri`, `penugasan.nonaktifkan`, `penugasan.aktifkan`,
      `penugasan.capability_berubah`, `penugasan.tolak_tumpang_tindih` dengan
      `actor_user_id` Anda dan tanpa password/token.

### 9.6 Bersihkan data uji

Nonaktifkan/akhiri penugasan uji dan arsipkan mata pelajaran uji (tidak ada
tombol hapus — memang disengaja).

## 10. Langkah rollback/pemulihan

### 10.1 Rollback kode saja

Kembalikan kode ke `1653ac4`. **Skema boleh dibiarkan**: tabel dan kolom
tambahan migrasi 012 tidak mengganggu kode lama.

### 10.2 Rollback skema

Baca `migrasi-dan-rollback.md` §6 seluruhnya (peringatan kehilangan data).
Urutan: (1) kembalikan kode ke `1653ac4`; (2) `php bin/migrate.php rollback`.

## 11. Larangan menjalankan migrasi dua kali

Migrasi 012 idempoten (aman bila terpaksa diulang setelah gagal di tengah),
tetapi **jangan** menjalankannya ulang tanpa alasan, dan **jangan pernah**
menjalankan rollback lalu migrasi ulang pada produksi yang sudah berisi
penugasan V3–V6: rollback menghapus isinya.

## Status audit independen 7 September 2026

Migrasi/rollback lokal dan rangkaian otomatis lulus, tetapi status penuh
**BELUM LULUS — MEMERLUKAN UJI MYSQL CPANEL**. Smoke Chromium tersedia pada
1440/768/390 px; Safari, pembaca layar, dan aplikasi lama terpasang belum
terbukti pada audit ini. Audit tidak melakukan merge, deployment, maupun
migrasi produksi. Ikuti bukti terbaru pada `test-results.md`.
