<?php

declare(strict_types=1);

/**
 * Seed uji aplikasi Fase 2 profil pengguna (PRD-PROFIL-IMPORT) di atas DB
 * `tests/profil_impor_f1_db.sh`. Seluruh data sintetis.
 *
 *   PIF1_DB=test_pif2_app_test bash tests/profil_impor_f1_db.sh
 *   PIF2_DB=test_pif2_app_test php tests/profil_impor_f2_app_seed.php > /tmp/pif2-ids.json
 *
 * - sbx_murobi_c   : password sementara `Sementara#2026A`, wajib diganti (PI-1)
 * - sbx_v5_ganda    : nama sangat panjang (guru + pengurus, empat status)
 * - sbx_admin       : email terisi (uji email milik akun lain → 409)
 * Keluaran JSON: id akun dan id penugasan Murobi sbx_murobi_a (untuk uji pencabutan).
 */

$db = getenv('PIF2_DB') ?: '';
if (!preg_match('/^test_.*_test$/', $db)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $db);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$conn = app_db();
$satu = static function (string $sql) use ($conn): mixed {
    $r = $conn->query($sql);
    if ($r === false) {
        throw new RuntimeException($conn->error);
    }
    return $r->fetch_row()[0] ?? null;
};
$id = static fn (string $u): int => (int) $satu("SELECT id FROM users WHERE username = '" . $conn->real_escape_string($u) . "'");

$wajib = $id('sbx_murobi_c');
$stmt = $conn->prepare('UPDATE users SET password = ?, force_password_change = 1 WHERE id = ?');
$hash = password_hash('Sementara#2026A', PASSWORD_DEFAULT);
$stmt->bind_param('si', $hash, $wajib);
$stmt->execute();

$ganda = $id('sbx_v5_ganda');
profil_service()->ubahDiri(['id' => $ganda], ['nama' => 'SBX V5 Ganda Muhammad Abdurrahman Nurhidayatullah Al-Fatih Ramadhan Syarifuddin']);
$admin = $id('sbx_admin');
profil_service()->ubahDiri(['id' => $admin], ['email' => 'admin.pif2@sbx.test']);

$guruMurobi = (int) $satu("SELECT guru_id FROM users WHERE username = 'sbx_murobi_a'");
$murobiIds = [];
$r = $conn->query('SELECT id FROM murobi_assignments WHERE guru_id = ' . $guruMurobi . ' AND is_active = 1 ORDER BY id');
while ($row = $r->fetch_row()) {
    $murobiIds[] = (int) $row[0];
}

echo json_encode([
    'admin' => $admin, 'wajib' => $wajib, 'ganda' => $ganda,
    'murobi_a' => $id('sbx_murobi_a'), 'pengurus_a' => $id('sbx_pengurus_a'), 'pengurus_b' => $id('sbx_pengurus_b'),
    'ortu_a' => $id('sbx_ortu_a'), 'guru_mapel' => $id('sbx_guru_mapel'),
    'murobi_assignment_ids' => $murobiIds,
], JSON_PRETTY_PRINT) . PHP_EOL;
