# Uji browser (opsional)

Dua skrip Playwright yang benar-benar **merender halaman dan mengklik tombol**,
sebagai pelengkap smoke test HTTP yang hanya memeriksa kode status dan potongan
HTML.

Skrip ini **bukan** bagian rangkaian uji wajib: ia memerlukan Node dan
Playwright, sedangkan repositori ini PHP murni. Jalankan bila ingin bukti
visual atau memeriksa galat JavaScript.

Keduanya hanya boleh dijalankan terhadap basis data sandbox `*_test` berisi
data sintetis.

## Persiapan

```bash
npm install -g playwright && npx playwright install chromium

# Data skenario (dua pengajuan; keluaran JSON berisi id-nya)
php tests/browser/seed-skenario.php
```

## 1. Website portal dan panel admin

```bash
php -S 127.0.0.1:8900 -t . tests/v2_phase3_router.php &

ID=$(mariadb -uroot -N -B webalhasan_test -e "
  SELECT o.id FROM notifikasi_outbox o
    JOIN users u ON u.id = o.penerima_user_id
   WHERE u.username = 'sbx_murobi_a' AND o.kanal = 'InApp'
   ORDER BY o.id DESC LIMIT 1")

ID_NOTIF_MUROBI="$ID" node tests/browser/uji-website.mjs
```

37 pemeriksaan. Tangkapan layar tersimpan di `tests/browser/tangkapan/`.

## 2. Aplikasi React Native

Bundel yang diuji adalah bundel React Native yang sama dengan build perangkat,
dirender lewat `react-native-web`. Ini **bukan** produk web — hanya permukaan
pengujian, sama seperti yang dipakai Fase 3.

```bash
# Di repositori alhasanApps
EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8950/api/v1 npx expo export -p web

# Di repositori ini: aplikasi dan API pada satu origin
php -S 127.0.0.1:8950 -t /path/ke/alhasanApps/dist tests/phase5_web_router.php &

ID_NOTIF_MUROBI="$ID" node tests/browser/uji-aplikasi.mjs
```

25 pemeriksaan. Tangkapan layar tersimpan di
`tests/browser/tangkapan-aplikasi/`.

**Kedatangan push TIDAK diuji di sini.** `expo-notifications` mengembalikan
`tidak_didukung` pada web sesuai dokumentasi SDK 57. Kriteria push PRD Fase 4
tetap memerlukan smoke test manusia pada perangkat Android dan iOS nyata —
lihat `docs/phase-v2-4/mobile-build-and-smoke-test.md`.

## Variabel lingkungan

| Variabel | Default | Keterangan |
| --- | --- | --- |
| `BASE_URL` | `http://127.0.0.1:8900` | Alamat website (skrip 1) |
| `APP_URL` | `http://127.0.0.1:8950` | Alamat aplikasi + API (skrip 2) |
| `ID_NOTIF_MUROBI` | — | ID notifikasi milik murobi, untuk uji penolakan lintas akun |
| `OUT_DIR` | folder `tangkapan*` di samping skrip | Tujuan tangkapan layar |
| `CHROMIUM_PATH` | bawaan Playwright | Jalur binari Chromium bila tidak standar |
| `DB_NAME` | `webalhasan_test` | Basis data sandbox |
| `DB_USER` | `root` | Pengguna basis data |
| `MARIADB_BIN` | `mariadb` | Nama binari klien MariaDB/MySQL |

## Catatan lingkungan tanpa akses CDN

Bila sandbox memblokir CDN, Bootstrap/FontAwesome/chart.js tidak termuat:
tangkapan layar akan tampil tanpa gaya dan `Chart is not defined` muncul pada
dashboard V1. Keduanya efek lingkungan, bukan cacat aplikasi, dan sudah
disaring oleh skrip.
