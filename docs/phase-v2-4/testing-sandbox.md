# V2 Fase 4 — Prosedur Pengujian Sandbox (dapat diulang)

Prosedur ini menyiapkan lingkungan uji dari **kondisi bersih** dan menjalankan
seluruh pengujian Fase 4. Hasil yang dilaporkan pada `test-results.md` dan
`acceptance-status.md` berasal dari prosedur ini dan dapat diulang auditor.

## 1. Aturan keras

1. **Tidak pernah memakai database, dump, credential, atau data produksi.**
   Seluruh data uji sintetis (`SBX` untuk fixture bersama, `F4`/`WEB` untuk
   fixture per berkas uji).
2. Nama database uji **wajib** berakhiran `_test`. Seluruh berkas uji dan skrip
   seed menolak berjalan bila tidak.
3. Setiap berkas uji memerlukan penanda lingkungan eksplisit
   (`V2_PHASE4_RUN_INTEGRATION=1`, dan seterusnya).
4. **Tidak ada permintaan jaringan keluar.** Push memakai klien tiruan
   (`PushClient`), WhatsApp memakai adapter uji. Tidak ada satu pun pengujian
   yang menghubungi Expo atau penyedia WhatsApp.
5. Tidak ada perubahan kode yang dibuat semata-mata agar cocok dengan sandbox.

## 2. Versi runtime yang dipakai audit

| Komponen | Versi sandbox | Catatan kesesuaian cPanel |
| --- | --- | --- |
| PHP | 8.4.14 (CLI, NTS) | Jalankan ulang `php -l` dengan versi PHP cPanel sebelum rilis. |
| MariaDB | 12.3.2 | Migrasi 008 memakai `CHECK` (MariaDB 10.2+/MySQL 8.0.16+). Pada MySQL 5.7 CHECK diabaikan; aturan yang sama tetap ditegakkan lapisan aplikasi dan klausa WHERE. |
| Node.js | 26.7.0 | Minimum SDK 57 adalah Node 22.13.x. |
| npm | 11.19.0 | `npm ci` memakai `package-lock.json`. |
| TypeScript | 6.0.3 (devDependencies) | Tidak diubah. |
| Expo SDK | 57 (`expo ~57.0.15`) | **Tidak** di-upgrade. |
| React Native | 0.86.2 | **Tidak** di-upgrade. |
| expo-notifications | `~57.0.13` | Versi selaras SDK 57 (`npm view expo-notifications dist-tags` → latest 57.0.13). |

## 3. Menyiapkan database uji dari kondisi bersih

Sama seperti Fase 3, hanya **DDL** struktur V1 yang dipakai — seluruh `INSERT`
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

# 2. Buat database uji kosong.
mariadb -uroot -e "DROP DATABASE IF EXISTS webalhasan_test;
  CREATE DATABASE webalhasan_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS 'wah_test'@'127.0.0.1' IDENTIFIED BY '<password-lokal>';
  GRANT ALL PRIVILEGES ON \`%\_test\`.* TO 'wah_test'@'127.0.0.1';
  GRANT CREATE, DROP ON *.* TO 'wah_test'@'127.0.0.1';
  FLUSH PRIVILEGES;"

# 3. Muat struktur, lalu jalankan seluruh migrasi Fase 1–4.
mariadb -uroot webalhasan_test < /tmp/legacy_ddl.sql
php bin/migrate.php up      # 001 … 008
php bin/migrate.php status  # verifikasi
```

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

`PUSH_TOKEN_KEY` **tidak** perlu diisi pada `.env` sandbox: setiap berkas uji
Fase 4 menyetel kunci sandbox sendiri lewat `putenv()` sebelum bootstrap, dan
mewariskannya secara eksplisit ke server uji yang ia jalankan.

## 4. Fixture sintetis

```bash
V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php
```

Fixture Fase 3 dipakai ulang oleh pengujian kontrak API dan smoke test web Fase
4 (akun `sbx_*`, password `Sandbox#123`). Pengujian integrasi dan concurrency
Fase 4 membuat fixture-nya sendiri dengan akhiran acak dan **menghapusnya
kembali** pada blok `finally`, termasuk memulihkan pengaturan kanal ke keadaan
semula.

## 5. Menjalankan seluruh pengujian

```bash
MOBILE_APP_ROOT=/path/ke/alhasanApps bash bin/v2_phase4_run_all_tests.sh
```

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

# Regresi V1
PHASE2_RUN_INTEGRATION=1 php tests/phase2_integration.php
PHASE3_RUN_INTEGRATION=1 php tests/phase3_integration.php
PHASE4_RUN_INTEGRATION=1 php tests/phase4_integration.php
PHASE5_RUN_INTEGRATION=1 php tests/phase5_integration.php

# Regresi V2 Fase 1–3
V2_PHASE1_RUN_INTEGRATION=1 php tests/v2_phase1_integration.php
V2_PHASE2_RUN_INTEGRATION=1 php tests/v2_phase2_integration.php
V2_PHASE2_RUN_NAV=1         php tests/v2_phase2_navigasi_murobi.php
V2_PHASE2_RUN_WEB=1         php tests/v2_phase2_web_smoke.php
V2_PHASE3_RUN_API=1         php tests/v2_phase3_api_contract.php

# Fase 4
V2_PHASE4_RUN_INTEGRATION=1 php tests/v2_phase4_integration.php
V2_PHASE4_RUN_API=1         php tests/v2_phase4_api_contract.php
V2_PHASE4_RUN_CONCURRENCY=1 php tests/v2_phase4_concurrency.php
V2_PHASE4_RUN_WEB=1         php tests/v2_phase4_web_smoke.php
```

Port yang dipakai (dapat diubah lewat environment):

| Berkas | Environment port | Default |
| --- | --- | --- |
| `v2_phase4_api_contract.php` | `V2_PHASE4_PORT` | 8499 |
| `v2_phase4_web_smoke.php` | `V2_PHASE4_WEB_PORT` | 8714 |

> Bila sebuah pengujian dihentikan paksa (Ctrl-C), server uji dapat tertinggal
> memegang portnya dan membuat putaran berikutnya menguji server yang salah.
> Periksa dengan `ss -ltnp | grep 84` lalu hentikan prosesnya sebelum mengulang.

### Aplikasi mobile (dari akar repo alhasanApps)

```bash
npm ci
npx expo lint          # setara `npm run lint`
npx tsc --noEmit
npx expo export -p web # memastikan seluruh rute ter-bundle
```

> `expo-env.d.ts` di-`gitignore` dan dihasilkan Expo CLI. Pada klon baru,
> jalankan sekali `npx expo start` (lalu hentikan) atau buat berkasnya dengan
> isi `/// <reference types="expo/types" />` agar `tsc` mengenali tipe CSS
> modul bawaan Expo.

### Diagnosa penerima notifikasi

Bila sebuah peran tidak menerima notifikasi dan Anda perlu tahu sebabnya:

```bash
php bin/v2_phase4_diagnose_notifikasi.php               # sebaran + kesiapan relasi
php bin/v2_phase4_diagnose_notifikasi.php --pengajuan=123
```

Skrip ini **hanya membaca** dan aman dijalankan pada produksi. Ia melaporkan
notifikasi per peran, akun mana yang secara relasi memang dapat menjadi
penerima (penugasan murobi aktif, tautan pengurus, relasi wali–santri), dan —
untuk satu pengajuan — penerima yang dihitung untuk setiap peristiwa berikut
notifikasi yang benar-benar tercatat.

### Uji browser (opsional)

Dua skrip Playwright yang benar-benar merender halaman, mengklik tombol, dan
mengambil tangkapan layar — pelengkap smoke test HTTP di atas. Bukan bagian
rangkaian uji wajib karena memerlukan Node dan Playwright.

```bash
npx playwright install chromium
php tests/browser/seed-skenario.php

php -S 127.0.0.1:8900 -t . tests/v2_phase3_router.php &
ID_NOTIF_MUROBI=<id> node tests/browser/uji-website.mjs   # 37 pemeriksaan

# Aplikasi React Native lewat react-native-web, satu origin dengan API
php -S 127.0.0.1:8950 -t /path/ke/alhasanApps/dist tests/phase5_web_router.php &
ID_NOTIF_MUROBI=<id> node tests/browser/uji-aplikasi.mjs  # 25 pemeriksaan
```

Prosedur lengkap dan daftar variabel lingkungan: `tests/browser/README.md`.
Hasil terakhir: `test-results.md` §9.

## 6. Menguji adapter WhatsApp tanpa vendor

```bash
# Adapter uji: memverifikasi kontrak TANPA mengirim pesan nyata.
WHATSAPP_PROVIDER=fake WHATSAPP_FAKE_MODE=ok \
  php bin/notifikasi_worker.php --kanal=whatsapp --uji-coba

# Menguji jalur gagal sementara / permanen / verifikasi gagal:
WHATSAPP_FAKE_MODE=gagal          # kegagalan sementara -> backoff
WHATSAPP_FAKE_MODE=gagal_permanen # kegagalan permanen  -> retry berhenti
WHATSAPP_FAKE_MODE=verify_gagal   # pemeriksaan konfigurasi gagal
```

Adapter uji **menolak berjalan** ketika `APP_ENV=production`.

## 7. Yang TIDAK dapat diuji di sandbox

| Tidak diuji | Alasan | Tindak lanjut |
| --- | --- | --- |
| Kedatangan push pada perangkat Android NYATA | Sandbox cloud tanpa perangkat | `mobile-build-and-smoke-test.md` |
| Kedatangan push pada perangkat iOS NYATA | Idem, dan iOS memerlukan macOS + Xcode | `mobile-build-and-smoke-test.md` |
| Pengiriman WhatsApp oleh penyedia NYATA | Belum ada vendor yang disetujui pemilik produk | `whatsapp-provider-checklist.md` |
| Perilaku Expo Push Service sesungguhnya | Diganti klien tiruan agar tidak ada trafik keluar | Smoke test perangkat |
| PHP versi cPanel | Sandbox memakai PHP 8.4 | `php -l` + tes pada staging cPanel |
| Data produksi & performa nyata | Dilarang | Staging dengan salinan tersamar |
