#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk PRD V6 Fase 2 (tagihan bulanan lengkap).
#
#   bash tests/v6_phase2_db.sh                 # → test_v6_f2_test
#   V6F2_DB=test_v6_f2_http_test bash tests/v6_phase2_db.sh
#
# Membangun DB V6 Fase 1 (berantai V5 Fase 5 → … → Tahap 7, seluruhnya
# fixture sintetis V1–V5 + fixture V6 Fase 1) dengan nama tujuan. Rantai itu
# menjalankan `bin/migrate.php up`, sehingga migrasi 027 dan 028 terpasang.
# Fase 2 tidak memerlukan fixture tambahan: tarif, penyesuaian, tagihan, dan
# pembayaran dibuat oleh uji melalui layanan. DB sandbox asal tidak diubah.
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${V6F2_DB:-test_v6_f2_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

V6F1_DB="$TUJUAN" bash tests/v6_phase1_db.sh >/dev/null
DB_NAME="$TUJUAN" php bin/migrate.php up >/dev/null
n=$(DB_NAME="$TUJUAN" php -r 'require "app/bootstrap.php"; echo (int) app_db()->query("SELECT COUNT(*) FROM schema_migrations WHERE migration = \"028_v6_fase2_tagihan_bulanan.sql\"")->fetch_row()[0];')
[ "$n" = "1" ] || { echo "Migrasi 028 tidak terpasang pada $TUJUAN" >&2; exit 1; }
echo "DB uji V6 Fase 2 siap: $TUJUAN"
