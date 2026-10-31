#!/usr/bin/env bash
# Regresi Fase 1 jadwal kelas gabungan: pengajian dan absensi V1 (jadwal,
# pertemuan, API absensi, laporan), perapihan, rekap rapor V4 (lewat HTTP
# redesain Tahap 7), Beranda/kehadiran/cetak redesain Tahap 2/4/6, kehadiran
# Keamanan (penugasan Fase 3/4), dan seluruh suite statis, pada DB salinan
# `test_*_test` berfixture sintetis.
#
#   bash docs/jadwal-kelas-gabungan/fase-1/perintah-regresi.sh
#   KODE=/jalur/klon-baseline OUT=/jalur/keluaran PFX=jkgk bash docs/jadwal-kelas-gabungan/fase-1/perintah-regresi.sh
#
# KODE  pohon kode yang diuji (bawaan: repo ini). Untuk kontrol baseline,
#       arahkan ke klon `22a3645` yang berisi .env dan vendor/.
# OUT   folder keluaran (bawaan: docs/jadwal-kelas-gabungan/fase-1/bukti/regresi).
# PFX   awalan nama DB uji agar kontrol tidak menimpa DB uji kode kini.
#
# Jalankan BERURUTAN; tidak bersamaan dengan skrip lain yang membangun DB.
set -u
SKRIP_DIR="$(cd "$(dirname "$0")" && pwd)"
KODE="${KODE:-$(cd "$SKRIP_DIR/../../.." && pwd)}"
OUT="${OUT:-$SKRIP_DIR/bukti/regresi}"
PFX="${PFX:-jkg}"
cd "$KODE" || exit 2
mkdir -p "$OUT"
RINGKAS="$OUT/ringkasan.txt"
: > "$RINGKAS"

hitung() { local f="$1" l g; l=$(grep -cE '^ *\[(lulus|OK|ok)\]|^ *✓|^PASS|^ok ' "$f" 2>/dev/null); g=$(grep -cE '^ *\[(gagal|GAGAL|FAIL)\]|^ *✗|^FAIL|^not ok ' "$f" 2>/dev/null); echo "${l:-0}/${g:-0}"; }
jalan() { local nama="$1"; shift; "$@" > "$OUT/$nama.txt" 2>&1; local kode=$?; echo "$nama exit=$kode $(hitung "$OUT/$nama.txt")" | tee -a "$RINGKAS"; }
db() { "$@" > /dev/null 2>&1 || echo "PENYIAPAN GAGAL: $*" | tee -a "$RINGKAS"; }

echo "== V1 pengajian/absensi: jadwal & pertemuan (Fase 3), API absensi (Fase 4), laporan (Fase 5) ==" | tee -a "$RINGKAS"
db env PKF3_DB=test_${PFX}_v1a_test bash tests/penugasan_keamanan_f3_db.sh
jalan v1-fase3-integrasi env PHASE3_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_v1a_test php tests/phase3_integration.php
jalan v1-fase4-integrasi env PHASE4_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_v1a_test php tests/phase4_integration.php
db env PKF3_DB=test_${PFX}_v1b_test bash tests/penugasan_keamanan_f3_db.sh
jalan v1-fase5-integrasi env PHASE5_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_v1b_test php tests/phase5_integration.php
db env PKF3_DB=test_${PFX}_v1c_test bash tests/penugasan_keamanan_f3_db.sh
jalan perapihan-integrasi env PERAPIHAN_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_v1c_test php tests/perapihan_integration.php

echo "== Kehadiran Keamanan (penugasan Fase 3 web, Fase 4 REST) ==" | tee -a "$RINGKAS"
db env PKF3_DB=test_${PFX}_pk3_test bash tests/penugasan_keamanan_f3_db.sh
jalan pk-fase3-integrasi env PKF3_DB=test_${PFX}_pk3_test php tests/penugasan_keamanan_f3_integration.php
db env PKF3_DB=test_${PFX}_pk3h_test bash tests/penugasan_keamanan_f3_db.sh
jalan pk-fase3-http env PKF3_DB=test_${PFX}_pk3h_test php tests/penugasan_keamanan_f3_http.php
db env PKF3_DB=test_${PFX}_pk3a_test bash tests/penugasan_keamanan_f3_db.sh
jalan pk-fase3-probe-audit env PKF3_DB=test_${PFX}_pk3a_test php tests/penugasan_keamanan_f3_audit_probe.php
db env PKF3_DB=test_${PFX}_pk4_test bash tests/penugasan_keamanan_f4_db.sh
jalan pk-fase4-http env PKF3_DB=test_${PFX}_pk4_test php tests/penugasan_keamanan_f4_http.php

echo "== Redesain web HTTP: Beranda/navigasi, kehadiran & cetak, data pesantren, rapor V4 ==" | tee -a "$RINGKAS"
db bash tests/redesain_web_tahap2_db.sh
jalan redesain-tahap2-http php tests/redesain_web_tahap2_http.php
db bash tests/redesain_web_tahap4_db.sh
jalan redesain-tahap4-http php tests/redesain_web_tahap4_http.php
db bash tests/redesain_web_tahap6_db.sh
jalan redesain-tahap6-http php tests/redesain_web_tahap6_http.php
db bash tests/redesain_web_tahap7_db.sh
jalan redesain-tahap7-http php tests/redesain_web_tahap7_http.php

echo "== Uji baru Fase 1 (kontrol: harus gagal pada kode lama) ==" | tee -a "$RINGKAS"
db env PKF3_DB=test_${PFX}_baru_test bash tests/penugasan_keamanan_f3_db.sh
jalan jkg-integrasi env JKG_DB=test_${PFX}_baru_test php tests/jadwal_kelas_gabungan_f1_integration.php
db env PKF3_DB=test_${PFX}_baruh_test bash tests/penugasan_keamanan_f3_db.sh
jalan jkg-http env JKG_DB=test_${PFX}_baruh_test php tests/jadwal_kelas_gabungan_f1_http.php

echo "== Statis (seluruh suite) ==" | tee -a "$RINGKAS"
for f in tests/*_static.php; do jalan "statis-$(basename "$f" .php)" php "$f"; done
echo "SELESAI" | tee -a "$RINGKAS"
