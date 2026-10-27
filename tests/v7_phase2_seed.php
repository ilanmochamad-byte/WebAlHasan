<?php

declare(strict_types=1);

/**
 * Fixture sintetis PRD V7 Fase 2 di atas fixture V7 Fase 1. Hanya untuk DB
 * `test_*_test`. Menambah routing MUROBI yang beragam untuk santri yang
 * masing-masing memiliki TEPAT SATU pembimbing sah (Pembimbing Satu):
 *
 *   V7S0006 TanpaMurobi  kamar V7 Tanpa Murobi → nol murobi → Perlu Penetapan Admin
 *   V7S0007 DuaMurobi    kamar V7 Dua Murobi (SBX Murobi A) + kelas V7 Murobi B
 *                        (SBX Murobi B) → dua murobi → Perlu Penetapan Admin
 *   V7S0008 Cadangan     kamar V7 Satu (Pembimbing Satu, SBX Murobi A) → murobi tunggal
 *
 * Wali pengaju (`sbx_v7_wali_a`) berelasi dengan ketiganya; wali kedua
 * (`sbx_v7_wali_b`) juga berelasi dengan V7S0008.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$namaDb = (string) app_config('database.database');
if (!preg_match('/^test_.*_test$/', $namaDb)) {
    fwrite(STDERR, 'Seed V7 Fase 2 hanya untuk DB test_*_test, bukan ' . $namaDb . PHP_EOL);
    exit(2);
}
$db = app_db();
$val = static fn (string $sql): mixed => $db->query($sql)?->fetch_row()[0] ?? null;
if ((int) $val("SELECT COUNT(*) FROM santri WHERE nis = 'V7S0006'") > 0) {
    fwrite(STDERR, "Fixture V7 Fase 2 sudah ada; bangun ulang DB.\n");
    exit(2);
}
$exec = static function (string $sql, array $params = []) use ($db): int {
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Seed V7 Fase 2 gagal disiapkan: ' . $db->error);
    }
    if ($params !== []) {
        $stmt->bind_param(implode('', array_map(static fn ($v): string => is_int($v) ? 'i' : 's', $params)), ...$params);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException('Seed V7 Fase 2 gagal: ' . $stmt->error);
    }
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return $id;
};

$admin = (int) $val("SELECT id FROM users WHERE username='sbx_admin'");
$tahun = (int) $val("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
$guruA = (int) $val("SELECT guru_id FROM users WHERE username='sbx_murobi_a'");
$guruB = (int) $val("SELECT guru_id FROM users WHERE username='sbx_murobi_b'");
$pbSatu = (int) $val("SELECT pengurus_id FROM users WHERE username='sbx_v7_pembimbing_satu'");
$kamarSatu = (int) $val("SELECT id FROM kamar WHERE nama_kamar='SBX V7 Kamar Satu'");
$waliA = (int) $val("SELECT wali_id FROM users WHERE username='sbx_v7_wali_a'");
$waliB = (int) $val("SELECT wali_id FROM users WHERE username='sbx_v7_wali_b'");
if ($admin < 1 || $tahun < 1 || $guruA < 1 || $guruB < 1 || $pbSatu < 1 || $kamarSatu < 1 || $waliA < 1 || $waliB < 1) {
    fwrite(STDERR, "Fixture V2/V7 Fase 1 belum ada.\n");
    exit(2);
}
$mulai = date('Y-m-d', strtotime('-30 days'));

$db->begin_transaction();
$kamarTanpa = $exec("INSERT INTO kamar (nama_kamar, kapasitas) VALUES ('SBX V7 Kamar Tanpa Murobi', 20)");
$kamarDua = $exec("INSERT INTO kamar (nama_kamar, kapasitas) VALUES ('SBX V7 Kamar Dua Murobi', 20)");
$kelasB = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES ('SBX V7 Kelas Murobi B', 'Tsanawi', 1)");
$paTanpa = $exec('INSERT INTO pembimbing_assignments (pengurus_id, tahun_ajaran_id, target_type, kamar_id, tanggal_mulai, is_active, created_by) VALUES (?, ?, \'Kamar\', ?, ?, 1, ?)',
    [$pbSatu, $tahun, $kamarTanpa, $mulai, $admin]);
$paDua = $exec('INSERT INTO pembimbing_assignments (pengurus_id, tahun_ajaran_id, target_type, kamar_id, tanggal_mulai, is_active, created_by) VALUES (?, ?, \'Kamar\', ?, ?, 1, ?)',
    [$pbSatu, $tahun, $kamarDua, $mulai, $admin]);
$exec("INSERT INTO murobi_assignments (guru_id, tahun_ajaran_id, target_type, kamar_id, tanggal_mulai, is_active) VALUES (?, ?, 'Kamar', ?, ?, 1)", [$guruA, $tahun, $kamarDua, $mulai]);
$exec("INSERT INTO murobi_assignments (guru_id, tahun_ajaran_id, target_type, kelas_id, tanggal_mulai, is_active) VALUES (?, ?, 'Kelas', ?, ?, 1)", [$guruB, $tahun, $kelasB, $mulai]);

$santri = static fn (string $nis, string $nama): int => $exec(
    "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi,
                         nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, is_active)
     VALUES (?, ?, 'L', 'Kota Uji', '2012-01-01', 'Alamat uji', 'Desa Uji', 'Kecamatan Uji', 'Kabupaten Uji', 'Provinsi Uji',
             'Ayah Uji', 'Ibu Uji', 'SD Uji', 'Tsanawi', 1)",
    [$nis, $nama]
);
$sTanpa = $santri('V7S0006', 'SBX V7 Santri Tanpa Murobi');
$sDua = $santri('V7S0007', 'SBX V7 Santri Dua Murobi');
$sCadangan = $santri('V7S0008', 'SBX V7 Santri Cadangan');
foreach ([[$sTanpa, $kamarTanpa], [$sDua, $kamarDua], [$sCadangan, $kamarSatu]] as [$s, $k]) {
    $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$s, $k, $tahun]);
}
$exec("INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, tanggal_mulai, status) VALUES (?, ?, ?, ?, 'Aktif')", [$sDua, $kelasB, $tahun, $mulai]);
foreach ([$sTanpa, $sDua, $sCadangan] as $s) {
    $exec('INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary, created_by) VALUES (?, ?, \'Ayah\', 1, ?)', [$s, $waliA, $admin]);
}
$exec('INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary, created_by) VALUES (?, ?, \'Ibu\', 0, ?)', [$sCadangan, $waliB, $admin]);
$db->commit();

echo json_encode([
    'db' => $namaDb,
    'santri' => ['tanpa_murobi' => $sTanpa, 'dua_murobi' => $sDua, 'cadangan' => $sCadangan],
    'penugasan' => ['tanpa_murobi' => $paTanpa, 'dua_murobi' => $paDua],
    'guru' => ['a' => $guruA, 'b' => $guruB],
], JSON_PRETTY_PRINT) . PHP_EOL;
