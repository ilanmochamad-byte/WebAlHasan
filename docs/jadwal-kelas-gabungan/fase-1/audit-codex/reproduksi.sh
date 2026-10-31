#!/usr/bin/env bash
# Jalankan berurutan; seluruh DB fixture sintetis terpisah dari sumber .env.
set -euo pipefail
cd "$(dirname "$0")/../../../.."
OUT=docs/jadwal-kelas-gabungan/fase-1/audit-codex
# Suite implementator dijalankan ulang auditor; skrip mencetak exit per suite.
OUT="$OUT/uji" bash docs/jadwal-kelas-gabungan/fase-1/perintah-uji.sh
JKG_TANPA_034=1 JKG_DB=test_jkg_audit_test bash tests/jadwal_kelas_gabungan_f1_db.sh > "$OUT/penyiapan-probe.txt" 2>&1
JKG_DB=test_jkg_audit_test JKG_HASH_OUT="$OUT/hash-tabel-lama.json" php tests/jadwal_kelas_gabungan_f1_audit_probe.php > "$OUT/probe.txt" 2>&1
JKG_DB=test_jkg_audit_test php tests/jadwal_kelas_gabungan_f1_audit_probe.php locks > "$OUT/kunci-terkendali.txt" 2>&1
# Klon baseline detached 22a3645, .env lokal dan vendor harus disiapkan sebelumnya.
: "${JKG_BASELINE:?Isi jalur klon baseline 22a3645}"
export JKG_BASELINE
JKG_DB=test_jkg_audit_test php tests/jadwal_kelas_gabungan_f1_audit_probe.php legacy > "$OUT/kontrol-pemulihan.txt" 2>&1
PFX=jkga OUT="$OUT/regresi" bash docs/jadwal-kelas-gabungan/fase-1/perintah-regresi.sh
php tests/jadwal_kelas_gabungan_f1_static.php > "$OUT/statis-final.txt" 2>&1
