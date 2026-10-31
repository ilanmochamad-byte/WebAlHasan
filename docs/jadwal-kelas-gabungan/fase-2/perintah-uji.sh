#!/usr/bin/env bash
# Uji Fase 2 jadwal kelas gabungan (aplikasi) pada DB salinan `test_*_test`
# berfixture sintetis. Jalankan dari akar repo web, BERURUTAN (rantai fixture
# memakai DB perantara bernama tetap). Keluaran di docs/jadwal-kelas-gabungan/fase-2/bukti/.
#
#   MOBILE_COPY=<salinan alhasanApps branch Fase 2 TANPA .env*, node_modules nyata dari lock ter-commit> \
#   MOBILE_BASE=<salinan `git archive b8d1825` dengan node_modules yang sama> \
#     bash docs/jadwal-kelas-gabungan/fase-2/perintah-uji.sh
#
# Uji native (Android emulator, iOS simulator) dijalankan terpisah; lihat README.
set -u
cd "$(dirname "$0")/../../.."
B=docs/jadwal-kelas-gabungan/fase-2/bukti
mkdir -p "$B/kontrol-baseline"
T=$(mktemp -d "${TMPDIR:-/tmp}/jkg2-uji-XXXXXX")
MOBILE_COPY="${MOBILE_COPY:?MOBILE_COPY wajib}"
for M in "$MOBILE_COPY" "${MOBILE_BASE:-$MOBILE_COPY}"; do
    [ ! -e "$M/.env.local" ] || { echo "Salinan mobile $M masih memuat .env.local" >&2; exit 2; }
    [ ! -L "$M/node_modules" ] || { echo "node_modules $M tidak boleh symlink" >&2; exit 2; }
done
DB=test_jkg_f2_app_test
siapkan() { JKG_DB=$DB bash tests/jadwal_kelas_gabungan_f1_db.sh > /dev/null && JKG_DB=$DB php tests/jadwal_kelas_gabungan_f2_seed.php > "$T/ids.json"; }

# Unit, TypeScript, lint aplikasi (salinan tanpa .env*).
(cd "$MOBILE_COPY" && node --experimental-strip-types --test tests/*.test.ts) > "$B/mobile-unit.txt" 2>&1; echo "unit exit=$?"

DB_NAME=$DB PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8961 tests/redesain_web_tahap1_router.php > "$T/app-server.log" 2>&1 & PAPI=$!
jalankan_expo() { # <salinan> <port>
    (cd "$1" && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8961/api/v1 CI=1 npx expo start --web --port "$2" --clear > "$T/metro-$2.log" 2>&1) &
    for i in $(seq 1 90); do curl -s -o /dev/null "http://127.0.0.1:$2/" && break; sleep 2; done
    BUNDEL=$(curl -s "http://127.0.0.1:$2/" | grep -o 'src="[^"]*\.bundle[^"]*"' | head -1 | sed 's/src="//;s/"$//')
    curl -s "http://127.0.0.1:$2$BUNDEL" -o "$T/bundle-$2.js"
    echo "port $2: host produksi $(grep -c 'alhasan.co.id' "$T/bundle-$2.js")"
}
uji() { # <port app> <berkas keluaran> [JKG_KONTROL_SATU]
    siapkan
    (cd tests/browser && DB_NAME=$DB JKG_WEB=http://127.0.0.1:8961 JKG_APP=http://127.0.0.1:$1 SEED_JSON="$T/ids.json" OUT_DIR="$T/tangkapan-$1" JKG_KONTROL_SATU="${3:-0}" node uji-jadwal-kelas-gabungan-f2-app.mjs) > "$2" 2>&1
    echo "$2 exit=$?"
}
jalankan_expo "$MOBILE_COPY" 8098
uji 8098 "$B/aplikasi-expo-web.txt"
uji 8098 "$B/aplikasi-expo-web-satu-kelas.txt" 1
if [ -n "${MOBILE_BASE:-}" ]; then
    jalankan_expo "$MOBILE_BASE" 8099
    uji 8099 "$B/kontrol-baseline/kontrol-baseline-penuh.txt"
    uji 8099 "$B/kontrol-baseline/kontrol-baseline-satu-kelas.txt" 1
fi
kill $PAPI; pkill -f "php -S 127.0.0.1:8961 "; pkill -f "expo start --web --port 809[89]"
grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$T/app-server.log" | sed 's/^/galat PHP server aplikasi: /'
