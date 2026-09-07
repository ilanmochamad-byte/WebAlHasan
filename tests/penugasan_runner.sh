#!/usr/bin/env bash
# Runner acceptance is tested with commands stubbed: this test never opens a DB.
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TEMP="$(mktemp -d)"
trap 'rm -rf "$TEMP"' EXIT
cat > "$TEMP/php" <<'PHP'
#!/bin/sh
if [ "${AUDIT_SKIP_CASE:-0}" = 1 ] && [ "$1" = tests/penugasan_concurrency.php ]; then exit 77; fi
printf '[lulus] Stub runner only\n'
PHP
cat > "$TEMP/bash" <<'BASH'
#!/bin/sh
exit 0
BASH
chmod +x "$TEMP/php" "$TEMP/bash"
PATH="$TEMP:$PATH" AUDIT_SKIP_CASE=0 MOBILE_APP_ROOT=/tmp /bin/bash "$ROOT/bin/penugasan_run_all_tests.sh" > "$TEMP/success"
echo '[lulus] Runner menerima rangkaian lengkap yang sukses'
if PATH="$TEMP:$PATH" AUDIT_SKIP_CASE=1 MOBILE_APP_ROOT=/tmp /bin/bash "$ROOT/bin/penugasan_run_all_tests.sh" > "$TEMP/skipped"; then
 echo '[gagal] Runner menerima concurrency yang dilewati'; exit 1
fi
if rg -q 'SELURUH PENGUJIAN OTOMATIS LULUS' "$TEMP/skipped"; then
 echo '[gagal] Klaim lulus meskipun concurrency dilewati'; exit 1
fi
echo '[lulus] Runner menolak penerimaan bila concurrency dilewati'
if PATH="$TEMP:$PATH" PENUGASAN_SKIP_REGRESI=1 MOBILE_APP_ROOT=/tmp /bin/bash "$ROOT/bin/penugasan_run_all_tests.sh" > "$TEMP/partial"; then
 echo '[gagal] Runner menerima regresi yang dilewati'; exit 1
fi
echo '[lulus] Runner tidak menyatakan lulus penuh bila regresi dilewati'
