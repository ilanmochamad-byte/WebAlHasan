#!/usr/bin/env bash
# Regresi Fase 2 penugasan lintas guru/pengurus (pemisahan tugas): jalur yang
# disentuh — perizinan V2 dan V7 (Fase 1–5), pembinaan V3, sikap/verifikasi
# rapor V4 lewat HTTP redesain, fondasi penugasan dan Fase 1, serta seluruh
# suite statis — pada DB salinan `test_*_test` berfixture sintetis.
#
#   bash docs/penugasan-keamanan/fase-2/perintah-regresi.sh
#   KODE=/jalur/arsip-baseline OUT=/jalur/keluaran PFX=pkf2k bash docs/penugasan-keamanan/fase-2/perintah-regresi.sh
#
# KODE  pohon kode yang diuji (bawaan: repo ini). Untuk kontrol baseline,
#       arahkan ke `git archive 464d9a1` yang berisi .env dan vendor/.
# OUT   folder keluaran (bawaan: docs/penugasan-keamanan/fase-2/bukti/regresi).
# PFX   awalan nama DB uji agar kontrol tidak menimpa DB uji kode kini.
#
# Jalankan BERURUTAN; tidak bersamaan dengan skrip lain yang membangun DB.
# Pembinaan V3 memakai DB tetap `webalhasan_v3_phase1_test` (syarat suite
# V3): DB itu di-dump dahulu lalu dipulihkan sesudah suite, sehingga kontrol
# baseline dan kode kini mulai dari isi yang sama.
set -u
SKRIP_DIR="$(cd "$(dirname "$0")" && pwd)"
KODE="${KODE:-$(cd "$SKRIP_DIR/../../.." && pwd)}"
OUT="${OUT:-$SKRIP_DIR/bukti/regresi}"
PFX="${PFX:-pkf2}"
cd "$KODE" || exit 2
mkdir -p "$OUT"
RINGKAS="$OUT/ringkasan.txt"
: > "$RINGKAS"
T="${TMPDIR:-/tmp}"

hitung() { local f="$1" l g; l=$(grep -cE '^ *\[(lulus|OK|ok)\]|^ *✓|^PASS|^ok ' "$f" 2>/dev/null); g=$(grep -cE '^ *\[(gagal|GAGAL|FAIL)\]|^ *✗|^FAIL|^not ok ' "$f" 2>/dev/null); echo "${l:-0}/${g:-0}"; }
jalan() { local nama="$1"; shift; "$@" > "$OUT/$nama.txt" 2>&1; local kode=$?; echo "$nama exit=$kode $(hitung "$OUT/$nama.txt")" | tee -a "$RINGKAS"; }
db() { "$@" > /dev/null 2>&1 || echo "PENYIAPAN GAGAL: $*" | tee -a "$RINGKAS"; }

echo "== Penugasan Fase 1 (identitas pengurus milik guru) ==" | tee -a "$RINGKAS"
db env PKF1_DB=test_${PFX}_f1_test bash tests/penugasan_keamanan_f1_db.sh
jalan pk-fase1-integrasi env PKF1_DB=test_${PFX}_f1_test php tests/penugasan_keamanan_f1_integration.php
db env PKF1_DB=test_${PFX}_f1a_test bash tests/penugasan_keamanan_f1_db.sh
jalan pk-fase1-probe-audit env PKF1_DB=test_${PFX}_f1a_test php tests/penugasan_keamanan_f1_audit_probe.php
# Probe token memakai keadaan sesudah probe audit pada DB yang sama.
jalan pk-fase1-probe-token env PKF1_DB=test_${PFX}_f1a_test PKF1_TOKEN_PORT=8980 php tests/penugasan_keamanan_f1_token_probe.php
db env PKF1_DB=test_${PFX}_f1h_test bash tests/penugasan_keamanan_f1_db.sh
jalan pk-fase1-http env PKF1_DB=test_${PFX}_f1h_test PKF1_PORT=8976 php tests/penugasan_keamanan_f1_http.php

echo "== V7 Fase 5 (E2E dan HTTP) ==" | tee -a "$RINGKAS"
db env V7F5_DB=test_${PFX}_v7f5_test bash tests/v7_phase5_db.sh
jalan v7-fase5-e2e env V7F5_DB=test_${PFX}_v7f5_test php tests/v7_phase5_e2e.php
db env V7F5_DB=test_${PFX}_v7f5h_test bash tests/v7_phase5_db.sh
jalan v7-fase5-http env V7F5_DB=test_${PFX}_v7f5h_test V7F5_PORT=8977 php tests/v7_phase5_http.php
db env V7F5_DB=test_${PFX}_v7f5s_test bash tests/v7_phase5_db.sh
jalan v7-fase5-probe-cakupan env V7_RUN_AUDIT=1 DB_NAME=test_${PFX}_v7f5s_test php tests/v7_phase5_scope.php
db env V7F5_DB=test_${PFX}_v7f5r_test bash tests/v7_phase5_db.sh
jalan v7-fase5-probe-pencabutan env V7_RUN_AUDIT=1 DB_NAME=test_${PFX}_v7f5r_test php tests/v7_phase5_revocation.php

echo "== V7 Fase 4 (paritas REST aplikasi) ==" | tee -a "$RINGKAS"
db env V7F4_DB=test_${PFX}_v7f4_test bash tests/v7_phase4_db.sh
jalan v7-fase4-http env V7F4_DB=test_${PFX}_v7f4_test php tests/v7_phase4_http.php

echo "== V7 Fase 3 ==" | tee -a "$RINGKAS"
db env V7F3_DB=test_${PFX}_v7f3_test bash tests/v7_phase3_db.sh
jalan v7-fase3-integrasi env V7F3_DB=test_${PFX}_v7f3_test php tests/v7_phase3_integration.php
jalan v7-fase3-probe-audit-notifikasi env V7_RUN_AUDIT=1 DB_NAME=test_${PFX}_v7f3_test php tests/v7_phase3_audit_notifications.php
db env V7F3_DB=test_${PFX}_v7f3h_test bash tests/v7_phase3_db.sh
jalan v7-fase3-http env V7F3_DB=test_${PFX}_v7f3h_test php tests/v7_phase3_http.php

echo "== V7 Fase 2 ==" | tee -a "$RINGKAS"
db env V7F2_DB=test_${PFX}_v7f2_test bash tests/v7_phase2_db.sh
jalan v7-fase2-integrasi env V7F2_DB=test_${PFX}_v7f2_test php tests/v7_phase2_integration.php
db env V7F2_DB=test_${PFX}_v7f2h_test bash tests/v7_phase2_db.sh
jalan v7-fase2-http env V7F2_DB=test_${PFX}_v7f2h_test php tests/v7_phase2_http.php
db env V7F2_DB=test_${PFX}_v7f2a_test bash tests/v7_phase2_db.sh
jalan v7-fase2-probe-audit-codex env V7_RUN_AUDIT=1 DB_NAME=test_${PFX}_v7f2a_test php tests/v7_phase2_audit.php

echo "== V7 Fase 1 ==" | tee -a "$RINGKAS"
db env V7F1_DB=test_${PFX}_v7f1_test bash tests/v7_phase1_db.sh
jalan v7-fase1-integrasi env V7F1_DB=test_${PFX}_v7f1_test php tests/v7_phase1_integration.php
db env V7F1_DB=test_${PFX}_v7f1h_test bash tests/v7_phase1_db.sh
jalan v7-fase1-http env V7F1_DB=test_${PFX}_v7f1h_test V7F1_PORT=8978 php tests/v7_phase1_http.php

echo "== Fondasi penugasan, perizinan dan notifikasi V2 ==" | tee -a "$RINGKAS"
for n in 1 2 3 4 5 6; do db env V7F1_DB=test_${PFX}_reg${n}_test bash tests/v7_phase1_db.sh; done
jalan penugasan-integrasi env PENUGASAN_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_reg1_test php tests/penugasan_integration.php
jalan v2-fase4-integrasi env V2_PHASE4_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_reg1_test php tests/v2_phase4_integration.php
jalan v2-fase5-integrasi env V2_PHASE5_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_reg1_test php tests/v2_phase5_integration.php
jalan v2-fase1-integrasi env V2_PHASE1_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_reg2_test php tests/v2_phase1_integration.php
jalan v2-fase2-integrasi env V2_PHASE2_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_reg3_test php tests/v2_phase2_integration.php
jalan v2-fase4-konkurensi env V2_PHASE4_RUN_CONCURRENCY=1 DB_NAME=test_${PFX}_reg4_test php tests/v2_phase4_concurrency.php
jalan v2-fase3-api env V2_PHASE3_RUN_API=1 DB_NAME=test_${PFX}_reg5_test V2_PHASE3_PORT=8761 php tests/v2_phase3_api_contract.php
jalan v2-fase4-api env V2_PHASE4_RUN_API=1 DB_NAME=test_${PFX}_reg5_test php tests/v2_phase4_api_contract.php
jalan v2-fase5-api env V2_PHASE5_RUN_API=1 DB_NAME=test_${PFX}_reg5_test php tests/v2_phase5_api_contract.php
jalan v2-fase2-web env V2_PHASE2_RUN_WEB=1 DB_NAME=test_${PFX}_reg6_test V2_PHASE2_WEB_PORT=8762 php tests/v2_phase2_web_smoke.php
jalan v2-fase4-web env V2_PHASE4_RUN_WEB=1 DB_NAME=test_${PFX}_reg6_test php tests/v2_phase4_web_smoke.php
jalan v2-fase5-web env V2_PHASE5_RUN_WEB=1 DB_NAME=test_${PFX}_reg6_test php tests/v2_phase5_web_smoke.php

echo "== Pembinaan V3 (DB tetap webalhasan_v3_phase1_test, dipulihkan) ==" | tee -a "$RINGKAS"
DUMP="$T/${PFX}-v3-phase1-awal.sql"
if mysqldump --single-transaction --routines --triggers webalhasan_v3_phase1_test > "$DUMP" 2>/dev/null; then
    for s in v3_phase2_integration v3_phase3_integration v3_phase4_integration v3_phase5_integration v3_phase5_access; do
        jalan "$s" env DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1 php "tests/$s.php"
        mysql webalhasan_v3_phase1_test < "$DUMP" || echo "PEMULIHAN V3 GAGAL sesudah $s" | tee -a "$RINGKAS"
    done
else
    echo "PENYIAPAN GAGAL: dump webalhasan_v3_phase1_test" | tee -a "$RINGKAS"
fi

echo "== Redesain web HTTP: navigasi, perizinan/cetak, pembinaan, rapor ==" | tee -a "$RINGKAS"
db bash tests/redesain_web_tahap2_db.sh
jalan redesain-tahap2-http php tests/redesain_web_tahap2_http.php
db bash tests/redesain_web_tahap4_db.sh
jalan redesain-tahap4-http php tests/redesain_web_tahap4_http.php
db bash tests/redesain_web_tahap5_db.sh
jalan redesain-tahap5-http php tests/redesain_web_tahap5_http.php
db bash tests/redesain_web_tahap7_db.sh
jalan redesain-tahap7-http php tests/redesain_web_tahap7_http.php

echo "== Statis (seluruh suite) ==" | tee -a "$RINGKAS"
for f in tests/*_static.php; do jalan "statis-$(basename "$f" .php)" php "$f"; done
echo "SELESAI" | tee -a "$RINGKAS"
