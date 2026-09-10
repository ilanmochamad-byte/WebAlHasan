#!/usr/bin/env bash
# Database sandbox yang sama dengan paket V3 internal; tidak pernah produksi.
set -u
cd "$(dirname "$0")/.." || exit 2
if [ "${DB_NAME:-}" != "webalhasan_v3_phase1_test" ]; then
  echo 'BELUM DIJALANKAN: DB_NAME harus webalhasan_v3_phase1_test.'
  exit 77
fi
export V3_RUN_TESTS=1
failed=0
for suite in static integration concurrency; do
  if php "tests/v3_phase2_${suite}.php"; then :; else failed=1; fi
done
if php bin/v3_phase2_verify.php; then :; else failed=1; fi
exit "$failed"
