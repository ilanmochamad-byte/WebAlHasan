#!/usr/bin/env bash
# Alur Maestro Fase 3 (Keuangan) pada iOS SIMULATOR — build Release terpasang,
# API lokal 127.0.0.1:8971 dengan DB uji sintetis (suite `fase3` perintah-uji.sh).
#   DB_NAME=test_*_test UDID=<simulator> bash jalankan-ios.sh <folder-keluaran>
set -u
DIR="$(cd "$(dirname "$0")" && pwd)"
OUT="${1:?folder keluaran}"; UDID="${UDID:?UDID simulator}"; DB="${DB_NAME:?DB_NAME}"
mkdir -p "$OUT/tangkapan" && cd "$OUT"
touch "$OUT/.mulai"
q() { mysql -N -B "$DB" -e "$1"; }
S='Sandbox#123'
SANTRI=$(q "SELECT id FROM santri WHERE nis='V6S0001'")
TAGIHAN=$(q "SELECT id FROM bsa_tagihan WHERE santri_id=$SANTRI AND status='Terbit' ORDER BY id DESC LIMIT 1")
PEMBAYARAN=$(q "SELECT id FROM bsa_pembayaran WHERE status='Menunggu' ORDER BY id DESC LIMIT 1")
echo "santri=$SANTRI tagihan=$TAGIHAN pembayaran_menunggu=$PEMBAYARAN"
E=(-e SANDI="$S" -e SANTRI="$SANTRI" -e TAGIHAN="$TAGIHAN" -e PEMBAYARAN="$PEMBAYARAN")
M() { maestro --device "$UDID" test "${E[@]}" "$@" > "$OUT/$NAMA_LOG.txt" 2>&1; echo "$NAMA_LOG exit=$?"; }
atur() { xcrun simctl ui "$UDID" appearance "$1"; xcrun simctl ui "$UDID" content_size "$2"; }
atur light large
SEBELUM=$(q "SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE santri_id=$SANTRI")
NAMA_LOG=petugas M -e AKUN=sbx_v6_bsa_b "$DIR/petugas.yaml"
SESUDAH=$(q "SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE santri_id=$SANTRI")
echo "ketukan ganda setoran: transaksi $SEBELUM → $SESUDAH (harus +1)" | tee "$OUT/ketukan-ganda.txt"
NAMA_LOG=dingin M "$DIR/dingin.yaml"
NAMA_LOG=peran M "$DIR/peran.yaml"
for t in "gelap dark large" "ax-xl light accessibility-extra-large" "ax-xxxl light accessibility-extra-extra-extra-large" "gelap-ax-xxxl dark accessibility-extra-extra-extra-large"; do
  set -- $t; atur "$2" "$3"; NAMA_LOG="tampilan-$1" M -e AKUN=sbx_v6_bsa_b -e NAMA="$1" "$DIR/tampilan.yaml"
done
atur light large
find "$HOME/.maestro/tests" . -newer "$OUT/.mulai" -name 'ios-f3-*.png' -not -path "./tangkapan/*" -exec cp {} "$OUT/tangkapan/" \; 2>/dev/null
echo "tangkapan: $(ls "$OUT/tangkapan" | wc -l)"
