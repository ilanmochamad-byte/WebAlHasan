#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk PRD V6 Fase 1 (fondasi BSA).
#
#   bash tests/v6_phase1_db.sh                 # → test_v6_f1_test
#   V6F1_DB=test_v6_f1_http_test bash tests/v6_phase1_db.sh
#
# Membangun DB V5 Fase 5 (berantai Fase 3 → 2 → 1 → Tahap 7, seluruhnya
# fixture sintetis V1–V5) dengan nama tujuan — rantai itu menjalankan
# `bin/migrate.php up`, sehingga migrasi 027 ikut terpasang — lalu menambah
# fixture sintetis `tests/v6_phase1_seed.php`. DB sandbox asal tidak diubah.
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${V6F1_DB:-test_v6_f1_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

V5F5_DB="$TUJUAN" bash tests/v5_phase5_db.sh >/dev/null
DB_NAME="$TUJUAN" php bin/migrate.php up >/dev/null
DB_NAME="$TUJUAN" php tests/v6_phase1_seed.php >/dev/null
echo "DB uji V6 Fase 1 siap: $TUJUAN"
