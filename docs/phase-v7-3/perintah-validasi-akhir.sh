#!/usr/bin/env bash
# Validasi akhir setelah F-3; jalankan sesudah runner regresi selesai.
# Arsip baseline bukan worktree. .env hanya disalin lokal dan dihapus saat keluar.
set -u
cd "$(dirname "$0")/../.."
OUT=docs/phase-v7-3/audit-codex
mkdir -p "$OUT/regresi"
AUDIT_V7_SRC=$(mktemp -d /tmp/v7-f3-kontrol-XXXXXX)
trap 'rm -f "$AUDIT_V7_SRC/.env"' EXIT
git archive 96dc827 app bin tests database | tar -x -C "$AUDIT_V7_SRC" || exit 1
cp .env "$AUDIT_V7_SRC/.env"
mkdir -p "$AUDIT_V7_SRC/storage/private/v4"
ln -s "$PWD/vendor" "$AUDIT_V7_SRC/vendor"
V7F1_DB=test_v7_f3_baselinef1_test bash "$AUDIT_V7_SRC/tests/v7_phase1_db.sh" > /dev/null || exit 1
V7F1_DB=test_v7_f3_baselinef1_test php "$AUDIT_V7_SRC/tests/v7_phase1_integration.php" > "$OUT/regresi/v7-fase1-kontrol-baseline-96dc827.txt" 2>&1
echo "kontrol-baseline-fase1 exit=$?"
bash docs/phase-v7-3/perintah-audit.sh
V7F2_DB=test_v7_f3_finalf2_test bash tests/v7_phase2_db.sh > /dev/null || exit 1
V7F2_DB=test_v7_f3_finalf2_test php tests/v7_phase2_integration.php > "$OUT/regresi/v7-fase2-integrasi-sesudah-f3.txt" 2>&1; echo "fase2-integrasi-sesudah-f3 exit=$?"
V7_RUN_AUDIT=1 DB_NAME=test_v7_f3_finalf2_test php tests/v7_phase2_audit.php > "$OUT/regresi/v7-fase2-probe-sesudah-f3.txt" 2>&1; echo "fase2-probe-sesudah-f3 exit=$?"
for v in 2 3; do DB_NAME=test_v7_f3_finalf2_test php "bin/v7_phase${v}_verify.php" > "$OUT/regresi/v7-fase${v}-verify-sesudah-f3.txt" 2>&1; echo "verifier-fase$v-sesudah-f3 exit=$?"; done
