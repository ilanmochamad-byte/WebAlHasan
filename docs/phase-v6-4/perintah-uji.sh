#!/usr/bin/env bash
# Uji PRD V6 Fase 4 (web/API + aplikasi Expo web) pada DB salinan `test_*_test`
# berfixture sintetis. Jalankan dari akar repo web, BERURUTAN. Keluaran di
# docs/phase-v6-4/bukti/.
#
#   MOBILE_COPY=/private/tmp/<salinan alhasanApps TANPA .env*> bash docs/phase-v6-4/perintah-uji.sh
#
# Fixture dibangun SEKALI lewat builder asli (tests/v6_phase4_db.sh), di-dump ke
# folder privat, lalu diimpor ulang ke DB terpisah untuk setiap suite.
# Aplikasi Expo web dijalankan dari salinan checkout mobile tanpa .env.local
# (berkas itu menunjuk API produksi dan tetap terbundel walau EXPO_NO_DOTENV=1).
set -u
cd "$(dirname "$0")/../.."
B=docs/phase-v6-4/bukti
mkdir -p "$B" docs/phase-v6-4/tangkapan
T=$(mktemp -d /private/tmp/v6f4-uji-XXXXXX)
MOBILE_COPY="${MOBILE_COPY:?MOBILE_COPY wajib (salinan alhasanApps tanpa .env*)}"
[ ! -e "$MOBILE_COPY/.env.local" ] || { echo "Salinan mobile masih memuat .env.local" >&2; exit 2; }
bash tests/v6_phase4_db.sh > /dev/null && mysqldump test_v6_f4_test > "$T/fixture.sql" || exit 2
fresh() { mysql -e "DROP DATABASE IF EXISTS \`$1\`; CREATE DATABASE \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" && mysql "$1" < "$T/fixture.sql"; }

php tests/v6_phase4_static.php > "$B/statis.txt" 2>&1; echo "statis exit=$?"
fresh test_v6_f4_http_test
V6F4_DB=test_v6_f4_http_test V6F4_LOG="$T/http-server.log" php tests/v6_phase4_http.php > "$B/http.txt" 2>&1; echo "http exit=$?"

fresh test_v6_f4_app_test
DB_NAME=test_v6_f4_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8984 tests/redesain_web_tahap1_router.php > "$T/app-server.log" 2>&1 & PAPI=$!
(cd "$MOBILE_COPY" && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8984/api/v1 CI=1 npx expo start --web --port 8099 --clear > "$T/metro.log" 2>&1) & PMETRO=$!
for i in $(seq 1 90); do curl -s -o /dev/null http://127.0.0.1:8099/ && break; sleep 2; done
BUNDEL=$(curl -s http://127.0.0.1:8099/ | grep -o 'src="[^"]*\.bundle[^"]*"' | head -1 | sed 's/src="//;s/"$//')
curl -s "http://127.0.0.1:8099$BUNDEL" -o "$T/bundle.js"
echo "Bundel $(wc -c < "$T/bundle.js") bita, host produksi: $(grep -c 'alhasan.co.id' "$T/bundle.js")" | tee "$B/aplikasi-expo-web.txt"
(cd tests/browser && DB_NAME=test_v6_f4_app_test V6_WEB=http://127.0.0.1:8984 V6_APP=http://127.0.0.1:8099 OUT_DIR="$PWD/../../docs/phase-v6-4/tangkapan" node uji-v6-fase4-app.mjs) >> "$B/aplikasi-expo-web.txt" 2>&1; echo "aplikasi exit=$?"
kill $PAPI; pkill -f "php -S 127.0.0.1:8984 "; pkill -f "expo start --web --port 8099"
grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$T/app-server.log" | sed 's/^/galat PHP server aplikasi: /' | tee -a "$B/aplikasi-expo-web.txt"
