#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk PRD V6 Fase 5 (laporan, audit, dan
# kesiapan rilis bersama): DB V6 Fase 4 (migrasi 027–029, fixture sintetis
# V1–V5 + V6 Fase 1 dan wali dua anak Fase 4). Fase 5 tidak menambah migrasi
# atau fixture: transaksi laporan dibuat uji melalui layanan.
#
#   bash tests/v6_phase5_db.sh                 # → test_v6_f5_test
#   V6F5_DB=test_v6_f5_http_test bash tests/v6_phase5_db.sh
#
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${V6F5_DB:-test_v6_f5_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

V6F4_DB="$TUJUAN" bash tests/v6_phase4_db.sh >/dev/null
echo "DB uji V6 Fase 5 siap: $TUJUAN"
