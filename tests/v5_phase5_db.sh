#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk PRD V5 Fase 5 (laporan dan kesiapan rilis).
#
#   bash tests/v5_phase5_db.sh                 # → test_v5_f5_test
#   V5F5_DB=test_v5_f5_http_test bash tests/v5_phase5_db.sh
#
# Membangun DB V5 Fase 3 (berantai Fase 2 → Fase 1 → Tahap 7, seluruhnya
# fixture sintetis) dengan nama tujuan, lalu menambah fixture laporan
# `tests/v5_phase5_seed.php` (kunjungan dibuat lewat layanan). Fase 4 dan 5
# tidak menambah migrasi. DB sandbox asal tidak diubah. Jalankan BERURUTAN:
# rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${V5F5_DB:-test_v5_f5_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

V5F3_DB="$TUJUAN" bash tests/v5_phase3_db.sh >/dev/null
DB_NAME="$TUJUAN" php tests/v5_phase5_seed.php >/dev/null
echo "DB uji V5 Fase 5 siap: $TUJUAN"
