# V2 Fase 5 — Prosedur Pengujian Sandbox (dapat diulang)

Prosedur ini menyiapkan lingkungan uji dari **kondisi bersih** dan menjalankan
seluruh pengujian Fase 1–5. Hasil pada `test-results.md`, `acceptance-status.md`,
dan `bukti-performa.md` berasal dari prosedur ini dan dapat diulang auditor.

## 1. Aturan keras

1. **Tidak pernah memakai database, dump, credential, atau data produksi.**
   Seluruh data uji sintetis: `SBX` (fixture peran, Fase 3), `F4`/`WEB`
   (Fase 4), `F5` (Fase 5), `P5` (fixture performa), `DRILL5` (latihan restore).
2. Nama database uji **wajib** berakhiran `_test`. Seluruh berkas uji, skrip
   seed, fixture, pengukuran, dan latihan restore menolak berjalan bila tidak.
3. Setiap berkas uji memerlukan penanda lingkungan eksplisit
   (`V2_PHASE5_RUN_INTEGRATION=1`, dan seterusnya).
4. **Tidak ada permintaan jaringan keluar.** Push memakai klien tiruan
   (`PushClient`/`PushReceiptClient`), WhatsApp memakai adapter uji. Tidak ada
   satu pun pengujian yang menghubungi Expo atau penyedia WhatsApp.
5. Fixture performa dan latihan restore **menolak** `APP_ENV=production`.
6. Tidak ada perubahan kode yang dibuat semata-mata agar cocok dengan sandbox.

## 2. Versi runtime yang dipakai

| Komponen | Versi | Catatan kesesuaian cPanel |
| --- | --- | --- |
| PHP | 8.4.21 (CLI, NTS) | Ulangi `php -l` dengan versi PHP cPanel sebelum rilis |
| MariaDB | 10.11.14 | Migrasi 009 hanya `ADD COLUMN`/`ADD KEY` berpenjaga `INFORMATION_SCHEMA`; aman pada MySQL 5.7/8 |
| Node.js | 22.23.2 | Minimum SDK 57 adalah Node 22.13.x |
| Expo SDK | 57 (`expo ~57.0.15`) | **Tidak** di-upgrade |
| React Native | 0.86.2 | **Tidak** di-upgrade |
| expo-print / expo-sharing / expo-file-system | `~57.0.1` / `~57.0.14` / `~57.0.5` | Seluruhnya selaras SDK 57 |

Median laporan sengaja memakai `LIMIT 1 OFFSET n`, bukan window function, agar
berjalan sama pada MySQL 5.7 yang masih umum pada cPanel.

## 3. Menyiapkan database uji dari kondisi bersih

Sama seperti Fase 3–4, hanya **DDL** struktur V1 yang dipakai — seluruh `INSERT`
dibuang sehingga tidak ada satu baris data produksi pun yang masuk.

```bash
# 1. Ekstrak DDL saja (tanpa satu pun INSERT) dari berkas struktur V1.
python3 - <<'PY'
lines = open('k1807225_webalhasan.sql', encoding='utf-8', errors='replace').read().split('\n')
out, skip = [], False
for ln in lines:
    if ln.upper().startswith('INSERT INTO'):
        skip = True
    if skip:
        if ln.rstrip().endswith(';'):
            skip = False
        continue
    out.append(ln)
ddl = '\n'.join(out)
assert 'INSERT INTO' not in ddl.upper(), 'DDL masih memuat data'
open('/tmp/legacy_ddl.sql', 'w', encoding='utf-8').write(ddl)
PY

# 2. Buat database uji kosong DAN database tujuan latihan restore.
mariadb -uroot -e "DROP DATABASE IF EXISTS webalhasan_test;
  CREATE DATABASE webalhasan_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS 'wah_test'@'127.0.0.1' IDENTIFIED BY '<password-lokal>';
  CREATE USER IF NOT EXISTS 'wah_test'@'localhost'  IDENTIFIED BY '<password-lokal>';
  GRANT ALL PRIVILEGES ON \`%\_test\`.*          TO 'wah_test'@'127.0.0.1';
  GRANT ALL PRIVILEGES ON \`%\_test\_restore\`.* TO 'wah_test'@'127.0.0.1';
  GRANT ALL PRIVILEGES ON \`%\_test\`.*          TO 'wah_test'@'localhost';
  GRANT ALL PRIVILEGES ON \`%\_test\_restore\`.* TO 'wah_test'@'localhost';
  GRANT CREATE, DROP ON *.* TO 'wah_test'@'127.0.0.1';
  GRANT CREATE, DROP ON *.* TO 'wah_test'@'localhost';
  FLUSH PRIVILEGES;"

# 3. Muat struktur, lalu jalankan seluruh migrasi Fase 1–5.
mariadb -uroot webalhasan_test < /tmp/legacy_ddl.sql
php bin/migrate.php up      # 001 … 009
php bin/migrate.php status  # verifikasi
```

> **Dua akun `wah_test` disengaja.** Klien MySQL/MariaDB dapat menerjemahkan
> host `127.0.0.1` menjadi `localhost` lewat resolusi balik, sehingga hak akses
> yang hanya diberikan untuk `@127.0.0.1` tidak terpakai saat latihan restore
> memakai klien baris perintah. Hak untuk `%_test_restore` diperlukan karena
> latihan restore membuat database tujuan terpisah.

`.env` sandbox (tidak pernah di-commit):

```
APP_ENV=testing
APP_DEBUG=true
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=webalhasan_test
DB_USER=wah_test
DB_PASSWORD=<password-lokal>
API_TOKEN_HASH_SECRET=<nilai acak khusus sandbox>
API_TOKEN_TTL_DAYS=30
IZIN_LEGACY_ENABLED=false
```

`PUSH_TOKEN_KEY` **tidak** perlu diisi: setiap berkas uji menyetel kunci sandbox
sendiri lewat `putenv()` sebelum bootstrap dan mewariskannya ke server uji.

## 4. Fixture

```bash
# Fixture peran (dipakai kontrak API dan smoke test web)
V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php

# Fixture performa (dipakai tests/v2_phase5_performance.php)
V2_PHASE5_FIXTURE=1 php bin/v2_phase5_fixture.php --jumlah=1000

# Membersihkan fixture performa
V2_PHASE5_FIXTURE=1 php bin/v2_phase5_fixture.php --bersihkan
```

Akun fixture: `sbx_admin`, `sbx_pengurus_a`, `sbx_pengurus_b`, `sbx_murobi_a`,
`sbx_murobi_b`, `sbx_murobi_c`, `sbx_guru_biasa`, `sbx_ortu_a`, `sbx_ortu_b`.
Password `Sandbox#123` — bukan credential produksi.

Pengujian integrasi dan smoke test Fase 5 membuat fixture-nya **sendiri** dengan
akhiran acak dan menghapusnya kembali pada blok `finally`, termasuk memulihkan
pengaturan kanal ke keadaan semula.

### Isolasi antar berkas uji

Fixture Fase 5 memberi setiap barisnya penanda unik per putaran (`F5<suffix>`)
yang **ikut menjadi filter pencarian** pada seluruh perhitungan. Rentang tanggal
saja tidak cukup: cakupan admin melihat **semua** baris, dan berkas uji lain
juga membuat pengajuan bertanggal jauh ke depan. Tanpa penanda ini, jumlah yang
diharapkan berubah tergantung berkas uji mana yang kebetulan berjalan lebih
dulu — kegagalan palsu yang menyesatkan auditor.

## 5. Menjalankan seluruh pengujian

```bash
MOBILE_APP_ROOT=/path/ke/alhasanApps bash bin/v2_phase5_run_all_tests.sh
```

Hasil yang diharapkan: **28 berkas, 2.230 pemeriksaan, 0 gagal.**

Atau satu per satu:

```bash
# Statis (tanpa database)
php tests/phase1_static.php
php tests/phase2_static.php
php tests/phase3_static.php
php tests/phase4_static.php
MOBILE_APP_ROOT=/path/ke/alhasanApps php tests/phase5_static.php
php tests/v2_phase1_static.php
php tests/v2_phase2_static.php
MOBILE_APP_ROOT=/path/ke/alhasanApps php tests/v2_phase3_static.php
MOBILE_APP_ROOT=/path/ke/alhasanApps php tests/v2_phase4_static.php
MOBILE_APP_ROOT=/path/ke/alhasanApps php tests/v2_phase5_static.php

# Regresi V1
PHASE2_RUN_INTEGRATION=1 php tests/phase2_integration.php
PHASE3_RUN_INTEGRATION=1 php tests/phase3_integration.php
PHASE4_RUN_INTEGRATION=1 php tests/phase4_integration.php
PHASE5_RUN_INTEGRATION=1 php tests/phase5_integration.php

# Regresi V2 Fase 1–4
V2_PHASE1_RUN_INTEGRATION=1 php tests/v2_phase1_integration.php
V2_PHASE2_RUN_INTEGRATION=1 php tests/v2_phase2_integration.php
V2_PHASE2_RUN_NAV=1         php tests/v2_phase2_navigasi_murobi.php
V2_PHASE2_RUN_WEB=1         php tests/v2_phase2_web_smoke.php
V2_PHASE3_RUN_API=1         php tests/v2_phase3_api_contract.php
V2_PHASE4_RUN_INTEGRATION=1 php tests/v2_phase4_integration.php
V2_PHASE4_RUN_API=1         php tests/v2_phase4_api_contract.php
V2_PHASE4_RUN_CONCURRENCY=1 php tests/v2_phase4_concurrency.php
V2_PHASE4_RUN_WEB=1         php tests/v2_phase4_web_smoke.php

# Fase 5
V2_PHASE5_RUN_INTEGRATION=1 php tests/v2_phase5_integration.php
V2_PHASE5_RUN_API=1         php tests/v2_phase5_api_contract.php
V2_PHASE5_RUN_WEB=1         php tests/v2_phase5_web_smoke.php
V2_PHASE5_RUN_PERF=1        php tests/v2_phase5_performance.php
```

Berkas uji Fase 5 memilih **port bebas** secara otomatis, sehingga server uji
dari putaran sebelumnya yang belum berhenti tidak diam-diam menjadi sasaran
pengujian. Port dapat dipaksa lewat `V2_PHASE5_PORT` dan `V2_PHASE5_WEB_PORT`;
bila port itu sudah dipakai, pengujian berhenti dengan pesan yang jelas
alih-alih menghasilkan hasil palsu.

## 6. Pengukuran performa dan `EXPLAIN`

```bash
V2_PHASE5_FIXTURE=1 php bin/v2_phase5_fixture.php --jumlah=1000
V2_PHASE5_UKUR=1    php bin/v2_phase5_ukur_laporan.php --ulang=9 --explain
```

Keluarannya disalin apa adanya ke `bukti-performa.md`. Untuk menguji perilaku
pertumbuhan, ulangi dengan `--jumlah=20000`.

## 7. Latihan backup → restore → migrasi → rollback

```bash
V2_PHASE5_DRILL=1 php bin/v2_phase5_backup_restore_drill.php
```

Membuat data warisan sintetis, mem-backfill-nya, membuat backup + manifest,
memulihkannya ke database `_test` **kedua**, mencocokkan seluruh tabel dengan
manifest, menjalankan migrasi 009, membuktikan ID dan nilai bisnis perizinan
lama tidak berubah, lalu menjalankan rollback dan membuktikan hal yang sama.
Seluruh artefak dan database tujuan dibersihkan pada akhir.

Hasil yang diharapkan: **17 pemeriksaan lulus**.

## 8. Kesiapan cron

```bash
php bin/v2_phase5_cron_check.php
php bin/v2_phase5_cron_check.php --ambang-menit=5
```

Hanya membaca; aman dijalankan pada produksi.

## 9. Aplikasi mobile (dari akar repositori alhasanApps)

```bash
npm ci
npx tsc --noEmit
npx expo lint
npx expo export -p web
```

> `expo-env.d.ts` di-`gitignore` dan dihasilkan Expo CLI. Pada klon baru,
> jalankan sekali `npx expo start` (lalu hentikan) atau buat berkasnya dengan
> isi `/// <reference types="expo/types" />`.
>
> Tipe rute Expo Router (`.expo/types/router.d.ts`) juga dihasilkan CLI. Setelah
> menambah berkas rute baru, jalankan `npx expo customize tsconfig.json` atau
> `npx expo start` sekali agar `tsc` mengenali rute barunya.
>
> `node_modules` yang dipasang pada satu sistem operasi **tidak** dapat dipakai
> di sistem lain: binding native (`lightningcss`, `unrs-resolver`) berbeda per
> platform. Bila memindahkan proyek antar OS, jalankan `npm ci` ulang.

## 10. Yang TIDAK dapat diuji di sandbox

| Tidak diuji | Alasan | Tindak lanjut |
| --- | --- | --- |
| Kedatangan push dan deep link pada perangkat Android/iOS NYATA | Perangkat fisik tidak tersedia | `uji-manual-tertunda.md` §1–§2 |
| Receipt akhir terhadap Expo NYATA | Tidak ada trafik keluar pada pengujian | `uji-manual-tertunda.md` §1 |
| Pengiriman WhatsApp oleh penyedia NYATA | DITANGGUHKAN | `../phase-v2-4/whatsapp-provider-checklist.md` |
| Migrasi, restore, dan cron pada cPanel produksi | Dilarang pada pekerjaan ini | `uji-manual-tertunda.md` §5 |
| PHP versi cPanel | Sandbox memakai PHP 8.4 | `php -l` pada staging cPanel |
| Data produksi dan performa nyata | Dilarang | Staging dengan salinan tersamar |
