#!/usr/bin/env bash
# Ulangi bukti akhir audit Fase 1; tidak menimpa bukti implementator.
# Semua pembangun DB harus BERURUTAN karena memakai perantara bernama tetap.
# Memerlukan .env sandbox *_test, MariaDB lokal, PHP, dan Playwright terpasang.
set -euo pipefail
cd "$(dirname "$0")/../../.."
OUT=docs/phase-v6-1/audit-codex
mkdir -p "$OUT/regresi" "$OUT/tangkapan"

php tests/v6_phase1_static.php > "$OUT/statis-akhir.txt" 2>&1
V6F1_DB=test_v6_codex_probe_test bash tests/v6_phase1_db.sh
V6F1_DB=test_v6_codex_probe_test V6_RUN_AUDIT=1 php tests/v6_phase1_audit.php > "$OUT/probe-akhir.txt" 2>&1
V6F1_DB=test_v6_codex_integrasi_test bash tests/v6_phase1_db.sh
V6F1_DB=test_v6_codex_integrasi_test V6_RUN_INTEGRATION=1 php tests/v6_phase1_integration.php > "$OUT/integrasi-akhir.txt" 2>&1
V6F1_DB=test_v6_codex_http_test bash tests/v6_phase1_db.sh
V6F1_DB=test_v6_codex_http_test php tests/v6_phase1_http.php > "$OUT/http-akhir.txt" 2>&1
# Skrip ini mengikat DB internal ke test_v6_f1_migrasi_test dan test_v6_f1_rollback_test.
DB_NAME=test_v6_f1_migrasi_test V6F1_DB=test_v6_f1_migrasi_test V6_RUN_MIGRATION=1 php tests/v6_phase1_migration.php > "$OUT/migrasi-rollback.txt" 2>&1

V6F1_DB=test_v6_codex_browser_test bash tests/v6_phase1_db.sh
DB_NAME=test_v6_codex_browser_test php -S 127.0.0.1:8998 tests/redesain_web_tahap1_router.php > /tmp/v6-codex-browser-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
sleep 1
BASE_URL=http://127.0.0.1:8998 OUT_DIR="$PWD/$OUT/tangkapan" node tests/browser/uji-v6-fase1.mjs > "$OUT/browser.txt" 2>&1
BASE_URL=http://127.0.0.1:8998 node tests/browser/uji-redesain-web-tahap1.mjs > "$OUT/regresi/browser-kerangka.txt" 2>&1
kill "$SERVER_PID"
trap - EXIT

PENUGASAN_RUN_INTEGRATION=1 DB_NAME=test_v6_codex_integrasi_test php tests/penugasan_integration.php > "$OUT/regresi/penugasan-integrasi.txt" 2>&1
V2_PHASE4_RUN_INTEGRATION=1 DB_NAME=test_v6_codex_integrasi_test php tests/v2_phase4_integration.php > "$OUT/regresi/v2-fase4-integrasi.txt" 2>&1
V2_PHASE5_RUN_INTEGRATION=1 DB_NAME=test_v6_codex_integrasi_test php tests/v2_phase5_integration.php > "$OUT/regresi/v2-fase5-integrasi.txt" 2>&1
V5F5_DB=test_v6_codex_v5_test bash tests/v5_phase5_db.sh
V5_RUN_INTEGRATION=1 DB_NAME=test_v6_codex_v5_test php tests/v5_phase5_integration.php > "$OUT/regresi/v5-fase5-integrasi.txt" 2>&1
V5F3_DB=test_v6_codex_v5f3_test bash tests/v5_phase3_db.sh
V5_RUN_INTEGRATION=1 V5F3_DB=test_v6_codex_v5f3_test php tests/v5_phase3_integration.php > "$OUT/regresi/v5-fase3-integrasi.txt" 2>&1

# Jangan hentikan loop pada oracle historis yang gagal; simpan exit sebenarnya.
: > "$OUT/regresi/statis-status.txt"
for f in tests/*_static.php; do
  n=$(basename "$f" .php)
  rc=0
  php "$f" > "$OUT/regresi/$n.txt" 2>&1 || rc=$?
  echo "$n exit=$rc" >> "$OUT/regresi/statis-status.txt"
done
