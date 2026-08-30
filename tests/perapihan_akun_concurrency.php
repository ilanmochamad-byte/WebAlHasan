<?php

declare(strict_types=1);

/**
 * Perlindungan admin terakhir pada PERMINTAAN BERSAMAAN.
 *
 * Paket "Koreksi dan Modernisasi UI/UX V1–V2", koreksi ke-1, kriteria
 * penerimaan: "Admin terakhir tetap terlindungi, termasuk pada permintaan
 * bersamaan."
 *
 * Pemeriksaan aplikasi biasa ("hitung dulu, baru ubah") TIDAK cukup untuk kasus
 * ini: dua permintaan dapat sama-sama membaca "masih ada 2 admin", lalu
 * sama-sama mencabut, dan sistem berakhir tanpa admin. Karena itu
 * `AccountService` melakukan pencabutan di dalam transaksi yang lebih dulu
 * mengunci baris relasi admin (`SELECT ... FOR UPDATE`).
 *
 * Skenario:
 *   KC-1  N proses PHP NYATA mencabut hak admin dari N akun admin berbeda pada
 *         detik yang sama. Tepat N-1 boleh berhasil; sistem WAJIB menyisakan
 *         sedikitnya satu admin aktif.
 *   KC-2  Hal yang sama untuk penonaktifan akun admin secara bersamaan.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   PERAPIHAN_RUN_CONCURRENCY=1 php tests/perapihan_akun_concurrency.php
 */

$root = dirname(__DIR__);
if (getenv('PERAPIHAN_RUN_CONCURRENCY') !== '1') {
    fwrite(STDOUT, "[lewati] Set PERAPIHAN_RUN_CONCURRENCY=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

require_once $root . '/app/bootstrap.php';

use App\Account\AccountService;

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Ditolak: pengujian ini hanya boleh berjalan pada database berakhiran _test.\n");
    exit(2);
}

$db = app_db();
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};

$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$adminRow = $db->query(
    "SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
      WHERE r.slug = 'admin' AND u.is_active = 1 ORDER BY u.id LIMIT 1"
)?->fetch_assoc();
if (!$adminRow) {
    fwrite(STDERR, "Akun admin fixture tidak tersedia.\n");
    exit(2);
}
$aktorId = (int) $adminRow['id'];
$_SESSION = ['user_id' => $aktorId];

$adminAwal = (int) ($db->query(
    "SELECT COUNT(*) AS c FROM users u JOIN user_roles ur ON ur.user_id = u.id
       JOIN roles r ON r.id = ur.role_id AND r.slug = 'admin' WHERE u.is_active = 1"
)?->fetch_assoc()['c'] ?? 0);

$akun = account_service();
$master = master_data_service();
$dibuat = ['users' => [], 'guru' => []];

$hitungAdmin = static fn (): int => (int) ($GLOBALS['db']->query(
    "SELECT COUNT(*) AS c FROM users u JOIN user_roles ur ON ur.user_id = u.id
       JOIN roles r ON r.id = ur.role_id AND r.slug = 'admin' WHERE u.is_active = 1"
)?->fetch_assoc()['c'] ?? 0);
$GLOBALS['db'] = $db;

/**
 * Membuat N akun admin tambahan sebagai bahan uji.
 *
 * @return array<int, int>
 */
$buatAdmin = static function (int $jumlah, string $tag) use ($akun, $master, $aktorId, $suffix, &$dibuat): array {
    $ids = [];
    for ($i = 1; $i <= $jumlah; $i++) {
        $guruId = $master->saveGuru(['nip' => 'CC' . $tag . $i . $suffix, 'nama_guru' => 'Guru CC ' . $tag . $i . ' ' . $suffix, 'no_hp' => '']);
        $dibuat['guru'][] = $guruId;
        $hasil = $akun->createTeacher([
            'guru_id' => $guruId,
            'name' => 'Admin Uji ' . $tag . $i . ' ' . $suffix,
            'username' => 'cc' . strtolower($tag . $i . $suffix),
            'email' => '', 'phone' => '',
        ], $aktorId);
        $id = (int) $hasil['id'];
        $dibuat['users'][] = $id;
        $akun->grantRole($id, 'admin', $aktorId, ['konfirmasi_admin' => AccountService::KONFIRMASI_ADMIN]);
        $ids[] = $id;
    }

    return $ids;
};

/**
 * Menjalankan beberapa proses anak pada detik yang sama.
 *
 * @param array<int, array{user:int, aksi:string}> $tugas
 * @return array<int, array<string, mixed>>
 */
$jalankanBersamaan = static function (array $tugas) use ($root, $aktorId): array {
    $mulai = microtime(true) + 1.0;
    $proses = [];
    $pipa = [];
    foreach ($tugas as $index => $item) {
        $perintah = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/perapihan_akun_concurrency_worker.php')
            . ' --at=' . escapeshellarg((string) $mulai)
            . ' --user=' . escapeshellarg((string) $item['user'])
            . ' --actor=' . escapeshellarg((string) $aktorId)
            . ' --aksi=' . escapeshellarg($item['aksi']);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
        $proses[$index] = proc_open($perintah, $descriptors, $pipa[$index], $root);
    }
    $hasil = [];
    foreach ($proses as $index => $handle) {
        if (!is_resource($handle)) {
            continue;
        }
        $keluaran = stream_get_contents($pipa[$index][1]);
        fclose($pipa[$index][1]);
        proc_close($handle);
        $baris = json_decode(trim((string) $keluaran), true);
        $hasil[] = is_array($baris) ? $baris : ['berhasil' => false, 'pesan' => 'keluaran tidak valid'];
    }

    return $hasil;
};

try {
    // =====================================================================
    echo PHP_EOL . '=== KC-1. Pencabutan role admin bersamaan ===' . PHP_EOL;
    // =====================================================================
    //
    // Kondisi awal dibuat sesempit mungkin: hanya akun-akun uji yang menjadi
    // admin aktif, sehingga "admin terakhir" benar-benar diuji. Akun admin
    // fixture untuk sementara dinonaktifkan rolenya, lalu dikembalikan.

    $adminUji = $buatAdmin(3, 'A');
    $adminLain = $db->query(
        "SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id
           JOIN roles r ON r.id = ur.role_id AND r.slug = 'admin'
          WHERE u.is_active = 1 AND u.id NOT IN (" . implode(',', $adminUji) . ')'
    );
    $dicabutSementara = [];
    while ($adminLain && $row = $adminLain->fetch_assoc()) {
        $dicabutSementara[] = (int) $row['id'];
    }
    foreach ($dicabutSementara as $id) {
        $db->query("DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = {$id} AND r.slug = 'admin'");
    }

    $sebelum = $hitungAdmin();
    $assert($sebelum === count($adminUji), 'KC-0 Kondisi awal: tepat ' . count($adminUji) . ' admin aktif [' . $sebelum . ']');

    $hasil = $jalankanBersamaan(array_map(static fn (int $id): array => ['user' => $id, 'aksi' => 'revoke'], $adminUji));
    $berhasil = count(array_filter($hasil, static fn (array $r): bool => (bool) ($r['berhasil'] ?? false)));
    $sesudah = $hitungAdmin();

    $assert(
        $sesudah >= 1,
        'KC-1a Setelah ' . count($adminUji) . ' pencabutan bersamaan, sistem TETAP memiliki admin aktif [' . $sesudah . ']'
    );
    $assert(
        $berhasil === count($adminUji) - 1,
        'KC-1b Tepat ' . (count($adminUji) - 1) . ' pencabutan berhasil, sisanya ditolak [' . $berhasil . ']'
    );
    $ditolak = array_values(array_filter($hasil, static fn (array $r): bool => !($r['berhasil'] ?? false)));
    $assert(
        $ditolak !== [] && str_contains((string) ($ditolak[0]['pesan'] ?? ''), 'admin aktif terakhir'),
        'KC-1c Permintaan yang ditolak menjelaskan sebabnya kepada pengguna'
    );

    // Kembalikan role admin pada akun yang tersisa dan pulihkan admin semula.
    foreach ($adminUji as $id) {
        $db->query("INSERT IGNORE INTO user_roles (user_id, role_id, assigned_by) SELECT {$id}, id, {$aktorId} FROM roles WHERE slug = 'admin'");
    }
    foreach ($dicabutSementara as $id) {
        $db->query("INSERT IGNORE INTO user_roles (user_id, role_id, assigned_by) SELECT {$id}, id, {$aktorId} FROM roles WHERE slug = 'admin'");
    }

    // =====================================================================
    echo PHP_EOL . '=== KC-2. Penonaktifan akun admin bersamaan ===' . PHP_EOL;
    // =====================================================================

    foreach ($dicabutSementara as $id) {
        $db->query("DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = {$id} AND r.slug = 'admin'");
    }
    $sebelum2 = $hitungAdmin();
    $assert($sebelum2 === count($adminUji), 'KC-2a Kondisi awal ronde kedua: ' . count($adminUji) . ' admin aktif [' . $sebelum2 . ']');

    $hasil2 = $jalankanBersamaan(array_map(static fn (int $id): array => ['user' => $id, 'aksi' => 'nonaktif'], $adminUji));
    $berhasil2 = count(array_filter($hasil2, static fn (array $r): bool => (bool) ($r['berhasil'] ?? false)));
    $sesudah2 = $hitungAdmin();

    $assert($sesudah2 >= 1, 'KC-2b Setelah penonaktifan bersamaan, sistem TETAP memiliki admin aktif [' . $sesudah2 . ']');
    $assert(
        $berhasil2 === count($adminUji) - 1,
        'KC-2c Tepat ' . (count($adminUji) - 1) . ' penonaktifan berhasil, sisanya ditolak [' . $berhasil2 . ']'
    );

    foreach ($dicabutSementara as $id) {
        $db->query("INSERT IGNORE INTO user_roles (user_id, role_id, assigned_by) SELECT {$id}, id, {$aktorId} FROM roles WHERE slug = 'admin'");
        $db->query('UPDATE users SET is_active = 1 WHERE id = ' . $id);
    }
} catch (Throwable $exception) {
    $assert(false, 'Pengujian terhenti: ' . $exception->getMessage());
} finally {
    foreach ($dibuat['users'] as $id) {
        $db->query('DELETE FROM user_roles WHERE user_id = ' . (int) $id);
        $db->query('DELETE FROM perangkat_push WHERE user_id = ' . (int) $id);
        $db->query('DELETE FROM users WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['guru'] as $id) {
        $db->query('DELETE FROM guru WHERE id = ' . (int) $id);
    }
    $akhir = (int) ($db->query(
        "SELECT COUNT(*) AS c FROM users u JOIN user_roles ur ON ur.user_id = u.id
           JOIN roles r ON r.id = ur.role_id AND r.slug = 'admin' WHERE u.is_active = 1"
    )?->fetch_assoc()['c'] ?? 0);
    echo '[bersih] Fixture concurrency dihapus. Admin aktif awal ' . $adminAwal . ', akhir ' . $akhir . '.' . PHP_EOL;
}

echo PHP_EOL;
if ($failures === []) {
    echo 'PERLINDUNGAN ADMIN TERAKHIR LULUS PADA PERMINTAAN BERSAMAAN.' . PHP_EOL;
    exit(0);
}
echo 'GAGAL (' . count($failures) . "):" . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;
exit(1);
