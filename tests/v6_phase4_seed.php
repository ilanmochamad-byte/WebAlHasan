<?php

declare(strict_types=1);

/**
 * Fixture sintetis PRD V6 Fase 4 (paritas aplikasi dan orang tua), di atas
 * fixture V6 Fase 1–3. Hanya untuk DB `test_*_test`.
 *
 * Menambah satu wali dengan DUA anak (SBX V6 Santri SMP dan SBX V6 Santri
 * Tsanawi) — `sbx_v6_wali_dua` — agar dua himpunan data anak yang terpisah
 * dan pencabutan satu relasi dapat diuji. Wali lain (`sbx_v6_wali_smp`,
 * `sbx_v6_wali_tsanawi`) tetap wali anak yang berbeda satu sama lain.
 * Kata sandi sama dengan akun sandbox lain; tanpa data nyata.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$namaDb = (string) app_config('database.database');
if (!preg_match('/^test_.*_test$/', $namaDb)) {
    fwrite(STDERR, 'Seed V6 Fase 4 hanya untuk DB test_*_test, bukan ' . $namaDb . PHP_EOL);
    exit(2);
}
$db = app_db();
$val = static fn (string $sql): mixed => $db->query($sql)?->fetch_row()[0] ?? null;
if ((int) $val("SELECT COUNT(*) FROM users WHERE username = 'sbx_v6_wali_dua'") > 0) {
    fwrite(STDERR, "Fixture V6 Fase 4 sudah ada; bangun ulang DB.\n");
    exit(2);
}
$admin = (int) $val("SELECT id FROM users WHERE username='sbx_admin'");
$hash = (string) $val("SELECT password FROM users WHERE username='sbx_admin'");
$roleOrtu = (int) $val("SELECT id FROM roles WHERE slug='orang_tua'");
$sSmp = (int) $val("SELECT id FROM santri WHERE nis='V6S0001'");
$sTsanawi = (int) $val("SELECT id FROM santri WHERE nis='V6S0002'");
if ($admin < 1 || $roleOrtu < 1 || $sSmp < 1 || $sTsanawi < 1) {
    fwrite(STDERR, "Fixture V6 Fase 1 belum ada.\n");
    exit(2);
}
$db->begin_transaction();
$stmt = $db->prepare("INSERT INTO wali (nama, no_hp, alamat, is_active) VALUES ('SBX V6 Wali Dua Anak', '081200006009', 'Alamat sintetis', 1)");
$stmt->execute();
$wali = (int) $stmt->insert_id;
$relasi = [];
foreach (['smp' => $sSmp, 'tsanawi' => $sTsanawi] as $k => $s) {
    $stmt = $db->prepare("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary, created_by) VALUES (?, ?, 'Ibu', 0, ?)");
    $stmt->bind_param('iii', $s, $wali, $admin);
    $stmt->execute();
    $relasi[$k] = (int) $stmt->insert_id;
}
$stmt = $db->prepare("INSERT INTO users (name, username, password, pengurus_id, wali_id, is_active, force_password_change, created_at) VALUES ('SBX V6 Wali Dua Anak', 'sbx_v6_wali_dua', ?, NULL, ?, 1, 0, NOW())");
$stmt->bind_param('si', $hash, $wali);
$stmt->execute();
$user = (int) $stmt->insert_id;
$stmt = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
$stmt->bind_param('ii', $user, $roleOrtu);
$stmt->execute();
$db->commit();

echo json_encode(['db' => $namaDb, 'wali_dua' => ['user' => $user, 'wali' => $wali, 'relasi' => $relasi]], JSON_PRETTY_PRINT) . PHP_EOL;
