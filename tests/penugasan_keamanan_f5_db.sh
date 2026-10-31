#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk Fase 5 penugasan lintas guru/pengurus
# (kesiapan rilis). Rantai yang sama dengan Fase 3/4 (fixture sintetis V1–V7 +
# `bin/migrate.php up`, sehingga 032 dan 033 terpasang); Fase 5 tanpa migrasi
# dan tanpa fixture baru. Skenario Keamanan dibentuk
# `tests/penugasan_keamanan_f3_seed.php` di atas DB ini.
#
#   PKF5_DB=test_pk_f5_e2e_test bash tests/penugasan_keamanan_f5_db.sh
#   PKF5_TANPA_PK=1 PKF5_DB=... bash tests/penugasan_keamanan_f5_db.sh   # salinan 031 berisi data V1–V7, tanpa 032–033 (titik awal rilis)
#
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${PKF5_DB:-test_pk_f5_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

PKF3_DB="$TUJUAN" bash tests/penugasan_keamanan_f3_db.sh >/dev/null
if [ "${PKF5_TANPA_PK:-0}" = "1" ]; then
    # Rantai menjalankan `migrate up` dari pohon kerja ini sehingga 032 dan 033
    # ikut terpasang (keduanya masih kosong); lepas berurutan 033 → 032.
    for _ in 1 2; do
        TERAKHIR=$(DB_NAME="$TUJUAN" php -r 'require "app/bootstrap.php"; echo app_db()->query("SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1")->fetch_row()[0];')
        case "$TERAKHIR" in 03[23]_penugasan_keamanan_*) DB_NAME="$TUJUAN" php bin/migrate.php rollback >/dev/null ;; esac
    done
    echo "DB uji penugasan Fase 5 (tanpa 032–033) siap: $TUJUAN"
    exit 0
fi
echo "DB uji penugasan Fase 5 siap: $TUJUAN"
