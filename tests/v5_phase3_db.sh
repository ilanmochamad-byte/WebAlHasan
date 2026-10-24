#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk PRD V5 Fase 3 (ringkasan wali dan
# notifikasi).
#
#   bash tests/v5_phase3_db.sh                 # → test_v5_f3_test
#   V5F3_DB=test_v5_f3_http_test bash tests/v5_phase3_db.sh
#
# Membangun DB V5 Fase 2 (berantai Fase 1 → Tahap 7, seluruhnya fixture
# sintetis) dengan nama tujuan; `bin/migrate.php up` di dalamnya juga memasang
# 026. Lalu menambah fixture sintetis `tests/v5_phase3_seed.php`. DB sandbox
# asal tidak diubah. Jalankan ulang kapan saja: DB tujuan dibuat ulang.
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${V5F3_DB:-test_v5_f3_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

V5F2_DB="$TUJUAN" bash tests/v5_phase2_db.sh >/dev/null
DB_NAME="$TUJUAN" php bin/migrate.php up >/dev/null
DB_NAME="$TUJUAN" php tests/v5_phase3_seed.php >/dev/null
echo "DB uji V5 Fase 3 siap: $TUJUAN"
