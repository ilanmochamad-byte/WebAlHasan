#!/usr/bin/env bash
# Uji PRD V7 Fase 3 (notifikasi tahap, worker Push, laporan lintas asal, audit)
# pada DB salinan `test_*_test` berfixture sintetis. Jalankan dari akar repo
# web, BERURUTAN (rantai fixture memakai DB perantara bernama tetap). Keluaran
# di docs/phase-v7-3/bukti/. Tidak menyentuh DB produksi, perangkat, atau
# penyedia Push/WhatsApp nyata (klien palsu).
#
#   bash docs/phase-v7-3/perintah-uji.sh
set -u
cd "$(dirname "$0")/../.."
OUT=docs/phase-v7-3/bukti
mkdir -p "$OUT"
T="${TMPDIR:-/tmp}"

php tests/v7_phase3_static.php > "$OUT/statis.txt" 2>&1; echo "statis exit=$?"
bash tests/v7_phase3_db.sh > /dev/null && php tests/v7_phase3_integration.php > "$OUT/integrasi.txt" 2>&1; echo "integrasi exit=$?"
V7F3_DB=test_v7_f3_http_test bash tests/v7_phase3_db.sh > /dev/null && V7F3_DB=test_v7_f3_http_test php tests/v7_phase3_http.php > "$OUT/http.txt" 2>&1; echo "http exit=$?"
V7F3_DB=test_v7_f3_browser_test bash tests/v7_phase3_db.sh > /dev/null
DB_NAME=test_v7_f3_browser_test php -S 127.0.0.1:8994 tests/redesain_web_tahap1_router.php > "$T/v7f3-browser.log" 2>&1 & P=$!; sleep 1
(cd tests/browser && BASE_URL=http://127.0.0.1:8994 OUT_DIR="$(pwd)/../../docs/phase-v7-3/tangkapan" node uji-v7-fase3.mjs) > "$OUT/browser.txt" 2>&1; echo "browser exit=$?"
kill $P
grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$T/v7f3-browser.log" | sed 's/^/galat PHP log server browser: /' >> "$OUT/browser.txt"
for v in 2 3; do DB_NAME=test_v7_f3_browser_test php "bin/v7_phase${v}_verify.php" > "$OUT/verifier-fase${v}-sesudah-browser.txt" 2>&1; echo "verifier-fase$v-browser exit=$?"; done
