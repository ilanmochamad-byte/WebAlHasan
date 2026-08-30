#!/usr/bin/env bash
# Paket "Koreksi dan Modernisasi UI/UX V1–V2" — menjalankan SELURUH pengujian
# otomatis: regresi V1 dan V2 yang sudah ada, ditambah pengujian baru paket ini.
#
# Regresi lama sengaja ikut dijalankan supaya laporan paket ini tidak
# menyembunyikan kerusakan pada fase sebelumnya.
#
# Prasyarat: lihat docs/perapihan-v1-v2/panduan-audit-codex.md
#   - database uji berakhiran _test dengan migrasi 001–010 sudah dijalankan;
#   - fixture peran   : V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php
#   - fixture performa: V2_PHASE5_FIXTURE=1 php bin/v2_phase5_fixture.php --jumlah=1000
#   - MOBILE_APP_ROOT menunjuk ke folder alhasanApps.
#
# Pemakaian:
#   MOBILE_APP_ROOT=/path/ke/alhasanApps bash bin/perapihan_run_all_tests.sh
#
# Yang TIDAK dicakup skrip ini (dan TIDAK boleh diklaim lulus tanpa bukti):
#   - uji browser sungguhan (jalankan tests/browser/uji-perapihan.mjs terpisah);
#   - Safari fisik pada macOS/iOS;
#   - kedatangan push pada perangkat Android/iOS nyata;
#   - pengiriman WhatsApp oleh penyedia nyata (tetap DITANGGUHKAN);
#   - migrasi, cron, dan smoke test pada cPanel produksi.
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
    printf '%-48s LULUS   (%s pemeriksaan)\n' "$nama" "$n"
  else
    local kode=$?
    if [ "$kode" -eq 77 ]; then
      printf '%-48s DILEWATI\n' "$nama"
      head -2 "$log"
    else
      printf '%-48s GAGAL\n' "$nama"
      grep '\[gagal\]' "$log" | head -10
      tail -3 "$log"
      gagal=$((gagal + 1))
    fi
  fi
  rm -f "$log"
}

echo '=== A. Regresi V1 dan V2 yang sudah ada ==='
bash bin/v2_phase5_run_all_tests.sh
regresi=$?

echo
echo '=== B. Pengujian paket perapihan V1-V2 ==='
jalankan 'tests/perapihan_static.php'            php tests/perapihan_static.php
jalankan 'tests/perapihan_integration.php'       env PERAPIHAN_RUN_INTEGRATION=1 php tests/perapihan_integration.php
jalankan 'tests/perapihan_akun_concurrency.php'  env PERAPIHAN_RUN_CONCURRENCY=1 php tests/perapihan_akun_concurrency.php
jalankan 'tests/perapihan_web_smoke.php'         env PERAPIHAN_RUN_WEB=1 php tests/perapihan_web_smoke.php

echo
echo "Pemeriksaan paket perapihan yang lulus: ${lulus_total}"
if [ "$gagal" -eq 0 ] && [ "$regresi" -eq 0 ]; then
  echo 'SELURUH PENGUJIAN OTOMATIS LULUS (regresi + paket perapihan).'
else
  [ "$regresi" -ne 0 ] && echo 'PERHATIAN: rangkaian regresi V1/V2 melaporkan kegagalan — periksa keluaran bagian A.'
  [ "$gagal" -ne 0 ] && echo "TERDAPAT ${gagal} BERKAS PENGUJIAN PAKET PERAPIHAN YANG GAGAL."
fi

echo
echo 'BELUM DICAKUP OLEH SKRIP INI:'
echo '  - uji browser 1440/768/390 px  : node tests/browser/uji-perapihan.mjs'
echo '  - Safari fisik (macOS/iOS)     : belum diuji, lihat uji-tertunda.md'
echo '  - push dan WhatsApp perangkat  : di luar cakupan paket ini'
echo '  - migrasi dan cron produksi    : dijalankan manusia, bukan di sini'

[ "$gagal" -eq 0 ] && [ "$regresi" -eq 0 ] && exit 0
exit 1
