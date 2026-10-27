#!/usr/bin/env bash
# Uji PRD V7 Fase 1 (pengajuan wali dan routing pembimbing) pada DB salinan
# `test_*_test` berfixture sintetis. Jalankan dari akar repo web, BERURUTAN
# (rantai fixture memakai DB perantara bernama tetap). Keluaran di
# docs/phase-v7-1/bukti/. Tidak menyentuh DB produksi.
#
#   bash docs/phase-v7-1/perintah-uji.sh
set -u
cd "$(dirname "$0")/../.."
OUT=docs/phase-v7-1/bukti
mkdir -p "$OUT"
T="${TMPDIR:-/tmp}"

php tests/v7_phase1_static.php > "$OUT/statis.txt" 2>&1; echo "statis exit=$?"
bash tests/v7_phase1_db.sh > /dev/null && php tests/v7_phase1_integration.php > "$OUT/integrasi.txt" 2>&1; echo "integrasi exit=$?"
V7F1_DB=test_v7_f1_http_test bash tests/v7_phase1_db.sh > /dev/null && V7F1_DB=test_v7_f1_http_test php tests/v7_phase1_http.php > "$OUT/http.txt" 2>&1; echo "http exit=$?"
V7F1_TANPA_030=1 V7F1_DB=test_v7_f1_mig_test bash tests/v7_phase1_db.sh > /dev/null && V7F1_DB=test_v7_f1_mig_test php tests/v7_phase1_migration.php > "$OUT/migrasi-rollback.txt" 2>&1; echo "migrasi exit=$?"
V7F1_DB=test_v7_f1_browser_test bash tests/v7_phase1_db.sh > /dev/null
DB_NAME=test_v7_f1_browser_test php -S 127.0.0.1:8999 tests/redesain_web_tahap1_router.php > "$T/v7f1-browser.log" 2>&1 & P=$!; sleep 1
(cd tests/browser && BASE_URL=http://127.0.0.1:8999 OUT_DIR="$(pwd)/../../docs/phase-v7-1/tangkapan" node uji-v7-fase1.mjs) > "$OUT/browser.txt" 2>&1; echo "browser exit=$?"
kill $P
grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$T/v7f1-browser.log" | sed 's/^/galat PHP log server browser: /' >> "$OUT/browser.txt"
