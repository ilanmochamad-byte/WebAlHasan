#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk redesain website Tahap 7 (penilaian &
# rapor).
#
#   bash tests/redesain_web_tahap7_db.sh
#
# Membangun salinan Tahap 6 (yang sendiri dibangun berantai dari sandbox
# .env) dengan nama Tahap 7, lalu menambah fixture sintetis
# `tests/redesain_web_tahap7_seed.php` (kelas 30 santri, semester lalu,
# penanda tangan beraset, nilai/sikap/verifikasi/rapor lewat layanan V4).
# Tahap 7 tidak menambah migrasi. DB sandbox asal tidak diubah.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${TAHAP7_DB:-test_redesain_t7_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

TAHAP6_DB="$TUJUAN" bash tests/redesain_web_tahap6_db.sh >/dev/null
DB_NAME="$TUJUAN" php tests/redesain_web_tahap7_seed.php
echo "DB uji Tahap 7 siap: $TUJUAN"
