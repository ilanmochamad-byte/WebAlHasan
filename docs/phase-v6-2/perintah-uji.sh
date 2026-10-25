#!/usr/bin/env bash
# Seluruh uji PRD V6 Fase 2 pada DB salinan `test_*_test` berfixture sintetis.
# Jalankan dari akar repo web, BERURUTAN (rantai fixture memakai DB perantara
# bernama tetap). Keluaran di docs/phase-v6-2/bukti/.
#
#   bash docs/phase-v6-2/perintah-uji.sh
set -u
cd "$(dirname "$0")/../.."
B=docs/phase-v6-2/bukti
mkdir -p "$B" docs/phase-v6-2/tangkapan
LOGDIR="${TMPDIR:-/tmp}"
php tests/v6_phase2_static.php > "$B/statis.txt" 2>&1; echo "statis exit=$?"
bash tests/v6_phase2_db.sh > /dev/null && V6_RUN_INTEGRATION=1 php tests/v6_phase2_integration.php > "$B/integrasi.txt" 2>&1; echo "integrasi exit=$?"
V6F2_DB=test_v6_f2_http_test bash tests/v6_phase2_db.sh > /dev/null && V6F2_DB=test_v6_f2_http_test php tests/v6_phase2_http.php > "$B/http.txt" 2>&1; echo "http exit=$?"
V6_RUN_MIGRATION=1 php tests/v6_phase2_migration.php > "$B/migrasi-rollback.txt" 2>&1; echo "migrasi exit=$?"
V6F2_DB=test_v6_f2_browser_test bash tests/v6_phase2_db.sh > /dev/null
DB_NAME=test_v6_f2_browser_test php -S 127.0.0.1:8997 tests/redesain_web_tahap1_router.php > "$LOGDIR/v6f2-browser-server.log" 2>&1 &
PID=$!; sleep 1
(cd tests/browser && BASE_URL=http://127.0.0.1:8997 OUT_DIR=../../docs/phase-v6-2/tangkapan node uji-v6-fase2.mjs) > "$B/browser.txt" 2>&1; echo "browser exit=$?"
kill $PID
grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$LOGDIR/v6f2-browser-server.log" | sed 's/^/galat PHP server browser: /' | tee -a "$B/browser.txt"
