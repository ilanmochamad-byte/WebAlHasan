#!/usr/bin/env bash
# Dedicated synthetic database only. Migration drill separately destroys V3 fixtures.
set -u
cd "$(dirname "$0")/.." || exit 2
if [ "${DB_NAME:-}" != "webalhasan_v3_phase1_test" ]; then
  echo 'BELUM DIJALANKAN: DB_NAME harus webalhasan_v3_phase1_test.'
  exit 77
fi
export V3_RUN_TESTS=1
failed=0
for suite in static integration concurrency diagnostics; do
  if php "tests/v3_phase1_${suite}.php"; then :; else failed=1; fi
done
exit "$failed"
