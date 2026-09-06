<?php

declare(strict_types=1);

/**
 * Fixture untuk uji browser "Pesan Kredensial Akun Siap Salin".
 *
 * Membuat satu akun admin uji beserta data master guru, pengurus, dan wali yang
 * BELUM memiliki akun, sehingga formulir pembuatan akun pada halaman
 * `admin/admin_akun.php` benar-benar dapat dipakai peramban uji.
 *
 * Seluruh data FIKTIF dan diberi awalan `bk_` / `BK` agar mudah dibersihkan.
 * Tidak ada kredensial nyata.
 *
 * Membuat fixture (mencetak JSON ke stdout):
 *   KREDENSIAL_SEED=1 php tests/browser/seed-kredensial.php
 *
 * Membersihkan kembali:
 *   KREDENSIAL_SEED=1 php tests/browser/seed-kredensial.php --bersihkan
 */

$root = dirname(__DIR__, 2);
if (getenv('KREDENSIAL_SEED') !== '1') {
    fwrite(STDERR, "Set KREDENSIAL_SEED=1 untuk menjalankan seed fixture.\n");
    exit(2);
}

require_once $root . '/app/bootstrap.php';

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Ditolak: seed hanya boleh berjalan pada database berakhiran _test.\n");
    exit(2);
}

$db = app_db();
$bersihkan = in_array('--bersihkan', $argv, true);

$hapus = static function (string $sql) use ($db): void {
    @$db->query($sql);
};

// Pembersihan selalu dijalankan lebih dulu supaya seed dapat diulang.
$hapus("DELETE ur FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE u.username LIKE 'bk\\_%'");
$hapus("DELETE al FROM audit_logs al JOIN users u ON u.id = al.entity_id WHERE al.entity_type = 'user' AND u.username LIKE 'bk\\_%'");
$hapus("DELETE al FROM audit_logs al JOIN users u ON u.id = al.actor_id WHERE u.username LIKE 'bk\\_%'");
$hapus("DELETE FROM users WHERE username LIKE 'bk\\_%'");
$hapus("DELETE sw FROM santri_wali sw JOIN santri s ON s.id = sw.santri_id WHERE s.nis LIKE 'BKS%'");
$hapus("DELETE FROM santri WHERE nis LIKE 'BKS%'");
$hapus("DELETE FROM wali WHERE nama LIKE 'Wali Browser Kredensial%'");
$hapus("DELETE FROM pengurus WHERE nomor_identitas LIKE 'BKP%'");
$hapus("DELETE FROM guru WHERE nip LIKE 'BKG%'");

if ($bersihkan) {
    echo "Fixture uji browser kredensial dibersihkan.\n";
    exit(0);
}

$sandi = 'UjiBrowser#Kredensial9';
$exec = static function (string $sql, array $params = []) use ($db): int {
    $statement = $db->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Query gagal disiapkan: ' . $db->error);
    }
    if ($params !== []) {
        $types = '';
        $refs = [];
        foreach ($params as $key => &$value) {
            $types .= is_int($value) ? 'i' : 's';
            $refs[$key] = &$value;
        }
        unset($value);
        $statement->bind_param($types, ...$refs);
    }
    if (!$statement->execute()) {
        $error = $statement->error;
        $statement->close();
        throw new RuntimeException('Query gagal dijalankan: ' . $error);
    }
    $id = (int) $statement->insert_id;
    $statement->close();

    return $id;
};

$adminId = $exec(
    'INSERT INTO users (name, username, password, is_active, force_password_change, created_at, updated_at)
     VALUES (?, ?, ?, 1, 0, NOW(), NOW())',
    ['Admin Uji Browser Kredensial', 'bk_admin', password_hash($sandi, PASSWORD_DEFAULT)]
);
$exec("INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = 'admin'", [$adminId, $adminId]);

$_SESSION = ['user_id' => $adminId];
$master = master_data_service();

$guruId = (int) $master->saveGuru(['nip' => 'BKG001', 'nama_guru' => 'Guru Browser Kredensial', 'no_hp' => '']);
$pengurusId = (int) $master->savePengurus([
    'nama' => 'Pengurus Browser Kredensial',
    'nomor_identitas' => 'BKP001',
    'no_hp' => '081200000021',
    'jabatan' => 'Keamanan',
]);
$santriId = (int) $master->saveSantri([
    'nis' => 'BKS001',
    'nama_santri' => 'Santri Browser Kredensial',
    'jenis_kelamin' => 'L',
    'tgl_lahir' => '2012-03-03',
    'wali' => ['Ayah' => ['mode' => 'baru', 'nama' => 'Wali Browser Kredensial', 'no_hp' => '081200000022', 'alamat' => 'Jl Uji']],
]);
$waliRow = $db->query('SELECT wali_id FROM santri_wali WHERE santri_id = ' . $santriId . ' AND archived_at IS NULL LIMIT 1')?->fetch_assoc();

echo json_encode([
    'admin_username' => 'bk_admin',
    'admin_password' => $sandi,
    'guru_id' => $guruId,
    'pengurus_id' => $pengurusId,
    'wali_id' => (int) ($waliRow['wali_id'] ?? 0),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
