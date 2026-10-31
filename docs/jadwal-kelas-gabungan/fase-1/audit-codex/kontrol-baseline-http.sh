#!/usr/bin/env bash
# Kontrol dua suite historis yang tidak hijau. Jalankan setelah seluruh
# penyiapan fixture kode kini selesai (rantai punya DB perantara tetap).
set -u
OUT="$(cd "$(dirname "$0")" && pwd)/baseline-http"
: "${JKG_BASELINE:?Jalur klon detached baseline 22a3645}"
mkdir -p "$OUT"
cd "$JKG_BASELINE" || exit 2
: > "$OUT/ringkasan.txt"
TAHAP2_DB=test_jkgb_t2_test TAHAP2_DB_KOSONG=test_jkgb_t2_kosong_test bash tests/redesain_web_tahap2_db.sh > "$OUT/penyiapan-tahap2.txt" 2>&1 || exit 2
TAHAP2_DB=test_jkgb_t2_test TAHAP2_DB_KOSONG=test_jkgb_t2_kosong_test php tests/redesain_web_tahap2_http.php > "$OUT/redesain-tahap2-http.txt" 2>&1
printf 'redesain-tahap2-http exit=%s\n' "$?" >> "$OUT/ringkasan.txt"
TAHAP6_DB=test_jkgb_t6_test bash tests/redesain_web_tahap6_db.sh > "$OUT/penyiapan-tahap6.txt" 2>&1 || exit 2
TAHAP6_DB=test_jkgb_t6_test php tests/redesain_web_tahap6_http.php > "$OUT/redesain-tahap6-http.txt" 2>&1
printf 'redesain-tahap6-http exit=%s\n' "$?" >> "$OUT/ringkasan.txt"
