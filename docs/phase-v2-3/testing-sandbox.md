# V2 Fase 3 — Prosedur Pengujian Sandbox (dapat diulang)

Dokumen ini menjelaskan cara menyiapkan lingkungan uji dari **kondisi bersih**
dan menjalankan seluruh pengujian Fase 3. Prosedur ini dipakai untuk hasil yang
dilaporkan pada `acceptance-status.md` dan dapat diulang oleh auditor.

## 1. Aturan keras

1. **Tidak pernah memakai database, dump, credential, atau data produksi.**
   Seluruh data uji bersifat sintetis dengan awalan `SBX`.
2. Nama database uji **wajib** berakhiran `_test`. Skrip seed dan seluruh
   pengujian menolak berjalan bila tidak.
3. Skrip seed dan pengujian hanya dapat dijalankan dari CLI dan memerlukan
   penanda lingkungan eksplisit (`V2_PHASE3_SEED=1`, `V2_PHASE3_RUN_API=1`).
4. Tidak ada perubahan kode yang dibuat semata-mata agar cocok dengan sandbox.

## 2. Versi runtime yang dipakai

| Komponen | Versi sandbox | Catatan kesesuaian cPanel |
| --- | --- | --- |
| PHP | 8.4.14 (CLI, NTS) | Kode memakai fitur PHP 8.1+ (enum-free, `readonly` tidak dipakai, promosi properti, `match`). Jalankan ulang `php -l` dengan versi PHP cPanel sebelum rilis. |
| MariaDB | 12.3.2 | Audit memakai instans lokal terisolasi. Migrasi memakai `CHECK` constraint dan kolom `GENERATED` yang didukung MariaDB 10.2+, tetapi staging MariaDB 10.x tetap wajib diuji. |
| Node.js | 26.7.0 | Untuk lint, `tsc`, dan export aplikasi; minimum SDK 57 adalah Node 22.13.x. |
| npm | 11.19.0 | `npm ci` memakai `package-lock.json` yang ada. |
| TypeScript | 6.0.3 (dari `devDependencies`) | Tidak diubah. |
| Expo SDK | 57 (`expo ^57.0.13`) | **Tidak** di-upgrade. |

> Perbedaan versi PHP dan MariaDB antara sandbox dan cPanel adalah risiko yang tercatat.
> Lihat `acceptance-status.md` bagian risiko.

## 3. Menyiapkan database uji dari kondisi bersih

Skema tabel warisan (`santri`, `guru`, `kamar`, `kelas`, `tahun_ajaran`,
`plotting_*`, `perizinan`, `users`, …) tidak dibuat oleh migrasi — ia berasal
dari sistem V1. Untuk sandbox, **hanya definisi struktur (DDL)** yang dipakai:
seluruh pernyataan `INSERT` dibuang sehingga tidak ada satu baris data produksi
pun yang masuk.

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

# 2. Buat database uji kosong.
mariadb -uroot -e "DROP DATABASE IF EXISTS webalhasan_test;
  CREATE DATABASE webalhasan_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS 'wah_test'@'localhost' IDENTIFIED BY '<password-lokal>';
  GRANT ALL PRIVILEGES ON \`%\_test\`.* TO 'wah_test'@'localhost';
  GRANT CREATE, DROP ON *.* TO 'wah_test'@'localhost';
  FLUSH PRIVILEGES;"

# 3. Muat struktur, lalu jalankan seluruh migrasi Fase 1–3.
mariadb -uroot webalhasan_test < /tmp/legacy_ddl.sql
php bin/migrate.php up      # 001 … 007, semuanya baru
php bin/migrate.php status  # verifikasi
```

> Hak `CREATE, DROP` pada `*.*` hanya dibutuhkan oleh pengujian backup/restore
> Fase 5 yang membuat database `*_test` sementara. Jangan diberikan di produksi.

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

## 4. Fixture sintetis

```bash
V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php
```

Menghasilkan:

| Objek | Isi |
| --- | --- |
| Tahun ajaran | `2026/2027` Ganjil, status Aktif |
| Kamar | `SBX Kamar A`, `SBX Kamar B`, `SBX Kamar C` (C sengaja tanpa murobi) |
| Kelas | `SBX Kelas 1` |
| Guru | Murobi A (kamar A), Murobi B (kamar B), Murobi C (kelas 1), Guru Tanpa Murobi |
| Pengurus | Pengurus A (pembimbing kamar A + C), Pengurus B (pembimbing kamar B) |
| Wali | Wali A (anak: Santri A1), Wali B (anak: Santri B1) |
| Santri | A1 (routing tunggal), A2 (kamar A + kelas 1 → **dua** kandidat), B1 (murobi B), C1 (**nol** kandidat) |
| Akun | `sbx_admin`, `sbx_pengurus_a`, `sbx_pengurus_b`, `sbx_murobi_a`, `sbx_murobi_b`, `sbx_murobi_c`, `sbx_guru_biasa`, `sbx_ortu_a`, `sbx_ortu_b` |

Password fixture: `Sandbox#123` — hanya berlaku di sandbox dan bukan credential
produksi. Skrip juga mereset `AUTO_INCREMENT` tabel warisan yang kosong agar ID
mulai dari 1; tanpa ini beberapa pengujian V1 yang memakai pelaku `user_id = 1`
gagal menyiapkan fixture-nya sendiri.

Skrip bersifat aman diulang: bila `sbx_admin` sudah ada, ia berhenti tanpa
mengubah apa pun.

## 5. Menjalankan pengujian

### Backend (dari akar repo WebAlHasan)

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

# Integrasi V1 (regresi)
PHASE2_RUN_INTEGRATION=1 php tests/phase2_integration.php
PHASE3_RUN_INTEGRATION=1 php tests/phase3_integration.php
PHASE4_RUN_INTEGRATION=1 php tests/phase4_integration.php
PHASE5_RUN_INTEGRATION=1 php tests/phase5_integration.php

# Integrasi V2 Fase 1–2 (regresi)
V2_PHASE1_RUN_INTEGRATION=1 php tests/v2_phase1_integration.php
V2_PHASE2_RUN_INTEGRATION=1 php tests/v2_phase2_integration.php
V2_PHASE2_RUN_NAV=1        php tests/v2_phase2_navigasi_murobi.php
V2_PHASE2_RUN_WEB=1        php tests/v2_phase2_web_smoke.php

# Kontrak REST API Fase 3 (HTTP sungguhan, termasuk concurrency dua proses)
V2_PHASE3_RUN_API=1 php tests/v2_phase3_api_contract.php
```

`tests/v2_phase3_api_contract.php` menjalankan server bawaan PHP sendiri
(`tests/v2_phase3_router.php`) pada `127.0.0.1:8399`, memanggil API lewat HTTP,
lalu mematikan server dan menghapus pengajuan yang ia buat. Port dapat diubah
dengan `V2_PHASE3_PORT`.

### Aplikasi mobile (dari akar repo alhasanApps)

```bash
npm ci
npx expo lint          # setara `npm run lint`
npx tsc --noEmit
npx expo export -p web # memastikan seluruh rute ter-bundle
```

Untuk memperbarui tipe rute (`typedRoutes` aktif) jalankan `npx expo start`
sekali; berkas `.expo/types/router.d.ts` akan diregenerasi. Berkas ini
di-`gitignore` dan tidak ikut commit.

### Smoke test web + API pada satu origin

```bash
DB_NAME=webalhasan_test php -S 127.0.0.1:8123 \
  -t /path/ke/alhasanApps/dist /path/ke/WebAlHasan/tests/phase5_web_router.php
```

## 6. Yang TIDAK dapat diuji di sandbox

| Tidak diuji | Alasan | Tindak lanjut |
| --- | --- | --- |
| Perangkat Android nyata | Sandbox cloud tanpa perangkat/emulator | `mobile-build-and-smoke-test.md` |
| Perangkat iOS nyata | Idem, dan iOS memerlukan macOS + Xcode | `mobile-build-and-smoke-test.md` |
| PHP versi cPanel | Sandbox memakai PHP 8.4 | Jalankan `php -l` + tes API pada staging cPanel |
| Data produksi & performa nyata | Dilarang | Uji pada staging dengan salinan yang disamarkan |
