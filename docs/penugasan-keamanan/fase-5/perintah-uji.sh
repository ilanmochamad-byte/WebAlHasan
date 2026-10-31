#!/usr/bin/env bash
# Uji Fase 5 penugasan lintas guru/pengurus (kesiapan rilis) pada DB salinan
# `test_*_test` berfixture sintetis. Jalankan dari akar repo web, BERURUTAN
# (tidak bersamaan dengan skrip lain yang membangun DB). Keluaran di
# docs/penugasan-keamanan/fase-5/bukti/.
#
#   bash docs/penugasan-keamanan/fase-5/perintah-uji.sh
set -u
cd "$(dirname "$0")/../../.."
B=docs/penugasan-keamanan/fase-5/bukti
mkdir -p "$B"
verifikasi() { DB_NAME="$1" php bin/penugasan_keamanan_verify.php > "$B/verifier-$2.txt" 2>&1; echo "verifier-$2 exit=$? $(grep -o 'Ringkasan verifier penugasan Keamanan: .*' "$B/verifier-$2.txt")"; }

php tests/penugasan_keamanan_f5_static.php > "$B/statis.txt" 2>&1; echo "statis exit=$? $(grep '^Hasil' "$B/statis.txt")"

PK_RUN_RILIS=1 php tests/penugasan_keamanan_f5_rilis.php > "$B/rilis.txt" 2>&1; echo "rilis exit=$? $(grep '^Hasil' "$B/rilis.txt")"

PKF5_DB=test_pk_f5_e2e_test bash tests/penugasan_keamanan_f5_db.sh > /dev/null
PKF5_DB=test_pk_f5_e2e_test php tests/penugasan_keamanan_f5_e2e.php > "$B/e2e.txt" 2>&1; echo "e2e exit=$? $(grep '^Hasil' "$B/e2e.txt")"
verifikasi test_pk_f5_e2e_test e2e
# Verifier rilis V5/V6/V7 yang mungkin dijadwalkan berkala tetap lulus sesudah
# 032–033 dan pemakaian Keamanan (tanpa manifest; hanya membaca).
for v in v5 v6 v7; do
    DB_NAME=test_pk_f5_e2e_test php "bin/${v}_verify.php" > "$B/verifier-lama-$v.txt" 2>&1
    echo "verifier-lama-$v exit=$? gagal=$(grep -cE '^ *\[gagal\]' "$B/verifier-lama-$v.txt")"
done
