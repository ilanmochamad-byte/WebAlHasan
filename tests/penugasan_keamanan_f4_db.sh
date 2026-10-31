#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk Fase 4 penugasan lintas guru/pengurus (REST
# baca-saja Keamanan dan identitas akun untuk aplikasi). Rantai yang sama
# dengan Fase 3 (fixture sintetis V1–V7 + `bin/migrate.php up`, sehingga 032
# dan 033 terpasang); Fase 4 tanpa migrasi. Skenario Keamanan dibentuk
# `tests/penugasan_keamanan_f3_seed.php` di atas DB ini.
#
#   PKF3_DB=test_pk_f4_http_test bash tests/penugasan_keamanan_f4_db.sh
#
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${PKF3_DB:-test_pk_f4_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

PKF3_DB="$TUJUAN" bash tests/penugasan_keamanan_f3_db.sh >/dev/null
echo "DB uji penugasan Fase 4 siap: $TUJUAN"
