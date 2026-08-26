# V2 Fase 5 — Migrasi dan Rollback

## 1. Apa yang diubah migrasi 009

Berkas: `database/migrations/009_v2_phase5_laporan_dan_push_receipt.sql`
Rollback: `database/rollbacks/009_v2_phase5_laporan_dan_push_receipt.sql`

**Laporan Fase 5 TIDAK memerlukan perubahan skema sama sekali.** Ia membaca
tabel yang sudah ada (`izin_pengajuan`, `izin_keputusan`, `izin_riwayat_status`,
`notifikasi_outbox`) tanpa denormalisasi dan tanpa tabel ringkasan.

Seluruh isi migrasi 009 adalah penyelesaian **temuan terbuka Fase 4 nomor 2**:
server sebelumnya berhenti pada tiket awal Expo, yang hanya membuktikan Expo
*menerima* pesan — bukan bahwa FCM/APNs benar-benar mengantarkannya.

| Objek | Tabel | Sifat |
| --- | --- | --- |
| `tiket_id VARCHAR(120) NULL` | `notifikasi_outbox` | tambah kolom |
| `receipt_status ENUM('Belum Diperlukan','Menunggu','Terkirim','Gagal','Tidak Tersedia') NOT NULL DEFAULT 'Belum Diperlukan'` | `notifikasi_outbox` | tambah kolom |
| `receipt_kode VARCHAR(60) NULL` | `notifikasi_outbox` | tambah kolom |
| `receipt_pesan VARCHAR(255) NULL` | `notifikasi_outbox` | tambah kolom |
| `receipt_diperiksa_pada DATETIME NULL` | `notifikasi_outbox` | tambah kolom |
| `receipt_percobaan SMALLINT UNSIGNED NOT NULL DEFAULT 0` | `notifikasi_outbox` | tambah kolom |
| `notifikasi_receipt_index (receipt_status, dikirim_pada, id)` | `notifikasi_outbox` | tambah indeks |

Seluruh kolom `NULL` atau punya `DEFAULT`, sehingga **baris outbox lama tetap
valid tanpa backfill** dan tanpa satu nilai pun berubah.

## 2. Sifat keamanan migrasi

| Sifat | Bukti |
| --- | --- |
| **Aditif murni** | Tidak ada `DROP TABLE`, `DROP COLUMN`, `TRUNCATE`, atau `DELETE`; diperiksa `tests/v2_phase5_static.php` §1 pada kode SQL setelah komentar dibuang |
| **Idempoten** | Setiap pernyataan dibungkus pemeriksaan `INFORMATION_SCHEMA`; menjalankan `php bin/migrate.php up` dua kali menghasilkan "Tidak ada migrasi baru" |
| **Tidak menyentuh data V1** | Tidak ada `ALTER TABLE` terhadap `perizinan`, `izin_pengajuan`, `izin_keputusan`, atau `izin_riwayat_status` |
| **Aman MySQL/cPanel** | Hanya `ALTER TABLE ADD COLUMN`/`ADD KEY` dengan penjagaan `INFORMATION_SCHEMA`; tidak memakai fitur khusus MariaDB |
| **Berpasangan** | Jumlah berkas migrasi = jumlah berkas rollback (diperiksa `tests/v2_phase3_static.php`) |

### Catatan MySQL 5.7

Migrasi 009 tidak memakai `CHECK` constraint, sehingga tidak terpengaruh
perbedaan MySQL 5.7 yang mengabaikannya. Query laporan juga sengaja **tidak**
memakai window function; median dihitung dengan `LIMIT 1 OFFSET n` agar berjalan
sama pada MySQL 5.7, MySQL 8, dan MariaDB.

### Waktu eksekusi

`ALTER TABLE` pada `notifikasi_outbox` mengunci tabel selama perubahan. Pada
outbox berukuran wajar (puluhan ribu baris) ini berlangsung di bawah satu detik.
Bila outbox sangat besar, jalankan di luar jam sibuk — notifikasi in-app yang
sedang dibaca pengguna akan menunggu selama `ALTER` berlangsung.

## 3. Prosedur naik

```bash
# 1. Preflight (backup + manifest + konflik). WAJIB.
php bin/v2_phase5_preflight.php

# 2. Uji pada salinan _test lebih dulu. WAJIB.
mysql -u USER -p nama_db_test < storage/backups/v2-phase5/<stamp>/database.sql
php bin/migrate.php up          # dengan DB_NAME menunjuk salinan _test
php bin/v2_phase5_verify.php storage/backups/v2-phase5/<stamp>/manifest.json

# 3. Produksi
php bin/migrate.php up
php bin/migrate.php status

# 4. Verifikasi produksi
php bin/v2_phase5_verify.php storage/backups/v2-phase5/<stamp>/manifest.json
```

Simpan keluaran langkah 1 dan 4 sebagai bukti rilis.

## 4. Prosedur rollback

Rollback **hanya** untuk staging atau pemulihan terencana, dan **hanya** setelah
backup terverifikasi tersedia.

```bash
mysql -u USER -p nama_db < database/rollbacks/009_v2_phase5_laporan_dan_push_receipt.sql
```

### Yang hilang dan yang tidak

| Hilang (disengaja) | Tetap ada |
| --- | --- |
| `tiket_id`, `receipt_status`, `receipt_kode`, `receipt_pesan`, `receipt_diperiksa_pada`, `receipt_percobaan` beserta isinya | Seluruh **baris** `notifikasi_outbox`, termasuk notifikasi in-app yang sudah dibaca |
| `notifikasi_receipt_index` | `status`, `dikirim_pada`, `percobaan`, `error_terakhir` — status pengiriman utama |
| | Seluruh data perizinan V1 dan V2 tanpa kecuali |
| | `perangkat_push`, `pengaturan_notifikasi`, `audit_logs` |

Setelah rollback, sistem kembali **persis ke perilaku Fase 4**: pengiriman push
tetap berjalan, hanya berhenti pada tiket awal tanpa rekonsiliasi receipt.

Terbukti pada latihan: rollback dijalankan pada database pulihan dan ID serta
nilai bisnis `perizinan` **tidak berubah**, sementara seluruh baris
`notifikasi_outbox` tetap utuh.

### Rollback kode laporan

Laporan Fase 5 **tidak memerlukan rollback basis data**. Untuk mengembalikan
keadaan fungsional sebelum Fase 5, cukup kembalikan kode:

```bash
git checkout prd-v2-fase-4 -- app/Report portal/laporan.php portal/laporan_cetak.php \
                              portal/laporan_csv.php api/v1/index.php app/bootstrap.php
```

atau kembalikan seluruh branch. Tidak ada tabel, kolom, atau indeks laporan yang
perlu dilepas.

## 5. Urutan pemulihan penuh (skenario terburuk)

Bila migrasi gagal di tengah dan keadaan basis data meragukan:

1. **Hentikan** cron worker notifikasi agar tidak menulis lagi:
   nonaktifkan baris cron pada cPanel.
2. **Jangan** menjalankan ulang migrasi untuk "memperbaiki".
3. Pulihkan dari backup preflight:
   ```bash
   mysql -u USER -p nama_db < storage/backups/v2-phase5/<stamp>/database.sql
   ```
4. Cocokkan dengan manifest:
   ```bash
   php bin/verify_restore.php storage/backups/v2-phase5/<stamp>/manifest.json
   ```
5. Kembalikan kode ke commit sebelum rilis.
6. Aktifkan kembali cron.
7. Catat kejadian pada `incident-runbook.md` §5.

## 6. Riwayat migrasi V2

| Migrasi | Fase | Isi |
| --- | --- | --- |
| 006 | V2 Fase 1 | Fondasi perizinan, akun, notifikasi, perangkat, pengaturan kanal |
| 007 | V2 Fase 2 | Jejak routing/penetapan/pembatalan, koreksi keputusan, indeks antrean |
| 008 | V2 Fase 4 | Kolom operasional notifikasi, percobaan, audit kanal, sewa worker |
| 009 | V2 Fase 5 | Receipt akhir push (`tiket_id` + kolom receipt + indeks) |

Seluruhnya aditif dan memiliki rollback berpasangan.
