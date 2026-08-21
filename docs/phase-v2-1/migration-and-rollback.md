# Migrasi & Rollback — V2 Fase 1

Migrasi: `database/migrations/006_v2_phase1_perizinan_foundation.sql`
Rollback: `database/rollbacks/006_v2_phase1_perizinan_foundation.sql`

Migrasi ini **sepenuhnya aditif**. Tidak ada pernyataan `DROP`, `DELETE`, atau
`TRUNCATE` terhadap struktur maupun data V1, dan tabel `perizinan` lama sama sekali
tidak disentuh.

---

## 1. Preflight (wajib, sebelum apa pun)

```bash
php bin/v2_phase1_preflight.php
# opsional: --output=/path/lain
```

Menghasilkan `storage/backups/v2-phase1/<timestamp>/`:

| Berkas | Isi |
|---|---|
| `database.sql` | Backup lengkap seluruh tabel |
| `manifest.json` | Jumlah baris tiap tabel + total, rentang, dan **daftar ID** `perizinan` |
| `inventory.json` | Struktur kolom tabel yang tersentuh |
| `report.md` | Ringkasan yang dapat dibaca manusia |

Kode keluar:

| Kode | Arti | Tindakan |
|---:|---|---|
| `0` | Aman | Lanjut ke langkah 2 |
| `2` | Migrasi V1 (001–005) belum lengkap | Jalankan `php bin/migrate.php up` untuk V1 dahulu |
| `3` | **Ada baris `perizinan` tanpa santri yang cocok** | **JANGAN migrasi.** Perbaiki relasi yatim dahulu |

Kode `3` bersifat memblokir karena `izin_pengajuan.santri_id` memasang foreign key ke
`santri`; baris yatim akan menggagalkan migrasi di tengah jalan.

## 2. Uji pada salinan `_test` terlebih dahulu

```bash
# Salin database produksi ke <nama>_test, arahkan DB_NAME pada .env ke salinan itu.
php bin/migrate.php status
php bin/migrate.php up
php bin/v2_phase1_verify.php storage/backups/v2-phase1/<timestamp>/manifest.json
php tests/v2_phase1_static.php
V2_PHASE1_RUN_INTEGRATION=1 php tests/v2_phase1_integration.php
```

Seluruh tes regresi V1 juga harus tetap lulus:

```bash
php tests/phase1_static.php && php tests/phase2_static.php && php tests/phase3_static.php \
  && php tests/phase4_static.php && php tests/phase5_static.php
PHASE2_RUN_INTEGRATION=1 php tests/phase2_integration.php
PHASE3_RUN_INTEGRATION=1 php tests/phase3_integration.php
PHASE4_RUN_INTEGRATION=1 php tests/phase4_integration.php
PHASE5_RUN_INTEGRATION=1 php tests/phase5_integration.php
```

## 3. Migrasi naik

```bash
php bin/migrate.php up
```

Yang terjadi:

1. Role `pengurus` dan `orang_tua` ditambahkan (idempoten, `ON DUPLICATE KEY UPDATE`).
2. `users` mendapat `pengurus_id` dan `wali_id` (nullable, unik, ber-FK).
3. Delapan tabel V2 dibuat.
4. Baris `pengaturan_notifikasi` tunggal dibuat: in-app **ON**, push **OFF**, WhatsApp **OFF**.
5. Blok **BACKFILL** menyalin setiap baris `perizinan` ke `izin_pengajuan`:
   - `izin_pengajuan.id` = `perizinan.id` (ID dipertahankan persis),
   - `legacy_perizinan_id` = ID lama, `is_legacy = 1`,
   - status dipetakan `Pending → Diajukan`, `Disetujui → Disetujui`, `Ditolak → Ditolak`,
   - kolom pelaku tetap `NULL`,
   - satu baris `izin_riwayat_status` bertipe `migrasi_warisan` per pengajuan,
   - `AUTO_INCREMENT` diselaraskan agar ID warisan tidak pernah dipakai ulang.

Blok backfill idempoten: menjalankannya berkali-kali tidak menduplikasi baris.

## 4. Verifikasi

```bash
php bin/v2_phase1_verify.php storage/backups/v2-phase1/<timestamp>/manifest.json
```

Memeriksa: keberadaan seluruh tabel/kolom/role baru, tabel `perizinan` masih ada,
jumlah dan daftar ID izin lama identik dengan manifest pra-migrasi, seluruh nilai
bisnis lama terbaca identik, kolom pelaku warisan tetap `NULL`, `AUTO_INCREMENT`
melewati ID tertinggi, WhatsApp mati bawaan, dan jumlah baris tabel inti tidak berubah.

Kode keluar `0` = lulus, `2` = ada pemeriksaan yang gagal.

## 5. Backfill susulan (opsional)

Bila ada baris `perizinan` baru yang muncul setelah migrasi (misalnya modul lama
masih dipakai sementara di Fase 1):

```bash
php bin/v2_phase1_backfill.php
```

Skrip ini membaca blok yang sama dari file migrasi (di antara penanda
`BACKFILL:BEGIN` / `BACKFILL:END`), sehingga tidak ada duplikasi SQL.

## 6. Rollback

```bash
php bin/migrate.php rollback
```

Rollback melepas kedelapan tabel V2, kolom `pengurus_id`/`wali_id` beserta kunci dan
FK-nya, lalu menghapus role `pengurus`/`orang_tua` dan penetapannya. Tabel `perizinan`
lama tetap utuh, jadi ID dan nilai bisnis izin warisan tidak hilang.

> Rollback menghapus seluruh data V2 yang sudah terlanjur dibuat. Selalu ambil backup
> terverifikasi terlebih dahulu, dan lakukan hanya di staging atau sebagai pemulihan
> terencana yang disetujui pengguna.

## 7. Restore dan verifikasi backup

```bash
mysql -u <user> -p <db>_test < storage/backups/v2-phase1/<timestamp>/database.sql
# arahkan DB_NAME ke <db>_test
php bin/verify_restore.php storage/backups/v2-phase1/<timestamp>/manifest.json
```

`bin/verify_restore.php` membandingkan jumlah baris hasil restore dengan manifest.

## 8. Catatan cPanel

- Skrip `bin/*.php` hanya berjalan dari CLI; akses via HTTP membalas `404`.
- Folder `app`, `bin`, `database`, `docs`, `storage`, `tests` diblokir `.htaccess`.
- `.env`, `error_log`, `PRD.md`, `PRD-V2.md`, `AGENTS.md`, dan seluruh `*.sql` tidak dapat diunduh.
- Simpan `storage/backups/` di luar `public_html` bila hosting memungkinkan.
