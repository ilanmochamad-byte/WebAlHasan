#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk redesain website Tahap 5 (pembinaan).
#
#   bash tests/redesain_web_tahap5_db.sh
#
# Membangun salinan Tahap 4 (yang sendiri dibangun berantai dari sandbox
# .env) dengan nama Tahap 5, lalu menambah fixture sintetis pembinaan
# (`tests/redesain_web_tahap5_seed.php`). DB sandbox asal tidak diubah.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${TAHAP5_DB:-test_redesain_t5_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac
SEMENTARA="test_redesain_t5_t4kosong_test"

TAHAP4_DB="$TUJUAN" TAHAP4_DB_KOSONG="$SEMENTARA" bash tests/redesain_web_tahap4_db.sh >/dev/null
DB_NAME="$TUJUAN" php tests/redesain_web_tahap5_seed.php

baca() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
HOST="$(baca DB_HOST)"; HOST="${HOST:-127.0.0.1}"
PORT="$(baca DB_PORT)"; PORT="${PORT:-3306}"
export MYSQL_PWD="$(baca DB_PASS)$(baca DB_PASSWORD)"
mysql -h"$HOST" -P"$PORT" -u"$(baca DB_USER)" -e "DROP DATABASE IF EXISTS \`$SEMENTARA\`" 2>&1 | grep -v "ssl-verify-server-cert" || true
echo "DB uji Tahap 5 siap: $TUJUAN"
