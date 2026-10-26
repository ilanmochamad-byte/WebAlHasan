#!/usr/bin/env bash
# Validasi lanjutan Claude atas audit Codex Fase 4 (main hasil merge = branch audit).
# Jalankan dari akar repo web. MOBILE_COPY = salinan alhasanApps TANPA .env*
# dengan node_modules versi ter-commit (npm ci). Keluaran di folder ini.
set -u
cd "$(dirname "$0")/../../.."
O=docs/phase-v6-4/validasi-claude
MOBILE_COPY="${MOBILE_COPY:?MOBILE_COPY wajib}"
[ ! -e "$MOBILE_COPY/.env.local" ] || { echo "salinan memuat .env.local" >&2; exit 2; }
T=$(mktemp -d /private/tmp/v6f4-val-XXXXXX)
bash tests/v6_phase4_db.sh > /dev/null && mysqldump test_v6_f4_test > "$T/fixture.sql" || exit 2
fresh() { mysql -e "DROP DATABASE IF EXISTS \`$1\`; CREATE DATABASE \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" && mysql "$1" < "$T/fixture.sql"; }
hitung() { echo "$(grep -c '^\[lulus\]' "$1")/$(grep -c '^\[gagal\]' "$1")"; }
php tests/v6_phase4_static.php > "$O/statis.txt" 2>&1; echo "statis exit=$? $(hitung $O/statis.txt)"
fresh test_v6_f4_http_test
V6F4_DB=test_v6_f4_http_test php tests/v6_phase4_http.php > "$O/http.txt" 2>&1; echo "http exit=$? $(hitung $O/http.txt)"
# Aplikasi: sumber dari commit yang diminta (asli atau koreksi), Metro bersih, bundel tanpa host produksi.
metro() {
  pkill -f "expo start --web --port 8099"; sleep 1
  rm -rf "$MOBILE_COPY/src" && git -C ~/alhasanApps archive "$1" src | tar -x -C "$MOBILE_COPY"
  (cd "$MOBILE_COPY" && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8984/api/v1 CI=1 npx expo start --web --port 8099 --clear > "$T/metro-$1.log" 2>&1 &)
  for i in $(seq 1 90); do curl -s -o /dev/null http://127.0.0.1:8099/ && break; sleep 2; done
  B=$(curl -s http://127.0.0.1:8099/ | grep -o 'src="[^"]*\.bundle[^"]*"' | head -1 | sed 's/src="//;s/"$//')
  curl -s "http://127.0.0.1:8099$B" -o "$T/bundle.js"; echo "bundel $1: host produksi $(grep -c 'alhasan.co.id' "$T/bundle.js")"
}
api() { pkill -f "php -S 127.0.0.1:8984 "; sleep 1; fresh test_v6_f4_app_test; (DB_NAME=test_v6_f4_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8984 tests/redesain_web_tahap1_router.php > "$T/api.log" 2>&1 &); sleep 1; }
jalan() { (cd tests/browser && DB_NAME=test_v6_f4_app_test V6_WEB=http://127.0.0.1:8984 V6_APP=http://127.0.0.1:8099 OUT_DIR="$T/shots" node "$1") > "$2" 2>&1; echo "$(basename "$2") exit=$? $(hitung "$2")"; }
PROBE="$PWD/docs/phase-v6-4/audit-codex/uji-mutasi-akses.mjs"  # probe Codex apa adanya (impor relatif dari foldernya)
metro 12fb04a; api; jalan "$PROBE" "$O/probe-f1-kontrol-12fb04a.txt"
metro 87d1c10; api; jalan "$PROBE" "$O/probe-f1-koreksi-87d1c10.txt"
api; jalan uji-v6-fase4-app.mjs "$O/expo-web-87d1c10.txt"
pkill -f "php -S 127.0.0.1:8984 "; pkill -f "expo start --web --port 8099"
echo "galat PHP API: $(grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$T/api.log")"
