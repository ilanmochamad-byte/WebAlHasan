#!/usr/bin/env bash
# Uji PRD V6 Fase 5 (laporan, ekspor, alat rilis; web/API + aplikasi Expo web)
# pada DB salinan `test_*_test` berfixture sintetis. Jalankan dari akar repo
# web, BERURUTAN dan tidak bersamaan dengan perintah-regresi.sh (rantai fixture
# memakai DB perantara bernama tetap). Keluaran di docs/phase-v6-5/bukti/.
#
#   MOBILE_COPY=/private/tmp/<salinan alhasanApps TANPA .env*> bash docs/phase-v6-5/perintah-uji.sh
#
# Aplikasi Expo web dijalankan dari salinan checkout mobile tanpa .env* (berkas
# itu menunjuk API produksi dan tetap terbundel walau EXPO_NO_DOTENV=1);
# skrip memeriksa bundel tidak memuat host produksi sebelum uji.
set -u
cd "$(dirname "$0")/../.."
B=docs/phase-v6-5/bukti
mkdir -p "$B" docs/phase-v6-5/tangkapan
T=$(mktemp -d /private/tmp/v6f5-uji-XXXXXX)
MOBILE_COPY="${MOBILE_COPY:?MOBILE_COPY wajib (salinan alhasanApps tanpa .env*)}"
ls -a "$MOBILE_COPY" | grep -q '^\.env' && { echo "Salinan mobile masih memuat .env*" >&2; exit 2; }
fixture() {
  local db="$1" q
  q() { mysql -N -B "$db" -e "$1"; }
  touch "$T/aba"
  DB_NAME="$db" php tests/v6_phase5_worker.php "$T/aba" "$(q "SELECT id FROM users WHERE username='sbx_v6_bsa_b'")" fixture \
    "$(q "SELECT id FROM santri WHERE nis='V6S0001'")" "$(q "SELECT id FROM santri WHERE nis='V6S0002'")" "$(q "SELECT id FROM santri WHERE nis='V6S0004'")" > /dev/null
}

php tests/v6_phase5_static.php > "$B/statis.txt" 2>&1; echo "statis exit=$?"
bash tests/v6_phase5_db.sh > /dev/null && V6_RUN_INTEGRATION=1 php tests/v6_phase5_integration.php > "$B/integrasi.txt" 2>&1; echo "integrasi exit=$?"
V6F5_DB=test_v6_f5_http_test bash tests/v6_phase5_db.sh > /dev/null && V6F5_DB=test_v6_f5_http_test php tests/v6_phase5_http.php > "$B/http.txt" 2>&1; echo "http exit=$?"
V6_RUN_RILIS=1 php tests/v6_phase5_rilis.php > "$B/rilis.txt" 2>&1; echo "rilis exit=$?"

V6F5_DB=test_v6_f5_browser_test bash tests/v6_phase5_db.sh > /dev/null && fixture test_v6_f5_browser_test
DB_NAME=test_v6_f5_browser_test php -S 127.0.0.1:8987 tests/redesain_web_tahap1_router.php > "$T/browser-server.log" 2>&1 & PB=$!; sleep 1
(cd tests/browser && BASE_URL=http://127.0.0.1:8987 OUT_DIR=../../docs/phase-v6-5/tangkapan node uji-v6-fase5.mjs) > "$B/browser.txt" 2>&1; echo "browser exit=$?"
kill $PB
grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$T/browser-server.log" | sed 's/^/galat PHP server browser: /' | tee -a "$B/browser.txt"

V6F5_DB=test_v6_f5_app_test bash tests/v6_phase5_db.sh > /dev/null && fixture test_v6_f5_app_test
DB_NAME=test_v6_f5_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8986 tests/redesain_web_tahap1_router.php > "$T/app-server.log" 2>&1 & PAPI=$!
(cd "$MOBILE_COPY" && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8986/api/v1 CI=1 npx expo start --web --port 8099 --clear > "$T/metro.log" 2>&1) & PMETRO=$!
for i in $(seq 1 90); do curl -s -o /dev/null http://127.0.0.1:8099/ && break; sleep 2; done
BUNDEL=$(curl -s http://127.0.0.1:8099/ | grep -o 'src="[^"]*\.bundle[^"]*"' | head -1 | sed 's/src="//;s/"$//')
curl -s "http://127.0.0.1:8099$BUNDEL" -o "$T/bundel.js"
{ echo "bundel host produksi: $(grep -c 'alhasan.co.id' "$T/bundel.js")"; echo "bundel API lokal: $(grep -c '127.0.0.1:8986/api/v1' "$T/bundel.js")"; } > "$B/aplikasi-expo-web.txt"
if [ "$(grep -c 'alhasan.co.id' "$T/bundel.js")" = "0" ]; then
  (cd tests/browser && DB_NAME=test_v6_f5_app_test V6_WEB=http://127.0.0.1:8986 V6_APP=http://127.0.0.1:8099 OUT_DIR=../../docs/phase-v6-5/tangkapan node uji-v6-fase5-app.mjs) >> "$B/aplikasi-expo-web.txt" 2>&1; echo "aplikasi exit=$?"
else
  echo "BUNDEL MEMUAT HOST PRODUKSI — uji aplikasi tidak dijalankan" | tee -a "$B/aplikasi-expo-web.txt"
fi
kill $PAPI $PMETRO 2>/dev/null; lsof -ti tcp:8099 | xargs kill 2>/dev/null
grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$T/app-server.log" | sed 's/^/galat PHP server aplikasi: /' | tee -a "$B/aplikasi-expo-web.txt"
