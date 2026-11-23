#!/usr/bin/env bash
# Uji baru status "Izin" otomatis (migrasi 036) — statis, integrasi (K-1…K-6),
# migrasi naik–rollback–naik + K-7, HTTP web/REST (K-4). Untuk kontrol baseline:
#   KODE=/jalur/arsip-baseline OUT=… PFX=iopkb bash docs/izin-otomatis-pengajian/implementasi/perintah-uji.sh
# (arsip baseline berisi salinan berkas uji baru; DB uji diberi SQL 036 agar
#  kontrol menguji kode, bukan skema). Jalankan BERURUTAN.
set -u
SKRIP_DIR="$(cd "$(dirname "$0")" && pwd)"
KODE="${KODE:-$(cd "$SKRIP_DIR/../../.." && pwd)}"
OUT="${OUT:-$SKRIP_DIR/bukti}"
PFX="${PFX:-iopk}"
cd "$KODE" || exit 2
mkdir -p "$OUT"
jkg() { JKG_DB="$1" bash tests/jadwal_kelas_gabungan_f1_db.sh > /dev/null; }
php tests/izin_otomatis_pengajian_static.php > "$OUT/statis.txt" 2>&1; echo "statis exit=$? $(tail -1 "$OUT/statis.txt")"
jkg test_${PFX}_int_test
IOP_DB=test_${PFX}_int_test php tests/izin_otomatis_pengajian_integration.php > "$OUT/integrasi.txt" 2>&1; echo "integrasi exit=$? $(tail -1 "$OUT/integrasi.txt")"
jkg test_${PFX}_mig_test
IOP_DB=test_${PFX}_mig_test php tests/izin_otomatis_pengajian_migration.php > "$OUT/migrasi.txt" 2>&1; echo "migrasi exit=$? $(tail -1 "$OUT/migrasi.txt")"
jkg test_${PFX}_http_test
IOP_DB=test_${PFX}_http_test php tests/izin_otomatis_pengajian_http.php > "$OUT/http.txt" 2>&1; echo "http exit=$? $(tail -1 "$OUT/http.txt")"
