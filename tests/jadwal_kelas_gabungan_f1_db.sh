#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk Fase 1 perbaikan jadwal kelas gabungan
# (migrasi 034). Rantai yang sama dengan penugasan Fase 3 (fixture sintetis
# V1–V7 + `bin/migrate.php up` dari pohon kerja ini), sehingga 032, 033, dan
# 034 terpasang. Skenario kelas gabungan dibentuk `tests/jadwal_kelas_gabungan_f1_seed.php`
# lewat layanan resmi di atas DB ini.
#
#   bash tests/jadwal_kelas_gabungan_f1_db.sh                         # → test_jkg_f1_test
#   JKG_DB=test_jkg_f1_http_test bash tests/jadwal_kelas_gabungan_f1_db.sh
#   JKG_TANPA_034=1 JKG_DB=test_jkg_f1_mig_test bash tests/jadwal_kelas_gabungan_f1_db.sh  # salinan 033 (uji migrasi)
#
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${JKG_DB:-test_jkg_f1_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

PKF3_DB="$TUJUAN" bash tests/penugasan_keamanan_f3_db.sh >/dev/null
DB_NAME="$TUJUAN" php bin/migrate.php up >/dev/null
if [ "${JKG_TANPA_034:-0}" = "1" ]; then
    TERAKHIR=$(DB_NAME="$TUJUAN" php -r 'require "app/bootstrap.php"; echo app_db()->query("SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1")->fetch_row()[0];')
    case "$TERAKHIR" in 034_jadwal_kelas_*) DB_NAME="$TUJUAN" php bin/migrate.php rollback >/dev/null ;; esac
    echo "DB uji jadwal kelas gabungan (tanpa 034) siap: $TUJUAN"
    exit 0
fi
echo "DB uji jadwal kelas gabungan Fase 1 siap: $TUJUAN"
