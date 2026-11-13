#!/usr/bin/env bash
# Uji Expo web redesain tampilan aplikasi gelombang 2 Fase 4 (Kesehatan,
# Pembinaan, Rapor, Keamanan + K-10 menyeluruh, 13 November 2026). BERURUTAN;
# hanya data sintetis `test_*_test` dan 127.0.0.1.
#
# Prasyarat: Expo web dari SALINAN alhasanApps TANPA .env* (node_modules dari
# `npm ci` lock ter-commit) yang API-nya menunjuk http://127.0.0.1:8971/api/v1:
#   EXPO_NO_DOTENV=1 EXPO_OFFLINE=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8971/api/v1 CI=1 npx expo start --web --port <PORT> --clear
#
#   APP=http://127.0.0.1:8987 bash docs/redesain-tampilan-v2/fase-4/perintah-uji.sh <folder-keluaran> [suite…]
#
# Suite: fase4-pembinaan-rapor fase4-kesehatan fase4-keamanan fase4-k10 fase4-bsa
#        jaringan-pembinaan-rapor jaringan-kesehatan jaringan-keamanan
# Kontrol baseline: APP = Expo web dari `git archive origin/main` aplikasi (port
# lain) dan DUMP yang SAMA. Perekam jaringan memulihkan DB dari dump di $DUMP
# (dibuat bila belum ada) sehingga baseline dan fase berangkat dari keadaan sama:
#   DUMP=<folder> APP=<baseline> … <keluar-baseline> jaringan-kesehatan
#   DUMP=<folder> APP=<fase>     … <keluar-fase>     jaringan-kesehatan
#   node tests/browser/uji-redesain-v2-fase4-jaringan.mjs banding <keluar-baseline>/jaringan-kesehatan.json <keluar-fase>/jaringan-kesehatan.json
# Regresi modul lain: docs/redesain-tampilan-v2/fase-2/perintah-regresi.sh dan
# docs/redesain-tampilan-v2/fase-3/perintah-uji.sh (APP sama).
set -u
cd "$(dirname "$0")/../../.."
OUT="${1:?folder keluaran}"; shift
APP="${APP:?APP wajib, mis. http://127.0.0.1:8987}"
WEB=http://127.0.0.1:8971
SUITE="${*:-fase4-pembinaan-rapor fase4-kesehatan fase4-keamanan fase4-k10 fase4-bsa}"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
DUMP="${DUMP:-$OUT/dump}"; mkdir -p "$DUMP"

api() { # api <DB>
  pkill -f "php -S 127.0.0.1:8971 " 2>/dev/null; sleep 1
  DB_NAME="$1" PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8971 tests/redesain_web_tahap1_router.php >> "$OUT/api.log" 2>&1 &
  for i in $(seq 1 20); do curl -s -o /dev/null "$WEB/api/v1/profile" && break; sleep 0.5; done
}
jalan() { # jalan <nama> <perintah…>
  local nama="$1"; shift
  (cd tests/browser && "$@") > "$OUT/$nama.txt" 2>&1
  echo "$nama exit=$? :: $(grep -E '^\[lulus\]' "$OUT/$nama.txt" | wc -l | tr -d ' ') lulus / $(grep -E '^\[gagal\]' "$OUT/$nama.txt" | wc -l | tr -d ' ') gagal :: $(grep -E '^Direkam|^Ringkasan' "$OUT/$nama.txt" | tail -1)"
}
siapkan() { # siapkan <nama> <DB> — buat DB fixture modul
  case "$1" in
    t7) TAHAP7_DB="$2" bash tests/redesain_web_tahap7_db.sh > /dev/null ;;
    v5) V5F3_DB="$2" bash tests/v5_phase3_db.sh > /dev/null ;;
    pk) PKF3_DB="$2" bash tests/penugasan_keamanan_f4_db.sh > /dev/null
        PKF3_DB="$2" php tests/penugasan_keamanan_f3_seed.php > "$DUMP/ids-pk.json" ;;
    v6) V6F4_DB="$2" bash tests/v6_phase4_db.sh > /dev/null ;;
  esac
}
pulih() { # pulih <nama> <DB> — dump dibuat sekali, lalu dipulihkan setiap kali
  local n="$1" d="$2"
  if [ ! -f "$DUMP/$n.sql" ]; then siapkan "$n" "$d"; mysqldump "$d" > "$DUMP/$n.sql"; fi
  mysql -e "DROP DATABASE IF EXISTS $d; CREATE DATABASE $d CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  mysql "$d" < "$DUMP/$n.sql"
}

for s in $SUITE; do case "$s" in
  jaringan-pembinaan-rapor)
    D=test_rtv2f4_t7_test; pulih t7 $D; api $D
    jalan "$s" env DB_NAME=$D RTV2_WEB=$WEB RTV2_APP=$APP node uji-redesain-v2-fase4-jaringan.mjs rekam pembinaan-rapor "$OUT/$s.json" ;;
  jaringan-kesehatan)
    D=test_rtv2f4_v5_test; pulih v5 $D; api $D
    jalan "$s" env DB_NAME=$D RTV2_WEB=$WEB RTV2_APP=$APP node uji-redesain-v2-fase4-jaringan.mjs rekam kesehatan "$OUT/$s.json" ;;
  jaringan-keamanan)
    D=test_rtv2f4_pk_test; pulih pk $D; api $D
    jalan "$s" env DB_NAME=$D RTV2_WEB=$WEB RTV2_APP=$APP SEED_JSON="$DUMP/ids-pk.json" node uji-redesain-v2-fase4-jaringan.mjs rekam keamanan "$OUT/$s.json" ;;
  fase4-pembinaan-rapor|fase4-kesehatan|fase4-keamanan|fase4-k10|fase4-bsa)
    b="${s#fase4-}"
    case "$b" in pembinaan-rapor) n=t7; D=test_rtv2f4_t7_test ;; kesehatan) n=v5; D=test_rtv2f4_v5_test ;; keamanan) n=pk; D=test_rtv2f4_pk_test ;; k10) n=t7; D=test_rtv2f4_t7_test ;; bsa) n=v6; D=test_rtv2f4_v6_test ;; esac
    pulih $n $D; api $D
    jalan "$s" env DB_NAME=$D RTV2_WEB=$WEB RTV2_APP=$APP SEED_JSON="$DUMP/ids-pk.json" OUT_DIR="$OUT/tangkapan-$s" node uji-redesain-v2-fase4.mjs "$b" ;;
  *) echo "suite tidak dikenal: $s" ;;
esac; done
pkill -f "php -S 127.0.0.1:8971 " 2>/dev/null
echo "galat PHP server API: $(grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$OUT/api.log" 2>/dev/null || echo 0)"
