<?php

declare(strict_types=1);

/**
 * Fixture sintetis PRD V5 Fase 1 (kesehatan santri).
 *
 * Dijalankan oleh `tests/v5_phase1_db.sh` pada DB salinan `test_*_test`
 * yang sudah memuat migrasi 024. Menolak DB lain. Seluruh akun berawalan
 * `sbx_v5_`, kata sandi sama dengan akun sandbox lain (`Sandbox#123`), dan
 * tidak ada data pribadi nyata.
 *
 * Menambah:
 *   - sbx_v5_petugas        : pengurus TANPA penugasan kesehatan (admin
 *                             menugaskannya lewat pusat penugasan di uji);
 *   - sbx_v5_petugas_b      : pengurus + penugasan kesehatan aktif (skenario
 *                             penugasan diakhiri saat sesi masih aktif);
 *   - sbx_v5_petugas_c      : pengurus + penugasan kesehatan aktif (skenario
 *                             akun dinonaktifkan);
 *   - sbx_v5_admin_petugas  : admin + pengurus + penugasan kesehatan (admin
 *                             tetap tidak boleh mengubah isi klinis);
 *   - santri SBX V5 Klinik (aktif) dan SBX V5 Nonaktif (is_active = 0).
 *
 * Akun lama yang dipakai uji: sbx_admin, sbx_pengurus_biasa (pengurus tanpa
 * penugasan), sbx_pengurus_a (pembimbing), sbx_guru_biasa (guru non-murobi),
 * sbx_murobi_c (murobi), sbx_ortu_a (wali).
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$namaDb = (string) app_config('database.database');
if (!preg_match('/^test_.*_test$/', $namaDb)) {
    fwrite(STDERR, 'Seed V5 Fase 1 hanya untuk DB test_*_test, bukan ' . $namaDb . PHP_EOL);
    exit(2);
}

$db = app_db();
$q = static function (string $sql, string $types = '', array $params = []) use ($db): int {
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error . ' :: ' . $sql);
    }
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return $id;
};
$val = static function (string $sql) use ($db): mixed {
    $row = $db->query($sql)?->fetch_row();

    return $row[0] ?? null;
};
if ((int) $val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kesehatan_kunjungan'") !== 1) {
    fwrite(STDERR, "Migrasi 024 belum terpasang pada DB ini.\n");
    exit(2);
}
if ((int) $val("SELECT COUNT(*) FROM users WHERE username LIKE 'sbx\\_v5\\_%'") > 0) {
    fwrite(STDERR, "Fixture V5 Fase 1 sudah ada; bangun ulang DB.\n");
    exit(2);
}

$db->begin_transaction();
$tahun = (int) $val("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1");
$hash = (string) $val("SELECT password FROM users WHERE username='sbx_admin'");
$admin = (int) $val("SELECT id FROM users WHERE username='sbx_admin'");
$role = static fn (string $slug): int => (int) $val("SELECT id FROM roles WHERE slug='" . $slug . "'");
$mulai = date('Y-m-d', strtotime('-30 days'));

$pengurus = static fn (string $nama): int => $q('INSERT INTO pengurus (nama, jabatan, is_active) VALUES (?, ?, 1)', 'ss', [$nama, 'Klinik (sintetis)']);
$akun = static function (string $username, string $name, array $roles, ?int $pengurusId) use ($q, $hash, $role): int {
    $id = $q('INSERT INTO users (name, username, password, pengurus_id, is_active, force_password_change, created_at) VALUES (?,?,?,?,1,0,NOW())', 'sssi', [$name, $username, $hash, $pengurusId]);
    foreach ($roles as $slug) {
        $q('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', 'ii', [$id, $role($slug)]);
    }

    return $id;
};
$tugaskan = static fn (int $pengurusId): int => $q(
    'INSERT INTO kesehatan_assignments (pengurus_id, tahun_ajaran_id, tanggal_mulai, is_active, catatan, created_by, updated_by) VALUES (?, ?, ?, 1, ?, ?, ?)',
    'iissii', [$pengurusId, $tahun, $mulai, 'Fixture V5 Fase 1', $admin, $admin]
);

$pA = $pengurus('SBX V5 Petugas Klinik');
$pB = $pengurus('SBX V5 Petugas B');
$pC = $pengurus('SBX V5 Petugas C');
$pD = $pengurus('SBX V5 Admin Petugas');
$petugas = $akun('sbx_v5_petugas', 'SBX V5 Petugas Klinik', ['pengurus'], $pA);
$petugasB = $akun('sbx_v5_petugas_b', 'SBX V5 Petugas B', ['pengurus'], $pB);
$petugasC = $akun('sbx_v5_petugas_c', 'SBX V5 Petugas C', ['pengurus'], $pC);
$adminPetugas = $akun('sbx_v5_admin_petugas', 'SBX V5 Admin Petugas', ['admin', 'pengurus'], $pD);
$tugasB = $tugaskan($pB);
$tugasC = $tugaskan($pC);
$tugasD = $tugaskan($pD);

$santri = static fn (string $nis, string $nama, int $aktif): int => $q(
    "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, is_active)
     VALUES (?, ?, 'L', 'Sintetis', '2012-01-01', 'Alamat sintetis', 'Desa', 'Kecamatan', 'Kota', 'Provinsi', 'Ayah sintetis', 'Ibu sintetis', 'Sekolah sintetis', 'Pesantren', ?)",
    'ssi', [$nis, $nama, $aktif]
);
$santriAktif = $santri('V5S0001', 'SBX V5 Santri Klinik', 1);
$santriNonaktif = $santri('V5S0002', 'SBX V5 Santri Nonaktif', 0);
$db->commit();

echo json_encode([
    'db' => $namaDb,
    'akun' => compact('petugas', 'petugasB', 'petugasC', 'adminPetugas'),
    'pengurus' => ['petugas' => $pA, 'petugas_b' => $pB, 'petugas_c' => $pC, 'admin_petugas' => $pD],
    'penugasan' => ['petugas_b' => $tugasB, 'petugas_c' => $tugasC, 'admin_petugas' => $tugasD],
    'santri' => ['aktif' => $santriAktif, 'nonaktif' => $santriNonaktif],
    'tahun_ajaran' => $tahun,
], JSON_PRETTY_PRINT) . PHP_EOL;
