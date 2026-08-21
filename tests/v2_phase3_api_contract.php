<?php

declare(strict_types=1);

/**
 * Pengujian kontrak REST API V2 Fase 3 (HTTP sungguhan).
 *
 * Menguji seluruh kriteria penerimaan Fase 3 yang dapat diotomatiskan:
 *   - envelope JSON, pagination, filter, dan status HTTP mengikuti konvensi V1;
 *   - autentikasi bearer, pencabutan token saat logout, dan penolakan token lama;
 *   - capability aktual pada profil (navigasi tidak boleh berbasis nama role);
 *   - alur pengurus: cari santri, buat, baca kembali, batalkan;
 *   - alur murobi: antrean, detail, keputusan, riwayat;
 *   - alur admin: pemantauan, routing, penetapan murobi, keputusan pengganti;
 *   - alur orang tua: daftar anak, status, riwayat, tanpa endpoint mutasi;
 *   - otorisasi lintas peran: manipulasi ID/parameter selalu ditolak server;
 *   - idempotensi create/decision/cancel/assign;
 *   - concurrency: dua keputusan bersamaan -> tepat satu keputusan + satu 409;
 *   - regresi V1: endpoint jadwal/laporan guru tetap berperilaku sama.
 *
 * Prasyarat (lihat docs/phase-v2-3/testing-sandbox.md):
 *   1. database uji berakhiran `_test` dengan migrasi 001–007 sudah dijalankan;
 *   2. fixture sintetis dari `bin/v2_phase3_sandbox_seed.php`.
 *
 * Jalankan:
 *   V2_PHASE3_RUN_API=1 php tests/v2_phase3_api_contract.php
 */

$root = dirname(__DIR__);
if (getenv('V2_PHASE3_RUN_API') !== '1') {
    fwrite(STDOUT, "[lewati] Set V2_PHASE3_RUN_API=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}
require_once $root . '/app/bootstrap.php';
if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Pengujian API ditolak: DB_NAME wajib berakhiran _test.\n");
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

// ---------------------------------------------------------------------------
// Server uji
// ---------------------------------------------------------------------------
$host = '127.0.0.1';
$port = (int) (getenv('V2_PHASE3_PORT') ?: 8399);
$base = 'http://' . $host . ':' . $port . '/api/v1';
$serverLog = sys_get_temp_dir() . '/v2_phase3_server_' . getmypid() . '.log';

$command = sprintf(
    '%s -S %s:%d -t %s %s > %s 2>&1 & echo $!',
    escapeshellarg(PHP_BINARY),
    $host,
    $port,
    escapeshellarg($root),
    escapeshellarg($root . '/tests/v2_phase3_router.php'),
    escapeshellarg($serverLog)
);
$serverPid = (int) trim((string) shell_exec($command));
register_shutdown_function(static function () use ($serverPid, $serverLog): void {
    if ($serverPid > 0) {
        @exec('kill ' . $serverPid . ' 2>/dev/null');
    }
    @unlink($serverLog);
});

$ready = false;
for ($attempt = 0; $attempt < 60; $attempt++) {
    $probe = @fsockopen($host, $port, $errno, $errstr, 0.4);
    if (is_resource($probe)) {
        fclose($probe);
        $ready = true;
        break;
    }
    usleep(200_000);
}
if (!$ready) {
    fwrite(STDERR, "Server uji tidak dapat dijalankan pada {$host}:{$port}. Log: " . (string) @file_get_contents($serverLog) . "\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// Klien HTTP
// ---------------------------------------------------------------------------
/**
 * @param array<string, mixed>|null $body
 * @return array{status:int, json:array<string, mixed>|null, raw:string}
 */
$http = static function (string $method, string $path, ?array $body = null, ?string $token = null) use ($base): array {
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_UNICODE),
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($base . $path, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }

    return [
        'status' => $status,
        'json' => $raw === false ? null : json_decode($raw, true),
        'raw' => $raw === false ? '' : $raw,
    ];
};

$login = static function (string $username) use ($http, $assert): ?array {
    $response = $http('POST', '/auth/login', [
        'username' => $username,
        'password' => 'Sandbox#123',
        'device_name' => 'uji-kontrak-fase-3',
    ]);
    $assert($response['status'] === 200, 'Login fixture ' . $username . ' berhasil');

    return $response['json']['data'] ?? null;
};

foreach (['sbx_admin', 'sbx_pengurus_a', 'sbx_murobi_a', 'sbx_ortu_a'] as $required) {
    $row = $db->query("SELECT id FROM users WHERE username = '" . $db->real_escape_string($required) . "' LIMIT 1");
    if (!$row || $row->num_rows === 0) {
        fwrite(STDERR, "Fixture sandbox belum tersedia. Jalankan: V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php\n");
        exit(2);
    }
}

$sesi = [];
foreach ([
    'admin' => 'sbx_admin',
    'pengurus_a' => 'sbx_pengurus_a',
    'pengurus_b' => 'sbx_pengurus_b',
    'murobi_a' => 'sbx_murobi_a',
    'murobi_b' => 'sbx_murobi_b',
    'murobi_c' => 'sbx_murobi_c',
    'guru_biasa' => 'sbx_guru_biasa',
    'ortu_a' => 'sbx_ortu_a',
    'ortu_b' => 'sbx_ortu_b',
] as $alias => $username) {
    $sesi[$alias] = $login($username);
}
$token = static fn (string $alias): string => (string) ($sesi[$alias]['token'] ?? '');

$santri = [];
foreach ([
    'a1' => 'SBX-S-001',
    'a2' => 'SBX-S-002',
    'b1' => 'SBX-S-003',
    'c1' => 'SBX-S-004',
] as $alias => $nis) {
    $row = $db->query("SELECT id FROM santri WHERE nis = '" . $db->real_escape_string($nis) . "' LIMIT 1")?->fetch_assoc();
    $santri[$alias] = (int) ($row['id'] ?? 0);
}
$guruId = static function (string $nip) use ($db): int {
    $row = $db->query("SELECT id FROM guru WHERE nip = '" . $db->real_escape_string($nip) . "' LIMIT 1")?->fetch_assoc();

    return (int) ($row['id'] ?? 0);
};

// Rentang tanggal unik per eksekusi supaya pengujian dapat diulang tanpa bentrok
// dengan pengajuan yang dibuat eksekusi sebelumnya.
$offset = random_int(400, 3000);
$tanggal = static function (int $mulai, int $lama) use ($offset): array {
    $from = date('Y-m-d', strtotime('+' . ($offset + $mulai) . ' days'));

    return [$from, date('Y-m-d', strtotime($from . ' +' . $lama . ' days'))];
};
$kunci = static fn (string $prefix): string => $prefix . '-' . bin2hex(random_bytes(8));

/** @var array<int, int> $createdPengajuan */
$createdPengajuan = [];
register_shutdown_function(static function () use (&$createdPengajuan, $db): void {
    if ($createdPengajuan === []) {
        return;
    }
    $ids = implode(',', array_map('intval', $createdPengajuan));
    // Pembersihan fixture pengujian pada database *_test saja.
    $db->query('DELETE FROM izin_keputusan_koreksi WHERE pengajuan_id IN (' . $ids . ')');
    $db->query('DELETE FROM izin_idempotency_keys WHERE pengajuan_id IN (' . $ids . ')');
    $db->query('DELETE FROM izin_riwayat_status WHERE pengajuan_id IN (' . $ids . ')');
    $db->query('DELETE FROM izin_keputusan WHERE pengajuan_id IN (' . $ids . ')');
    $db->query('DELETE FROM izin_pengajuan WHERE id IN (' . $ids . ')');
});

$hitungPengajuan = static fn (): int => (int) ($db->query('SELECT COUNT(*) AS jumlah FROM izin_pengajuan')?->fetch_assoc()['jumlah'] ?? 0);
$hitungKeputusan = static fn (int $id): int => (int) ($db->query('SELECT COUNT(*) AS jumlah FROM izin_keputusan WHERE pengajuan_id = ' . $id)?->fetch_assoc()['jumlah'] ?? 0);

echo PHP_EOL . '=== 1. Envelope, autentikasi, dan capability ===' . PHP_EOL;

$root_ = $http('GET', '/');
$assert(
    $root_['status'] === 200
    && ($root_['json']['success'] ?? null) === true
    && array_key_exists('data', (array) $root_['json'])
    && array_key_exists('error', (array) $root_['json']),
    'Envelope sukses tetap {success,data,error}'
);

$tanpaToken = $http('GET', '/izin/pengajuan');
$assert($tanpaToken['status'] === 401, 'Endpoint perizinan tanpa bearer token menghasilkan 401');
$assert(($tanpaToken['json']['error']['code'] ?? '') === 'UNAUTHENTICATED', 'Kode error 401 adalah UNAUTHENTICATED');

$tokenPalsu = $http('GET', '/izin/pengajuan', null, str_repeat('0', 64));
$assert($tokenPalsu['status'] === 401, 'Token palsu menghasilkan 401');

$profilPengurus = $http('GET', '/profile', null, $token('pengurus_a'));
$capPengurus = $profilPengurus['json']['data']['capabilities'] ?? [];
$assert($profilPengurus['status'] === 200, 'Akun pengurus dapat membaca /profile');
$assert(($capPengurus['list'] ?? []) === ['pengurus'], 'Profil pengurus memuat capability aktual `pengurus`');
$assert(($capPengurus['konteks']['pengurus_id'] ?? null) !== null, 'Konteks capability memuat pengurus_id tertaut');
$assert(($capPengurus['aksi']['dapat_memutuskan'] ?? true) === false, 'Pengurus tidak memperoleh kemampuan keputusan');

$capGuruBiasa = ($http('GET', '/profile', null, $token('guru_biasa'))['json']['data']['capabilities']['list'] ?? ['x']);
$assert($capGuruBiasa === [], 'Guru tanpa penugasan murobi tidak memperoleh capability perizinan (bukan berbasis nama role)');

$capMurobi = ($http('GET', '/me/capabilities', null, $token('murobi_a'))['json']['data']['list'] ?? []);
$assert($capMurobi === ['murobi'], 'Guru dengan penugasan murobi aktif memperoleh capability `murobi`');

$capOrtu = ($http('GET', '/profile', null, $token('ortu_a'))['json']['data']['capabilities'] ?? []);
$assert(($capOrtu['aksi']['hanya_baca'] ?? false) === true, 'Akun orang tua ditandai hanya baca');

/**
 * Memastikan pelebaran login tidak menerbitkan token ketika relasi master
 * dinonaktifkan. Fixture selalu dipulihkan melalui finally.
 */
$loginSaatMasterNonaktif = static function (string $table, string $linkColumn, string $username) use ($db, $http): int {
    $escapedUsername = $db->real_escape_string($username);
    $row = $db->query(
        "SELECT master.id
           FROM users u
           JOIN {$table} master ON master.id = u.{$linkColumn}
          WHERE u.username = '{$escapedUsername}'
          LIMIT 1"
    )?->fetch_assoc();
    $masterId = (int) ($row['id'] ?? 0);
    if ($masterId < 1) {
        return 0;
    }

    $db->query("UPDATE {$table} SET is_active = 0 WHERE id = {$masterId}");
    try {
        return $http('POST', '/auth/login', [
            'username' => $username,
            'password' => 'Sandbox#123',
            'device_name' => 'uji-master-nonaktif',
        ])['status'];
    } finally {
        $db->query("UPDATE {$table} SET is_active = 1 WHERE id = {$masterId}");
    }
};
$assert(
    $loginSaatMasterNonaktif('pengurus', 'pengurus_id', 'sbx_pengurus_a') === 401,
    'Login pengurus ditolak ketika relasi master pengurus tidak aktif'
);
$assert(
    $loginSaatMasterNonaktif('wali', 'wali_id', 'sbx_ortu_a') === 401,
    'Login orang tua ditolak ketika relasi master wali tidak aktif'
);

echo PHP_EOL . '=== 2. Alur pengurus ===' . PHP_EOL;

$daftarSantri = $http('GET', '/izin/santri?per_page=50', null, $token('pengurus_a'));
$idSantriScope = array_map(static fn (array $row): int => (int) $row['id'], $daftarSantri['json']['data']['items'] ?? []);
$assert($daftarSantri['status'] === 200, 'Pengurus dapat membaca daftar santri dalam cakupannya');
$assert(in_array($santri['a1'], $idSantriScope, true), 'Santri dalam cakupan pembimbing muncul pada daftar pengurus');
$assert(!in_array($santri['b1'], $idSantriScope, true), 'Santri di luar cakupan tidak pernah muncul pada daftar pengurus');
$assert(
    isset($daftarSantri['json']['data']['pagination']['current_page'], $daftarSantri['json']['data']['pagination']['total_pages']),
    'Daftar santri memakai metadata pagination V1'
);

[$dariA1, $sampaiA1] = $tanggal(0, 2);
$kunciBuat = $kunci('sbx-api-create');
$sebelum = $hitungPengajuan();
$buat = $http('POST', '/izin/pengajuan', [
    'santri_id' => $santri['a1'],
    'tgl_izin' => $dariA1,
    'tgl_kembali' => $sampaiA1,
    'alasan' => 'Menghadiri acara keluarga',
    'catatan_pengurus' => 'Dijemput orang tua',
    'idempotency_key' => $kunciBuat,
], $token('pengurus_a'));
$idA1 = (int) ($buat['json']['data']['id'] ?? 0);
if ($idA1 > 0) {
    $createdPengajuan[] = $idA1;
}
$assert($buat['status'] === 201, 'Pengurus dapat membuat pengajuan (201)');
$assert(($buat['json']['data']['status'] ?? '') === 'Diajukan', 'Routing tunggal menghasilkan status Diajukan');
$assert((int) ($buat['json']['data']['murobi_guru_id'] ?? 0) === $guruId('SBX-G-001'), 'Pengajuan diarahkan ke murobi kamar yang benar');

$ulang = $http('POST', '/izin/pengajuan', [
    'santri_id' => $santri['a1'],
    'tgl_izin' => $dariA1,
    'tgl_kembali' => $sampaiA1,
    'alasan' => 'Menghadiri acara keluarga',
    'catatan_pengurus' => 'Dijemput orang tua',
    'idempotency_key' => $kunciBuat,
], $token('pengurus_a'));
$assert($ulang['status'] === 200, 'Retry create dengan kunci dan isi sama membalas 200 (bukan 201)');
$assert((int) ($ulang['json']['data']['id'] ?? 0) === $idA1, 'Retry create mengembalikan pengajuan yang sama');
$assert(($ulang['json']['data']['idempotent_replay'] ?? false) === true, 'Retry create ditandai sebagai pemutaran ulang');
$assert($hitungPengajuan() === $sebelum + 1, 'Retry create tidak menambah baris pengajuan');

$bacaKembali = $http('GET', '/izin/pengajuan/' . $idA1, null, $token('pengurus_a'));
$assert($bacaKembali['status'] === 200, 'Pengurus dapat membaca kembali pengajuan yang baru disimpan');
$assert(($bacaKembali['json']['data']['pengajuan']['alasan'] ?? '') === 'Menghadiri acara keluarga', 'Nilai bisnis pengajuan terbaca utuh');

$konflikKunci = $http('POST', '/izin/pengajuan', [
    'santri_id' => $santri['a1'],
    'tgl_izin' => $dariA1,
    'tgl_kembali' => $sampaiA1,
    'alasan' => 'Isi berbeda dengan kunci yang sama',
    'idempotency_key' => $kunciBuat,
], $token('pengurus_a'));
$assert($konflikKunci['status'] === 409, 'Kunci idempotensi sama dengan isi berbeda menghasilkan 409');

[$dariBentrok, $sampaiBentrok] = $tanggal(1, 1);
$bentrok = $http('POST', '/izin/pengajuan', [
    'santri_id' => $santri['a1'],
    'tgl_izin' => $dariBentrok,
    'tgl_kembali' => $sampaiBentrok,
    'alasan' => 'Rentang bersinggungan',
    'idempotency_key' => $kunci('sbx-api-overlap'),
], $token('pengurus_a'));
$assert($bentrok['status'] === 409, 'Pengajuan tumpang tindih ditolak dengan 409');

[$dariSalah, $sampaiSalah] = $tanggal(40, 0);
$tanggalSalah = $http('POST', '/izin/pengajuan', [
    'santri_id' => $santri['a1'],
    'tgl_izin' => $sampaiSalah,
    'tgl_kembali' => date('Y-m-d', strtotime($dariSalah . ' -3 days')),
    'alasan' => 'Tanggal terbalik',
    'idempotency_key' => $kunci('sbx-api-baddate'),
], $token('pengurus_a'));
$assert($tanggalSalah['status'] === 422, 'Tanggal kembali sebelum tanggal izin ditolak dengan 422');

$tanpaKunci = $http('POST', '/izin/pengajuan', [
    'santri_id' => $santri['a1'],
    'tgl_izin' => $dariSalah,
    'tgl_kembali' => $sampaiSalah,
    'alasan' => 'Tanpa kunci idempotensi',
], $token('pengurus_a'));
$assert($tanpaKunci['status'] === 422, 'Mutasi tanpa idempotency key ditolak dengan 422');

echo PHP_EOL . '=== 3. Otorisasi lintas peran ===' . PHP_EOL;

$luarCakupan = $http('POST', '/izin/pengajuan', [
    'santri_id' => $santri['b1'],
    'tgl_izin' => $dariSalah,
    'tgl_kembali' => $sampaiSalah,
    'alasan' => 'Santri milik pengurus lain',
    'idempotency_key' => $kunci('sbx-api-scope'),
], $token('pengurus_a'));
$assert($luarCakupan['status'] === 403, 'Pengurus tidak dapat mengajukan santri di luar cakupannya (403)');

$pengurusLain = $http('GET', '/izin/pengajuan/' . $idA1, null, $token('pengurus_b'));
$assert($pengurusLain['status'] === 403, 'Pengurus lain menerima 403 saat membuka pengajuan bukan miliknya');

$murobiLain = $http('POST', '/izin/pengajuan/' . $idA1 . '/keputusan', [
    'hasil' => 'Disetujui',
    'alasan' => 'Mencoba memutus milik murobi lain',
    'idempotency_key' => $kunci('sbx-api-crossmurobi'),
], $token('murobi_b'));
$assert($murobiLain['status'] === 403, 'Murobi B menerima 403 saat memutus pengajuan milik Murobi A');

$ortuLain = $http('GET', '/izin/pengajuan/' . $idA1, null, $token('ortu_b'));
$assert($ortuLain['status'] === 403, 'Orang tua lain menerima 403 untuk santri yang tidak terhubung');

$ortuMutasi = $http('POST', '/izin/pengajuan', [
    'santri_id' => $santri['a1'],
    'tgl_izin' => $dariSalah,
    'tgl_kembali' => $sampaiSalah,
    'alasan' => 'Orang tua mencoba mengajukan',
    'idempotency_key' => $kunci('sbx-api-ortu'),
], $token('ortu_a'));
$assert($ortuMutasi['status'] === 403, 'Orang tua tidak dapat membuat pengajuan (403)');

$ortuKeputusan = $http('POST', '/izin/pengajuan/' . $idA1 . '/keputusan', [
    'hasil' => 'Disetujui',
    'alasan' => 'Orang tua mencoba memutus',
    'idempotency_key' => $kunci('sbx-api-ortudec'),
], $token('ortu_a'));
$assert($ortuKeputusan['status'] === 403, 'Orang tua tidak dapat memberi keputusan (403)');

$ortuBatal = $http('POST', '/izin/pengajuan/' . $idA1 . '/pembatalan', [
    'alasan' => 'Orang tua mencoba membatalkan',
    'idempotency_key' => $kunci('sbx-api-ortucancel'),
], $token('ortu_a'));
$assert($ortuBatal['status'] === 403, 'Orang tua tidak dapat membatalkan pengajuan (403)');

$modePalsu = $http('GET', '/izin/pengajuan?mode=admin', null, $token('pengurus_a'));
$assert(
    $modePalsu['status'] === 200 && ($modePalsu['json']['data']['scope']['mode'] ?? '') === 'pengurus',
    'Parameter `mode` tidak dapat menaikkan cakupan: pengurus tetap pada mode pengurus'
);

$monitorPengurus = $http('GET', '/izin/admin/monitor', null, $token('pengurus_a'));
$assert($monitorPengurus['status'] === 403, 'Pemantauan admin ditolak untuk non-admin (403)');

$routingMurobi = $http('GET', '/izin/pengajuan/' . $idA1 . '/routing', null, $token('murobi_a'));
$assert($routingMurobi['status'] === 403, 'Routing hanya dapat dibaca admin (403 untuk murobi)');

$penetapanMurobi = $http('POST', '/izin/pengajuan/' . $idA1 . '/penetapan-murobi', [
    'murobi_guru_id' => $guruId('SBX-G-002'),
    'alasan' => 'Murobi mencoba menetapkan',
    'idempotency_key' => $kunci('sbx-api-assign403'),
], $token('murobi_a'));
$assert($penetapanMurobi['status'] === 403, 'Penetapan murobi ditolak untuk non-admin (403)');

echo PHP_EOL . '=== 4. Antrean murobi dan keputusan ===' . PHP_EOL;

$antreanMurobi = $http('GET', '/izin/antrean?per_page=100', null, $token('murobi_a'));
$idAntrean = array_map(static fn (array $row): int => (int) $row['id'], $antreanMurobi['json']['data']['items'] ?? []);
$assert($antreanMurobi['status'] === 200, 'Murobi dapat membaca antreannya');
$assert(in_array($idA1, $idAntrean, true), 'Pengajuan yang ditetapkan muncul pada antrean murobi tujuan');
$assert(($antreanMurobi['json']['data']['scope']['mode'] ?? '') === 'murobi', 'Cakupan antrean murobi ditetapkan server');

$antreanMurobiB = $http('GET', '/izin/antrean?per_page=100', null, $token('murobi_b'));
$idAntreanB = array_map(static fn (array $row): int => (int) $row['id'], $antreanMurobiB['json']['data']['items'] ?? []);
$assert(!in_array($idA1, $idAntreanB, true), 'Antrean murobi lain tidak memuat pengajuan yang bukan tujuannya');

$detailMurobi = $http('GET', '/izin/pengajuan/' . $idA1, null, $token('murobi_a'));
$versiA1 = (int) ($detailMurobi['json']['data']['pengajuan']['version'] ?? 0);
$assert(($detailMurobi['json']['data']['aksi']['putuskan_murobi'] ?? false) === true, 'Detail murobi menandai aksi keputusan tersedia');

$kunciKeputusan = $kunci('sbx-api-dec');
$keputusan = $http('POST', '/izin/pengajuan/' . $idA1 . '/keputusan', [
    'hasil' => 'Disetujui',
    'alasan' => 'Alasan dan rentang tanggal wajar',
    'version' => $versiA1,
    'idempotency_key' => $kunciKeputusan,
], $token('murobi_a'));
$assert($keputusan['status'] === 201, 'Murobi dapat memberi keputusan (201)');
$assert(($keputusan['json']['data']['kapasitas'] ?? '') === 'Murobi', 'Kapasitas keputusan murobi tercatat');

$keputusanUlang = $http('POST', '/izin/pengajuan/' . $idA1 . '/keputusan', [
    'hasil' => 'Disetujui',
    'alasan' => 'Alasan dan rentang tanggal wajar',
    'version' => $versiA1,
    'idempotency_key' => $kunciKeputusan,
], $token('murobi_a'));
$assert($keputusanUlang['status'] === 200, 'Retry keputusan dengan kunci sama membalas 200');
$assert($hitungKeputusan($idA1) === 1, 'Retry keputusan tidak menambah baris keputusan');

$keputusanKedua = $http('POST', '/izin/pengajuan/' . $idA1 . '/keputusan', [
    'hasil' => 'Ditolak',
    'alasan' => 'Keputusan kedua harus ditolak',
    'idempotency_key' => $kunci('sbx-api-dec2'),
], $token('murobi_a'));
$assert($keputusanKedua['status'] === 409, 'Keputusan kedua menghasilkan 409');
$assert($hitungKeputusan($idA1) === 1, 'Keputusan pertama tidak ditimpa keputusan kedua');

$statusSetelah = $http('GET', '/izin/pengajuan/' . $idA1, null, $token('pengurus_a'));
$assert(
    ($statusSetelah['json']['data']['pengajuan']['status'] ?? '') === 'Disetujui'
    && ($statusSetelah['json']['data']['keputusan']['hasil'] ?? '') === 'Disetujui',
    'Hasil keputusan terlihat oleh pengurus pengaju'
);
$statusOrtu = $http('GET', '/izin/pengajuan/' . $idA1, null, $token('ortu_a'));
$assert(
    ($statusOrtu['json']['data']['keputusan']['hasil'] ?? '') === 'Disetujui'
    && ($statusOrtu['json']['data']['scope']['hanya_baca'] ?? false) === true,
    'Hasil keputusan terlihat oleh orang tua terhubung dalam mode baca-saja'
);
$assert(
    array_sum(array_map(
        static fn (bool $value): int => $value ? 1 : 0,
        array_values((array) ($statusOrtu['json']['data']['aksi'] ?? []))
    )) === 0,
    'Orang tua tidak menerima satu pun aksi mutasi pada detail'
);

$riwayat = $http('GET', '/izin/pengajuan/' . $idA1 . '/riwayat', null, $token('murobi_a'));
$peristiwa = array_map(static fn (array $row): string => (string) $row['peristiwa'], $riwayat['json']['data']['riwayat'] ?? []);
$assert($riwayat['status'] === 200, 'Riwayat perubahan dapat dibaca melalui API');
$assert(
    in_array('pengajuan_dibuat', $peristiwa, true)
    && in_array('routing_otomatis', $peristiwa, true)
    && in_array('keputusan', $peristiwa, true),
    'Riwayat memuat pembuatan, routing, dan keputusan'
);

echo PHP_EOL . '=== 5. Alur admin: routing, penetapan, keputusan pengganti ===' . PHP_EOL;

[$dariA2, $sampaiA2] = $tanggal(10, 1);
$buatA2 = $http('POST', '/izin/pengajuan', [
    'santri_id' => $santri['a2'],
    'tgl_izin' => $dariA2,
    'tgl_kembali' => $sampaiA2,
    'alasan' => 'Kontrol kesehatan rutin',
    'idempotency_key' => $kunci('sbx-api-a2'),
], $token('pengurus_a'));
$idA2 = (int) ($buatA2['json']['data']['id'] ?? 0);
if ($idA2 > 0) {
    $createdPengajuan[] = $idA2;
}
$assert(($buatA2['json']['data']['status'] ?? '') === 'Perlu Penetapan Admin', 'Routing dengan lebih dari satu kandidat masuk antrean admin');
$assert((int) ($buatA2['json']['data']['routing_kandidat'] ?? 0) === 2, 'Jumlah kandidat routing tercatat pada respons');

$tidakTerlihatMurobi = $http('GET', '/izin/pengajuan/' . $idA2, null, $token('murobi_a'));
$assert($tidakTerlihatMurobi['status'] === 403, 'Pengajuan yang belum ditetapkan tidak terlihat oleh murobi kandidat');

$monitor = $http('GET', '/izin/admin/monitor?per_page=100', null, $token('admin'));
$assert($monitor['status'] === 200, 'Admin dapat memantau seluruh pengajuan');
$assert(isset($monitor['json']['data']['antrean_admin']), 'Pemantauan admin memuat penghitung antrean penetapan');

$routing = $http('GET', '/izin/pengajuan/' . $idA2 . '/routing', null, $token('admin'));
$kandidat = array_map(static fn (array $row): int => (int) $row['guru_id'], $routing['json']['data']['kandidat'] ?? []);
$assert($routing['status'] === 200 && count($kandidat) === 2, 'Admin melihat dua kandidat routing');

$assignSalah = $http('POST', '/izin/pengajuan/' . $idA2 . '/penetapan-murobi', [
    'murobi_guru_id' => $guruId('SBX-G-004'),
    'alasan' => 'Guru tanpa penugasan murobi',
    'idempotency_key' => $kunci('sbx-api-assignbad'),
], $token('admin'));
$assert($assignSalah['status'] === 422, 'Penetapan guru tanpa penugasan murobi aktif ditolak dengan 422');

$assignTanpaAlasan = $http('POST', '/izin/pengajuan/' . $idA2 . '/penetapan-murobi', [
    'murobi_guru_id' => $guruId('SBX-G-003'),
    'idempotency_key' => $kunci('sbx-api-assignnoreason'),
], $token('admin'));
$assert($assignTanpaAlasan['status'] === 422, 'Penetapan murobi tanpa alasan ditolak dengan 422');

$versiA2 = (int) ($routing['json']['data']['version'] ?? 0);
$assign = $http('POST', '/izin/pengajuan/' . $idA2 . '/penetapan-murobi', [
    'murobi_guru_id' => $guruId('SBX-G-003'),
    'alasan' => 'Kelas lebih relevan untuk santri ini',
    'version' => $versiA2,
    'idempotency_key' => $kunci('sbx-api-assign'),
], $token('admin'));
$assert($assign['status'] === 200, 'Admin dapat menetapkan murobi (200)');
$assert(($assign['json']['data']['status'] ?? '') === 'Diajukan', 'Setelah penetapan, status kembali ke Diajukan');

$antreanC = $http('GET', '/izin/antrean?per_page=100', null, $token('murobi_c'));
$idAntreanC = array_map(static fn (array $row): int => (int) $row['id'], $antreanC['json']['data']['items'] ?? []);
$assert(in_array($idA2, $idAntreanC, true), 'Pengajuan masuk antrean murobi yang ditetapkan admin');

$versiStale = $http('POST', '/izin/pengajuan/' . $idA2 . '/penetapan-murobi', [
    'murobi_guru_id' => $guruId('SBX-G-001'),
    'alasan' => 'Memakai versi lama',
    'version' => $versiA2,
    'idempotency_key' => $kunci('sbx-api-assignstale'),
], $token('admin'));
$assert($versiStale['status'] === 409, 'Versi kedaluwarsa pada penetapan menghasilkan 409');

[$dariC1, $sampaiC1] = $tanggal(20, 1);
$buatC1 = $http('POST', '/izin/pengajuan', [
    'santri_id' => $santri['c1'],
    'tgl_izin' => $dariC1,
    'tgl_kembali' => $sampaiC1,
    'alasan' => 'Keperluan keluarga mendesak',
    'idempotency_key' => $kunci('sbx-api-c1'),
], $token('pengurus_a'));
$idC1 = (int) ($buatC1['json']['data']['id'] ?? 0);
if ($idC1 > 0) {
    $createdPengajuan[] = $idC1;
}
$assert(
    ($buatC1['json']['data']['status'] ?? '') === 'Perlu Penetapan Admin'
    && (int) ($buatC1['json']['data']['routing_kandidat'] ?? -1) === 0,
    'Routing tanpa kandidat masuk antrean admin'
);

$penggantiTanpaAlasan = $http('POST', '/izin/pengajuan/' . $idC1 . '/keputusan', [
    'hasil' => 'Disetujui',
    'alasan' => 'Sudah diverifikasi admin',
    'idempotency_key' => $kunci('sbx-api-subno'),
], $token('admin'));
$assert($penggantiTanpaAlasan['status'] === 422, 'Admin Pengganti tanpa alasan penggantian ditolak dengan 422');

$pengganti = $http('POST', '/izin/pengajuan/' . $idC1 . '/keputusan', [
    'hasil' => 'Disetujui',
    'alasan' => 'Sudah diverifikasi admin bersama pengurus',
    'alasan_penggantian' => 'Kamar C belum memiliki murobi aktif',
    'idempotency_key' => $kunci('sbx-api-sub'),
], $token('admin'));
$assert($pengganti['status'] === 201, 'Admin dapat memberi keputusan pengganti');
$assert(($pengganti['json']['data']['kapasitas'] ?? '') === 'Admin Pengganti', 'Keputusan admin disimpan dengan kapasitas Admin Pengganti');

$koreksi = $http('POST', '/izin/pengajuan/' . $idC1 . '/koreksi', [
    'hasil' => 'Ditolak',
    'alasan' => 'Ternyata bertepatan dengan ujian',
    'alasan_koreksi' => 'Informasi jadwal ujian baru diterima',
    'idempotency_key' => $kunci('sbx-api-kor'),
], $token('admin'));
$assert($koreksi['status'] === 200, 'Admin dapat mengoreksi keputusan melalui API');
$riwayatKoreksi = $http('GET', '/izin/pengajuan/' . $idC1 . '/riwayat', null, $token('admin'));
$assert(count($riwayatKoreksi['json']['data']['koreksi'] ?? []) === 1, 'Koreksi tersimpan sebagai peristiwa terpisah tanpa menghapus keputusan');
$assert($hitungKeputusan($idC1) === 1, 'Koreksi tidak menghasilkan keputusan tambahan');

echo PHP_EOL . '=== 6. Pembatalan pengurus ===' . PHP_EOL;

$detailA2 = $http('GET', '/izin/pengajuan/' . $idA2, null, $token('pengurus_a'));
$versiBatal = (int) ($detailA2['json']['data']['pengajuan']['version'] ?? 0);
$batalTanpaAlasan = $http('POST', '/izin/pengajuan/' . $idA2 . '/pembatalan', [
    'idempotency_key' => $kunci('sbx-api-cancelnoreason'),
], $token('pengurus_a'));
$assert($batalTanpaAlasan['status'] === 422, 'Pembatalan tanpa alasan ditolak dengan 422');

$kunciBatal = $kunci('sbx-api-cancel');
$batal = $http('POST', '/izin/pengajuan/' . $idA2 . '/pembatalan', [
    'alasan' => 'Jadwal keberangkatan dibatalkan',
    'version' => $versiBatal,
    'idempotency_key' => $kunciBatal,
], $token('pengurus_a'));
$assert($batal['status'] === 200 && ($batal['json']['data']['status'] ?? '') === 'Dibatalkan', 'Pengurus dapat membatalkan sebelum keputusan');
$batalUlang = $http('POST', '/izin/pengajuan/' . $idA2 . '/pembatalan', [
    'alasan' => 'Jadwal keberangkatan dibatalkan',
    'version' => $versiBatal,
    'idempotency_key' => $kunciBatal,
], $token('pengurus_a'));
$assert($batalUlang['status'] === 200 && ($batalUlang['json']['data']['idempotent_replay'] ?? false) === true, 'Retry pembatalan memakai kunci sama tidak menghasilkan peristiwa baru');

$batalSetelahKeputusan = $http('POST', '/izin/pengajuan/' . $idA1 . '/pembatalan', [
    'alasan' => 'Mencoba membatalkan setelah keputusan',
    'idempotency_key' => $kunci('sbx-api-cancelafter'),
], $token('pengurus_a'));
$assert($batalSetelahKeputusan['status'] === 409, 'Pembatalan setelah keputusan ditolak dengan 409');

echo PHP_EOL . '=== 7. Orang tua ===' . PHP_EOL;

$anak = $http('GET', '/izin/anak', null, $token('ortu_a'));
$idAnak = array_map(static fn (array $row): int => (int) $row['santri']['id'], $anak['json']['data']['items'] ?? []);
$assert($anak['status'] === 200, 'Orang tua dapat membaca daftar anak terhubung');
$assert($idAnak === [$santri['a1']], 'Daftar anak hanya memuat santri dengan relasi wali aktif');

$daftarOrtu = $http('GET', '/izin/pengajuan?per_page=100', null, $token('ortu_a'));
$santriTerlihat = array_unique(array_map(
    static fn (array $row): int => (int) $row['santri']['id'],
    $daftarOrtu['json']['data']['items'] ?? []
));
$assert(
    $santriTerlihat === [] || array_values($santriTerlihat) === [$santri['a1']],
    'Daftar izin orang tua hanya memuat santri yang terhubung'
);

$anakOlehPengurus = $http('GET', '/izin/anak', null, $token('pengurus_a'));
$assert($anakOlehPengurus['status'] === 403, 'Endpoint daftar anak hanya untuk akun orang tua');

echo PHP_EOL . '=== 8. Concurrency: dua keputusan bersamaan ===' . PHP_EOL;

[$dariRace, $sampaiRace] = $tanggal(30, 1);
$buatRace = $http('POST', '/izin/pengajuan', [
    'santri_id' => $santri['a1'],
    'tgl_izin' => $dariRace,
    'tgl_kembali' => $sampaiRace,
    'alasan' => 'Uji dua keputusan bersamaan',
    'idempotency_key' => $kunci('sbx-api-race'),
], $token('pengurus_a'));
$idRace = (int) ($buatRace['json']['data']['id'] ?? 0);
if ($idRace > 0) {
    $createdPengajuan[] = $idRace;
}
$assert($idRace > 0, 'Pengajuan untuk uji concurrency berhasil dibuat');

$mulaiBersama = microtime(true) + 1.5;
$perintah = static function (string $hasil, string $kunciIdempotensi) use ($root, $base, $idRace, $token, $mulaiBersama): string {
    $payload = base64_encode((string) json_encode([
        'hasil' => $hasil,
        'alasan' => 'Keputusan bersamaan ' . $hasil,
        'idempotency_key' => $kunciIdempotensi,
    ]));

    return implode(' ', [
        escapeshellarg(PHP_BINARY),
        escapeshellarg($root . '/tests/v2_phase3_concurrency_worker.php'),
        escapeshellarg('--url=' . $base . '/izin/pengajuan/' . $idRace . '/keputusan'),
        escapeshellarg('--token=' . $token('murobi_a')),
        escapeshellarg('--payload=' . $payload),
        escapeshellarg('--at=' . $mulaiBersama),
    ]);
};

$gabungan = $perintah('Disetujui', $kunci('sbx-race-a')) . ' & ' . $perintah('Ditolak', $kunci('sbx-race-b')) . ' & wait';
$keluaran = (string) shell_exec($gabungan);
$statusRace = [];
foreach (explode("\n", trim($keluaran)) as $baris) {
    $baris = trim($baris);
    if ($baris === '') {
        continue;
    }
    $decoded = json_decode($baris, true);
    if (is_array($decoded)) {
        $statusRace[] = (int) ($decoded['status'] ?? 0);
    }
}
sort($statusRace);
$assert(count($statusRace) === 2, 'Dua proses keputusan bersamaan menghasilkan dua respons');
$assert($statusRace === [201, 409], 'Tepat satu keputusan berhasil (201) dan satu ditolak konflik (409) — diterima: ' . implode(', ', $statusRace));
$assert($hitungKeputusan($idRace) === 1, 'Hanya satu baris keputusan tersimpan setelah request bersamaan');
$statusAkhir = $db->query('SELECT status FROM izin_pengajuan WHERE id = ' . $idRace)?->fetch_assoc()['status'] ?? '';
$assert(in_array($statusAkhir, ['Disetujui', 'Ditolak'], true), 'Status akhir pengajuan tepat satu hasil keputusan');

echo PHP_EOL . '=== 9. Pencabutan token ===' . PHP_EOL;

$sesiSementara = $login('sbx_pengurus_a');
$tokenSementara = (string) ($sesiSementara['token'] ?? '');
$sebelumLogout = $http('GET', '/izin/pengajuan', null, $tokenSementara);
$assert($sebelumLogout['status'] === 200, 'Token baru dapat mengakses endpoint V2');
$logout = $http('POST', '/auth/logout', [], $tokenSementara);
$assert($logout['status'] === 200, 'Logout berhasil');
$setelahLogout = $http('GET', '/izin/pengajuan', null, $tokenSementara);
$assert($setelahLogout['status'] === 401, 'Token lama tidak dapat lagi mengakses endpoint V2 setelah logout');
$profilSetelahLogout = $http('GET', '/profile', null, $tokenSementara);
$assert($profilSetelahLogout['status'] === 401, 'Token yang dicabut juga ditolak pada endpoint V1');

echo PHP_EOL . '=== 10. Regresi kontrak V1 ===' . PHP_EOL;

$v1Profile = $http('GET', '/profile', null, $token('guru_biasa'));
$dataProfile = (array) ($v1Profile['json']['data'] ?? []);
$assert($v1Profile['status'] === 200, 'Guru V1 tetap dapat membaca /profile');
foreach (['id', 'name', 'username', 'guru', 'roles'] as $field) {
    $assert(array_key_exists($field, $dataProfile), 'Field profil V1 `' . $field . '` tetap ada');
}
$assert(is_array($dataProfile['guru'] ?? null) && isset($dataProfile['guru']['id'], $dataProfile['guru']['nip'], $dataProfile['guru']['name']), 'Bentuk objek `guru` pada profil tidak berubah');

$v1Today = $http('GET', '/schedules/today', null, $token('guru_biasa'));
$assert($v1Today['status'] === 200, 'GET /schedules/today tetap 200 untuk guru');
$assert(isset($v1Today['json']['data']['date'], $v1Today['json']['data']['schedules']), 'Bentuk respons /schedules/today tidak berubah');

$v1Schedules = $http('GET', '/schedules?page=1&per_page=5', null, $token('guru_biasa'));
$assert(
    $v1Schedules['status'] === 200 && isset($v1Schedules['json']['data']['pagination']['current_page']),
    'GET /schedules tetap mengembalikan pagination V1'
);

$v1Reports = $http('GET', '/reports', null, $token('guru_biasa'));
$assert($v1Reports['status'] === 200 && isset($v1Reports['json']['data']['summary']), 'GET /reports tetap berfungsi untuk guru');

$v1Forbidden = $http('GET', '/schedules/today', null, $token('pengurus_a'));
$assert($v1Forbidden['status'] === 403, 'Penjaga V1 tetap menolak akun tanpa role guru/admin dengan 403');

$notFound = $http('GET', '/izin/tidak-ada', null, $token('admin'));
$assert($notFound['status'] === 404, 'Rute tidak dikenal tetap menghasilkan 404');

echo PHP_EOL;
if ($failures === []) {
    echo 'SELURUH PENGUJIAN KONTRAK API FASE 3 LULUS.' . PHP_EOL;
    exit(0);
}
echo 'GAGAL (' . count($failures) . '):' . PHP_EOL;
foreach ($failures as $failure) {
    echo ' - ' . $failure . PHP_EOL;
}
exit(1);
