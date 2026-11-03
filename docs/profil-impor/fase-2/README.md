# Profil pengguna aplikasi — Fase 2 (uji dan dokumen sisi web)

**Status audit 4 November 2026:** lulus teknis setelah koreksi. Hasil independen dan gerbang rilis ada di [Audit Codex](AUDIT-CODEX.md). Catatan implementator di bawah dipertahankan sebagai riwayat.

Implementasi Fase 2 ada di repo **alhasanApps** branch `claude/profil-impor-fase-2`, commit `b887d7b` (dari `origin/main` `b9a4e10`). Handoff lengkap, bukti, penafsiran PF2-1…PF2-8, temuan T-1…T-3, dan batas: `docs/profil-impor/fase-2/README.md` repo aplikasi.

Branch web `claude/profil-impor-fase-2` dari `origin/main` `18539cd` (PR #113, memuat audit Fase 1 `c4647be`; `git diff` kosong) **hanya menambah uji dan dokumen**. Kode aplikasi web, API, migrasi, dan kontrak Fase 1 tidak berubah; tidak ada perbaikan server yang diselipkan.

## Berkas

| Berkas | Isi |
| --- | --- |
| `tests/profil_impor_f2_app_seed.php` | Seed sintetis di atas `tests/profil_impor_f1_db.sh`: `sbx_murobi_c` password sementara (PI-1), `sbx_v5_ganda` nama sangat panjang + empat status, email `sbx_admin` (uji email akun lain). Keluaran JSON id akun dan penugasan Murobi untuk uji pencabutan. |
| `tests/browser/uji-profil-impor-f2-app.mjs` | Expo **web** (Chromium 375 px) melawan API/DB uji nyata, B-A…B-L. Hasil implementator **102/0**; kontrol kode mobile `b9a4e10` **0/1**. |
| `tests/browser/uji-profil-impor-f2-android.mjs` | Penggerak **Android emulator** (uiautomator + adb, dev client Debug + Metro), N-A…N-E. Hasil implementator **25/0**. |

## Menjalankan

```bash
PIF1_DB=test_pif2_app_test bash tests/profil_impor_f1_db.sh
PIF2_DB=test_pif2_app_test php tests/profil_impor_f2_app_seed.php > /tmp/pif2-ids.json
DB_NAME=test_pif2_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8962 tests/redesain_web_tahap1_router.php &
# salinan alhasanApps TANPA .env* (node_modules direktori nyata), lalu:
EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8962/api/v1 npx expo start --web --port 8963 --clear
cd tests/browser && DB_NAME=test_pif2_app_test PIF2_WEB=http://127.0.0.1:8962 PIF2_APP=http://127.0.0.1:8963 SEED_JSON=/tmp/pif2-ids.json OUT_DIR=/tmp/pif2 node uji-profil-impor-f2-app.mjs
# Android: adb reverse tcp:8962 tcp:8962; adb reverse tcp:8963 tcp:8963; foto contoh di /sdcard/Pictures; dev client Debug BARU (ada modul expo-image-picker)
DB_NAME=test_pif2_app_test METRO=http://127.0.0.1:8963 SEED_JSON=/tmp/pif2-ids.json OUT_DIR=/tmp/pif2-android node uji-profil-impor-f2-android.mjs
```

Kedua uji mengubah data; bangun ulang DB dan seed sebelum mengulang. Metro dapat menyajikan bundel lama walau sumber berubah: mulai ulang dengan `--clear` sebelum menyimpulkan hasil.

## Regresi statis

`php tests/profil_impor_f1_static.php` → **70/1** (audit Fase 1: 71/0): satu-satunya kegagalan adalah oracle historis "Perubahan hanya pada berkas yang direncanakan" Fase 1, yang kini juga melihat berkas uji/dokumen Fase 2 di atas. Kode yang diperiksa suite itu tidak berubah; oracle tidak dilonggarkan.

## Batas

MariaDB 12.3.2 lokal dan data sintetis saja. iOS diuji manual pada simulator (build Release baru), tidak dengan skrip. Perangkat fisik, build distribusi, Push nyata, MariaDB 10.6.28/MySQL/cPanel, migrasi 035 produksi, dan UAT belum diuji. Tidak merge, deploy, atau rilis.
