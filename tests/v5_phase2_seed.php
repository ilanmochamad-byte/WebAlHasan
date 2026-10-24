<?php

declare(strict_types=1);

/**
 * Fixture sintetis PRD V5 Fase 2 (obat, rujukan, koreksi, pembaca internal).
 *
 * Dijalankan oleh `tests/v5_phase2_db.sh` SESUDAH fixture Fase 1 pada DB
 * salinan `test_*_test` yang sudah memuat migrasi 024 dan 025. Menolak DB
 * lain. Seluruh akun berawalan `sbx_v5_`, kata sandi sama dengan akun sandbox
 * lain (`Sandbox#123`), tanpa data pribadi nyata. Kamar/kelas/santri baru
 * khusus fixture ini sehingga cakupan binaan tidak tercampur fixture lama.
 *
 * Menambah (tahun ajaran aktif):
 *   - Kamar "SBX V5 Kamar A" dan "SBX V5 Kamar B", kelas "SBX V5 Kelas X"
 *     dan "SBX V5 Kelas Kosong";
 *   - santri SBX V5 Binaan A (Kamar A + Kelas X), SBX V5 Binaan B (Kamar B),
 *     SBX V5 Luar Cakupan (tanpa penempatan);
 *   - sbx_v5_pembimbing    : pengurus, pembimbing Kamar A;
 *   - sbx_v5_pembimbing_b  : pengurus, pembimbing Kamar B;
 *   - sbx_v5_murobi        : guru, murobi Kelas X;
 *   - sbx_v5_murobi_kosong : guru, murobi Kelas Kosong (tanpa santri);
 *   - sbx_v5_ganda         : pengurus + guru, pembimbing Kamar B + murobi Kelas X;
 *   - sbx_v5_petugas_d     : pengurus + penugasan kesehatan aktif (petugas kedua
 *                            untuk koreksi bersamaan);
 *   - sbx_v5_petugas_e     : pengurus + penugasan kesehatan aktif (pencabutan
 *                            saat sesi koreksi masih terbuka).
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$namaDb = (string) app_config('database.database');
if (!preg_match('/^test_.*_test$/', $namaDb)) {
    fwrite(STDERR, 'Seed V5 Fase 2 hanya untuk DB test_*_test, bukan ' . $namaDb . PHP_EOL);
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
if ((int) $val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kesehatan_versi_rincian'") !== 1) {
    fwrite(STDERR, "Migrasi 025 belum terpasang pada DB ini.\n");
    exit(2);
}
if ((int) $val("SELECT COUNT(*) FROM users WHERE username = 'sbx_v5_petugas_b'") !== 1) {
    fwrite(STDERR, "Fixture V5 Fase 1 belum ada; jalankan tests/v5_phase1_db.sh.\n");
    exit(2);
}
if ((int) $val("SELECT COUNT(*) FROM users WHERE username = 'sbx_v5_pembimbing'") > 0) {
    fwrite(STDERR, "Fixture V5 Fase 2 sudah ada; bangun ulang DB.\n");
    exit(2);
}

$db->begin_transaction();
$tahun = (int) $val("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1");
$hash = (string) $val("SELECT password FROM users WHERE username='sbx_admin'");
$admin = (int) $val("SELECT id FROM users WHERE username='sbx_admin'");
$role = static fn (string $slug): int => (int) $val("SELECT id FROM roles WHERE slug='" . $slug . "'");
$mulai = date('Y-m-d', strtotime('-30 days'));

$pengurus = static fn (string $nama): int => $q('INSERT INTO pengurus (nama, jabatan, is_active) VALUES (?, ?, 1)', 'ss', [$nama, 'Fixture V5 Fase 2']);
$guru = static fn (string $nama): int => $q("INSERT INTO guru (nama_guru, status, is_active) VALUES (?, 'Guru', 1)", 's', [$nama]);
$akun = static function (string $username, string $name, array $roles, ?int $pengurusId, ?int $guruId) use ($q, $hash, $role): int {
    $id = $q('INSERT INTO users (name, username, password, pengurus_id, guru_id, is_active, force_password_change, created_at) VALUES (?,?,?,?,?,1,0,NOW())',
        'sssii', [$name, $username, $hash, $pengurusId, $guruId]);
    foreach ($roles as $slug) {
        $q('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', 'ii', [$id, $role($slug)]);
    }

    return $id;
};
$kamar = static fn (string $nama): int => $q("INSERT INTO kamar (nama_kamar, kapasitas, asrama) VALUES (?, 20, 'Putra')", 's', [$nama]);
$kelas = static fn (string $nama): int => $q("INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES (?, 'SMP', 1)", 's', [$nama]);
$santri = static fn (string $nis, string $nama): int => $q(
    "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, is_active)
     VALUES (?, ?, 'L', 'Sintetis', '2012-01-01', 'Alamat sintetis', 'Desa', 'Kecamatan', 'Kota', 'Provinsi', 'Ayah sintetis', 'Ibu sintetis', 'Sekolah sintetis', 'Pesantren', 1)",
    'ss', [$nis, $nama]
);
$diKamar = static fn (int $s, int $k): int => $q('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', 'iii', [$s, $k, $tahun]);
$diKelas = static fn (int $s, int $k): int => $q("INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, tanggal_mulai, status, created_by) VALUES (?, ?, ?, ?, 'Aktif', ?)", 'iiisi', [$s, $k, $tahun, $mulai, $admin]);
$pembimbing = static fn (int $p, string $tipe, ?int $kamarId, ?int $kelasId): int => $q(
    'INSERT INTO pembimbing_assignments (pengurus_id, tahun_ajaran_id, target_type, kamar_id, kelas_id, tanggal_mulai, is_active, created_by, catatan) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
    'iisiisis', [$p, $tahun, $tipe, $kamarId, $kelasId, $mulai, $admin, 'Fixture V5 Fase 2']
);
$murobi = static fn (int $g, string $tipe, ?int $kamarId, ?int $kelasId): int => $q(
    'INSERT INTO murobi_assignments (guru_id, tahun_ajaran_id, target_type, kamar_id, kelas_id, tanggal_mulai, is_active, created_by, catatan) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
    'iisiisis', [$g, $tahun, $tipe, $kamarId, $kelasId, $mulai, $admin, 'Fixture V5 Fase 2']
);
$tugaskan = static fn (int $pengurusId): int => $q(
    'INSERT INTO kesehatan_assignments (pengurus_id, tahun_ajaran_id, tanggal_mulai, is_active, catatan, created_by, updated_by) VALUES (?, ?, ?, 1, ?, ?, ?)',
    'iissii', [$pengurusId, $tahun, $mulai, 'Fixture V5 Fase 2', $admin, $admin]
);

$kamarA = $kamar('SBX V5 Kamar A');
$kamarB = $kamar('SBX V5 Kamar B');
$kelasX = $kelas('SBX V5 Kelas X');
$kelasKosong = $kelas('SBX V5 Kelas Kosong');
$sA = $santri('V5S0101', 'SBX V5 Binaan A');
$sB = $santri('V5S0102', 'SBX V5 Binaan B');
$sLuar = $santri('V5S0103', 'SBX V5 Luar Cakupan');
$diKamar($sA, $kamarA);
$diKelas($sA, $kelasX);
$diKamar($sB, $kamarB);

$pPembimbing = $pengurus('SBX V5 Pembimbing A');
$pPembimbingB = $pengurus('SBX V5 Pembimbing B');
$pGanda = $pengurus('SBX V5 Ganda');
$pD = $pengurus('SBX V5 Petugas D');
$pE = $pengurus('SBX V5 Petugas E');
$gMurobi = $guru('SBX V5 Murobi X');
$gKosong = $guru('SBX V5 Murobi Kosong');
$gGanda = $guru('SBX V5 Ganda');

$uPembimbing = $akun('sbx_v5_pembimbing', 'SBX V5 Pembimbing A', ['pengurus'], $pPembimbing, null);
$uPembimbingB = $akun('sbx_v5_pembimbing_b', 'SBX V5 Pembimbing B', ['pengurus'], $pPembimbingB, null);
$uMurobi = $akun('sbx_v5_murobi', 'SBX V5 Murobi X', ['guru'], null, $gMurobi);
$uMurobiKosong = $akun('sbx_v5_murobi_kosong', 'SBX V5 Murobi Kosong', ['guru'], null, $gKosong);
$uGanda = $akun('sbx_v5_ganda', 'SBX V5 Ganda', ['pengurus', 'guru'], $pGanda, $gGanda);
$uD = $akun('sbx_v5_petugas_d', 'SBX V5 Petugas D', ['pengurus'], $pD, null);
$uE = $akun('sbx_v5_petugas_e', 'SBX V5 Petugas E', ['pengurus'], $pE, null);

$aPembimbing = $pembimbing($pPembimbing, 'Kamar', $kamarA, null);
$aPembimbingB = $pembimbing($pPembimbingB, 'Kamar', $kamarB, null);
$aGandaP = $pembimbing($pGanda, 'Kamar', $kamarB, null);
$aMurobi = $murobi($gMurobi, 'Kelas', null, $kelasX);
$aKosong = $murobi($gKosong, 'Kelas', null, $kelasKosong);
$aGandaM = $murobi($gGanda, 'Kelas', null, $kelasX);
$tD = $tugaskan($pD);
$tE = $tugaskan($pE);
$db->commit();

echo json_encode([
    'db' => $namaDb,
    'akun' => compact('uPembimbing', 'uPembimbingB', 'uMurobi', 'uMurobiKosong', 'uGanda', 'uD', 'uE'),
    'santri' => ['binaan_a' => $sA, 'binaan_b' => $sB, 'luar' => $sLuar],
    'kamar' => ['a' => $kamarA, 'b' => $kamarB],
    'kelas' => ['x' => $kelasX, 'kosong' => $kelasKosong],
    'penugasan' => compact('aPembimbing', 'aPembimbingB', 'aGandaP', 'aMurobi', 'aKosong', 'aGandaM', 'tD', 'tE'),
    'tahun_ajaran' => $tahun,
], JSON_PRETTY_PRINT) . PHP_EOL;
