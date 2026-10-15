#!/bin/bash
# Fixture uji redesain Fase 2: akun sbx_murobi_a (murobi) diberi juga peran
# pengurus yang ditautkan ke "P5 Pengurus 01", sehingga punya DUA cakupan izin
# nyata (murobi + pengurus). HANYA untuk database *_test; dapat dipulihkan.
# Pemakaian: DB_NAME=webalhasan_v3_phase1_test bash tests/browser/fixture-redesain-multi-cakupan.sh pasang|pulihkan
set -euo pipefail
DB="${DB_NAME:?Isi DB_NAME}"
case "$DB" in *_test) ;; *) echo "Menolak: DB_NAME harus berakhiran _test" >&2; exit 1;; esac
case "${1:-}" in
  pasang)
    mysql "$DB" <<'SQL'
SET @u := (SELECT id FROM users WHERE username = 'sbx_murobi_a');
SET @r := (SELECT id FROM roles WHERE slug = 'pengurus');
SET @p := (SELECT id FROM pengurus WHERE nama = 'P5 Pengurus 01' AND is_active = 1 AND archived_at IS NULL);
INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (@u, @r);
UPDATE users SET pengurus_id = @p WHERE id = @u AND pengurus_id IS NULL;
SQL
    ;;
  pulihkan)
    mysql "$DB" <<'SQL'
SET @u := (SELECT id FROM users WHERE username = 'sbx_murobi_a');
SET @r := (SELECT id FROM roles WHERE slug = 'pengurus');
DELETE FROM user_roles WHERE user_id = @u AND role_id = @r;
UPDATE users SET pengurus_id = NULL WHERE id = @u;
SQL
    ;;
  *) echo "Argumen: pasang | pulihkan" >&2; exit 1;;
esac
mysql -N "$DB" -e "SELECT u.username, u.pengurus_id, GROUP_CONCAT(r.slug) FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id WHERE u.username='sbx_murobi_a' GROUP BY u.id"
