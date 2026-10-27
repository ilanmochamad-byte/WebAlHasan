#!/usr/bin/env bash
# Regresi independen auditor PRD V7 Fase 3: V7 Fase 1–2 (layanan, HTTP, browser, migrasi, probe
# audit Codex), jalur perizinan dan notifikasi V2, worker Push V5/V6, fondasi
# penugasan, redesain web, V6 Fase 5, dan seluruh suite statis, pada DB salinan
# `test_*_test` berfixture sintetis. Jalankan dari akar repo web, BERURUTAN.
# Keluaran per suite di docs/phase-v7-3/audit-codex/regresi/, ringkasan di ringkasan.txt.
#
#   bash docs/phase-v7-3/perintah-regresi-audit.sh
#
# Fase 3 tidak menambah migrasi; suite yang mengasumsikan 030 sebagai migrasi
# terakhir tetap oracle historis seperti di Fase 2 (tidak dilonggarkan).
set -u
cd "$(dirname "$0")/../.."
OUT=docs/phase-v7-3/audit-codex/regresi
mkdir -p "$OUT"
RINGKAS="$OUT/ringkasan.txt"
: > "$RINGKAS"
T="${TMPDIR:-/tmp}"

hitung() { local f="$1" l g; l=$(grep -cE '^ *\[(lulus|OK|ok)\]|^ *✓' "$f" 2>/dev/null); g=$(grep -cE '^ *\[(gagal|GAGAL|FAIL)\]|^ *✗' "$f" 2>/dev/null); echo "${l:-0}/${g:-0}"; }
jalan() { local nama="$1"; shift; "$@" > "$OUT/$nama.txt" 2>&1; local kode=$?; echo "$nama exit=$kode $(hitung "$OUT/$nama.txt")" | tee -a "$RINGKAS"; }
db() { "$@" > /dev/null 2>&1 || echo "PENYIAPAN GAGAL: $*" | tee -a "$RINGKAS"; }

echo "== V7 Fase 2 pada kode Fase 3 ==" | tee -a "$RINGKAS"
db env V7F2_DB=test_v7_f3_regf2_test bash tests/v7_phase2_db.sh
jalan v7-fase2-integrasi env V7F2_DB=test_v7_f3_regf2_test php tests/v7_phase2_integration.php
db env V7F2_DB=test_v7_f3_regf2h_test bash tests/v7_phase2_db.sh
jalan v7-fase2-http env V7F2_DB=test_v7_f3_regf2h_test php tests/v7_phase2_http.php
db env V7F2_DB=test_v7_f3_regf2a_test bash tests/v7_phase2_db.sh
jalan v7-fase2-probe-audit-codex env V7_RUN_AUDIT=1 DB_NAME=test_v7_f3_regf2a_test php tests/v7_phase2_audit.php
db env V7F2_TANPA_031=1 V7F2_DB=test_v7_f3_regf2m_test bash tests/v7_phase2_db.sh
jalan v7-fase2-migrasi env V7F2_DB=test_v7_f3_regf2m_test php tests/v7_phase2_migration.php
db env V7F2_DB=test_v7_f3_regf2b_test bash tests/v7_phase2_db.sh
DB_NAME=test_v7_f3_regf2b_test php -S 127.0.0.1:8995 tests/redesain_web_tahap1_router.php > "$T/v7f3-reg-f2browser.log" 2>&1 & P=$!; sleep 1
jalan v7-fase2-browser bash -c 'cd tests/browser && BASE_URL=http://127.0.0.1:8995 OUT_DIR="$TMPDIR/v7f3-reg-f2-tangkapan" node uji-v7-fase2.mjs'
kill $P

echo "== V7 Fase 1 pada kode Fase 3 ==" | tee -a "$RINGKAS"
db env V7F1_DB=test_v7_f3_regf1_test bash tests/v7_phase1_db.sh
jalan v7-fase1-integrasi env V7F1_DB=test_v7_f3_regf1_test php tests/v7_phase1_integration.php
db env V7F1_DB=test_v7_f3_regf1a_test bash tests/v7_phase1_db.sh
jalan v7-fase1-probe-audit-codex env V7_RUN_AUDIT=1 DB_NAME=test_v7_f3_regf1a_test php tests/v7_phase1_audit.php
db env V7F1_DB=test_v7_f3_regf1h_test bash tests/v7_phase1_db.sh
jalan v7-fase1-http env V7F1_DB=test_v7_f3_regf1h_test V7F1_PORT=8997 php tests/v7_phase1_http.php
db env V7F1_DB=test_v7_f3_regf1b_test bash tests/v7_phase1_db.sh
DB_NAME=test_v7_f3_regf1b_test php -S 127.0.0.1:8999 tests/redesain_web_tahap1_router.php > "$T/v7f3-reg-f1browser.log" 2>&1 & P=$!; sleep 1
jalan v7-fase1-browser bash -c 'cd tests/browser && BASE_URL=http://127.0.0.1:8999 OUT_DIR="$TMPDIR/v7f3-reg-f1-tangkapan" node uji-v7-fase1.mjs'
kill $P

echo "== Fondasi, perizinan dan notifikasi V2 ==" | tee -a "$RINGKAS"
for n in 1 2 3 4 5 6; do db env V7F1_DB=test_v7_f3_reg${n}_test bash tests/v7_phase1_db.sh; done
jalan penugasan-integrasi env PENUGASAN_RUN_INTEGRATION=1 DB_NAME=test_v7_f3_reg1_test php tests/penugasan_integration.php
jalan v2-fase4-integrasi env V2_PHASE4_RUN_INTEGRATION=1 DB_NAME=test_v7_f3_reg1_test php tests/v2_phase4_integration.php
jalan v2-fase5-integrasi env V2_PHASE5_RUN_INTEGRATION=1 DB_NAME=test_v7_f3_reg1_test php tests/v2_phase5_integration.php
jalan v2-fase1-integrasi env V2_PHASE1_RUN_INTEGRATION=1 DB_NAME=test_v7_f3_reg2_test php tests/v2_phase1_integration.php
jalan v2-fase2-integrasi env V2_PHASE2_RUN_INTEGRATION=1 DB_NAME=test_v7_f3_reg3_test php tests/v2_phase2_integration.php
jalan v2-fase4-konkurensi env V2_PHASE4_RUN_CONCURRENCY=1 DB_NAME=test_v7_f3_reg4_test php tests/v2_phase4_concurrency.php
jalan v2-fase3-api env V2_PHASE3_RUN_API=1 DB_NAME=test_v7_f3_reg5_test V2_PHASE3_PORT=8761 php tests/v2_phase3_api_contract.php
jalan v2-fase4-api env V2_PHASE4_RUN_API=1 DB_NAME=test_v7_f3_reg5_test php tests/v2_phase4_api_contract.php
jalan v2-fase5-api env V2_PHASE5_RUN_API=1 DB_NAME=test_v7_f3_reg5_test php tests/v2_phase5_api_contract.php
jalan v2-fase2-web env V2_PHASE2_RUN_WEB=1 DB_NAME=test_v7_f3_reg6_test V2_PHASE2_WEB_PORT=8762 php tests/v2_phase2_web_smoke.php
jalan v2-fase4-web env V2_PHASE4_RUN_WEB=1 DB_NAME=test_v7_f3_reg6_test php tests/v2_phase4_web_smoke.php
jalan v2-fase5-web env V2_PHASE5_RUN_WEB=1 DB_NAME=test_v7_f3_reg6_test php tests/v2_phase5_web_smoke.php
for n in 1 2 3 4 5 6; do for v in 2 3; do jalan "v7-fase${v}-verify-sesudah-reg$n" env DB_NAME=test_v7_f3_reg${n}_test php bin/v7_phase${v}_verify.php; done; done

echo "== Worker Push V5/V6 (NotificationDispatcher berubah) ==" | tee -a "$RINGKAS"
db bash tests/v5_phase3_db.sh
jalan v5-fase3-integrasi env V5_RUN_INTEGRATION=1 php tests/v5_phase3_integration.php
db env V6F4_DB=test_v6_f4_http_test bash tests/v6_phase4_db.sh
jalan v6-fase4-http env V6F4_DB=test_v6_f4_http_test php tests/v6_phase4_http.php

echo "== V6 Fase 5 ==" | tee -a "$RINGKAS"
db bash tests/v6_phase5_db.sh
jalan v6-fase5-integrasi env V6_RUN_INTEGRATION=1 DB_NAME=test_v6_f5_test php tests/v6_phase5_integration.php
db env V6F5_DB=test_v6_f5_http_test bash tests/v6_phase5_db.sh
jalan v6-fase5-http env V6F5_DB=test_v6_f5_http_test php tests/v6_phase5_http.php

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

echo "== Browser kerangka sembilan akun (DB V7) ==" | tee -a "$RINGKAS"
db env V7F1_DB=test_v7_f3_kerangka_test bash tests/v7_phase1_db.sh
DB_NAME=test_v7_f3_kerangka_test php -S 127.0.0.1:8931 tests/redesain_web_tahap1_router.php > "$T/v7f3-reg-kerangka.log" 2>&1 & P=$!; sleep 1
jalan browser-kerangka-sembilan-akun bash -c 'cd tests/browser && BASE_URL=http://127.0.0.1:8931 node uji-redesain-web-tahap1.mjs'
kill $P

echo "== Statis (seluruh suite) ==" | tee -a "$RINGKAS"
for f in tests/*_static.php; do jalan "statis-$(basename "$f" .php)" php "$f"; done
echo "SELESAI" | tee -a "$RINGKAS"
