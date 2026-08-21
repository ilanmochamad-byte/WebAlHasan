<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Izin\IzinException;

/**
 * Pengujian integrasi V2 Fase 1.
 *
 * Menjalankan kriteria penerimaan yang membutuhkan basis data sungguhan:
 * relasi akun, kemampuan murobi, cakupan pembimbing, isolasi antar peran,
 * dan penampilan data warisan.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   V2_PHASE1_RUN_INTEGRATION=1 php tests/v2_phase1_integration.php
 */

$root = dirname(__DIR__);
if (getenv('V2_PHASE1_RUN_INTEGRATION') !== '1') {
    fwrite(STDOUT, "[lewati] Set V2_PHASE1_RUN_INTEGRATION=1 dan arahkan DB_NAME ke database khusus *_test.\n");
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
$expectForbidden = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message);
    } catch (IzinException $exception) {
        $assert($exception->status() === 403, $message);
    }
};

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
    'plotting_kamar' => [], 'plotting_kelas' => [], 'izin' => [], 'riwayat' => [],
];

$exec = static function (string $sql, array $params = []) use ($db): int {
    $statement = $db->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Fixture gagal disiapkan: ' . $db->error . ' | ' . $sql);
    }
    if ($params !== []) {
        $types = '';
        $references = [];
        foreach ($params as $key => &$value) {
            $types .= is_int($value) ? 'i' : 's';
            $references[$key] = &$value;
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

try {
    // --- Master data uji ---------------------------------------------------
    $kamarId = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 20)', ['Kamar Uji ' . $suffix]);
    $created['kamar'][] = $kamarId;
    $kelasId = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES (?, 'Uji', 1)", ['Kelas Uji ' . $suffix]);
    $created['kelas'][] = $kelasId;

    $santriSql = "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan,
                    kab_kota, provinsi, nama_ayah, no_hp_ayah, nama_ibu, no_hp_ibu, asal_sekolah, sekolah_saat_ini, is_active)
                  VALUES (?, ?, 'L', 'Ciamis', '2010-01-01', 'Alamat', 'Desa', 'Kec', 'Ciamis', 'Jabar', '', NULL, '', NULL, 'A', 'B', 1)";
    $santriKamar = $exec($santriSql, ['S1' . $suffix, 'Santri Kamar ' . $suffix]);
    $santriKelas = $exec($santriSql, ['S2' . $suffix, 'Santri Kelas ' . $suffix]);
    $santriLuar = $exec($santriSql, ['S3' . $suffix, 'Santri Luar ' . $suffix]);
    $created['santri'] = [$santriKamar, $santriKelas, $santriLuar];

    $created['plotting_kamar'][] = $exec(
        'INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)',
        [$santriKamar, $kamarId, $yearId]
    );
    $created['plotting_kelas'][] = $exec(
        "INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, status) VALUES (?, ?, ?, 'Aktif')",
        [$santriKelas, $kelasId, $yearId]
    );

    $guruMurobi = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['G1' . $suffix, 'Guru Murobi ' . $suffix]);
    $guruBiasa = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['G2' . $suffix, 'Guru Biasa ' . $suffix]);
    $created['guru'] = [$guruMurobi, $guruBiasa];
    $created['murobi'][] = $exec(
        "INSERT INTO murobi_assignments (guru_id, tahun_ajaran_id, target_type, kamar_id, tanggal_mulai, is_active)
         VALUES (?, ?, 'Kamar', ?, DATE_SUB(CURDATE(), INTERVAL 30 DAY), 1)",
        [$guruMurobi, $yearId, $kamarId]
    );

    $pengurusA = $exec("INSERT INTO pengurus (nama, nomor_identitas, jabatan, is_active) VALUES (?, ?, 'Keamanan', 1)", ['Pengurus A ' . $suffix, 'PA' . $suffix]);
    $pengurusB = $exec("INSERT INTO pengurus (nama, nomor_identitas, jabatan, is_active) VALUES (?, ?, 'Keamanan', 1)", ['Pengurus B ' . $suffix, 'PB' . $suffix]);
    $pengurusBaru = $exec("INSERT INTO pengurus (nama, nomor_identitas, jabatan, is_active) VALUES (?, ?, 'Kebersihan', 1)", ['Pengurus C ' . $suffix, 'PC' . $suffix]);
    $pengurusNonaktif = $exec("INSERT INTO pengurus (nama, nomor_identitas, jabatan, is_active) VALUES (?, ?, 'Arsip', 0)", ['Pengurus D ' . $suffix, 'PD' . $suffix]);
    $created['pengurus'] = [$pengurusA, $pengurusB, $pengurusBaru, $pengurusNonaktif];

    $waliSatu = $exec('INSERT INTO wali (nama, no_hp, is_active) VALUES (?, ?, 1)', ['Wali Satu ' . $suffix, '081200000001']);
    $waliDua = $exec('INSERT INTO wali (nama, no_hp, is_active) VALUES (?, ?, 1)', ['Wali Dua ' . $suffix, '081200000002']);
    $waliBaru = $exec('INSERT INTO wali (nama, no_hp, is_active) VALUES (?, ?, 1)', ['Wali Tiga ' . $suffix, '081200000003']);
    $created['wali'] = [$waliSatu, $waliDua, $waliBaru];

    $created['santri_wali'][] = $exec("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary) VALUES (?, ?, 'Ayah', 1)", [$santriKamar, $waliSatu]);
    $created['santri_wali'][] = $exec("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary) VALUES (?, ?, 'Ayah', 1)", [$santriLuar, $waliDua]);
    $created['santri_wali'][] = $exec("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary) VALUES (?, ?, 'Ayah', 1)", [$santriKelas, $waliBaru]);
    // Relasi yang sudah diarsipkan: TIDAK boleh terlihat oleh orang tua.
    $relasiArsip = $exec(
        "INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary, archived_at) VALUES (?, ?, 'Paman', 0, NOW())",
        [$santriKelas, $waliSatu]
    );
    $created['santri_wali'][] = $relasiArsip;

    // --- Akun uji ----------------------------------------------------------
    $hash = password_hash('UjiPassword123!Aa', PASSWORD_DEFAULT);
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
    unset($hash);

    $userPengurusA = $makeUser('uji.pa.' . strtolower($suffix), 'Akun Pengurus A', null, $pengurusA, null, 'pengurus');
    $userPengurusB = $makeUser('uji.pb.' . strtolower($suffix), 'Akun Pengurus B', null, $pengurusB, null, 'pengurus');
    $userMurobi = $makeUser('uji.gm.' . strtolower($suffix), 'Akun Guru Murobi', $guruMurobi, null, null, 'guru');
    $userGuruBiasa = $makeUser('uji.gb.' . strtolower($suffix), 'Akun Guru Biasa', $guruBiasa, null, null, 'guru');
    $userOrtuSatu = $makeUser('uji.o1.' . strtolower($suffix), 'Akun Ortu Satu', null, null, $waliSatu, 'orang_tua');
    $userOrtuDua = $makeUser('uji.o2.' . strtolower($suffix), 'Akun Ortu Dua', null, null, $waliDua, 'orang_tua');
    $userTanpaRelasi = $makeUser('uji.nn.' . strtolower($suffix), 'Akun Tanpa Relasi', null, null, null, null);
    $created['users'] = [$userPengurusA, $userPengurusB, $userMurobi, $userGuruBiasa, $userOrtuSatu, $userOrtuDua, $userTanpaRelasi];

    $loadUser = static fn (int $id): array => auth_repository()->findActiveById($id) ?? throw new RuntimeException('Akun uji tidak ditemukan: ' . $id);

    // --- 1. Kemampuan ------------------------------------------------------
    $capabilities = capabilities();
    $assert($capabilities->forUser($loadUser($adminId)) === [Capabilities::ADMIN], 'Akun admin memperoleh kemampuan admin');
    $assert($capabilities->forUser($loadUser($userPengurusA)) === [Capabilities::PENGURUS], 'Akun pengurus dengan relasi aktif memperoleh kemampuan pengurus');
    $assert($capabilities->forUser($loadUser($userOrtuSatu)) === [Capabilities::ORANG_TUA], 'Akun orang tua dengan relasi wali aktif memperoleh kemampuan orang tua');
    $assert($capabilities->forUser($loadUser($userMurobi)) === [Capabilities::MUROBI], 'Guru dengan penugasan murobi aktif memperoleh kemampuan murobi');
    $assert($capabilities->forUser($loadUser($userGuruBiasa)) === [], 'Guru tanpa penugasan murobi aktif TIDAK memperoleh kemampuan keputusan izin');
    $assert($capabilities->forUser($loadUser($userTanpaRelasi)) === [], 'Akun tanpa role dan relasi tidak memperoleh kemampuan apa pun');

    // Penugasan murobi yang sudah berakhir mencabut kemampuan.
    $exec('UPDATE murobi_assignments SET tanggal_selesai = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = ?', [$created['murobi'][0]]);
    $capabilities->forget($userMurobi);
    $assert($capabilities->forUser($loadUser($userMurobi)) === [], 'Penugasan murobi yang sudah berakhir mencabut kemampuan keputusan');
    $exec('UPDATE murobi_assignments SET tanggal_selesai = NULL WHERE id = ?', [$created['murobi'][0]]);
    $capabilities->forget($userMurobi);
    $assert($capabilities->forUser($loadUser($userMurobi)) === [Capabilities::MUROBI], 'Penugasan murobi aktif kembali memberi kemampuan murobi');

    // --- 2. Penugasan pembimbing ------------------------------------------
    $pembimbing = pembimbing_service();
    $assignmentId = $pembimbing->create([
        'pengurus_id' => $pengurusA,
        'tahun_ajaran_id' => $yearId,
        'target_type' => 'Kamar',
        'kamar_id' => $kamarId,
        'tanggal_mulai' => date('Y-m-d', strtotime('-10 days')),
        'tanggal_selesai' => '',
    ], $adminId);
    $created['pembimbing'][] = $assignmentId;
    $assert($assignmentId > 0, 'Penugasan pembimbing dapat dibuat untuk pengurus aktif dan kamar valid');

    $reject = static function (array $input) use ($pembimbing, $adminId): bool {
        try {
            $pembimbing->create($input, $adminId);
            return false;
        } catch (IzinException) {
            return true;
        }
    };
    $assert($reject([
        'pengurus_id' => $pengurusNonaktif, 'tahun_ajaran_id' => $yearId,
        'target_type' => 'Kamar', 'kamar_id' => $kamarId, 'tanggal_mulai' => date('Y-m-d'),
    ]), 'Penugasan pembimbing menolak pengurus tidak aktif');
    $assert($reject([
        'pengurus_id' => $pengurusB, 'tahun_ajaran_id' => $yearId,
        'target_type' => 'Kamar', 'kamar_id' => 99999999, 'tanggal_mulai' => date('Y-m-d'),
    ]), 'Penugasan pembimbing menolak kamar yang tidak ada');
    $assert($reject([
        'pengurus_id' => $pengurusB, 'tahun_ajaran_id' => $yearId,
        'target_type' => 'Kelas', 'kelas_id' => $kelasId,
        'tanggal_mulai' => '2026-05-10', 'tanggal_selesai' => '2026-05-01',
    ]), 'Penugasan pembimbing menolak tanggal selesai sebelum tanggal mulai');

    // --- 3. Cakupan santri -------------------------------------------------
    $scopeSantri = izin_service()->santriInScope($loadUser($userPengurusA));
    $scopeIds = array_map(static fn (array $row): int => (int) $row['id'], $scopeSantri);
    $assert(in_array($santriKamar, $scopeIds, true), 'Pengurus melihat santri di kamar binaannya');
    $assert(!in_array($santriKelas, $scopeIds, true) && !in_array($santriLuar, $scopeIds, true), 'Pengurus tidak melihat santri di luar cakupan pembimbingnya');

    $anakOrtuSatu = array_map(
        static fn (array $row): int => (int) $row['id'],
        izin_service()->santriInScope($loadUser($userOrtuSatu))
    );
    $assert($anakOrtuSatu === [$santriKamar], 'Satu akun orang tua hanya melihat santri dengan relasi wali aktif');
    $assert(!in_array($santriKelas, $anakOrtuSatu, true), 'Relasi wali yang sudah diarsipkan tidak terlihat oleh orang tua');

    // --- 4. Akun pengurus dan orang tua -----------------------------------
    $accounts = perizinan_account_service();
    $newPengurusAccount = $accounts->create('pengurus', [
        'pengurus_id' => $pengurusBaru,
        'name' => 'Akun Pengurus Baru ' . $suffix,
        'username' => 'uji.pc.' . strtolower($suffix),
        'phone' => '081234567890',
    ], $adminId);
    $created['users'][] = $newPengurusAccount['id'];
    $assert($newPengurusAccount['id'] > 0 && $newPengurusAccount['temporary_password'] !== '', 'Admin dapat membuat akun pengurus dengan password sementara');

    $newAccountRow = $db->query('SELECT pengurus_id, force_password_change FROM users WHERE id = ' . (int) $newPengurusAccount['id'])?->fetch_assoc();
    $assert((int) ($newAccountRow['pengurus_id'] ?? 0) === $pengurusBaru, 'Akun pengurus terhubung ke tepat satu baris pengurus');
    $assert((int) ($newAccountRow['force_password_change'] ?? 0) === 1, 'Akun baru wajib mengganti password awal');

    $duplicatePengurusBlocked = false;
    try {
        $accounts->create('pengurus', [
            'pengurus_id' => $pengurusBaru,
            'name' => 'Akun Pengurus Ganda ' . $suffix,
            'username' => 'uji.pd.' . strtolower($suffix),
        ], $adminId);
    } catch (Throwable) {
        $duplicatePengurusBlocked = true;
    }
    $assert($duplicatePengurusBlocked, 'Satu baris pengurus tidak dapat dihubungkan ke lebih dari satu akun');

    $inactivePengurusBlocked = false;
    try {
        $accounts->create('pengurus', [
            'pengurus_id' => $pengurusNonaktif,
            'name' => 'Akun Pengurus Nonaktif ' . $suffix,
            'username' => 'uji.pe.' . strtolower($suffix),
        ], $adminId);
    } catch (Throwable) {
        $inactivePengurusBlocked = true;
    }
    $assert($inactivePengurusBlocked, 'Akun tidak dapat dibuat untuk pengurus yang tidak aktif');

    $accounts->link('orang_tua', $userTanpaRelasi, $waliBaru, $adminId);
    $linkedWali = $db->query('SELECT wali_id FROM users WHERE id = ' . $userTanpaRelasi)?->fetch_assoc();
    $assert((int) ($linkedWali['wali_id'] ?? 0) === $waliBaru, 'Admin dapat menghubungkan akun yang sudah ada ke satu baris wali');

    $duplicateBlocked = false;
    try {
        $accounts->link('orang_tua', $newPengurusAccount['id'], $waliBaru, $adminId);
    } catch (Throwable) {
        $duplicateBlocked = true;
    }
    $assert($duplicateBlocked, 'Satu wali tidak dapat dihubungkan ke lebih dari satu akun');

    $capabilities->forget($userTanpaRelasi);
    $assert(
        $capabilities->forUser($loadUser($userTanpaRelasi)) === [Capabilities::ORANG_TUA],
        'Akun yang baru dihubungkan langsung memperoleh kemampuan orang tua'
    );

    $relations = $accounts->waliRelations($waliSatu);
    $activeRelations = array_values(array_filter($relations, static fn (array $row): bool => $row['archived_at'] === null));
    $assert(count($relations) === 2 && count($activeRelations) === 1, 'Admin dapat memeriksa relasi wali–santri aktif maupun yang diarsipkan');

    // --- 5. Pengajuan uji dan isolasi cakupan ------------------------------
    $izinA = $exec(
        "INSERT INTO izin_pengajuan (santri_id, pengurus_id, diajukan_oleh_user_id, pembimbing_assignment_id,
            murobi_guru_id, tahun_ajaran_id, tgl_izin, tgl_kembali, alasan, status, diajukan_pada)
         VALUES (?, ?, ?, ?, ?, ?, '2026-06-01', '2026-06-03', 'Uji cakupan A', 'Diajukan', NOW())",
        [$santriKamar, $pengurusA, $userPengurusA, $assignmentId, $guruMurobi, $yearId]
    );
    $izinB = $exec(
        "INSERT INTO izin_pengajuan (santri_id, pengurus_id, diajukan_oleh_user_id,
            murobi_guru_id, tahun_ajaran_id, tgl_izin, tgl_kembali, alasan, status, diajukan_pada)
         VALUES (?, ?, ?, ?, ?, '2026-06-05', '2026-06-06', 'Uji cakupan B', 'Diajukan', NOW())",
        [$santriLuar, $pengurusB, $userPengurusB, $guruBiasa, $yearId]
    );
    $created['izin'] = [$izinA, $izinB];

    $izin = izin_service();
    $listPengurusA = $izin->list($loadUser($userPengurusA), [], 1, 50);
    $idsPengurusA = array_map(static fn (array $row): int => (int) $row['id'], $listPengurusA['rows']);
    $assert(in_array($izinA, $idsPengurusA, true) && !in_array($izinB, $idsPengurusA, true), 'Pengurus hanya melihat pengajuan yang dibuat atas namanya');

    $listMurobi = $izin->list($loadUser($userMurobi), [], 1, 50);
    $idsMurobi = array_map(static fn (array $row): int => (int) $row['id'], $listMurobi['rows']);
    $assert(in_array($izinA, $idsMurobi, true) && !in_array($izinB, $idsMurobi, true), 'Murobi hanya melihat pengajuan yang diarahkan kepadanya');

    $expectForbidden(static fn () => izin_service()->detail($loadUser($userMurobi), $izinB), 'Murobi A menerima 403 saat membuka pengajuan milik Murobi B');
    $expectForbidden(static fn () => izin_service()->detail($loadUser($userPengurusA), $izinB), 'Pengurus menerima 403 saat membuka pengajuan pengurus lain');
    $expectForbidden(static fn () => izin_service()->detail($loadUser($userOrtuSatu), $izinB), 'Orang tua A menerima 403 untuk pengajuan santri yang tidak terhubung dengannya');
    $expectForbidden(static fn () => izin_service()->detail($loadUser($userGuruBiasa), $izinA), 'Guru tanpa kemampuan perizinan menerima 403');

    $detailOrtu = $izin->detail($loadUser($userOrtuSatu), $izinA);
    $assert((int) $detailOrtu['pengajuan']['id'] === $izinA, 'Orang tua dapat membuka pengajuan santri yang terhubung dengannya');

    $detailAdmin = $izin->detail($loadUser($adminId), $izinB);
    $assert((int) $detailAdmin['pengajuan']['id'] === $izinB, 'Admin dapat membuka seluruh pengajuan');

    // Meminta mode di luar kemampuan tidak boleh memperluas cakupan.
    $modePaksa = $izin->list($loadUser($userPengurusA), [], 1, 50, Capabilities::ADMIN);
    $assert($modePaksa['scope']['mode'] === Capabilities::PENGURUS, 'Parameter mode tidak dapat memperluas cakupan di luar kemampuan pengguna');

    // --- 6. Data warisan ---------------------------------------------------
    $legacy = $db->query('SELECT id FROM izin_pengajuan WHERE is_legacy = 1 ORDER BY id LIMIT 1')?->fetch_assoc();
    if ($legacy) {
        $legacyDetail = $izin->detail($loadUser($adminId), (int) $legacy['id']);
        $assert($legacyDetail['pengajuan']['sumber_label'] === 'Data warisan', 'Pengajuan warisan diberi label Data warisan');
        $assert(
            $legacyDetail['pengajuan']['pengurus_label'] === 'Data warisan'
            && $legacyDetail['pengajuan']['murobi_label'] === 'Data warisan',
            'Data lama tanpa pelaku tidak menunjuk pengguna fiktif'
        );
        $assert($legacyDetail['riwayat'] !== [], 'Pengajuan warisan memiliki jejak riwayat migrasi');
    } else {
        $assert(true, 'Tidak ada baris perizinan warisan pada database uji (lewati pemeriksaan label)');
    }

    // --- 7. Audit ----------------------------------------------------------
    $auditActions = ['perizinan_account_created', 'perizinan_account_linked', 'pembimbing_assignment_created'];
    foreach ($auditActions as $action) {
        $row = $db->query(
            "SELECT COUNT(*) AS jumlah FROM audit_logs WHERE action = '" . $db->real_escape_string($action) . "'
              AND actor_user_id = " . $adminId . " AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
        )?->fetch_assoc();
        $assert((int) ($row['jumlah'] ?? 0) > 0, 'Audit mencatat ' . $action);
    }
    $secretLeak = $db->query(
        "SELECT COUNT(*) AS jumlah FROM audit_logs
          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
            AND (after_json LIKE '%password\":\"%' OR before_json LIKE '%password\":\"%')"
    )?->fetch_assoc();
    $assert((int) ($secretLeak['jumlah'] ?? 0) === 0, 'Audit tidak menyimpan credential atau password');
} catch (Throwable $exception) {
    $failures[] = 'Kesalahan tak terduga: ' . $exception->getMessage();
    echo '[gagal] ' . $exception->getMessage() . PHP_EOL;
} finally {
    // --- Pembersihan fixture ---------------------------------------------
    $db->query('SET FOREIGN_KEY_CHECKS=0');
    $cleanup = [
        'izin_riwayat_status' => ['pengajuan_id', $created['izin']],
        'izin_pengajuan' => ['id', $created['izin']],
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
    $db->query("DELETE FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        AND action IN ('perizinan_account_created','perizinan_account_linked','pembimbing_assignment_created','pembimbing_assignment_state_changed')");
    $db->query('SET FOREIGN_KEY_CHECKS=1');
    echo '[bersih] Fixture uji dihapus.' . PHP_EOL;
}

exit($failures === [] ? 0 : 1);
