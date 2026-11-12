#!/usr/bin/env bash
# Menjalankan alur Maestro Fase 2 pada iOS SIMULATOR (build Release yang sudah
# terpasang; API lokal 127.0.0.1:8971 dengan DB uji sintetis).
#   UDID=<simulator> bash jalankan-ios.sh <folder-keluaran> izin              # DB V7 (tests/v7_phase4_db.sh)
#   UDID=<simulator> MAPEL='Nahwu <sfx>' bash jalankan-ios.sh <folder-keluaran> pengajian   # DB kelas gabungan + seed
set -u
DIR="$(cd "$(dirname "$0")" && pwd)"
OUT="${1:?folder keluaran}"; BAGIAN="${2:?izin|pengajian}"; UDID="${UDID:?UDID simulator}"
mkdir -p "$OUT/tangkapan" && cd "$OUT"
touch "$OUT/.mulai-$BAGIAN"
S='Sandbox#123'
M() { maestro --device "$UDID" test "$@" > "$OUT/$NAMA_LOG.txt" 2>&1; echo "$NAMA_LOG exit=$?"; }
atur() { xcrun simctl ui "$UDID" appearance "$1"; xcrun simctl ui "$UDID" content_size "$2"; }
atur light large
if [ "$BAGIAN" = izin ]; then
  [ "${HANYA_TAMPILAN:-0}" = 1 ] || {
  NAMA_LOG=perizinan M -e AKUN=sbx_pengurus_a -e SANDI="$S" "$DIR/perizinan.yaml"
  # Geometri FAB terhadap bilah tab sistem dari hierarki aksesibilitas.
  NAMA_LOG=fab-pengurus M -e AKUN=sbx_pengurus_a -e SANDI="$S" -e HARAP=ada -e NAMA=fab-pengurus "$DIR/fab-diam.yaml"
  maestro --device "$UDID" hierarchy > "$OUT/hierarki-perizinan.json" 2>/dev/null
  node "$DIR/ukur-fab.mjs" "$OUT/hierarki-perizinan.json" | tee "$OUT/ukur-fab.txt"
  for P in "sbx_admin ada" "sbx_v7_pembimbing_satu ada" "sbx_murobi_a tidak" "sbx_ortu_a tidak"; do
    set -- $P
    NAMA_LOG="peran-$1" M -e AKUN="$1" -e SANDI="$S" -e HARAP="$2" -e NAMA="$1" "$DIR/fab-diam.yaml"
  done
  NAMA_LOG=wali M -e AKUN=sbx_v7_wali_a -e SANDI="$S" "$DIR/wali.yaml"
  # Geometri FAB wali tidak diukur di iOS: layar izin wali menutup isinya saat
  # aplikasi tidak aktif dan tangkapannya hitam (perlindungan tidak diubah);
  # tampil dan dapat diketuknya FAB dibuktikan alur wali.yaml.
  NAMA_LOG=notifikasi M -e AKUN=sbx_murobi_a -e SANDI="$S" "$DIR/notifikasi.yaml"
  }
  tampil() { atur "$2" "$3"; NAMA_LOG="tampilan-izin-$1" M -e AKUN=sbx_pengurus_a -e SANDI="$S" -e NAMA="$1" "$DIR/tampilan-izin.yaml"; }
else
  [ "${HANYA_TAMPILAN:-0}" = 1 ] || \
  NAMA_LOG=pengajian M -e AKUN=sbx_guru_biasa -e SANDI="$S" -e MAPEL="${MAPEL:?MAPEL}" "$DIR/pengajian.yaml"
  tampil() { atur "$2" "$3"; NAMA_LOG="tampilan-pengajian-$1" M -e AKUN=sbx_guru_biasa -e SANDI="$S" -e MAPEL="$MAPEL" -e NAMA="$1" "$DIR/tampilan-pengajian.yaml"; }
fi
[ "${HANYA_TAMPILAN:-0}" = 1 ] && rm -f "$OUT"/tampilan-*.txt
[ "${TANPA_TAMPILAN:-0}" = 1 ] || {
tampil gelap dark large
tampil ax-xl light accessibility-extra-large
tampil ax-xxxl light accessibility-extra-extra-extra-large
tampil gelap-ax-xxxl dark accessibility-extra-extra-extra-large
}
atur light large
# Maestro menyimpan tangkapan di ~/.maestro/tests/<waktu>/…; kumpulkan yang dibuat run ini.
find "$HOME/.maestro/tests" . -newer "$OUT/.mulai-$BAGIAN" -name 'ios-*.png' -not -path "./tangkapan/*" -exec cp {} "$OUT/tangkapan/" \; 2>/dev/null
echo "tangkapan: $(ls "$OUT/tangkapan" | wc -l)"
