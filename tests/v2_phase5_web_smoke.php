<?php

declare(strict_types=1);

/**
 * Smoke test web V2 Fase 5 — halaman laporan, cetak, dan unduhan CSV.
 *
 * Menjalankan server PHP lokal dan membuka halaman portal SUNGGUHAN dengan
 * sesi login per peran (cookie jar terpisah), bukan memanggil kelas langsung:
 *
 *   WL-1  halaman laporan menolak anonim dan mengarahkan ke halaman masuk;
 *   WL-2  setiap peran melihat halamannya sendiri dengan cakupan yang benar;
 *   WL-3  isolasi cakupan terlihat pada HTML yang benar-benar dirender;
 *   WL-4  halaman cetak memuat identitas, filter, pembuat, waktu, nomor halaman;
 *   WL-5  unduhan CSV memakai header HTTP yang benar dan isi yang aman;
 *   WL-6  parameter yang memperluas cakupan ditolak, bukan diam-diam dituruti;
 *   WL-7  filter tidak valid ditangani tanpa membocorkan galat internal;
 *   WL-8  halaman perizinan V1/V2 lain tetap dapat dibuka (regresi navigasi).
 *
 * Jalankan:
 *   V2_PHASE5_RUN_WEB=1 php tests/v2_phase5_web_smoke.php
 */

$root = dirname(__DIR__);
if (getenv('V2_PHASE5_RUN_WEB') !== '1') {
    fwrite(STDOUT, "[lewati] Set V2_PHASE5_RUN_WEB=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

$kunciUji = base64_encode(str_repeat("\x37", 32));
putenv('PUSH_TOKEN_KEY=' . $kunciUji);
$_ENV['PUSH_TOKEN_KEY'] = $kunciUji;

require_once $root . '/app/bootstrap.php';

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Smoke test ditolak: DB_NAME wajib berakhiran _test.\n");
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

$portBebas = static function (): int {
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        return 8814;
    }
    $nama = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($nama, (int) strrpos($nama, ':') + 1);
};
$portDiminta = (int) (getenv('V2_PHASE5_WEB_PORT') ?: 0);
if ($portDiminta > 0) {
    $probe = @fsockopen('127.0.0.1', $portDiminta, $errno, $errstr, 0.5);
    if (is_resource($probe)) {
        fclose($probe);
        fwrite(STDERR, "Port {$portDiminta} sudah dipakai proses lain.\n");
        exit(2);
    }
}
$port = $portDiminta > 0 ? $portDiminta : $portBebas();
$baseUrl = 'http://127.0.0.1:' . $port;
$sandi = 'Sandbox#123';

/** Klien HTTP sederhana dengan cookie jar per peran. */
final class KlienWebFase5
{
    private string $jar;

    public function __construct(private string $baseUrl, string $label)
    {
        $this->jar = (string) tempnam(sys_get_temp_dir(), 'wah5-' . $label . '-');
    }

    /**
     * @param array<string, string>|null $post
     * @return array{status:int, body:string, headers:string, location:?string}
     */
    public function request(string $path, ?array $post = null): array
    {
        $handle = curl_init($this->baseUrl . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($post !== null) {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $location = null;
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $matches) === 1) {
            $location = trim($matches[1]);
        }

        return ['status' => $status, 'body' => $body, 'headers' => $headers, 'location' => $location];
    }

    public function csrf(string $path): string
    {
        $response = $this->request($path);
        if (preg_match('/name="_csrf" value="([^"]+)"/', $response['body'], $matches) === 1) {
            return $matches[1];
        }
        throw new RuntimeException('Token CSRF tidak ditemukan pada ' . $path);
    }

    public function login(string $username, string $password): array
    {
        return $this->request('/admin/cek_login.php', [
            '_csrf' => $this->csrf('/admin/admin_login.php'),
            'username' => $username,
            'password' => $password,
        ]);
    }

    public function bersih(): void
    {
        @unlink($this->jar);
    }
}

$server = null;
$klien = [];

try {
    foreach (['sbx_admin', 'sbx_pengurus_a', 'sbx_pengurus_b', 'sbx_murobi_a', 'sbx_ortu_a', 'sbx_ortu_b'] as $required) {
        $row = $db->query("SELECT id FROM users WHERE username = '" . $db->real_escape_string($required) . "' LIMIT 1");
        if (!$row || $row->num_rows === 0) {
            throw new RuntimeException('Fixture sandbox belum tersedia. Jalankan: V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php');
        }
    }

    // --------------------------------------------------------- server lokal
    $descriptors = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $pipes = [];
    $serverEnv = getenv();
    $serverEnv['PUSH_TOKEN_KEY'] = $kunciUji;
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
        $descriptors,
        $pipes,
        $root,
        $serverEnv
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Server uji tidak dapat dijalankan.');
    }
    $siap = false;
    for ($i = 0; $i < 60; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
        if ($socket !== false) {
            fclose($socket);
            $siap = true;
            break;
        }
        usleep(200000);
    }
    if (!$siap) {
        throw new RuntimeException('Server uji tidak merespons pada port ' . $port . '.');
    }

    $rentang = 'date_from=2000-01-01&date_to=2100-12-31';

    // =====================================================================
    echo PHP_EOL . '=== WL-1. Anonim ditolak ===' . PHP_EOL;
    // =====================================================================

    $anon = new KlienWebFase5($baseUrl, 'anon');
    $klien['anon'] = $anon;

    foreach (['/portal/laporan.php', '/portal/laporan_cetak.php', '/portal/laporan_csv.php'] as $halaman) {
        $r = $anon->request($halaman . '?' . $rentang);
        $assert(
            $r['status'] === 302 && str_contains((string) $r['location'], 'admin_login.php'),
            'WL-1a Anonim diarahkan ke halaman masuk dari ' . $halaman . ' [' . $r['status'] . ']'
        );
        $assert(
            !str_contains($r['body'], 'SBX Santri'),
            'WL-1b Tidak ada data santri yang bocor pada respons anonim dari ' . $halaman
        );
    }

    // =====================================================================
    echo PHP_EOL . '=== WL-2. Setiap peran membuka laporannya ===' . PHP_EOL;
    // =====================================================================

    foreach ([
        'admin' => 'sbx_admin',
        'pengurus_a' => 'sbx_pengurus_a',
        'pengurus_b' => 'sbx_pengurus_b',
        'murobi_a' => 'sbx_murobi_a',
        'ortu_a' => 'sbx_ortu_a',
        'ortu_b' => 'sbx_ortu_b',
    ] as $alias => $username) {
        $klien[$alias] = new KlienWebFase5($baseUrl, $alias);
        $klien[$alias]->login($username, $sandi);
    }

    $labelCakupan = [
        'admin' => 'Admin — seluruh pengajuan',
        'pengurus_a' => 'Pengurus — pengajuan yang Anda buat',
        'murobi_a' => 'Murobi — pengajuan yang diarahkan kepada Anda',
        'ortu_a' => 'Orang tua — santri yang terhubung dengan Anda',
    ];

    $halamanPeran = [];
    foreach ($labelCakupan as $alias => $label) {
        $r = $klien[$alias]->request('/portal/laporan.php?' . $rentang);
        $halamanPeran[$alias] = $r['body'];
        $assert($r['status'] === 200, 'WL-2a Halaman laporan terbuka untuk ' . $alias . ' [' . $r['status'] . ']');
        $assert(str_contains($r['body'], 'Laporan Perizinan'), 'WL-2b Judul laporan tampil untuk ' . $alias);
        $assert(str_contains($r['body'], $label), 'WL-2c Label cakupan benar untuk ' . $alias);
        $assert(
            str_contains($r['body'], 'Median durasi keputusan'),
            'WL-2d Ringkasan median durasi tampil untuk ' . $alias
        );
        $assert(
            str_contains($r['body'], 'Total pengajuan'),
            'WL-2e Ringkasan total tampil untuk ' . $alias
        );
        $assert(
            !preg_match('/Fatal error|Uncaught|Warning:/i', $r['body']),
            'WL-2f Tidak ada galat PHP pada halaman laporan ' . $alias
        );
    }

    // Navigasi portal memuat tautan laporan untuk seluruh peran perizinan.
    foreach (array_keys($labelCakupan) as $alias) {
        $assert(
            str_contains($halamanPeran[$alias], '/portal/laporan.php'),
            'WL-2g Navigasi ' . $alias . ' memuat tautan laporan'
        );
    }

    // =====================================================================
    echo PHP_EOL . '=== WL-3. Isolasi cakupan pada HTML terender ===' . PHP_EOL;
    // =====================================================================

    // Nama santri milik pengurus/wali B tidak boleh muncul pada halaman A.
    $santriB1 = (string) ($db->query("SELECT nama_santri FROM santri WHERE nis = 'SBX-S-003' LIMIT 1")?->fetch_assoc()['nama_santri'] ?? '');
    $santriA1 = (string) ($db->query("SELECT nama_santri FROM santri WHERE nis = 'SBX-S-001' LIMIT 1")?->fetch_assoc()['nama_santri'] ?? '');
    $assert($santriA1 !== '' && $santriB1 !== '', 'WL-3a Nama santri fixture ditemukan');

    $halamanOrtuA = $klien['ortu_a']->request('/portal/laporan.php?' . $rentang . '&per_page=100')['body'];
    $assert(
        !str_contains($halamanOrtuA, $santriB1),
        'WL-3b Laporan orang tua A tidak memuat santri yang tidak terhubung'
    );
    $halamanOrtuB = $klien['ortu_b']->request('/portal/laporan.php?' . $rentang . '&per_page=100')['body'];
    $assert(
        !str_contains($halamanOrtuB, $santriA1),
        'WL-3c Laporan orang tua B tidak memuat santri yang tidak terhubung'
    );

    $csvOrtuA = $klien['ortu_a']->request('/portal/laporan_csv.php?' . $rentang);
    $assert(
        !str_contains($csvOrtuA['body'], $santriB1),
        'WL-3d Unduhan CSV orang tua A tidak memuat santri di luar cakupannya'
    );
    $cetakOrtuA = $klien['ortu_a']->request('/portal/laporan_cetak.php?' . $rentang);
    $assert(
        !str_contains($cetakOrtuA['body'], $santriB1),
        'WL-3e Halaman cetak orang tua A tidak memuat santri di luar cakupannya'
    );

    // =====================================================================
    echo PHP_EOL . '=== WL-4. Halaman cetak ===' . PHP_EOL;
    // =====================================================================

    $cetakAdmin = $klien['admin']->request('/portal/laporan_cetak.php?' . $rentang);
    $assert($cetakAdmin['status'] === 200, 'WL-4a Halaman cetak terbuka [' . $cetakAdmin['status'] . ']');
    foreach ([
        'Pesantren Al Hasan' => 'identitas pesantren',
        'Laporan Perizinan Santri' => 'judul laporan',
        'Dibuat oleh' => 'pembuat laporan',
        'Waktu pembuatan' => 'waktu pembuatan',
        'Rentang tanggal izin' => 'filter aktif',
        'Median durasi keputusan' => 'median durasi keputusan',
    ] as $penanda => $arti) {
        $assert(str_contains($cetakAdmin['body'], $penanda), 'WL-4b Halaman cetak memuat ' . $arti);
    }
    // Nomor halaman diperiksa sebagai TEKS HASIL, bukan sebagai string CSS
    // `counter(page)` yang justru menghasilkan "Halaman 0" pada Safari.
    $lembarCetak = substr_count($cetakAdmin['body'], '<section class="lembar">');
    preg_match_all('/Halaman (\d+) dari (\d+)/', $cetakAdmin['body'], $nomorCetak);
    $assert(
        $lembarCetak >= 1
            && !str_contains($cetakAdmin['body'], 'Halaman 0')
            && $nomorCetak[1] === array_map('strval', range(1, $lembarCetak))
            && $nomorCetak[2] === array_fill(0, $lembarCetak, (string) $lembarCetak),
        'WL-4b2 Halaman cetak memuat nomor halaman 1..' . $lembarCetak . ' tanpa "Halaman 0"'
    );
    $assert(
        str_contains($cetakAdmin['headers'], 'no-store'),
        'WL-4c Halaman cetak dikirim dengan Cache-Control: no-store'
    );
    $assert(
        !preg_match('/Fatal error|Uncaught|Warning:/i', $cetakAdmin['body']),
        'WL-4d Tidak ada galat PHP pada halaman cetak'
    );

    // =====================================================================
    echo PHP_EOL . '=== WL-5. Unduhan CSV ===' . PHP_EOL;
    // =====================================================================

    $csvAdmin = $klien['admin']->request('/portal/laporan_csv.php?' . $rentang);
    $assert($csvAdmin['status'] === 200, 'WL-5a Unduhan CSV menjawab 200');
    $assert(
        str_contains(strtolower($csvAdmin['headers']), 'content-type: text/csv'),
        'WL-5b Content-Type unduhan adalah text/csv'
    );
    $assert(
        str_contains(strtolower($csvAdmin['headers']), 'content-disposition: attachment'),
        'WL-5c CSV dipaksa diunduh sebagai lampiran'
    );
    $assert(
        str_contains(strtolower($csvAdmin['headers']), 'x-content-type-options: nosniff'),
        'WL-5d CSV dikirim dengan nosniff'
    );
    $assert(
        preg_match('/^X-Laporan-Jumlah-Baris:\s*(\d+)/mi', $csvAdmin['headers'], $cocok) === 1,
        'WL-5e CSV mengumumkan jumlah baris pada header'
    );
    $jumlahHeader = (int) ($cocok[1] ?? -1);
    $barisIsi = array_values(array_filter(explode("\n", trim($csvAdmin['body'])), static fn (string $b): bool => trim($b) !== ''));
    $assert(
        count($barisIsi) === $jumlahHeader + 1,
        'WL-5f Jumlah baris berkas (' . (count($barisIsi) - 1) . ') sesuai header X-Laporan-Jumlah-Baris (' . $jumlahHeader . ')'
    );
    $assert(str_starts_with($csvAdmin['body'], "\xEF\xBB\xBF"), 'WL-5g Berkas CSV diawali BOM UTF-8');

    $selBerbahaya = 0;
    foreach (array_slice($barisIsi, 1) as $baris) {
        foreach (str_getcsv($baris) as $sel) {
            if ($sel !== '' && in_array(substr($sel, 0, 1), ['=', '+', '-', '@', "\t", "\r"], true)) {
                $selBerbahaya++;
            }
        }
    }
    $assert($selBerbahaya === 0, 'WL-5h Tidak ada sel CSV yang diawali karakter formula');

    // Pagination tidak boleh memotong ekspor.
    $csvSempit = $klien['admin']->request('/portal/laporan_csv.php?' . $rentang . '&page=1&per_page=1');
    preg_match('/^X-Laporan-Jumlah-Baris:\s*(\d+)/mi', $csvSempit['headers'], $cocokSempit);
    $assert(
        (int) ($cocokSempit[1] ?? -1) === $jumlahHeader,
        'WL-5i Unduhan CSV mengabaikan pagination dan tetap memuat seluruh hasil filter'
    );

    // =====================================================================
    echo PHP_EOL . '=== WL-6. Parameter tidak memperluas cakupan ===' . PHP_EOL;
    // =====================================================================

    $pengurusBId = (int) ($db->query(
        "SELECT p.id FROM pengurus p JOIN users u ON u.pengurus_id = p.id WHERE u.username = 'sbx_pengurus_b' LIMIT 1"
    )?->fetch_assoc()['id'] ?? 0);
    $guruBId = (int) ($db->query(
        "SELECT g.id FROM guru g JOIN users u ON u.guru_id = g.id WHERE u.username = 'sbx_murobi_b' LIMIT 1"
    )?->fetch_assoc()['id'] ?? 0);

    $luas = $klien['pengurus_a']->request('/portal/laporan.php?' . $rentang . '&pengurus_id=' . $pengurusBId);
    $assert($luas['status'] === 403, 'WL-6a Pengurus A ditolak 403 saat memperluas cakupan [' . $luas['status'] . ']');
    $assert(
        str_contains($luas['body'], 'Laporan tidak dapat ditampilkan') || str_contains($luas['body'], 'hanya dapat membuka'),
        'WL-6b Penolakan dijelaskan kepada pengguna, bukan halaman kosong'
    );
    $assert(
        !str_contains($luas['body'], $santriB1),
        'WL-6c Tidak ada data cakupan lain yang bocor pada halaman penolakan'
    );

    $csvLuas = $klien['murobi_a']->request('/portal/laporan_csv.php?' . $rentang . '&murobi_guru_id=' . $guruBId);
    $assert($csvLuas['status'] === 403, 'WL-6d Unduhan CSV yang memperluas cakupan ditolak 403');
    $assert(
        !str_contains(strtolower($csvLuas['headers']), 'content-type: text/csv'),
        'WL-6e Penolakan CSV tidak mengirim berkas apa pun'
    );

    $cetakLuas = $klien['pengurus_a']->request('/portal/laporan_cetak.php?' . $rentang . '&pengurus_id=' . $pengurusBId);
    $assert($cetakLuas['status'] === 403, 'WL-6f Halaman cetak yang memperluas cakupan ditolak 403');

    // =====================================================================
    echo PHP_EOL . '=== WL-7. Filter tidak valid ===' . PHP_EOL;
    // =====================================================================

    foreach ([
        'date_from=2030-01-01&date_to=2029-01-01' => 'tanggal terbalik',
        $rentang . '&status=TidakAda' => 'status tidak dikenal',
        $rentang . '&santri_id=abc' => 'ID non-numerik',
    ] as $query => $label) {
        $r = $klien['admin']->request('/portal/laporan.php?' . $query);
        $assert($r['status'] === 422, 'WL-7a Filter tidak valid (' . $label . ') dijawab 422 [' . $r['status'] . ']');
        $assert(
            !preg_match('/Fatal error|Uncaught|SQLSTATE|mysqli/i', $r['body']),
            'WL-7b Halaman galat (' . $label . ') tidak membocorkan detail internal'
        );
        $assert(
            str_contains($r['body'], 'Bersihkan filter'),
            'WL-7c Pengguna diberi jalan keluar dari filter yang salah (' . $label . ')'
        );
    }

    // =====================================================================
    echo PHP_EOL . '=== WL-8. Regresi halaman lain ===' . PHP_EOL;
    // =====================================================================

    foreach ([
        '/portal/index.php' => 'admin',
        '/portal/izin.php' => 'admin',
        '/portal/izin_antrean.php' => 'murobi_a',
        '/portal/notifikasi.php' => 'murobi_a',
    ] as $halaman => $alias) {
        $r = $klien[$alias]->request($halaman);
        $assert(
            $r['status'] === 200 && !preg_match('/Fatal error|Uncaught/i', $r['body']),
            'WL-8a Halaman ' . $halaman . ' tetap terbuka untuk ' . $alias . ' [' . $r['status'] . ']'
        );
    }
    $adminNotif = $klien['admin']->request('/admin/admin_notifikasi.php');
    $assert(
        $adminNotif['status'] === 200,
        'WL-8b Panel kanal notifikasi Fase 4 tetap terbuka [' . $adminNotif['status'] . ']'
    );
    $laporanAbsensi = $klien['admin']->request('/admin/admin_laporan_absensi.php');
    $assert(
        in_array($laporanAbsensi['status'], [200, 302], true)
            && !preg_match('/Fatal error|Uncaught/i', $laporanAbsensi['body']),
        'WL-8c Laporan absensi V1 tidak rusak oleh laporan V2 [' . $laporanAbsensi['status'] . ']'
    );
} finally {
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
    foreach ($klien as $c) {
        $c->bersih();
    }
}

echo PHP_EOL;
if ($failures !== []) {
    echo 'GAGAL (' . count($failures) . '):' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}
echo 'SELURUH SMOKE TEST WEB FASE 5 LULUS.' . PHP_EOL;
exit(0);
