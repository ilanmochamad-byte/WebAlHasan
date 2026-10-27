#!/usr/bin/env bash
# Regresi PRD V7 Fase 4 (web). Satu-satunya perubahan kode web adalah kunci
# aditif `asal` pada penyajian pengajuan REST V2 (`IzinApiService`), sehingga
# regresi difokuskan pada kontrak REST/web perizinan V2, V7 Fase 1–3, fondasi
# penugasan, laporan/cetak perizinan Tahap 4, kerangka sembilan akun, dan
# seluruh suite statis. DB salinan `test_*_test` berfixture sintetis.
# Jalankan dari akar repo web, BERURUTAN. Keluaran di bukti/regresi/.
#
#   bash docs/phase-v7-4/perintah-regresi.sh
set -u
cd "$(dirname "$0")/../.."
OUT=docs/phase-v7-4/bukti/regresi
mkdir -p "$OUT"
RINGKAS="$OUT/ringkasan.txt"
: > "$RINGKAS"
T="${TMPDIR:-/tmp}"

hitung() { local f="$1" l g; l=$(grep -cE '^ *\[(lulus|OK|ok)\]|^ *✓' "$f" 2>/dev/null); g=$(grep -cE '^ *\[(gagal|GAGAL|FAIL)\]|^ *✗' "$f" 2>/dev/null); echo "${l:-0}/${g:-0}"; }
jalan() { local nama="$1"; shift; "$@" > "$OUT/$nama.txt" 2>&1; local kode=$?; echo "$nama exit=$kode $(hitung "$OUT/$nama.txt")" | tee -a "$RINGKAS"; }
db() { "$@" > /dev/null 2>&1 || echo "PENYIAPAN GAGAL: $*" | tee -a "$RINGKAS"; }

echo "== V7 Fase 1–3 pada kode Fase 4 ==" | tee -a "$RINGKAS"
db env V7F3_DB=test_v7_f4_regf3_test bash tests/v7_phase3_db.sh
jalan v7-fase3-integrasi env DB_NAME=test_v7_f4_regf3_test V7F3_DB=test_v7_f4_regf3_test php tests/v7_phase3_integration.php
db env V7F3_DB=test_v7_f4_regf3h_test bash tests/v7_phase3_db.sh
jalan v7-fase3-http env V7F3_DB=test_v7_f4_regf3h_test php tests/v7_phase3_http.php
db env V7F2_DB=test_v7_f4_regf2_test bash tests/v7_phase2_db.sh
jalan v7-fase2-integrasi env V7F2_DB=test_v7_f4_regf2_test php tests/v7_phase2_integration.php
db env V7F2_DB=test_v7_f4_regf2h_test bash tests/v7_phase2_db.sh
jalan v7-fase2-http env V7F2_DB=test_v7_f4_regf2h_test php tests/v7_phase2_http.php
db env V7F1_DB=test_v7_f4_regf1h_test bash tests/v7_phase1_db.sh
jalan v7-fase1-http env V7F1_DB=test_v7_f4_regf1h_test V7F1_PORT=8973 php tests/v7_phase1_http.php

echo "== Fondasi, perizinan dan notifikasi V2 ==" | tee -a "$RINGKAS"
for n in 1 2 3 4 5 6; do db env V7F1_DB=test_v7_f4_reg${n}_test bash tests/v7_phase1_db.sh; done
jalan penugasan-integrasi env PENUGASAN_RUN_INTEGRATION=1 DB_NAME=test_v7_f4_reg1_test php tests/penugasan_integration.php
jalan v2-fase4-integrasi env V2_PHASE4_RUN_INTEGRATION=1 DB_NAME=test_v7_f4_reg1_test php tests/v2_phase4_integration.php
jalan v2-fase5-integrasi env V2_PHASE5_RUN_INTEGRATION=1 DB_NAME=test_v7_f4_reg1_test php tests/v2_phase5_integration.php
jalan v2-fase1-integrasi env V2_PHASE1_RUN_INTEGRATION=1 DB_NAME=test_v7_f4_reg2_test php tests/v2_phase1_integration.php
jalan v2-fase2-integrasi env V2_PHASE2_RUN_INTEGRATION=1 DB_NAME=test_v7_f4_reg3_test php tests/v2_phase2_integration.php
jalan v2-fase4-konkurensi env V2_PHASE4_RUN_CONCURRENCY=1 DB_NAME=test_v7_f4_reg4_test php tests/v2_phase4_concurrency.php
jalan v2-fase3-api env V2_PHASE3_RUN_API=1 DB_NAME=test_v7_f4_reg5_test V2_PHASE3_PORT=8761 php tests/v2_phase3_api_contract.php
jalan v2-fase4-api env V2_PHASE4_RUN_API=1 DB_NAME=test_v7_f4_reg5_test php tests/v2_phase4_api_contract.php
jalan v2-fase5-api env V2_PHASE5_RUN_API=1 DB_NAME=test_v7_f4_reg5_test php tests/v2_phase5_api_contract.php
jalan v2-fase2-web env V2_PHASE2_RUN_WEB=1 DB_NAME=test_v7_f4_reg6_test V2_PHASE2_WEB_PORT=8762 php tests/v2_phase2_web_smoke.php
jalan v2-fase4-web env V2_PHASE4_RUN_WEB=1 DB_NAME=test_v7_f4_reg6_test php tests/v2_phase4_web_smoke.php
jalan v2-fase5-web env V2_PHASE5_RUN_WEB=1 DB_NAME=test_v7_f4_reg6_test php tests/v2_phase5_web_smoke.php
for n in 1 2 3 4 5 6; do for v in 2 3; do jalan "v7-fase${v}-verify-sesudah-reg$n" env DB_NAME=test_v7_f4_reg${n}_test php bin/v7_phase${v}_verify.php; done; done

echo "== Redesain web perizinan/cetak (HTTP) ==" | tee -a "$RINGKAS"
db bash tests/redesain_web_tahap4_db.sh
jalan redesain-tahap4-http php tests/redesain_web_tahap4_http.php

echo "== Browser kerangka sembilan akun (DB V7) ==" | tee -a "$RINGKAS"
db env V7F1_DB=test_v7_f4_kerangka_test bash tests/v7_phase1_db.sh
DB_NAME=test_v7_f4_kerangka_test php -S 127.0.0.1:8931 tests/redesain_web_tahap1_router.php > "$T/v7f4-reg-kerangka.log" 2>&1 & P=$!; sleep 1
jalan browser-kerangka-sembilan-akun bash -c 'cd tests/browser && BASE_URL=http://127.0.0.1:8931 node uji-redesain-web-tahap1.mjs'
kill $P; pkill -f 'php -S 127.0.0.1:8931 '

echo "== Statis (seluruh suite) ==" | tee -a "$RINGKAS"
for f in tests/*_static.php; do jalan "statis-$(basename "$f" .php)" php "$f"; done
echo "SELESAI" | tee -a "$RINGKAS"
