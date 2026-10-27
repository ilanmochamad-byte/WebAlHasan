#!/usr/bin/env bash
# Uji PRD V7 Fase 5 (regresi, keamanan, kesiapan rilis) pada DB salinan
# `test_*_test` berfixture sintetis. Jalankan dari akar repo web, BERURUTAN
# (tidak bersamaan dengan builder/regresi lain). Keluaran audit-codex/f5-1/utama/.
#
#   MOBILE_COPY=/private/tmp/<salinan alhasanApps TANPA .env*, node_modules nyata dari lock ter-commit> \
#     bash docs/phase-v7-5/perintah-f5-1-audit.sh
#
# Salinan mobile: `git archive <HEAD>` (package.json/package-lock.json salinan
# kerja milik pekerjaan lain TIDAK dipakai), `node_modules` nyata dari lock
# ter-commit (bukan symlink), plus `expo-env.d.ts` dan `.expo/types` hasil
# generator Expo. Tanpa `.env*` (checkout asli menunjuk API produksi).
set -u
cd "$(dirname "$0")/../.."
B=docs/phase-v7-5/audit-codex/f5-1/utama
mkdir -p "$B" docs/phase-v7-5/audit-codex/f5-1/tangkapan
T=$(mktemp -d /private/tmp/v7f5-uji-XXXXXX)
MOBILE_COPY="${MOBILE_COPY:?MOBILE_COPY wajib (salinan alhasanApps tanpa .env*)}"
[ ! -e "$MOBILE_COPY/.env.local" ] || { echo "Salinan mobile masih memuat .env.local" >&2; exit 2; }
[ ! -L "$MOBILE_COPY/node_modules" ] || { echo "node_modules salinan tidak boleh symlink" >&2; exit 2; }
verifikasi() { DB_NAME="$1" php bin/v7_verify.php > "$B/verifier-$2.txt" 2>&1; echo "verifier-$2 exit=$? $(grep -o 'Ringkasan verifier V7: .*' "$B/verifier-$2.txt")"; }

php tests/v7_phase5_static.php > "$B/statis.txt" 2>&1; echo "statis exit=$? $(tail -1 "$B/statis.txt")"

V7F5_DB=test_v7_f51_codex_test bash tests/v7_phase5_db.sh > /dev/null
V7F5_DB=test_v7_f51_codex_test php tests/v7_phase5_e2e.php > "$B/e2e.txt" 2>&1; echo "e2e exit=$? $(grep '^Hasil' "$B/e2e.txt")"
verifikasi test_v7_f51_codex_test e2e

V7F5_DB=test_v7_f51_codex_http_test bash tests/v7_phase5_db.sh > /dev/null
V7F5_DB=test_v7_f51_codex_http_test php tests/v7_phase5_http.php > "$B/http.txt" 2>&1; echo "http exit=$? $(grep '^Hasil' "$B/http.txt")"
verifikasi test_v7_f51_codex_http_test http

V7_RUN_RILIS=1 V7F5_RILIS_DB="${V7F5_RILIS_DB:-test_v7_f51_codex_rilis_test}" php tests/v7_phase5_rilis.php > "$B/rilis.txt" 2>&1; echo "rilis exit=$? $(grep '^Hasil' "$B/rilis.txt")"

V7F5_DB=test_v7_f51_codex_browser_test bash tests/v7_phase5_db.sh > /dev/null
DB_NAME=test_v7_f51_codex_browser_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:9012 tests/redesain_web_tahap1_router.php > "$T/browser-server.log" 2>&1 & P=$!; sleep 1
(cd tests/browser && BASE_URL=http://127.0.0.1:9012 OUT_DIR="$PWD/../../docs/phase-v7-5/audit-codex/f5-1/tangkapan" node uji-v7-fase5.mjs) > "$B/browser.txt" 2>&1; echo "browser exit=$? $(grep '^Hasil' "$B/browser.txt")"
kill $P; pkill -f 'php -S 127.0.0.1:9012 '
# Klik ganda formulir web (E-A) → tepat satu pengajuan per lebar layar.
DB_NAME=test_v7_f51_codex_browser_test php -r 'require "app/bootstrap.php"; foreach (["2027-08-02","2027-08-09","2027-08-16"] as $t) { $n = (int) app_db()->query("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = \"Wali\" AND tgl_izin = \"$t\"")->fetch_row()[0]; echo ($n === 1 ? "[lulus] " : "[gagal] ") . "Klik ganda browser $t: $n pengajuan\n"; }' >> "$B/browser.txt"
grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$T/browser-server.log" | sed 's/^/galat PHP server browser: /' | tee -a "$B/browser.txt"
verifikasi test_v7_f51_codex_browser_test browser

# Aplikasi (Expo web) — suite Fase 4 dan probe audit Codex pada kode Fase 5.
for s in app regresi audit; do
    V7F4_DB=test_v7_f51_codex_app_${s}_test bash tests/v7_phase4_db.sh > /dev/null
    DB_NAME=test_v7_f51_codex_app_${s}_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:9016 tests/redesain_web_tahap1_router.php > "$T/app-$s-server.log" 2>&1 & PAPI=$!
    (cd "$MOBILE_COPY" && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:9016/api/v1 CI=1 npx expo start --web --port 8128 --clear > "$T/metro-$s.log" 2>&1) & PMETRO=$!
    for i in $(seq 1 90); do curl -s -o /dev/null http://127.0.0.1:8128/ && break; sleep 2; done
    BUNDEL=$(curl -s http://127.0.0.1:8128/ | grep -o 'src="[^"]*\.bundle[^"]*"' | head -1 | sed 's/src="//;s/"$//')
    curl -s "http://127.0.0.1:8128$BUNDEL" -o "$T/bundle-$s.js"
    echo "Bundel $(wc -c < "$T/bundle-$s.js") bita, host produksi: $(grep -c 'alhasan.co.id' "$T/bundle-$s.js")" > "$B/aplikasi-$s.txt"
    (cd tests/browser && DB_NAME=test_v7_f51_codex_app_${s}_test V7_WEB=http://127.0.0.1:9016 V7_APP=http://127.0.0.1:8128 OUT_DIR="$T/tangkapan-$s" \
        node "uji-v7-fase4-$([ "$s" = app ] && echo app || echo "$s").mjs") >> "$B/aplikasi-$s.txt" 2>&1
    echo "aplikasi-$s exit=$? $(grep -cE '^\[lulus\]|^ *✓' "$B/aplikasi-$s.txt")/$(grep -cE '^\[gagal\]|^ *✗' "$B/aplikasi-$s.txt")"
    kill $PAPI 2>/dev/null; pkill -f 'php -S 127.0.0.1:9016 '; pkill -f 'expo start --web --port 8128'; sleep 2
    grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$T/app-$s-server.log" | sed 's/^/galat PHP server aplikasi: /' | tee -a "$B/aplikasi-$s.txt"
    verifikasi test_v7_f51_codex_app_${s}_test "aplikasi-$s"
done
{
    echo '# Mobile — tsc/lint/unit (salinan HEAD 7478f7c tanpa .env*, node_modules lock ter-commit)'
    (cd "$MOBILE_COPY" && npx tsc --noEmit; echo "tsc exit=$?"; npx eslint src tests; echo "lint exit=$?"; node --experimental-strip-types --test tests/*.test.ts 2>&1 | grep -E '^ℹ (tests|pass|fail)')
} > "$B/mobile-tsc-lint-unit.txt" 2>&1; cat "$B/mobile-tsc-lint-unit.txt"
echo "Sementara: $T"
