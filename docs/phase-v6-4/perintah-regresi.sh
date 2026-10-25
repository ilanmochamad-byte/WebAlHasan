#!/usr/bin/env bash
# Regresi PRD V6 Fase 4: Fase 1–3 (V6), PSB, fondasi, V2/V5, redesain web, pada
# DB salinan `test_*_test` berfixture sintetis. Jalankan dari akar repo web,
# BERURUTAN. Keluaran per suite di docs/phase-v6-4/bukti/regresi/, ringkasan di
# ringkasan.txt. Tidak menyentuh DB produksi.
#
#   KPSB_DIR=/private/tmp/<privat> bash docs/phase-v6-4/perintah-regresi.sh
#
# Fase 4 tanpa migrasi baru: 029 tetap migrasi terakhir. Oracle historis
# Fase 1–2 (027/028 terakhir, transfer tanpa centang sah) dicatat di README.
set -u
cd "$(dirname "$0")/../.."
OUT=docs/phase-v6-4/bukti/regresi
mkdir -p "$OUT"
RINGKAS="$OUT/ringkasan.txt"
: > "$RINGKAS"
T="${TMPDIR:-/tmp}"
KPSB_DIR="${KPSB_DIR:?KPSB_DIR wajib (folder privat di luar checkout untuk server PSB Apache)}"

hitung() { local f="$1" l g; l=$(grep -cE '^\[lulus\]|^ *\[lulus\]' "$f" 2>/dev/null); g=$(grep -cE '^\[gagal\]|^ *\[gagal\]' "$f" 2>/dev/null); echo "${l:-0}/${g:-0}"; }
jalan() { local nama="$1"; shift; "$@" > "$OUT/$nama.txt" 2>&1; local kode=$?; echo "$nama exit=$kode $(hitung "$OUT/$nama.txt")" | tee -a "$RINGKAS"; }
db() { "$@" > /dev/null 2>&1 || echo "PENYIAPAN GAGAL: $*" | tee -a "$RINGKAS"; }

echo "== Statis (seluruh suite) ==" | tee -a "$RINGKAS"
for f in tests/*_static.php; do jalan "statis-$(basename "$f" .php)" php "$f"; done

echo "== V6 Fase 1 (kode Fase 4, DB 027–029) ==" | tee -a "$RINGKAS"
db bash tests/v6_phase1_db.sh
jalan v6-fase1-integrasi env V6_RUN_INTEGRATION=1 php tests/v6_phase1_integration.php
db env V6F1_DB=test_v6_f1_http_test bash tests/v6_phase1_db.sh
jalan v6-fase1-http env V6F1_DB=test_v6_f1_http_test php tests/v6_phase1_http.php
db env V6F1_DB=test_v6_f1_audit_test bash tests/v6_phase1_db.sh
jalan v6-fase1-probe-audit env V6F1_DB=test_v6_f1_audit_test V6_RUN_AUDIT=1 php tests/v6_phase1_audit.php
db env V6F1_DB=test_v6_f1_browser_test bash tests/v6_phase1_db.sh
DB_NAME=test_v6_f1_browser_test php -S 127.0.0.1:8998 tests/redesain_web_tahap1_router.php > "$T/v6f4-reg-f1b.log" 2>&1 & P=$!; sleep 1
jalan v6-fase1-browser bash -c "cd tests/browser && BASE_URL=http://127.0.0.1:8998 OUT_DIR='$T/v6f4-reg-f1-tangkapan' node uji-v6-fase1.mjs"
kill $P

echo "== V6 Fase 2 (kode Fase 4, DB 027–029) ==" | tee -a "$RINGKAS"
db bash tests/v6_phase2_db.sh
jalan v6-fase2-integrasi env V6_RUN_INTEGRATION=1 php tests/v6_phase2_integration.php
db env V6F2_DB=test_v6_f2_http_test bash tests/v6_phase2_db.sh
jalan v6-fase2-http env V6F2_DB=test_v6_f2_http_test php tests/v6_phase2_http.php
db env V6F2_DB=test_v6_f2_audit_test bash tests/v6_phase2_db.sh
jalan v6-fase2-probe-audit env V6F2_DB=test_v6_f2_audit_test V6_RUN_AUDIT=1 php tests/v6_phase2_audit.php
db env V6F2_DB=test_v6_f2_legacy_test bash tests/v6_phase2_db.sh
jalan v6-fase2-probe-era027 env DB_NAME=test_v6_f2_legacy_test V6_RUN_AUDIT=1 php tests/v6_phase2_legacy_audit.php
db env V6F2_DB=test_v6_f2_browser_test bash tests/v6_phase2_db.sh
DB_NAME=test_v6_f2_browser_test php -S 127.0.0.1:8997 tests/redesain_web_tahap1_router.php > "$T/v6f4-reg-f2b.log" 2>&1 & P=$!; sleep 1
jalan v6-fase2-browser bash -c "cd tests/browser && BASE_URL=http://127.0.0.1:8997 OUT_DIR='$T/v6f4-reg-f2-tangkapan' node uji-v6-fase2.mjs"
kill $P
jalan v6-fase1-migrasi env V6_RUN_MIGRATION=1 php tests/v6_phase1_migration.php
jalan v6-fase2-migrasi env V6_RUN_MIGRATION=1 php tests/v6_phase2_migration.php

echo "== V6 Fase 3 (kode Fase 4) ==" | tee -a "$RINGKAS"
db bash tests/v6_phase3_db.sh
jalan v6-fase3-integrasi env V6_RUN_INTEGRATION=1 php tests/v6_phase3_integration.php
db env V6F3_DB=test_v6_f3_http_test bash tests/v6_phase3_db.sh
jalan v6-fase3-http env V6F3_DB=test_v6_f3_http_test php tests/v6_phase3_http.php
db env V6F3_DB=test_v6_f3_audit_test bash tests/v6_phase3_db.sh
jalan v6-fase3-probe-audit env V6F3_DB=test_v6_f3_audit_test V6_RUN_AUDIT=1 php tests/v6_phase3_audit.php
db env V6F3_DB=test_v6_f3_boundary_test bash tests/v6_phase3_db.sh
jalan v6-fase3-probe-tengah-malam env V6F3_DB=test_v6_f3_boundary_test V6_RUN_AUDIT=1 php tests/v6_phase3_boundary_audit.php
jalan v6-fase3-migrasi env V6_RUN_MIGRATION=1 php tests/v6_phase3_migration.php
db env V6F3_DB=test_v6_f3_browser_test bash tests/v6_phase3_db.sh
DB_NAME=test_v6_f3_browser_test php -S 127.0.0.1:8996 tests/redesain_web_tahap1_router.php > "$T/v6f4-reg-f3b.log" 2>&1 & P=$!; sleep 1
jalan v6-fase3-browser bash -c "cd tests/browser && BASE_URL=http://127.0.0.1:8996 OUT_DIR='$T/v6f4-reg-f3-tangkapan' node uji-v6-fase3.mjs"
kill $P

echo "== PSB (Apache + php-fpm, .htaccess aktif) ==" | tee -a "$RINGKAS"
mysql -e 'DROP DATABASE IF EXISTS test_v6_f4_psb_test; CREATE DATABASE test_v6_f4_psb_test'
SRC=$(grep '^DB_NAME=' .env | cut -d= -f2)
mysqldump "$SRC" | mysql test_v6_f4_psb_test && DB_NAME=test_v6_f4_psb_test php bin/migrate.php up > /dev/null
KPSB_DIR="$KPSB_DIR" KPSB_PORT=8971 KPSB_DB=test_v6_f4_psb_test bash tests/keamanan_psb_server.sh start > /dev/null 2>&1
jalan keamanan-psb-http env KEAMANAN_PSB_RUN_TESTS=1 KPSB_URL=http://127.0.0.1:8971 KPSB_DB=test_v6_f4_psb_test KPSB_WEB_ROOT="$KPSB_DIR/web" php tests/keamanan_psb_http.php
jalan keamanan-psb-browser bash -c 'BASE_URL=http://127.0.0.1:8971 KPSB_BROWSER=1 node tests/browser/uji-keamanan-psb.mjs'
KPSB_DIR="$KPSB_DIR" bash tests/keamanan_psb_server.sh stop > /dev/null 2>&1

echo "== Fondasi, V2, V5 ==" | tee -a "$RINGKAS"
db env V6F3_DB=test_v6_f4_regresi_test bash tests/v6_phase3_db.sh
jalan penugasan-integrasi env PENUGASAN_RUN_INTEGRATION=1 DB_NAME=test_v6_f4_regresi_test php tests/penugasan_integration.php
jalan v2-fase4-integrasi env V2_PHASE4_RUN_INTEGRATION=1 DB_NAME=test_v6_f4_regresi_test php tests/v2_phase4_integration.php
jalan v2-fase5-integrasi env V2_PHASE5_RUN_INTEGRATION=1 DB_NAME=test_v6_f4_regresi_test php tests/v2_phase5_integration.php
db bash tests/v5_phase5_db.sh
jalan v5-fase5-integrasi env V5_RUN_INTEGRATION=1 DB_NAME=test_v5_f5_test php tests/v5_phase5_integration.php
db bash tests/v5_phase3_db.sh
jalan v5-fase3-integrasi env V5_RUN_INTEGRATION=1 php tests/v5_phase3_integration.php

echo "== Redesain web (HTTP) ==" | tee -a "$RINGKAS"
db bash tests/redesain_web_tahap2_db.sh
jalan redesain-tahap2-http php tests/redesain_web_tahap2_http.php
db bash tests/redesain_web_tahap3_db.sh
jalan redesain-tahap3-http php tests/redesain_web_tahap3_http.php
db bash tests/redesain_web_tahap5_db.sh
jalan redesain-tahap5-http php tests/redesain_web_tahap5_http.php
db bash tests/redesain_web_tahap7_db.sh
jalan redesain-tahap7-http php tests/redesain_web_tahap7_http.php
db bash tests/redesain_web_tahap6_db.sh
T6=$(grep -oE 'TAHAP6_DB:-[a-z0-9_]+' tests/redesain_web_tahap6_db.sh | cut -d- -f2)
{ for f in v6_phase3 v6_phase2 v6_phase1 v5_phase3 v5_phase2 v5_phase1; do DB_NAME="$T6" php "bin/${f}_rollback_preflight.php" && DB_NAME="$T6" php bin/migrate.php rollback; done; } > "$OUT/redesain-tahap6-rollback-029-024.txt" 2>&1
echo "redesain-tahap6-rollback exit=$? $(hitung "$OUT/redesain-tahap6-rollback-029-024.txt")" | tee -a "$RINGKAS"
jalan redesain-tahap6-http-tanpa-024-029 php tests/redesain_web_tahap6_http.php

echo "== Browser kerangka sembilan akun (DB V6 Fase 3) ==" | tee -a "$RINGKAS"
db env V6F3_DB=test_v6_f4_kerangka_test bash tests/v6_phase3_db.sh
DB_NAME=test_v6_f4_kerangka_test php -S 127.0.0.1:8931 tests/redesain_web_tahap1_router.php > "$T/v6f4-reg-kerangka.log" 2>&1 & P=$!; sleep 1
jalan browser-kerangka-sembilan-akun bash -c 'cd tests/browser && BASE_URL=http://127.0.0.1:8931 node uji-redesain-web-tahap1.mjs'
kill $P
echo "SELESAI" | tee -a "$RINGKAS"
