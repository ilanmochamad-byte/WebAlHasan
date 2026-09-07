# Migrasi dan rollback: Fondasi Penugasan V3–V6

Keputusan pengguna 7 September 2026. Branch `feat/fondasi-penugasan-v3-v6`.

> **Agen tidak menjalankan migrasi pada produksi.** Berkas ini adalah panduan
> untuk operator manusia.

## 1. Ringkasan

Paket ini **menambah satu migrasi**:
`database/migrations/012_fondasi_penugasan_v3_v6.sql`, berpasangan dengan
`database/rollbacks/012_fondasi_penugasan_v3_v6.sql`.

Migrasi **aditif dan idempoten**: tabel baru dibuat dengan
`CREATE TABLE IF NOT EXISTS`, kolom/kunci asing pada tabel lama dijaga
pemeriksaan `information_schema`. Tidak ada `DROP`, `DELETE`, `UPDATE`,
`TRUNCATE`, dan **tidak ada satu pun baris penugasan yang diisi** — seluruh
tabel baru kosong sampai admin mengisinya. Tabel `roles`, `users`, dan
`user_roles` tidak disentuh.

Prasyarat urutan: migrasi 001–011 sudah diterapkan.

## 2. Yang ditambahkan

### 2.1 Tabel baru

| Tabel | Guna | Kunci unik | Kunci asing |
| --- | --- | --- | --- |
| `mata_pelajaran` | master minimum mapel | `nama`; `kode_unique_key` (kode non-kosong) | `created_by`, `updated_by` → `users` |
| `guru_mapel_assignments` | guru pengampu mapel per kelas + tahun ajaran | (`guru_id`, `mata_pelajaran_id`, `kelas_id`, `tahun_ajaran_id`, `tanggal_mulai`) | `guru`, `mata_pelajaran`, `kelas`, `tahun_ajaran`, 3× `users` |
| `pendidikan_assignments` | Bagian Pendidikan | (`pengurus_id`, `tahun_ajaran_id`, `jenjang_key`, `tanggal_mulai`) | `pengurus`, `tahun_ajaran`, 3× `users` |
| `bendahara_bulanan_assignments` | bendahara pembiayaan bulanan | idem | idem |
| `panitia_psb_assignments` | panitia PSB | (`pengurus_id`, `tahun_ajaran_id`, `gelombang_key`, `tanggal_mulai`) | idem |
| `bendahara_psb_assignments` | bendahara PSB | idem | idem |

Setiap tabel penugasan baru memiliki `CHECK (tanggal_selesai IS NULL OR
tanggal_selesai >= tanggal_mulai)`, kolom generated STORED untuk kunci cakupan
(`jenjang_key`/`gelombang_key` = `COALESCE(kolom, '*')`), serta indeks
(subjek, `is_active`, tanggal) dan (`tahun_ajaran_id`, `is_active`).

Tipe kolom kunci asing mengikuti tabel rujukan aktual: `guru.id INT`,
`kelas.id INT`, `tahun_ajaran.id INT`, `pengurus.id BIGINT UNSIGNED`,
`users.id BIGINT UNSIGNED`. Preflight memverifikasinya.

### 2.2 Kolom tambahan pada tabel lama

`murobi_assignments` dan `pembimbing_assignments` masing-masing ditambah:

| Kolom | Tipe | Guna |
| --- | --- | --- |
| `catatan` | `VARCHAR(500) NULL` | catatan admin / alasan status dari pusat |
| `updated_by` | `BIGINT UNSIGNED NULL` (FK `users`, SET NULL) | pengubah terakhir |
| `diakhiri_pada` | `DATETIME NULL` | waktu pengakhiran historis |
| `diakhiri_oleh` | `BIGINT UNSIGNED NULL` (FK `users`, SET NULL) | admin yang mengakhiri |
| `alasan_pengakhiran` | `VARCHAR(500) NULL` | alasan pengakhiran |

Tidak ada kolom lama yang diubah tipenya. Halaman/layanan lama tetap menulis
kolom yang sama seperti sebelumnya.

## 3. Pemeriksaan SEBELUM migrasi

Jalankan pada **salinan `_test` dari backup produksi**:

```bash
php bin/penugasan_preflight.php
```

Yang diperiksa: migrasi 011 terpasang; versi server dan dukungan kolom
generated/CHECK (dibuktikan migrasi 002/006); tipe kolom tabel rujukan kunci
asing; nama tabel/kolom baru belum dipakai hal lain; manifest jumlah baris
(simpan!); baris murobi/pembimbing dengan tanggal selesai mendahului mulai
(laporan saja); `binlog_format`.

Catat juga:

```sql
SELECT COUNT(*) AS murobi_sebelum FROM murobi_assignments;
SELECT COUNT(*) AS pembimbing_sebelum FROM pembimbing_assignments;
SELECT slug FROM roles ORDER BY slug;          -- harus tepat 4 role dasar
```

## 4. Menjalankan migrasi

```bash
php bin/migrate.php status     # 012 harus [menunggu]
php bin/migrate.php up
php bin/migrate.php status     # 012 harus [diterapkan]
```

Perkiraan durasi: `CREATE TABLE` enam tabel kosong (sekejap) dan `ALTER TABLE`
menambah lima kolom nullable pada dua tabel penugasan lama (hitungan detik pada
puluhan–ratusan baris).

**Bila migrasi gagal di tengah.** MySQL tidak mendukung DDL transaksional.
Migrasi ini idempoten: jalankan ulang `php bin/migrate.php up` setelah
menyelesaikan penyebabnya; setiap pernyataan yang sudah berhasil dilewati.
Jangan menjalankan migrasi dua kali **tanpa alasan** hanya karena "ingin
memastikan".

## 5. Pemeriksaan SESUDAH migrasi

```bash
php bin/penugasan_verify.php --murobi=<N> --pembimbing=<N>
```

Skrip ini memeriksa (hanya membaca): migrasi 012 tercatat; seluruh kolom, kunci
unik, CHECK, dan kunci asing enam tabel baru; lima kolom jejak + kunci asing pada
dua tabel lama; kolom dan kunci unik lama tetap utuh; jumlah baris murobi dan
pembimbing tidak berkurang; tabel baru kosong (tidak ada pengisian tebakan);
`roles` tetap empat; resolver capability berjalan (admin memperoleh 21
capability pengawasan).

Query pemeriksaan jumlah data:

```sql
SELECT COUNT(*) FROM murobi_assignments;        -- = sebelum
SELECT COUNT(*) FROM pembimbing_assignments;    -- = sebelum
SELECT COUNT(*) FROM guru_mapel_assignments;    -- 0 setelah migrasi pertama
SELECT COUNT(*) FROM pendidikan_assignments;    -- 0
SELECT COUNT(*) FROM bendahara_bulanan_assignments;  -- 0
SELECT COUNT(*) FROM panitia_psb_assignments;   -- 0
SELECT COUNT(*) FROM bendahara_psb_assignments; -- 0
SELECT COUNT(*) FROM mata_pelajaran;            -- 0
SELECT COUNT(*) FROM roles;                     -- 4
```

## 6. Rollback

```bash
php bin/migrate.php rollback   # mengembalikan 012
```

### PERINGATAN KEHILANGAN DATA YANG DISENGAJA

Rollback **menghapus** enam tabel baru **beserta isinya** (seluruh penugasan
V3–V6 dan master mata pelajaran yang pernah dibuat), serta melepas lima kolom
jejak dari `murobi_assignments`/`pembimbing_assignments`. Baris
murobi/pembimbing **tidak** dihapus; `tanggal_selesai` yang diisi lewat
pengakhiran dari pusat tetap berlaku karena itu kolom lama.

Jejak pembuatan/pengakhiran tetap dapat ditelusuri pada `audit_logs`
(aksi `penugasan.*`) — tabel itu tidak disentuh rollback.

### Pemeriksaan wajib sebelum rollback

```sql
SELECT COUNT(*) FROM guru_mapel_assignments;
SELECT COUNT(*) FROM pendidikan_assignments;
SELECT COUNT(*) FROM bendahara_bulanan_assignments;
SELECT COUNT(*) FROM panitia_psb_assignments;
SELECT COUNT(*) FROM bendahara_psb_assignments;
SELECT COUNT(*) FROM mata_pelajaran;
```

Bila ada yang tidak nol, ekspor tabelnya (`mysqldump`) lebih dahulu.

### Urutan pemulihan yang benar

1. kembalikan kode ke commit sebelum paket ini (`1653ac4`);
2. baru `php bin/migrate.php rollback`.

Membalik urutan membuat Pusat Penugasan dan resolver gagal karena tabelnya
tidak ada. Kode lama tidak membutuhkan tabel 012, sehingga skema **boleh
dibiarkan** saat hanya kode yang dikembalikan.

## 7. Yang TIDAK dilakukan migrasi ini

- tidak menambah role;
- tidak mengisi penugasan apa pun;
- tidak menyentuh `mapel` warisan, `users`, `user_roles`, `audit_logs`,
  `izin_*`, `jadwal_ngaji`, `absensi_*`;
- tidak membuat tabel nilai, rapor, tagihan, pembayaran, kuitansi, jurnal,
  konseling, pelanggaran baru, seleksi PSB, atau tabel bisnis V3–V6 lainnya.
