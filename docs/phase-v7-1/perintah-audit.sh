#!/usr/bin/env bash
# Audit independen. Hanya fixture test_*_test; simpan bukti terpisah, berurutan.
set -euo pipefail
cd "$(dirname "$0")/../.."
OUT=docs/phase-v7-1/audit-codex
mkdir -p "$OUT/regresi" "$OUT/tangkapan"
run() {
    local name="$1"; shift
    local result=0
    "$@" > "$OUT/$name.txt" 2>&1 || result=$?
    echo "$name exit=$result" | tee -a "$OUT/ringkasan.txt"
}
: > "$OUT/ringkasan.txt"
V7F1_DB=test_v7_audit_base_test bash tests/v7_phase1_db.sh > "$OUT/siapkan-base.txt" 2>&1
# Dump fixture sekali; masing-masing suite memperoleh salinan bersih sendiri.
readenv() { rg "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
TASK_HOST="$(readenv DB_HOST)"; TASK_HOST="${TASK_HOST:-127.0.0.1}"
TASK_PORT="$(readenv DB_PORT)"; TASK_PORT="${TASK_PORT:-3306}"
TASK_USER="$(readenv DB_USER)"
export MYSQL_PWD="$(readenv DB_PASS || true)$(readenv DB_PASSWORD || true)"
TASK_DUMP="$(mktemp)"
trap 'rm -f "$TASK_DUMP"' EXIT
mysqldump -h"$TASK_HOST" -P"$TASK_PORT" -u"$TASK_USER" --single-transaction test_v7_audit_base_test > "$TASK_DUMP" 2>/dev/null
clone() {
    case "$1" in test_v7_audit_*_test) ;; *) exit 2;; esac
    mysql -h"$TASK_HOST" -P"$TASK_PORT" -u"$TASK_USER" -e "DROP DATABASE IF EXISTS \`$1\`; CREATE DATABASE \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>/dev/null
    mysql -h"$TASK_HOST" -P"$TASK_PORT" -u"$TASK_USER" "$1" < "$TASK_DUMP" 2>/dev/null
}
clone test_v7_audit_int_test
run integrasi-akhir env V7F1_DB=test_v7_audit_int_test php tests/v7_phase1_integration.php
run probe-akhir env V7_RUN_AUDIT=1 DB_NAME=test_v7_audit_int_test php tests/v7_phase1_audit.php
clone test_v7_audit_http_test
run http-akhir env V7F1_DB=test_v7_audit_http_test V7F1_PORT=8997 php tests/v7_phase1_http.php
clone test_v7_audit_migrasi_test
DB_NAME=test_v7_audit_migrasi_test php bin/v7_phase1_rollback_preflight.php > "$OUT/rollback-kosong.txt"
DB_NAME=test_v7_audit_migrasi_test php bin/migrate.php rollback >> "$OUT/rollback-kosong.txt"
run migrasi-akhir env V7F1_DB=test_v7_audit_migrasi_test php tests/v7_phase1_migration.php
clone test_v7_audit_legacy_test
DB_NAME=test_v7_audit_legacy_test php bin/migrate.php rollback > "$OUT/siapkan-warisan.txt"
run migrasi-warisan env V7_RUN_AUDIT=1 DB_NAME=test_v7_audit_legacy_test php tests/v7_phase1_legacy_audit.php
clone test_v7_audit_browser_test
DB_NAME=test_v7_audit_browser_test php -S 127.0.0.1:8999 tests/redesain_web_tahap1_router.php > "$OUT/server-browser.txt" 2>&1 &
TASK_SERVER=$!
sleep 1
run browser env BASE_URL=http://127.0.0.1:8999 OUT_DIR="$PWD/$OUT/tangkapan" node tests/browser/uji-v7-fase1.mjs
kill "$TASK_SERVER"
for n in 1 2 3 4 5 6; do clone "test_v7_audit_reg${n}_test"; done
run regresi/penugasan env PENUGASAN_RUN_INTEGRATION=1 DB_NAME=test_v7_audit_reg1_test php tests/penugasan_integration.php
run regresi/v2-fase4-integrasi env V2_PHASE4_RUN_INTEGRATION=1 DB_NAME=test_v7_audit_reg1_test php tests/v2_phase4_integration.php
run regresi/v2-fase5-integrasi env V2_PHASE5_RUN_INTEGRATION=1 DB_NAME=test_v7_audit_reg1_test php tests/v2_phase5_integration.php
run regresi/v2-fase1-integrasi env V2_PHASE1_RUN_INTEGRATION=1 DB_NAME=test_v7_audit_reg2_test php tests/v2_phase1_integration.php
run regresi/v2-fase2-integrasi env V2_PHASE2_RUN_INTEGRATION=1 DB_NAME=test_v7_audit_reg3_test php tests/v2_phase2_integration.php
run regresi/v2-fase4-konkurensi env V2_PHASE4_RUN_CONCURRENCY=1 DB_NAME=test_v7_audit_reg4_test php tests/v2_phase4_concurrency.php
run regresi/v2-fase3-api env V2_PHASE3_RUN_API=1 DB_NAME=test_v7_audit_reg5_test V2_PHASE3_PORT=8761 php tests/v2_phase3_api_contract.php
run regresi/v2-fase4-api env V2_PHASE4_RUN_API=1 DB_NAME=test_v7_audit_reg5_test php tests/v2_phase4_api_contract.php
run regresi/v2-fase5-api env V2_PHASE5_RUN_API=1 DB_NAME=test_v7_audit_reg5_test php tests/v2_phase5_api_contract.php
run regresi/v2-fase2-web env V2_PHASE2_RUN_WEB=1 DB_NAME=test_v7_audit_reg6_test V2_PHASE2_WEB_PORT=8762 php tests/v2_phase2_web_smoke.php
run regresi/v2-fase4-web env V2_PHASE4_RUN_WEB=1 DB_NAME=test_v7_audit_reg6_test php tests/v2_phase4_web_smoke.php
run regresi/v2-fase5-web env V2_PHASE5_RUN_WEB=1 DB_NAME=test_v7_audit_reg6_test php tests/v2_phase5_web_smoke.php
for n in 1 2 3 4 5 6; do
    run "regresi/verifier-reg$n" env DB_NAME="test_v7_audit_reg${n}_test" php bin/v7_phase1_verify.php
done
run statis-akhir php tests/v7_phase1_static.php
echo SELESAI | tee -a "$OUT/ringkasan.txt"
