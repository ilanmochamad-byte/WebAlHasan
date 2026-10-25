#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk PRD V6 Fase 3 (tabungan lengkap dan
# pembayaran silang).
#
#   bash tests/v6_phase3_db.sh                 # → test_v6_f3_test
#   V6F3_DB=test_v6_f3_http_test bash tests/v6_phase3_db.sh
#
# Membangun DB V6 Fase 2 (berantai V6 Fase 1 → V5 Fase 5 → … → Tahap 7,
# seluruhnya fixture sintetis V1–V5 + fixture V6 Fase 1) dengan nama tujuan.
# Rantai itu menjalankan `bin/migrate.php up`, sehingga migrasi 027, 028, dan
# 029 terpasang. Fase 3 tidak memerlukan fixture tambahan: setoran, tagihan,
# dan pembayaran dibuat oleh uji melalui layanan. DB sandbox asal tidak diubah.
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${V6F3_DB:-test_v6_f3_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

V6F2_DB="$TUJUAN" bash tests/v6_phase2_db.sh >/dev/null
DB_NAME="$TUJUAN" php bin/migrate.php up >/dev/null
n=$(DB_NAME="$TUJUAN" php -r 'require "app/bootstrap.php"; echo (int) app_db()->query("SELECT COUNT(*) FROM schema_migrations WHERE migration = \"029_v6_fase3_tabungan.sql\"")->fetch_row()[0];')
[ "$n" = "1" ] || { echo "Migrasi 029 tidak terpasang pada $TUJUAN" >&2; exit 1; }
echo "DB uji V6 Fase 3 siap: $TUJUAN"
