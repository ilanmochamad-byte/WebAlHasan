#!/usr/bin/env bash
# Paket "Fondasi Penugasan dan Hak Akses Lintas PRD V3–V6" (keputusan pengguna
# 7 September 2026) — menjalankan SELURUH pengujian otomatis: regresi V1/V2,
# paket perapihan, pesan kredensial, penempatan, koreksi alumni, ditambah
# pengujian baru paket ini.
#
# Regresi lama sengaja ikut dijalankan supaya laporan paket ini tidak
# menyembunyikan kerusakan pada paket sebelumnya (kriteria: jadwal, absensi,
# laporan, perizinan V1–V2 tidak mengalami regresi).
#
# Prasyarat:
#   - database uji berakhiran _test dengan migrasi 001-012 sudah dijalankan
#     (paket ini MENAMBAH migrasi 012 — jalankan `php bin/migrate.php up`);
#   - fixture peran   : V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php
#   - fixture performa: V2_PHASE5_FIXTURE=1 php bin/v2_phase5_fixture.php --jumlah=1000
#   - MOBILE_APP_ROOT menunjuk ke folder alhasanApps (bila ada; bila tidak,
#     beberapa pemeriksaan aplikasi mobile pada tests/phase5_static.php akan
#     GAGAL karena berkasnya memang tidak tersedia — itu bukan kegagalan paket ini);
#   - fixture uji browser TIDAK sedang tertinggal di basis data.
#
# Pemakaian:
#   MOBILE_APP_ROOT=/path/ke/alhasanApps bash bin/penugasan_run_all_tests.sh
#   PENUGASAN_SKIP_REGRESI=1 bash bin/penugasan_run_all_tests.sh   # hanya paket ini
#
# Yang TIDAK dicakup skrip ini (dan TIDAK boleh diklaim lulus tanpa bukti):
#   - uji peramban sungguhan 1440/768/390 px;
#   - Safari fisik pada macOS/iOS;
#   - pembaca layar nyata (NVDA/JAWS/VoiceOver);
#   - aplikasi perangkat yang sudah terpasang (hanya kontrak API-nya yang diuji);
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

regresi=0
if [ "${PENUGASAN_SKIP_REGRESI:-0}" = "1" ]; then
  echo '=== A. Regresi V1, V2, perapihan, kredensial, penempatan, alumni: DILEWATI (PENUGASAN_SKIP_REGRESI=1) ==='
else
  echo '=== A. Regresi V1, V2, perapihan, kredensial, penempatan, dan alumni ==='
  bash bin/alumni_run_all_tests.sh
  regresi=$?
fi

echo
echo '=== B. Preflight migrasi 012 (hanya membaca) ==='
if php bin/penugasan_preflight.php; then
  echo 'Preflight penugasan: tidak ada penghalang.'
else
  echo 'PERHATIAN: preflight menemukan penghalang. Selesaikan lebih dahulu.'
  gagal=$((gagal + 1))
fi

echo
echo '=== C. Verifikasi skema setelah migrasi 012 ==='
if php bin/penugasan_verify.php; then
  echo 'Verifikasi migrasi 012: lulus.'
else
  echo 'PERHATIAN: verifikasi migrasi 012 gagal. Jalankan "php bin/migrate.php up" lebih dahulu.'
  gagal=$((gagal + 1))
fi

echo
echo '=== D. Pengujian paket fondasi penugasan V3–V6 ==='
jalankan 'tests/penugasan_static.php'       php tests/penugasan_static.php
jalankan 'tests/penugasan_integration.php'  env PENUGASAN_RUN_INTEGRATION=1 php tests/penugasan_integration.php
jalankan 'tests/penugasan_concurrency.php'  env PENUGASAN_RUN_CONCURRENCY=1 php tests/penugasan_concurrency.php
jalankan 'tests/penugasan_web_smoke.php'    env PENUGASAN_RUN_WEB=1 php tests/penugasan_web_smoke.php

echo
echo "Pemeriksaan paket penugasan yang lulus: ${lulus_total}"
if [ "$gagal" -eq 0 ] && [ "$regresi" -eq 0 ]; then
  echo 'SELURUH PENGUJIAN OTOMATIS LULUS (regresi + paket penugasan).'
else
  [ "$regresi" -ne 0 ] && echo 'PERHATIAN: rangkaian regresi melaporkan kegagalan — periksa keluaran bagian A.'
  [ "$gagal" -ne 0 ] && echo "TERDAPAT ${gagal} BAGIAN PAKET INI YANG GAGAL."
fi

echo
echo 'BELUM DICAKUP OLEH SKRIP INI:'
echo '  - uji peramban 1440/768/390 px  : belum diuji pada paket ini'
echo '  - Safari fisik (macOS/iOS)      : belum diuji'
echo '  - pembaca layar nyata           : belum diuji'
echo '  - aplikasi perangkat terpasang  : hanya kontrak API yang diuji'
echo '  - migrasi dan smoke test cPanel : dijalankan manusia, bukan di sini'

[ "$gagal" -eq 0 ] && [ "$regresi" -eq 0 ] && exit 0
exit 1
