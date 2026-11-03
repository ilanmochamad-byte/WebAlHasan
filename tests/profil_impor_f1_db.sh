#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk Fase 1 profil pengguna dan impor
# guru/pengurus (migrasi 035). Rantai sama dengan kelas gabungan Fase 1
# (fixture sintetis V1–V7 + `bin/migrate.php up` dari pohon kerja ini), sehingga
# 032–035 terpasang. Seluruh data sintetis; tidak ada data pribadi nyata.
#
#   bash tests/profil_impor_f1_db.sh                                   # → test_pif1_test
#   PIF1_DB=test_pif1_http_test bash tests/profil_impor_f1_db.sh
#   PIF1_TANPA_035=1 PIF1_DB=test_pif1_mig_test bash tests/profil_impor_f1_db.sh   # salinan 034 (uji migrasi)
#
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${PIF1_DB:-test_pif1_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

JKG_DB="$TUJUAN" bash tests/jadwal_kelas_gabungan_f1_db.sh >/dev/null
DB_NAME="$TUJUAN" php bin/migrate.php up >/dev/null
if [ "${PIF1_TANPA_035:-0}" = "1" ]; then
    TERAKHIR=$(DB_NAME="$TUJUAN" php -r 'require "app/bootstrap.php"; echo app_db()->query("SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1")->fetch_row()[0];')
    case "$TERAKHIR" in 035_profil_impor_*) DB_NAME="$TUJUAN" php bin/migrate.php rollback >/dev/null ;; esac
    echo "DB uji profil (tanpa 035) siap: $TUJUAN"
    exit 0
fi
echo "DB uji profil Fase 1 siap: $TUJUAN"
