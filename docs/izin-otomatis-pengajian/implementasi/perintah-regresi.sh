#!/usr/bin/env bash
# Regresi K-8 status "Izin" otomatis (migrasi 036): kelas gabungan, perbaikan
# peserta absensi, pencarian santri, Keamanan Fase 3/4, izin V2/V7, rapor V4,
# HTTP redesain Tahap 4/7, dan seluruh suite statis — DB salinan `test_*_test`
# berfixture sintetis.
#
#   bash docs/izin-otomatis-pengajian/implementasi/perintah-regresi.sh
#   KODE=/jalur/arsip-baseline OUT=/jalur/keluaran PFX=iopb bash docs/izin-otomatis-pengajian/implementasi/perintah-regresi.sh
#
# KODE  pohon kode yang diuji (bawaan: repo ini); untuk kontrol baseline,
#       arahkan ke `git archive origin/main` yang berisi .env dan vendor/.
# Jalankan BERURUTAN; tidak bersamaan dengan skrip lain yang membangun DB.
set -u
SKRIP_DIR="$(cd "$(dirname "$0")" && pwd)"
KODE="${KODE:-$(cd "$SKRIP_DIR/../../.." && pwd)}"
OUT="${OUT:-$SKRIP_DIR/bukti/regresi}"
PFX="${PFX:-iop}"
cd "$KODE" || exit 2
mkdir -p "$OUT"
RINGKAS="$OUT/ringkasan.txt"
: > "$RINGKAS"
hitung() { local f="$1" l g; l=$(grep -cE '^ *\[(lulus|OK|ok|PASS)\]|^ *✓|^PASS|^ok ' "$f" 2>/dev/null); g=$(grep -cE '^ *\[(gagal|GAGAL|FAIL)\]|^ *✗|^FAIL|^not ok ' "$f" 2>/dev/null); echo "${l:-0}/${g:-0}"; }
jalan() { local nama="$1"; shift; "$@" > "$OUT/$nama.txt" 2>&1; local kode=$?; echo "$nama exit=$kode $(hitung "$OUT/$nama.txt")" | tee -a "$RINGKAS"; }
db() { "$@" > /dev/null 2>&1 || echo "PENYIAPAN GAGAL: $*" | tee -a "$RINGKAS"; }
jkg() { db env JKG_DB="$1" bash tests/jadwal_kelas_gabungan_f1_db.sh; }

echo "== Kelas gabungan Fase 1 ==" | tee -a "$RINGKAS"
jkg test_${PFX}_jkg_test;  jalan jkg-integrasi env JKG_DB=test_${PFX}_jkg_test php tests/jadwal_kelas_gabungan_f1_integration.php
jkg test_${PFX}_jkgh_test; jalan jkg-http env JKG_DB=test_${PFX}_jkgh_test php tests/jadwal_kelas_gabungan_f1_http.php
jkg test_${PFX}_jkga_test; jalan jkg-probe-audit env JKG_DB=test_${PFX}_jkga_test php tests/jadwal_kelas_gabungan_f1_audit_probe.php
db env JKG_TANPA_034=1 JKG_DB=test_${PFX}_jkgm_test bash tests/jadwal_kelas_gabungan_f1_db.sh
jalan jkg-migrasi env JKG_DB=test_${PFX}_jkgm_test php tests/jadwal_kelas_gabungan_f1_migration.php

echo "== Perbaikan peserta absensi ==" | tee -a "$RINGKAS"
jkg test_${PFX}_ppa_test;  jalan ppa-integrasi env PPA_DB=test_${PFX}_ppa_test php tests/perbaikan_peserta_absensi_integration.php
jkg test_${PFX}_ppak_test; jalan ppa-konkurensi env PPA_DB=test_${PFX}_ppak_test php tests/perbaikan_peserta_absensi_konkurensi.php
jkg test_${PFX}_ppaa_test; jalan ppa-probe-audit env PPA_DB=test_${PFX}_ppaa_test php tests/perbaikan_peserta_absensi_audit.php

echo "== Pencarian santri ==" | tee -a "$RINGKAS"
db env PKF3_DB=test_${PFX}_pcs_test bash tests/penugasan_keamanan_f4_db.sh
jalan pcs-http env PKF3_DB=test_${PFX}_pcs_test php tests/pencarian_santri_http.php
db env PKF3_DB=test_${PFX}_pcsa_test bash tests/penugasan_keamanan_f4_db.sh
db env PKF3_DB=test_${PFX}_pcsa_test php tests/penugasan_keamanan_f3_seed.php
jalan pcs-probe-audit env DB_NAME=test_${PFX}_pcsa_test php tests/pencarian_santri_audit.php

echo "== Keamanan Fase 3/4 ==" | tee -a "$RINGKAS"
db env PKF3_DB=test_${PFX}_pk3_test bash tests/penugasan_keamanan_f3_db.sh;  jalan pk-fase3-integrasi env PKF3_DB=test_${PFX}_pk3_test php tests/penugasan_keamanan_f3_integration.php
db env PKF3_DB=test_${PFX}_pk3h_test bash tests/penugasan_keamanan_f3_db.sh; jalan pk-fase3-http env PKF3_DB=test_${PFX}_pk3h_test php tests/penugasan_keamanan_f3_http.php
db env PKF3_DB=test_${PFX}_pk3a_test bash tests/penugasan_keamanan_f3_db.sh; jalan pk-fase3-probe-audit env PKF3_DB=test_${PFX}_pk3a_test php tests/penugasan_keamanan_f3_audit_probe.php
db env PKF3_DB=test_${PFX}_pk4_test bash tests/penugasan_keamanan_f4_db.sh;  jalan pk-fase4-http env PKF3_DB=test_${PFX}_pk4_test php tests/penugasan_keamanan_f4_http.php

echo "== Izin V7 Fase 1–5 dan V2 ==" | tee -a "$RINGKAS"
db env V7F5_DB=test_${PFX}_v7f5_test bash tests/v7_phase5_db.sh;   jalan v7-fase5-e2e env V7F5_DB=test_${PFX}_v7f5_test php tests/v7_phase5_e2e.php
db env V7F5_DB=test_${PFX}_v7f5h_test bash tests/v7_phase5_db.sh;  jalan v7-fase5-http env V7F5_DB=test_${PFX}_v7f5h_test V7F5_PORT=8977 php tests/v7_phase5_http.php
db env V7F4_DB=test_${PFX}_v7f4_test bash tests/v7_phase4_db.sh;   jalan v7-fase4-http env V7F4_DB=test_${PFX}_v7f4_test php tests/v7_phase4_http.php
db env V7F3_DB=test_${PFX}_v7f3_test bash tests/v7_phase3_db.sh;   jalan v7-fase3-integrasi env V7F3_DB=test_${PFX}_v7f3_test php tests/v7_phase3_integration.php
db env V7F2_DB=test_${PFX}_v7f2_test bash tests/v7_phase2_db.sh;   jalan v7-fase2-integrasi env V7F2_DB=test_${PFX}_v7f2_test php tests/v7_phase2_integration.php
db env V7F1_DB=test_${PFX}_v7f1_test bash tests/v7_phase1_db.sh;   jalan v7-fase1-integrasi env V7F1_DB=test_${PFX}_v7f1_test php tests/v7_phase1_integration.php
for n in 1 2 3; do db env V7F1_DB=test_${PFX}_reg${n}_test bash tests/v7_phase1_db.sh; done
jalan v2-fase4-integrasi env V2_PHASE4_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_reg1_test php tests/v2_phase4_integration.php
jalan v2-fase5-integrasi env V2_PHASE5_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_reg1_test php tests/v2_phase5_integration.php
jalan v2-fase1-integrasi env V2_PHASE1_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_reg2_test php tests/v2_phase1_integration.php
jalan v2-fase2-integrasi env V2_PHASE2_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_reg3_test php tests/v2_phase2_integration.php

echo "== V1 pengajian/laporan, redesain HTTP Tahap 4 (kehadiran & cetak) dan Tahap 7 (rapor V4) ==" | tee -a "$RINGKAS"
db env PKF3_DB=test_${PFX}_v1a_test bash tests/penugasan_keamanan_f3_db.sh
jalan v1-fase3-integrasi env PHASE3_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_v1a_test php tests/phase3_integration.php
jalan v1-fase4-integrasi env PHASE4_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_v1a_test php tests/phase4_integration.php
db env PKF3_DB=test_${PFX}_v1b_test bash tests/penugasan_keamanan_f3_db.sh
jalan v1-fase5-integrasi env PHASE5_RUN_INTEGRATION=1 DB_NAME=test_${PFX}_v1b_test php tests/phase5_integration.php
db bash tests/redesain_web_tahap4_db.sh; jalan redesain-tahap4-http php tests/redesain_web_tahap4_http.php
db bash tests/redesain_web_tahap7_db.sh; jalan redesain-tahap7-http php tests/redesain_web_tahap7_http.php

echo "== Statis (seluruh suite) ==" | tee -a "$RINGKAS"
for f in tests/*_static.php; do jalan "statis-$(basename "$f" .php)" php "$f"; done
echo "SELESAI" | tee -a "$RINGKAS"
