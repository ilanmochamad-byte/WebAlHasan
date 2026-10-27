#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk PRD V7 Fase 5 (regresi dan kesiapan rilis):
# rantai DB V7 Fase 4 (migrasi 001–031 dari pohon kerja ini + fixture V1–V7).
# Fase 5 tidak menambah migrasi maupun fixture; satu/nol/dua pembimbing,
# satu/nol/dua murobi, dua wali, wali asing, dan relasi dicabut sudah ada.
#
#   bash tests/v7_phase5_db.sh                 # → test_v7_f5_test
#   V7F5_DB=test_v7_f5_http_test bash tests/v7_phase5_db.sh
#   V7F5_TANPA_V7=1 V7F5_DB=... bash tests/v7_phase5_db.sh   # salinan V6 (029) tanpa 030–031 dan tanpa fixture V7 (uji rilis)
#
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${V7F5_DB:-test_v7_f5_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

if [ "${V7F5_TANPA_V7:-0}" = "1" ]; then
    # Salinan V6 (029) tanpa 030–031: titik awal rilis V7 yang sesungguhnya.
    # Rantai V6 menjalankan `migrate up` dari pohon kerja ini sehingga 030 dan
    # 031 ikut terpasang (tabel V7 masih kosong); lepas keduanya berurutan.
    # (`V7F1_TANPA_030` hanya melepas satu migrasi, yaitu 031, sejak 031 ada.)
    V6F5_DB="$TUJUAN" bash tests/v6_phase5_db.sh >/dev/null
    for _ in 1 2; do
        TERAKHIR=$(DB_NAME="$TUJUAN" php -r 'require "app/bootstrap.php"; echo app_db()->query("SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1")->fetch_row()[0];')
        case "$TERAKHIR" in 03[01]_v7_*) DB_NAME="$TUJUAN" php bin/migrate.php rollback >/dev/null ;; esac
    done
    echo "DB uji V7 Fase 5 (tanpa 030–031) siap: $TUJUAN"
    exit 0
fi
V7F4_DB="$TUJUAN" bash tests/v7_phase4_db.sh >/dev/null
echo "DB uji V7 Fase 5 siap: $TUJUAN"
