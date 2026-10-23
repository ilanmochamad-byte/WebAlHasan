<?php

declare(strict_types=1);

/**
 * Fixture sintetis redesain website Tahap 6 — data pesantren & penugasan.
 *
 * Dijalankan oleh `tests/redesain_web_tahap6_db.sh` SETELAH migrasi 023 pada
 * DB salinan `test_*_test`. Menolak DB lain. Tidak memakai data pribadi.
 *
 * Menambah:
 *   - asrama kamar: SBX Kamar A/B = Putra, SBX Kamar C = Putri; kamar P5
 *     dibiarkan "Belum ditentukan" (keputusan AE);
 *   - santri putri tanpa kamar "SBX T6 Putri" dan santri "SBX T6 Lulus"
 *     yang diluluskan lewat `AlumniService` (D-8/D-9);
 *   - dua pasangan kemungkinan ganda wali (nama sama; HP sama) untuk D-7,
 *     ditambah satu pasangan yang KEDUA-nya punya akun (diblokir);
 *   - santri "SBX T6 Luar" di kamar P5 (di luar cakupan pembimbing A).
 *
 * Cakupan binaan memakai fixture Tahap 2–5: sbx_pengurus_a membina Kamar A
 * dan C, sbx_pengurus_b membina Kamar A dan B, sbx_murobi_c membina Kelas 1.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$namaDb = (string) app_config('database.database');
if (!preg_match('/^test_.*_test$/', $namaDb)) {
    fwrite(STDERR, 'Seed Tahap 6 hanya untuk DB test_*_test, bukan ' . $namaDb . PHP_EOL);
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

if ((int) $val("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kamar' AND COLUMN_NAME = 'asrama'") !== 1) {
    fwrite(STDERR, "Migrasi 023 belum dijalankan.\n");
    exit(2);
}

$admin = (int) $val("SELECT id FROM users WHERE username='sbx_admin'");
$tahun = (int) $val("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1");
$hash = (string) $val("SELECT password FROM users WHERE username='sbx_admin'");
$roleOrtu = (int) $val("SELECT id FROM roles WHERE slug='orang_tua'");
$_SESSION['user_id'] = $admin;

// --- Asrama kamar (AE) ------------------------------------------------------
$q("UPDATE kamar SET asrama = 'Putra' WHERE nama_kamar IN ('SBX Kamar A', 'SBX Kamar B')");
$q("UPDATE kamar SET asrama = 'Putri' WHERE nama_kamar = 'SBX Kamar C'");
$kamarA = (int) $val("SELECT id FROM kamar WHERE nama_kamar = 'SBX Kamar A'");
$kamarP5 = (int) $val("SELECT id FROM kamar WHERE nama_kamar LIKE 'P5 Kamar%' ORDER BY id LIMIT 1");

// --- Santri tambahan ---------------------------------------------------------
$santri = static fn (string $nis, string $nama, string $jk): int => $q(
    "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, is_active)
     VALUES (?, ?, ?, 'Kota Uji', '2013-02-02', 'Alamat uji', 'Desa', 'Kecamatan', 'Kabupaten', 'Provinsi', 'Ayah Uji', 'Ibu Uji', 'SD Uji', 'Tsanawi', 1)",
    'sss', [$nis, $nama, $jk]
);
$putri = $santri('SBX-T6-PUTRI', 'SBX T6 Putri', 'P');
$luar = $santri('SBX-T6-LUAR', 'SBX T6 Luar', 'L');
$q('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', 'iii', [$luar, $kamarP5, $tahun]);
$lulus = $santri('SBX-T6-LULUS', 'SBX T6 Lulus', 'L');
$q('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', 'iii', [$lulus, $kamarA, $tahun]);
$alumni = alumni_service()->terapkan([$lulus], [
    'status_keluar' => 'Lulus', 'tgl_keluar' => date('Y-m-d'), 'tahun_angkatan' => '2026', 'tingkat' => 'Tsanawi', 'catatan' => 'Fixture Tahap 6',
], $admin);

// --- Wali kemungkinan ganda (D-7) --------------------------------------------
$wali = static fn (string $nama, ?string $hp): int => $q('INSERT INTO wali (nama, no_hp, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())', 'ss', [$nama, $hp]);
$gandaNama1 = $wali('SBX Ganda Nama', '081260000001');
$gandaNama2 = $wali('sbx ganda nama ', '081260000002');
$gandaHp1 = $wali('SBX Ganda HP Satu', '081260000099');
$gandaHp2 = $wali('SBX Ganda HP Dua', '081260000099');
$q('INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary, created_by) VALUES (?, ?, ?, 1, ?)', 'iisi', [$putri, $gandaNama1, 'Ayah', $admin]);
$q('INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary, created_by) VALUES (?, ?, ?, 0, ?)', 'iisi', [$luar, $gandaNama2, 'Ayah', $admin]);
// Pasangan yang kedua sisinya punya akun: penggabungan diblokir.
$akunA = $wali('SBX Ganda Akun', '081260000077');
$akunB = $wali('SBX Ganda Akun', '081260000078');
foreach ([[$akunA, 'sbx_t6_ortu_a'], [$akunB, 'sbx_t6_ortu_b']] as [$wid, $username]) {
    $uid = $q('INSERT INTO users (name, username, password, wali_id, is_active, force_password_change) VALUES (?, ?, ?, ?, 1, 0)', 'sssi', [$username, $username, $hash, $wid]);
    $q('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', 'ii', [$uid, $roleOrtu]);
}

echo json_encode([
    'santri' => ['putri' => $putri, 'luar' => $luar, 'lulus' => $lulus],
    'alumni' => $alumni['alumni_id'] ?? null,
    'wali' => ['ganda_nama' => [$gandaNama1, $gandaNama2], 'ganda_hp' => [$gandaHp1, $gandaHp2], 'ganda_akun' => [$akunA, $akunB]],
    'kamar' => ['a' => $kamarA, 'p5' => $kamarP5],
], JSON_PRETTY_PRINT) . PHP_EOL;
