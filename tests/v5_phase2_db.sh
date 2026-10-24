#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk PRD V5 Fase 2 (obat, rujukan, koreksi,
# pembaca internal).
#
#   bash tests/v5_phase2_db.sh                 # → test_v5_f2_test
#   V5F2_DB=test_v5_f2_http_test bash tests/v5_phase2_db.sh
#
# Membangun DB V5 Fase 1 (berantai Tahap 7, fixture sintetis V1–V4 + V5 Fase 1)
# dengan nama tujuan; `bin/migrate.php up` di dalamnya juga memasang 025.
# Lalu menambah fixture sintetis `tests/v5_phase2_seed.php`. DB sandbox asal
# tidak diubah. Jalankan ulang kapan saja: DB tujuan dibuat ulang dari awal.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${V5F2_DB:-test_v5_f2_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

V5F1_DB="$TUJUAN" bash tests/v5_phase1_db.sh >/dev/null
DB_NAME="$TUJUAN" php bin/migrate.php up >/dev/null
DB_NAME="$TUJUAN" php tests/v5_phase2_seed.php >/dev/null
echo "DB uji V5 Fase 2 siap: $TUJUAN"
