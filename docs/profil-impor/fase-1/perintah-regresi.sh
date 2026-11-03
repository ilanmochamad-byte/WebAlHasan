#!/usr/bin/env bash
# Regresi Fase 1 profil pengguna dan impor guru/pengurus: akun & pesan
# kredensial (reset password lama), identitas pengurus milik guru (penugasan
# Fase 1), REST profil/identitas (penugasan Fase 4), master data V1 dan API
# absensi V1, perapihan wali/akun, kelas gabungan Fase 1, HTTP redesain Tahap
# 2/3/6 (navigasi, ganti kata sandi, halaman lama, data pesantren), dan seluruh
# suite statis — pada DB salinan `test_*_test` berfixture sintetis.
#
#   bash docs/profil-impor/fase-1/perintah-regresi.sh
#   KODE=/jalur/klon-baseline OUT=/jalur/keluaran PFX=pifk bash docs/profil-impor/fase-1/perintah-regresi.sh
#
# KODE  pohon kode yang diuji (bawaan: repo ini). Untuk kontrol baseline,
#       arahkan ke klon `origin/main` yang berisi .env dan vendor/.
# OUT   folder keluaran (bawaan: docs/profil-impor/fase-1/bukti/regresi).
# PFX   awalan nama DB uji agar kontrol tidak menimpa DB uji kode kini.
#
# Jalankan BERURUTAN; tidak bersamaan dengan skrip lain yang membangun DB.
set -u
SKRIP_DIR="$(cd "$(dirname "$0")" && pwd)"
KODE="${KODE:-$(cd "$SKRIP_DIR/../../.." && pwd)}"
OUT="${OUT:-$SKRIP_DIR/bukti/regresi}"
PFX="${PFX:-pif}"
cd "$KODE" || exit 2
mkdir -p "$OUT"
RINGKAS="$OUT/ringkasan.txt"
: > "$RINGKAS"

hitung() { local f="$1" l g; l=$(grep -cE '^ *\[(lulus|OK|ok)\]|^ *✓|^PASS|^ok ' "$f" 2>/dev/null); g=$(grep -cE '^ *\[(gagal|GAGAL|FAIL)\]|^ *✗|^FAIL|^not ok ' "$f" 2>/dev/null); echo "${l:-0}/${g:-0}"; }
jalan() { local nama="$1"; shift; "$@" > "$OUT/$nama.txt" 2>&1; local kode=$?; echo "$nama exit=$kode $(hitung "$OUT/$nama.txt")" | tee -a "$RINGKAS"; }
db() { "$@" > /dev/null 2>&1 || echo "PENYIAPAN GAGAL: $*" | tee -a "$RINGKAS"; }
siapkan() { db env JKG_DB="$1" bash tests/jadwal_kelas_gabungan_f1_db.sh; }

echo "== Akun dan pesan kredensial (reset password, ganti password) ==" | tee -a "$RINGKAS"
siapkan test_${PFX}_kred_test
jalan kredensial-integrasi env KREDENSIAL_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_kred_test php tests/kredensial_integration.php
siapkan test_${PFX}_kredw_test
jalan kredensial-web env KREDENSIAL_RUN_WEB=1 DB_NAME=test_${PFX}_kredw_test php tests/kredensial_web_smoke.php

echo "== Identitas pengurus milik guru (penugasan Fase 1) dan REST profil (Fase 4) ==" | tee -a "$RINGKAS"
db env PKF1_DB=test_${PFX}_pk1_test bash tests/penugasan_keamanan_f1_db.sh
jalan pk-fase1-integrasi env PKF1_DB=test_${PFX}_pk1_test php tests/penugasan_keamanan_f1_integration.php
db env PKF1_DB=test_${PFX}_pk1h_test bash tests/penugasan_keamanan_f1_db.sh
jalan pk-fase1-http env PKF1_DB=test_${PFX}_pk1h_test php tests/penugasan_keamanan_f1_http.php
db env PKF3_DB=test_${PFX}_pk4_test bash tests/penugasan_keamanan_f4_db.sh
jalan pk-fase4-http env PKF3_DB=test_${PFX}_pk4_test php tests/penugasan_keamanan_f4_http.php

echo "== V1 master data, API absensi, perapihan wali/akun ==" | tee -a "$RINGKAS"
siapkan test_${PFX}_v1a_test
jalan v1-fase2-integrasi env PHASE2_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_v1a_test php tests/phase2_integration.php
siapkan test_${PFX}_v1b_test
jalan v1-fase4-integrasi env PHASE4_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_v1b_test php tests/phase4_integration.php
siapkan test_${PFX}_v1c_test
jalan perapihan-integrasi env PERAPIHAN_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_v1c_test php tests/perapihan_integration.php

echo "== Kelas gabungan Fase 1 (baseline terbaru) ==" | tee -a "$RINGKAS"
siapkan test_${PFX}_jkg_test
jalan jkg-integrasi env JKG_DB=test_${PFX}_jkg_test php tests/jadwal_kelas_gabungan_f1_integration.php
siapkan test_${PFX}_jkgh_test
jalan jkg-http env JKG_DB=test_${PFX}_jkgh_test php tests/jadwal_kelas_gabungan_f1_http.php

echo "== Redesain web HTTP: Tahap 2 (navigasi, ganti kata sandi), 3 (halaman lama), 6 (data pesantren) ==" | tee -a "$RINGKAS"
db bash tests/redesain_web_tahap2_db.sh
jalan redesain-tahap2-http php tests/redesain_web_tahap2_http.php
db bash tests/redesain_web_tahap3_db.sh
jalan redesain-tahap3-http php tests/redesain_web_tahap3_http.php
db bash tests/redesain_web_tahap6_db.sh
jalan redesain-tahap6-http php tests/redesain_web_tahap6_http.php

echo "== Statis (seluruh suite) ==" | tee -a "$RINGKAS"
for f in tests/*_static.php; do jalan "statis-$(basename "$f" .php)" php "$f"; done
echo "SELESAI" | tee -a "$RINGKAS"
