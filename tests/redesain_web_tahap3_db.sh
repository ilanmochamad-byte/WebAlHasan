#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk redesain website Tahap 3.
#
#   bash tests/redesain_web_tahap3_db.sh
#
# Membangun DB salinan Tahap 2 (sandbox .env + migrasi + fixture Tahap 2)
# dengan nama Tahap 3, lalu menambah fixture sintetis Tahap 3
# (`tests/redesain_web_tahap3_seed.php`). Salinan kedua `..._kosong_test`
# mengosongkan PSB, konten website, arsip pelanggaran, dan pengiriman gagal
# untuk menguji keadaan kosong. DB sandbox asal tidak diubah.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${TAHAP3_DB:-test_redesain_t3_test}"
KOSONG="${TAHAP3_DB_KOSONG:-test_redesain_t3_kosong_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac
case "$KOSONG" in test_*_test) ;; *) echo "Nama DB kosong harus test_*_test" >&2; exit 2;; esac

TAHAP2_DB="$TUJUAN" TAHAP2_DB_KOSONG="test_redesain_t3_t2kosong_test" bash tests/redesain_web_tahap2_db.sh >/dev/null
DB_NAME="$TUJUAN" php tests/redesain_web_tahap3_seed.php

exec 2> >(grep -v "ssl-verify-server-cert" >&2)
baca() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
HOST="$(baca DB_HOST)"; HOST="${HOST:-127.0.0.1}"
PORT="$(baca DB_PORT)"; PORT="${PORT:-3306}"
USER_DB="$(baca DB_USER)"
export MYSQL_PWD="$(baca DB_PASS)$(baca DB_PASSWORD)"
mysql -h"$HOST" -P"$PORT" -u"$USER_DB" -e "DROP DATABASE IF EXISTS \`test_redesain_t3_t2kosong_test\`; DROP DATABASE IF EXISTS \`$KOSONG\`; CREATE DATABASE \`$KOSONG\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysqldump -h"$HOST" -P"$PORT" -u"$USER_DB" --single-transaction --routines --triggers "$TUJUAN" 2>/dev/null | mysql -h"$HOST" -P"$PORT" -u"$USER_DB" "$KOSONG"
mysql -h"$HOST" -P"$PORT" -u"$USER_DB" "$KOSONG" <<'SQL'
DELETE FROM psb_pembayaran;
DELETE FROM psb_pendaftar;
DELETE FROM berita;
DELETE FROM galeri;
DELETE FROM download;
DELETE FROM pelanggaran;
DELETE FROM notifikasi_outbox WHERE status = 'Failed';
DELETE FROM notifikasi_pengaturan_audit;
SQL
echo "DB uji Tahap 3 siap: $TUJUAN (kosong: $KOSONG)"
