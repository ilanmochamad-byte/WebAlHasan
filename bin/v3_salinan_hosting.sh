#!/usr/bin/env bash
# Gladi migrasi 013 pada SALINAN STRUKTUR hosting (PRD V3 Fase 1).
#
# Skrip ini membangun database uji terpisah dari dump STRUKTUR hosting, memasang
# migrasi 001-013, lalu menjalankan pre-check dan post-check. Gunanya adalah
# gladi berulang sebelum uji sungguhan di server cPanel.
#
# BATAS PENTING: skrip ini berjalan pada MariaDB/MySQL LOKAL. Ia TIDAK
# membuktikan kompatibilitas versi hosting. Uji yang menentukan tetap harus
# dijalankan pada server cPanel itu sendiri -- lihat
# docs/phase-v3-1/uji-salinan-hosting.md.
#
# PENJAGA KERAS:
#   - nama database uji wajib berakhiran `_test` atau berawalan `test_`;
#   - dump wajib dibersihkan dari seluruh INSERT sebelum diimpor, sehingga tidak
#     ada satu baris data pun yang tersalin;
#   - database yang sedang dikonfigurasi di .env tidak boleh menjadi sasaran.
#
# Pemakaian:
#   bash bin/v3_salinan_hosting.sh <dump-struktur.sql> <nama-db-uji>
# Contoh:
#   bash bin/v3_salinan_hosting.sh ~/Downloads/struktur_hosting.sql test_salinan_hosting

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 2

DUMP="${1:-}"
DB="${2:-}"

if [ -z "$DUMP" ] || [ -z "$DB" ]; then
  echo "Pemakaian: bash bin/v3_salinan_hosting.sh <dump-struktur.sql> <nama-db-uji>" >&2
  exit 2
fi
if [ ! -f "$DUMP" ]; then
  echo "BERHENTI: berkas dump tidak ditemukan: $DUMP" >&2
  exit 2
fi
case "$DB" in
  *_test|test_*) ;;
  *) echo "BERHENTI: nama database uji wajib berakhiran _test atau berawalan test_. Diberikan: $DB" >&2; exit 2 ;;
esac

# Kredensial hanya dibaca dari .env milik proyek; tidak pernah dicetak.
set -a; . "$ROOT/.env"; set +a
if [ "$DB" = "${DB_NAME:-}" ]; then
  echo "BERHENTI: sasaran sama dengan DB_NAME pada .env ($DB). Pakai database terpisah." >&2
  exit 2
fi

MYSQL=(mysql -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}")
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "=== 1. Membuang seluruh INSERT dari dump (struktur saja) ==="
python3 - "$DUMP" "$TMP/struktur.sql" <<'PY'
import sys
src, dst = sys.argv[1], sys.argv[2]
out, skip = [], False
for ln in open(src, encoding='utf-8', errors='replace').read().split('\n'):
    if ln.upper().startswith('INSERT INTO'):
        skip = True
    if skip:
        if ln.rstrip().endswith(';'):
            skip = False
        continue
    out.append(ln)
ddl = '\n'.join(out)
assert 'INSERT INTO' not in ddl.upper(), 'DDL masih memuat data; hentikan.'
open(dst, 'w', encoding='utf-8').write(ddl)
print(f'  struktur bersih: {len(ddl)} byte, 0 pernyataan INSERT')
PY
[ $? -eq 0 ] || { echo "BERHENTI: pembersihan dump gagal." >&2; exit 1; }

echo "=== 2. Membuat ulang database uji $DB ==="
if ! "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" > "$TMP/create.log" 2>&1; then
  grep -v WARNING "$TMP/create.log" >&2
  echo "BERHENTI: tidak dapat membuat $DB. Akun database mungkin tidak punya hak CREATE DATABASE." >&2
  exit 1
fi

echo "=== 3. Mengimpor struktur ==="
if ! "${MYSQL[@]}" -D"$DB" < "$TMP/struktur.sql" > "$TMP/impor.log" 2>&1; then
  grep -v WARNING "$TMP/impor.log" >&2
  echo "BERHENTI: impor struktur gagal." >&2
  exit 1
fi
awal="$("${MYSQL[@]}" -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB';" 2>/dev/null | grep -v WARNING)"
echo "  tabel sesudah impor struktur: $awal"

export DB_NAME="$DB"

echo "=== 4. Pre-check (sebelum migrasi) ==="
echo "  Catatan: pada salinan struktur KOSONG, pemeriksaan kesiapan DATA"
echo "  (admin aktif, penugasan, relasi wali) memang gagal. Yang harus lulus"
echo "  di tahap ini hanyalah keberadaan tabel prasyarat."
php bin/v3_verify.php --pre
echo "  exit pre-check: $?"

echo "=== 5. Migrasi 001-013 ==="
php bin/migrate.php up || { echo "BERHENTI: migrasi gagal. Ini temuan yang harus dilaporkan." >&2; exit 1; }

echo "=== 6. Post-check struktur (SQL murni, sama dengan yang dipakai di cPanel) ==="
"${MYSQL[@]}" -D"$DB" -t < database/checks/013_v3_fase1_postcheck.sql 2>&1 | grep -v WARNING

gagal="$("${MYSQL[@]}" -D"$DB" -N -B < database/checks/013_v3_fase1_postcheck.sql 2>/dev/null | grep -c 'GAGAL')"
echo
echo "=== Ringkasan ==="
echo "  Baris GAGAL pada post-check struktur: ${gagal:-0}"
echo "  Versi server yang dipakai gladi ini:"
"${MYSQL[@]}" -N -B -e "SELECT VERSION();" 2>/dev/null | grep -v WARNING | sed 's/^/    /'
echo
echo "  INI GLADI LOKAL. Kompatibilitas versi hosting BELUM terbukti sampai"
echo "  langkah pada docs/phase-v3-1/uji-salinan-hosting.md dijalankan di cPanel."
[ "${gagal:-0}" -eq 0 ] || exit 1
