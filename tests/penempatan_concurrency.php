<?php

declare(strict_types=1);

/**
 * Penempatan santri pada PERMINTAAN BERSAMAAN
 * (keputusan pengguna 6 September 2026).
 *
 * Pemeriksaan aplikasi biasa ("hitung dulu, baru simpan") TIDAK cukup: dua
 * admin dapat sama-sama membaca "sisa 1 tempat", lalu sama-sama menyimpan, dan
 * kamar berakhir melebihi kapasitas. Karena itu `PenempatanService` mengunci
 * baris kamar (`SELECT ... FOR UPDATE`) sebelum menghitung penghuni, dan
 * mengunci baris santri lebih dahulu supaya satu santri tidak dapat memperoleh
 * dua kamar sekaligus.
 *
 * Skenario:
 *   KP-1 dua admin mengisi tempat TERAKHIR sebuah kamar pada detik yang sama —
 *        tepat satu berhasil dan kamar tidak pernah melebihi kapasitas;
 *   KP-2 lima admin memperebutkan dua tempat terakhir — tepat dua berhasil;
 *   KP-3 dua admin menempatkan SANTRI YANG SAMA ke dua kamar berbeda pada detik
 *        yang sama — santri tetap hanya memiliki satu kamar;
 *   KP-4 dua admin memindahkan santri yang sama ke dua kelas berbeda —
 *        tetap hanya satu penempatan kelas berstatus Aktif;
 *   KP-5 tidak ada permintaan yang berakhir dengan galat basis data mentah.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   PENEMPATAN_RUN_CONCURRENCY=1 php tests/penempatan_concurrency.php
 */

$root = dirname(__DIR__);
if (getenv('PENEMPATAN_RUN_CONCURRENCY') !== '1') {
    fwrite(STDOUT, "[lewati] Set PENEMPATAN_RUN_CONCURRENCY=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

require_once $root . '/app/bootstrap.php';

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
$kecil = strtolower($suffix);

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
$angka = static function (string $sql) use ($db): int {
    $rs = $db->query($sql);

    return (int) (($rs && $row = $rs->fetch_assoc()) ? ($row['n'] ?? 0) : 0);
};

/**
 * Menjalankan beberapa proses anak pada detik yang sama.
 *
 * @param array<int, array<string, int|string>> $tugas
 * @return array<int, array<string, mixed>>
 */
$jalankanBersamaan = static function (array $tugas) use ($root): array {
    $mulai = microtime(true) + 1.0;
    $proses = [];
    $pipa = [];
    foreach ($tugas as $index => $item) {
        $perintah = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/penempatan_concurrency_worker.php')
            . ' --at=' . escapeshellarg((string) $mulai);
        foreach ($item as $nama => $nilai) {
            $perintah .= ' --' . $nama . '=' . escapeshellarg((string) $nilai);
        }
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

$dibuat = ['users' => [], 'santri' => [], 'kamar' => [], 'kelas' => []];

try {
    $adminId = $exec(
        'INSERT INTO users (name, username, password, is_active, force_password_change, created_at, updated_at)
         VALUES (?, ?, ?, 1, 0, NOW(), NOW())',
        ['Admin Konkurensi ' . $suffix, 'kp.admin.' . $kecil, password_hash('UjiKonkurensi123Aa', PASSWORD_DEFAULT)]
    );
    $dibuat['users'][] = $adminId;
    $exec("INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = 'admin'", [$adminId, $adminId]);
    $_SESSION = ['user_id' => $adminId];

    $year = penempatan_service()->activeYear();
    if ($year === null) {
        fwrite(STDERR, "Tahun ajaran aktif tidak tersedia.\n");
        exit(2);
    }
    $yearId = (int) $year['id'];

    $santri = [];
    for ($i = 1; $i <= 6; $i++) {
        $santri[$i] = $exec(
            "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi,
                                 nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, foto, is_active, created_at, updated_at)
             VALUES (?, ?, 'L', 'Ciamis', '2012-03-01', 'Jl Uji', 'Desa', 'Kec', 'Kab', 'Prov', '', '', '', 'SMK Uji', 'default.jpg', 1, NOW(), NOW())",
            ['KP' . $suffix . $i, 'Santri Konkurensi ' . $i . ' ' . $suffix]
        );
        $dibuat['santri'][] = $santri[$i];
    }

    // ================================================================= KP-1
    $kamarSatuSlot = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 1)', ['KP Kamar 1 ' . $suffix]);
    $dibuat['kamar'][] = $kamarSatuSlot;
    $hasil1 = $jalankanBersamaan([
        ['santri' => $santri[1], 'kamar' => $kamarSatuSlot, 'actor' => $adminId, 'aksi' => 'kamar'],
        ['santri' => $santri[2], 'kamar' => $kamarSatuSlot, 'actor' => $adminId, 'aksi' => 'kamar'],
    ]);
    $berhasil1 = count(array_filter($hasil1, static fn (array $r): bool => (bool) ($r['berhasil'] ?? false)));
    $terisi1 = $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_kamar = {$kamarSatuSlot} AND id_tahun = {$yearId}");
    $assert($berhasil1 === 1, 'KP-1a Dua admin mengisi tempat terakhir bersamaan: tepat satu berhasil [' . $berhasil1 . ']');
    $assert($terisi1 === 1, 'KP-1b Kamar berkapasitas 1 tetap terisi satu orang [' . $terisi1 . ']');
    $ditolak1 = array_values(array_filter($hasil1, static fn (array $r): bool => !($r['berhasil'] ?? false)));
    $assert(
        $ditolak1 !== [] && (
            str_contains((string) ($ditolak1[0]['pesan'] ?? ''), 'Kapasitas')
            || str_contains((string) ($ditolak1[0]['pesan'] ?? ''), 'perubahan bersamaan')
        ),
        'KP-1c Permintaan yang kalah mendapat pesan yang dapat dimengerti admin [' . (string) ($ditolak1[0]['pesan'] ?? '') . ']'
    );

    // ================================================================= KP-2
    $kamarDuaSlot = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 2)', ['KP Kamar 2 ' . $suffix]);
    $dibuat['kamar'][] = $kamarDuaSlot;
    $hasil2 = $jalankanBersamaan(array_map(
        static fn (int $id): array => ['santri' => $id, 'kamar' => $kamarDuaSlot, 'actor' => $adminId, 'aksi' => 'kamar'],
        [$santri[2], $santri[3], $santri[4], $santri[5], $santri[6]]
    ));
    $berhasil2 = count(array_filter($hasil2, static fn (array $r): bool => (bool) ($r['berhasil'] ?? false)));
    $terisi2 = $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_kamar = {$kamarDuaSlot} AND id_tahun = {$yearId}");
    $assert($berhasil2 === 2, 'KP-2a Lima admin memperebutkan dua tempat: tepat dua berhasil [' . $berhasil2 . ']');
    $assert($terisi2 === 2, 'KP-2b Kamar tidak pernah melebihi kapasitas [' . $terisi2 . ' dari 2]');

    // ================================================================= KP-3
    $kamarX = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 5)', ['KP Kamar X ' . $suffix]);
    $kamarY = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 5)', ['KP Kamar Y ' . $suffix]);
    $dibuat['kamar'][] = $kamarX;
    $dibuat['kamar'][] = $kamarY;
    $db->query('DELETE FROM plotting_kamar WHERE id_santri = ' . $santri[1] . ' AND id_tahun = ' . $yearId);
    $hasil3 = $jalankanBersamaan([
        ['santri' => $santri[1], 'kamar' => $kamarX, 'actor' => $adminId, 'aksi' => 'kamar'],
        ['santri' => $santri[1], 'kamar' => $kamarY, 'actor' => $adminId, 'aksi' => 'kamar'],
    ]);
    $barisSantri1 = $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_santri = {$santri[1]} AND id_tahun = {$yearId}");
    $assert(
        $barisSantri1 === 1,
        'KP-3 Santri yang sama ditempatkan ke dua kamar bersamaan tetap memiliki satu kamar [' . $barisSantri1 . ' baris]'
    );
    $assert(
        count(array_filter($hasil3, static fn (array $r): bool => (bool) ($r['berhasil'] ?? false))) >= 1,
        'KP-3 Sedikitnya satu permintaan berhasil (tidak keduanya gagal diam-diam)'
    );

    // ================================================================= KP-4
    $kelasP = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active, created_at, updated_at) VALUES (?, 'Uji', 1, NOW(), NOW())", ['KP Kelas P ' . $suffix]);
    $kelasQ = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active, created_at, updated_at) VALUES (?, 'Uji', 1, NOW(), NOW())", ['KP Kelas Q ' . $suffix]);
    $dibuat['kelas'][] = $kelasP;
    $dibuat['kelas'][] = $kelasQ;
    $hasil4 = $jalankanBersamaan([
        ['santri' => $santri[2], 'kelas' => $kelasP, 'actor' => $adminId, 'aksi' => 'kelas'],
        ['santri' => $santri[2], 'kelas' => $kelasQ, 'actor' => $adminId, 'aksi' => 'kelas'],
    ]);
    $kelasAktif = $angka("SELECT COUNT(*) n FROM plotting_kelas WHERE id_santri = {$santri[2]} AND id_tahun = {$yearId} AND status = 'Aktif'");
    $assert($kelasAktif === 1, 'KP-4 Santri tetap hanya memiliki satu penempatan kelas berstatus Aktif [' . $kelasAktif . ']');
    $assert(
        count(array_filter($hasil4, static fn (array $r): bool => (bool) ($r['berhasil'] ?? false))) >= 1,
        'KP-4 Sedikitnya satu penempatan kelas bersamaan berhasil'
    );

    // ================================================================= KP-5
    $semuaPesan = array_merge($hasil1, $hasil2, $hasil3, $hasil4);
    $bocor = array_filter($semuaPesan, static function (array $r): bool {
        $pesan = (string) ($r['pesan'] ?? '');

        return $pesan !== '' && (
            str_contains($pesan, 'SELECT ') || str_contains($pesan, 'INSERT ') || str_contains($pesan, 'UPDATE ')
            || str_contains($pesan, 'SQLSTATE') || str_contains($pesan, 'mysqli')
        );
    });
    $assert($bocor === [], 'KP-5 Tidak ada pesan yang membocorkan query atau detail internal basis data');
} catch (Throwable $exception) {
    $assert(false, 'Pengujian berhenti karena galat tak terduga: ' . $exception->getMessage());
} finally {
    try {
        foreach ($dibuat['santri'] as $id) {
            $db->query('DELETE FROM plotting_kamar WHERE id_santri = ' . (int) $id);
            $db->query('DELETE FROM plotting_kelas WHERE id_santri = ' . (int) $id);
            $db->query('DELETE FROM santri WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['kamar'] as $id) {
            $db->query('DELETE FROM plotting_kamar WHERE id_kamar = ' . (int) $id);
            $db->query('DELETE FROM kamar WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['kelas'] as $id) {
            $db->query('DELETE FROM plotting_kelas WHERE id_kelas = ' . (int) $id);
            $db->query('DELETE FROM kelas WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['users'] as $id) {
            $db->query('DELETE FROM user_roles WHERE user_id = ' . (int) $id);
            $db->query('DELETE FROM audit_logs WHERE actor_user_id = ' . (int) $id);
            $db->query('DELETE FROM users WHERE id = ' . (int) $id);
        }
    } catch (Throwable $exception) {
        echo '[gagal] Bersih-bersih fixture: ' . $exception->getMessage() . PHP_EOL;
        $failures[] = 'bersih-bersih fixture';
    }
}

echo PHP_EOL;
if ($failures === []) {
    echo 'SELURUH PENGUJIAN PERMINTAAN BERSAMAAN PENEMPATAN LULUS.' . PHP_EOL;
    exit(0);
}
echo 'GAGAL (' . count($failures) . '):' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;
exit(1);
