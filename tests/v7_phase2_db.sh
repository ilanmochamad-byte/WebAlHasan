#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk PRD V7 Fase 2 (tinjauan pembimbing,
# perbaikan, pembatalan, dan keputusan akhir): DB V7 Fase 1 (migrasi 001–031
# dari pohon kerja ini + fixture V1–V7 Fase 1), lalu fixture
# `tests/v7_phase2_seed.php`.
#
#   bash tests/v7_phase2_db.sh                 # → test_v7_f2_test
#   V7F2_DB=test_v7_f2_http_test bash tests/v7_phase2_db.sh
#   V7F2_TANPA_031=1 V7F2_DB=... bash tests/v7_phase2_db.sh   # salinan 030 + fixture tanpa 031 (uji migrasi)
#
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${V7F2_DB:-test_v7_f2_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

V7F1_DB="$TUJUAN" bash tests/v7_phase1_db.sh >/dev/null
DB_NAME="$TUJUAN" php tests/v7_phase2_seed.php >/dev/null
if [ "${V7F2_TANPA_031:-0}" = "1" ]; then
    # Rantai Fase 1 menjalankan `migrate up` dari pohon kerja ini, sehingga
    # 031 ikut terpasang; lepas kembali (tabel Fase 2 masih kosong).
    DB_NAME="$TUJUAN" php bin/migrate.php rollback >/dev/null
    echo "DB uji V7 Fase 2 (tanpa 031) siap: $TUJUAN"
    exit 0
fi
echo "DB uji V7 Fase 2 siap: $TUJUAN"
