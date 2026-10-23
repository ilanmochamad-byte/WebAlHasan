#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk redesain website Tahap 4.
#
#   bash tests/redesain_web_tahap4_db.sh
#
# Membangun salinan Tahap 3 (yang sendiri dibangun dari salinan Tahap 2 di
# atas sandbox .env) dengan nama Tahap 4, lalu menambah fixture sintetis
# Tahap 4 (`tests/redesain_web_tahap4_seed.php`). Salinan kedua
# `..._kosong_test` mengosongkan perizinan, pertemuan, dan kehadiran untuk
# menguji keadaan kosong. DB sandbox asal tidak diubah.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${TAHAP4_DB:-test_redesain_t4_test}"
KOSONG="${TAHAP4_DB_KOSONG:-test_redesain_t4_kosong_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac
case "$KOSONG" in test_*_test) ;; *) echo "Nama DB kosong harus test_*_test" >&2; exit 2;; esac

TAHAP3_DB="$TUJUAN" TAHAP3_DB_KOSONG="test_redesain_t4_t3kosong_test" bash tests/redesain_web_tahap3_db.sh >/dev/null
DB_NAME="$TUJUAN" php tests/redesain_web_tahap4_seed.php

exec 2> >(grep -v "ssl-verify-server-cert" >&2)
baca() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
HOST="$(baca DB_HOST)"; HOST="${HOST:-127.0.0.1}"
PORT="$(baca DB_PORT)"; PORT="${PORT:-3306}"
USER_DB="$(baca DB_USER)"
export MYSQL_PWD="$(baca DB_PASS)$(baca DB_PASSWORD)"
mysql -h"$HOST" -P"$PORT" -u"$USER_DB" -e "DROP DATABASE IF EXISTS \`test_redesain_t4_t3kosong_test\`; DROP DATABASE IF EXISTS \`$KOSONG\`; CREATE DATABASE \`$KOSONG\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysqldump -h"$HOST" -P"$PORT" -u"$USER_DB" --single-transaction --routines --triggers "$TUJUAN" 2>/dev/null | mysql -h"$HOST" -P"$PORT" -u"$USER_DB" "$KOSONG"
mysql -h"$HOST" -P"$PORT" -u"$USER_DB" "$KOSONG" <<'SQL'
SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM izin_keputusan_koreksi;
DELETE FROM izin_idempotency_keys;
DELETE FROM izin_keputusan;
DELETE FROM izin_riwayat_status;
DELETE FROM izin_pengajuan;
DELETE FROM absensi_santri;
DELETE FROM absensi_guru;
DELETE FROM pertemuan_peserta;
DELETE FROM pertemuan_pengajian;
SET FOREIGN_KEY_CHECKS = 1;
SQL
echo "DB uji Tahap 4 siap: $TUJUAN (kosong: $KOSONG)"
