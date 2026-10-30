#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk Fase 3 penugasan lintas guru/pengurus
# (penugasan Keamanan, hanya baca). Rantai yang sama dengan Fase 1/2 (fixture
# sintetis V1–V7 + `bin/migrate.php up` dari pohon kerja ini), sehingga migrasi
# 032 dan 033 terpasang. Skenario Keamanan (penugasan, izin, pembinaan
# Internal/Rahasia, santri nonaktif) dibentuk `tests/penugasan_keamanan_f3_seed.php`
# lewat layanan resmi di atas DB ini.
#
#   bash tests/penugasan_keamanan_f3_db.sh                      # → test_pk_f3_test
#   PKF3_DB=test_pk_f3_http_test bash tests/penugasan_keamanan_f3_db.sh
#
# Jalankan BERURUTAN: rantai fixture memakai DB perantara bernama tetap.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${PKF3_DB:-test_pk_f3_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

PKF1_DB="$TUJUAN" bash tests/penugasan_keamanan_f1_db.sh >/dev/null
echo "DB uji penugasan Fase 3 siap: $TUJUAN"
