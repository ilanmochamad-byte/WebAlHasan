#!/usr/bin/env bash
# V2 Fase 5 — menjalankan SELURUH pengujian otomatis (Fase 1 sampai 5) lalu
# meringkasnya. Termasuk regresi V1 dan V2 supaya laporan Fase 5 tidak
# menyembunyikan kerusakan pada fase sebelumnya.
#
# Prasyarat: lihat docs/phase-v2-5/testing-sandbox.md
#   - database uji berakhiran _test dengan migrasi 001–009 sudah dijalankan;
#   - fixture peran   : V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php
#   - fixture performa: V2_PHASE5_FIXTURE=1 php bin/v2_phase5_fixture.php --jumlah=1000
#   - MOBILE_APP_ROOT menunjuk ke folder alhasanApps.
#
# Pemakaian:
#   MOBILE_APP_ROOT=/path/ke/alhasanApps bash bin/v2_phase5_run_all_tests.sh
#
# Yang TIDAK dicakup skrip ini (dan TIDAK boleh diklaim lulus tanpa bukti):
#   - kedatangan push pada perangkat Android/iOS NYATA;
#   - deep link push pada foreground/background/cold-start perangkat NYATA;
#   - pengiriman WhatsApp oleh penyedia NYATA (DITANGGUHKAN);
#   - smoke test produksi cPanel dan pemasangan cron produksi;
#   - `npx tsc --noEmit`, `npx expo lint`, dan `npx expo export -p web`
#     (dijalankan dari repositori alhasanApps, bukan dari sini).
# Lihat docs/phase-v2-5/uji-manual-tertunda.md.
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 2
MOBILE_APP_ROOT="${MOBILE_APP_ROOT:-$(cd "$ROOT/../../../alhasanApps" 2>/dev/null && pwd)}"
export MOBILE_APP_ROOT

gagal=0
lulus_total=0

jalankan() {
  local nama="$1"; shift
  local log
  log="$(mktemp)"
  if "$@" > "$log" 2>&1; then
    local n
    n="$(grep -c '\[lulus\]' "$log")"
    lulus_total=$((lulus_total + n))
    printf '%-46s LULUS   (%s pemeriksaan)\n' "$nama" "$n"
  else
    local kode=$?
    if [ "$kode" -eq 77 ]; then
      printf '%-46s DILEWATI\n' "$nama"
      head -2 "$log"
    else
      printf '%-46s GAGAL\n' "$nama"
      grep '\[gagal\]' "$log" | head -10
      tail -3 "$log"
      gagal=$((gagal + 1))
    fi
  fi
  rm -f "$log"
}

echo '--- Statis ---'
jalankan 'tests/phase1_static.php'             php tests/phase1_static.php
jalankan 'tests/phase2_static.php'             php tests/phase2_static.php
jalankan 'tests/phase3_static.php'             php tests/phase3_static.php
jalankan 'tests/phase4_static.php'             php tests/phase4_static.php
jalankan 'tests/phase5_static.php'             php tests/phase5_static.php
jalankan 'tests/v2_phase1_static.php'          php tests/v2_phase1_static.php
jalankan 'tests/v2_phase2_static.php'          php tests/v2_phase2_static.php
jalankan 'tests/v2_phase3_static.php'          php tests/v2_phase3_static.php
jalankan 'tests/v2_phase4_static.php'          php tests/v2_phase4_static.php
jalankan 'tests/v2_phase5_static.php'          php tests/v2_phase5_static.php

echo '--- Integrasi regresi V1 ---'
jalankan 'tests/phase2_integration.php'        env PHASE2_RUN_INTEGRATION=1 php tests/phase2_integration.php
jalankan 'tests/phase3_integration.php'        env PHASE3_RUN_INTEGRATION=1 php tests/phase3_integration.php
jalankan 'tests/phase4_integration.php'        env PHASE4_RUN_INTEGRATION=1 php tests/phase4_integration.php
jalankan 'tests/phase5_integration.php'        env PHASE5_RUN_INTEGRATION=1 php tests/phase5_integration.php

echo '--- Integrasi regresi V2 Fase 1-2 ---'
jalankan 'tests/v2_phase1_integration.php'     env V2_PHASE1_RUN_INTEGRATION=1 php tests/v2_phase1_integration.php
jalankan 'tests/v2_phase2_integration.php'     env V2_PHASE2_RUN_INTEGRATION=1 php tests/v2_phase2_integration.php
jalankan 'tests/v2_phase2_navigasi_murobi.php' env V2_PHASE2_RUN_NAV=1 php tests/v2_phase2_navigasi_murobi.php
jalankan 'tests/v2_phase2_web_smoke.php'       env V2_PHASE2_RUN_WEB=1 php tests/v2_phase2_web_smoke.php

echo '--- Kontrak API Fase 3 ---'
jalankan 'tests/v2_phase3_api_contract.php'    env V2_PHASE3_RUN_API=1 php tests/v2_phase3_api_contract.php

echo '--- Fase 4: notifikasi, push, WhatsApp ---'
jalankan 'tests/v2_phase4_integration.php'     env V2_PHASE4_RUN_INTEGRATION=1 php tests/v2_phase4_integration.php
jalankan 'tests/v2_phase4_api_contract.php'    env V2_PHASE4_RUN_API=1 php tests/v2_phase4_api_contract.php
jalankan 'tests/v2_phase4_concurrency.php'     env V2_PHASE4_RUN_CONCURRENCY=1 php tests/v2_phase4_concurrency.php
jalankan 'tests/v2_phase4_web_smoke.php'       env V2_PHASE4_RUN_WEB=1 php tests/v2_phase4_web_smoke.php

echo '--- Fase 5: laporan, cetak, ekspor, receipt ---'
jalankan 'tests/v2_phase5_integration.php'     env V2_PHASE5_RUN_INTEGRATION=1 php tests/v2_phase5_integration.php
jalankan 'tests/v2_phase5_api_contract.php'    env V2_PHASE5_RUN_API=1 php tests/v2_phase5_api_contract.php
jalankan 'tests/v2_phase5_web_smoke.php'       env V2_PHASE5_RUN_WEB=1 php tests/v2_phase5_web_smoke.php
jalankan 'tests/v2_phase5_performance.php'     env V2_PHASE5_RUN_PERF=1 php tests/v2_phase5_performance.php

echo
echo "Total pemeriksaan lulus: ${lulus_total}"
if [ "$gagal" -eq 0 ]; then
  echo 'SELURUH PENGUJIAN OTOMATIS LULUS.'
  echo
  echo 'BELUM TERCAKUP (wajib tetap ditandai MENUNGGU VERIFIKASI):'
  echo '  - push dan deep link pada perangkat Android/iOS NYATA;'
  echo '  - pengiriman WhatsApp oleh penyedia NYATA (DITANGGUHKAN);'
  echo '  - migrasi, restore, cron, dan smoke test pada cPanel produksi;'
  echo '  - tsc/lint/export aplikasi (dijalankan dari repositori alhasanApps).'
  exit 0
fi
echo "TERDAPAT ${gagal} BERKAS PENGUJIAN YANG GAGAL."
exit 1
