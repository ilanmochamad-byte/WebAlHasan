#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk PRD V7 Fase 1 (pengajuan wali dan routing
# pembimbing): DB V6 Fase 5 (migrasi 001–029, fixture sintetis V1–V6), lalu
# `bin/migrate.php up` memasang 030, lalu fixture `tests/v7_phase1_seed.php`.
#
#   bash tests/v7_phase1_db.sh                 # → test_v7_f1_test
#   V7F1_DB=test_v7_f1_http_test bash tests/v7_phase1_db.sh
#   V7F1_TANPA_030=1 V7F1_DB=... bash tests/v7_phase1_db.sh   # salinan tanpa 030 (uji migrasi)
#
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${V7F1_DB:-test_v7_f1_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

V6F5_DB="$TUJUAN" bash tests/v6_phase5_db.sh >/dev/null
if [ "${V7F1_TANPA_030:-0}" = "1" ]; then
    # Rantai V6 menjalankan `migrate up` dari pohon kerja ini, sehingga 030
    # ikut terpasang; lepas kembali (tabel V7 masih kosong) untuk salinan 029.
    DB_NAME="$TUJUAN" php bin/migrate.php rollback >/dev/null
    echo "DB uji V7 Fase 1 (tanpa 030) siap: $TUJUAN"
    exit 0
fi
DB_NAME="$TUJUAN" php bin/migrate.php up >/dev/null
DB_NAME="$TUJUAN" php tests/v7_phase1_seed.php >/dev/null
echo "DB uji V7 Fase 1 siap: $TUJUAN"
