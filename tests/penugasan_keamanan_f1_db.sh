#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk Fase 1 penugasan lintas guru/pengurus
# (identitas pengurus milik guru): rantai DB V7 Fase 5 (migrasi 001–031 dan
# fixture sintetis V1–V7). Rantai itu menjalankan `bin/migrate.php up` dari
# pohon kerja ini sehingga 032 ikut terpasang. Fase 1 tidak menambah fixture:
# guru berakun (sbx_guru_biasa), guru tanpa akun (P5 Murobi 0x), akun ganda
# warisan (sbx_v5_ganda → pengurus biasa), dan pengurus biasa sudah ada.
#
#   bash tests/penugasan_keamanan_f1_db.sh                      # → test_pk_f1_test
#   PKF1_DB=test_pk_f1_http_test bash tests/penugasan_keamanan_f1_db.sh
#   PKF1_TANPA_032=1 PKF1_DB=... bash tests/penugasan_keamanan_f1_db.sh   # salinan 031 (uji migrasi)
#
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${PKF1_DB:-test_pk_f1_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

V7F5_DB="$TUJUAN" bash tests/v7_phase5_db.sh >/dev/null
DB_NAME="$TUJUAN" php bin/migrate.php up >/dev/null
if [ "${PKF1_TANPA_032:-0}" = "1" ]; then
    TERAKHIR=$(DB_NAME="$TUJUAN" php -r 'require "app/bootstrap.php"; echo app_db()->query("SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1")->fetch_row()[0];')
    case "$TERAKHIR" in 032_penugasan_*) DB_NAME="$TUJUAN" php bin/migrate.php rollback >/dev/null ;; esac
    echo "DB uji penugasan Fase 1 (tanpa 032) siap: $TUJUAN"
    exit 0
fi
echo "DB uji penugasan Fase 1 siap: $TUJUAN"
