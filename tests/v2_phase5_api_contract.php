<?php

declare(strict_types=1);

/**
 * Pengujian kontrak REST API V2 Fase 5 (HTTP sungguhan).
 *
 * Menguji endpoint laporan lewat server HTTP nyata dengan fixture sandbox
 * Fase 3 (`bin/v2_phase3_sandbox_seed.php`):
 *
 *   KR-1  envelope, pagination, dan status HTTP mengikuti konvensi V1;
 *   KR-2  isolasi cakupan ditegakkan server pada SETIAP endpoint laporan;
 *   KR-3  parameter yang berusaha memperluas cakupan dijawab 403;
 *   KR-4  ringkasan, detail, cetak, dan CSV konsisten lewat HTTP;
 *   KR-5  CSV lewat API memuat seluruh hasil filter dan aman formula injection;
 *   KR-6  input tidak valid dijawab 422 tanpa membocorkan data;
 *   KR-7  tanpa token / token dicabut dijawab 401;
 *   KR-8  EXPLAIN hanya untuk admin;
 *   KR-9  endpoint V1 dan Fase 3-4 tidak berubah perilakunya (regresi);
 *   KR-10 respons laporan tidak memuat secret, token, atau credential.
 *
 * Prasyarat (lihat docs/phase-v2-5/testing-sandbox.md):
 *   1. database uji berakhiran `_test` dengan migrasi 001-009 sudah dijalankan;
 *   2. fixture sintetis dari `bin/v2_phase3_sandbox_seed.php`.
 *
 * Jalankan:
 *   V2_PHASE5_RUN_API=1 php tests/v2_phase5_api_contract.php
 */

$root = dirname(__DIR__);
if (getenv('V2_PHASE5_RUN_API') !== '1') {
    fwrite(STDOUT, "[lewati] Set V2_PHASE5_RUN_API=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

$kunciUji = base64_encode(str_repeat("\x37", 32));
putenv('PUSH_TOKEN_KEY=' . $kunciUji);
$_ENV['PUSH_TOKEN_KEY'] = $kunciUji;
putenv('WHATSAPP_PROVIDER=');

require_once $root . '/app/bootstrap.php';

use App\Report\IzinCsvExport;

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
// Server uji (port bebas agar tidak menguji server lama yang tertinggal)
// ---------------------------------------------------------------------------
$host = '127.0.0.1';
$portDiminta = (int) (getenv('V2_PHASE5_PORT') ?: 0);
if ($portDiminta > 0) {
    $probe = @fsockopen($host, $portDiminta, $errno, $errstr, 0.5);
    if (is_resource($probe)) {
        fclose($probe);
        fwrite(STDERR, "Port {$portDiminta} sudah dipakai proses lain.\n");
        exit(2);
    }
    $port = $portDiminta;
} else {
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        $port = 8599;
    } else {
        $nama = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($nama, (int) strrpos($nama, ':') + 1);
    }
}
$base = 'http://' . $host . ':' . $port . '/api/v1';
$serverLog = sys_get_temp_dir() . '/v2_phase5_server_' . getmypid() . '.log';

$serverEnv = getenv();
$serverEnv['PUSH_TOKEN_KEY'] = $kunciUji;
$serverPipes = [];
$server = proc_open(
    [PHP_BINARY, '-S', $host . ':' . $port, '-t', $root, $root . '/tests/v2_phase3_router.php'],
    [1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']],
    $serverPipes,
    $root,
    $serverEnv
);
if (!is_resource($server)) {
    fwrite(STDERR, "Server uji tidak dapat dijalankan.\n");
    exit(2);
}
register_shutdown_function(static function () use ($server, $serverLog): void {
    if (is_resource($server)) {
        proc_terminate($server);
        for ($i = 0; $i < 20 && (proc_get_status($server)['running'] ?? false) === true; $i++) {
            usleep(100000);
        }
        if ((proc_get_status($server)['running'] ?? false) === true) {
            proc_terminate($server, 9);
        }
        proc_close($server);
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
    fwrite(STDERR, "Server uji tidak dapat dijalankan pada {$host}:{$port}.\n");
    exit(2);
}

/**
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

    return ['status' => $status, 'json' => $raw === false ? null : json_decode($raw, true), 'raw' => $raw === false ? '' : $raw];
};

$login = static function (string $username) use ($http, $assert): ?array {
    $response = $http('POST', '/auth/login', [
        'username' => $username,
        'password' => 'Sandbox#123',
        'device_name' => 'uji-kontrak-fase-5',
    ]);
    $assert($response['status'] === 200, 'Login fixture ' . $username . ' berhasil');

    return $response['json']['data'] ?? null;
};

foreach (['sbx_admin', 'sbx_pengurus_a', 'sbx_pengurus_b', 'sbx_murobi_a', 'sbx_ortu_a', 'sbx_ortu_b'] as $required) {
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
    'ortu_a' => 'sbx_ortu_a',
    'ortu_b' => 'sbx_ortu_b',
] as $alias => $username) {
    $sesi[$alias] = $login($username);
}
$token = static fn (string $alias): string => (string) ($sesi[$alias]['token'] ?? '');

$rentang = 'date_from=2000-01-01&date_to=2100-12-31';

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== KR-1. Envelope dan konvensi V1 ===' . PHP_EOL;
// ---------------------------------------------------------------------------

$laporanAdmin = $http('GET', '/izin/laporan?' . $rentang, null, $token('admin'));
$assert($laporanAdmin['status'] === 200, 'KR-1a GET /izin/laporan menjawab 200');
$assert(
    is_array($laporanAdmin['json'])
        && array_key_exists('success', $laporanAdmin['json'])
        && array_key_exists('data', $laporanAdmin['json'])
        && array_key_exists('error', $laporanAdmin['json']),
    'KR-1b Envelope JSON mengikuti konvensi V1 (success/data/error)'
);
$data = $laporanAdmin['json']['data'] ?? [];
foreach (['cakupan', 'ringkasan', 'durasi', 'items', 'pagination', 'filter', 'filter_aktif', 'kriteria'] as $kunci) {
    $assert(array_key_exists($kunci, $data), 'KR-1c Respons laporan memuat kunci `' . $kunci . '`');
}
foreach (['current_page', 'per_page', 'total', 'total_pages'] as $kunci) {
    $assert(array_key_exists($kunci, $data['pagination'] ?? []), 'KR-1d Pagination memuat `' . $kunci . '` seperti V1');
}
$assert(
    $http('GET', '/izin/laporan/tidak-ada', null, $token('admin'))['status'] === 404,
    'KR-1e Rute laporan yang tidak dikenal menjawab 404'
);

$filters = $http('GET', '/izin/laporan/filters?' . $rentang, null, $token('admin'));
$assert($filters['status'] === 200, 'KR-1f GET /izin/laporan/filters menjawab 200');
foreach (['santri', 'pengurus', 'murobi', 'tahun_ajaran', 'kamar', 'kelas', 'status', 'kanal'] as $kunci) {
    $assert(array_key_exists($kunci, $filters['json']['data'] ?? []), 'KR-1g Pilihan filter memuat `' . $kunci . '`');
}

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== KR-2. Isolasi cakupan lewat HTTP ===' . PHP_EOL;
// ---------------------------------------------------------------------------

$totalUntuk = static function (string $alias) use ($http, $token, $rentang): int {
    $r = $http('GET', '/izin/laporan?' . $rentang, null, $token($alias));

    return (int) ($r['json']['data']['ringkasan']['total'] ?? -1);
};
$modeUntuk = static function (string $alias) use ($http, $token, $rentang): string {
    $r = $http('GET', '/izin/laporan?' . $rentang, null, $token($alias));

    return (string) ($r['json']['data']['cakupan']['mode'] ?? '');
};

$totalAdmin = $totalUntuk('admin');
$assert($totalAdmin > 0, 'KR-2a Admin menerima laporan berisi data (' . $totalAdmin . ' pengajuan)');
$assert($modeUntuk('admin') === 'admin', 'KR-2b Cakupan admin dikenali server');
$assert($modeUntuk('pengurus_a') === 'pengurus', 'KR-2c Cakupan pengurus dikenali server');
$assert($modeUntuk('murobi_a') === 'murobi', 'KR-2d Cakupan murobi dikenali server');
$assert($modeUntuk('ortu_a') === 'orang_tua', 'KR-2e Cakupan orang tua dikenali server');

foreach (['pengurus_a', 'murobi_a', 'ortu_a'] as $alias) {
    $assert(
        $totalUntuk($alias) <= $totalAdmin,
        'KR-2f Cakupan ' . $alias . ' tidak pernah melebihi cakupan admin'
    );
}

// Baris yang diterima orang tua HANYA milik santri terhubung.
$anak = $http('GET', '/izin/anak', null, $token('ortu_a'));
$idAnak = array_map(static fn (array $r): int => (int) $r['santri']['id'], $anak['json']['data']['items'] ?? []);
$itemOrtu = $http('GET', '/izin/laporan?' . $rentang . '&per_page=100', null, $token('ortu_a'))['json']['data']['items'] ?? [];
$santriTerlihat = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['santri_id'], $itemOrtu)));
$assert(
    $idAnak !== [] && array_diff($santriTerlihat, $idAnak) === [],
    'KR-2g Seluruh baris laporan orang tua hanya milik santri yang terhubung'
);

// Cakupan berlaku pada SETIAP permukaan, bukan hanya daftar.
foreach (['/izin/laporan/cetak', '/izin/laporan/csv'] as $ruteEkspor) {
    $ekspor = $http('GET', $ruteEkspor . '?' . $rentang, null, $token('ortu_a'));
    $assert($ekspor['status'] === 200, 'KR-2h Orang tua dapat mengekspor laporan cakupannya (' . $ruteEkspor . ')');
}
$csvOrtuA = $http('GET', '/izin/laporan/csv?' . $rentang, null, $token('ortu_a'));
$csvAdmin = $http('GET', '/izin/laporan/csv?' . $rentang, null, $token('admin'));
$assert(
    (int) ($csvOrtuA['json']['data']['jumlah_baris'] ?? -1) < (int) ($csvAdmin['json']['data']['jumlah_baris'] ?? -1),
    'KR-2i CSV orang tua memuat baris lebih sedikit daripada CSV admin'
);

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== KR-3. Parameter tidak memperluas cakupan ===' . PHP_EOL;
// ---------------------------------------------------------------------------

$pengurusBId = (int) ($db->query(
    "SELECT p.id FROM pengurus p JOIN users u ON u.pengurus_id = p.id WHERE u.username = 'sbx_pengurus_b' LIMIT 1"
)?->fetch_assoc()['id'] ?? 0);
$guruBId = (int) ($db->query(
    "SELECT g.id FROM guru g JOIN users u ON u.guru_id = g.id WHERE u.username = 'sbx_murobi_b' LIMIT 1"
)?->fetch_assoc()['id'] ?? 0);

$assert($pengurusBId > 0 && $guruBId > 0, 'KR-3a ID pengurus B dan murobi B ditemukan pada fixture');

foreach ([
    ['/izin/laporan', 'pengurus_a', 'pengurus_id=' . $pengurusBId, 'daftar'],
    ['/izin/laporan/csv', 'pengurus_a', 'pengurus_id=' . $pengurusBId, 'CSV'],
    ['/izin/laporan/cetak', 'pengurus_a', 'pengurus_id=' . $pengurusBId, 'cetak'],
    ['/izin/laporan', 'murobi_a', 'murobi_guru_id=' . $guruBId, 'daftar'],
    ['/izin/laporan/csv', 'murobi_a', 'murobi_guru_id=' . $guruBId, 'CSV'],
] as [$rute, $alias, $param, $label]) {
    $r = $http('GET', $rute . '?' . $rentang . '&' . $param, null, $token($alias));
    $assert(
        $r['status'] === 403,
        'KR-3b ' . $alias . ' ditolak 403 saat memperluas cakupan lewat ' . $label . ' [' . $r['status'] . ']'
    );
    $assert(
        ($r['json']['error']['code'] ?? '') === 'FORBIDDEN',
        'KR-3c Kode galat penolakan adalah FORBIDDEN'
    );
}

// `mode` bukan hak akses.
$modePaksa = $http('GET', '/izin/laporan?' . $rentang . '&mode=admin', null, $token('ortu_a'));
$assert(
    $modePaksa['status'] === 200
        && ($modePaksa['json']['data']['cakupan']['mode'] ?? '') === 'orang_tua',
    'KR-3d Parameter mode=admin dari akun orang tua tidak memberi hak admin'
);
$assert(
    (int) ($modePaksa['json']['data']['ringkasan']['total'] ?? -1) === $totalUntuk('ortu_a'),
    'KR-3e Total tetap sama dengan cakupan sah walau mode dipalsukan'
);

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== KR-4. Konsistensi antar permukaan lewat HTTP ===' . PHP_EOL;
// ---------------------------------------------------------------------------

foreach (['admin', 'pengurus_a', 'murobi_a', 'ortu_a'] as $alias) {
    $rep = $http('GET', '/izin/laporan?' . $rentang . '&per_page=1', null, $token($alias))['json']['data'] ?? [];
    $cetak = $http('GET', '/izin/laporan/cetak?' . $rentang, null, $token($alias))['json']['data'] ?? [];
    $csv = $http('GET', '/izin/laporan/csv?' . $rentang, null, $token($alias))['json']['data'] ?? [];

    $total = (int) ($rep['ringkasan']['total'] ?? -1);
    $assert(
        $total === (int) ($cetak['jumlah_baris'] ?? -2),
        'KR-4a [' . $alias . '] total ringkasan = jumlah baris cetak (' . $total . ')'
    );
    $assert(
        $total === (int) ($csv['jumlah_baris'] ?? -2),
        'KR-4b [' . $alias . '] total ringkasan = jumlah baris CSV'
    );
    $assert(
        count(array_unique([
            (string) ($rep['kriteria'] ?? 'a'),
            (string) ($cetak['kriteria'] ?? 'b'),
            (string) ($csv['kriteria'] ?? 'c'),
        ])) === 1,
        'KR-4c [' . $alias . '] ketiga permukaan memakai sidik jari kriteria yang sama'
    );
    $assert(
        (int) ($rep['pagination']['per_page'] ?? 0) === 1 && $total !== (int) count($rep['items'] ?? []) || $total <= 1,
        'KR-4d [' . $alias . '] pagination membatasi daftar tanpa membatasi ekspor'
    );
}

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== KR-5. CSV lewat API ===' . PHP_EOL;
// ---------------------------------------------------------------------------

$csvData = $csvAdmin['json']['data'] ?? [];
$konten = (string) ($csvData['konten'] ?? '');
$assert($konten !== '', 'KR-5a Respons CSV memuat isi berkas');
$assert(str_starts_with($konten, "\xEF\xBB\xBF"), 'KR-5b CSV lewat API tetap diawali BOM UTF-8');

$barisCsv = array_values(array_filter(explode("\n", trim($konten)), static fn (string $b): bool => trim($b) !== ''));
$assert(
    count($barisCsv) === (int) ($csvData['jumlah_baris'] ?? -1) + 1,
    'KR-5c Jumlah baris berkas sesuai dengan jumlah_baris yang dilaporkan'
);
$assert(
    str_getcsv(ltrim($barisCsv[0], "\xEF\xBB\xBF")) === IzinCsvExport::HEADERS,
    'KR-5d Header CSV lewat API sama dengan konstanta terdokumentasi'
);
$selBerbahaya = 0;
foreach (array_slice($barisCsv, 1) as $baris) {
    foreach (str_getcsv($baris) as $sel) {
        if ($sel !== '' && in_array(substr($sel, 0, 1), IzinCsvExport::PEMBUKA_BERBAHAYA, true)) {
            $selBerbahaya++;
        }
    }
}
$assert($selBerbahaya === 0, 'KR-5e Tidak ada sel CSV lewat API yang diawali karakter formula');
$assert(
    array_key_exists('nama_berkas', $csvData) && str_ends_with((string) $csvData['nama_berkas'], '.csv'),
    'KR-5f Respons CSV menyertakan nama berkas unduhan'
);
$assert(
    ($csvData['terpotong'] ?? null) === false,
    'KR-5g Respons CSV menyatakan hasil tidak terpotong'
);
// Pagination TIDAK boleh mempengaruhi ekspor.
$csvSempit = $http('GET', '/izin/laporan/csv?' . $rentang . '&page=1&per_page=1', null, $token('admin'));
$assert(
    (int) ($csvSempit['json']['data']['jumlah_baris'] ?? -1) === (int) ($csvData['jumlah_baris'] ?? -2),
    'KR-5h CSV mengabaikan pagination dan tetap memuat seluruh hasil filter'
);

// Halaman cetak lewat API.
$cetakAdmin = $http('GET', '/izin/laporan/cetak?' . $rentang, null, $token('admin'));
$html = (string) ($cetakAdmin['json']['data']['html'] ?? '');
foreach (['Pesantren Al Hasan', 'Laporan Perizinan Santri', 'counter(page)', 'Median durasi keputusan'] as $penanda) {
    $assert(str_contains($html, $penanda), 'KR-5i HTML cetak lewat API memuat "' . $penanda . '"');
}

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== KR-6. Validasi input ===' . PHP_EOL;
// ---------------------------------------------------------------------------

foreach ([
    'date_from=2029-12-31&date_to=2029-01-01' => 'tanggal akhir sebelum awal',
    'date_from=31-12-2029' => 'format tanggal salah',
    $rentang . '&status=TidakAda' => 'status tidak dikenal',
    $rentang . '&basis_tanggal=entah' => 'basis tanggal tidak dikenal',
    $rentang . '&kanal=Telegram' => 'kanal tidak dikenal',
    $rentang . '&santri_id=1+OR+1%3D1' => 'ID non-numerik (percobaan injeksi)',
    $rentang . '&durasi_min_jam=9&durasi_maks_jam=2' => 'durasi maks lebih kecil dari min',
    $rentang . '&sumber=entah' => 'sumber data tidak dikenal',
] as $query => $label) {
    $r = $http('GET', '/izin/laporan?' . $query, null, $token('admin'));
    $assert($r['status'] === 422, 'KR-6a Ditolak 422: ' . $label . ' [' . $r['status'] . ']');
    $assert(
        ($r['json']['data'] ?? null) === null && ($r['json']['error']['code'] ?? '') === 'VALIDATION_FAILED',
        'KR-6b Respons 422 tidak memuat data dan berkode VALIDATION_FAILED: ' . $label
    );
}

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== KR-7. Autentikasi ===' . PHP_EOL;
// ---------------------------------------------------------------------------

foreach (['/izin/laporan', '/izin/laporan/filters', '/izin/laporan/cetak', '/izin/laporan/csv', '/izin/laporan/explain'] as $rute) {
    $assert(
        $http('GET', $rute . '?' . $rentang)['status'] === 401,
        'KR-7a Tanpa token, ' . $rute . ' menjawab 401'
    );
    $assert(
        $http('GET', $rute . '?' . $rentang, null, 'token-palsu-yang-tidak-pernah-ada')['status'] === 401,
        'KR-7b Token palsu ditolak 401 pada ' . $rute
    );
}

// Token yang sudah dicabut lewat logout tidak boleh dipakai lagi.
$sesiSekali = $login('sbx_ortu_b');
$tokenSekali = (string) ($sesiSekali['token'] ?? '');
$assert(
    $http('GET', '/izin/laporan?' . $rentang, null, $tokenSekali)['status'] === 200,
    'KR-7c Token baru dapat membuka laporan'
);
$http('POST', '/auth/logout', [], $tokenSekali);
$assert(
    $http('GET', '/izin/laporan?' . $rentang, null, $tokenSekali)['status'] === 401,
    'KR-7d Token yang sudah di-logout tidak dapat membuka laporan'
);

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== KR-8. EXPLAIN hanya admin ===' . PHP_EOL;
// ---------------------------------------------------------------------------

$explainAdmin = $http('GET', '/izin/laporan/explain?' . $rentang, null, $token('admin'));
$assert($explainAdmin['status'] === 200, 'KR-8a Admin dapat membuka rencana eksekusi query');
$assert(
    isset($explainAdmin['json']['data']['explain']['ringkasan'])
        && isset($explainAdmin['json']['data']['explain']['detail']),
    'KR-8b Respons EXPLAIN memuat rencana ringkasan dan detail'
);
foreach (['pengurus_a', 'murobi_a', 'ortu_a'] as $alias) {
    $assert(
        $http('GET', '/izin/laporan/explain?' . $rentang, null, $token($alias))['status'] === 403,
        'KR-8c ' . $alias . ' ditolak 403 saat membuka EXPLAIN'
    );
}

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== KR-8b. Akun tanpa kemampuan perizinan ===' . PHP_EOL;
// ---------------------------------------------------------------------------
//
// REGRESI: `IzinService::scopeFor()` melempar `IzinException`, sedangkan router
// API hanya menangani `ApiException`. Sebelum diperbaiki, akun tanpa kemampuan
// perizinan menerima `500 SERVER_ERROR` — membocorkan galat internal dan
// menyembunyikan penolakan yang sebenarnya. Endpoint laporan WAJIB menjawab 403.

$guruBiasaAda = ($db->query("SELECT id FROM users WHERE username = 'sbx_guru_biasa' LIMIT 1")?->num_rows ?? 0) === 1;
if (!$guruBiasaAda) {
    echo '[lewat] Fixture sbx_guru_biasa tidak tersedia.' . PHP_EOL;
} else {
    $sesiGuru = $login('sbx_guru_biasa');
    $tokenGuru = (string) ($sesiGuru['token'] ?? '');
    foreach (['/izin/laporan', '/izin/laporan/filters', '/izin/laporan/cetak', '/izin/laporan/csv', '/izin/laporan/explain'] as $rute) {
        $r = $http('GET', $rute . '?' . $rentang, null, $tokenGuru);
        $assert(
            $r['status'] === 403,
            'KR-8d Guru tanpa penugasan murobi ditolak 403 (bukan 500) pada ' . $rute . ' [' . $r['status'] . ']'
        );
        $assert(
            ($r['json']['error']['code'] ?? '') !== 'SERVER_ERROR',
            'KR-8e Penolakan pada ' . $rute . ' bukan galat server yang bocor'
        );
    }
    // Endpoint jadwal V1 miliknya TETAP dapat diakses: penolakan laporan
    // perizinan tidak boleh mematikan kemampuan guru yang sah.
    $assert(
        $http('GET', '/schedules/today', null, $tokenGuru)['status'] === 200,
        'KR-8f Guru biasa tetap dapat memakai endpoint jadwal V1 miliknya'
    );
}

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== KR-9. Regresi endpoint V1 dan Fase 3-4 ===' . PHP_EOL;
// ---------------------------------------------------------------------------

$assert($http('GET', '/', null, null)['status'] === 200, 'KR-9a Endpoint akar API tetap 200');
$assert($http('GET', '/profile', null, $token('admin'))['status'] === 200, 'KR-9b /profile tetap berfungsi');
$assert($http('GET', '/me/capabilities', null, $token('pengurus_a'))['status'] === 200, 'KR-9c /me/capabilities tetap berfungsi');
$assert($http('GET', '/izin/pengajuan', null, $token('pengurus_a'))['status'] === 200, 'KR-9d /izin/pengajuan Fase 3 tetap berfungsi');
$assert($http('GET', '/izin/antrean', null, $token('murobi_a'))['status'] === 200, 'KR-9e /izin/antrean Fase 3 tetap berfungsi');
$assert($http('GET', '/notifikasi', null, $token('murobi_a'))['status'] === 200, 'KR-9f /notifikasi Fase 4 tetap berfungsi');
$assert($http('GET', '/notifikasi/belum-dibaca', null, $token('murobi_a'))['status'] === 200, 'KR-9g Jumlah belum dibaca Fase 4 tetap berfungsi');

// Laporan absensi V1 (aplikasi guru) TIDAK boleh berubah kontraknya.
$reportsV1 = $http('GET', '/reports?date_from=2000-01-01&date_to=2100-12-31', null, $token('admin'));
$assert($reportsV1['status'] === 200, 'KR-9h Laporan absensi V1 /reports tetap 200');
foreach (['summary', 'items', 'pagination', 'filters', 'active_filters'] as $kunci) {
    $assert(
        array_key_exists($kunci, $reportsV1['json']['data'] ?? []),
        'KR-9i Kontrak /reports V1 tetap memuat `' . $kunci . '`'
    );
}
$assert(
    $http('GET', '/reports/filters', null, $token('admin'))['status'] === 200,
    'KR-9j /reports/filters V1 tetap berfungsi'
);
$assert(
    $http('GET', '/reports/print?date_from=2000-01-01&date_to=2100-12-31', null, $token('admin'))['status'] === 200,
    'KR-9k /reports/print V1 tetap berfungsi'
);
// Endpoint laporan V2 TIDAK boleh menimpa endpoint laporan V1.
$assert(
    !array_key_exists('cakupan', $reportsV1['json']['data'] ?? []),
    'KR-9l Respons /reports V1 tidak tercampur bentuk laporan V2'
);

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== KR-10. Tidak ada secret pada respons ===' . PHP_EOL;
// ---------------------------------------------------------------------------

$semuaRespons = '';
foreach (['/izin/laporan', '/izin/laporan/filters', '/izin/laporan/cetak', '/izin/laporan/csv'] as $rute) {
    $semuaRespons .= $http('GET', $rute . '?' . $rentang, null, $token('admin'))['raw'];
}
foreach ([
    'ExponentPushToken' => 'token push',
    (string) app_config('api.token_hash_secret') => 'secret hash token API',
    (string) app_config('database.password') => 'password basis data',
] as $rahasia => $label) {
    if (trim($rahasia) === '') {
        continue;
    }
    $assert(!str_contains($semuaRespons, $rahasia), 'KR-10a Respons laporan tidak memuat ' . $label);
}
$assert(
    !preg_match('/"password"\s*:|token_hash|PUSH_TOKEN_KEY|DB_PASSWORD/i', $semuaRespons),
    'KR-10b Respons laporan tidak memuat nama field credential apa pun'
);

// ---------------------------------------------------------------------------
echo PHP_EOL;
if ($failures !== []) {
    echo 'GAGAL (' . count($failures) . '):' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}
echo 'SELURUH PENGUJIAN KONTRAK API FASE 5 LULUS.' . PHP_EOL;
exit(0);
