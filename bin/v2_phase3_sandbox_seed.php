<?php

declare(strict_types=1);

/**
 * Fixture sintetis V2 Fase 3 untuk database pengujian.
 *
 * TUJUAN: menyediakan data uji yang dapat diulang bagi pengujian API, kontrak,
 * otorisasi lintas peran, idempotensi, dan concurrency — TANPA memakai dump,
 * data santri, credential, atau akses database produksi.
 *
 * PENJAGA KERAS:
 *   - hanya berjalan pada CLI,
 *   - hanya berjalan bila `DB_NAME` berakhiran `_test`,
 *   - hanya berjalan bila `V2_PHASE3_SEED=1`.
 *
 * Seluruh nama, NIS, NIP, dan nomor bersifat karangan dengan awalan `SBX`.
 * Password fixture bukan credential produksi dan hanya berlaku di sandbox.
 *
 * Pemakaian:
 *   V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php
 *
 * Struktur yang dihasilkan (lihat docs/phase-v2-3/testing-sandbox.md):
 *   - 1 tahun ajaran aktif;
 *   - kamar A/B/C dan satu kelas;
 *   - murobi A (kamar A), murobi B (kamar B), murobi C (kelas), guru tanpa murobi;
 *   - pengurus A (pembimbing kamar A + C), pengurus B (pembimbing kamar B);
 *   - santri A1 (routing tunggal), A2 (dua kandidat), B1 (murobi B), C1 (tanpa murobi);
 *   - wali A (anak A1) dan wali B (anak B1);
 *   - akun: admin, pengurus_a, pengurus_b, murobi_a, murobi_b, murobi_c,
 *           guru_biasa, ortu_a, ortu_b.
 */

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (getenv('V2_PHASE3_SEED') !== '1') {
    fwrite(STDERR, "Tolak: setel V2_PHASE3_SEED=1 untuk menjalankan seed fixture sandbox.\n");
    exit(2);
}
$database = (string) app_config('database.database');
if (!str_ends_with($database, '_test')) {
    fwrite(STDERR, "Tolak: DB_NAME (`{$database}`) wajib berakhiran _test.\n");
    exit(2);
}

$db = app_db();
$db->query('SET foreign_key_checks = 1');

/** Password fixture sandbox — bukan credential produksi. */
const SBX_PASSWORD = 'Sandbox#123';

$exec = static function (string $sql, array $params = []) use ($db): int {
    $statement = $db->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Seed gagal disiapkan: ' . $db->error . ' | ' . $sql);
    }
    if ($params !== []) {
        $types = '';
        $references = [];
        foreach ($params as $index => &$value) {
            $types .= is_int($value) ? 'i' : 's';
            $references[$index] = &$value;
        }
        unset($value);
        $statement->bind_param($types, ...$references);
    }
    if (!$statement->execute()) {
        $error = $statement->error;
        $statement->close();
        throw new RuntimeException('Seed gagal dijalankan: ' . $error . ' | ' . $sql);
    }
    $id = (int) $statement->insert_id;
    $statement->close();

    return $id;
};

$scalar = static function (string $sql) use ($db): ?array {
    $result = $db->query($sql);

    return $result ? ($result->fetch_assoc() ?: null) : null;
};

if ($scalar("SELECT id FROM users WHERE username = 'sbx_admin' LIMIT 1") !== null) {
    fwrite(STDOUT, "Fixture sandbox sudah ada. Buat ulang database uji bila ingin kondisi bersih.\n");
    exit(0);
}

$hash = password_hash(SBX_PASSWORD, PASSWORD_DEFAULT);
$today = date('Y-m-d');
$mulai = date('Y-m-d', strtotime('-30 days'));

// Skema warisan membawa nilai AUTO_INCREMENT dari lingkungan asalnya. Pada
// database uji yang kosong, nilai itu membuat ID pertama tidak dimulai dari 1
// sehingga pengujian V1 yang memakai pelaku `user_id = 1` gagal menyiapkan
// fixture. Reset hanya dilakukan untuk tabel yang benar-benar kosong.
foreach ([
    'users', 'santri', 'guru', 'kamar', 'kelas', 'tahun_ajaran',
    'plotting_kamar', 'plotting_kelas', 'perizinan',
] as $tabel) {
    $jumlah = (int) ($scalar('SELECT COUNT(*) AS jumlah FROM `' . $tabel . '`')['jumlah'] ?? 0);
    if ($jumlah === 0) {
        $db->query('ALTER TABLE `' . $tabel . '` AUTO_INCREMENT = 1');
    }
}

// --- Tahun ajaran -----------------------------------------------------------
$tahunId = $exec(
    "INSERT INTO tahun_ajaran (tahun, semester, status) VALUES ('2026/2027', 'Ganjil', 'Aktif')"
);

// --- Kamar dan kelas --------------------------------------------------------
$kamarA = $exec("INSERT INTO kamar (nama_kamar, kapasitas) VALUES ('SBX Kamar A', 20)");
$kamarB = $exec("INSERT INTO kamar (nama_kamar, kapasitas) VALUES ('SBX Kamar B', 20)");
$kamarC = $exec("INSERT INTO kamar (nama_kamar, kapasitas) VALUES ('SBX Kamar C', 20)");
$kelas1 = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES ('SBX Kelas 1', 'Tsanawi', 1)");

// --- Guru -------------------------------------------------------------------
$guru = static function (string $nip, string $nama) use ($exec): int {
    return $exec(
        "INSERT INTO guru (nip, nama_guru, no_hp, status, is_active) VALUES (?, ?, '0800000000', 'Guru', 1)",
        [$nip, $nama]
    );
};
$guruMurobiA = $guru('SBX-G-001', 'SBX Murobi A');
$guruMurobiB = $guru('SBX-G-002', 'SBX Murobi B');
$guruMurobiC = $guru('SBX-G-003', 'SBX Murobi C');
$guruBiasa = $guru('SBX-G-004', 'SBX Guru Tanpa Murobi');

// --- Pengurus dan wali ------------------------------------------------------
$pengurusA = $exec(
    "INSERT INTO pengurus (nama, nomor_identitas, no_hp, jabatan, is_active) VALUES ('SBX Pengurus A', 'SBX-P-001', '0800000001', 'Keamanan', 1)"
);
$pengurusB = $exec(
    "INSERT INTO pengurus (nama, nomor_identitas, no_hp, jabatan, is_active) VALUES ('SBX Pengurus B', 'SBX-P-002', '0800000002', 'Keamanan', 1)"
);
$waliA = $exec("INSERT INTO wali (nama, no_hp, alamat, is_active) VALUES ('SBX Wali A', '0800000003', 'Alamat uji A', 1)");
$waliB = $exec("INSERT INTO wali (nama, no_hp, alamat, is_active) VALUES ('SBX Wali B', '0800000004', 'Alamat uji B', 1)");

// --- Santri -----------------------------------------------------------------
$santri = static function (string $nis, string $nama) use ($exec): int {
    return $exec(
        "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa,
                             kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, asal_sekolah,
                             sekolah_saat_ini, is_active)
         VALUES (?, ?, 'L', 'Kota Uji', '2012-01-01', 'Alamat uji', 'Desa Uji', 'Kecamatan Uji',
                 'Kabupaten Uji', 'Provinsi Uji', 'Ayah Uji', 'Ibu Uji', 'SD Uji', 'Tsanawi', 1)",
        [$nis, $nama]
    );
};
$santriA1 = $santri('SBX-S-001', 'SBX Santri A1');
$santriA2 = $santri('SBX-S-002', 'SBX Santri A2');
$santriB1 = $santri('SBX-S-003', 'SBX Santri B1');
$santriC1 = $santri('SBX-S-004', 'SBX Santri C1');

// --- Plotting kamar/kelas ---------------------------------------------------
$plotKamar = static function (int $santriId, int $kamarId) use ($exec, $tahunId): void {
    $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santriId, $kamarId, $tahunId]);
};
$plotKamar($santriA1, $kamarA);
$plotKamar($santriA2, $kamarA);
$plotKamar($santriB1, $kamarB);
$plotKamar($santriC1, $kamarC);
// A2 juga masuk kelas yang murobinya berbeda -> dua kandidat -> Perlu Penetapan Admin.
$exec(
    "INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, tanggal_mulai, status) VALUES (?, ?, ?, ?, 'Aktif')",
    [$santriA2, $kelas1, $tahunId, $mulai]
);

// --- Penugasan murobi (kamar A, kamar B, kelas 1) ---------------------------
$murobi = static function (int $guruId, string $type, ?int $kamarId, ?int $kelasId) use ($exec, $tahunId, $mulai): void {
    $exec(
        'INSERT INTO murobi_assignments (guru_id, tahun_ajaran_id, target_type, kamar_id, kelas_id, tanggal_mulai, is_active)
         VALUES (?, ?, ?, ?, ?, ?, 1)',
        [$guruId, $tahunId, $type, $kamarId, $kelasId, $mulai]
    );
};
$murobi($guruMurobiA, 'Kamar', $kamarA, null);
$murobi($guruMurobiB, 'Kamar', $kamarB, null);
$murobi($guruMurobiC, 'Kelas', null, $kelas1);
// Kamar C sengaja TANPA murobi -> routing nol kandidat -> antrean admin.

// --- Penugasan pembimbing (cakupan pengurus) --------------------------------
$pembimbing = static function (int $pengurusId, int $kamarId) use ($exec, $tahunId, $mulai): void {
    $exec(
        "INSERT INTO pembimbing_assignments (pengurus_id, tahun_ajaran_id, target_type, kamar_id, tanggal_mulai, is_active)
         VALUES (?, ?, 'Kamar', ?, ?, 1)",
        [$pengurusId, $tahunId, $kamarId, $mulai]
    );
};
$pembimbing($pengurusA, $kamarA);
$pembimbing($pengurusA, $kamarC);
$pembimbing($pengurusB, $kamarB);

// --- Relasi wali–santri -----------------------------------------------------
$exec(
    "INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary) VALUES (?, ?, 'Ayah', 1)",
    [$santriA1, $waliA]
);
$exec(
    "INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary) VALUES (?, ?, 'Ayah', 1)",
    [$santriB1, $waliB]
);

// --- Akun -------------------------------------------------------------------
$roleId = static function (string $slug) use ($scalar): int {
    $row = $scalar("SELECT id FROM roles WHERE slug = '" . $slug . "' LIMIT 1");
    if ($row === null) {
        throw new RuntimeException('Role ' . $slug . ' belum tersedia. Jalankan migrasi lebih dulu.');
    }

    return (int) $row['id'];
};

$account = static function (
    string $username,
    string $nama,
    string $role,
    ?int $guruId = null,
    ?int $pengurusId = null,
    ?int $waliId = null
) use ($exec, $roleId, $hash): int {
    $userId = $exec(
        'INSERT INTO users (name, username, password, guru_id, pengurus_id, wali_id, is_active, force_password_change, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW())',
        [$nama, $username, $hash, $guruId, $pengurusId, $waliId]
    );
    $exec('INSERT INTO user_roles (user_id, role_id, assigned_at) VALUES (?, ?, NOW())', [$userId, $roleId($role)]);

    return $userId;
};

$account('sbx_admin', 'SBX Administrator', 'admin');
$account('sbx_pengurus_a', 'SBX Pengurus A', 'pengurus', null, $pengurusA);
$account('sbx_pengurus_b', 'SBX Pengurus B', 'pengurus', null, $pengurusB);
$account('sbx_murobi_a', 'SBX Murobi A', 'guru', $guruMurobiA);
$account('sbx_murobi_b', 'SBX Murobi B', 'guru', $guruMurobiB);
$account('sbx_murobi_c', 'SBX Murobi C', 'guru', $guruMurobiC);
$account('sbx_guru_biasa', 'SBX Guru Tanpa Murobi', 'guru', $guruBiasa);
$account('sbx_ortu_a', 'SBX Orang Tua A', 'orang_tua', null, null, $waliA);
$account('sbx_ortu_b', 'SBX Orang Tua B', 'orang_tua', null, null, $waliB);

fwrite(STDOUT, "Fixture sandbox V2 Fase 3 selesai dibuat pada database `{$database}`.\n");
fwrite(STDOUT, "Akun uji (password: " . SBX_PASSWORD . "):\n");
foreach ([
    'sbx_admin' => 'admin',
    'sbx_pengurus_a' => 'pengurus (kamar A + C)',
    'sbx_pengurus_b' => 'pengurus (kamar B)',
    'sbx_murobi_a' => 'guru + murobi kamar A',
    'sbx_murobi_b' => 'guru + murobi kamar B',
    'sbx_murobi_c' => 'guru + murobi kelas 1',
    'sbx_guru_biasa' => 'guru tanpa penugasan murobi',
    'sbx_ortu_a' => 'orang tua santri A1',
    'sbx_ortu_b' => 'orang tua santri B1',
] as $username => $keterangan) {
    fwrite(STDOUT, sprintf("  - %-16s %s\n", $username, $keterangan));
}
fwrite(STDOUT, sprintf(
    "ID referensi: tahun_ajaran=%d kamarA=%d kamarB=%d kamarC=%d kelas=%d santriA1=%d santriA2=%d santriB1=%d santriC1=%d\n",
    $tahunId,
    $kamarA,
    $kamarB,
    $kamarC,
    $kelas1,
    $santriA1,
    $santriA2,
    $santriB1,
    $santriC1
));
exit(0);
