<?php

declare(strict_types=1);

/**
 * Fixture sintetis PRD V6 Fase 1 (fondasi BSA).
 *
 * Dijalankan oleh `tests/v6_phase1_db.sh` pada DB salinan `test_*_test` yang
 * sudah memuat migrasi 027. Menolak DB lain. Seluruh akun berawalan
 * `sbx_v6_`, kata sandi sama dengan akun sandbox lain (`Sandbox#123`), dan
 * tidak ada data pribadi atau keuangan nyata. Tidak ada saldo atau tagihan
 * awal: semua santri mulai dari Rp0 (PRD V6 §5.1).
 *
 * Menambah:
 *   - sbx_v6_bsa          : pengurus TANPA penugasan BSA (admin menugaskannya
 *                           lewat pusat penugasan di uji);
 *   - sbx_v6_bsa_b        : pengurus + penugasan BSA seluruh unit (skenario
 *                           konkurensi dan penugasan dicabut saat sesi aktif);
 *   - sbx_v6_bsa_tsanawi  : pengurus + penugasan BSA unit Tsanawi saja;
 *   - sbx_v6_bsa_lama     : pengurus + penugasan BSA seluruh unit pada tahun
 *                           ajaran NON-AKTIF (keputusan 25 Oktober 2026: tetap
 *                           berlaku, tidak gugur saat semester berganti);
 *   - sbx_v6_admin_bsa    : admin + pengurus + penugasan BSA (admin tetap
 *                           tidak boleh memutasi);
 *   - santri SBX V6 SMP (kelas SMP), SBX V6 Tsanawi (kelas Tsanawi),
 *     SBX V6 Tanpa Kelas (aktif, belum ditempatkan), SBX V6 Nonaktif;
 *   - sbx_v6_wali_smp (wali SBX V6 SMP), sbx_v6_wali_tsanawi (wali SBX V6
 *     Tsanawi) — wali anak lain satu sama lain.
 *
 * Akun lama yang dipakai uji: sbx_admin, sbx_pengurus_biasa (pengurus tanpa
 * penugasan), sbx_guru_biasa (guru), sbx_ortu_a (wali tanpa relasi santri V6),
 * sbx_v5_petugas_b (petugas kesehatan, bukan BSA).
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$namaDb = (string) app_config('database.database');
if (!preg_match('/^test_.*_test$/', $namaDb)) {
    fwrite(STDERR, 'Seed V6 Fase 1 hanya untuk DB test_*_test, bukan ' . $namaDb . PHP_EOL);
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
if ((int) $val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bsa_tabungan_transaksi'") !== 1) {
    fwrite(STDERR, "Migrasi 027 belum terpasang pada DB ini.\n");
    exit(2);
}
if ((int) $val("SELECT COUNT(*) FROM users WHERE username LIKE 'sbx\\_v6\\_%'") > 0) {
    fwrite(STDERR, "Fixture V6 Fase 1 sudah ada; bangun ulang DB.\n");
    exit(2);
}

$db->begin_transaction();
$tahun = (int) $val("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
$tahunLama = (int) $val("SELECT id FROM tahun_ajaran WHERE status<>'Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
if ($tahun < 1 || $tahunLama < 1) {
    throw new RuntimeException('Fixture memerlukan satu tahun ajaran aktif dan satu non-aktif.');
}
$kelasSmp = (int) $val("SELECT id FROM kelas WHERE jenjang='SMP' AND is_active=1 AND archived_at IS NULL ORDER BY id LIMIT 1");
$kelasTsanawi = (int) $val("SELECT id FROM kelas WHERE jenjang='Tsanawi' AND is_active=1 AND archived_at IS NULL ORDER BY id LIMIT 1");
$hash = (string) $val("SELECT password FROM users WHERE username='sbx_admin'");
$admin = (int) $val("SELECT id FROM users WHERE username='sbx_admin'");
$role = static fn (string $slug): int => (int) $val("SELECT id FROM roles WHERE slug='" . $slug . "'");
$mulai = date('Y-m-d', strtotime('-30 days'));

$pengurus = static fn (string $nama): int => $q('INSERT INTO pengurus (nama, jabatan, is_active) VALUES (?, ?, 1)', 'ss', [$nama, 'BSA (sintetis)']);
$akun = static function (string $username, string $name, array $roles, ?int $pengurusId, ?int $waliId = null) use ($q, $hash, $role): int {
    $id = $q('INSERT INTO users (name, username, password, pengurus_id, wali_id, is_active, force_password_change, created_at) VALUES (?,?,?,?,?,1,0,NOW())', 'sssii', [$name, $username, $hash, $pengurusId, $waliId]);
    foreach ($roles as $slug) {
        $q('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', 'ii', [$id, $role($slug)]);
    }

    return $id;
};
$tugaskan = static fn (int $pengurusId, int $tahunId, ?string $jenjang): int => $q(
    'INSERT INTO bendahara_bulanan_assignments (pengurus_id, tahun_ajaran_id, jenjang, tanggal_mulai, is_active, catatan, created_by, updated_by) VALUES (?, ?, ?, ?, 1, ?, ?, ?)',
    'iisssii', [$pengurusId, $tahunId, $jenjang, $mulai, 'Fixture V6 Fase 1', $admin, $admin]
);

$pA = $pengurus('SBX V6 Petugas BSA');
$pB = $pengurus('SBX V6 Petugas BSA B');
$pT = $pengurus('SBX V6 Petugas BSA Tsanawi');
$pL = $pengurus('SBX V6 Petugas BSA Lama');
$pD = $pengurus('SBX V6 Admin BSA');
$bsa = $akun('sbx_v6_bsa', 'SBX V6 Petugas BSA', ['pengurus'], $pA);
$bsaB = $akun('sbx_v6_bsa_b', 'SBX V6 Petugas BSA B', ['pengurus'], $pB);
$bsaT = $akun('sbx_v6_bsa_tsanawi', 'SBX V6 Petugas BSA Tsanawi', ['pengurus'], $pT);
$bsaL = $akun('sbx_v6_bsa_lama', 'SBX V6 Petugas BSA Lama', ['pengurus'], $pL);
$adminBsa = $akun('sbx_v6_admin_bsa', 'SBX V6 Admin BSA', ['admin', 'pengurus'], $pD);
$tugasB = $tugaskan($pB, $tahun, null);
$tugasT = $tugaskan($pT, $tahun, 'Tsanawi');
$tugasL = $tugaskan($pL, $tahunLama, null);
$tugasD = $tugaskan($pD, $tahun, null);

$santri = static fn (string $nis, string $nama, int $aktif): int => $q(
    "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, is_active)
     VALUES (?, ?, 'L', 'Sintetis', '2012-01-01', 'Alamat sintetis', 'Desa', 'Kecamatan', 'Kota', 'Provinsi', 'Ayah sintetis', 'Ibu sintetis', 'Sekolah sintetis', 'Pesantren', ?)",
    'ssi', [$nis, $nama, $aktif]
);
$tempatkan = static fn (int $santriId, int $kelasId): int => $q(
    "INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, tanggal_mulai, status, created_by) VALUES (?, ?, ?, ?, 'Aktif', ?)",
    'iiisi', [$santriId, $kelasId, $tahun, $mulai, $admin]
);
$sSmp = $santri('V6S0001', 'SBX V6 Santri SMP', 1);
$sTsanawi = $santri('V6S0002', 'SBX V6 Santri Tsanawi', 1);
$sTanpa = $santri('V6S0003', 'SBX V6 Santri Tanpa Kelas', 1);
$sNonaktif = $santri('V6S0004', 'SBX V6 Santri Nonaktif', 0);
$tempatkan($sSmp, $kelasSmp);
$tempatkan($sTsanawi, $kelasTsanawi);

$roleOrtu = 'orang_tua';
$wali = static fn (string $nama, string $hp): int => $q('INSERT INTO wali (nama, no_hp, alamat, is_active) VALUES (?, ?, ?, 1)', 'sss', [$nama, $hp, 'Alamat sintetis']);
$relasi = static fn (int $s, int $w): int => $q("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary, created_by) VALUES (?, ?, 'Ayah', 1, ?)", 'iii', [$s, $w, $admin]);
$wSmp = $wali('SBX V6 Wali SMP', '081200006001');
$wTsanawi = $wali('SBX V6 Wali Tsanawi', '081200006002');
$relasiSmp = $relasi($sSmp, $wSmp);
$relasiTsanawi = $relasi($sTsanawi, $wTsanawi);
$uWaliSmp = $akun('sbx_v6_wali_smp', 'SBX V6 Wali SMP', [$roleOrtu], null, $wSmp);
$uWaliTsanawi = $akun('sbx_v6_wali_tsanawi', 'SBX V6 Wali Tsanawi', [$roleOrtu], null, $wTsanawi);
$db->commit();

echo json_encode([
    'db' => $namaDb,
    'akun' => compact('bsa', 'bsaB', 'bsaT', 'bsaL', 'adminBsa', 'uWaliSmp', 'uWaliTsanawi'),
    'penugasan' => ['bsa_b' => $tugasB, 'bsa_tsanawi' => $tugasT, 'bsa_lama' => $tugasL, 'admin_bsa' => $tugasD],
    'santri' => ['smp' => $sSmp, 'tsanawi' => $sTsanawi, 'tanpa_kelas' => $sTanpa, 'nonaktif' => $sNonaktif],
    'relasi' => ['smp' => $relasiSmp, 'tsanawi' => $relasiTsanawi],
    'tahun_ajaran' => ['aktif' => $tahun, 'nonaktif' => $tahunLama],
], JSON_PRETTY_PRINT) . PHP_EOL;
