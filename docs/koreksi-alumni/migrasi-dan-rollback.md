# Migrasi dan rollback: Koreksi Pengelolaan Alumni

Keputusan pengguna 6 September 2026. Branch `feat/koreksi-alumni`.

> **Agen tidak menjalankan migrasi pada produksi.** Berkas ini adalah panduan
> untuk operator manusia.

---

## 1. Ringkasan

Paket ini **menambah satu migrasi**: `database/migrations/011_koreksi_alumni.sql`,
berpasangan dengan `database/rollbacks/011_koreksi_alumni.sql`.

Migrasi ini **aditif dan idempoten**: setiap perubahan skema dijaga pemeriksaan
`information_schema`, sehingga aman dijalankan ulang. Tidak ada `DROP TABLE`,
`TRUNCATE`, `DELETE`, maupun `DROP COLUMN`. **Tidak ada satu baris `alumni` lama
yang dihapus atau diubah nilainya**, dan tidak ada berkas foto yang disentuh.

## 2. Yang ditambahkan pada tabel `alumni`

| Kolom | Tipe | Guna |
| --- | --- | --- |
| `santri_id` | `INT NULL` | referensi stabil ke santri sumber |
| `kelas_terakhir` | `VARCHAR(50) NULL` | snapshot kelas aktif saat diproses |
| `kamar_terakhir` | `VARCHAR(50) NULL` | snapshot kamar aktif saat diproses |
| `catatan` | `TEXT NULL` | catatan admin |
| `archived_at` | `TIMESTAMP NULL` | penanda arsip; NULL = catatan aktif |
| `jenis_arsip` | `VARCHAR(20) NULL` | `arsip` atau `pembatalan` |
| `alasan_arsip` | `VARCHAR(500) NULL` | alasan wajib saat mengarsipkan |
| `created_by` | `BIGINT UNSIGNED NULL` | admin yang memproses |
| `updated_by` | `BIGINT UNSIGNED NULL` | admin yang terakhir mengubah |
| `created_at` | `TIMESTAMP NULL` | waktu proses; **NULL untuk data warisan** |
| `updated_at` | `TIMESTAMP NULL ON UPDATE` | waktu perubahan terakhir |
| `santri_aktif_guard` | `INT` generated STORED | penjaga keunikan alumni aktif per santri |
| `nis_aktif_guard` | `VARCHAR(20)` generated STORED | penjaga keunikan alumni aktif per NIS |

`created_at` **sengaja tanpa** `DEFAULT CURRENT_TIMESTAMP`: baris warisan tidak
boleh mengaku dibuat pada saat migrasi dijalankan. NULL berarti “tidak
diketahui”, dan halaman menampilkannya sebagai *“tidak tercatat (data warisan)”*.

Kolom generated STORED memakai pola yang sudah terbukti di produksi sejak
migrasi 002 (`plotting_kelas.active_year_guard`, `santri_wali.active_guard`,
`tahun_ajaran.active_guard`), sehingga versi MySQL/MariaDB hosting sudah pasti
mendukungnya.

## 3. Indeks dan constraint

Ditambahkan:

```
UNIQUE KEY alumni_santri_aktif_unique (santri_aktif_guard)
UNIQUE KEY alumni_nis_aktif_unique    (nis_aktif_guard)
KEY        alumni_nis_index           (nis)
KEY        alumni_santri_index        (santri_id)
KEY        alumni_filter_index        (status_keluar, tahun_angkatan, tingkat)
KEY        alumni_arsip_index         (archived_at, tgl_keluar)
CONSTRAINT alumni_santri_fk  FOREIGN KEY (santri_id)  REFERENCES santri (id)
CONSTRAINT alumni_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
CONSTRAINT alumni_updater_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
```

### 3.1 Perubahan indeks yang disengaja

`UNIQUE KEY nis (nis)` **dilepas** dan digantikan pasangan
`alumni_nis_aktif_unique` + `alumni_nis_index`.

**Alasannya.** Tanpa perubahan ini, mengarsipkan atau membatalkan kelulusan akan
**mengunci NIS itu selamanya**: santri yang kelulusannya dibatalkan tidak akan
pernah bisa diluluskan lagi, karena baris arsipnya masih memakai NIS tersebut.

Keunikan **tidak dilonggarkan untuk data aktif** — satu NIS tetap hanya boleh
punya satu catatan alumni AKTIF. Ia hanya berhenti berlaku bagi baris yang sudah
diarsipkan.

**Urutannya disengaja:** kunci unik pengganti dipasang **lebih dahulu**, dan
`DROP INDEX nis` adalah pernyataan **terakhir** migrasi, dijaga syarat bahwa
kunci penggantinya sudah ada. Tidak ada satu momen pun ketika tabel berada tanpa
perlindungan NIS ganda untuk data aktif.

`alumni_santri_fk` memakai RESTRICT (bawaan): baris santri sumber tidak dapat
dihapus selama catatan alumninya ada. Sistem ini memang tidak pernah menghapus
santri — hanya mengarsipkannya — sehingga constraint ini jaring pengaman, bukan
perubahan alur.

---

## 4. Pemeriksaan SEBELUM migrasi

Jalankan pada **salinan `_test` dari backup produksi**, bukan pada produksi:

```bash
php bin/alumni_preflight.php
```

Bagian yang **wajib kosong** sebelum migrasi:

- **Bagian 1 — NIS ganda.** Kunci unik `alumni_nis_aktif_unique` tidak dapat
  dipasang bila ada dua catatan aktif dengan NIS sama. Karena `UNIQUE KEY nis`
  lama masih berlaku sampai migrasi selesai, bagian ini seharusnya sudah kosong;
  bila tidak, hentikan dan selidiki.

Bagian lain **tidak menghalangi** migrasi, tetapi harus dibaca:

- Bagian 2 — catatan warisan tanpa referensi santri (ditangani backfill).
- Bagian 3–6 — sisa data dari proses lama (alumni yang santrinya masih aktif,
  alumni yang masih memegang kelas atau kamar).
- Bagian 7 — `binlog_format`. Nilainya harus `ROW` atau `MIXED`. Pada
  `STATEMENT`, MariaDB menolak menulis tabel InnoDB di dalam transaksi
  READ COMMITTED (galat 1665) dan **setiap proses alumni akan gagal**.

Catat juga jumlah baris alumni sebelum migrasi:

```sql
SELECT COUNT(*) AS alumni_sebelum FROM alumni;
SELECT COUNT(*) AS alumni_dengan_foto FROM alumni WHERE foto IS NOT NULL AND foto <> '';
SELECT status_keluar, COUNT(*) FROM alumni GROUP BY status_keluar;
SELECT tahun_angkatan, COUNT(*) FROM alumni GROUP BY tahun_angkatan ORDER BY tahun_angkatan;
```

Simpan hasilnya. Nilai-nilai ini dipakai membandingkan keadaan sesudah migrasi.

## 5. Menjalankan migrasi

```bash
php bin/migrate.php status     # 011 harus tampil sebagai [menunggu]
php bin/migrate.php up
php bin/migrate.php status     # 011 harus tampil sebagai [diterapkan]
```

## 6. Pemeriksaan SESUDAH migrasi

```bash
php bin/alumni_verify.php --sebelum=<jumlah baris dari langkah 4>
```

Skrip ini memeriksa 25 hal: seluruh kolom baru ada, kedua kunci unik terpasang,
kunci unik `nis` lama sudah **digantikan** (bukan sekadar dilepas), ketiga kunci
asing terpasang, jumlah baris tidak berkurang, tidak ada alumni aktif ganda per
santri maupun per NIS, dan seluruh baris masih memiliki NIS, nama, serta foto.

Bandingkan juga hitungan per status dan per tahun dengan hasil langkah 4 —
nilainya harus **identik**.

## 7. Backfill referensi santri (opsional, terpisah)

Migrasi **tidak** mengisi `santri_id`. Pemasangan dilakukan skrip terpisah yang
secara bawaan hanya melapor:

```bash
php bin/alumni_backfill.php              # laporan saja, tidak menulis apa pun
php bin/alumni_backfill.php --terapkan   # pasangkan yang PASTI saja
```

Sebuah pasangan dianggap PASTI hanya bila **seluruh** syarat terpenuhi:

1. catatan alumni AKTIF dengan `santri_id` masih NULL;
2. NIS-nya cocok dengan **tepat satu** baris `santri`;
3. NIS itu dipakai **tepat satu** catatan alumni;
4. santri itu belum punya catatan alumni aktif lain.

Selebihnya dilaporkan sebagai **AMBIGU** dan **dibiarkan apa adanya**. Skrip
tidak pernah menebak dari kesamaan nama santri, nama ayah, atau nama ibu; tidak
pernah menghapus baris; dan setiap pemasangan diperiksa ulang di dalam transaksi
lalu dicatat pada `audit_logs` sebagai `alumni.backfill`.

Data ambigu dihubungkan satu per satu dari halaman Data Alumni → Detail →
**“Hubungkan ke santri sumber”**, setelah admin memastikan orangnya.

Backfill **boleh dijalankan kapan saja** setelah migrasi, termasuk berkali-kali.
Fitur alumni berjalan normal tanpa backfill; catatan warisan hanya tidak dapat
memakai tindakan “Batalkan kelulusan” sampai referensinya dipasang.

---

## 8. Rollback

```bash
php bin/migrate.php rollback   # mengembalikan 011
```

### PERINGATAN KEHILANGAN DATA YANG DISENGAJA

Rollback melepas kolom yang ditambahkan, sehingga hal berikut **hilang dari
skema** (baris alumninya sendiri **tidak** dihapus):

- referensi `santri_id`;
- snapshot kelas/kamar terakhir;
- catatan, penanda arsip, jenis arsip, dan alasan arsip;
- jejak pelaku dan waktu.

Akibatnya, baris alumni yang tadinya **berstatus arsip akan kembali tampil
sebagai catatan biasa**, karena penandanya sudah tidak ada. Jejak setiap
pengarsipan tetap dapat ditelusuri pada `audit_logs` — tabel itu tidak disentuh
rollback.

### Pemeriksaan wajib sebelum rollback

Rollback mencoba memasang kembali `UNIQUE KEY nis (nis)`. Itu hanya berhasil
bila **tidak ada NIS ganda di seluruh tabel**, termasuk baris arsip:

```sql
SELECT nis, COUNT(*) AS jumlah FROM alumni GROUP BY nis HAVING COUNT(*) > 1;
```

Bila query itu mengembalikan baris — misalnya karena sudah pernah ada
pembatalan lalu pemrosesan ulang — **putuskan dahulu baris mana yang
dipertahankan**. Rollback sengaja **tidak** menghapus atau menggabungkan baris
untuk Anda: langkah 9 pada berkas rollback melewatkan pemasangan kunci unik bila
masih ada NIS ganda, sehingga rollback tetap selesai tanpa galat, tetapi tabel
berakhir **tanpa kunci unik NIS**. Kondisi itu harus diselesaikan manual:

```sql
ALTER TABLE alumni ADD UNIQUE KEY nis (nis);
```

### Rollback kode

Rollback skema **tidak wajib** diikuti rollback kode, dan sebaliknya — tetapi
kode paket ini **membutuhkan** kolom migrasi 011. Bila skema di-rollback tanpa
mengembalikan kode, halaman alumni akan gagal. Urutan pemulihan yang benar:

1. kembalikan kode ke commit sebelum paket ini (`git checkout <commit-lama>`);
2. baru jalankan `php bin/migrate.php rollback`.

## 9. Yang TIDAK dilakukan migrasi ini

- Tidak mengisi `santri_id` (itu tugas `bin/alumni_backfill.php`).
- Tidak menghapus, memindahkan, atau mengganti nama berkas foto.
- Tidak menyentuh `santri`, `plotting_kelas`, `plotting_kamar`, `santri_wali`,
  `wali`, `users`, atau `audit_logs`.
- Tidak menambah tabel baru dan tidak membuat sistem audit kedua.
