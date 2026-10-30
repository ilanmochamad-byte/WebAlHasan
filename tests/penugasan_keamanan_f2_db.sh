#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk Fase 2 penugasan lintas guru/pengurus
# (pemisahan tugas per akun). Fase 2 tanpa migrasi: rantai yang sama dengan
# Fase 1 (fixture sintetis V1–V7 + migrasi 001–032). Skenario pemisahan tugas
# dibentuk oleh masing-masing uji lewat layanan resmi di atas DB ini.
#
#   bash tests/penugasan_keamanan_f2_db.sh                      # → test_pk_f2_test
#   PKF2_DB=test_pk_f2_http_test bash tests/penugasan_keamanan_f2_db.sh
#
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${PKF2_DB:-test_pk_f2_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

PKF1_DB="$TUJUAN" bash tests/penugasan_keamanan_f1_db.sh >/dev/null
echo "DB uji penugasan Fase 2 siap: $TUJUAN"
