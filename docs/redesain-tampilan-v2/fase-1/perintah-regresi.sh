#!/usr/bin/env bash
# Regresi Expo web redesain tampilan aplikasi gelombang 2 Fase 1 (12 November 2026).
# Jalankan dari akar repo web, BERURUTAN. Hanya data sintetis dan 127.0.0.1.
#
# Prasyarat: Expo web dari SALINAN alhasanApps TANPA .env* (node_modules nyata,
# paket = HEAD) yang API-nya menunjuk http://127.0.0.1:8971/api/v1:
#   EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8971/api/v1 CI=1 npx expo start --web --port <PORT> --clear
#
#   APP=http://127.0.0.1:8973 bash docs/redesain-tampilan-v2/fase-1/perintah-regresi.sh <folder-keluaran> [suite…]
#
# Kontrol baseline: jalankan lagi dengan APP = Expo web dari `git archive origin/main`
# aplikasi (port lain). Suite memakai DB berbeda, tetapi satu port API (8971),
# karena alamat API tertanam di bundel Expo.
set -u
cd "$(dirname "$0")/../../.."
OUT="${1:?folder keluaran}"; shift
APP="${APP:?APP wajib, mis. http://127.0.0.1:8973}"
WEB=http://127.0.0.1:8971
SUITE="${*:-fase1 jaringan profil keamanan gabungan cari-absensi cari-daftar peserta v6f4 v7f4 v5f4 v5f5}"
mkdir -p "$OUT"

api() { # api <DB>
  pkill -f "php -S 127.0.0.1:8971 " 2>/dev/null; sleep 1
  DB_NAME="$1" PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8971 tests/redesain_web_tahap1_router.php >> "$OUT/api.log" 2>&1 &
  for i in $(seq 1 20); do curl -s -o /dev/null "$WEB/api/v1/profile" && break; sleep 0.5; done
}
jalan() { # jalan <nama> <perintah…>
  local nama="$1"; shift
  (cd tests/browser && "$@") > "$OUT/$nama.txt" 2>&1
  echo "$nama exit=$? :: $(grep -E '^Hasil|lulus /|LULUS|GAGAL' "$OUT/$nama.txt" | tail -1)"
}

for s in $SUITE; do case "$s" in
  fase1|jaringan|profil)
    D=test_rtv2_app_test
    PIF1_DB=$D bash tests/profil_impor_f1_db.sh > /dev/null
    PIF2_DB=$D php tests/profil_impor_f2_app_seed.php > "$OUT/ids-profil.json"
    [ "$s" = profil ] || RTV2_DB=$D php tests/redesain_v2_fase1_seed.php > /dev/null
    api $D
    case "$s" in
      fase1) jalan fase1 env RTV2_WEB=$WEB RTV2_APP=$APP OUT_DIR="$OUT/tangkapan-fase1" node uji-redesain-v2-fase1.mjs ;;
      jaringan) jalan jaringan env RTV2_WEB=$WEB RTV2_APP=$APP node uji-redesain-v2-fase1-jaringan.mjs rekam "$OUT/jaringan.json" ;;
      profil) jalan profil env DB_NAME=$D PIF2_WEB=$WEB PIF2_APP=$APP SEED_JSON="$OUT/ids-profil.json" OUT_DIR="$OUT/tangkapan-profil" node uji-profil-impor-f2-app.mjs ;;
    esac ;;
  keamanan)
    D=test_rtv2_pk_test
    PKF3_DB=$D bash tests/penugasan_keamanan_f4_db.sh > /dev/null
    PKF3_DB=$D php tests/penugasan_keamanan_f3_seed.php > "$OUT/ids-keamanan.json"
    api $D
    jalan keamanan env DB_NAME=$D PKF4_WEB=$WEB PKF4_APP=$APP SEED_JSON="$OUT/ids-keamanan.json" OUT_DIR="$OUT/tangkapan-keamanan" node uji-penugasan-keamanan-f4-app.mjs ;;
  cari-daftar)
    D=test_rtv2_pk_test
    PKF3_DB=$D bash tests/penugasan_keamanan_f4_db.sh > /dev/null
    PKF3_DB=$D php tests/penugasan_keamanan_f3_seed.php > "$OUT/ids-daftar.json"
    api $D
    jalan cari-daftar env DB_NAME=$D PCS_WEB=$WEB PCS_APP=$APP SEED_JSON="$OUT/ids-daftar.json" OUT_DIR="$OUT/tangkapan-cari-daftar" node uji-pencarian-santri-daftar-app.mjs ;;
  gabungan|cari-absensi|peserta)
    D=test_rtv2_jkg_test
    JKG_DB=$D bash tests/jadwal_kelas_gabungan_f1_db.sh > /dev/null
    JKG_DB=$D php tests/jadwal_kelas_gabungan_f2_seed.php > "$OUT/ids-$s.json"
    api $D
    case "$s" in
      gabungan) jalan gabungan env DB_NAME=$D JKG_WEB=$WEB JKG_APP=$APP SEED_JSON="$OUT/ids-$s.json" OUT_DIR="$OUT/tangkapan-gabungan" node uji-jadwal-kelas-gabungan-f2-app.mjs ;;
      cari-absensi) jalan cari-absensi env DB_NAME=$D PCS_WEB=$WEB PCS_APP=$APP SEED_JSON="$OUT/ids-$s.json" OUT_DIR="$OUT/tangkapan-cari-absensi" node uji-pencarian-santri-absensi-app.mjs ;;
      peserta) jalan peserta env DB_NAME=$D PPA_WEB=$WEB PPA_APP=$APP SEED_JSON="$OUT/ids-$s.json" OUT_DIR="$OUT/tangkapan-peserta" node uji-perbaikan-peserta-absensi.mjs ;;
    esac ;;
  v6f4)
    D=test_rtv2_v6_test; V6F4_DB=$D bash tests/v6_phase4_db.sh > /dev/null; api $D
    jalan v6f4 env DB_NAME=$D V6_WEB=$WEB V6_APP=$APP OUT_DIR="$OUT/tangkapan-v6f4" node uji-v6-fase4-app.mjs ;;
  v7f4)
    D=test_rtv2_v7_test; V7F4_DB=$D bash tests/v7_phase4_db.sh > /dev/null; api $D
    jalan v7f4 env DB_NAME=$D V7_WEB=$WEB V7_APP=$APP OUT_DIR="$OUT/tangkapan-v7f4" node uji-v7-fase4-app.mjs ;;
  v5f4)
    D=test_rtv2_v5_test; V5F3_DB=$D bash tests/v5_phase3_db.sh > /dev/null; api $D
    jalan v5f4 env DB_NAME=$D V5_WEB=$WEB V5_APP=$APP OUT_DIR="$OUT/tangkapan-v5f4" node uji-v5-fase4-app.mjs ;;
  v5f5)
    D=test_rtv2_v5_test; V5F5_DB=$D bash tests/v5_phase5_db.sh > /dev/null; api $D
    jalan v5f5 env V5_WEB=$WEB V5_APP=$APP OUT_DIR="$OUT/tangkapan-v5f5" node uji-v5-fase5-app.mjs ;;
  *) echo "suite tidak dikenal: $s" ;;
esac; done
pkill -f "php -S 127.0.0.1:8971 " 2>/dev/null
echo "galat PHP server API: $(grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$OUT/api.log")"
