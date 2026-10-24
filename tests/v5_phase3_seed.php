<?php

declare(strict_types=1);

/**
 * Fixture sintetis PRD V5 Fase 3 (ringkasan wali dan notifikasi).
 *
 * Dijalankan oleh `tests/v5_phase3_db.sh` SESUDAH fixture Fase 2 pada DB
 * salinan `test_*_test` yang sudah memuat migrasi 024–026. Menolak DB lain.
 * Akun berawalan `sbx_v5_`, kata sandi `Sandbox#123`, tanpa data pribadi
 * nyata; nomor telepon wali sintetis dipakai untuk membuktikan nomor tidak
 * pernah masuk payload.
 *
 * Menambah (tahun ajaran aktif):
 *   - santri SBX V5 Wali Santri (V5S0201, Kamar A → binaan sbx_v5_pembimbing)
 *     dengan empat wali: Wali Satu (sbx_v5_wali_1), Wali Dua (sbx_v5_wali_2),
 *     Wali Cabut (sbx_v5_wali_cabut, relasi dicabut di tengah uji), dan
 *     Wali Tanpa Akun (master saja);
 *   - santri SBX V5 Santri Lain (V5S0202) dengan Wali Lain (sbx_v5_wali_lain);
 *   - sbx_v5_wali_kosong : akun orang tua tanpa anak berelasi;
 *   - sbx_v5_petugas_f   : pengurus + penugasan kesehatan aktif (penerbit);
 *   - sbx_v5_petugas_g   : pengurus + penugasan kesehatan aktif (dicabut di
 *                          tengah sesi).
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$namaDb = (string) app_config('database.database');
if (!preg_match('/^test_.*_test$/', $namaDb)) {
    fwrite(STDERR, 'Seed V5 Fase 3 hanya untuk DB test_*_test, bukan ' . $namaDb . PHP_EOL);
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
if ((int) $val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kesehatan_ringkasan_penerima'") !== 1) {
    fwrite(STDERR, "Migrasi 026 belum terpasang pada DB ini.\n");
    exit(2);
}
if ((int) $val("SELECT COUNT(*) FROM users WHERE username = 'sbx_v5_pembimbing'") !== 1) {
    fwrite(STDERR, "Fixture V5 Fase 2 belum ada; jalankan tests/v5_phase2_db.sh.\n");
    exit(2);
}
if ((int) $val("SELECT COUNT(*) FROM users WHERE username = 'sbx_v5_wali_1'") > 0) {
    fwrite(STDERR, "Fixture V5 Fase 3 sudah ada; bangun ulang DB.\n");
    exit(2);
}

$db->begin_transaction();
$tahun = (int) $val("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1");
$hash = (string) $val("SELECT password FROM users WHERE username='sbx_admin'");
$admin = (int) $val("SELECT id FROM users WHERE username='sbx_admin'");
$roleOrtu = (int) $val("SELECT id FROM roles WHERE slug='orang_tua'");
$rolePengurus = (int) $val("SELECT id FROM roles WHERE slug='pengurus'");
$kamarA = (int) $val("SELECT id FROM kamar WHERE nama_kamar='SBX V5 Kamar A'");
$mulai = date('Y-m-d', strtotime('-30 days'));

$santri = static fn (string $nis, string $nama): int => $q(
    "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, is_active)
     VALUES (?, ?, 'L', 'Sintetis', '2012-01-01', 'Alamat sintetis', 'Desa', 'Kecamatan', 'Kota', 'Provinsi', 'Ayah sintetis', 'Ibu sintetis', 'Sekolah sintetis', 'Pesantren', 1)",
    'ss', [$nis, $nama]
);
$wali = static fn (string $nama, string $hp): int => $q('INSERT INTO wali (nama, no_hp, alamat, is_active) VALUES (?, ?, ?, 1)', 'sss', [$nama, $hp, 'Alamat sintetis']);
$relasi = static fn (int $s, int $w, string $hubungan, int $utama): int => $q('INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary, created_by) VALUES (?, ?, ?, ?, ?)', 'iisii', [$s, $w, $hubungan, $utama, $admin]);
$akunWali = static function (string $username, string $nama, int $waliId) use ($q, $hash, $roleOrtu): int {
    $id = $q('INSERT INTO users (name, username, password, wali_id, is_active, force_password_change, created_at) VALUES (?,?,?,?,1,0,NOW())', 'sssi', [$nama, $username, $hash, $waliId]);
    $q('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', 'ii', [$id, $roleOrtu]);

    return $id;
};
$petugas = static function (string $username, string $nama) use ($q, $hash, $rolePengurus, $tahun, $mulai, $admin): array {
    $p = $q('INSERT INTO pengurus (nama, jabatan, is_active) VALUES (?, ?, 1)', 'ss', [$nama, 'Fixture V5 Fase 3']);
    $u = $q('INSERT INTO users (name, username, password, pengurus_id, is_active, force_password_change, created_at) VALUES (?,?,?,?,1,0,NOW())', 'sssi', [$nama, $username, $hash, $p]);
    $q('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', 'ii', [$u, $rolePengurus]);
    $t = $q('INSERT INTO kesehatan_assignments (pengurus_id, tahun_ajaran_id, tanggal_mulai, is_active, catatan, created_by, updated_by) VALUES (?, ?, ?, 1, ?, ?, ?)',
        'iissii', [$p, $tahun, $mulai, 'Fixture V5 Fase 3', $admin, $admin]);

    return ['user' => $u, 'pengurus' => $p, 'penugasan' => $t];
};

$sW = $santri('V5S0201', 'SBX V5 Wali Santri');
$sLain = $santri('V5S0202', 'SBX V5 Santri Lain');
$q('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', 'iii', [$sW, $kamarA, $tahun]);

$w1 = $wali('SBX V5 Wali Satu', '081200005301');
$w2 = $wali('SBX V5 Wali Dua', '081200005302');
$wCabut = $wali('SBX V5 Wali Cabut', '081200005303');
$wTanpa = $wali('SBX V5 Wali Tanpa Akun', '081200005304');
$wLain = $wali('SBX V5 Wali Lain', '081200005305');
$wKosong = $wali('SBX V5 Wali Kosong', '081200005306');
$relasi($sW, $w1, 'Ayah', 1);
$relasi($sW, $w2, 'Ibu', 0);
$relasi($sW, $wCabut, 'Wali', 0);
$relasi($sW, $wTanpa, 'Paman', 0);
$relasi($sLain, $wLain, 'Ayah', 1);

$uW1 = $akunWali('sbx_v5_wali_1', 'SBX V5 Wali Satu', $w1);
$uW2 = $akunWali('sbx_v5_wali_2', 'SBX V5 Wali Dua', $w2);
$uCabut = $akunWali('sbx_v5_wali_cabut', 'SBX V5 Wali Cabut', $wCabut);
$uLain = $akunWali('sbx_v5_wali_lain', 'SBX V5 Wali Lain', $wLain);
$uKosong = $akunWali('sbx_v5_wali_kosong', 'SBX V5 Wali Kosong', $wKosong);
$pF = $petugas('sbx_v5_petugas_f', 'SBX V5 Petugas F');
$pG = $petugas('sbx_v5_petugas_g', 'SBX V5 Petugas G');
$db->commit();

echo json_encode([
    'db' => $namaDb,
    'santri' => ['wali' => $sW, 'lain' => $sLain],
    'wali' => compact('w1', 'w2', 'wCabut', 'wTanpa', 'wLain', 'wKosong'),
    'akun' => compact('uW1', 'uW2', 'uCabut', 'uLain', 'uKosong') + ['petugas_f' => $pF, 'petugas_g' => $pG],
    'tahun_ajaran' => $tahun,
], JSON_PRETTY_PRINT) . PHP_EOL;
