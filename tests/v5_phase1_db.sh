#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk PRD V5 Fase 1 (kesehatan santri).
#
#   bash tests/v5_phase1_db.sh                 # → test_v5_f1_test
#   V5F1_DB=test_v5_f1_http_test bash tests/v5_phase1_db.sh
#
# Membangun salinan Tahap 7 redesain (berantai dari sandbox .env, seluruhnya
# fixture sintetis V1–V4) dengan nama tujuan, memastikan migrasi 024 terpasang
# lewat `bin/migrate.php up`, lalu menambah fixture sintetis
# `tests/v5_phase1_seed.php`. DB sandbox asal tidak diubah. Jalankan ulang
# kapan saja: DB tujuan dibuat ulang dari awal.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${V5F1_DB:-test_v5_f1_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

TAHAP7_DB="$TUJUAN" bash tests/redesain_web_tahap7_db.sh >/dev/null
DB_NAME="$TUJUAN" php bin/migrate.php up >/dev/null
DB_NAME="$TUJUAN" php tests/v5_phase1_seed.php >/dev/null
echo "DB uji V5 Fase 1 siap: $TUJUAN"
