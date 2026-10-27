<?php

declare(strict_types=1);

/**
 * Fixture sintetis PRD V7 Fase 1 (pengajuan wali dan routing pembimbing), di
 * atas fixture V6 Fase 5 (rantai V1–V6). Hanya untuk DB `test_*_test`.
 *
 * Santri (semuanya karangan, awalan SBX V7):
 *   V7S0001 Satu       kamar V7 Satu → TEPAT SATU pembimbing sah (Pembimbing Satu);
 *                      kamar itu juga punya murobi (SBX Murobi A) agar terbukti
 *                      murobi tidak menerima apa pun sebelum penerusan.
 *   V7S0002 Nol        kamar V7 Nol tanpa pembimbing → NOL kandidat.
 *   V7S0003 Dua        kamar V7 Dua (Pembimbing Satu) + kelas V7 Dua (Pembimbing Dua)
 *                      → DUA kandidat.
 *   V7S0004 TanpaAkun  kamar dengan penugasan pembimbing milik pengurus TANPA akun
 *                      login → nol kandidat SAH.
 *   V7S0005 Nonaktif   santri nonaktif yang tetap berelasi dengan wali pengaju.
 *
 * Akun (kata sandi sama dengan akun sandbox lain):
 *   sbx_v7_wali_a        wali pengaju — relasi aktif ke kelima santri;
 *   sbx_v7_wali_b        wali kedua — relasi aktif ke V7S0001 saja;
 *   sbx_v7_wali_dicabut  relasi ke V7S0001 sudah diarsipkan;
 *   sbx_v7_pembimbing_satu, sbx_v7_pembimbing_dua  pengurus berpenugasan pembimbing.
 * Wali asing memakai `sbx_ortu_b` (anak SBX Santri B1) dari fixture V2.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$namaDb = (string) app_config('database.database');
if (!preg_match('/^test_.*_test$/', $namaDb)) {
    fwrite(STDERR, 'Seed V7 Fase 1 hanya untuk DB test_*_test, bukan ' . $namaDb . PHP_EOL);
    exit(2);
}
$db = app_db();
$val = static fn (string $sql): mixed => $db->query($sql)?->fetch_row()[0] ?? null;
if ((int) $val("SELECT COUNT(*) FROM users WHERE username = 'sbx_v7_wali_a'") > 0) {
    fwrite(STDERR, "Fixture V7 Fase 1 sudah ada; bangun ulang DB.\n");
    exit(2);
}
$exec = static function (string $sql, array $params = []) use ($db): int {
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Seed V7 gagal disiapkan: ' . $db->error);
    }
    if ($params !== []) {
        $stmt->bind_param(implode('', array_map(static fn ($v): string => is_int($v) ? 'i' : 's', $params)), ...$params);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException('Seed V7 gagal: ' . $stmt->error);
    }
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return $id;
};

$admin = (int) $val("SELECT id FROM users WHERE username='sbx_admin'");
$hash = (string) $val("SELECT password FROM users WHERE username='sbx_admin'");
$tahun = (int) $val("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
$guruMurobiA = (int) $val("SELECT id FROM guru WHERE nip='SBX-G-001'");
$roleOrtu = (int) $val("SELECT id FROM roles WHERE slug='orang_tua'");
$rolePengurus = (int) $val("SELECT id FROM roles WHERE slug='pengurus'");
if ($admin < 1 || $tahun < 1 || $guruMurobiA < 1 || $roleOrtu < 1 || $rolePengurus < 1) {
    fwrite(STDERR, "Fixture V2/V6 belum ada.\n");
    exit(2);
}
$mulai = date('Y-m-d', strtotime('-30 days'));

$db->begin_transaction();

$kamar = static fn (string $nama): int => $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 20)', [$nama]);
$kamarSatu = $kamar('SBX V7 Kamar Satu');
$kamarNol = $kamar('SBX V7 Kamar Nol');
$kamarDua = $kamar('SBX V7 Kamar Dua');
$kamarTanpaAkun = $kamar('SBX V7 Kamar Tanpa Akun');
$kelasDua = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES ('SBX V7 Kelas Dua', 'Tsanawi', 1)");

$pengurus = static fn (string $nama, string $nomor): int => $exec(
    "INSERT INTO pengurus (nama, nomor_identitas, no_hp, jabatan, is_active) VALUES (?, ?, '0800007700', 'Pembimbing', 1)",
    [$nama, $nomor]
);
$pbSatu = $pengurus('SBX V7 Pembimbing Satu', 'SBX-V7-P-001');
$pbDua = $pengurus('SBX V7 Pembimbing Dua', 'SBX-V7-P-002');
$pbTanpaAkun = $pengurus('SBX V7 Pengurus Tanpa Akun', 'SBX-V7-P-003');

$pembimbing = static fn (int $pengurusId, string $tipe, ?int $kamarId, ?int $kelasId): int => $exec(
    'INSERT INTO pembimbing_assignments (pengurus_id, tahun_ajaran_id, target_type, kamar_id, kelas_id, tanggal_mulai, is_active, created_by)
     VALUES (?, ?, ?, ?, ?, ?, 1, ?)',
    array_map(static fn ($v) => $v, [$pengurusId, $tahun, $tipe, $kamarId, $kelasId, $mulai, $admin])
);
$paSatu = $pembimbing($pbSatu, 'Kamar', $kamarSatu, null);
$paDuaKamar = $pembimbing($pbSatu, 'Kamar', $kamarDua, null);
$paDuaKelas = $pembimbing($pbDua, 'Kelas', null, $kelasDua);
$paTanpaAkun = $pembimbing($pbTanpaAkun, 'Kamar', $kamarTanpaAkun, null);
$exec(
    "INSERT INTO murobi_assignments (guru_id, tahun_ajaran_id, target_type, kamar_id, tanggal_mulai, is_active) VALUES (?, ?, 'Kamar', ?, ?, 1)",
    [$guruMurobiA, $tahun, $kamarSatu, $mulai]
);

$santri = static fn (string $nis, string $nama, int $aktif): int => $exec(
    "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi,
                         nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, is_active)
     VALUES (?, ?, 'L', 'Kota Uji', '2012-01-01', 'Alamat uji', 'Desa Uji', 'Kecamatan Uji', 'Kabupaten Uji', 'Provinsi Uji',
             'Ayah Uji', 'Ibu Uji', 'SD Uji', 'Tsanawi', ?)",
    [$nis, $nama, $aktif]
);
$sSatu = $santri('V7S0001', 'SBX V7 Santri Satu', 1);
$sNol = $santri('V7S0002', 'SBX V7 Santri Nol', 1);
$sDua = $santri('V7S0003', 'SBX V7 Santri Dua', 1);
$sTanpaAkun = $santri('V7S0004', 'SBX V7 Santri Tanpa Akun', 1);
$sNonaktif = $santri('V7S0005', 'SBX V7 Santri Nonaktif', 0);

$plotKamar = static fn (int $s, int $k): int => $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$s, $k, $tahun]);
$plotKamar($sSatu, $kamarSatu);
$plotKamar($sNol, $kamarNol);
$plotKamar($sDua, $kamarDua);
$plotKamar($sTanpaAkun, $kamarTanpaAkun);
$plotKamar($sNonaktif, $kamarSatu);
$exec("INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, tanggal_mulai, status) VALUES (?, ?, ?, ?, 'Aktif')", [$sDua, $kelasDua, $tahun, $mulai]);

$wali = static fn (string $nama, string $hp): int => $exec("INSERT INTO wali (nama, no_hp, alamat, is_active) VALUES (?, ?, 'Alamat sintetis', 1)", [$nama, $hp]);
$waliA = $wali('SBX V7 Wali Pengaju', '081200007001');
$waliB = $wali('SBX V7 Wali Kedua', '081200007002');
$waliDicabut = $wali('SBX V7 Wali Relasi Dicabut', '081200007003');

$relasi = static fn (int $s, int $w, string $hub, int $utama): int => $exec(
    'INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary, created_by) VALUES (?, ?, ?, ?, ?)',
    [$s, $w, $hub, $utama, $admin]
);
foreach ([$sSatu, $sNol, $sDua, $sTanpaAkun, $sNonaktif] as $s) {
    $relasi($s, $waliA, 'Ayah', 1);
}
$relasi($sSatu, $waliB, 'Ibu', 0);
$relasiDicabut = $relasi($sSatu, $waliDicabut, 'Paman', 0);
$exec('UPDATE santri_wali SET archived_at = NOW() WHERE id = ?', [$relasiDicabut]);

$akun = static function (string $username, string $nama, int $role, ?int $pengurusId, ?int $waliId) use ($exec, $hash): int {
    $id = $exec(
        'INSERT INTO users (name, username, password, pengurus_id, wali_id, is_active, force_password_change, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), NOW())',
        [$nama, $username, $hash, $pengurusId, $waliId]
    );
    $exec('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$id, $role]);

    return $id;
};
$uWaliA = $akun('sbx_v7_wali_a', 'SBX V7 Wali Pengaju', $roleOrtu, null, $waliA);
$uWaliB = $akun('sbx_v7_wali_b', 'SBX V7 Wali Kedua', $roleOrtu, null, $waliB);
$uWaliDicabut = $akun('sbx_v7_wali_dicabut', 'SBX V7 Wali Relasi Dicabut', $roleOrtu, null, $waliDicabut);
$uPbSatu = $akun('sbx_v7_pembimbing_satu', 'SBX V7 Pembimbing Satu', $rolePengurus, $pbSatu, null);
$uPbDua = $akun('sbx_v7_pembimbing_dua', 'SBX V7 Pembimbing Dua', $rolePengurus, $pbDua, null);

$db->commit();

echo json_encode([
    'db' => $namaDb,
    'tahun' => $tahun,
    'santri' => ['satu' => $sSatu, 'nol' => $sNol, 'dua' => $sDua, 'tanpa_akun' => $sTanpaAkun, 'nonaktif' => $sNonaktif],
    'kamar' => ['satu' => $kamarSatu, 'nol' => $kamarNol, 'dua' => $kamarDua, 'tanpa_akun' => $kamarTanpaAkun],
    'pengurus' => ['satu' => $pbSatu, 'dua' => $pbDua, 'tanpa_akun' => $pbTanpaAkun],
    'penugasan' => ['satu' => $paSatu, 'dua_kamar' => $paDuaKamar, 'dua_kelas' => $paDuaKelas, 'tanpa_akun' => $paTanpaAkun],
    'wali' => ['a' => $waliA, 'b' => $waliB, 'dicabut' => $waliDicabut],
    'users' => ['wali_a' => $uWaliA, 'wali_b' => $uWaliB, 'wali_dicabut' => $uWaliDicabut, 'pembimbing_satu' => $uPbSatu, 'pembimbing_dua' => $uPbDua],
], JSON_PRETTY_PRINT) . PHP_EOL;
