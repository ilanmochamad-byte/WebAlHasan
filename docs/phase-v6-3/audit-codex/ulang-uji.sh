#!/usr/bin/env bash
# Fixture disiapkan sekali melalui builder asli, lalu disalin terpisah untuk
# setiap suite. Tidak mengubah suite historis atau bukti implementator.
set -uo pipefail
cd "$(dirname "$0")/../../.."
OUT=docs/phase-v6-3/audit-codex
mkdir -p "$OUT/regresi" "$OUT/tangkapan"
T=$(mktemp -d /private/tmp/v6f3-audit-XXXXXX)
FIXTURE=${V6_AUDIT_FIXTURE:-$T/fixture.sql}
if [ -z "${V6_AUDIT_FIXTURE:-}" ]; then
  V6F3_DB=test_v6_f3_fixture_test bash tests/v6_phase3_db.sh > "$T/fixture.log" 2>&1 || exit 2
  mysqldump test_v6_f3_fixture_test > "$FIXTURE" || exit 2
fi
R="$OUT/regresi/ringkasan.txt"
if [ "${V6_AUDIT_CORE_ONLY:-0}" = 1 ]; then R="$OUT/ringkasan-core.txt"; fi
: > "$R"
run() { local name=$1; shift; "$@" > "$OUT/$name.txt" 2>&1; local code=$?; echo "$name exit=$code" | tee -a "$R"; }
fresh() {
  case "$1" in test_*_test) ;; *) exit 2;; esac
  mysql -e "DROP DATABASE IF EXISTS \`$1\`; CREATE DATABASE \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" && mysql "$1" < "$FIXTURE" || exit 2
}
browser() {
  local suite=$1 database=$2 port=$3 output=$4
  fresh "$database"
  DB_NAME="$database" php -S "127.0.0.1:$port" tests/redesain_web_tahap1_router.php > "$T/$suite-server.log" 2>&1 &
  local pid=$!
  sleep 1
  run "$output" env BASE_URL="http://127.0.0.1:$port" OUT_DIR="$PWD/$OUT/tangkapan/$suite" node "tests/browser/$suite.mjs"
  kill "$pid"
  local n
  n=$(grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$T/$suite-server.log" || true)
  echo "$suite galat PHP=$n" | tee -a "$OUT/server-ringkasan.txt"
}
run statis-akhir php tests/v6_phase3_static.php
fresh test_v6_f3_audit_test
run probe-akhir env V6F3_DB=test_v6_f3_audit_test V6_RUN_AUDIT=1 php tests/v6_phase3_audit.php
fresh test_v6_f3_boundary_test
run batas-tengah-malam-akhir env V6F3_DB=test_v6_f3_boundary_test V6_RUN_AUDIT=1 php tests/v6_phase3_boundary_audit.php
fresh test_v6_f3_test
run integrasi-akhir env V6_RUN_INTEGRATION=1 php tests/v6_phase3_integration.php
fresh test_v6_f3_http_test
run http-akhir env V6F3_DB=test_v6_f3_http_test php tests/v6_phase3_http.php
browser uji-v6-fase3 test_v6_f3_browser_audit_test 8994 browser
if [ "${V6_AUDIT_CORE_ONLY:-0}" = 1 ]; then exit 0; fi

for f in tests/*_static.php; do run "regresi/statis-$(basename "$f" .php)" php "$f"; done
fresh test_v6_f1_test
run regresi/v6-fase1-integrasi env V6_RUN_INTEGRATION=1 php tests/v6_phase1_integration.php
fresh test_v6_f1_http_test
run regresi/v6-fase1-http env V6F1_DB=test_v6_f1_http_test php tests/v6_phase1_http.php
fresh test_v6_f1_audit_test
run regresi/v6-fase1-probe env V6F1_DB=test_v6_f1_audit_test V6_RUN_AUDIT=1 php tests/v6_phase1_audit.php
browser uji-v6-fase1 test_v6_f1_browser_audit_test 8995 regresi/v6-fase1-browser
fresh test_v6_f1_http_test
DB_NAME=test_v6_f1_http_test php bin/v6_phase3_rollback_preflight.php > "$T/rollback.log" && DB_NAME=test_v6_f1_http_test php bin/migrate.php rollback >> "$T/rollback.log" || exit 2
run regresi/v6-fase1-http-tanpa029 env V6F1_DB=test_v6_f1_http_test php tests/v6_phase1_http.php
fresh test_v6_f2_test
run regresi/v6-fase2-integrasi env V6_RUN_INTEGRATION=1 php tests/v6_phase2_integration.php
fresh test_v6_f2_http_test
run regresi/v6-fase2-http env V6F2_DB=test_v6_f2_http_test php tests/v6_phase2_http.php
fresh test_v6_f2_audit_test
run regresi/v6-fase2-probe env V6F2_DB=test_v6_f2_audit_test V6_RUN_AUDIT=1 php tests/v6_phase2_audit.php
fresh test_v6_f2_legacy_test
run regresi/v6-fase2-era027 env DB_NAME=test_v6_f2_legacy_test V6_RUN_AUDIT=1 php tests/v6_phase2_legacy_audit.php
browser uji-v6-fase2 test_v6_f2_browser_audit_test 8997 regresi/v6-fase2-browser
fresh test_v6_f3_regresi_test
run regresi/penugasan env PENUGASAN_RUN_INTEGRATION=1 DB_NAME=test_v6_f3_regresi_test php tests/penugasan_integration.php
run regresi/v2-fase4 env V2_PHASE4_RUN_INTEGRATION=1 DB_NAME=test_v6_f3_regresi_test php tests/v2_phase4_integration.php
run regresi/v2-fase5 env V2_PHASE5_RUN_INTEGRATION=1 DB_NAME=test_v6_f3_regresi_test php tests/v2_phase5_integration.php
browser uji-redesain-web-tahap1 test_v6_f3_kerangka_audit_test 8931 regresi/browser-kerangka

# PSB memakai salinan sandbox asal sesuai harness asli, Apache + .htaccess.
KPSB_DIR=${KPSB_DIR:-$T/psb}
SRC=$(sed -n 's/^DB_NAME=//p' .env)
case "$SRC" in *_test) ;; *) exit 2;; esac
mysql -e 'DROP DATABASE IF EXISTS test_v6_f3_psb_audit_test; CREATE DATABASE test_v6_f3_psb_audit_test'
mysqldump "$SRC" | mysql test_v6_f3_psb_audit_test || exit 2
DB_NAME=test_v6_f3_psb_audit_test php bin/migrate.php up > "$T/psb-migrate.log" || exit 2
KPSB_DIR="$KPSB_DIR" KPSB_PORT=8971 KPSB_DB=test_v6_f3_psb_audit_test bash tests/keamanan_psb_server.sh start > "$T/psb-server.log" 2>&1 || exit 2
run regresi/psb-http env KEAMANAN_PSB_RUN_TESTS=1 KPSB_URL=http://127.0.0.1:8971 KPSB_DB=test_v6_f3_psb_audit_test KPSB_WEB_ROOT="$KPSB_DIR/web" php tests/keamanan_psb_http.php
run regresi/psb-browser env BASE_URL=http://127.0.0.1:8971 KPSB_BROWSER=1 node tests/browser/uji-keamanan-psb.mjs
KPSB_DIR="$KPSB_DIR" bash tests/keamanan_psb_server.sh stop >> "$T/psb-server.log" 2>&1
run migrasi-rollback-akhir env V6_RUN_MIGRATION=1 php tests/v6_phase3_migration.php
echo "Log privat: $T" | tee -a "$R"
