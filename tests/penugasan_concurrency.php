<?php

declare(strict_types=1);

/**
 * Penugasan pada PERMINTAAN BERSAMAAN (audit fondasi penugasan V3–V6,
 * 7 September 2026).
 *
 * Pemeriksaan aplikasi biasa ("cek dulu, baru simpan") TIDAK cukup: dua
 * permintaan dapat sama-sama membaca "belum ada penugasan yang bertumpang
 * tindih", lalu sama-sama menyimpan. `PenugasanService` mengunci seluruh baris
 * subjek pada tahun ajaran itu (`SELECT ... FOR UPDATE`, isolasi bawaan
 * REPEATABLE READ sehingga InnoDB juga mengunci celah indeks terhadap
 * penyisipan baru) sebelum memeriksa tumpang tindih, dan kunci unik basis data
 * menjadi lapisan kedua untuk penugasan yang identik.
 *
 * Skenario (setiap proses anak adalah proses PHP nyata berkoneksi sendiri):
 *   KP-1 lima permintaan membuat penugasan guru mapel yang BERTUMPANG TINDIH
 *        (tanggal mulai berbeda, cakupan sama) pada detik yang sama — tepat
 *        satu berhasil, sisanya 409;
 *   KP-2 lima permintaan IDENTIK (klik ganda) — tepat satu baris;
 *   KP-3 dua penugasan nonaktif yang saling bertumpang tindih diaktifkan
 *        bersamaan — paling banyak satu yang aktif;
 *   KP-4 permintaan yang kalah menerima pesan yang dapat dimengerti admin,
 *        bukan galat basis data mentah, dan tercatat pada audit;
 *   KP-5 permintaan pada cakupan BERBEDA yang dikirim bersamaan seluruhnya
 *        berhasil (penguncian tidak terlalu luas).
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   PENUGASAN_RUN_CONCURRENCY=1 php tests/penugasan_concurrency.php
 */

$root = dirname(__DIR__);
if (getenv('PENUGASAN_RUN_CONCURRENCY') !== '1') {
    fwrite(STDOUT, "[lewati] Set PENUGASAN_RUN_CONCURRENCY=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

require_once $root . '/app/bootstrap.php';

use App\Penugasan\PenugasanJenis;

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
$hari = static fn (int $selisih): string => date('Y-m-d', strtotime(($selisih >= 0 ? '+' : '') . $selisih . ' days'));

$satu = static function (string $sql) use ($db): array {
    $rs = $db->query($sql);

    return ($rs && $row = $rs->fetch_assoc()) ? $row : [];
};
$angka = static fn (string $sql): int => (int) ($satu($sql)['n'] ?? 0);
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

/**
 * Menjalankan beberapa proses anak pada waktu mulai yang sama.
 *
 * @param array<int, array{aksi:string, jenis:string, isian:array<string, mixed>}> $tugas
 * @return array<int, array<string, mixed>>
 */
$jalankanBersamaan = static function (array $tugas, int $actorId) use ($root): array {
    $mulai = microtime(true) + 1.5;
    $proses = [];
    $pipa = [];
    foreach ($tugas as $index => $t) {
        $perintah = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/penugasan_concurrency_worker.php')
            . ' --at=' . escapeshellarg((string) $mulai)
            . ' --aksi=' . escapeshellarg($t['aksi'])
            . ' --jenis=' . escapeshellarg($t['jenis'])
            . ' --isian=' . escapeshellarg((string) json_encode($t['isian']))
            . ' --actor=' . escapeshellarg((string) $actorId);
        $proses[$index] = proc_open($perintah, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipa[$index], $root);
        if (!is_resource($proses[$index])) {
            throw new RuntimeException('Proses anak gagal dijalankan.');
        }
    }
    $hasil = [];
    foreach ($proses as $index => $p) {
        $keluaran = stream_get_contents($pipa[$index][1]);
        $galat = stream_get_contents($pipa[$index][2]);
        fclose($pipa[$index][1]);
        fclose($pipa[$index][2]);
        proc_close($p);
        $baris = json_decode((string) trim((string) $keluaran), true);
        $hasil[$index] = is_array($baris) ? $baris : ['berhasil' => false, 'pesan' => 'keluaran tidak terbaca: ' . $keluaran . $galat];
    }

    return $hasil;
};

$tahunAktif = (int) ($satu("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1")['id'] ?? 0);
if ($tahunAktif < 1) {
    fwrite(STDOUT, "[lewati] Tidak ada tahun ajaran aktif pada database uji.\n");
    exit(77);
}

$dibuat = ['users' => [], 'guru' => [], 'pengurus' => [], 'kelas' => [], 'mapel' => []];
$mulaiUji = date('Y-m-d H:i:s');

try {
    $kelasA = $exec('INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES (?, ?, 1)', ['Kelas KP A ' . $suffix, 'KP' . $suffix]);
    $kelasB = $exec('INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES (?, ?, 1)', ['Kelas KP B ' . $suffix, 'KP' . $suffix]);
    array_push($dibuat['kelas'], $kelasA, $kelasB);
    $guru = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['KP1' . $suffix, 'Guru KP ' . $suffix]);
    $dibuat['guru'][] = $guru;
    $pengurus = $exec("INSERT INTO pengurus (nama, nomor_identitas, jabatan, is_active) VALUES (?, ?, 'Keamanan', 1)", ['Pengurus KP ' . $suffix, 'KPP' . $suffix]);
    $dibuat['pengurus'][] = $pengurus;
    $adminId = $exec(
        'INSERT INTO users (name, username, password, is_active, force_password_change, created_at, updated_at) VALUES (?, ?, ?, 1, 0, NOW(), NOW())',
        ['Admin KP ' . $suffix, 'kp.admin.' . $kecil, password_hash('UjiKp123Aa', PASSWORD_DEFAULT)]
    );
    $dibuat['users'][] = $adminId;
    $exec("INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, NULL FROM roles WHERE slug = 'admin'", [$adminId]);
    $_SESSION = ['user_id' => $adminId];
    $mapel = penugasan_service()->simpanMataPelajaran(['nama' => 'Mapel KP ' . $suffix, 'kode' => 'KP' . $suffix], null, $adminId);
    $dibuat['mapel'][] = $mapel;

    $isianDasar = ['guru_id' => $guru, 'mata_pelajaran_id' => $mapel, 'kelas_id' => $kelasA, 'tahun_ajaran_id' => $tahunAktif, 'tanggal_selesai' => ''];

    // =============================================================== KP-1
    $tugas = [];
    for ($i = 0; $i < 5; $i++) {
        $tugas[] = ['aksi' => 'buat', 'jenis' => PenugasanJenis::GURU_MAPEL, 'isian' => $isianDasar + ['tanggal_mulai' => $hari($i)]];
    }
    $hasil = $jalankanBersamaan($tugas, $adminId);
    $berhasil = array_values(array_filter($hasil, static fn (array $h): bool => $h['berhasil'] === true));
    $kalah = array_values(array_filter($hasil, static fn (array $h): bool => $h['berhasil'] !== true));
    $assert(count($berhasil) === 1, 'KP-1 lima permintaan bertumpang tindih bersamaan: tepat satu berhasil (' . count($berhasil) . ')');
    $assert($angka('SELECT COUNT(*) n FROM guru_mapel_assignments WHERE guru_id = ' . $guru . ' AND is_active = 1') === 1, 'KP-1 hanya satu baris penugasan aktif tersimpan');
    $assert(count($kalah) === 4 && count(array_filter($kalah, static fn (array $h): bool => ($h['status'] ?? null) === 409)) === 4, 'KP-1 empat permintaan yang kalah dijawab 409 tumpang tindih');
    $assert(
        count(array_filter($kalah, static fn (array $h): bool => str_contains((string) $h['pesan'], 'bertumpang tindih'))) === 4,
        'KP-4 permintaan yang kalah menerima pesan yang dapat dimengerti, bukan galat basis data'
    );
    $assert($angka("SELECT COUNT(*) n FROM audit_logs WHERE action = 'penugasan.tolak_tumpang_tindih' AND actor_user_id = " . $adminId) >= 4, 'KP-4 penolakan bersamaan tercatat pada audit');
    $assert($angka("SELECT COUNT(*) n FROM audit_logs WHERE action = 'penugasan.buat' AND actor_user_id = " . $adminId . " AND entity_type = 'guru_mapel_assignment'") === 1, 'KP-4 hanya satu audit pembuatan (transaksi yang kalah dibatalkan utuh)');

    // =============================================================== KP-2
    $isianKelasB = ['guru_id' => $guru, 'mata_pelajaran_id' => $mapel, 'kelas_id' => $kelasB, 'tahun_ajaran_id' => $tahunAktif, 'tanggal_mulai' => $hari(0), 'tanggal_selesai' => ''];
    $hasil = $jalankanBersamaan(array_fill(0, 5, ['aksi' => 'buat', 'jenis' => PenugasanJenis::GURU_MAPEL, 'isian' => $isianKelasB]), $adminId);
    $berhasil = array_filter($hasil, static fn (array $h): bool => $h['berhasil'] === true);
    $assert(count($berhasil) === 1, 'KP-2 lima permintaan identik (klik ganda): tepat satu berhasil (' . count($berhasil) . ')');
    $assert($angka('SELECT COUNT(*) n FROM guru_mapel_assignments WHERE guru_id = ' . $guru . ' AND kelas_id = ' . $kelasB) === 1, 'KP-2 hanya satu baris untuk cakupan itu');
    $assert(
        count(array_filter($hasil, static fn (array $h): bool => $h['berhasil'] !== true && ($h['status'] ?? null) === 409)) === 4,
        'KP-2 permintaan identik yang kalah dijawab 409'
    );

    // =============================================================== KP-3
    $service = penugasan_service();
    $p1 = $service->buat(PenugasanJenis::PENDIDIKAN, ['pengurus_id' => $pengurus, 'tahun_ajaran_id' => $tahunAktif, 'jenjang' => '', 'tanggal_mulai' => $hari(0)], $adminId);
    $service->nonaktifkan(PenugasanJenis::PENDIDIKAN, $p1, 'Siapkan uji bersamaan', $adminId);
    $p2 = $service->buat(PenugasanJenis::PENDIDIKAN, ['pengurus_id' => $pengurus, 'tahun_ajaran_id' => $tahunAktif, 'jenjang' => 'KP' . $suffix, 'tanggal_mulai' => $hari(0)], $adminId);
    $service->nonaktifkan(PenugasanJenis::PENDIDIKAN, $p2, 'Siapkan uji bersamaan', $adminId);
    $hasil = $jalankanBersamaan([
        ['aksi' => 'aktifkan', 'jenis' => PenugasanJenis::PENDIDIKAN, 'isian' => ['id' => $p1]],
        ['aksi' => 'aktifkan', 'jenis' => PenugasanJenis::PENDIDIKAN, 'isian' => ['id' => $p2]],
    ], $adminId);
    $aktif = $angka('SELECT COUNT(*) n FROM pendidikan_assignments WHERE pengurus_id = ' . $pengurus . ' AND is_active = 1');
    $assert($aktif === 1, 'KP-3 dua pengaktifan bertumpang tindih bersamaan: tepat satu yang aktif (' . $aktif . ')');
    $assert(count(array_filter($hasil, static fn (array $h): bool => $h['berhasil'] === true)) === 1, 'KP-3 tepat satu permintaan pengaktifan yang berhasil');

    // =============================================================== KP-5
    $tugas = [
        ['aksi' => 'buat', 'jenis' => PenugasanJenis::PANITIA_PSB, 'isian' => ['pengurus_id' => $pengurus, 'tahun_ajaran_id' => $tahunAktif, 'gelombang' => '1', 'tanggal_mulai' => $hari(0)]],
        ['aksi' => 'buat', 'jenis' => PenugasanJenis::PANITIA_PSB, 'isian' => ['pengurus_id' => $pengurus, 'tahun_ajaran_id' => $tahunAktif, 'gelombang' => '2', 'tanggal_mulai' => $hari(0)]],
        ['aksi' => 'buat', 'jenis' => PenugasanJenis::BENDAHARA_PSB, 'isian' => ['pengurus_id' => $pengurus, 'tahun_ajaran_id' => $tahunAktif, 'gelombang' => '', 'tanggal_mulai' => $hari(0)]],
        ['aksi' => 'buat', 'jenis' => PenugasanJenis::BENDAHARA_BULANAN, 'isian' => ['pengurus_id' => $pengurus, 'tahun_ajaran_id' => $tahunAktif, 'jenjang' => '', 'tanggal_mulai' => $hari(0)]],
    ];
    $hasil = $jalankanBersamaan($tugas, $adminId);
    $assert(
        count(array_filter($hasil, static fn (array $h): bool => $h['berhasil'] === true)) === 4,
        'KP-5 empat permintaan cakupan/jenis berbeda yang dikirim bersamaan seluruhnya berhasil'
    );
} finally {
    $_SESSION = [];
    foreach ($dibuat['users'] as $id) {
        $db->query('DELETE FROM audit_logs WHERE actor_user_id = ' . (int) $id);
    }
    foreach ($dibuat['guru'] as $id) {
        $db->query('DELETE FROM guru_mapel_assignments WHERE guru_id = ' . (int) $id);
    }
    foreach ($dibuat['pengurus'] as $id) {
        foreach (['pendidikan_assignments', 'bendahara_bulanan_assignments', 'panitia_psb_assignments', 'bendahara_psb_assignments'] as $t) {
            $db->query('DELETE FROM ' . $t . ' WHERE pengurus_id = ' . (int) $id);
        }
    }
    foreach ($dibuat['mapel'] as $id) {
        $db->query('DELETE FROM mata_pelajaran WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['users'] as $id) {
        $db->query('DELETE FROM user_roles WHERE user_id = ' . (int) $id);
        $db->query('DELETE FROM users WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['guru'] as $id) {
        $db->query('DELETE FROM guru WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['pengurus'] as $id) {
        $db->query('DELETE FROM pengurus WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['kelas'] as $id) {
        $db->query('DELETE FROM kelas WHERE id = ' . (int) $id);
    }
}

echo PHP_EOL . 'Total gagal: ' . count($failures) . PHP_EOL;
exit($failures === [] ? 0 : 1);
