#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk PRD V6 Fase 4 (paritas aplikasi dan
# orang tua): DB V6 Fase 3 (migrasi 027–029, fixture sintetis V1–V5 + V6
# Fase 1) ditambah fixture Fase 4 (wali dengan dua anak). Tanpa migrasi baru.
#
#   bash tests/v6_phase4_db.sh                 # → test_v6_f4_test
#   V6F4_DB=test_v6_f4_http_test bash tests/v6_phase4_db.sh
#
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${V6F4_DB:-test_v6_f4_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

V6F3_DB="$TUJUAN" bash tests/v6_phase3_db.sh >/dev/null
DB_NAME="$TUJUAN" php tests/v6_phase4_seed.php >/dev/null
echo "DB uji V6 Fase 4 siap: $TUJUAN"
