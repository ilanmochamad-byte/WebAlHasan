<?php

declare(strict_types=1);

/**
 * Fixture untuk uji browser "Penempatan Kelas & Kamar Santri"
 * (keputusan pengguna 6 September 2026).
 *
 * Membuat satu akun admin uji, dua kelas, tiga kamar (salah satunya hampir
 * penuh), dan sejumlah santri dengan keadaan berbeda-beda: ada yang belum punya
 * kelas, belum punya kamar, dan sudah lengkap. Cukup untuk menguji seluruh
 * filter, pagination, penempatan individual, penempatan massal, konfirmasi,
 * dan pesan kesalahan kapasitas pada peramban sungguhan.
 *
 * Seluruh data FIKTIF dan diberi awalan `bp_` / `BP` agar mudah dibersihkan.
 * Tidak ada data pribadi dan tidak ada kredensial nyata.
 *
 * Membuat fixture (mencetak JSON ke stdout):
 *   PENEMPATAN_SEED=1 php tests/browser/seed-penempatan.php
 *
 * Membersihkan kembali:
 *   PENEMPATAN_SEED=1 php tests/browser/seed-penempatan.php --bersihkan
 */

$root = dirname(__DIR__, 2);
if (getenv('PENEMPATAN_SEED') !== '1') {
    fwrite(STDERR, "Set PENEMPATAN_SEED=1 untuk menjalankan seed fixture.\n");
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
$hapus("DELETE pk FROM plotting_kamar pk JOIN santri s ON s.id = pk.id_santri WHERE s.nis LIKE 'BPS%'");
$hapus("DELETE pk FROM plotting_kelas pk JOIN santri s ON s.id = pk.id_santri WHERE s.nis LIKE 'BPS%'");
$hapus("DELETE pk FROM plotting_kamar pk JOIN kamar k ON k.id = pk.id_kamar WHERE k.nama_kamar LIKE 'BP Kamar%'");
$hapus("DELETE pk FROM plotting_kelas pk JOIN kelas k ON k.id = pk.id_kelas WHERE k.nama_kelas LIKE 'BP Kelas%'");
$hapus("DELETE sw FROM santri_wali sw JOIN santri s ON s.id = sw.santri_id WHERE s.nis LIKE 'BPS%'");
$hapus("DELETE FROM santri WHERE nis LIKE 'BPS%'");
$hapus("DELETE FROM kamar WHERE nama_kamar LIKE 'BP Kamar%'");
$hapus("DELETE FROM kelas WHERE nama_kelas LIKE 'BP Kelas%'");
$hapus("DELETE ur FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE u.username LIKE 'bp\\_%'");
$hapus("DELETE al FROM audit_logs al JOIN users u ON u.id = al.actor_user_id WHERE u.username LIKE 'bp\\_%'");
$hapus("DELETE FROM users WHERE username LIKE 'bp\\_%'");

if ($bersihkan) {
    echo "Fixture uji browser penempatan dibersihkan.\n";
    exit(0);
}

$sandi = 'UjiBrowser#Penempatan9';
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
    ['Admin Uji Browser Penempatan', 'bp_admin', password_hash($sandi, PASSWORD_DEFAULT)]
);
$exec("INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = 'admin'", [$adminId, $adminId]);

$tahun = $db->query("SELECT id, tahun, semester FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1")?->fetch_assoc();
if ($tahun === null) {
    fwrite(STDERR, "Tahun ajaran aktif belum tersedia. Jalankan fixture Fase 3 terlebih dahulu.\n");
    exit(2);
}
$tahunId = (int) $tahun['id'];

$kelasSatu = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active, created_at, updated_at) VALUES ('BP Kelas Satu', 'Ula', 1, NOW(), NOW())");
$kelasDua = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active, created_at, updated_at) VALUES ('BP Kelas Dua', 'Ula', 1, NOW(), NOW())");

$kamarLapang = $exec("INSERT INTO kamar (nama_kamar, kapasitas) VALUES ('BP Kamar Lapang', 12)");
$kamarSedang = $exec("INSERT INTO kamar (nama_kamar, kapasitas) VALUES ('BP Kamar Sedang', 4)");
$kamarPenuh = $exec("INSERT INTO kamar (nama_kamar, kapasitas) VALUES ('BP Kamar Penuh', 1)");

$unit = ['SMK Terpadu Al Hasan', 'SMP Terpadu Al Hasan'];
$santri = [];
for ($i = 1; $i <= 26; $i++) {
    $nomor = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $santri[$i] = $exec(
        "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi,
                             nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, foto, is_active, created_at, updated_at)
         VALUES (?, ?, ?, 'Ciamis', '2012-01-01', 'Alamat Uji', 'Desa Uji', 'Kecamatan Uji', 'Ciamis', 'Jawa Barat',
                 '', '', '', ?, 'default.jpg', 1, NOW(), NOW())",
        ['BPS' . $nomor, 'Santri Uji ' . $nomor, $i % 2 === 0 ? 'P' : 'L', $unit[$i % 2]]
    );
}

// Santri 1–6 sudah punya kelas; 1–3 juga sudah punya kamar. Sisanya sengaja
// dibiarkan kosong agar filter "belum mempunyai kelas/kamar" ada isinya.
foreach ([1, 2, 3, 4, 5, 6] as $i) {
    $exec(
        "INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, tanggal_mulai, status, created_by, created_at, updated_at)
         VALUES (?, ?, ?, CURDATE(), 'Aktif', ?, NOW(), NOW())",
        [$santri[$i], $i <= 3 ? $kelasSatu : $kelasDua, $tahunId, $adminId]
    );
}
foreach ([1, 2, 3] as $i) {
    $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santri[$i], $kamarLapang, $tahunId]);
}
// Kamar berkapasitas 1 sengaja dibuat penuh untuk menguji pesan kesalahan.
$exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santri[4], $kamarPenuh, $tahunId]);

echo json_encode([
    'admin' => ['username' => 'bp_admin', 'password' => $sandi],
    'tahun_ajaran' => $tahun['tahun'] . ' ' . $tahun['semester'],
    'kelas' => ['satu' => $kelasSatu, 'dua' => $kelasDua],
    'kamar' => ['lapang' => $kamarLapang, 'sedang' => $kamarSedang, 'penuh' => $kamarPenuh],
    'santri' => $santri,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
