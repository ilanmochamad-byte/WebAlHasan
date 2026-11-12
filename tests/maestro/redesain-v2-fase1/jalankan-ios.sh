#!/usr/bin/env bash
# Menjalankan seluruh alur Maestro Fase 1 pada iOS SIMULATOR (build Release
# yang sudah terpasang; API lokal 127.0.0.1:8971 dengan DB uji sintetis).
#   UDID=<simulator> bash jalankan-ios.sh <folder-keluaran>
set -u
DIR="$(cd "$(dirname "$0")" && pwd)"
OUT="${1:?folder keluaran}"; UDID="${UDID:?UDID simulator}"
mkdir -p "$OUT/tangkapan" && cd "$OUT"
touch "$OUT/.mulai"
M() { maestro --device "$UDID" test "$@" > "$OUT/$NAMA_LOG.txt" 2>&1; echo "$NAMA_LOG exit=$?"; }
xcrun simctl ui "$UDID" appearance light; xcrun simctl ui "$UDID" content_size large
[ "${HANYA_TAMPILAN:-0}" = 1 ] || {
for A in sbx_guru_biasa sbx_pengurus_biasa sbx_ortu_a sbx_v6_bsa sbx_v5_petugas sbx_pengurus_b sbx_admin; do
  NAMA_LOG="peran-$A" M -e AKUN=$A -e SANDI='Sandbox#123' "$DIR/peran.yaml"
done
NAMA_LOG=kembali M -e AKUN=sbx_pengurus_biasa -e SANDI='Sandbox#123' "$DIR/kembali.yaml"
NAMA_LOG=wajib M "$DIR/wajib.yaml"
}
tampil() { # tampil <nama> <appearance> <content_size>
  xcrun simctl ui "$UDID" appearance "$2"; xcrun simctl ui "$UDID" content_size "$3"
  NAMA_LOG="tampilan-$1" M -e AKUN=sbx_admin -e SANDI='Sandbox#123' -e NAMA="$1" "$DIR/tampilan.yaml"
}
tampil gelap dark large
tampil ax-xl light accessibility-extra-large
tampil ax-xxxl light accessibility-extra-extra-extra-large
tampil gelap-ax-xxxl dark accessibility-extra-extra-extra-large
xcrun simctl ui "$UDID" appearance light; xcrun simctl ui "$UDID" content_size large
# Maestro menyimpan tangkapan di ~/.maestro/tests/<waktu>/…; kumpulkan yang dibuat run ini.
find "$HOME/.maestro/tests" -newer "$OUT/.mulai" -name 'ios-*.png' -exec cp {} "$OUT/tangkapan/" \;
echo "tangkapan: $(ls "$OUT/tangkapan" | wc -l)"
