<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Izin\IzinException;
use App\Izin\IzinRouter;

/**
 * Pengujian integrasi V2 Fase 2 — pengajuan, routing, keputusan.
 *
 * Menjalankan setiap kriteria penerimaan Fase 2 pada basis data sungguhan:
 * cakupan pembimbing, validasi tanggal, idempotensi, tumpang tindih, routing
 * satu/nol/lebih dari satu murobi, akses silang antarperan, dua keputusan
 * bersamaan (dua proses PHP nyata), keputusan Admin Pengganti, pembatalan,
 * koreksi, serta kelengkapan riwayat dan audit.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   V2_PHASE2_RUN_INTEGRATION=1 php tests/v2_phase2_integration.php
 */

$root = dirname(__DIR__);
if (getenv('V2_PHASE2_RUN_INTEGRATION') !== '1') {
    fwrite(STDOUT, "[lewati] Set V2_PHASE2_RUN_INTEGRATION=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}
require_once $root . '/app/bootstrap.php';
if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Pengujian integrasi ditolak: DB_NAME wajib berakhiran _test.\n");
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

/** Menjalankan callback dan memastikan IzinException dengan status tertentu. */
$expectStatus = static function (int $status, callable $callback, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message . ' (tidak ada penolakan)');
    } catch (IzinException $exception) {
        $assert(
            $exception->status() === $status,
            $message . ' [' . $exception->status() . ($exception->status() === $status ? '' : ' ≠ ' . $status) . ']'
        );
    }
};

$meta = ['ip' => '203.0.113.10', 'user_agent' => 'uji-integrasi-fase-2'];
$key = static fn (string $prefix): string => $prefix . '-' . bin2hex(random_bytes(8));

$adminRow = $db->query(
    "SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
      WHERE r.slug = 'admin' AND u.is_active = 1 LIMIT 1"
)?->fetch_assoc();
if (!$adminRow) {
    fwrite(STDERR, "Akun admin fixture tidak tersedia.\n");
    exit(2);
}
$adminId = (int) $adminRow['id'];
$_SESSION = ['user_id' => $adminId];

$activeYear = $db->query("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1")?->fetch_assoc();
if (!$activeYear) {
    fwrite(STDERR, "Tahun ajaran aktif tidak tersedia.\n");
    exit(2);
}
$yearId = (int) $activeYear['id'];

$suffix = strtoupper(bin2hex(random_bytes(3)));
$created = [
    'users' => [], 'pengurus' => [], 'wali' => [], 'santri_wali' => [], 'santri' => [],
    'guru' => [], 'kamar' => [], 'kelas' => [], 'murobi' => [], 'pembimbing' => [],
    'plotting_kamar' => [], 'plotting_kelas' => [], 'izin' => [],
];

$exec = static function (string $sql, array $params = []) use ($db): int {
    $statement = $db->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Fixture gagal disiapkan: ' . $db->error . ' | ' . $sql);
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
        throw new RuntimeException('Fixture gagal dijalankan: ' . $error . ' | ' . $sql);
    }
    $id = (int) $statement->insert_id;
    $statement->close();

    return $id;
};

$countPengajuan = static fn (): int => (int) ($db->query('SELECT COUNT(*) AS jumlah FROM izin_pengajuan')?->fetch_assoc()['jumlah'] ?? 0);

/** Menjalankan dua proses PHP yang benar-benar bersamaan. */
$runConcurrently = static function (array $argsA, array $argsB) use ($root): array {
    $startAt = microtime(true) + 1.5;
    $build = static function (array $args) use ($root, $startAt): string {
        $args['at'] = (string) $startAt;
        $parts = [escapeshellarg(PHP_BINARY), escapeshellarg($root . '/tests/v2_phase2_concurrency_worker.php')];
        foreach ($args as $name => $value) {
            $parts[] = escapeshellarg('--' . $name . '=' . $value);
        }
        return implode(' ', $parts);
    };

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipesA = $pipesB = [];
    $processA = proc_open($build($argsA), $descriptors, $pipesA, $root);
    $processB = proc_open($build($argsB), $descriptors, $pipesB, $root);
    if (!is_resource($processA) || !is_resource($processB)) {
        throw new RuntimeException('Proses uji konkurensi tidak dapat dijalankan.');
    }
    $outA = (string) stream_get_contents($pipesA[1]);
    $errA = (string) stream_get_contents($pipesA[2]);
    $outB = (string) stream_get_contents($pipesB[1]);
    $errB = (string) stream_get_contents($pipesB[2]);
    foreach ([$pipesA, $pipesB] as $pipes) {
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
    }
    proc_close($processA);
    proc_close($processB);

    $decode = static function (string $out, string $err): array {
        $line = trim($out);
        $decoded = $line === '' ? null : json_decode($line, true);
        return is_array($decoded) ? $decoded : ['ok' => false, 'status' => 500, 'message' => trim($err . ' ' . $out)];
    };

    return [$decode($outA, $errA), $decode($outB, $errB)];
};

try {
    // =====================================================================
    // Fixture master data
    // =====================================================================
    $kamarSatu = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 20)', ['Kamar F2A ' . $suffix]);
    $kamarDua = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 20)', ['Kamar F2B ' . $suffix]);
    $kamarTiga = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 20)', ['Kamar F2C ' . $suffix]);
    $created['kamar'] = [$kamarSatu, $kamarDua, $kamarTiga];
    $kelasSatu = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES (?, 'Uji', 1)", ['Kelas F2 ' . $suffix]);
    $kelasNonaktif = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES (?, 'Uji', 1)", ['Kelas F2 Nonaktif ' . $suffix]);
    $created['kelas'] = [$kelasSatu, $kelasNonaktif];

    $santriSql = "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan,
                    kab_kota, provinsi, nama_ayah, no_hp_ayah, nama_ibu, no_hp_ibu, asal_sekolah, sekolah_saat_ini, is_active)
                  VALUES (?, ?, 'L', 'Ciamis', '2010-01-01', 'Alamat', 'Desa', 'Kec', 'Ciamis', 'Jabar', '', NULL, '', NULL, 'A', 'B', 1)";
    $santriSatu = $exec($santriSql, ['F2A' . $suffix, 'Santri Satu Murobi ' . $suffix]);   // routing = 1 kandidat
    $santriGanda = $exec($santriSql, ['F2B' . $suffix, 'Santri Dua Murobi ' . $suffix]);   // routing = 2 kandidat
    $santriNol = $exec($santriSql, ['F2C' . $suffix, 'Santri Tanpa Murobi ' . $suffix]);   // routing = 0 kandidat
    $santriLuar = $exec($santriSql, ['F2D' . $suffix, 'Santri Luar Cakupan ' . $suffix]);  // di luar cakupan pengurus A
    $santriKelasNonaktif = $exec($santriSql, ['F2E' . $suffix, 'Santri Kelas Nonaktif ' . $suffix]);
    $created['santri'] = [$santriSatu, $santriGanda, $santriNol, $santriLuar, $santriKelasNonaktif];

    $created['plotting_kamar'][] = $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santriSatu, $kamarSatu, $yearId]);
    $created['plotting_kamar'][] = $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santriGanda, $kamarDua, $yearId]);
    $created['plotting_kamar'][] = $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santriNol, $kamarTiga, $yearId]);
    $created['plotting_kelas'][] = $exec("INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, status) VALUES (?, ?, ?, 'Aktif')", [$santriGanda, $kelasSatu, $yearId]);
    $created['plotting_kelas'][] = $exec("INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, status) VALUES (?, ?, ?, 'Aktif')", [$santriKelasNonaktif, $kelasNonaktif, $yearId]);

    $guruSatu = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['F2G1' . $suffix, 'Guru Murobi Satu ' . $suffix]);
    $guruDua = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['F2G2' . $suffix, 'Guru Murobi Dua ' . $suffix]);
    $guruTiga = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['F2G3' . $suffix, 'Guru Murobi Tiga ' . $suffix]);
    $guruBiasa = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['F2G4' . $suffix, 'Guru Tanpa Penugasan ' . $suffix]);
    $guruKelasNonaktif = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['F2G5' . $suffix, 'Guru Kelas Nonaktif ' . $suffix]);
    $created['guru'] = [$guruSatu, $guruDua, $guruTiga, $guruBiasa, $guruKelasNonaktif];

    $murobiSql = "INSERT INTO murobi_assignments (guru_id, tahun_ajaran_id, target_type, kamar_id, kelas_id, tanggal_mulai, is_active)
                  VALUES (?, ?, ?, ?, ?, DATE_SUB(CURDATE(), INTERVAL 30 DAY), 1)";
    $created['murobi'][] = $exec($murobiSql, [$guruSatu, $yearId, 'Kamar', $kamarSatu, null]);
    $created['murobi'][] = $exec($murobiSql, [$guruDua, $yearId, 'Kamar', $kamarDua, null]);
    $created['murobi'][] = $exec($murobiSql, [$guruTiga, $yearId, 'Kelas', null, $kelasSatu]);
    $created['murobi'][] = $exec($murobiSql, [$guruKelasNonaktif, $yearId, 'Kelas', null, $kelasNonaktif]);

    $pengurusA = $exec("INSERT INTO pengurus (nama, nomor_identitas, jabatan, is_active) VALUES (?, ?, 'Keamanan', 1)", ['Pengurus F2A ' . $suffix, 'F2PA' . $suffix]);
    $pengurusB = $exec("INSERT INTO pengurus (nama, nomor_identitas, jabatan, is_active) VALUES (?, ?, 'Keamanan', 1)", ['Pengurus F2B ' . $suffix, 'F2PB' . $suffix]);
    $created['pengurus'] = [$pengurusA, $pengurusB];

    $waliSatu = $exec('INSERT INTO wali (nama, no_hp, is_active) VALUES (?, ?, 1)', ['Wali F2 Satu ' . $suffix, '081300000001']);
    $waliDua = $exec('INSERT INTO wali (nama, no_hp, is_active) VALUES (?, ?, 1)', ['Wali F2 Dua ' . $suffix, '081300000002']);
    $created['wali'] = [$waliSatu, $waliDua];
    $created['santri_wali'][] = $exec("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary) VALUES (?, ?, 'Ayah', 1)", [$santriSatu, $waliSatu]);
    $created['santri_wali'][] = $exec("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary) VALUES (?, ?, 'Ayah', 1)", [$santriLuar, $waliDua]);

    $makeUser = static function (string $username, string $name, ?int $guruId, ?int $pengurusId, ?int $waliId, ?string $role) use ($exec, $adminId): int {
        $id = $exec(
            'INSERT INTO users (name, username, password, guru_id, pengurus_id, wali_id, is_active, force_password_change, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW())',
            [$name, $username, password_hash('UjiPassword123!Aa', PASSWORD_DEFAULT), $guruId, $pengurusId, $waliId]
        );
        if ($role !== null) {
            $exec('INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = ?', [$id, $adminId, $role]);
        }
        return $id;
    };

    $lower = strtolower($suffix);
    $userPengurusA = $makeUser('f2.pa.' . $lower, 'Akun Pengurus F2 A', null, $pengurusA, null, 'pengurus');
    $userPengurusB = $makeUser('f2.pb.' . $lower, 'Akun Pengurus F2 B', null, $pengurusB, null, 'pengurus');
    $userMurobiSatu = $makeUser('f2.m1.' . $lower, 'Akun Murobi Satu', $guruSatu, null, null, 'guru');
    $userMurobiDua = $makeUser('f2.m2.' . $lower, 'Akun Murobi Dua', $guruDua, null, null, 'guru');
    $userGuruBiasa = $makeUser('f2.gb.' . $lower, 'Akun Guru Biasa', $guruBiasa, null, null, 'guru');
    $userGuruKelasNonaktif = $makeUser('f2.gn.' . $lower, 'Akun Guru Kelas Nonaktif', $guruKelasNonaktif, null, null, 'guru');
    $userOrtuSatu = $makeUser('f2.o1.' . $lower, 'Akun Ortu Satu', null, null, $waliSatu, 'orang_tua');
    $userOrtuDua = $makeUser('f2.o2.' . $lower, 'Akun Ortu Dua', null, null, $waliDua, 'orang_tua');
    $created['users'] = [$userPengurusA, $userPengurusB, $userMurobiSatu, $userMurobiDua, $userGuruBiasa, $userGuruKelasNonaktif, $userOrtuSatu, $userOrtuDua];

    $loadUser = static fn (int $id): array => auth_repository()->findActiveById($id) ?? throw new RuntimeException('Akun uji tidak ditemukan: ' . $id);

    // Penugasan pembimbing pengurus A: tiga kamar uji (BUKAN santri luar cakupan).
    $pembimbing = pembimbing_service();
    foreach ([$kamarSatu, $kamarDua, $kamarTiga] as $kamarTarget) {
        $created['pembimbing'][] = $pembimbing->create([
            'pengurus_id' => $pengurusA,
            'tahun_ajaran_id' => $yearId,
            'target_type' => 'Kamar',
            'kamar_id' => $kamarTarget,
            'tanggal_mulai' => date('Y-m-d', strtotime('-10 days')),
            'tanggal_selesai' => '',
        ], $adminId);
    }
    // Penugasan dibuat saat kelas masih aktif, lalu kelas dinonaktifkan. Scope
    // dan routing tidak boleh memakai referensi master yang sudah nonaktif.
    $created['pembimbing'][] = $pembimbing->create([
        'pengurus_id' => $pengurusA,
        'tahun_ajaran_id' => $yearId,
        'target_type' => 'Kelas',
        'kelas_id' => $kelasNonaktif,
        'tanggal_mulai' => date('Y-m-d', strtotime('-10 days')),
        'tanggal_selesai' => '',
    ], $adminId);
    $exec('UPDATE kelas SET is_active = 0 WHERE id = ?', [$kelasNonaktif]);
    // Pengurus B membina kamar lain agar cakupannya benar-benar terpisah.
    $kamarPengurusB = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 10)', ['Kamar F2D ' . $suffix]);
    $created['kamar'][] = $kamarPengurusB;
    $created['pembimbing'][] = $pembimbing->create([
        'pengurus_id' => $pengurusB,
        'tahun_ajaran_id' => $yearId,
        'target_type' => 'Kamar',
        'kamar_id' => $kamarPengurusB,
        'tanggal_mulai' => date('Y-m-d', strtotime('-10 days')),
        'tanggal_selesai' => '',
    ], $adminId);

    $workflow = izin_workflow_service();
    $izin = izin_service();
    $besok = date('Y-m-d', strtotime('+1 day'));
    $lusa = date('Y-m-d', strtotime('+2 days'));

    // =====================================================================
    // KP-1. Pengurus hanya dapat memilih santri dalam cakupan pembimbingnya
    // =====================================================================
    $pilihan = $workflow->selectableSantri($loadUser($userPengurusA), '', 1, 100);
    $idsPilihan = array_map(static fn (array $row): int => (int) $row['id'], $pilihan['rows']);
    $assert(
        in_array($santriSatu, $idsPilihan, true)
        && in_array($santriGanda, $idsPilihan, true)
        && in_array($santriNol, $idsPilihan, true),
        'KP-1a Pengurus melihat seluruh santri dalam cakupan pembimbingnya'
    );
    $assert(!in_array($santriLuar, $idsPilihan, true), 'KP-1b Santri di luar cakupan tidak muncul pada daftar pilihan');
    $assert(!in_array($santriKelasNonaktif, $idsPilihan, true), 'KP-1c Santri pada kelas nonaktif tidak muncul pada daftar pilihan');

    $pencarian = $workflow->selectableSantri($loadUser($userPengurusA), 'Santri Luar', 1, 100);
    $assert($pencarian['rows'] === [], 'KP-1d Pencarian tidak dapat memunculkan santri di luar cakupan');

    $sebelum = $countPengajuan();
    $expectStatus(403, static fn () => $workflow->create(
        $loadUser($userPengurusA),
        ['santri_id' => $santriLuar, 'tgl_izin' => $besok, 'tgl_kembali' => $lusa, 'alasan' => 'Percobaan di luar cakupan'],
        $key('luar'),
        $meta
    ), 'KP-1e Pengajuan untuk santri di luar cakupan ditolak 403');
    $expectStatus(403, static fn () => $workflow->create(
        $loadUser($userPengurusA),
        ['santri_id' => $santriKelasNonaktif, 'tgl_izin' => $besok, 'tgl_kembali' => $lusa, 'alasan' => 'Kelas sudah nonaktif'],
        $key('kelas-nonaktif'),
        $meta
    ), 'KP-1f Pengajuan melalui penugasan kelas nonaktif ditolak 403');
    $assert($workflow->routingCandidates(['santri_id' => $santriKelasNonaktif]) === [], 'KP-1g Kelas nonaktif tidak menghasilkan kandidat routing');
    $assert(
        !in_array(Capabilities::MUROBI, capabilities()->forUser($loadUser($userGuruKelasNonaktif)), true),
        'KP-1h Penugasan pada kelas nonaktif tidak memberi kemampuan murobi'
    );
    $assert($countPengajuan() === $sebelum, 'KP-1i Penolakan cakupan tidak menyimpan baris pengajuan');

    // =====================================================================
    // KP-2. Validasi tanggal
    // =====================================================================
    $sebelum = $countPengajuan();
    $expectStatus(422, static fn () => $workflow->create(
        $loadUser($userPengurusA),
        ['santri_id' => $santriSatu, 'tgl_izin' => $lusa, 'tgl_kembali' => $besok, 'alasan' => 'Tanggal terbalik'],
        $key('tgl'),
        $meta
    ), 'KP-2a Tanggal kembali sebelum tanggal izin ditolak 422');
    $expectStatus(422, static fn () => $workflow->create(
        $loadUser($userPengurusA),
        ['santri_id' => $santriSatu, 'tgl_izin' => '2026-02-30', 'tgl_kembali' => '2026-03-01', 'alasan' => 'Tanggal tidak ada'],
        $key('tgl2'),
        $meta
    ), 'KP-2b Tanggal yang tidak ada pada kalender ditolak 422');
    $expectStatus(422, static fn () => $workflow->create(
        $loadUser($userPengurusA),
        ['santri_id' => $santriSatu, 'tgl_izin' => $besok, 'tgl_kembali' => $lusa, 'alasan' => ''],
        $key('alasan'),
        $meta
    ), 'KP-2c Alasan kosong ditolak 422');
    $assert($countPengajuan() === $sebelum, 'KP-2d Seluruh penolakan validasi tidak menyimpan baris');

    // =====================================================================
    // KP-3. Idempotensi pembuatan
    // =====================================================================
    $kunciBuat = $key('buat');
    $inputBuat = [
        'santri_id' => $santriSatu,
        'tgl_izin' => $besok,
        'tgl_kembali' => $lusa,
        'alasan' => 'Menghadiri acara keluarga',
        'catatan_pengurus' => 'Dijemput orang tua',
    ];
    $sebelum = $countPengajuan();
    $buatSatu = $workflow->create($loadUser($userPengurusA), $inputBuat, $kunciBuat, $meta);
    $buatDua = $workflow->create($loadUser($userPengurusA), $inputBuat, $kunciBuat, $meta);
    $created['izin'][] = (int) $buatSatu['id'];
    $assert((int) $buatSatu['id'] === (int) $buatDua['id'], 'KP-3a Dua request dengan idempotency key sama menghasilkan satu pengajuan');
    $assert(($buatDua['idempotent_replay'] ?? false) === true, 'KP-3b Request kedua ditandai sebagai putar ulang idempoten');
    $assert($countPengajuan() === $sebelum + 1, 'KP-3c Hanya satu baris pengajuan bertambah setelah retry');

    $expectStatus(409, static fn () => $workflow->create(
        $loadUser($userPengurusA),
        ['santri_id' => $santriSatu, 'tgl_izin' => $besok, 'tgl_kembali' => $lusa, 'alasan' => 'Isi berbeda dengan kunci sama'],
        $kunciBuat,
        $meta
    ), 'KP-3d Kunci idempotensi yang dipakai untuk isi berbeda ditolak 409');

    // =====================================================================
    // KP-5. Routing satu murobi
    // =====================================================================
    $pengajuanSatu = (int) $buatSatu['id'];
    $assert((string) $buatSatu['status'] === 'Diajukan', 'KP-5a Pengajuan dengan satu murobi valid berstatus Diajukan');
    $assert((int) $buatSatu['murobi_guru_id'] === $guruSatu, 'KP-5b Routing memilih murobi dari penugasan aktif yang cocok');
    $antreanMurobiSatu = $izin->list($loadUser($userMurobiSatu), ['antrean' => '1'], 1, 100);
    $idsAntrean = array_map(static fn (array $row): int => (int) $row['id'], $antreanMurobiSatu['rows']);
    $assert(in_array($pengajuanSatu, $idsAntrean, true), 'KP-5c Pengajuan muncul pada antrean murobi yang ditetapkan');
    $antreanMurobiDua = $izin->list($loadUser($userMurobiDua), ['antrean' => '1'], 1, 100);
    $assert(
        !in_array($pengajuanSatu, array_map(static fn (array $row): int => (int) $row['id'], $antreanMurobiDua['rows']), true),
        'KP-5d Murobi lain tidak melihat pengajuan tersebut'
    );

    // =====================================================================
    // KP-4. Tumpang tindih
    // =====================================================================
    $sebelum = $countPengajuan();
    $expectStatus(409, static fn () => $workflow->create(
        $loadUser($userPengurusA),
        ['santri_id' => $santriSatu, 'tgl_izin' => $besok, 'tgl_kembali' => $lusa, 'alasan' => 'Rentang identik'],
        $key('ovl1'),
        $meta
    ), 'KP-4a Pengajuan dengan rentang identik ditolak 409');
    $expectStatus(409, static fn () => $workflow->create(
        $loadUser($userPengurusA),
        ['santri_id' => $santriSatu, 'tgl_izin' => $lusa, 'tgl_kembali' => date('Y-m-d', strtotime('+5 days')), 'alasan' => 'Rentang bersinggungan'],
        $key('ovl2'),
        $meta
    ), 'KP-4b Pengajuan dengan rentang bersinggungan sebagian ditolak 409');
    $assert($countPengajuan() === $sebelum, 'KP-4c Penolakan tumpang tindih tidak menyimpan baris');

    $terpisah = $workflow->create(
        $loadUser($userPengurusA),
        [
            'santri_id' => $santriSatu,
            'tgl_izin' => date('Y-m-d', strtotime('+20 days')),
            'tgl_kembali' => date('Y-m-d', strtotime('+21 days')),
            'alasan' => 'Rentang tidak bersinggungan',
        ],
        $key('sep'),
        $meta
    );
    $created['izin'][] = (int) $terpisah['id'];
    $assert((int) $terpisah['id'] > 0, 'KP-4d Rentang yang tidak bersinggungan tetap diterima');

    // =====================================================================
    // KP-6. Routing tanpa kandidat dan lebih dari satu kandidat
    // =====================================================================
    $nol = $workflow->create(
        $loadUser($userPengurusA),
        ['santri_id' => $santriNol, 'tgl_izin' => $besok, 'tgl_kembali' => $lusa, 'alasan' => 'Santri tanpa murobi'],
        $key('nol'),
        $meta
    );
    $created['izin'][] = (int) $nol['id'];
    $assert((string) $nol['status'] === IzinRouter::STATUS_PERLU_ADMIN && $nol['murobi_guru_id'] === null, 'KP-6a Routing tanpa kandidat masuk antrean penetapan admin');
    $assert((int) $nol['routing_kandidat'] === 0, 'KP-6b Jumlah kandidat nol tercatat pada pengajuan');

    $ganda = $workflow->create(
        $loadUser($userPengurusA),
        ['santri_id' => $santriGanda, 'tgl_izin' => $besok, 'tgl_kembali' => $lusa, 'alasan' => 'Santri dengan dua murobi'],
        $key('ganda'),
        $meta
    );
    $created['izin'][] = (int) $ganda['id'];
    $assert((string) $ganda['status'] === IzinRouter::STATUS_PERLU_ADMIN, 'KP-6c Routing dengan lebih dari satu kandidat masuk antrean penetapan admin');
    $assert((int) $ganda['routing_kandidat'] === 2, 'KP-6d Jumlah kandidat lebih dari satu tercatat pada pengajuan');

    $antreanAdmin = $izin->list($loadUser($adminId), ['antrean' => '1'], 1, 200);
    $idsAntreanAdmin = array_map(static fn (array $row): int => (int) $row['id'], $antreanAdmin['rows']);
    $assert(
        in_array((int) $nol['id'], $idsAntreanAdmin, true) && in_array((int) $ganda['id'], $idsAntreanAdmin, true),
        'KP-6e Kedua kasus muncul pada antrean admin'
    );
    foreach ([$userMurobiSatu, $userMurobiDua] as $murobiUser) {
        $daftar = $izin->list($loadUser($murobiUser), [], 1, 200);
        $idsMurobi = array_map(static fn (array $row): int => (int) $row['id'], $daftar['rows']);
        $assert(
            !in_array((int) $nol['id'], $idsMurobi, true) && !in_array((int) $ganda['id'], $idsMurobi, true),
            'KP-6f Pengajuan tanpa routing tunggal tidak terlihat oleh murobi yang tidak ditetapkan (user ' . $murobiUser . ')'
        );
    }

    // =====================================================================
    // KP-7. Akses silang antarmurobi
    // =====================================================================
    $expectStatus(403, static fn () => $workflow->decide(
        $loadUser($userMurobiDua),
        $pengajuanSatu,
        'Disetujui',
        'Percobaan lintas murobi',
        null,
        null,
        $key('silang'),
        $meta
    ), 'KP-7a Murobi B menerima 403 saat memutus pengajuan milik Murobi A');
    $expectStatus(403, static fn () => $izin->detail($loadUser($userMurobiDua), $pengajuanSatu), 'KP-7b Murobi B menerima 403 saat membuka detail pengajuan Murobi A');
    $expectStatus(403, static fn () => $workflow->decide(
        $loadUser($userGuruBiasa),
        $pengajuanSatu,
        'Disetujui',
        'Guru tanpa penugasan murobi',
        null,
        null,
        $key('gurubiasa'),
        $meta
    ), 'KP-7c Guru tanpa penugasan murobi aktif tidak dapat memutus');
    $expectStatus(403, static fn () => $workflow->decide(
        $loadUser($userPengurusA),
        $pengajuanSatu,
        'Disetujui',
        'Pengurus mencoba memutus',
        null,
        null,
        $key('pengurusputus'),
        $meta
    ), 'KP-7d Pengurus tidak dapat memberi keputusan');
    $expectStatus(403, static fn () => $workflow->create(
        $loadUser($userMurobiSatu),
        ['santri_id' => $santriSatu, 'tgl_izin' => $besok, 'tgl_kembali' => $lusa, 'alasan' => 'Murobi mengajukan'],
        $key('murobibuat'),
        $meta
    ), 'KP-7e Murobi tidak dapat membuat pengajuan');

    // =====================================================================
    // KP-8. Admin menetapkan / mengganti murobi
    // =====================================================================
    $expectStatus(422, static fn () => $workflow->assignMurobi(
        $loadUser($adminId),
        (int) $ganda['id'],
        $guruDua,
        '',
        null,
        $key('tetapkosong'),
        $meta
    ), 'KP-8a Penetapan murobi tanpa alasan ditolak 422');
    $expectStatus(422, static fn () => $workflow->assignMurobi(
        $loadUser($adminId),
        (int) $ganda['id'],
        $guruBiasa,
        'Guru tanpa penugasan murobi',
        null,
        $key('tetaptidaklayak'),
        $meta
    ), 'KP-8b Guru tanpa penugasan murobi aktif tidak dapat ditetapkan');
    $expectStatus(403, static fn () => $workflow->assignMurobi(
        $loadUser($userPengurusA),
        (int) $ganda['id'],
        $guruDua,
        'Pengurus mencoba menetapkan',
        null,
        $key('tetappengurus'),
        $meta
    ), 'KP-8c Non-admin menerima 403 saat menetapkan murobi');

    $penetapan = $workflow->assignMurobi($loadUser($adminId), (int) $ganda['id'], $guruDua, 'Kamar menjadi rujukan utama', null, $key('tetap'), $meta);
    $assert((string) $penetapan['status'] === 'Diajukan' && (int) $penetapan['murobi_guru_id'] === $guruDua, 'KP-8d Admin dapat menetapkan murobi dan status kembali ke Diajukan');
    $antreanMurobiDua = $izin->list($loadUser($userMurobiDua), ['antrean' => '1'], 1, 100);
    $assert(
        in_array((int) $ganda['id'], array_map(static fn (array $row): int => (int) $row['id'], $antreanMurobiDua['rows']), true),
        'KP-8e Pengajuan yang ditetapkan muncul pada antrean murobi tujuan'
    );

    $penetapanUlang = $workflow->assignMurobi($loadUser($adminId), (int) $ganda['id'], $guruTiga, 'Murobi kamar berhalangan', null, $key('tetapulang'), $meta);
    $assert((int) $penetapanUlang['murobi_guru_id'] === $guruTiga, 'KP-8f Admin dapat menetapkan ulang murobi sebelum ada keputusan');
    $riwayatPenetapan = izin_repository()->history((int) $ganda['id']);
    $peristiwa = array_column($riwayatPenetapan, 'peristiwa');
    $assert(
        in_array('murobi_ditetapkan', $peristiwa, true) && in_array('murobi_ditetapkan_ulang', $peristiwa, true),
        'KP-8g Penetapan dan penetapan ulang tercatat sebagai peristiwa terpisah pada riwayat'
    );

    // =====================================================================
    // KP-9. Admin Pengganti wajib beralasan
    // =====================================================================
    $expectStatus(422, static fn () => $workflow->decide(
        $loadUser($adminId),
        (int) $nol['id'],
        'Disetujui',
        'Keputusan admin tanpa alasan penggantian',
        '',
        null,
        $key('penggantikosong'),
        $meta
    ), 'KP-9a Admin Pengganti tidak dapat memutus tanpa alasan penggantian (422)');

    $keputusanAdmin = $workflow->decide(
        $loadUser($adminId),
        (int) $nol['id'],
        'Disetujui',
        'Izin disetujui karena keperluan mendesak',
        'Belum ada murobi yang dapat ditetapkan hari ini',
        null,
        $key('adminputus'),
        $meta
    );
    $assert((string) $keputusanAdmin['kapasitas'] === 'Admin Pengganti', 'KP-9b Keputusan admin tersimpan dengan kapasitas Admin Pengganti');
    $barisKeputusan = $db->query('SELECT kapasitas, alasan_penggantian FROM izin_keputusan WHERE pengajuan_id = ' . (int) $nol['id'])?->fetch_assoc();
    $assert(
        (string) ($barisKeputusan['kapasitas'] ?? '') === 'Admin Pengganti'
        && trim((string) ($barisKeputusan['alasan_penggantian'] ?? '')) !== '',
        'KP-9c Basis data menyimpan kapasitas dan alasan penggantian'
    );

    // =====================================================================
    // KP-10. Orang tua hanya membaca santri terhubung
    // =====================================================================
    $detailOrtu = $izin->detail($loadUser($userOrtuSatu), $pengajuanSatu);
    $assert((int) $detailOrtu['pengajuan']['id'] === $pengajuanSatu, 'KP-10a Orang tua dapat melihat status izin santri yang terhubung');
    $expectStatus(403, static fn () => $izin->detail($loadUser($userOrtuDua), $pengajuanSatu), 'KP-10b Orang tua A menerima 403 untuk pengajuan santri yang tidak terhubung');
    $expectStatus(403, static fn () => $workflow->decide(
        $loadUser($userOrtuSatu),
        $pengajuanSatu,
        'Disetujui',
        'Orang tua mencoba memutus',
        null,
        null,
        $key('ortuputus'),
        $meta
    ), 'KP-10c Orang tua tidak dapat memberi keputusan');
    $expectStatus(403, static fn () => $workflow->cancel(
        $loadUser($userOrtuSatu),
        $pengajuanSatu,
        'Orang tua mencoba membatalkan',
        null,
        $key('ortubatal'),
        $meta
    ), 'KP-10d Orang tua tidak dapat membatalkan pengajuan');
    $expectStatus(403, static fn () => $workflow->create(
        $loadUser($userOrtuSatu),
        ['santri_id' => $santriSatu, 'tgl_izin' => $besok, 'tgl_kembali' => $lusa, 'alasan' => 'Orang tua mengajukan'],
        $key('ortubuat'),
        $meta
    ), 'KP-10e Orang tua tidak dapat membuat pengajuan');

    // =====================================================================
    // KP-11. Pembatalan sebelum keputusan
    // =====================================================================
    $expectStatus(403, static fn () => $workflow->cancel(
        $loadUser($userPengurusB),
        (int) $terpisah['id'],
        'Pengurus lain mencoba membatalkan',
        null,
        $key('batalsilang'),
        $meta
    ), 'KP-11a Pengurus lain menerima 403 saat membatalkan pengajuan bukan miliknya');
    $expectStatus(422, static fn () => $workflow->cancel(
        $loadUser($userPengurusA),
        (int) $terpisah['id'],
        '',
        null,
        $key('batalkosong'),
        $meta
    ), 'KP-11b Pembatalan tanpa alasan ditolak 422');

    $riwayatSebelumBatal = count(izin_repository()->history((int) $terpisah['id']));
    $batal = $workflow->cancel($loadUser($userPengurusA), (int) $terpisah['id'], 'Acara keluarga dibatalkan', null, $key('batal'), $meta);
    $assert((string) $batal['status'] === 'Dibatalkan', 'KP-11c Pengurus dapat membatalkan pengajuan sebelum keputusan');
    $riwayatSesudahBatal = izin_repository()->history((int) $terpisah['id']);
    $assert(count($riwayatSesudahBatal) === $riwayatSebelumBatal + 1, 'KP-11d Pembatalan menambah riwayat tanpa menghapus riwayat sebelumnya');
    $assert(
        in_array('pembatalan', array_column($riwayatSesudahBatal, 'peristiwa'), true)
        && in_array('pengajuan_dibuat', array_column($riwayatSesudahBatal, 'peristiwa'), true),
        'KP-11e Riwayat pembuatan tetap ada setelah pembatalan'
    );
    $expectStatus(409, static fn () => $workflow->cancel(
        $loadUser($userPengurusA),
        (int) $terpisah['id'],
        'Membatalkan dua kali',
        null,
        $key('batal2'),
        $meta
    ), 'KP-11f Pembatalan kedua ditolak 409');

    // Rentang yang tadinya bentrok kini bebas karena pengajuan dibatalkan.
    $setelahBatal = $workflow->create(
        $loadUser($userPengurusA),
        [
            'santri_id' => $santriSatu,
            'tgl_izin' => date('Y-m-d', strtotime('+20 days')),
            'tgl_kembali' => date('Y-m-d', strtotime('+21 days')),
            'alasan' => 'Pengajuan ulang setelah pembatalan',
        ],
        $key('ulang'),
        $meta
    );
    $created['izin'][] = (int) $setelahBatal['id'];
    $assert((int) $setelahBatal['id'] > 0, 'KP-11g Pengajuan dibatalkan tidak lagi menahan rentang tanggal santri');

    // =====================================================================
    // KP-12. Keputusan murobi + konflik versi
    // =====================================================================
    $detailSebelumPutus = $izin->detail($loadUser($userMurobiSatu), $pengajuanSatu);
    $versiSebelum = (int) $detailSebelumPutus['pengajuan']['version'];
    $keputusanMurobi = $workflow->decide(
        $loadUser($userMurobiSatu),
        $pengajuanSatu,
        'Disetujui',
        'Alasan izin dapat diterima',
        null,
        $versiSebelum,
        $key('murobiputus'),
        $meta
    );
    $assert((string) $keputusanMurobi['status'] === 'Disetujui' && (string) $keputusanMurobi['kapasitas'] === 'Murobi', 'KP-12a Murobi yang ditugaskan dapat memutus dengan kapasitas Murobi');
    $expectStatus(409, static fn () => $workflow->decide(
        $loadUser($userMurobiSatu),
        $pengajuanSatu,
        'Ditolak',
        'Mencoba memutus dua kali',
        null,
        null,
        $key('murobiputus2'),
        $meta
    ), 'KP-12b Keputusan kedua atas pengajuan yang sama ditolak 409');
    $expectStatus(409, static fn () => $workflow->cancel(
        $loadUser($userPengurusA),
        $pengajuanSatu,
        'Membatalkan setelah keputusan',
        null,
        $key('batalsetelah'),
        $meta
    ), 'KP-12c Pembatalan setelah keputusan ditolak 409');

    $pengajuanVersi = $workflow->create(
        $loadUser($userPengurusA),
        [
            'santri_id' => $santriNol,
            'tgl_izin' => date('Y-m-d', strtotime('+30 days')),
            'tgl_kembali' => date('Y-m-d', strtotime('+31 days')),
            'alasan' => 'Uji konflik versi',
        ],
        $key('versi'),
        $meta
    );
    $created['izin'][] = (int) $pengajuanVersi['id'];
    $expectStatus(409, static fn () => $workflow->decide(
        $loadUser($adminId),
        (int) $pengajuanVersi['id'],
        'Disetujui',
        'Versi kedaluwarsa',
        'Murobi belum ditetapkan',
        999,
        $key('versisalah'),
        $meta
    ), 'KP-12d Versi optimistic yang kedaluwarsa ditolak 409');

    // =====================================================================
    // KP-13. Dua keputusan bersamaan (dua proses PHP nyata)
    // =====================================================================
    $pengajuanRace = $workflow->create(
        $loadUser($userPengurusA),
        [
            'santri_id' => $santriSatu,
            'tgl_izin' => date('Y-m-d', strtotime('+40 days')),
            'tgl_kembali' => date('Y-m-d', strtotime('+41 days')),
            'alasan' => 'Uji dua keputusan bersamaan',
        ],
        $key('race'),
        $meta
    );
    $created['izin'][] = (int) $pengajuanRace['id'];
    $versiRace = 1;

    [$hasilA, $hasilB] = $runConcurrently(
        [
            'op' => 'decide', 'user' => (string) $userMurobiSatu, 'pengajuan' => (string) $pengajuanRace['id'],
            'hasil' => 'Disetujui', 'version' => (string) $versiRace, 'key' => $key('raceA'), 'label' => 'A',
        ],
        [
            'op' => 'decide', 'user' => (string) $userMurobiSatu, 'pengajuan' => (string) $pengajuanRace['id'],
            'hasil' => 'Ditolak', 'version' => (string) $versiRace, 'key' => $key('raceB'), 'label' => 'B',
        ]
    );
    $sukses = (int) ($hasilA['ok'] ?? false) + (int) ($hasilB['ok'] ?? false);
    $konflik = array_filter([$hasilA, $hasilB], static fn (array $row): bool => ($row['ok'] ?? false) === false && (int) ($row['status'] ?? 0) === 409);
    $assert($sukses === 1, 'KP-13a Dua keputusan bersamaan: tepat satu berhasil (' . json_encode([$hasilA, $hasilB], JSON_UNESCAPED_UNICODE) . ')');
    $assert(count($konflik) === 1, 'KP-13b Request kedua menerima 409 tanpa menimpa keputusan pertama');
    $jumlahKeputusan = (int) ($db->query('SELECT COUNT(*) AS jumlah FROM izin_keputusan WHERE pengajuan_id = ' . (int) $pengajuanRace['id'])?->fetch_assoc()['jumlah'] ?? 0);
    $assert($jumlahKeputusan === 1, 'KP-13c Hanya satu baris keputusan tersimpan');
    $statusAkhir = (string) ($db->query('SELECT status FROM izin_pengajuan WHERE id = ' . (int) $pengajuanRace['id'])?->fetch_assoc()['status'] ?? '');
    $assert(in_array($statusAkhir, ['Disetujui', 'Ditolak'], true), 'KP-13d Status akhir tunggal dan konsisten: ' . $statusAkhir);

    // Dua pembuatan bersamaan dengan kunci idempotensi yang sama.
    $kunciKembar = $key('kembar');
    $sebelumKembar = $countPengajuan();
    [$buatA, $buatB] = $runConcurrently(
        [
            'op' => 'create', 'user' => (string) $userPengurusA, 'santri' => (string) $santriGanda,
            'from' => date('Y-m-d', strtotime('+50 days')), 'to' => date('Y-m-d', strtotime('+51 days')),
            'alasan' => 'Uji idempotensi bersamaan', 'key' => $kunciKembar, 'label' => 'A',
        ],
        [
            'op' => 'create', 'user' => (string) $userPengurusA, 'santri' => (string) $santriGanda,
            'from' => date('Y-m-d', strtotime('+50 days')), 'to' => date('Y-m-d', strtotime('+51 days')),
            'alasan' => 'Uji idempotensi bersamaan', 'key' => $kunciKembar, 'label' => 'B',
        ]
    );
    $assert($countPengajuan() === $sebelumKembar + 1, 'KP-13e Dua pembuatan bersamaan dengan kunci sama hanya menambah satu pengajuan');
    $idKembar = (int) ($buatA['response']['id'] ?? $buatB['response']['id'] ?? 0);
    if ($idKembar > 0) {
        $created['izin'][] = $idKembar;
    }
    $assert(
        ($buatA['ok'] ?? false) || ($buatB['ok'] ?? false),
        'KP-13f Sedikitnya satu dari dua request bersamaan berhasil (' . json_encode([$buatA, $buatB], JSON_UNESCAPED_UNICODE) . ')'
    );

    // =====================================================================
    // KP-14. Koreksi keputusan tanpa kehilangan riwayat
    // =====================================================================
    $expectStatus(403, static fn () => $workflow->correctDecision(
        $loadUser($userMurobiSatu),
        $pengajuanSatu,
        'Ditolak',
        'Murobi mencoba mengoreksi',
        'Percobaan koreksi',
        null,
        $key('koreksimurobi'),
        $meta
    ), 'KP-14a Non-admin menerima 403 saat mengoreksi keputusan');
    $expectStatus(422, static fn () => $workflow->correctDecision(
        $loadUser($adminId),
        $pengajuanSatu,
        'Ditolak',
        'Alasan baru',
        '',
        null,
        $key('koreksikosong'),
        $meta
    ), 'KP-14b Koreksi tanpa alasan koreksi ditolak 422');

    $riwayatSebelumKoreksi = count(izin_repository()->history($pengajuanSatu));
    $keputusanSebelumKoreksi = izin_repository()->decision($pengajuanSatu);
    $koreksi = $workflow->correctDecision(
        $loadUser($adminId),
        $pengajuanSatu,
        'Ditolak',
        'Setelah ditinjau ulang, izin tidak dapat diberikan',
        'Kesalahan input murobi pada keputusan pertama',
        null,
        $key('koreksi'),
        $meta
    );
    $assert((string) $koreksi['status'] === 'Ditolak', 'KP-14c Admin dapat mengoreksi keputusan melalui peristiwa koreksi');
    $daftarKoreksi = izin_repository()->corrections($pengajuanSatu);
    $assert(count($daftarKoreksi) === 1, 'KP-14d Koreksi tersimpan sebagai satu peristiwa');
    $assert(
        (string) $daftarKoreksi[0]['hasil_sebelum'] === (string) ($keputusanSebelumKoreksi['hasil'] ?? '')
        && (string) $daftarKoreksi[0]['hasil_sesudah'] === 'Ditolak'
        && (string) $daftarKoreksi[0]['alasan_sebelum'] === (string) ($keputusanSebelumKoreksi['alasan'] ?? ''),
        'KP-14e Koreksi menyimpan nilai sebelum dan sesudah'
    );
    $riwayatSesudahKoreksi = izin_repository()->history($pengajuanSatu);
    $assert(count($riwayatSesudahKoreksi) === $riwayatSebelumKoreksi + 1, 'KP-14f Koreksi menambah riwayat, tidak menghapusnya');
    $assert(
        in_array('keputusan', array_column($riwayatSesudahKoreksi, 'peristiwa'), true)
        && in_array('keputusan_dikoreksi', array_column($riwayatSesudahKoreksi, 'peristiwa'), true),
        'KP-14g Riwayat keputusan pertama tetap ada setelah koreksi'
    );
    $jumlahKeputusanSetelahKoreksi = (int) ($db->query('SELECT COUNT(*) AS jumlah FROM izin_keputusan WHERE pengajuan_id = ' . $pengajuanSatu)?->fetch_assoc()['jumlah'] ?? 0);
    $assert($jumlahKeputusanSetelahKoreksi === 1, 'KP-14h Koreksi tidak menghapus atau menggandakan baris keputusan');

    // =====================================================================
    // KP-15. Riwayat, audit, dan daftar/detail per peran
    // =====================================================================
    $riwayatLengkap = izin_repository()->history($pengajuanSatu);
    $wajibAda = ['pengajuan_dibuat', 'routing_otomatis', 'keputusan', 'keputusan_dikoreksi'];
    foreach ($wajibAda as $peristiwaWajib) {
        $assert(in_array($peristiwaWajib, array_column($riwayatLengkap, 'peristiwa'), true), 'KP-15a Riwayat memuat peristiwa ' . $peristiwaWajib);
    }
    $tanpaPelaku = array_filter(
        $riwayatLengkap,
        static fn (array $row): bool => $row['pelaku_nama'] === null || trim((string) $row['alasan']) === '' || $row['created_at'] === null
    );
    $assert($tanpaPelaku === [], 'KP-15b Setiap riwayat V2 memiliki pelaku, alasan, dan waktu');

    $auditWajib = [
        'izin_pengajuan_created',
        'izin_routing_resolved',
        'izin_murobi_assigned',
        'izin_decision_recorded',
        'izin_cancelled',
        'izin_decision_corrected',
    ];
    $idsIzin = array_values(array_filter(array_map('intval', $created['izin'])));
    $daftarId = implode(',', $idsIzin === [] ? [0] : $idsIzin);
    foreach ($auditWajib as $aksiAudit) {
        $baris = $db->query(
            "SELECT COUNT(*) AS jumlah FROM audit_logs
              WHERE action = '" . $db->real_escape_string($aksiAudit) . "'
                AND entity_type = 'izin_pengajuan'
                AND entity_id IN (" . $daftarId . ')'
        )?->fetch_assoc();
        $assert((int) ($baris['jumlah'] ?? 0) > 0, 'KP-15c Audit mencatat ' . $aksiAudit);
    }
    $bocor = $db->query(
        "SELECT COUNT(*) AS jumlah FROM audit_logs
          WHERE entity_type = 'izin_pengajuan' AND entity_id IN (" . $daftarId . ")
            AND (after_json LIKE '%password%' OR before_json LIKE '%password%'
                 OR after_json LIKE '%secret%' OR before_json LIKE '%secret%')"
    )?->fetch_assoc();
    $assert((int) ($bocor['jumlah'] ?? 0) === 0, 'KP-15d Audit perizinan tidak memuat credential atau secret');

    $daftarPengurus = $izin->list($loadUser($userPengurusA), ['q' => 'Santri Satu'], 1, 5);
    $assert($daftarPengurus['rows'] !== [] && $daftarPengurus['per_page'] === 5, 'KP-15e Daftar mendukung pencarian dan pagination');
    $daftarKosong = $izin->list($loadUser($userPengurusA), ['q' => 'tidak-akan-pernah-cocok-' . $suffix], 1, 20);
    $assert($daftarKosong['rows'] === [] && $daftarKosong['total'] === 0, 'KP-15f Filter tanpa hasil menghasilkan empty state, bukan galat');
    $daftarStatus = $izin->list($loadUser($adminId), ['status' => 'Perlu Penetapan Admin'], 1, 100);
    $assert(
        array_filter($daftarStatus['rows'], static fn (array $row): bool => (string) $row['status'] !== 'Perlu Penetapan Admin') === [],
        'KP-15g Filter status hanya mengembalikan status yang diminta'
    );

    // Data warisan tetap baca-saja pada alur V2.
    $legacy = $db->query('SELECT id FROM izin_pengajuan WHERE is_legacy = 1 ORDER BY id LIMIT 1')?->fetch_assoc();
    if ($legacy) {
        $expectStatus(409, static fn () => $workflow->decide(
            $loadUser($adminId),
            (int) $legacy['id'],
            'Disetujui',
            'Mencoba memutus data warisan',
            'Uji data warisan',
            null,
            $key('warisan'),
            $meta
        ), 'KP-15h Pengajuan warisan V1 tidak dapat diproses pada alur V2');
    } else {
        $assert(true, 'KP-15h Tidak ada baris warisan pada database uji (lewati pemeriksaan)');
    }

    // Aksi yang ditampilkan UI sejalan dengan hak server.
    $scopeOrtu = $izin->scopeFor($loadUser($userOrtuSatu));
    $aksiOrtu = $workflow->actionsFor($izin->detail($loadUser($userOrtuSatu), $pengajuanSatu)['pengajuan'], $scopeOrtu);
    $assert(
        array_filter($aksiOrtu) === [],
        'KP-15i Orang tua tidak memperoleh satu pun tombol mutasi'
    );
} catch (Throwable $exception) {
    $failures[] = 'Kesalahan tak terduga: ' . $exception->getMessage();
    echo '[gagal] ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine() . PHP_EOL;
} finally {
    // =====================================================================
    // Pembersihan fixture
    // =====================================================================
    $db->query('SET FOREIGN_KEY_CHECKS=0');
    $idsIzin = array_values(array_unique(array_filter(array_map('intval', $created['izin']))));
    if ($idsIzin !== []) {
        $daftar = implode(',', $idsIzin);
        $db->query('DELETE FROM audit_logs WHERE entity_type = \'izin_pengajuan\' AND entity_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_keputusan_koreksi WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_keputusan WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_riwayat_status WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_idempotency_keys WHERE pengajuan_id IN (' . $daftar . ')');
    }
    $idsUser = array_values(array_filter(array_map('intval', $created['users'])));
    if ($idsUser !== []) {
        $db->query('DELETE FROM izin_idempotency_keys WHERE user_id IN (' . implode(',', $idsUser) . ')');
    }
    $db->query("DELETE FROM izin_idempotency_keys WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND operation LIKE 'izin.%' AND pengajuan_id IS NULL");
    if ($idsIzin !== []) {
        $db->query('DELETE FROM izin_pengajuan WHERE id IN (' . implode(',', $idsIzin) . ')');
    }
    $cleanup = [
        'pembimbing_assignments' => ['id', $created['pembimbing']],
        'murobi_assignments' => ['id', $created['murobi']],
        'santri_wali' => ['id', $created['santri_wali']],
        'plotting_kamar' => ['id', $created['plotting_kamar']],
        'plotting_kelas' => ['id', $created['plotting_kelas']],
        'user_roles' => ['user_id', $created['users']],
        'users' => ['id', $created['users']],
        'wali' => ['id', $created['wali']],
        'pengurus' => ['id', $created['pengurus']],
        'guru' => ['id', $created['guru']],
        'santri' => ['id', $created['santri']],
        'kelas' => ['id', $created['kelas']],
        'kamar' => ['id', $created['kamar']],
    ];
    foreach ($cleanup as $table => [$column, $ids]) {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            continue;
        }
        $db->query('DELETE FROM `' . $table . '` WHERE `' . $column . '` IN (' . implode(',', $ids) . ')');
    }
    $db->query("DELETE FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        AND action IN ('pembimbing_assignment_created','pembimbing_assignment_state_changed')");
    $db->query('SET FOREIGN_KEY_CHECKS=1');
    echo '[bersih] Fixture uji Fase 2 dihapus.' . PHP_EOL;
}

exit($failures === [] ? 0 : 1);
