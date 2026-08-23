<?php

declare(strict_types=1);

/**
 * Pengujian kontrak REST API V2 Fase 4 (HTTP sungguhan).
 *
 * Menguji seluruh endpoint notifikasi lewat server HTTP nyata, memakai fixture
 * sandbox Fase 3 (`bin/v2_phase3_sandbox_seed.php`):
 *
 *   KA-1  envelope, pagination, dan status HTTP mengikuti konvensi V1;
 *   KA-2  notifikasi milik pengguna lain selalu 403, apa pun ID yang dicoba;
 *   KA-3  jumlah belum dibaca, detail, tandai satu, tandai semua;
 *   KA-4  registrasi, daftar, sakelar, dan pencabutan perangkat push;
 *   KA-5  deep link menolak pengguna yang tidak berhak atas pengajuan;
 *   KA-6  endpoint admin ditolak untuk non-admin dan bekerja untuk admin;
 *   KA-7  WhatsApp tidak dapat dinyalakan lewat API bila pemeriksaan gagal;
 *   KA-8  logout mencabut registrasi perangkat dan token sesi;
 *   KA-9  secret dan token tidak pernah muncul pada respons API;
 *   KA-10 endpoint V1 dan Fase 3 tidak berubah perilakunya.
 *
 * Prasyarat (lihat docs/phase-v2-4/testing-sandbox.md):
 *   1. database uji berakhiran `_test` dengan migrasi 001-008 sudah dijalankan;
 *   2. fixture sintetis dari `bin/v2_phase3_sandbox_seed.php`.
 *
 * Jalankan:
 *   V2_PHASE4_RUN_API=1 php tests/v2_phase4_api_contract.php
 */

$root = dirname(__DIR__);
if (getenv('V2_PHASE4_RUN_API') !== '1') {
    fwrite(STDOUT, "[lewati] Set V2_PHASE4_RUN_API=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

// Kunci sandbox untuk proses induk DAN server uji (diwariskan lewat environment).
$kunciUji = base64_encode(str_repeat("\x2b", 32));
putenv('PUSH_TOKEN_KEY=' . $kunciUji);
$_ENV['PUSH_TOKEN_KEY'] = $kunciUji;
putenv('WHATSAPP_PROVIDER=');

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
$port = (int) (getenv('V2_PHASE4_PORT') ?: 8499);
$base = 'http://' . $host . ':' . $port . '/api/v1';
$serverLog = sys_get_temp_dir() . '/v2_phase4_server_' . getmypid() . '.log';

$command = sprintf(
    'PUSH_TOKEN_KEY=%s %s -S %s:%d -t %s %s > %s 2>&1 & echo $!',
    escapeshellarg($kunciUji),
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
        'device_name' => 'uji-kontrak-fase-4',
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
    'murobi_a' => 'sbx_murobi_a',
    'murobi_b' => 'sbx_murobi_b',
    'ortu_a' => 'sbx_ortu_a',
    'ortu_b' => 'sbx_ortu_b',
] as $alias => $username) {
    $sesi[$alias] = $login($username);
}
$token = static fn (string $alias): string => (string) ($sesi[$alias]['token'] ?? '');
$userId = static fn (string $alias): int => (int) ($sesi[$alias]['profile']['id'] ?? 0);

$santriA1 = (int) ($db->query("SELECT id FROM santri WHERE nis = 'SBX-S-001' LIMIT 1")?->fetch_assoc()['id'] ?? 0);
$suffix = strtolower(bin2hex(random_bytes(3)));
$settings = notification_settings_repository();
$pengaturanAwal = $settings->current();
$dibuat = ['izin' => []];

try {
    // =====================================================================
    // Persiapan: satu pengajuan yang menghasilkan notifikasi nyata
    // =====================================================================
    echo PHP_EOL . '=== Persiapan ===' . PHP_EOL;

    $settings->setPushEnabled(false, $userId('admin'));
    $settings->setWhatsappEnabled(false, $userId('admin'));

    $buat = $http('POST', '/izin/pengajuan', [
        'santri_id' => $santriA1,
        'tgl_izin' => date('Y-m-d', strtotime('+3 days')),
        'tgl_kembali' => date('Y-m-d', strtotime('+4 days')),
        'alasan' => 'Alasan rahasia untuk uji kontrak notifikasi',
        'catatan_pengurus' => 'Catatan internal uji kontrak',
        'idempotency_key' => 'f4-api-' . $suffix,
    ], $token('pengurus_a'));
    $assert($buat['status'] === 201, 'Pengajuan uji dibuat (201)');
    $pengajuanId = (int) ($buat['json']['data']['id'] ?? 0);
    $dibuat['izin'][] = $pengajuanId;
    $assert($pengajuanId > 0, 'ID pengajuan uji diperoleh');

    // =====================================================================
    // KA-1 & KA-3. Pusat notifikasi
    // =====================================================================
    echo PHP_EOL . '=== KA-1/KA-3. Pusat notifikasi ===' . PHP_EOL;

    $daftar = $http('GET', '/notifikasi?per_page=5', null, $token('murobi_a'));
    $assert($daftar['status'] === 200, 'GET /notifikasi menjawab 200');
    $assert(
        ($daftar['json']['success'] ?? null) === true && array_key_exists('data', $daftar['json'] ?? []),
        'KA-1a Envelope JSON mengikuti konvensi V1'
    );
    $data = $daftar['json']['data'] ?? [];
    foreach (['items', 'jumlah_belum_dibaca', 'filters', 'pagination'] as $kunci) {
        $assert(array_key_exists($kunci, $data), 'KA-1b Respons memuat ' . $kunci);
    }
    foreach (['current_page', 'per_page', 'total', 'total_pages'] as $kunci) {
        $assert(array_key_exists($kunci, $data['pagination'] ?? []), 'KA-1c Pagination memuat ' . $kunci);
    }
    $assert(($data['pagination']['per_page'] ?? 0) === 5, 'KA-1d Parameter per_page dihormati');
    $assert(($data['items'] ?? []) !== [], 'KA-3a Murobi menerima notifikasi pengajuan baru');

    $notifikasiMurobi = $data['items'][0];
    foreach (['id', 'event_type', 'event_label', 'judul', 'isi', 'dibaca', 'tautan'] as $kunci) {
        $assert(array_key_exists($kunci, $notifikasiMurobi), 'KA-3b Item notifikasi memuat ' . $kunci);
    }
    $assert(
        !str_contains(json_encode($notifikasiMurobi, JSON_UNESCAPED_UNICODE) ?: '', 'rahasia')
            && !str_contains(json_encode($notifikasiMurobi, JSON_UNESCAPED_UNICODE) ?: '', 'Catatan internal'),
        'KA-3c Notifikasi tidak memuat alasan izin maupun catatan pengurus'
    );
    $idNotifikasiMurobi = (int) $notifikasiMurobi['id'];

    $belum = $http('GET', '/notifikasi/belum-dibaca', null, $token('murobi_a'));
    $assert(
        $belum['status'] === 200 && ($belum['json']['data']['jumlah'] ?? -1) >= 1,
        'KA-3d Jumlah belum dibaca tersedia dan positif'
    );
    $jumlahAwal = (int) $belum['json']['data']['jumlah'];

    $detail = $http('GET', '/notifikasi/' . $idNotifikasiMurobi, null, $token('murobi_a'));
    $assert($detail['status'] === 200, 'KA-3e Detail notifikasi milik sendiri dapat dibuka');

    $tandai = $http('POST', '/notifikasi/' . $idNotifikasiMurobi . '/dibaca', [], $token('murobi_a'));
    $assert($tandai['status'] === 200, 'KA-3f Tandai dibaca menjawab 200');
    $assert(
        (int) ($tandai['json']['data']['jumlah_belum_dibaca'] ?? -1) === $jumlahAwal - 1,
        'KA-3g Jumlah belum dibaca berkurang tepat satu'
    );
    $ulangi = $http('POST', '/notifikasi/' . $idNotifikasiMurobi . '/dibaca', [], $token('murobi_a'));
    $assert(
        $ulangi['status'] === 200
            && (int) ($ulangi['json']['data']['jumlah_belum_dibaca'] ?? -1) === $jumlahAwal - 1,
        'KA-3h Menandai dibaca dua kali bersifat idempoten'
    );

    $semua = $http('POST', '/notifikasi/dibaca-semua', [], $token('murobi_a'));
    $assert(
        $semua['status'] === 200 && (int) ($semua['json']['data']['jumlah_belum_dibaca'] ?? -1) === 0,
        'KA-3i Tandai semua dibaca mengosongkan jumlah'
    );

    $filter = $http('GET', '/notifikasi?status=belum_dibaca', null, $token('murobi_a'));
    $assert(
        $filter['status'] === 200 && ($filter['json']['data']['items'] ?? null) === [],
        'KA-3j Filter belum_dibaca menghasilkan daftar kosong setelah semua dibaca'
    );

    // =====================================================================
    // KA-2. Otorisasi lintas pengguna
    // =====================================================================
    echo PHP_EOL . '=== KA-2. Otorisasi lintas pengguna ===' . PHP_EOL;

    foreach (['ortu_a', 'ortu_b', 'murobi_b', 'pengurus_a'] as $alias) {
        $silang = $http('GET', '/notifikasi/' . $idNotifikasiMurobi, null, $token($alias));
        $assert($silang['status'] === 403, 'KA-2a ' . $alias . ' ditolak 403 membuka notifikasi milik murobi_a');
    }
    $silangTandai = $http('POST', '/notifikasi/' . $idNotifikasiMurobi . '/dibaca', [], $token('ortu_a'));
    $assert($silangTandai['status'] === 403, 'KA-2b Menandai baca notifikasi orang lain ditolak 403');
    $tidakAda = $http('GET', '/notifikasi/999999999', null, $token('murobi_a'));
    $assert($tidakAda['status'] === 403, 'KA-2c ID yang tidak ada dijawab 403, tidak membocorkan keberadaan baris');
    $tanpaToken = $http('GET', '/notifikasi', null, null);
    $assert($tanpaToken['status'] === 401, 'KA-2d Tanpa bearer token dijawab 401');

    // Admin pun tidak boleh membaca notifikasi pribadi pengguna lain.
    $adminSilang = $http('GET', '/notifikasi/' . $idNotifikasiMurobi, null, $token('admin'));
    $assert($adminSilang['status'] === 403, 'KA-2e Admin sekalipun tidak dapat membaca notifikasi pribadi murobi');

    // =====================================================================
    // KA-4. Perangkat push
    // =====================================================================
    echo PHP_EOL . '=== KA-4. Perangkat push ===' . PHP_EOL;

    $tokenPush = 'ExponentPushToken[' . str_repeat('c', 14) . $suffix . ']';
    $daftarkan = $http('POST', '/notifikasi/perangkat', [
        'token' => $tokenPush,
        'platform' => 'android',
        'device_id' => 'api-' . $suffix,
        'device_label' => 'Perangkat Kontrak F4',
        'app_version' => '1.0.0',
    ], $token('murobi_a'));
    $assert($daftarkan['status'] === 201, 'KA-4a Registrasi perangkat menjawab 201');
    $perangkatId = (int) ($daftarkan['json']['data']['perangkat_id'] ?? 0);
    $assert($perangkatId > 0, 'KA-4b ID perangkat dikembalikan');
    $assert(
        !str_contains($daftarkan['raw'], 'ExponentPushToken'),
        'KA-4c Respons registrasi tidak mengembalikan token'
    );

    $tolakToken = $http('POST', '/notifikasi/perangkat', [
        'token' => 'bukan-token',
        'platform' => 'android',
    ], $token('murobi_a'));
    $assert($tolakToken['status'] === 422, 'KA-4d Token yang bukan Expo push token ditolak 422');

    $daftarPerangkat = $http('GET', '/notifikasi/perangkat', null, $token('murobi_a'));
    $assert($daftarPerangkat['status'] === 200, 'KA-4e Daftar perangkat dapat dibaca pemiliknya');
    $assert(
        !str_contains($daftarPerangkat['raw'], 'ExponentPushToken')
            && !str_contains($daftarPerangkat['raw'], 'token_hash'),
        'KA-4f Daftar perangkat tidak pernah memuat token atau hash-nya'
    );

    $sakelarPerangkat = $http('POST', '/notifikasi/perangkat/' . $perangkatId . '/push', ['push_aktif' => false], $token('murobi_a'));
    $assert($sakelarPerangkat['status'] === 200, 'KA-4g Pengguna dapat mematikan push perangkatnya');
    $assert(
        ($sakelarPerangkat['json']['data']['push_aktif'] ?? true) === false,
        'KA-4h Status push perangkat berubah menjadi nonaktif'
    );

    $silangPerangkat = $http('POST', '/notifikasi/perangkat/' . $perangkatId . '/push', ['push_aktif' => true], $token('ortu_a'));
    $assert($silangPerangkat['status'] === 403, 'KA-4i Pengguna lain tidak dapat mengubah perangkat milik murobi');
    $silangCabut = $http('POST', '/notifikasi/perangkat/pencabutan', ['perangkat_id' => $perangkatId], $token('ortu_a'));
    $assert($silangCabut['status'] === 403, 'KA-4j Pengguna lain tidak dapat mencabut perangkat milik murobi');

    // =====================================================================
    // KA-5. Deep link tetap diverifikasi server
    // =====================================================================
    echo PHP_EOL . '=== KA-5. Deep link ===' . PHP_EOL;

    $detailIzinMurobi = $http('GET', '/izin/pengajuan/' . $pengajuanId, null, $token('murobi_a'));
    $assert($detailIzinMurobi['status'] === 200, 'KA-5a Murobi tujuan dapat membuka detail izin dari deep link');
    $detailIzinOrtuB = $http('GET', '/izin/pengajuan/' . $pengajuanId, null, $token('ortu_b'));
    $assert(
        $detailIzinOrtuB['status'] === 403,
        'KA-5b Orang tua yang tidak terhubung ditolak 403 walaupun mengetahui ID dari payload'
    );
    $detailIzinMurobiB = $http('GET', '/izin/pengajuan/' . $pengajuanId, null, $token('murobi_b'));
    $assert($detailIzinMurobiB['status'] === 403, 'KA-5c Murobi lain ditolak 403 untuk pengajuan yang bukan miliknya');

    // =====================================================================
    // KA-6 & KA-7. Panel admin
    // =====================================================================
    echo PHP_EOL . '=== KA-6/KA-7. Panel admin ===' . PHP_EOL;

    foreach (['pengurus_a', 'murobi_a', 'ortu_a'] as $alias) {
        $tolak = $http('GET', '/notifikasi/admin/status', null, $token($alias));
        $assert($tolak['status'] === 403, 'KA-6a ' . $alias . ' ditolak 403 pada status kanal admin');
    }
    $tolakSakelar = $http('POST', '/notifikasi/admin/sakelar', ['kanal' => 'Push', 'aktif' => true], $token('pengurus_a'));
    $assert($tolakSakelar['status'] === 403, 'KA-6b Non-admin ditolak 403 saat mengubah sakelar');

    $statusAdmin = $http('GET', '/notifikasi/admin/status', null, $token('admin'));
    $assert($statusAdmin['status'] === 200, 'KA-6c Admin dapat membaca status kanal');
    $kanalStatus = $statusAdmin['json']['data']['kanal'] ?? [];
    $assert(count($kanalStatus) === 3, 'KA-6d Status memuat ketiga kanal');
    $inApp = array_values(array_filter($kanalStatus, static fn (array $k): bool => $k['kanal'] === 'InApp'))[0] ?? [];
    $assert(
        ($inApp['aktif'] ?? false) === true && ($inApp['dapat_dimatikan'] ?? true) === false,
        'KA-6e Kanal in-app selalu aktif dan tidak dapat dimatikan'
    );
    $tolakInApp = $http('POST', '/notifikasi/admin/sakelar', ['kanal' => 'InApp', 'aktif' => false], $token('admin'));
    $assert($tolakInApp['status'] === 422, 'KA-6f Permintaan mematikan in-app ditolak 422');

    $sakelarPush = $http('POST', '/notifikasi/admin/sakelar', ['kanal' => 'Push', 'aktif' => true], $token('admin'));
    $assert($sakelarPush['status'] === 200, 'KA-6g Admin dapat menyalakan push');
    $matikanPush = $http('POST', '/notifikasi/admin/sakelar', ['kanal' => 'Push', 'aktif' => false], $token('admin'));
    $assert($matikanPush['status'] === 200, 'KA-6h Admin dapat mematikan push kembali');

    $periksaPush = $http('POST', '/notifikasi/admin/pemeriksaan', ['kanal' => 'Push'], $token('admin'));
    $assert($periksaPush['status'] === 200, 'KA-6i Pemeriksaan konfigurasi push dapat dijalankan');
    $assert(
        in_array($periksaPush['json']['data']['status'] ?? '', ['Lulus', 'Gagal'], true),
        'KA-6j Pemeriksaan mengembalikan status yang jelas'
    );

    $periksaWa = $http('POST', '/notifikasi/admin/pemeriksaan', ['kanal' => 'WhatsApp'], $token('admin'));
    $assert($periksaWa['status'] === 200, 'KA-7a Pemeriksaan WhatsApp dapat dijalankan tanpa penyedia');
    $assert(
        ($periksaWa['json']['data']['status'] ?? '') === 'Gagal',
        'KA-7b Tanpa penyedia, pemeriksaan WhatsApp berstatus Gagal'
    );
    $nyalakanWa = $http('POST', '/notifikasi/admin/sakelar', ['kanal' => 'WhatsApp', 'aktif' => true], $token('admin'));
    $assert($nyalakanWa['status'] === 409, 'KA-7c WhatsApp ditolak 409 saat pemeriksaan konfigurasi gagal');
    $assert(
        $settings->current()['whatsapp_enabled'] === false,
        'KA-7d Sakelar WhatsApp tetap mati setelah penolakan'
    );

    $kegagalan = $http('GET', '/notifikasi/admin/kegagalan', null, $token('admin'));
    $assert($kegagalan['status'] === 200, 'KA-6k Daftar kegagalan dapat dibaca admin');
    $auditKanal = $http('GET', '/notifikasi/admin/audit', null, $token('admin'));
    $assert($auditKanal['status'] === 200, 'KA-6l Audit kanal dapat dibaca admin');
    $assert(
        ($auditKanal['json']['data']['items'] ?? []) !== [],
        'KA-6m Perubahan sakelar tercatat dan terbaca pada audit kanal'
    );

    $workerUji = $http('POST', '/notifikasi/admin/worker', ['kanal' => 'WhatsApp', 'uji_coba' => true], $token('admin'));
    $assert($workerUji['status'] === 200, 'KA-6n Mode uji coba worker dapat dijalankan admin');
    $assert(
        ($workerUji['json']['data']['dijalankan'] ?? true) === false,
        'KA-6o Worker WhatsApp tidak berjalan karena kanal mati'
    );

    $pesanUji = $http('POST', '/notifikasi/admin/pesan-uji', ['kanal' => 'InApp'], $token('admin'));
    $assert($pesanUji['status'] === 200, 'KA-6p Admin dapat mengirim pesan uji in-app');
    $pesanUjiPush = $http('POST', '/notifikasi/admin/pesan-uji', ['kanal' => 'Push'], $token('admin'));
    $assert($pesanUjiPush['status'] === 409, 'KA-6q Pesan uji push ditolak 409 ketika kanal push mati');

    // =====================================================================
    // KA-9. Secret tidak muncul pada respons
    // =====================================================================
    echo PHP_EOL . '=== KA-9. Kebocoran secret ===' . PHP_EOL;

    $gabungan = $statusAdmin['raw'] . $kegagalan['raw'] . $auditKanal['raw'] . $daftarPerangkat['raw'];
    $assert(!str_contains($gabungan, 'ExponentPushToken'), 'KA-9a Respons admin tidak memuat token perangkat');
    $assert(!str_contains($gabungan, $kunciUji), 'KA-9b Respons admin tidak memuat kunci environment');
    $assert(!preg_match('/"password"|"api_key"|Bearer [A-Za-z0-9]/i', $gabungan), 'KA-9c Respons admin tidak memuat credential');
    $assert(
        str_contains($statusAdmin['raw'], 'WHATSAPP_API_TOKEN'),
        'KA-9d Status hanya menyebut NAMA environment yang dibutuhkan'
    );

    // =====================================================================
    // KA-8. Logout mencabut perangkat dan token
    // =====================================================================
    echo PHP_EOL . '=== KA-8. Logout ===' . PHP_EOL;

    $sebelumLogout = (int) ($db->query(
        'SELECT COUNT(*) AS n FROM perangkat_push WHERE id = ' . $perangkatId . ' AND dicabut_pada IS NULL'
    )?->fetch_assoc()['n'] ?? 0);
    $assert($sebelumLogout === 1, 'KA-8a Perangkat masih aktif sebelum logout');

    $logout = $http('POST', '/auth/logout', ['push_token' => $tokenPush], $token('murobi_a'));
    $assert($logout['status'] === 200, 'KA-8b Logout berhasil');
    $assert(
        (int) ($logout['json']['data']['perangkat_push_dicabut'] ?? 0) === 1,
        'KA-8c Logout melaporkan satu perangkat dicabut'
    );
    $assert(
        (int) ($db->query(
            "SELECT COUNT(*) AS n FROM perangkat_push WHERE id = " . $perangkatId
            . " AND dicabut_pada IS NOT NULL AND alasan_pencabutan = 'logout'"
        )?->fetch_assoc()['n'] ?? 0) === 1,
        'KA-8d Perangkat tercabut dengan alasan logout'
    );
    $setelahLogout = $http('GET', '/notifikasi', null, $token('murobi_a'));
    $assert($setelahLogout['status'] === 401, 'KA-8e Token sesi lama tidak dapat lagi mengakses notifikasi');

    // =====================================================================
    // KA-10. Regresi endpoint lama
    // =====================================================================
    echo PHP_EOL . '=== KA-10. Regresi V1 dan Fase 3 ===' . PHP_EOL;

    $profil = $http('GET', '/profile', null, $token('admin'));
    $assert(
        $profil['status'] === 200 && isset($profil['json']['data']['capabilities']['list']),
        'KA-10a Profil V1 tetap mengirim capability Fase 3'
    );
    $antrean = $http('GET', '/izin/antrean', null, $token('admin'));
    $assert($antrean['status'] === 200, 'KA-10b Endpoint antrean Fase 3 tidak berubah');
    $tidakDikenal = $http('GET', '/notifikasi/tidak-ada', null, $token('admin'));
    $assert($tidakDikenal['status'] === 404, 'KA-10c Rute notifikasi yang tidak dikenal tetap 404');
    $jadwal = $http('GET', '/schedules/today', null, $token('admin'));
    $assert(in_array($jadwal['status'], [200, 403], true), 'KA-10d Endpoint jadwal V1 tetap berperilaku sama');
} catch (Throwable $exception) {
    $failures[] = 'Kesalahan tak terduga: ' . $exception->getMessage();
    echo '[gagal] ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine() . PHP_EOL;
} finally {
    try {
        $settings->setWhatsappEnabled(false, $userId('admin'));
        $settings->recordWhatsappCheck(
            $pengaturanAwal['whatsapp_check_status'],
            (string) ($pengaturanAwal['whatsapp_check_pesan'] ?? 'Dipulihkan setelah pengujian.'),
            $pengaturanAwal['whatsapp_provider'],
            $userId('admin')
        );
        if ($pengaturanAwal['whatsapp_enabled']) {
            $settings->setWhatsappEnabled(true, $userId('admin'), (string) $pengaturanAwal['whatsapp_provider']);
        }
        $settings->setPushEnabled($pengaturanAwal['push_enabled'], $userId('admin'));
    } catch (Throwable $exception) {
        echo '[perhatian] Pengaturan kanal tidak dapat dipulihkan: ' . $exception->getMessage() . PHP_EOL;
    }

    $db->query('SET FOREIGN_KEY_CHECKS=0');
    $ids = array_values(array_filter(array_map('intval', $dibuat['izin'])));
    if ($ids !== []) {
        $daftar = implode(',', $ids);
        $db->query('DELETE FROM notifikasi_percobaan WHERE outbox_id IN (SELECT id FROM notifikasi_outbox WHERE pengajuan_id IN (' . $daftar . '))');
        $db->query('DELETE FROM notifikasi_outbox WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query("DELETE FROM audit_logs WHERE entity_type = 'izin_pengajuan' AND entity_id IN (" . $daftar . ')');
        $db->query('DELETE FROM izin_keputusan WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_riwayat_status WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_idempotency_keys WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_pengajuan WHERE id IN (' . $daftar . ')');
    }
    $db->query("DELETE FROM perangkat_push WHERE device_id LIKE 'api-%'");
    $db->query("DELETE FROM notifikasi_outbox WHERE event_type = 'sistem.pesan_uji'");
    $db->query('DELETE FROM notifikasi_pengaturan_audit WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
    $db->query("DELETE FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        AND action LIKE 'notifikasi.%'");
    $db->query('SET FOREIGN_KEY_CHECKS=1');
    echo '[bersih] Fixture kontrak Fase 4 dihapus.' . PHP_EOL;
}

exit($failures === [] ? 0 : 1);
