#!/usr/bin/env bash
# Uji perbaikan "Terbitkan tagihan" satu santri dari tarif + regresi V6 yang
# menyentuh halaman tagihan satu santri, penerbitan, dan laporan, pada DB
# salinan `test_*_test` berfixture sintetis. Jalankan dari akar repo web,
# BERURUTAN (rantai fixture memakai DB perantara bernama tetap). Keluaran di
# docs/perbaikan-tagihan-satu-santri/bukti/, ringkasan di ringkasan.txt.
#
#   bash docs/perbaikan-tagihan-satu-santri/perintah-uji.sh
set -u
cd "$(dirname "$0")/../.."
OUT=docs/perbaikan-tagihan-satu-santri/bukti
mkdir -p "$OUT" docs/perbaikan-tagihan-satu-santri/tangkapan
RINGKAS="$OUT/ringkasan.txt"
: > "$RINGKAS"
T="${TMPDIR:-/tmp}"
hitung() { local f="$1" l g; l=$(grep -cE '^ *\[lulus\]' "$f" 2>/dev/null); g=$(grep -cE '^ *\[gagal\]' "$f" 2>/dev/null); echo "${l:-0}/${g:-0}"; }
jalan() { local nama="$1"; shift; "$@" > "$OUT/$nama.txt" 2>&1; local kode=$?; echo "$nama exit=$kode $(hitung "$OUT/$nama.txt")" | tee -a "$RINGKAS"; }
db() { "$@" > /dev/null 2>&1 || echo "PENYIAPAN GAGAL: $*" | tee -a "$RINGKAS"; }

echo "== Perbaikan satu santri ==" | tee -a "$RINGKAS"
db env V6F5_DB=test_v6_satu_santri_test bash tests/v6_phase5_db.sh
jalan integrasi env V6_RUN_INTEGRATION=1 DB_NAME=test_v6_satu_santri_test php tests/v6_tagihan_satu_santri_integration.php
db env V6F5_DB=test_v6_satu_santri_http_test bash tests/v6_phase5_db.sh
jalan http env DB_NAME=test_v6_satu_santri_http_test php tests/v6_tagihan_satu_santri_http.php
db env V6F5_DB=test_v6_satu_santri_browser_test bash tests/v6_phase5_db.sh
db env DB_NAME=test_v6_satu_santri_browser_test php tests/v6_tagihan_satu_santri_seed.php
SMP_LAIN=$(mysql -N -B test_v6_satu_santri_browser_test -e "SELECT s.id FROM santri s JOIN plotting_kelas pk ON pk.id_santri = s.id AND pk.status = 'Aktif' AND pk.id_tahun = (SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1) JOIN kelas kl ON kl.id = pk.id_kelas WHERE s.is_active = 1 AND s.archived_at IS NULL AND kl.jenjang = 'SMP' AND s.nis <> 'V6S0001' ORDER BY s.id LIMIT 1")
DB_NAME=test_v6_satu_santri_browser_test php -S 127.0.0.1:8967 tests/redesain_web_tahap1_router.php > "$T/v6ss-browser.log" 2>&1 & P=$!; sleep 1
jalan browser bash -c "cd tests/browser && BASE_URL=http://127.0.0.1:8967 SMP_LAIN=$SMP_LAIN OUT_DIR=../../docs/perbaikan-tagihan-satu-santri/tangkapan node uji-tagihan-satu-santri.mjs"
kill $P
echo "galat PHP server browser: $(grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$T/v6ss-browser.log")" | tee -a "$RINGKAS"

echo "== Regresi V6 ==" | tee -a "$RINGKAS"
db env V6F1_DB=test_v6_f1_http_test bash tests/v6_phase1_db.sh
jalan v6-fase1-http env V6F1_DB=test_v6_f1_http_test php tests/v6_phase1_http.php
db env V6F1_DB=test_v6_f1_audit_test bash tests/v6_phase1_db.sh
jalan v6-fase1-probe-audit env V6F1_DB=test_v6_f1_audit_test V6_RUN_AUDIT=1 php tests/v6_phase1_audit.php
db env V6F1_DB=test_v6_f1_browser_test bash tests/v6_phase1_db.sh
DB_NAME=test_v6_f1_browser_test php -S 127.0.0.1:8998 tests/redesain_web_tahap1_router.php > "$T/v6ss-f1b.log" 2>&1 & P=$!; sleep 1
jalan v6-fase1-browser bash -c "cd tests/browser && BASE_URL=http://127.0.0.1:8998 OUT_DIR='$T/v6ss-f1-tangkapan' node uji-v6-fase1.mjs"
kill $P
db bash tests/v6_phase2_db.sh
jalan v6-fase2-integrasi env V6_RUN_INTEGRATION=1 php tests/v6_phase2_integration.php
db env V6F2_DB=test_v6_f2_http_test bash tests/v6_phase2_db.sh
jalan v6-fase2-http env V6F2_DB=test_v6_f2_http_test php tests/v6_phase2_http.php
db env V6F2_DB=test_v6_f2_audit_test bash tests/v6_phase2_db.sh
jalan v6-fase2-probe-audit env V6F2_DB=test_v6_f2_audit_test V6_RUN_AUDIT=1 php tests/v6_phase2_audit.php
db env V6F2_DB=test_v6_f2_browser_test bash tests/v6_phase2_db.sh
DB_NAME=test_v6_f2_browser_test php -S 127.0.0.1:8997 tests/redesain_web_tahap1_router.php > "$T/v6ss-f2b.log" 2>&1 & P=$!; sleep 1
jalan v6-fase2-browser bash -c "cd tests/browser && BASE_URL=http://127.0.0.1:8997 OUT_DIR='$T/v6ss-f2-tangkapan' node uji-v6-fase2.mjs"
kill $P
db bash tests/v6_phase3_db.sh
jalan v6-fase3-integrasi env V6_RUN_INTEGRATION=1 php tests/v6_phase3_integration.php
db env V6F3_DB=test_v6_f3_http_test bash tests/v6_phase3_db.sh
jalan v6-fase3-http env V6F3_DB=test_v6_f3_http_test php tests/v6_phase3_http.php
db env V6F3_DB=test_v6_f3_browser_test bash tests/v6_phase3_db.sh
DB_NAME=test_v6_f3_browser_test php -S 127.0.0.1:8996 tests/redesain_web_tahap1_router.php > "$T/v6ss-f3b.log" 2>&1 & P=$!; sleep 1
jalan v6-fase3-browser bash -c "cd tests/browser && BASE_URL=http://127.0.0.1:8996 OUT_DIR='$T/v6ss-f3-tangkapan' node uji-v6-fase3.mjs"
kill $P
db env V6F4_DB=test_v6_f4_http_test bash tests/v6_phase4_db.sh
jalan v6-fase4-http env V6F4_DB=test_v6_f4_http_test V6F4_LOG="$T/v6ss-f4http.log" php tests/v6_phase4_http.php
db bash tests/v6_phase5_db.sh
jalan v6-fase5-integrasi env V6_RUN_INTEGRATION=1 php tests/v6_phase5_integration.php
db env V6F5_DB=test_v6_f5_http_test bash tests/v6_phase5_db.sh
jalan v6-fase5-http env V6F5_DB=test_v6_f5_http_test php tests/v6_phase5_http.php
jalan v6-verify-sesudah-fase5-http env DB_NAME=test_v6_f5_http_test php bin/v6_verify.php

echo "== Statis (seluruh suite) ==" | tee -a "$RINGKAS"
for f in tests/*_static.php; do jalan "statis-$(basename "$f" .php)" php "$f"; done
echo "SELESAI" | tee -a "$RINGKAS"
