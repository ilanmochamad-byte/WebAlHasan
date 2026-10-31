#!/usr/bin/env bash
# Uji Fase 4 penugasan lintas guru/pengurus (REST baca-saja Keamanan, identitas
# akun, layar aplikasi) pada DB salinan `test_*_test` berfixture sintetis.
# Jalankan dari akar repo web, BERURUTAN (tidak bersamaan dengan skrip yang
# membangun DB). Keluaran di docs/penugasan-keamanan/fase-4/bukti/.
#
#   MOBILE_COPY=/private/tmp/<salinan alhasanApps TANPA .env*, node_modules nyata dari lock ter-commit> \
#   BASELINE=/private/tmp/<klon 6ebdc24 berisi .env, symlink vendor/, storage/private/{v3,v4}> \
#     bash docs/penugasan-keamanan/fase-4/perintah-uji.sh
set -u
cd "$(dirname "$0")/../../.."
B=docs/penugasan-keamanan/fase-4/bukti
mkdir -p "$B/kontrol-baseline"
T=$(mktemp -d "${TMPDIR:-/tmp}/pkf4-uji-XXXXXX")
MOBILE_COPY="${MOBILE_COPY:?MOBILE_COPY wajib (salinan alhasanApps tanpa .env*)}"
[ ! -e "$MOBILE_COPY/.env.local" ] || { echo "Salinan mobile masih memuat .env.local" >&2; exit 2; }
[ ! -L "$MOBILE_COPY/node_modules" ] || { echo "node_modules salinan tidak boleh symlink" >&2; exit 2; }

php tests/penugasan_keamanan_f4_static.php > "$B/statis.txt" 2>&1; echo "statis exit=$?"
PKF3_DB=test_pk_f4_http_test bash tests/penugasan_keamanan_f4_db.sh > /dev/null && PKF3_DB=test_pk_f4_http_test php tests/penugasan_keamanan_f4_http.php > "$B/http.txt" 2>&1; echo "http exit=$?"

PKF3_DB=test_pk_f4_app_test bash tests/penugasan_keamanan_f4_db.sh > /dev/null
PKF3_DB=test_pk_f4_app_test php tests/penugasan_keamanan_f3_seed.php > "$T/ids.json"
DB_NAME=test_pk_f4_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8957 tests/redesain_web_tahap1_router.php > "$T/app-server.log" 2>&1 & PAPI=$!
(cd "$MOBILE_COPY" && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8957/api/v1 CI=1 npx expo start --web --port 8096 --clear > "$T/metro.log" 2>&1) &
for i in $(seq 1 90); do curl -s -o /dev/null http://127.0.0.1:8096/ && break; sleep 2; done
BUNDEL=$(curl -s http://127.0.0.1:8096/ | grep -o 'src="[^"]*\.bundle[^"]*"' | head -1 | sed 's/src="//;s/"$//')
curl -s "http://127.0.0.1:8096$BUNDEL" -o "$T/bundle.js"
echo "Bundel $(wc -c < "$T/bundle.js") bita, host produksi: $(grep -c 'alhasan.co.id' "$T/bundle.js"), layar Keamanan: $(grep -c 'Pantauan santri' "$T/bundle.js")" | tee "$B/aplikasi-expo-web.txt"
(cd tests/browser && DB_NAME=test_pk_f4_app_test PKF4_WEB=http://127.0.0.1:8957 PKF4_APP=http://127.0.0.1:8096 SEED_JSON="$T/ids.json" OUT_DIR="$T/tangkapan" node uji-penugasan-keamanan-f4-app.mjs) >> "$B/aplikasi-expo-web.txt" 2>&1; echo "aplikasi exit=$?"
kill $PAPI; pkill -f "php -S 127.0.0.1:8957 "; pkill -f "expo start --web --port 8096"
grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$T/app-server.log" | sed 's/^/galat PHP server aplikasi: /' | tee -a "$B/aplikasi-expo-web.txt"

# Kontrol: uji HTTP baru pada kode baseline (REST Keamanan dan `identitas` belum ada).
if [ -n "${BASELINE:-}" ]; then
    cp tests/penugasan_keamanan_f4_http.php tests/penugasan_keamanan_f4_db.sh "$BASELINE/tests/"
    (cd "$BASELINE" && PKF3_DB=test_pk_f4k_http_test bash tests/penugasan_keamanan_f4_db.sh > /dev/null && PKF3_DB=test_pk_f4k_http_test php tests/penugasan_keamanan_f4_http.php) > "$B/kontrol-baseline/f4_http.txt" 2>&1
    echo "kontrol http baseline exit=$?"
    rm -f "$BASELINE/tests/penugasan_keamanan_f4_http.php" "$BASELINE/tests/penugasan_keamanan_f4_db.sh"
fi
