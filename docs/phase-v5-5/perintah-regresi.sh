#!/usr/bin/env bash
# Regresi PRD V5 Fase 5 (V1–V5) pada DB salinan `test_*_test` berfixture
# sintetis. Jalankan dari akar repo web, BERURUTAN (rantai fixture memakai
# DB perantara bernama tetap). Keluaran per suite di docs/phase-v5-5/bukti/regresi/,
# ringkasan exit/hitungan di ringkasan.txt. Tidak menyentuh DB produksi.
#
#   bash docs/phase-v5-5/perintah-regresi.sh
#
# Browser dan aplikasi Expo dijalankan terpisah (lihat README) karena
# memerlukan server PHP/Expo yang hidup.
set -u
cd "$(dirname "$0")/../.."
OUT=docs/phase-v5-5/bukti/regresi
mkdir -p "$OUT"
RINGKAS="$OUT/ringkasan.txt"
: > "$RINGKAS"

hitung() { # berkas → "lulus/gagal"
  local f="$1" l g
  l=$(grep -c '^\[lulus\]' "$f" 2>/dev/null); g=$(grep -c '^\[gagal\]' "$f" 2>/dev/null)
  echo "${l:-0}/${g:-0}"
}
jalan() { # nama perintah…
  local nama="$1"; shift
  "$@" > "$OUT/$nama.txt" 2>&1
  local kode=$?
  echo "$nama exit=$kode $(hitung "$OUT/$nama.txt")" | tee -a "$RINGKAS"
}
db() { "$@" > /dev/null 2>&1 || echo "PENYIAPAN GAGAL: $*" | tee -a "$RINGKAS"; }

echo "== Statis ==" | tee -a "$RINGKAS"
for f in tests/*_static.php; do
  n=$(basename "$f" .php)
  jalan "statis-$n" php "$f"
done

echo "== V5 Fase 5 ==" | tee -a "$RINGKAS"
db bash tests/v5_phase5_db.sh
jalan v5-fase5-integrasi env V5_RUN_INTEGRATION=1 DB_NAME=test_v5_f5_test php tests/v5_phase5_integration.php
db env V5F5_DB=test_v5_f5_http_test bash tests/v5_phase5_db.sh
jalan v5-fase5-http env V5F5_DB=test_v5_f5_http_test php tests/v5_phase5_http.php
jalan v5-fase5-rilis env V5_RUN_RILIS=1 php tests/v5_phase5_rilis.php

echo "== V5 Fase 1–4 ==" | tee -a "$RINGKAS"
db bash tests/v5_phase1_db.sh
jalan v5-fase1-integrasi env V5_RUN_INTEGRATION=1 php tests/v5_phase1_integration.php
db env V5F1_DB=test_v5_f1_http_test bash tests/v5_phase1_db.sh
jalan v5-fase1-http env V5F1_DB=test_v5_f1_http_test php tests/v5_phase1_http.php
db bash tests/v5_phase2_db.sh
jalan v5-fase2-integrasi env V5_RUN_INTEGRATION=1 php tests/v5_phase2_integration.php
db env V5F2_DB=test_v5_f2_http_test bash tests/v5_phase2_db.sh
jalan v5-fase2-http env V5F2_DB=test_v5_f2_http_test php tests/v5_phase2_http.php
db env V5F2_DB=test_codex_v5_f2_test bash tests/v5_phase2_db.sh
jalan v5-fase2-audit-probe env V5F2_DB=test_codex_v5_f2_test V5_RUN_AUDIT=1 php tests/v5_phase2_audit.php
jalan v5-fase2-migrasi env V5_RUN_MIGRATION=1 php tests/v5_phase2_migration.php
db bash tests/v5_phase3_db.sh
jalan v5-fase3-integrasi env V5_RUN_INTEGRATION=1 php tests/v5_phase3_integration.php
db env V5F3_DB=test_v5_f3_http_test bash tests/v5_phase3_db.sh
jalan v5-fase3-http env V5F3_DB=test_v5_f3_http_test php tests/v5_phase3_http.php
db env V5F3_DB=test_codex_v5_f3_probe_test bash tests/v5_phase3_db.sh
jalan v5-fase3-audit-probe env V5F3_DB=test_codex_v5_f3_probe_test V5_RUN_AUDIT=1 php tests/v5_phase3_audit.php
jalan v5-fase3-migrasi env V5_RUN_MIGRATION=1 php tests/v5_phase3_migration.php
db env V5F3_DB=test_v5_f4_http_test bash tests/v5_phase3_db.sh
jalan v5-fase4-http env V5F4_DB=test_v5_f4_http_test php tests/v5_phase4_http.php

echo "== Fondasi, V2 ==" | tee -a "$RINGKAS"
db env V5F5_DB=test_v5_f5_regresi_test bash tests/v5_phase5_db.sh
jalan penugasan-integrasi env PENUGASAN_RUN_INTEGRATION=1 DB_NAME=test_v5_f5_regresi_test php tests/penugasan_integration.php
jalan v2-fase4-integrasi env V2_PHASE4_RUN_INTEGRATION=1 DB_NAME=test_v5_f5_regresi_test php tests/v2_phase4_integration.php
jalan v2-fase5-integrasi env V2_PHASE5_RUN_INTEGRATION=1 DB_NAME=test_v5_f5_regresi_test php tests/v2_phase5_integration.php
# Kontrol V2 Fase 5 (pola audit Tahap 4/Fase 3): salinan baru, antrean Push/WhatsApp fixture lama ditunda 1 hari; kode dan suite tidak diubah.
db env V5F5_DB=test_v5_f5_kontrol_test bash tests/v5_phase5_db.sh
DB_NAME=test_v5_f5_kontrol_test php tests/v5_phase4_sql.php "UPDATE notifikasi_outbox SET tersedia_pada = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE kanal <> 'InApp' AND status IN ('Queued','Failed') AND gagal_permanen = 0" > /dev/null
jalan v2-fase5-integrasi-kontrol env V2_PHASE5_RUN_INTEGRATION=1 DB_NAME=test_v5_f5_kontrol_test php tests/v2_phase5_integration.php

echo "== Redesain web (HTTP) ==" | tee -a "$RINGKAS"
db bash tests/redesain_web_tahap2_db.sh
jalan redesain-tahap2-http php tests/redesain_web_tahap2_http.php
db bash tests/redesain_web_tahap3_db.sh
jalan redesain-tahap3-http php tests/redesain_web_tahap3_http.php
db bash tests/redesain_web_tahap5_db.sh
jalan redesain-tahap5-http php tests/redesain_web_tahap5_http.php
db bash tests/redesain_web_tahap7_db.sh
jalan redesain-tahap7-http php tests/redesain_web_tahap7_http.php
# Tahap 6 mengasumsikan 023 migrasi terakhir: lepas 026 → 025 → 024 kosong dengan preflight (kini juga penjaga SQL).
db bash tests/redesain_web_tahap6_db.sh
T6=$(grep -oE 'TAHAP6_DB:-[a-z0-9_]+' tests/redesain_web_tahap6_db.sh | cut -d- -f2)
{ for f in v5_phase3 v5_phase2 v5_phase1; do DB_NAME="$T6" php "bin/${f}_rollback_preflight.php" && DB_NAME="$T6" php bin/migrate.php rollback; done; } > "$OUT/redesain-tahap6-rollback-026-025-024.txt" 2>&1
echo "redesain-tahap6-rollback exit=$? $(hitung "$OUT/redesain-tahap6-rollback-026-025-024.txt")" | tee -a "$RINGKAS"
jalan redesain-tahap6-http-tanpa-024-026 php tests/redesain_web_tahap6_http.php

echo "SELESAI" | tee -a "$RINGKAS"
