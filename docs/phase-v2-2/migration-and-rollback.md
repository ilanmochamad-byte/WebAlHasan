# Migrasi & Rollback — V2 Fase 2

Migrasi: `database/migrations/007_v2_phase2_pengajuan_routing_keputusan.sql`
Rollback: `database/rollbacks/007_v2_phase2_pengajuan_routing_keputusan.sql`

## 1. Sifat migrasi

- **Aditif sepenuhnya.** Tidak ada `DROP TABLE`, `DROP COLUMN`, `DELETE`, atau
  `TRUNCATE`. Tabel `perizinan` V1 tidak disentuh sama sekali.
- **Dapat dijalankan ulang (idempoten).** Setiap `ALTER TABLE`/`ADD KEY`/
  `ADD CONSTRAINT` dibungkus pemeriksaan `INFORMATION_SCHEMA`; tabel koreksi
  memakai `CREATE TABLE IF NOT EXISTS`. Menjalankan file yang sama dua kali tidak
  menghasilkan error "duplicate column/key".
- **Fondasi tabel sudah ada sejak Fase 1** (`izin_pengajuan`, `izin_keputusan`,
  `izin_riwayat_status`, `izin_idempotency_keys`). Fase 2 hanya menambahkan:

| Objek | Tabel | Fungsi |
|---|---|---|
| `routing_kandidat`, `routing_catatan`, `routing_pada` | `izin_pengajuan` | jejak hasil routing (termasuk kasus 0 dan >1 kandidat) |
| `murobi_ditetapkan_oleh_user_id`, `murobi_ditetapkan_pada` | `izin_pengajuan` | jejak penetapan/penetapan ulang murobi oleh admin |
| `dibatalkan_oleh_user_id`, `dibatalkan_pada`, `alasan_pembatalan` | `izin_pengajuan` | jejak pembatalan oleh pengurus/admin |
| `izin_pengajuan_antrean_index (status, murobi_guru_id, id)` | `izin_pengajuan` | antrean murobi/admin |
| `izin_pengajuan_overlap_index (santri_id, status, tgl_izin, tgl_kembali)` | `izin_pengajuan` | pemeriksaan tumpang tindih |
| `dikoreksi_pada`, `jumlah_koreksi` | `izin_keputusan` | penanda keputusan yang sudah dikoreksi |
| tabel `izin_keputusan_koreksi` | baru | nilai sebelum/sesudah + alasan setiap koreksi (riwayat tidak pernah dihapus) |

## 2. Urutan eksekusi (staging/`_test` lebih dulu, WAJIB)

```bash
# 0. Pastikan berada pada salinan uji.
grep DB_NAME .env              # harus berakhiran _test

# 1. Preflight: backup + manifest + inventaris + laporan konflik.
php bin/v2_phase2_preflight.php
#    keluar 0 = aman; keluar 3 = ada konflik memblokir, JANGAN lanjut.
#    Output: storage/backups/v2-phase2/<timestamp>/
#      database.sql, manifest.json, inventory.json, conflicts.json

# 2. Migrasi naik.
php bin/migrate.php status
php bin/migrate.php up

# 3. Verifikasi.
php bin/v2_phase2_verify.php storage/backups/v2-phase2/<timestamp>/manifest.json

# 4. Pengujian.
php tests/v2_phase2_static.php
V2_PHASE2_RUN_INTEGRATION=1 php tests/v2_phase2_integration.php
V2_PHASE2_RUN_WEB=1        php tests/v2_phase2_web_smoke.php

# 5. Regresi V1 + Fase 1.
php tests/phase1_static.php ... php tests/phase5_static.php
php tests/v2_phase1_static.php
PHASE2_RUN_INTEGRATION=1 php tests/phase2_integration.php   # dst. sampai phase5
V2_PHASE1_RUN_INTEGRATION=1 php tests/v2_phase1_integration.php
```

`bin/v2_phase2_preflight.php` juga melaporkan kesiapan operasional yang
menentukan perilaku routing di produksi:

- jumlah tahun ajaran aktif (harus tepat 1),
- jumlah guru dengan penugasan murobi aktif (0 → semua pengajuan baru masuk
  antrean admin),
- jumlah penugasan pembimbing aktif (0 → pengurus belum bisa mengajukan).

Konflik yang **memblokir** (`exit 3`):

- keputusan berkapasitas `Admin Pengganti` tanpa alasan penggantian,
- pengajuan dengan lebih dari satu baris keputusan.

Konflik yang hanya **diperingatkan**: pasangan pengajuan aktif dengan rentang
tumpang tindih pada data lama (alur baru menolak kasus seperti ini, tetapi data
yang sudah ada tidak diubah oleh migrasi).

## 3. Rollback

```bash
php bin/migrate.php rollback     # melepas 007 saja
```

Yang dilepas: tabel `izin_keputusan_koreksi`, kolom jejak routing/penetapan/
pembatalan pada `izin_pengajuan`, kolom jejak koreksi pada `izin_keputusan`, serta
kedua indeks Fase 2.

Yang **tetap ada**: seluruh baris `izin_pengajuan`, `izin_keputusan`,
`izin_riwayat_status`, `izin_idempotency_keys`, dan tabel `perizinan` V1. Artinya
pengajuan serta keputusan yang sudah dibuat pada Fase 2 tidak hilang; hanya kolom
jejak tambahan dan riwayat koreksi yang ikut terhapus — karena itu **backup
preflight wajib dibuat lebih dulu** bila sudah ada koreksi keputusan.

Rollback juga dapat dijalankan ulang dengan aman (setiap pernyataan dijaga
`INFORMATION_SCHEMA`).

Setelah rollback, kode Fase 2 tidak boleh dibiarkan aktif: `IzinWorkflowService`
menulis ke kolom yang sudah dilepas. Urutan pemulihan yang benar:

1. `git checkout` ke commit sebelum Fase 2 (atau nonaktifkan portal Fase 2),
2. `php bin/migrate.php rollback`,
3. bila perlu, pulihkan `database.sql` dari preflight ke basis data `_test`
   lebih dulu dan bandingkan jumlah baris dengan `manifest.json`.

## 4. Pemulihan penuh dari backup

```bash
mysql -u <user> -p <db>_test < storage/backups/v2-phase2/<timestamp>/database.sql
php bin/verify_restore.php storage/backups/v2-phase2/<timestamp>/manifest.json
```

## 5. Modul perizinan lama

`admin/admin_izin.php` **tidak dihapus**. Berkas dan seluruh kodenya tetap ada,
tetapi kini mengalihkan (302) ke `/portal/izin.php`; tautan lama yang membawa
`?id=<n>` diarahkan ke `/portal/izin_detail.php?id=<n>` karena ID perizinan lama
dipertahankan sebagai ID pengajuan V2.

Membuka kembali modul lama untuk pemulihan darurat:

1. setel `IZIN_LEGACY_ENABLED=true` pada `.env`,
2. buka `admin/admin_izin.php?legacy=1`,
3. **matikan kembali segera setelah selesai** — penulisan lewat modul lama masuk
   langsung ke tabel `perizinan` dan tidak melewati routing, keputusan, riwayat,
   maupun audit V2.

Tabel `perizinan` dan seluruh datanya tetap utuh dan tidak dijadwalkan dihapus
sampai ada persetujuan eksplisit pengguna (PRD §8.20).
