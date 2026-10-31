#!/usr/bin/env bash
# Uji Fase 1 perbaikan jadwal pengajian kelas gabungan (migrasi 034) pada DB
# salinan `test_*_test` berfixture sintetis. Keluaran ke bukti/uji/.
#
#   bash docs/jadwal-kelas-gabungan/fase-1/perintah-uji.sh
#
# Jalankan BERURUTAN; tidak bersamaan dengan skrip lain yang membangun DB.
set -u
SKRIP_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SKRIP_DIR/../../.." || exit 2
OUT="${OUT:-$SKRIP_DIR/bukti/uji}"
mkdir -p "$OUT"
RINGKAS="$OUT/ringkasan.txt"
: > "$RINGKAS"
hitung() { local l g; l=$(grep -c '^\[lulus\]' "$1"); g=$(grep -c '^\[gagal\]' "$1"); echo "${l:-0}/${g:-0}"; }
jalan() { local nama="$1"; shift; "$@" > "$OUT/$nama.txt" 2>&1; local kode=$?; echo "$nama exit=$kode $(hitung "$OUT/$nama.txt")" | tee -a "$RINGKAS"; }

jalan statis php tests/jadwal_kelas_gabungan_f1_static.php
JKG_DB=test_jkg_f1_test bash tests/jadwal_kelas_gabungan_f1_db.sh >/dev/null
jalan integrasi env JKG_DB=test_jkg_f1_test php tests/jadwal_kelas_gabungan_f1_integration.php
JKG_TANPA_034=1 JKG_DB=test_jkg_f1_mig_test bash tests/jadwal_kelas_gabungan_f1_db.sh >/dev/null
jalan migrasi env JKG_DB=test_jkg_f1_mig_test php tests/jadwal_kelas_gabungan_f1_migration.php
JKG_DB=test_jkg_f1_http_test bash tests/jadwal_kelas_gabungan_f1_db.sh >/dev/null
jalan http env JKG_DB=test_jkg_f1_http_test php tests/jadwal_kelas_gabungan_f1_http.php
echo SELESAI | tee -a "$RINGKAS"
