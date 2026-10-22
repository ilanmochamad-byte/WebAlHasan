#!/usr/bin/env bash
# Menyiapkan DB uji terpisah untuk redesain website Tahap 2.
#
#   bash tests/redesain_web_tahap2_db.sh
#
# Menyalin DB sandbox dari .env (WAJIB berakhiran _test) ke
# `test_redesain_t2_test`, menerapkan migrasi 014–022 yang belum ada di
# sandbox (V3 Fase 2–5, V4 Fase 1–4), lalu memuat fixture sintetis Tahap 2
# (`tests/redesain_web_tahap2_seed.php`). DB sandbox asal tidak diubah.
# Jalankan ulang kapan saja: DB tujuan dibuat ulang dari awal.
set -euo pipefail
cd "$(dirname "$0")/.."

TUJUAN="${TAHAP2_DB:-test_redesain_t2_test}"
case "$TUJUAN" in test_*_test) ;; *) echo "Nama DB tujuan harus test_*_test" >&2; exit 2;; esac

# Peringatan klien MySQL tentang SSL lokal tidak relevan untuk DB uji.
exec 2> >(grep -v "ssl-verify-server-cert" >&2)

baca() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
ASAL="$(baca DB_NAME)"
HOST="$(baca DB_HOST)"; HOST="${HOST:-127.0.0.1}"
PORT="$(baca DB_PORT)"; PORT="${PORT:-3306}"
USER_DB="$(baca DB_USER)"
export MYSQL_PWD="$(baca DB_PASS)$(baca DB_PASSWORD)"
case "$ASAL" in *_test) ;; *) echo "DB sandbox .env harus berakhiran _test" >&2; exit 2;; esac

mysql -h"$HOST" -P"$PORT" -u"$USER_DB" -e "DROP DATABASE IF EXISTS \`$TUJUAN\`; CREATE DATABASE \`$TUJUAN\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysqldump -h"$HOST" -P"$PORT" -u"$USER_DB" --single-transaction --routines --triggers "$ASAL" 2>/dev/null | mysql -h"$HOST" -P"$PORT" -u"$USER_DB" "$TUJUAN"
for berkas in database/migrations/0*.sql; do
  nama="$(basename "$berkas")"
  sudah="$(mysql -h"$HOST" -P"$PORT" -u"$USER_DB" -N -e "SELECT COUNT(*) FROM schema_migrations WHERE migration='$nama'" "$TUJUAN")"
  if [ "$sudah" = "0" ]; then
    mysql -h"$HOST" -P"$PORT" -u"$USER_DB" "$TUJUAN" < "$berkas"
    mysql -h"$HOST" -P"$PORT" -u"$USER_DB" -e "INSERT INTO schema_migrations(migration) VALUES('$nama')" "$TUJUAN"
    echo "migrasi $nama"
  fi
done
DB_NAME="$TUJUAN" php tests/redesain_web_tahap2_seed.php

# Salinan kedua tanpa pekerjaan tertunda, untuk keadaan "Tidak ada yang perlu
# ditindaklanjuti" di Beranda admin (keputusan L).
KOSONG="${TAHAP2_DB_KOSONG:-test_redesain_t2_kosong_test}"
case "$KOSONG" in test_*_test) ;; *) echo "Nama DB kosong harus test_*_test" >&2; exit 2;; esac
mysql -h"$HOST" -P"$PORT" -u"$USER_DB" -e "DROP DATABASE IF EXISTS \`$KOSONG\`; CREATE DATABASE \`$KOSONG\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysqldump -h"$HOST" -P"$PORT" -u"$USER_DB" --single-transaction --routines --triggers "$TUJUAN" 2>/dev/null | mysql -h"$HOST" -P"$PORT" -u"$USER_DB" "$KOSONG"
mysql -h"$HOST" -P"$PORT" -u"$USER_DB" "$KOSONG" <<'SQL'
UPDATE izin_pengajuan SET status = 'Disetujui' WHERE status IN ('Diajukan', 'Perlu Penetapan Admin');
UPDATE psb_pendaftar SET status = 'Diterima' WHERE status = 'Baru';
UPDATE psb_pembayaran SET status_pembayaran = 'Lunas', waktu_lunas = NOW() WHERE status_pembayaran = 'Belum Lunas';
UPDATE notifikasi_outbox SET status = 'Sent' WHERE status = 'Failed';
UPDATE v4_bahan SET status = 'Terverifikasi' WHERE status = 'Dikembalikan';
UPDATE v3_rekomendasi SET status = 'Selesai';
UPDATE santri s SET s.is_active = 0
 WHERE NOT EXISTS (SELECT 1 FROM plotting_kelas pk JOIN tahun_ajaran t ON t.id = pk.id_tahun AND t.status = 'Aktif' WHERE pk.id_santri = s.id AND pk.status = 'Aktif')
    OR NOT EXISTS (SELECT 1 FROM plotting_kamar pm JOIN tahun_ajaran t ON t.id = pm.id_tahun AND t.status = 'Aktif' WHERE pm.id_santri = s.id);
SQL
echo "DB uji Tahap 2 siap: $TUJUAN (kosong: $KOSONG)"
