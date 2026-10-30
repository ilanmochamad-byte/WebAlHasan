#!/usr/bin/env bash
# Prasyarat lokal: /tmp/pkf3-audit-clean.sql = dump DB test_pk_f3_clean_test
# yang dibentuk f3_db.sh sebelum seed. Klien mysql lokal memakai akun uji.
# /tmp/pkf3-f1-baseline = git archive 464d9a1 dengan .env/vendor lokal.
# Seluruh penyiapan berurutan; tidak ada DB produksi atau dump pribadi.
set -u
cd "$(dirname "$0")/../../../.." || exit 2
OUT="$PWD/docs/penugasan-keamanan/fase-3/audit-codex/regresi"
mkdir -p "$OUT"
: > "$OUT/ringkasan.txt"
run() {
  local label="$1"; shift
  "$@" > "$OUT/$label.txt" 2>&1
  local result=$?
  echo "$label exit=$result lulus=$(rg -c '^ *\[(lulus|OK|ok)\]|^ *✓|^PASS|^ok ' "$OUT/$label.txt" || true) gagal=$(rg -c '^ *\[(gagal|GAGAL|FAIL)\]|^ *✗|^FAIL|^not ok ' "$OUT/$label.txt" || true)" | tee -a "$OUT/ringkasan.txt"
}
clone() {
  local name="$1"
  case "$name" in test_pkf3_audit_*_test) ;; *) exit 2;; esac
  mysql -e "DROP DATABASE IF EXISTS \`$name\`; CREATE DATABASE \`$name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" || exit 2
  mysql "$name" < /tmp/pkf3-audit-clean.sql || exit 2
}
clone test_pkf3_audit_f3_test
run f3-integrasi env PKF3_DB=test_pkf3_audit_f3_test php tests/penugasan_keamanan_f3_integration.php
clone test_pkf3_audit_f1_test
run f1-integrasi env PKF1_DB=test_pkf3_audit_f1_test php tests/penugasan_keamanan_f1_integration.php
clone test_pkf3_audit_f1a_test
run f1-probe env PKF1_DB=test_pkf3_audit_f1a_test php tests/penugasan_keamanan_f1_audit_probe.php
run f1-token env PKF1_DB=test_pkf3_audit_f1a_test PKF1_TOKEN_PORT=8997 php tests/penugasan_keamanan_f1_token_probe.php
clone test_pkf3_audit_f1h_test
run f1-http env PKF1_DB=test_pkf3_audit_f1h_test PKF1_PORT=8998 php tests/penugasan_keamanan_f1_http.php
clone test_pkf3_audit_f2_test
run f2-integrasi env PKF2_DB=test_pkf3_audit_f2_test php tests/penugasan_keamanan_f2_integration.php
run f2-probe env PKF2_DB=test_pkf3_audit_f2_test PKF2_BASELINE=/tmp/pkf3-f1-baseline php tests/penugasan_keamanan_f2_audit_probe.php
clone test_pkf3_audit_f2v_test
run f2-integrasi-v4 env PKF2_DB=test_pkf3_audit_f2v_test php tests/penugasan_keamanan_f2_integration.php
run f2-probe-v4 env PKF2_DB=test_pkf3_audit_f2v_test php tests/penugasan_keamanan_f2_audit_v4.php
clone test_pkf3_audit_f2h_test
run f2-http env PKF2_DB=test_pkf3_audit_f2h_test PKF2_PORT=8999 php tests/penugasan_keamanan_f2_http.php
clone test_pkf3_audit_v7_test
run v7-f3-integrasi env V7F3_DB=test_pkf3_audit_v7_test php tests/v7_phase3_integration.php
run v7-f3-notifikasi env V7_RUN_AUDIT=1 DB_NAME=test_pkf3_audit_v7_test php tests/v7_phase3_audit_notifications.php
clone test_pkf3_audit_v7h_test
run v7-f3-http env V7F3_DB=test_pkf3_audit_v7h_test V7F3_PORT=9000 php tests/v7_phase3_http.php
clone test_pkf3_audit_fondasi_test
run penugasan env PENUGASAN_RUN_INTEGRATION=1 DB_NAME=test_pkf3_audit_fondasi_test php tests/penugasan_integration.php
run v2-f4 env V2_PHASE4_RUN_INTEGRATION=1 DB_NAME=test_pkf3_audit_fondasi_test php tests/v2_phase4_integration.php
run v2-f5 env V2_PHASE5_RUN_INTEGRATION=1 DB_NAME=test_pkf3_audit_fondasi_test php tests/v2_phase5_integration.php
# Suite V3 mensyaratkan nama tetap; pertahankan DB uji sebelum setiap suite.
if mysqldump --single-transaction --routines --triggers webalhasan_v3_phase1_test > /tmp/pkf3-v3-before.sql; then
  for suite in v3_phase2_integration v3_phase3_integration v3_phase4_integration v3_phase5_integration v3_phase5_access; do
    run "$suite" env DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1 php "tests/$suite.php"
    mysql webalhasan_v3_phase1_test < /tmp/pkf3-v3-before.sql || exit 2
  done
else exit 2; fi
run tahap4-setup bash tests/redesain_web_tahap4_db.sh
run tahap4-http php tests/redesain_web_tahap4_http.php
run tahap5-setup bash tests/redesain_web_tahap5_db.sh
run tahap5-http php tests/redesain_web_tahap5_http.php
for file in tests/*_static.php; do run "statis-$(basename "$file" .php)" php "$file"; done
echo SELESAI | tee -a "$OUT/ringkasan.txt"
