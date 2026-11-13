#!/usr/bin/env bash
# Probe tindak lanjut audit redesain gelombang 2 (13 November 2026), BERURUTAN.
# Hanya data sintetis `test_*_test` dan 127.0.0.1.
#
# Prasyarat: Expo web dari SALINAN alhasanApps TANPA .env* yang API-nya
# http://127.0.0.1:8971/api/v1 (lihat ../fase-4/perintah-uji.sh), di port mana pun.
#
#   APP=http://127.0.0.1:<port> bash docs/redesain-tampilan-v2/tindak-lanjut/perintah-probe.sh <keluaran> [bagian…]
#
# Kontrol baseline: jalankan sekali dengan APP = Expo web dari `git archive origin/main`
# aplikasi (1.8.0) dan sekali dengan kode akhir; butir yang diperbaiki harus gagal
# di baseline dan lulus di akhir.
set -u
cd "$(dirname "$0")/../../.."
OUT="${1:?folder keluaran}"; shift
APP="${APP:?APP wajib}"
WEB=http://127.0.0.1:8971
BAGIAN="${*:-akun izin keuangan pembinaan kesehatan}"
mkdir -p "$OUT"; OUT="$(cd "$OUT" && pwd)"
api() {
  pkill -f "php -S 127.0.0.1:8971 " 2>/dev/null; sleep 1
  DB_NAME="$1" PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8971 tests/redesain_web_tahap1_router.php >> "$OUT/api.log" 2>&1 &
  for i in $(seq 1 20); do curl -s -o /dev/null "$WEB/api/v1/profile" && break; sleep 0.5; done
}
for b in $BAGIAN; do
  case "$b" in
    akun) D=test_tl_app_test; PIF1_DB=$D bash tests/profil_impor_f1_db.sh > /dev/null; PIF2_DB=$D php tests/profil_impor_f2_app_seed.php > /dev/null ;;
    izin) D=test_tl_v7_test; V7F4_DB=$D bash tests/v7_phase4_db.sh > /dev/null ;;
    keuangan) D=test_tl_v6_test; V6F5_DB=$D bash tests/v6_phase5_db.sh > /dev/null && DB_NAME=$D php tests/v6_tagihan_satu_santri_seed.php > /dev/null ;;
    pembinaan) D=test_tl_t7_test; TAHAP7_DB=$D bash tests/redesain_web_tahap7_db.sh > /dev/null ;;
    kesehatan) D=test_tl_v5_test; V5F3_DB=$D bash tests/v5_phase3_db.sh > /dev/null ;;
    *) echo "bagian tidak dikenal: $b"; continue ;;
  esac
  api $D
  (cd tests/browser && env RTV2_WEB=$WEB RTV2_APP=$APP DB_NAME=$D node uji-tindak-lanjut-redesain-v2.mjs "$b") > "$OUT/$b.txt" 2>&1
  echo "$b exit=$? :: $(grep -E '^Ringkasan' "$OUT/$b.txt" | tail -1)"
done
pkill -f "php -S 127.0.0.1:8971 " 2>/dev/null
echo "galat PHP server API: $(grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$OUT/api.log" 2>/dev/null || echo 0)"
