#!/usr/bin/env bash
# Audit independen PRD V7 Fase 2 (tinjauan pembimbing, perbaikan/pembatalan wali, penerusan,
# keputusan akhir V2) pada DB salinan `test_*_test` berfixture sintetis.
# Jalankan dari akar repo web, BERURUTAN (rantai fixture memakai DB perantara
# bernama tetap). Keluaran di docs/phase-v7-2/audit-codex/. Tidak menyentuh DB produksi.
#
#   bash docs/phase-v7-2/perintah-audit.sh
set -u
cd "$(dirname "$0")/../.."
OUT=docs/phase-v7-2/audit-codex
mkdir -p "$OUT"
T="${TMPDIR:-/tmp}"

php tests/v7_phase2_static.php > "$OUT/statis.txt" 2>&1; echo "statis exit=$?"
bash tests/v7_phase2_db.sh > /dev/null && php tests/v7_phase2_integration.php > "$OUT/integrasi.txt" 2>&1; echo "integrasi exit=$?"
V7_RUN_AUDIT=1 DB_NAME=test_v7_f2_test php tests/v7_phase2_audit.php > "$OUT/probe-akhir.txt" 2>&1; echo "probe exit=$?"
DB_NAME=test_v7_f2_test php bin/v7_phase2_verify.php > "$OUT/verifier-sesudah-probe.txt" 2>&1; echo "verifier-probe exit=$?"
V7F2_DB=test_v7_f2_http_test bash tests/v7_phase2_db.sh > /dev/null && V7F2_DB=test_v7_f2_http_test php tests/v7_phase2_http.php > "$OUT/http.txt" 2>&1; echo "http exit=$?"
V7F2_TANPA_031=1 V7F2_DB=test_v7_f2_mig_test bash tests/v7_phase2_db.sh > /dev/null && V7F2_DB=test_v7_f2_mig_test php tests/v7_phase2_migration.php > "$OUT/migrasi-rollback.txt" 2>&1; echo "migrasi exit=$?"
V7F2_DB=test_v7_f2_browser_test bash tests/v7_phase2_db.sh > /dev/null
DB_NAME=test_v7_f2_browser_test php -S 127.0.0.1:8995 tests/redesain_web_tahap1_router.php > "$T/v7f2-browser.log" 2>&1 & P=$!; sleep 1
(cd tests/browser && BASE_URL=http://127.0.0.1:8995 OUT_DIR="$(pwd)/../../docs/phase-v7-2/audit-codex/tangkapan" node uji-v7-fase2.mjs) > "$OUT/browser.txt" 2>&1; echo "browser exit=$?"
kill $P
grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$T/v7f2-browser.log" | sed 's/^/galat PHP log server browser: /' >> "$OUT/browser.txt"
DB_NAME=test_v7_f2_browser_test php bin/v7_phase2_verify.php > "$OUT/verifier-sesudah-browser.txt" 2>&1; echo "verifier-browser exit=$?"
