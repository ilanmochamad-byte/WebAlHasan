#!/usr/bin/env bash
# Regresi Fase 5 penugasan lintas guru/pengurus (kesiapan rilis). Fase 5 tidak
# mengubah kode aplikasi, sehingga regresi memastikan (a) seluruh suite Fase
# 1–4 tetap lulus seperti baseline dan (b) verifier rilis baru LULUS pada
# keadaan DB yang dihasilkan setiap suite resmi itu (tidak memberi positif
# palsu), ditambah fondasi penugasan, V7 Fase 5, dan seluruh suite statis.
#
#   bash docs/penugasan-keamanan/fase-5/perintah-regresi.sh
#
# Probe audit Codex Fase 2 (perlu arsip baseline 464d9a1) dan matriks laporan
# Fase 3 (perlu server dan sakelar audit) tidak diulang: Fase 5 tidak mengubah
# kode yang mereka periksa.
#
# Jalankan BERURUTAN; tidak bersamaan dengan skrip lain yang membangun DB.
set -u
SKRIP_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SKRIP_DIR/../../.." || exit 2
OUT="${OUT:-$SKRIP_DIR/bukti/regresi}"
PFX="${PFX:-pkf5}"
mkdir -p "$OUT"
RINGKAS="$OUT/ringkasan.txt"
: > "$RINGKAS"

hitung() { local f="$1" l g; l=$(grep -cE '^ *\[(lulus|OK|ok)\]|^ *✓|^PASS|^ok ' "$f" 2>/dev/null); g=$(grep -cE '^ *\[(gagal|GAGAL|FAIL)\]|^ *✗|^FAIL|^not ok ' "$f" 2>/dev/null); echo "${l:-0}/${g:-0}"; }
jalan() { local nama="$1"; shift; "$@" > "$OUT/$nama.txt" 2>&1; local kode=$?; echo "$nama exit=$kode $(hitung "$OUT/$nama.txt")" | tee -a "$RINGKAS"; }
db() { "$@" > /dev/null 2>&1 || echo "PENYIAPAN GAGAL: $*" | tee -a "$RINGKAS"; }
# Verifier rilis pada DB sesudah suite: `[gagal]` di sini berarti verifier menolak keadaan yang dibentuk suite resmi.
verif() { DB_NAME="$1" php bin/penugasan_keamanan_verify.php > "$OUT/verifier-$2.txt" 2>&1; echo "verifier-$2 exit=$? $(grep -o '[0-9]* lulus, [0-9]* gagal' "$OUT/verifier-$2.txt" | tail -1)" | tee -a "$RINGKAS"; }

echo "== Penugasan Fase 1 (identitas pengurus milik guru) ==" | tee -a "$RINGKAS"
db env PKF1_DB=test_${PFX}_f1_test bash tests/penugasan_keamanan_f1_db.sh
jalan pk-fase1-integrasi env PKF1_DB=test_${PFX}_f1_test php tests/penugasan_keamanan_f1_integration.php
verif test_${PFX}_f1_test pk-fase1-integrasi
db env PKF1_DB=test_${PFX}_f1a_test bash tests/penugasan_keamanan_f1_db.sh
jalan pk-fase1-probe-audit env PKF1_DB=test_${PFX}_f1a_test php tests/penugasan_keamanan_f1_audit_probe.php
jalan pk-fase1-probe-token env PKF1_DB=test_${PFX}_f1a_test PKF1_TOKEN_PORT=8980 php tests/penugasan_keamanan_f1_token_probe.php
verif test_${PFX}_f1a_test pk-fase1-probe
db env PKF1_DB=test_${PFX}_f1h_test bash tests/penugasan_keamanan_f1_db.sh
jalan pk-fase1-http env PKF1_DB=test_${PFX}_f1h_test PKF1_PORT=8976 php tests/penugasan_keamanan_f1_http.php
verif test_${PFX}_f1h_test pk-fase1-http

echo "== Penugasan Fase 2 (pemisahan tugas) ==" | tee -a "$RINGKAS"
db env PKF2_DB=test_${PFX}_f2_test bash tests/penugasan_keamanan_f2_db.sh
jalan pk-fase2-integrasi env PKF2_DB=test_${PFX}_f2_test php tests/penugasan_keamanan_f2_integration.php
verif test_${PFX}_f2_test pk-fase2-integrasi
db env PKF2_DB=test_${PFX}_f2h_test bash tests/penugasan_keamanan_f2_db.sh
jalan pk-fase2-http env PKF2_DB=test_${PFX}_f2h_test PKF2_PORT=8979 php tests/penugasan_keamanan_f2_http.php
verif test_${PFX}_f2h_test pk-fase2-http

echo "== Penugasan Fase 3 (Keamanan di website) ==" | tee -a "$RINGKAS"
db env PKF3_DB=test_${PFX}_f3_test bash tests/penugasan_keamanan_f3_db.sh
jalan pk-fase3-integrasi env PKF3_DB=test_${PFX}_f3_test php tests/penugasan_keamanan_f3_integration.php
verif test_${PFX}_f3_test pk-fase3-integrasi
db env PKF3_DB=test_${PFX}_f3h_test bash tests/penugasan_keamanan_f3_db.sh
jalan pk-fase3-http env PKF3_DB=test_${PFX}_f3h_test php tests/penugasan_keamanan_f3_http.php
verif test_${PFX}_f3h_test pk-fase3-http
db env PKF3_DB=test_${PFX}_f3m_test bash tests/penugasan_keamanan_f3_db.sh
jalan pk-fase3-migrasi env PKF3_DB=test_${PFX}_f3m_test php tests/penugasan_keamanan_f3_migration.php
db env PKF3_DB=test_${PFX}_f3a_test bash tests/penugasan_keamanan_f3_db.sh
jalan pk-fase3-probe-audit env PKF3_DB=test_${PFX}_f3a_test php tests/penugasan_keamanan_f3_audit_probe.php
verif test_${PFX}_f3a_test pk-fase3-probe-audit

echo "== Penugasan Fase 4 (REST Keamanan) ==" | tee -a "$RINGKAS"
db env PKF3_DB=test_${PFX}_f4h_test bash tests/penugasan_keamanan_f4_db.sh
jalan pk-fase4-http env PKF3_DB=test_${PFX}_f4h_test php tests/penugasan_keamanan_f4_http.php
verif test_${PFX}_f4h_test pk-fase4-http

echo "== Fondasi penugasan dan V7 Fase 5 ==" | tee -a "$RINGKAS"
db env V7F1_DB=test_${PFX}_reg1_test bash tests/v7_phase1_db.sh
jalan penugasan-integrasi env PENUGASAN_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_reg1_test php tests/penugasan_integration.php
db env V7F5_DB=test_${PFX}_v7f5_test bash tests/v7_phase5_db.sh
jalan v7-fase5-e2e env V7F5_DB=test_${PFX}_v7f5_test php tests/v7_phase5_e2e.php
db env V7F5_DB=test_${PFX}_v7f5h_test bash tests/v7_phase5_db.sh
jalan v7-fase5-http env V7F5_DB=test_${PFX}_v7f5h_test V7F5_PORT=8977 php tests/v7_phase5_http.php
jalan v7-fase5-rilis env V7_RUN_RILIS=1 V7F5_RILIS_DB=test_${PFX}_v7rilis_test php tests/v7_phase5_rilis.php

echo "== Statis (seluruh suite) ==" | tee -a "$RINGKAS"
for f in tests/*_static.php; do jalan "statis-$(basename "$f" .php)" php "$f"; done
echo "SELESAI" | tee -a "$RINGKAS"
