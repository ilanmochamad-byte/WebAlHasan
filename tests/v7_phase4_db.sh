#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk PRD V7 Fase 4 (paritas aplikasi): rantai DB
# V7 Fase 3 (migrasi 001–031 dari pohon kerja ini + fixture V1–V7). Fase 4 tidak
# menambah migrasi maupun fixture; dua wali pada anak yang sama, wali asing,
# satu/nol/dua pembimbing, dan murobi tunggal/ambigu sudah ada di fixture V7.
#
#   bash tests/v7_phase4_db.sh                 # → test_v7_f4_test
#   V7F4_DB=test_v7_f4_http_test bash tests/v7_phase4_db.sh
#
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${V7F4_DB:-test_v7_f4_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

V7F3_DB="$TUJUAN" bash tests/v7_phase3_db.sh >/dev/null
echo "DB uji V7 Fase 4 siap: $TUJUAN"
