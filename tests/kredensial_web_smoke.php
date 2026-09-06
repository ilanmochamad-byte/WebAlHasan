<?php

declare(strict_types=1);

/**
 * Smoke test web fitur "Pesan Kredensial Akun Siap Salin"
 * (keputusan pengguna 6 September 2026).
 *
 * Yang hanya terlihat lewat HTTP dan karena itu diuji di sini:
 *
 *   PW-1  panel kredensial muncul setelah pembuatan akun guru berhasil;
 *   PW-2  respons yang memuat password memakai Cache-Control private, no-store;
 *   PW-3  password sementara yang tampil benar-benar cocok dengan hash di basis
 *         data, jadi bukan nilai hiasan;
 *   PW-4  memuat ulang halaman menghilangkan panel dan password;
 *   PW-5  password tidak pernah muncul pada URL, header Location, atau cookie;
 *   PW-6  perilaku sama untuk akun pengurus dan orang tua;
 *   PW-7  pembuatan akun yang GAGAL tidak menampilkan panel kredensial;
 *   PW-8  retry POST yang sama tidak membuat akun atau pesan kedua;
 *   PW-9  isi kotak pesan sama persis dengan teks baku;
 *   PW-10 halaman daftar akun dan detailnya tidak pernah menampilkan password.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   KREDENSIAL_RUN_WEB=1 php tests/kredensial_web_smoke.php
 */

$root = dirname(__DIR__);
if (getenv('KREDENSIAL_RUN_WEB') !== '1') {
    fwrite(STDOUT, "[lewati] Set KREDENSIAL_RUN_WEB=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

require_once $root . '/app/bootstrap.php';

use App\Account\CredentialMessage;

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Ditolak: pengujian ini hanya boleh berjalan pada database berakhiran _test.\n");
    exit(2);
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};

/** Klien HTTP sederhana yang juga menyimpan header respons. */
final class KlienKredensial
{
    private string $jar;

    public function __construct(private string $baseUrl, string $label)
    {
        $this->jar = (string) tempnam(sys_get_temp_dir(), 'kredensial-' . $label . '-');
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
            CURLOPT_TIMEOUT => 20,
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
        $location = null;
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $cocok) === 1) {
            $location = trim($cocok[1]);
        }

        return ['status' => $status, 'body' => substr($raw, $headerSize), 'headers' => $headers, 'location' => $location];
    }

    public function csrf(string $path): string
    {
        $response = $this->request($path);
        if (preg_match('/name="_csrf" value="([^"]+)"/', $response['body'], $cocok) === 1) {
            return $cocok[1];
        }
        throw new RuntimeException('Token CSRF tidak ditemukan pada ' . $path . ' (status ' . $response['status'] . ')');
    }

    public function isiJar(): string
    {
        return is_file($this->jar) ? (string) file_get_contents($this->jar) : '';
    }
}

$db = app_db();
$port = (int) (getenv('KREDENSIAL_WEB_PORT') ?: 8937);
$baseUrl = 'http://127.0.0.1:' . $port;
$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$kecil = strtolower($suffix);
$sandiAdmin = 'UjiKredensial123Aa';

$dibuat = ['users' => [], 'guru' => [], 'pengurus' => [], 'wali' => [], 'santri' => []];
$server = null;

$satu = static function (string $sql) use ($db): array {
    $rs = $db->query($sql);

    return ($rs && $row = $rs->fetch_assoc()) ? $row : [];
};
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

/** Mengambil password sementara yang TERLIHAT pada panel. */
$sandiDariPanel = static function (string $body): ?string {
    if (preg_match('#class="user-select-all ah-kredensial__sandi">([^<]+)</code>#', $body, $cocok) === 1) {
        return html_entity_decode($cocok[1], ENT_QUOTES, 'UTF-8');
    }

    return null;
};
/** Mengambil isi kotak pesan siap salin sebagai teks biasa. */
$teksDariPanel = static function (string $body): ?string {
    if (preg_match('#<pre class="ah-kredensial__teks[^"]*" id="ah-kredensial-teks"[^>]*>(.*?)</pre>#s', $body, $cocok) === 1) {
        return html_entity_decode($cocok[1], ENT_QUOTES, 'UTF-8');
    }

    return null;
};

try {
    // --------------------------------------------------------- fixture admin
    $adminUji = $exec(
        'INSERT INTO users (name, username, password, is_active, force_password_change, created_at, updated_at)
         VALUES (?, ?, ?, 1, 0, NOW(), NOW())',
        ['Admin Uji Kredensial ' . $suffix, 'kw.admin.' . $kecil, password_hash($sandiAdmin, PASSWORD_DEFAULT)]
    );
    $dibuat['users'][] = $adminUji;
    $exec(
        "INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = 'admin'",
        [$adminUji, $adminUji]
    );

    $_SESSION = ['user_id' => $adminUji];
    $master = master_data_service();

    $guruId = (int) $master->saveGuru(['nip' => 'KW' . $suffix, 'nama_guru' => 'Guru Web ' . $suffix, 'no_hp' => '']);
    $dibuat['guru'][] = $guruId;
    $pengurusId = (int) $master->savePengurus([
        'nama' => 'Pengurus Web ' . $suffix, 'nomor_identitas' => 'PW' . $suffix,
        'no_hp' => '081200000011', 'jabatan' => 'Keamanan',
    ]);
    $dibuat['pengurus'][] = $pengurusId;
    $santriId = (int) $master->saveSantri([
        'nis' => 'WS' . $suffix, 'nama_santri' => 'Santri Web ' . $suffix,
        'jenis_kelamin' => 'P', 'tgl_lahir' => '2012-02-02',
        'wali' => ['Ibu' => ['mode' => 'baru', 'nama' => 'Wali Web ' . $suffix, 'no_hp' => '081200000012', 'alamat' => 'Jl Uji']],
    ]);
    $dibuat['santri'][] = $santriId;
    $waliId = (int) ($satu('SELECT wali_id FROM santri_wali WHERE santri_id = ' . $santriId . ' AND archived_at IS NULL LIMIT 1')['wali_id'] ?? 0);
    $dibuat['wali'][] = $waliId;

    // --------------------------------------------------------- server lokal
    $descriptors = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $pipes = [];
    $server = proc_open(
        escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root),
        $descriptors,
        $pipes,
        $root
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

    $klien = new KlienKredensial($baseUrl, 'admin');
    $token = $klien->csrf('/portal/index.php');
    $masuk = $klien->request('/admin/cek_login.php', [
        '_csrf' => $token, 'username' => 'kw.admin.' . $kecil, 'password' => $sandiAdmin,
    ]);
    $assert($masuk['status'] === 302, 'PW-0 admin uji berhasil masuk [' . $masuk['status'] . ']');

    $urlDikunjungi = [];
    $kunjungi = static function (string $path, ?array $post = null) use ($klien, &$urlDikunjungi): array {
        $urlDikunjungi[] = $path;
        $respons = $klien->request($path, $post);
        if ($respons['location'] !== null) {
            $urlDikunjungi[] = $respons['location'];
        }

        return $respons;
    };

    $jalur = [
        'guru' => [
            'post' => ['action' => 'create_guru', 'guru_id' => (string) $guruId, 'name' => 'Akun Guru Web ' . $suffix,
                'username' => 'kw.guru.' . $kecil, 'email' => 'kw.guru.' . $kecil . '@contoh.test', 'phone' => ''],
            'label' => 'guru',
        ],
        'pengurus' => [
            'post' => ['action' => 'create', 'kind' => 'pengurus', 'pengurus_id' => (string) $pengurusId,
                'name' => 'Akun Pengurus Web ' . $suffix, 'username' => 'kw.pengurus.' . $kecil,
                'email' => '', 'phone' => ''],
            'label' => 'pengurus',
        ],
        'orang_tua' => [
            'post' => ['action' => 'create', 'kind' => 'orang_tua', 'wali_id' => (string) $waliId,
                'name' => 'Akun Wali Web ' . $suffix, 'username' => 'kw.wali.' . $kecil,
                'email' => 'kw.wali.' . $kecil . '@contoh.test', 'phone' => ''],
            'label' => 'orang tua',
        ],
    ];

    $sandiTerlihat = [];
    foreach ($jalur as $kunci => $rencana) {
        echo PHP_EOL . '=== PW: jalur ' . $rencana['label'] . ' ===' . PHP_EOL;

        $tokenForm = $klien->csrf('/admin/admin_akun.php');
        $kirim = $kunjungi('/admin/admin_akun.php', ['_csrf' => $tokenForm] + $rencana['post']);
        $assert($kirim['status'] === 302, 'PW-1a POST pembuatan akun ' . $rencana['label'] . ' menjawab redirect [' . $kirim['status'] . ']');

        $halaman = $kunjungi('/admin/admin_akun.php');
        $sandi = $sandiDariPanel($halaman['body']);
        $sandiTerlihat[$kunci] = $sandi;

        $assert(str_contains($halaman['body'], 'id="ah-kredensial-teks"'),
            'PW-1b panel pesan kredensial tampil setelah akun ' . $rencana['label'] . ' dibuat');
        $assert(str_contains($halaman['body'], 'Salin pesan')
            && str_contains($halaman['body'], 'id="ah-kredensial-status"'),
            'PW-1c tombol salin dan area status tersedia');
        $assert(is_string($sandi) && $sandi !== '', 'PW-1d password sementara tampil pada panel');

        $assert(preg_match('/^Cache-Control:.*private/mi', $halaman['headers']) === 1
            && preg_match('/^Cache-Control:.*no-store/mi', $halaman['headers']) === 1,
            'PW-2 respons berisi password memakai Cache-Control private, no-store');

        $barisAkun = $satu("SELECT id, password, username FROM users WHERE username = '"
            . $db->real_escape_string((string) $rencana['post']['username']) . "' LIMIT 1");
        if ($barisAkun !== []) {
            $dibuat['users'][] = (int) $barisAkun['id'];
        }
        $assert($barisAkun !== [] && is_string($sandi) && password_verify($sandi, (string) $barisAkun['password']),
            'PW-3a password yang tampil cocok dengan hash akun yang tersimpan');
        $assert($barisAkun !== [] && (string) $barisAkun['password'] !== $sandi,
            'PW-3b basis data tetap hanya menyimpan hash');

        $teks = $teksDariPanel($halaman['body']);
        $bakuHarapan = CredentialMessage::text([
            'name' => (string) $rencana['post']['name'],
            'username' => (string) $rencana['post']['username'],
            'password' => (string) $sandi,
            'portal_url' => CredentialMessage::PORTAL_URL,
        ]);
        $assert($teks === $bakuHarapan, 'PW-9 isi kotak pesan sama persis dengan teks baku');
        $assert(is_string($teks) && !str_contains($teks, '<') && !str_contains($teks, '&amp;'),
            'PW-9b isi yang disalin berupa teks biasa tanpa markup');

        // Muat ulang: panel dan password harus hilang.
        $ulang = $kunjungi('/admin/admin_akun.php');
        $assert(!str_contains($ulang['body'], 'id="ah-kredensial-teks"'),
            'PW-4a memuat ulang halaman menghilangkan panel kredensial');
        $assert(is_string($sandi) && !str_contains($ulang['body'], $sandi),
            'PW-4b password tidak muncul lagi pada muat ulang');

        // Membuka lagi lewat alamat yang sama (meniru tombol kembali) tetap bersih.
        $kembali = $kunjungi('/admin/admin_akun.php?role=' . ($kunci === 'guru' ? 'guru' : $kunci));
        $assert(is_string($sandi) && !str_contains($kembali['body'], $sandi),
            'PW-4c membuka kembali halaman tidak menyajikan password dari sesi');

        $assert(is_string($sandi) && !str_contains($klien->isiJar(), $sandi),
            'PW-5a password tidak pernah ditulis ke cookie');
        $assert($kirim['location'] === null || (is_string($sandi) && !str_contains((string) $kirim['location'], $sandi)),
            'PW-5b header Location tidak membawa password');
    }

    echo PHP_EOL . '=== PW-6 s.d. PW-10. Kegagalan, retry, dan daftar akun ===' . PHP_EOL;

    $assert(count(array_filter($sandiTerlihat)) === 3,
        'PW-6 ketiga jalur (guru, pengurus, orang tua) menampilkan panel yang sama');
    $assert(count(array_unique(array_filter($sandiTerlihat))) === 3,
        'PW-6b setiap akun memperoleh password sementara yang berbeda');

    // Gagal: username sudah dipakai.
    $tokenGagal = $klien->csrf('/admin/admin_akun.php');
    $gagal = $kunjungi('/admin/admin_akun.php', [
        '_csrf' => $tokenGagal, 'action' => 'create_guru', 'guru_id' => (string) $guruId,
        'name' => 'Akun Bentrok ' . $suffix, 'username' => 'kw.guru.' . $kecil, 'email' => '', 'phone' => '',
    ]);
    $assert($gagal['status'] === 302, 'PW-7a pembuatan akun yang gagal tetap menjawab redirect');
    $setelahGagal = $kunjungi('/admin/admin_akun.php');
    $assert(!str_contains($setelahGagal['body'], 'id="ah-kredensial-teks"'),
        'PW-7b kegagalan tidak menampilkan panel kredensial palsu');
    $assert(str_contains($setelahGagal['body'], 'ah-note--danger'),
        'PW-7c kegagalan dijelaskan dengan pesan galat');

    $jumlahGuru = (int) ($satu("SELECT COUNT(*) AS c FROM users WHERE username = 'kw.guru." . $kecil . "'")['c'] ?? 0);
    $assert($jumlahGuru === 1, 'PW-8 retry tidak membuat akun kedua dengan username yang sama');

    // Daftar akun biasa tidak boleh memuat password mana pun.
    $daftar = $kunjungi('/admin/admin_akun.php?q=' . rawurlencode('kw.'));
    $bocorDiDaftar = false;
    foreach (array_filter($sandiTerlihat) as $sandi) {
        if (str_contains($daftar['body'], (string) $sandi)) {
            $bocorDiDaftar = true;
        }
    }
    $assert(!$bocorDiDaftar, 'PW-10a daftar akun tidak pernah menampilkan password sementara');

    $bocorDiUrl = false;
    foreach ($urlDikunjungi as $url) {
        foreach (array_filter($sandiTerlihat) as $sandi) {
            if (str_contains($url, (string) $sandi)) {
                $bocorDiUrl = true;
            }
        }
    }
    $assert(!$bocorDiUrl, 'PW-10b tidak ada password pada URL maupun header Location sepanjang alur ('
        . count($urlDikunjungi) . ' alamat diperiksa)');
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $hapus = static function (string $sql) use ($db): void {
        @$db->query($sql);
    };
    if ($dibuat['users'] !== []) {
        $daftarId = implode(',', array_map('intval', array_unique($dibuat['users'])));
        $hapus("DELETE FROM audit_logs WHERE entity_type = 'user' AND entity_id IN (" . $daftarId . ')');
        $hapus('DELETE FROM audit_logs WHERE actor_id IN (' . $daftarId . ')');
        $hapus('DELETE FROM user_roles WHERE user_id IN (' . $daftarId . ')');
        $hapus('DELETE FROM users WHERE id IN (' . $daftarId . ')');
    }
    if ($dibuat['santri'] !== []) {
        $daftarId = implode(',', array_map('intval', $dibuat['santri']));
        $hapus('DELETE FROM santri_wali WHERE santri_id IN (' . $daftarId . ')');
        $hapus('DELETE FROM santri WHERE id IN (' . $daftarId . ')');
    }
    if ($dibuat['wali'] !== []) {
        $hapus('DELETE FROM wali WHERE id IN (' . implode(',', array_map('intval', $dibuat['wali'])) . ')');
    }
    if ($dibuat['pengurus'] !== []) {
        $hapus('DELETE FROM pengurus WHERE id IN (' . implode(',', array_map('intval', $dibuat['pengurus'])) . ')');
    }
    if ($dibuat['guru'] !== []) {
        $hapus('DELETE FROM guru WHERE id IN (' . implode(',', array_map('intval', $dibuat['guru'])) . ')');
    }
}

echo PHP_EOL;
if ($failures === []) {
    echo 'SELURUH SMOKE TEST WEB PESAN KREDENSIAL LULUS.' . PHP_EOL;
    exit(0);
}
echo 'GAGAL: ' . count($failures) . ' pemeriksaan.' . PHP_EOL;
foreach ($failures as $failure) {
    echo ' - ' . $failure . PHP_EOL;
}
exit(1);
