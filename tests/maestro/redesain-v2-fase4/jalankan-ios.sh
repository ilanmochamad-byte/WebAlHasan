#!/usr/bin/env bash
# Alur Maestro Fase 4 pada iOS SIMULATOR — build Release baru 1.8.0 terpasang,
# API lokal 127.0.0.1:8971 dengan DB uji sintetis. Satu bagian per DB:
#   DB_NAME=<DB Tahap 7>   UDID=<sim> bash jalankan-ios.sh <keluaran> t7
#   DB_NAME=<DB V5>        UDID=<sim> bash jalankan-ios.sh <keluaran> v5
#   DB_NAME=<DB Keamanan>  UDID=<sim> SEED_JSON=<ids-pk.json> bash jalankan-ios.sh <keluaran> pk
set -u
DIR="$(cd "$(dirname "$0")" && pwd)"
OUT="${1:?folder keluaran}"; B="${2:?bagian}"; UDID="${UDID:?UDID simulator}"; DB="${DB_NAME:?DB_NAME}"
mkdir -p "$OUT" && cd "$OUT"
q() { mysql -N -B "$DB" -e "$1"; }
M() { maestro --device "$UDID" test -e SANDI='Sandbox#123' "$@" > "$OUT/$NAMA_LOG.txt" 2>&1; echo "$NAMA_LOG exit=$?"; }
atur() { xcrun simctl ui "$UDID" appearance "$1"; xcrun simctl ui "$UDID" content_size "$2"; }
TAMPIL=("terang light large" "gelap dark large" "gelap-ax-xxxl dark accessibility-extra-extra-extra-large")
atur light large
case "$B" in
  t7)
    KASUS=$(q "SELECT id FROM v3_konseling_kasus WHERE kerahasiaan='Internal' ORDER BY id LIMIT 1")
    RAPOR=$(q "SELECT r.id FROM v4_rapor r JOIN santri s ON s.id=r.santri_id WHERE s.nama_santri='SBX T7 Santri 01' LIMIT 1")
    NAMA_LOG=pembimbing M -e AKUN=sbx_pengurus_a -e KASUS="$KASUS" "$DIR/pembimbing.yaml"
    for t in "${TAMPIL[@]}"; do set -- $t; atur "$2" "$3"; NAMA_LOG="wali-rapor-$1" M -e AKUN=sbx_t7_ortu -e RAPOR="$RAPOR" -e NAMA="$1" "$DIR/wali-rapor.yaml"; done ;;
  v5)
    KUNJUNGAN=$(q "SELECT id FROM kesehatan_kunjungan ORDER BY id DESC LIMIT 1")
    for t in "${TAMPIL[@]}"; do set -- $t; atur "$2" "$3"; NAMA_LOG="kesehatan-$1" M -e AKUN=sbx_v5_petugas_f -e KUNJUNGAN="$KUNJUNGAN" -e NAMA="$1" "$DIR/petugas-kesehatan.yaml"; done ;;
  pk)
    IZIN=$(python3 -c "import json,sys;print(json.load(open(sys.argv[1]))['izin_wali'])" "${SEED_JSON:?SEED_JSON}")
    for t in "${TAMPIL[@]}"; do set -- $t; atur "$2" "$3"; NAMA_LOG="keamanan-$1" M -e AKUN=sbx_pengurus_biasa -e IZIN="$IZIN" -e NAMA="$1" "$DIR/keamanan.yaml"; done ;;
esac
atur light large
