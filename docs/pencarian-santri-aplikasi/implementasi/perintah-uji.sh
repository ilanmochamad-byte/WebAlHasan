#!/usr/bin/env bash
# Reproduksi uji paket "pencarian santri, IBTIDA PA/PI, dan versi aplikasi"
# (5 November 2026). Jalankan dari akar repo web, BERURUTAN (rantai fixture
# memakai DB perantara bernama tetap). Hanya data sintetis, hanya 127.0.0.1.
#
#   MOBILE_COPY=<salinan alhasanApps branch fitur TANPA .env*, package.json/package-lock.json = HEAD,
#                node_modules nyata (bukan symlink) dari lock ter-commit> \
#     bash docs/pencarian-santri-aplikasi/implementasi/perintah-uji.sh <folder-keluaran>
#
# Uji native (Android emulator, iOS simulator) dijalankan terpisah — lihat README bagian "Native".
set -u
cd "$(dirname "$0")/../../.."
OUT="${1:?folder keluaran}"
mkdir -p "$OUT"
M="${MOBILE_COPY:?MOBILE_COPY wajib}"
[ ! -e "$M/.env.local" ] && [ ! -e "$M/.env" ] || { echo "Salinan mobile memuat .env produksi" >&2; exit 2; }
[ ! -L "$M/node_modules" ] || { echo "node_modules tidak boleh symlink" >&2; exit 2; }

# 1. Aplikasi: unit, TypeScript, lint, aturan versi.
(cd "$M" && node --experimental-strip-types --test tests/*.test.ts) > "$OUT/mobile-unit.txt" 2>&1; echo "unit exit=$?"
(cd "$M" && node_modules/.bin/tsc --noEmit) > "$OUT/mobile-tsc.txt" 2>&1; echo "tsc exit=$?"
(cd "$M" && EXPO_NO_DOTENV=1 node_modules/.bin/expo lint) > "$OUT/mobile-lint.txt" 2>&1; echo "lint exit=$?"
(cd /Users/ilanmochamad/alhasanApps && node scripts/versi-aplikasi.mjs periksa --dasar origin/main) > "$OUT/versi-periksa.txt" 2>&1; echo "versi exit=$?"

# 2. Kontrak server pencarian (C-1…C-6).
PKF3_DB=test_pcs_http_test bash tests/penugasan_keamanan_f4_db.sh > /dev/null
PKF3_DB=test_pcs_http_test php tests/pencarian_santri_http.php > "$OUT/kontrak-http.txt" 2>&1; echo "kontrak exit=$?"

# 3. Expo web: absensi (A-1…A-12) di atas fixture kelas gabungan.
JKG_DB=test_pcs_app_test bash tests/jadwal_kelas_gabungan_f1_db.sh > /dev/null
JKG_DB=test_pcs_app_test php tests/jadwal_kelas_gabungan_f2_seed.php > "$OUT/ids-absensi.json"
DB_NAME=test_pcs_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8961 tests/redesain_web_tahap1_router.php > "$OUT/api.log" 2>&1 & PAPI=$!
(cd "$M" && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8961/api/v1 CI=1 node_modules/.bin/expo start --web --port 8098 --clear > "$OUT/metro.log" 2>&1) &
for i in $(seq 1 90); do curl -s -o /dev/null http://127.0.0.1:8098/ && break; sleep 2; done
(cd tests/browser && DB_NAME=test_pcs_app_test PCS_WEB=http://127.0.0.1:8961 PCS_APP=http://127.0.0.1:8098 SEED_JSON="$OUT/ids-absensi.json" OUT_DIR="$OUT/tangkapan-absensi" node uji-pencarian-santri-absensi-app.mjs) > "$OUT/expo-absensi.txt" 2>&1; echo "absensi exit=$?"
kill $PAPI; pkill -f "php -S 127.0.0.1:8961 "

# 4. Expo web: daftar berhalaman (D-1…D-5) di atas fixture Keamanan.
PKF3_DB=test_pcs_daftar_test bash tests/penugasan_keamanan_f4_db.sh > /dev/null
PKF3_DB=test_pcs_daftar_test php tests/penugasan_keamanan_f3_seed.php > "$OUT/ids-daftar.json"
DB_NAME=test_pcs_daftar_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8961 tests/redesain_web_tahap1_router.php >> "$OUT/api.log" 2>&1 & PAPI=$!
sleep 2
(cd tests/browser && DB_NAME=test_pcs_daftar_test PCS_WEB=http://127.0.0.1:8961 PCS_APP=http://127.0.0.1:8098 SEED_JSON="$OUT/ids-daftar.json" OUT_DIR="$OUT/tangkapan-daftar" node uji-pencarian-santri-daftar-app.mjs) > "$OUT/expo-daftar.txt" 2>&1; echo "daftar exit=$?"
kill $PAPI; pkill -f "php -S 127.0.0.1:8961 "; pkill -f "expo start --web --port 8098"
grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$OUT/api.log" | sed 's/^/galat PHP server API: /'
