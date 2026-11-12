#!/usr/bin/env bash
# Uji Expo web redesain tampilan aplikasi gelombang 2 Fase 3 (Keuangan/BSA,
# 12 November 2026): suite keuangan V6 Fase 4/5, terbit tagihan satu santri
# (+ probe audit), probe F-1 dan V-1, perekam jaringan. BERURUTAN; hanya data
# sintetis `test_*_test` dan 127.0.0.1.
#
# Prasyarat: Expo web dari SALINAN alhasanApps TANPA .env* (node_modules dari
# `npm ci` lock ter-commit) yang API-nya menunjuk http://127.0.0.1:8971/api/v1:
#   EXPO_NO_DOTENV=1 EXPO_OFFLINE=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8971/api/v1 CI=1 npx expo start --web --port <PORT> --clear
#
#   APP=http://127.0.0.1:8962 bash docs/redesain-tampilan-v2/fase-3/perintah-uji.sh <folder-keluaran> [suite…]
#
# Suite: v6f4 v6f5 ss ss-audit probe-f1 probe-v1 jaringan
# Kontrol baseline: jalankan lagi dengan APP = Expo web dari `git archive origin/main`
# aplikasi (port lain). Perekam jaringan memulihkan DB dari dump di $DUMP
# (bawaan <folder-keluaran>/dump; dibuat bila belum ada) sehingga baseline dan
# fase berangkat dari keadaan yang persis sama:
#   DUMP=<folder> APP=<baseline> … <keluar-baseline> jaringan
#   DUMP=<folder> APP=<fase>     … <keluar-fase>     jaringan
#   node tests/browser/uji-redesain-v2-fase3-jaringan.mjs banding <keluar-baseline>/jaringan.json <keluar-fase>/jaringan.json
set -u
cd "$(dirname "$0")/../../.."
OUT="${1:?folder keluaran}"; shift
APP="${APP:?APP wajib, mis. http://127.0.0.1:8962}"
WEB=http://127.0.0.1:8971
SUITE="${*:-v6f4 v6f5 ss ss-audit probe-f1 probe-v1}"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
DUMP="${DUMP:-$OUT/dump}"; mkdir -p "$DUMP"
q() { mysql -N -B "$1" -e "$2"; }

api() { # api <DB>
  pkill -f "php -S 127.0.0.1:8971 " 2>/dev/null; sleep 1
  DB_NAME="$1" PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8971 tests/redesain_web_tahap1_router.php >> "$OUT/api.log" 2>&1 &
  for i in $(seq 1 20); do curl -s -o /dev/null "$WEB/api/v1/profile" && break; sleep 0.5; done
}
jalan() { # jalan <nama> <perintah…>
  local nama="$1"; shift
  (cd tests/browser && "$@") > "$OUT/$nama.txt" 2>&1
  echo "$nama exit=$? :: $(grep -E '^\[lulus\]' "$OUT/$nama.txt" | wc -l | tr -d ' ') lulus / $(grep -E '^\[gagal\]' "$OUT/$nama.txt" | wc -l | tr -d ' ') gagal :: $(grep -E 'Ringkasan' "$OUT/$nama.txt" | tail -1)"
}
f5fixture() { # f5fixture <DB> — fixture laporan Fase 5 (worker resmi)
  local d="$1"; touch "$OUT/aba"
  DB_NAME="$d" php tests/v6_phase5_worker.php "$OUT/aba" "$(q "$d" "SELECT id FROM users WHERE username='sbx_v6_bsa_b'")" fixture \
    "$(q "$d" "SELECT id FROM santri WHERE nis='V6S0001'")" "$(q "$d" "SELECT id FROM santri WHERE nis='V6S0002'")" "$(q "$d" "SELECT id FROM santri WHERE nis='V6S0004'")" > /dev/null
}
smplain() { # santri SMP aktif berkelas selain V6S0001
  q "$1" "SELECT s.id FROM santri s JOIN plotting_kelas pk ON pk.id_santri = s.id AND pk.status = 'Aktif' JOIN kelas kl ON kl.id = pk.id_kelas WHERE kl.jenjang = 'SMP' AND s.nis <> 'V6S0001' AND s.archived_at IS NULL ORDER BY s.id LIMIT 1"
}
ssdb() { # ssdb <DB>
  V6F5_DB="$1" bash tests/v6_phase5_db.sh > /dev/null && DB_NAME="$1" php tests/v6_tagihan_satu_santri_seed.php > /dev/null
}

for s in $SUITE; do case "$s" in
  v6f4)
    D=test_rtv2f3_v6f4_test; V6F4_DB=$D bash tests/v6_phase4_db.sh > /dev/null; api $D
    jalan v6f4 env DB_NAME=$D V6_WEB=$WEB V6_APP=$APP OUT_DIR="$OUT/tangkapan-v6f4" node uji-v6-fase4-app.mjs ;;
  v6f5)
    D=test_rtv2f3_v6f5_test; V6F5_DB=$D bash tests/v6_phase5_db.sh > /dev/null && f5fixture $D; api $D
    jalan v6f5 env DB_NAME=$D V6_WEB=$WEB V6_APP=$APP OUT_DIR="$OUT/tangkapan-v6f5" node uji-v6-fase5-app.mjs ;;
  ss)
    D=test_rtv2f3_ss_test; ssdb $D; api $D
    jalan ss env DB_NAME=$D SMP_LAIN="$(smplain $D)" V6_WEB=$WEB V6_APP=$APP OUT_DIR="$OUT/tangkapan-ss" node uji-tagihan-satu-santri-app.mjs ;;
  ss-audit)
    D=test_rtv2f3_ssa_test; ssdb $D; api $D
    jalan ss-audit env DB_NAME=$D SMP_LAIN="$(smplain $D)" V6_WEB=$WEB V6_APP=$APP OUT_DIR="$OUT/tangkapan-ss-audit" node uji-tagihan-satu-santri-app-audit.mjs ;;
  probe-f1)
    D=test_rtv2f3_f1_test; V6F4_DB=$D bash tests/v6_phase4_db.sh > /dev/null; api $D
    jalan probe-f1 env DB_NAME=$D V6_WEB=$WEB V6_APP=$APP OUT_DIR="$OUT/tangkapan-f1" node "$PWD/docs/phase-v6-4/audit-codex/uji-mutasi-akses.mjs" ;;
  probe-v1)
    D=test_rtv2f3_v1_test; V6F4_DB=$D bash tests/v6_phase4_db.sh > /dev/null; api $D
    jalan probe-v1 env DB_NAME=$D V6_WEB=$WEB V6_APP=$APP node "$PWD/docs/phase-v6-4/validasi-claude/uji-pesan-basi.mjs" ;;
  fase3)
    D=test_rtv2f3_tampil_test; V6F5_DB=$D bash tests/v6_phase5_db.sh > /dev/null && f5fixture $D && DB_NAME=$D php tests/v6_tagihan_satu_santri_seed.php > /dev/null; api $D
    jalan fase3 env DB_NAME=$D RTV2_WEB=$WEB RTV2_APP=$APP OUT_DIR="$OUT/tangkapan-fase3" node uji-redesain-v2-fase3.mjs ;;
  jaringan)
    D=test_rtv2f3_net_test
    if [ ! -f "$DUMP/net.sql" ]; then ssdb $D && mysqldump $D > "$DUMP/net.sql"; fi
    mysql -e "DROP DATABASE IF EXISTS $D; CREATE DATABASE $D CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" && mysql $D < "$DUMP/net.sql"
    api $D
    jalan jaringan env DB_NAME=$D RTV2_WEB=$WEB RTV2_APP=$APP node uji-redesain-v2-fase3-jaringan.mjs rekam "$OUT/jaringan.json" ;;
  *) echo "suite tidak dikenal: $s" ;;
esac; done
pkill -f "php -S 127.0.0.1:8971 " 2>/dev/null
echo "galat PHP server API: $(grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$OUT/api.log" 2>/dev/null)"
