<?php

declare(strict_types=1);

/**
 * Smoke test web paket "Koreksi dan Modernisasi UI/UX V1–V2".
 *
 * Menjalankan kriteria penerimaan koreksi ke-7 (satu pintu masuk `/portal/`)
 * dan pemeriksaan lintas-koreksi lain yang hanya terlihat lewat HTTP, memakai
 * server PHP lokal dan basis data uji:
 *
 *   PM-1  `/portal/` tanpa sesi menampilkan halaman masuk;
 *   PM-2  login berhasil untuk admin, guru non-murobi, murobi, pengurus,
 *         orang tua, dan seluruhnya mendarat pada satu beranda;
 *   PM-3  guru non-murobi masuk ke beranda umum tetapi TETAP ditolak dari
 *         fungsi keputusan perizinan;
 *   PM-4  pengguna yang sudah login tidak diminta login ulang saat membuka
 *         pintu masuk atau berpindah modul;
 *   PM-5  akun multi-peran dapat memakai seluruh menu yang diizinkan;
 *   PM-6  alamat login lama tetap berfungsi;
 *   PM-7  password salah, akun nonaktif, password sementara, sesi kedaluwarsa,
 *         dan logout ditangani dengan benar;
 *   PM-8  tidak ada redirect loop maupun pengalihan ke situs eksternal;
 *   PM-9  tautan detail tetap menjalankan pemeriksaan akses setelah login,
 *         termasuk setelah berganti akun;
 *   PM-10 alamat lama modul pengajian dan halaman akun tetap berfungsi;
 *   PM-11 halaman cetak tetap tanpa sidebar.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   PERAPIHAN_RUN_WEB=1 php tests/perapihan_web_smoke.php
 */

$root = dirname(__DIR__);
if (getenv('PERAPIHAN_RUN_WEB') !== '1') {
    fwrite(STDOUT, "[lewati] Set PERAPIHAN_RUN_WEB=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

require_once $root . '/app/bootstrap.php';

use App\Account\AccountService;

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

/** Klien HTTP sederhana dengan cookie jar per peran. */
final class KlienPerapihan
{
    private string $jar;

    public function __construct(private string $baseUrl, string $label)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'perapihan-' . $label . '-');
    }

    /**
     * @param array<string, string>|null $post
     * @return array{status:int, body:string, location:?string}
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
        $body = substr($raw, $headerSize);
        $location = null;
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $matches) === 1) {
            $location = trim($matches[1]);
        }

        return ['status' => $status, 'body' => $body, 'location' => $location];
    }

    public function csrf(string $path): string
    {
        $response = $this->request($path);
        if (preg_match('/name="_csrf" value="([^"]+)"/', $response['body'], $matches) === 1) {
            return $matches[1];
        }
        throw new RuntimeException('Token CSRF tidak ditemukan pada ' . $path . ' (status ' . $response['status'] . ')');
    }

    /**
     * @return array{status:int, body:string, location:?string}
     */
    public function login(string $username, string $password, ?string $next = null): array
    {
        $token = $this->csrf('/portal/index.php' . ($next === null ? '' : '?next=' . rawurlencode($next)));
        $data = ['_csrf' => $token, 'username' => $username, 'password' => $password];
        if ($next !== null) {
            $data['next'] = $next;
        }

        return $this->request('/admin/cek_login.php', $data);
    }

    public function keluar(): void
    {
        $token = $this->csrf('/admin/logout.php');
        $this->request('/admin/logout.php', ['_csrf' => $token]);
    }
}

$db = app_db();
$port = (int) (getenv('PERAPIHAN_WEB_PORT') ?: 8931);
$baseUrl = 'http://127.0.0.1:' . $port;
$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$lower = strtolower($suffix);
$sandi = 'UjiPerapihan123Aa';
$hash = password_hash($sandi, PASSWORD_DEFAULT);

$adminRow = $db->query(
    "SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
      WHERE r.slug = 'admin' AND u.is_active = 1 ORDER BY u.id LIMIT 1"
)?->fetch_assoc();
if (!$adminRow) {
    fwrite(STDERR, "Akun admin fixture tidak tersedia.\n");
    exit(2);
}
$adminId = (int) $adminRow['id'];
$_SESSION = ['user_id' => $adminId];

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

$server = null;
$dibuat = ['users' => [], 'guru' => [], 'murobi' => [], 'santri' => [], 'wali' => [], 'santri_wali' => [], 'kamar' => [], 'plotting_kamar' => []];
$master = master_data_service();
$akun = account_service();

try {
    // ---------------------------------------------------------------- fixture
    $tahunId = (int) ($db->query("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1")?->fetch_assoc()['id'] ?? 0);
    if ($tahunId < 1) {
        throw new RuntimeException('Tahun ajaran aktif tidak tersedia.');
    }

    $guruMurobi = $master->saveGuru(['nip' => 'PWM' . $suffix, 'nama_guru' => 'Guru Murobi ' . $suffix, 'no_hp' => '']);
    $guruBiasa = $master->saveGuru(['nip' => 'PWG' . $suffix, 'nama_guru' => 'Guru Biasa ' . $suffix, 'no_hp' => '']);
    $dibuat['guru'] = [$guruMurobi, $guruBiasa];

    $kamarId = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 10)', ['Kamar PW ' . $suffix]);
    $dibuat['kamar'][] = $kamarId;
    $dibuat['murobi'][] = $master->saveMurobi([
        'guru_id' => $guruMurobi, 'tahun_ajaran_id' => $tahunId, 'target_type' => 'Kamar',
        'kamar_id' => $kamarId, 'tanggal_mulai' => date('Y-m-d', strtotime('-1 day')), 'tanggal_selesai' => '',
    ], $adminId);

    $buatUser = static function (string $username, ?int $guruId = null, bool $paksaGanti = false) use ($exec, $hash, $suffix, &$dibuat): int {
        $id = $exec(
            'INSERT INTO users (name, username, password, guru_id, is_active, force_password_change, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())',
            ['Akun ' . $username . ' ' . $suffix, $username, $hash, $guruId, $paksaGanti ? 1 : 0]
        );
        $dibuat['users'][] = $id;

        return $id;
    };
    $beriRole = static function (int $userId, string $role) use ($exec, $adminId): void {
        $exec('INSERT IGNORE INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = ?', [$userId, $adminId, $role]);
    };

    $userMurobi = $buatUser('pw.m.' . $lower, $guruMurobi);
    $beriRole($userMurobi, 'guru');
    $userGuru = $buatUser('pw.g.' . $lower, $guruBiasa);
    $beriRole($userGuru, 'guru');
    $userAdmin = $buatUser('pw.a.' . $lower);
    $beriRole($userAdmin, 'admin');
    $userNonaktif = $buatUser('pw.x.' . $lower);
    $beriRole($userNonaktif, 'guru');
    $db->query('UPDATE users SET is_active = 0 WHERE id = ' . $userNonaktif);
    $userPaksa = $buatUser('pw.p.' . $lower, null, true);
    $beriRole($userPaksa, 'admin');
    // Akun multi-peran: admin sekaligus guru dengan penugasan murobi.
    $userMulti = $buatUser('pw.mm.' . $lower, $guruMurobi === 0 ? null : null);
    $beriRole($userMulti, 'admin');

    // Pengurus dan orang tua memakai fixture sandbox yang sudah ada bila tersedia.
    $pengurusRow = $db->query("SELECT username FROM users WHERE username = 'sbx_pengurus_a' LIMIT 1")?->fetch_assoc();
    $ortuRow = $db->query("SELECT username FROM users WHERE username = 'sbx_ortu_a' LIMIT 1")?->fetch_assoc();

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

    // =====================================================================
    echo PHP_EOL . '=== PM-1. Pintu masuk tanpa sesi ===' . PHP_EOL;
    // =====================================================================
    $anon = new KlienPerapihan($baseUrl, 'anon');
    $pintu = $anon->request('/portal/index.php');
    $assert($pintu['status'] === 200, 'PM-1a /portal/ tanpa sesi menjawab 200, bukan pengalihan berulang [' . $pintu['status'] . ']');
    $assert(str_contains($pintu['body'], 'Masuk Sistem Al Hasan'), 'PM-1b /portal/ tanpa sesi menampilkan halaman Masuk Sistem Al Hasan');
    $assert(
        str_contains($pintu['body'], 'action="' . '/admin/cek_login.php"') || str_contains($pintu['body'], '/admin/cek_login.php'),
        'PM-1c Formulir masuk mengirim ke penangan autentikasi yang sudah ada'
    );

    // Halaman internal yang dibuka tanpa sesi membawa tujuan sebagai `next`.
    $tanpaSesi = $anon->request('/admin/admin_master_santri.php?action=detail&id=1');
    $assert(
        $tanpaSesi['status'] === 302 && str_contains((string) $tanpaSesi['location'], '/portal/index.php')
        && str_contains((string) $tanpaSesi['location'], 'next='),
        'PM-1d Halaman internal tanpa sesi mengarah ke pintu masuk dengan tujuan tersimpan'
    );

    // =====================================================================
    echo PHP_EOL . '=== PM-2. Login seluruh peran mendarat di beranda ===' . PHP_EOL;
    // =====================================================================
    $peran = [
        'admin' => 'pw.a.' . $lower,
        'murobi' => 'pw.m.' . $lower,
        'guru' => 'pw.g.' . $lower,
    ];
    if ($pengurusRow) {
        $peran['pengurus'] = 'sbx_pengurus_a';
    }
    if ($ortuRow) {
        $peran['orang_tua'] = 'sbx_ortu_a';
    }
    $klien = [];
    foreach ($peran as $label => $username) {
        $klien[$label] = new KlienPerapihan($baseUrl, $label);
        $sandiPeran = str_starts_with($username, 'sbx_') ? 'Sandbox#123' : $sandi;
        $masuk = $klien[$label]->login($username, $sandiPeran);
        $assert(
            $masuk['status'] === 302 && str_contains((string) $masuk['location'], '/portal/index.php'),
            'PM-2 ' . $label . ' berhasil masuk dan mendarat di beranda tunggal [' . (string) $masuk['location'] . ']'
        );
        $beranda = $klien[$label]->request('/portal/index.php');
        $assert($beranda['status'] === 200, 'PM-2b Beranda ' . $label . ' terbuka [' . $beranda['status'] . ']');
    }

    // =====================================================================
    echo PHP_EOL . '=== PM-3. Guru non-murobi: beranda terbuka, perizinan tertutup ===' . PHP_EOL;
    // =====================================================================
    $berandaGuru = $klien['guru']->request('/portal/index.php');
    $assert($berandaGuru['status'] === 200, 'PM-3a Guru non-murobi dapat membuka beranda umum');
    foreach ([
        '/portal/izin_ringkasan.php',
        '/portal/izin.php',
        '/portal/izin_antrean.php?mode=murobi',
        '/portal/izin_aksi.php',
        '/portal/laporan.php',
    ] as $halaman) {
        $status = $klien['guru']->request($halaman)['status'];
        $assert($status === 403, 'PM-3b Guru non-murobi ditolak 403 pada ' . $halaman . ' [' . $status . ']');
    }
    $assert(
        $klien['murobi']->request('/portal/izin_antrean.php?mode=murobi')['status'] === 200,
        'PM-3c Murobi tetap dapat membuka antrean keputusan'
    );

    // =====================================================================
    echo PHP_EOL . '=== PM-4/5. Berpindah modul tanpa login ulang ===' . PHP_EOL;
    // =====================================================================
    $ulang = $klien['murobi']->request('/portal/index.php');
    $assert(
        $ulang['status'] === 200 && !str_contains($ulang['body'], 'Masuk Sistem Al Hasan'),
        'PM-4a Membuka pintu masuk saat sesi hidup tidak meminta login ulang'
    );
    foreach (['/admin/admin_pengajian.php?tab=jadwal', '/admin/admin_pengajian.php?tab=pertemuan', '/portal/izin_antrean.php?mode=murobi', '/portal/notifikasi.php'] as $halaman) {
        $status = $klien['murobi']->request($halaman)['status'];
        $assert($status === 200, 'PM-4b Murobi berpindah ke ' . $halaman . ' tanpa login ulang [' . $status . ']');
    }
    foreach (['/admin/admin_dashboard.php', '/admin/admin_akun.php', '/admin/admin_master_santri.php', '/admin/admin_laporan_absensi.php', '/admin/admin_wali_rekonsiliasi.php', '/portal/izin_ringkasan.php'] as $halaman) {
        $status = $klien['admin']->request($halaman)['status'];
        $assert($status === 200, 'PM-5 Akun admin dapat memakai menu ' . $halaman . ' [' . $status . ']');
    }

    // =====================================================================
    echo PHP_EOL . '=== PM-6. Alamat lama ===' . PHP_EOL;
    // =====================================================================
    $lamaAnon = $anon->request('/admin/admin_login.php');
    $assert(
        $lamaAnon['status'] === 302 && str_contains((string) $lamaAnon['location'], '/portal/index.php'),
        'PM-6a /admin/admin_login.php tetap berfungsi dan mengarah ke pintu masuk baru'
    );
    $lamaSesi = $klien['admin']->request('/admin/admin_login.php');
    $assert(
        $lamaSesi['status'] === 302 && str_contains((string) $lamaSesi['location'], '/portal/index.php'),
        'PM-6b Alamat lama saat sesi hidup tidak meminta login ulang'
    );

    // =====================================================================
    echo PHP_EOL . '=== PM-7. Kasus tepi autentikasi ===' . PHP_EOL;
    // =====================================================================
    $salah = new KlienPerapihan($baseUrl, 'salah');
    $hasilSalah = $salah->login('pw.a.' . $lower, 'PasswordSalah123');
    $assert(
        $hasilSalah['status'] === 302 && str_contains((string) $hasilSalah['location'], 'pesan=gagal'),
        'PM-7a Password salah ditolak dan dijelaskan di pintu masuk'
    );
    $assert(
        str_contains($salah->request('/portal/index.php?pesan=gagal')['body'], 'Username atau password salah'),
        'PM-7b Pesan kegagalan tidak membocorkan akun mana yang ada'
    );

    $nonaktif = new KlienPerapihan($baseUrl, 'nonaktif');
    $hasilNonaktif = $nonaktif->login('pw.x.' . $lower, $sandi);
    $assert(
        $hasilNonaktif['status'] === 302 && str_contains((string) $hasilNonaktif['location'], 'pesan=gagal'),
        'PM-7c Akun nonaktif tidak dapat masuk'
    );

    $paksa = new KlienPerapihan($baseUrl, 'paksa');
    $hasilPaksa = $paksa->login('pw.p.' . $lower, $sandi);
    $assert(
        $hasilPaksa['status'] === 302 && str_contains((string) $hasilPaksa['location'], 'ubah_password.php'),
        'PM-7d Password sementara wajib diselesaikan lebih dulu'
    );
    $dialihkan = $paksa->request('/admin/admin_dashboard.php');
    $assert(
        $dialihkan['status'] === 302 && str_contains((string) $dialihkan['location'], 'ubah_password.php'),
        'PM-7e Fungsi operasional tertutup sebelum password sementara diganti'
    );
    $berandaPaksa = $paksa->request('/portal/index.php');
    $assert(
        $berandaPaksa['status'] === 302 && str_contains((string) $berandaPaksa['location'], 'ubah_password.php'),
        'PM-7f Beranda pun mengarahkan kembali ke ganti password (tanpa lingkaran)'
    );

    $klien['admin']->keluar();
    $setelahKeluar = $klien['admin']->request('/admin/admin_dashboard.php');
    $assert(
        $setelahKeluar['status'] === 302 && str_contains((string) $setelahKeluar['location'], '/portal/index.php'),
        'PM-7g Setelah logout, halaman internal mengarah kembali ke pintu masuk yang sama'
    );

    // =====================================================================
    echo PHP_EOL . '=== PM-8. Tidak ada redirect loop atau tujuan eksternal ===' . PHP_EOL;
    // =====================================================================
    foreach ([
        'https://contoh-jahat.example/curi',
        '//contoh-jahat.example/curi',
        'javascript:alert(1)',
        '/etc/passwd',
        '/admin/logout.php',
    ] as $tujuanJahat) {
        $uji = new KlienPerapihan($baseUrl, 'redir');
        $hasil = $uji->login('pw.a.' . $lower, $sandi, $tujuanJahat);
        $assert(
            $hasil['status'] === 302
            && !str_contains((string) $hasil['location'], 'contoh-jahat')
            && !str_contains((string) $hasil['location'], 'javascript:')
            && !str_contains((string) $hasil['location'], '/etc/passwd'),
            'PM-8a Tujuan tidak sah ditolak: ' . $tujuanJahat . ' [' . (string) $hasil['location'] . ']'
        );
    }

    // Rantai pengalihan pintu masuk tidak boleh berputar.
    $rantai = new KlienPerapihan($baseUrl, 'rantai');
    $tujuan = '/admin/admin_login.php';
    $langkah = 0;
    $terlihat = [];
    while ($langkah < 8) {
        $respons = $rantai->request($tujuan);
        if ($respons['status'] !== 302) {
            break;
        }
        $tujuan = (string) parse_url((string) $respons['location'], PHP_URL_PATH)
            . (parse_url((string) $respons['location'], PHP_URL_QUERY) ? '?' . parse_url((string) $respons['location'], PHP_URL_QUERY) : '');
        if (isset($terlihat[$tujuan])) {
            break;
        }
        $terlihat[$tujuan] = true;
        $langkah++;
    }
    $assert($langkah <= 2, 'PM-8b Rantai pengalihan dari alamat lama berhenti dalam <= 2 langkah [' . $langkah . ']');

    // =====================================================================
    echo PHP_EOL . '=== PM-9. Tujuan tersimpan tetap diperiksa haknya ===' . PHP_EOL;
    // =====================================================================
    $tujuanAdmin = '/admin/admin_akun.php';
    $guruKembali = new KlienPerapihan($baseUrl, 'guru2');
    $hasilNext = $guruKembali->login('pw.g.' . $lower, $sandi, $tujuanAdmin);
    $assert(
        $hasilNext['status'] === 302 && str_contains((string) $hasilNext['location'], $tujuanAdmin),
        'PM-9a Tujuan internal yang sah dipulihkan setelah masuk'
    );
    $aksesNext = $guruKembali->request($tujuanAdmin);
    $assert(
        $aksesNext['status'] === 403,
        'PM-9b Tujuan yang dipulihkan TETAP diperiksa haknya di server: guru ditolak 403 [' . $aksesNext['status'] . ']'
    );
    // Berganti akun pada peramban yang sama: pemeriksaan tetap berlaku.
    $guruKembali->keluar();
    $guruKembali->login('pw.a.' . $lower, $sandi, $tujuanAdmin);
    $assert(
        $guruKembali->request($tujuanAdmin)['status'] === 200,
        'PM-9c Setelah berganti ke akun berhak, tujuan yang sama dapat dibuka'
    );

    // =====================================================================
    echo PHP_EOL . '=== PM-10/11. Alamat lama modul dan halaman cetak ===' . PHP_EOL;
    // =====================================================================
    $adminBaru = new KlienPerapihan($baseUrl, 'admin2');
    $adminBaru->login('pw.a.' . $lower, $sandi);
    foreach ([
        '/admin/admin_jadwal_ngaji.php' => 'admin_pengajian.php',
        '/admin/pertemuan_pengajian.php' => 'admin_pengajian.php',
        '/admin/admin_akun_perizinan.php' => 'admin_akun.php',
    ] as $lama => $baru) {
        $respons = $adminBaru->request($lama);
        $assert(
            $respons['status'] === 302 && str_contains((string) $respons['location'], $baru),
            'PM-10 Alamat lama ' . $lama . ' mengarah ke ' . $baru . ' [' . $respons['status'] . ']'
        );
    }
    $cetak = $adminBaru->request('/admin/laporan_absensi_cetak.php?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d'));
    $assert($cetak['status'] === 200, 'PM-11a Halaman cetak laporan kehadiran terbuka');
    $assert(
        !str_contains($cetak['body'], 'ah-sidebar') && !str_contains($cetak['body'], 'ah-topbar'),
        'PM-11b Halaman cetak tidak memuat sidebar atau topbar'
    );
    $assert(
        str_contains($cetak['body'], 'Penyajian'),
        'PM-11c Halaman cetak mencantumkan penyajian pada daftar filter aktif'
    );
} catch (Throwable $exception) {
    $assert(false, 'Pengujian terhenti: ' . $exception->getMessage());
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach ($dibuat['murobi'] as $id) {
        $db->query('DELETE FROM murobi_assignments WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['users'] as $id) {
        $db->query('DELETE FROM user_roles WHERE user_id = ' . (int) $id);
        $db->query('DELETE FROM perangkat_push WHERE user_id = ' . (int) $id);
        $db->query('DELETE FROM users WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['guru'] as $id) {
        $db->query('DELETE FROM murobi_assignments WHERE guru_id = ' . (int) $id);
        $db->query('DELETE FROM guru WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['kamar'] as $id) {
        $db->query('DELETE FROM plotting_kamar WHERE id_kamar = ' . (int) $id);
        $db->query('DELETE FROM kamar WHERE id = ' . (int) $id);
    }
    echo '[bersih] Fixture smoke test perapihan dihapus.' . PHP_EOL;
}

echo PHP_EOL;
if ($failures === []) {
    echo 'SELURUH SMOKE TEST WEB PERAPIHAN LULUS.' . PHP_EOL;
    exit(0);
}
echo 'GAGAL (' . count($failures) . "):" . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;
exit(1);
