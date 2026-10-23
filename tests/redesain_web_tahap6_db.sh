#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk redesain website Tahap 6 (data pesantren
# & penugasan).
#
#   bash tests/redesain_web_tahap6_db.sh
#
# Membangun salinan Tahap 5 (yang sendiri dibangun berantai dari sandbox
# .env) dengan nama Tahap 6, menjalankan migrasi 023 (kolom asrama kamar dan
# tabel "bukan ganda"), lalu menambah fixture sintetis
# `tests/redesain_web_tahap6_seed.php`. DB sandbox asal tidak diubah.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${TAHAP6_DB:-test_redesain_t6_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

TAHAP5_DB="$TUJUAN" bash tests/redesain_web_tahap5_db.sh >/dev/null
DB_NAME="$TUJUAN" php bin/migrate.php up
DB_NAME="$TUJUAN" php tests/redesain_web_tahap6_seed.php
echo "DB uji Tahap 6 siap: $TUJUAN"
