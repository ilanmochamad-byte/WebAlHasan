#!/usr/bin/env bash
# Regresi PRD V7 Fase 1: jalur perizinan V2 (pengurus → murobi), fondasi
# penugasan, redesain web (perizinan, peta menu), V6 Fase 5, dan seluruh suite
# statis, pada DB salinan `test_*_test` berfixture sintetis. Jalankan dari akar
# repo web, BERURUTAN. Keluaran per suite di docs/phase-v7-1/bukti/regresi/,
# ringkasan di ringkasan.txt. Tidak menyentuh DB produksi.
#
#   bash docs/phase-v7-1/perintah-regresi.sh
#
# Sejak V7 Fase 1, `bin/migrate.php up` pada setiap rantai DB ikut memasang 030;
# suite yang mengasumsikan 029 sebagai migrasi terakhir dicatat sebagai oracle
# historis di README (tidak dilonggarkan).
set -u
cd "$(dirname "$0")/../.."
OUT=docs/phase-v7-1/bukti/regresi
mkdir -p "$OUT"
RINGKAS="$OUT/ringkasan.txt"
: > "$RINGKAS"
T="${TMPDIR:-/tmp}"

hitung() { local f="$1" l g; l=$(grep -cE '^ *\[(lulus|OK|ok)\]|^ *✓' "$f" 2>/dev/null); g=$(grep -cE '^ *\[(gagal|GAGAL|FAIL)\]|^ *✗' "$f" 2>/dev/null); echo "${l:-0}/${g:-0}"; }
jalan() { local nama="$1"; shift; "$@" > "$OUT/$nama.txt" 2>&1; local kode=$?; echo "$nama exit=$kode $(hitung "$OUT/$nama.txt")" | tee -a "$RINGKAS"; }
db() { "$@" > /dev/null 2>&1 || echo "PENYIAPAN GAGAL: $*" | tee -a "$RINGKAS"; }

echo "== Fondasi dan V2 (DB V7: 001–030 + fixture V7, tanpa pengajuan wali) ==" | tee -a "$RINGKAS"
for n in 1 2 3 4 5 6; do db env V7F1_DB=test_v7_f1_reg${n}_test bash tests/v7_phase1_db.sh; done
jalan penugasan-integrasi env PENUGASAN_RUN_INTEGRATION=1 DB_NAME=test_v7_f1_reg1_test php tests/penugasan_integration.php
jalan v2-fase4-integrasi env V2_PHASE4_RUN_INTEGRATION=1 DB_NAME=test_v7_f1_reg1_test php tests/v2_phase4_integration.php
jalan v2-fase5-integrasi env V2_PHASE5_RUN_INTEGRATION=1 DB_NAME=test_v7_f1_reg1_test php tests/v2_phase5_integration.php
jalan v2-fase1-integrasi env V2_PHASE1_RUN_INTEGRATION=1 DB_NAME=test_v7_f1_reg2_test php tests/v2_phase1_integration.php
jalan v2-fase2-integrasi env V2_PHASE2_RUN_INTEGRATION=1 DB_NAME=test_v7_f1_reg3_test php tests/v2_phase2_integration.php
jalan v2-fase4-konkurensi env V2_PHASE4_RUN_CONCURRENCY=1 DB_NAME=test_v7_f1_reg4_test php tests/v2_phase4_concurrency.php
jalan v2-fase3-api env V2_PHASE3_RUN_API=1 DB_NAME=test_v7_f1_reg5_test V2_PHASE3_PORT=8761 php tests/v2_phase3_api_contract.php
jalan v2-fase4-api env V2_PHASE4_RUN_API=1 DB_NAME=test_v7_f1_reg5_test php tests/v2_phase4_api_contract.php
jalan v2-fase5-api env V2_PHASE5_RUN_API=1 DB_NAME=test_v7_f1_reg5_test php tests/v2_phase5_api_contract.php
jalan v2-fase2-web env V2_PHASE2_RUN_WEB=1 DB_NAME=test_v7_f1_reg6_test V2_PHASE2_WEB_PORT=8762 php tests/v2_phase2_web_smoke.php
jalan v2-fase4-web env V2_PHASE4_RUN_WEB=1 DB_NAME=test_v7_f1_reg6_test php tests/v2_phase4_web_smoke.php
jalan v2-fase5-web env V2_PHASE5_RUN_WEB=1 DB_NAME=test_v7_f1_reg6_test php tests/v2_phase5_web_smoke.php
for n in 1 2 3 4 5 6; do jalan "v7-verify-sesudah-reg$n" env DB_NAME=test_v7_f1_reg${n}_test php bin/v7_phase1_verify.php; done

echo "== V6 Fase 5 (DB 027–030) ==" | tee -a "$RINGKAS"
db bash tests/v6_phase5_db.sh
jalan v6-fase5-integrasi env V6_RUN_INTEGRATION=1 DB_NAME=test_v6_f5_test php tests/v6_phase5_integration.php
db env V6F5_DB=test_v6_f5_http_test bash tests/v6_phase5_db.sh
jalan v6-fase5-http env V6F5_DB=test_v6_f5_http_test php tests/v6_phase5_http.php
jalan v6-fase5-rilis env V6_RUN_RILIS=1 php tests/v6_phase5_rilis.php

echo "== Redesain web (HTTP) ==" | tee -a "$RINGKAS"
db bash tests/redesain_web_tahap2_db.sh
jalan redesain-tahap2-http php tests/redesain_web_tahap2_http.php
db bash tests/redesain_web_tahap3_db.sh
jalan redesain-tahap3-http php tests/redesain_web_tahap3_http.php
db bash tests/redesain_web_tahap4_db.sh
jalan redesain-tahap4-http php tests/redesain_web_tahap4_http.php
db bash tests/redesain_web_tahap5_db.sh
jalan redesain-tahap5-http php tests/redesain_web_tahap5_http.php
db bash tests/redesain_web_tahap7_db.sh
jalan redesain-tahap7-http php tests/redesain_web_tahap7_http.php
db bash tests/redesain_web_tahap6_db.sh
T6=$(grep -oE 'TAHAP6_DB:-[a-z0-9_]+' tests/redesain_web_tahap6_db.sh | cut -d- -f2)
{ for f in v7_phase1 v6_phase3 v6_phase2 v6_phase1 v5_phase3 v5_phase2 v5_phase1; do DB_NAME="$T6" php "bin/${f}_rollback_preflight.php" && DB_NAME="$T6" php bin/migrate.php rollback; done; } > "$OUT/redesain-tahap6-rollback-030-024.txt" 2>&1
echo "redesain-tahap6-rollback exit=$? $(hitung "$OUT/redesain-tahap6-rollback-030-024.txt")" | tee -a "$RINGKAS"
jalan redesain-tahap6-http-tanpa-024-030 php tests/redesain_web_tahap6_http.php

echo "== Browser kerangka sembilan akun dan perizinan Tahap 4 (DB V7) ==" | tee -a "$RINGKAS"
db env V7F1_DB=test_v7_f1_kerangka_test bash tests/v7_phase1_db.sh
DB_NAME=test_v7_f1_kerangka_test php -S 127.0.0.1:8931 tests/redesain_web_tahap1_router.php > "$T/v7f1-reg-kerangka.log" 2>&1 & P=$!; sleep 1
jalan browser-kerangka-sembilan-akun bash -c 'cd tests/browser && BASE_URL=http://127.0.0.1:8931 node uji-redesain-web-tahap1.mjs'
kill $P

echo "== Statis (seluruh suite) ==" | tee -a "$RINGKAS"
for f in tests/*_static.php; do jalan "statis-$(basename "$f" .php)" php "$f"; done
echo "SELESAI" | tee -a "$RINGKAS"
