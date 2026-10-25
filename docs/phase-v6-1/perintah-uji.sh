#!/usr/bin/env bash
# Seluruh uji PRD V6 Fase 1 pada DB salinan `test_*_test` berfixture sintetis.
# Jalankan dari akar repo web, BERURUTAN. Keluaran di docs/phase-v6-1/bukti/.
#
#   bash docs/phase-v6-1/perintah-uji.sh
set -u
cd "$(dirname "$0")/../.."
B=docs/phase-v6-1/bukti
mkdir -p "$B" docs/phase-v6-1/tangkapan
php tests/v6_phase1_static.php > "$B/statis.txt" 2>&1; echo "statis exit=$?"
bash tests/v6_phase1_db.sh > /dev/null && V6_RUN_INTEGRATION=1 php tests/v6_phase1_integration.php > "$B/integrasi.txt" 2>&1; echo "integrasi exit=$?"
V6F1_DB=test_v6_f1_http_test bash tests/v6_phase1_db.sh > /dev/null && V6F1_DB=test_v6_f1_http_test php tests/v6_phase1_http.php > "$B/http.txt" 2>&1; echo "http exit=$?"
V6_RUN_MIGRATION=1 php tests/v6_phase1_migration.php > "$B/migrasi-rollback.txt" 2>&1; echo "migrasi exit=$?"
V6F1_DB=test_v6_f1_browser_test bash tests/v6_phase1_db.sh > /dev/null
DB_NAME=test_v6_f1_browser_test php -S 127.0.0.1:8998 tests/redesain_web_tahap1_router.php > /tmp/v6f1-browser-server.log 2>&1 &
PID=$!; sleep 1
(cd tests/browser && BASE_URL=http://127.0.0.1:8998 OUT_DIR=../../docs/phase-v6-1/tangkapan node uji-v6-fase1.mjs) > "$B/browser.txt" 2>&1; echo "browser exit=$?"
kill $PID
grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' /tmp/v6f1-browser-server.log | sed 's/^/galat PHP server browser: /' | tee -a "$B/browser.txt"
# Regresi kerangka sembilan akun (Tahap 1) pada DB V6 (menu Keuangan ikut dibuka).
V6F1_DB=test_v6_f1_kerangka_test bash tests/v6_phase1_db.sh > /dev/null
DB_NAME=test_v6_f1_kerangka_test php -S 127.0.0.1:8931 tests/redesain_web_tahap1_router.php > /tmp/v6f1-kerangka-server.log 2>&1 &
PID=$!; sleep 1
(cd tests/browser && BASE_URL=http://127.0.0.1:8931 node uji-redesain-web-tahap1.mjs) > "$B/regresi/browser-kerangka-sembilan-akun.txt" 2>&1; echo "kerangka exit=$?"
kill $PID
