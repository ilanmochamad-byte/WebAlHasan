#!/usr/bin/env bash
# Uji Fase 1 profil pengguna dan impor guru/pengurus (migrasi 035) pada DB
# salinan `test_*_test` berfixture sintetis. Keluaran ke docs/profil-impor/fase-1/bukti/uji/.
#
#   bash docs/profil-impor/fase-1/perintah-uji.sh
#
# Jalankan BERURUTAN; tidak bersamaan dengan skrip lain yang membangun DB.
# Uji browser memerlukan Playwright di tests/browser/node_modules.
set -u
cd "$(dirname "$0")/../../.." || exit 2
OUT=docs/profil-impor/fase-1/bukti/uji
mkdir -p "$OUT"
jalan() { local nama="$1"; shift; "$@" > "$OUT/$nama.txt" 2>&1; echo "$nama exit=$? $(grep -c '^\[lulus\]' "$OUT/$nama.txt")/$(grep -c '^\[gagal\]' "$OUT/$nama.txt")"; }

jalan statis php tests/profil_impor_f1_static.php
PIF1_DB=test_pif1_test bash tests/profil_impor_f1_db.sh > /dev/null
jalan integrasi env PIF1_DB=test_pif1_test php tests/profil_impor_f1_integration.php
PIF1_TANPA_035=1 PIF1_DB=test_pif1_mig_test bash tests/profil_impor_f1_db.sh > /dev/null
jalan migrasi env PIF1_DB=test_pif1_mig_test php tests/profil_impor_f1_migration.php
PIF1_DB=test_pif1_http_test bash tests/profil_impor_f1_db.sh > /dev/null
jalan http env PIF1_DB=test_pif1_http_test php tests/profil_impor_f1_http.php
PIF1_DB=test_pif1_brw_test bash tests/profil_impor_f1_db.sh > /dev/null
DB_NAME=test_pif1_brw_test php -S 127.0.0.1:8991 tests/redesain_web_tahap1_router.php > /dev/null 2>&1 &
SERVER=$!
sleep 1
jalan browser env BASE_URL=http://127.0.0.1:8991 node tests/browser/uji-profil-impor-f1.mjs
kill "$SERVER" 2>/dev/null
