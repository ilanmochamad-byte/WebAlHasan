#!/usr/bin/env bash
# Uji PRD V7 Fase 4 (paritas aplikasi) pada DB salinan `test_*_test`
# berfixture sintetis. Jalankan dari akar repo web, BERURUTAN. Keluaran di
# docs/phase-v7-4/bukti/.
#
#   MOBILE_COPY=/private/tmp/<salinan alhasanApps TANPA .env*, node_modules nyata dari lock ter-commit> \
#     bash docs/phase-v7-4/perintah-uji.sh
#
# Salinan mobile: `git ls-files -co --exclude-standard` tanpa `.env*`, plus
# `expo-env.d.ts` dan `.expo/types`; `node_modules` HARUS direktori nyata
# (bukan symlink ke luar akar proyek — `import()` dinamis Metro gagal
# menyelesaikan modul, lihat README §Batas) dari `package-lock.json` ter-commit.
set -u
cd "$(dirname "$0")/../.."
B=docs/phase-v7-4/bukti
mkdir -p "$B" docs/phase-v7-4/tangkapan
T=$(mktemp -d /private/tmp/v7f4-uji-XXXXXX)
MOBILE_COPY="${MOBILE_COPY:?MOBILE_COPY wajib (salinan alhasanApps tanpa .env*)}"
[ ! -e "$MOBILE_COPY/.env.local" ] || { echo "Salinan mobile masih memuat .env.local" >&2; exit 2; }
[ ! -L "$MOBILE_COPY/node_modules" ] || { echo "node_modules salinan tidak boleh symlink" >&2; exit 2; }

php tests/v7_phase4_static.php > "$B/statis.txt" 2>&1; echo "statis exit=$?"
V7F4_DB=test_v7_f4_http_test bash tests/v7_phase4_db.sh > /dev/null && V7F4_DB=test_v7_f4_http_test php tests/v7_phase4_http.php > "$B/http.txt" 2>&1; echo "http exit=$?"

V7F4_DB=test_v7_f4_app_test bash tests/v7_phase4_db.sh > /dev/null
DB_NAME=test_v7_f4_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8976 tests/redesain_web_tahap1_router.php > "$T/app-server.log" 2>&1 & PAPI=$!
(cd "$MOBILE_COPY" && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8976/api/v1 CI=1 npx expo start --web --port 8098 --clear > "$T/metro.log" 2>&1) & PMETRO=$!
for i in $(seq 1 90); do curl -s -o /dev/null http://127.0.0.1:8098/ && break; sleep 2; done
BUNDEL=$(curl -s http://127.0.0.1:8098/ | grep -o 'src="[^"]*\.bundle[^"]*"' | head -1 | sed 's/src="//;s/"$//')
curl -s "http://127.0.0.1:8098$BUNDEL" -o "$T/bundle.js"
echo "Bundel $(wc -c < "$T/bundle.js") bita, host produksi: $(grep -c 'alhasan.co.id' "$T/bundle.js"), sumber salinan: $(grep -c "$MOBILE_COPY/src/app/izin-wali" "$T/bundle.js")" | tee "$B/aplikasi-expo-web.txt"
(cd tests/browser && DB_NAME=test_v7_f4_app_test V7_WEB=http://127.0.0.1:8976 V7_APP=http://127.0.0.1:8098 OUT_DIR="$PWD/../../docs/phase-v7-4/tangkapan" node uji-v7-fase4-app.mjs) >> "$B/aplikasi-expo-web.txt" 2>&1; echo "aplikasi exit=$?"
kill $PAPI; pkill -f "php -S 127.0.0.1:8976 "; pkill -f "expo start --web --port 8098"
grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$T/app-server.log" | sed 's/^/galat PHP server aplikasi: /' | tee -a "$B/aplikasi-expo-web.txt"
