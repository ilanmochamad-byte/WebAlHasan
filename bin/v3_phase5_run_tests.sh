#!/usr/bin/env bash
set -u
cd "$(dirname "$0")/.." || exit 2
if [ "${DB_NAME:-}" != "webalhasan_v3_phase1_test" ]; then exit 77; fi
export V3_RUN_TESTS=1
failed=0
for suite in access integration performance migration fixture_teardown; do
  if php "tests/v3_phase5_${suite}.php"; then :; else failed=1; fi
done
if php bin/v3_phase5_verify.php; then :; else failed=1; fi
exit "$failed"
