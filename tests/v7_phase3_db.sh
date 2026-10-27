#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk PRD V7 Fase 3 (notifikasi, laporan, audit):
# rantai DB V7 Fase 2 (migrasi 001–031 dari pohon kerja ini + fixture V1–V7
# Fase 2). Fase 3 tidak menambah migrasi maupun fixture; laporan dan
# notifikasi diuji di atas data sintetis yang sama.
#
#   bash tests/v7_phase3_db.sh                 # → test_v7_f3_test
#   V7F3_DB=test_v7_f3_http_test bash tests/v7_phase3_db.sh
#
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${V7F3_DB:-test_v7_f3_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

V7F2_DB="$TUJUAN" bash tests/v7_phase2_db.sh >/dev/null
echo "DB uji V7 Fase 3 siap: $TUJUAN"
