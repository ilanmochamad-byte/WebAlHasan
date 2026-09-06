#!/usr/bin/env bash
# Fitur "Pesan Kredensial Akun Siap Salin" (keputusan pengguna 6 September 2026)
# — menjalankan SELURUH pengujian otomatis: regresi V1/V2 dan paket perapihan
# yang sudah ada, ditambah pengujian baru fitur ini.
#
# Regresi lama sengaja ikut dijalankan supaya laporan fitur ini tidak
# menyembunyikan kerusakan pada paket sebelumnya.
#
# Prasyarat (lihat docs/perapihan-v1-v2/panduan-audit-codex.md):
#   - database uji berakhiran _test dengan migrasi 001-010 sudah dijalankan;
#   - pengguna basis data uji boleh membuat database (untuk drill restore);
#   - fixture peran   : V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php
#   - fixture performa: V2_PHASE5_FIXTURE=1 php bin/v2_phase5_fixture.php --jumlah=1000
#   - MOBILE_APP_ROOT menunjuk ke folder alhasanApps.
#
# Pemakaian:
#   MOBILE_APP_ROOT=/path/ke/alhasanApps bash bin/kredensial_run_all_tests.sh
#
# Yang TIDAK dicakup skrip ini (dan TIDAK boleh diklaim lulus tanpa bukti):
#   - uji browser sungguhan (jalankan tests/browser/uji-kredensial.mjs terpisah);
#   - Safari fisik pada macOS/iOS, termasuk perilaku papan klipnya;
#   - pembaca layar nyata (NVDA/JAWS/VoiceOver);
#   - pengiriman email oleh admin (memang di luar sistem, manual).
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 2
MOBILE_APP_ROOT="${MOBILE_APP_ROOT:-$(cd "$ROOT/../../../alhasanApps" 2>/dev/null && pwd)}"
export MOBILE_APP_ROOT
export CHROMIUM_PATH="${CHROMIUM_PATH:-}"

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

echo '=== A. Regresi V1, V2, dan paket perapihan ==='
bash bin/perapihan_run_all_tests.sh
regresi=$?

echo
echo '=== B. Pengujian fitur pesan kredensial ==='
jalankan 'tests/kredensial_static.php'       php tests/kredensial_static.php
jalankan 'tests/kredensial_integration.php'  env KREDENSIAL_RUN_INTEGRATION=1 php tests/kredensial_integration.php
jalankan 'tests/kredensial_web_smoke.php'    env KREDENSIAL_RUN_WEB=1 php tests/kredensial_web_smoke.php

echo
echo "Pemeriksaan fitur pesan kredensial yang lulus: ${lulus_total}"
if [ "$gagal" -eq 0 ] && [ "$regresi" -eq 0 ]; then
  echo 'SELURUH PENGUJIAN OTOMATIS LULUS (regresi + paket perapihan + pesan kredensial).'
else
  [ "$regresi" -ne 0 ] && echo 'PERHATIAN: rangkaian regresi melaporkan kegagalan — periksa keluaran bagian A.'
  [ "$gagal" -ne 0 ] && echo "TERDAPAT ${gagal} BERKAS PENGUJIAN FITUR INI YANG GAGAL."
fi

echo
echo 'BELUM DICAKUP OLEH SKRIP INI:'
echo '  - uji browser desktop/ponsel   : lihat docs/perapihan-v1-v2/pesan-kredensial-akun.md'
echo '  - Safari fisik (macOS/iOS)     : belum diuji'
echo '  - pembaca layar nyata          : belum diuji'
echo '  - penempelan pesan ke email    : dilakukan admin secara manual'

[ "$gagal" -eq 0 ] && [ "$regresi" -eq 0 ] && exit 0
exit 1
