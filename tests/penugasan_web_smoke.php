<?php

declare(strict_types=1);

/**
 * Smoke test web "Fondasi Penugasan dan Hak Akses Lintas PRD V3–V6"
 * (keputusan pengguna 7 September 2026) lewat HTTP sungguhan.
 *
 * Yang hanya terlihat lewat HTTP dan karena itu diuji di sini:
 *
 *   FW-1  anonim diarahkan ke pintu masuk; admin dapat membuka seluruh tab
 *         Pusat Penugasan beserta menu sidebar-nya;
 *   FW-2  guru, pengurus, dan orang tua DITOLAK (403) membuka/mengirim ke
 *         Pusat Penugasan — bukan sekadar tidak melihat menu;
 *   FW-3  CSRF hilang/palsu ditolak 419 tanpa mengubah data;
 *   FW-4  parameter aksi lewat GET tidak memutasi apa pun;
 *   FW-5  admin membuat mata pelajaran dan penugasan tiap jenis lewat formulir,
 *         daftar menampilkan status, cakupan, masa berlaku, dan capability efektif;
 *   FW-6  duplikat/tumpang tindih lewat formulir ditolak dengan pesan;
 *   FW-7  ubah, akhiri, nonaktifkan, aktifkan lewat formulir tercermin di data;
 *   FW-8  halaman lama admin_murobi.php dan admin_pembimbing.php tetap
 *         berfungsi (GET 200, POST lama menyimpan) dan menaut ke pusat;
 *   FW-9  halaman Akun & Hak Akses menampilkan ringkasan penugasan dan tautan;
 *   FW-10 API: login/profil guru dan pengurus memuat struktur `capabilities`
 *         lama yang identik sebelum dan sesudah penugasan, plus field aditif;
 *   FW-11 login web keempat peran tetap berfungsi;
 *   FW-12 keluaran di-escape (nama mata pelajaran berisi tag HTML tidak dieksekusi);
 *   FW-13 IDOR lewat formulir (id jenis lain / id orang lain) ditolak tanpa perubahan.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   PENUGASAN_RUN_WEB=1 php tests/penugasan_web_smoke.php
 */

$root = dirname(__DIR__);
if (getenv('PENUGASAN_RUN_WEB') !== '1') {
    fwrite(STDOUT, "[lewati] Set PENUGASAN_RUN_WEB=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

require_once $root . '/app/bootstrap.php';

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Ditolak: pengujian ini hanya boleh berjalan pada database berakhiran _test.\n");
    exit(2);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "Ekstensi curl dibutuhkan.\n");
    exit(2);
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};

final class KlienPenugasan
{
    private string $jar;

    public function __construct(private string $baseUrl, string $label)
    {
        $this->jar = (string) tempnam(sys_get_temp_dir(), 'penugasan-' . $label . '-');
    }

    /**
     * @param array<string, string>|null $post
     * @return array{status:int, body:string, headers:string, location:?string, json:mixed}
     */
    public function request(string $path, ?array $post = null, array $headers = [], ?string $jsonBody = null): array
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
        if ($jsonBody !== null) {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, $jsonBody);
            $headers[] = 'Content-Type: application/json';
        }
        if ($headers !== []) {
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        }
        $raw = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);
        $head = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $location = null;
        if (preg_match('/^Location:\s*(.+)$/mi', $head, $cocok) === 1) {
            $location = trim($cocok[1]);
        }

        return ['status' => $status, 'body' => $body, 'headers' => $head, 'location' => $location, 'json' => json_decode($body, true)];
    }

    public function csrf(string $path): string
    {
        $response = $this->request($path);
        if (preg_match('/name="_csrf" value="([^"]+)"/', $response['body'], $cocok) === 1) {
            return $cocok[1];
        }
        if (preg_match('/window\.ALHASAN_CSRF = "([^"]+)"/', $response['body'], $cocok) === 1) {
            return $cocok[1];
        }
        throw new RuntimeException('Token CSRF tidak ditemukan pada ' . $path . ' (status ' . $response['status'] . ')');
    }

    public function masuk(string $username, string $sandi): int
    {
        $token = $this->csrf('/portal/index.php');

        return $this->request('/admin/cek_login.php', ['_csrf' => $token, 'username' => $username, 'password' => $sandi])['status'];
    }
}

$db = app_db();
$port = (int) (getenv('PENUGASAN_WEB_PORT') ?: 8962);
$baseUrl = 'http://127.0.0.1:' . $port;
$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$kecil = strtolower($suffix);
$sandi = 'UjiPenugasanWeb123Aa';
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

$tahunAktif = (int) ($satu("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1")['id'] ?? 0);
if ($tahunAktif < 1) {
    fwrite(STDOUT, "[lewati] Tidak ada tahun ajaran aktif pada database uji.\n");
    exit(77);
}

$dibuat = ['users' => [], 'guru' => [], 'pengurus' => [], 'wali' => [], 'kelas' => [], 'kamar' => []];
$mulaiUji = date('Y-m-d H:i:s');
$server = null;
$tokenApi = [];

try {
    // ------------------------------------------------------------- fixture
    $jenjang = 'Web' . $suffix;
    $kelas = $exec('INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES (?, ?, 1)', ['Kelas Web ' . $suffix, $jenjang]);
    $dibuat['kelas'][] = $kelas;
    $kamar = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 8)', ['Kamar Web ' . $suffix]);
    $dibuat['kamar'][] = $kamar;
    $guru = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['FW1' . $suffix, 'Guru Web ' . $suffix]);
    $guru2 = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['FW2' . $suffix, 'Guru Web Dua ' . $suffix]);
    array_push($dibuat['guru'], $guru, $guru2);
    $pengurus = $exec("INSERT INTO pengurus (nama, nomor_identitas, jabatan, is_active) VALUES (?, ?, 'Keamanan', 1)", ['Pengurus Web ' . $suffix, 'FWP' . $suffix]);
    $dibuat['pengurus'][] = $pengurus;
    $wali = $exec('INSERT INTO wali (nama, no_hp, is_active) VALUES (?, ?, 1)', ['Wali Web ' . $suffix, '081200008888']);
    $dibuat['wali'][] = $wali;

    $akun = static function (string $username, string $nama, string $role, ?int $guruId = null, ?int $pengurusId = null, ?int $waliId = null) use ($exec, $sandi, &$dibuat): int {
        $id = $exec(
            'INSERT INTO users (name, username, password, guru_id, pengurus_id, wali_id, is_active, force_password_change, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW())',
            [$nama, $username, password_hash($sandi, PASSWORD_DEFAULT), $guruId, $pengurusId, $waliId]
        );
        $dibuat['users'][] = $id;
        $exec('INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, NULL FROM roles WHERE slug = ?', [$id, $role]);

        return $id;
    };
    $adminId = $akun('fw.admin.' . $kecil, 'Admin Web ' . $suffix, 'admin');
    $guruUser = $akun('fw.guru.' . $kecil, 'Akun Guru Web ' . $suffix, 'guru', $guru);
    $pengurusUser = $akun('fw.pengurus.' . $kecil, 'Akun Pengurus Web ' . $suffix, 'pengurus', null, $pengurus);
    $ortuUser = $akun('fw.ortu.' . $kecil, 'Akun Ortu Web ' . $suffix, 'orang_tua', null, null, $wali);

    // ------------------------------------------------------------- server
    $descriptors = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $pipes = [];
    $server = proc_open(
        escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root) . ' ' . escapeshellarg($root . '/tests/v2_phase3_router.php'),
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

    // ------------------------------------------------------------- FW-10 (sebelum penugasan)
    $api = static function (string $path, ?string $token = null, ?array $json = null) use ($baseUrl): array {
        $klien = new KlienPenugasan($baseUrl, 'api');
        $headers = ['Accept: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        return $klien->request($path, null, $headers, $json === null ? null : (string) json_encode($json));
    };
    $loginApi = $api('/api/v1/auth/login', null, ['username' => 'fw.guru.' . $kecil, 'password' => $sandi, 'device_name' => 'uji-penugasan']);
    $tokenApi['guru'] = $loginApi['json']['data']['token'] ?? null;
    $assert($loginApi['status'] === 200 && $tokenApi['guru'] !== null, 'FW-10a login API guru berhasil');
    $profilGuruSebelum = $api('/api/v1/profile', $tokenApi['guru'])['json']['data'] ?? [];
    $assert(array_keys($profilGuruSebelum['capabilities'] ?? []) === ['list', 'default_mode', 'konteks', 'menus', 'aksi'], 'FW-10b struktur capabilities lama utuh pada profil guru');
    $assert(($profilGuruSebelum['feature_capabilities']['list'] ?? ['x']) === [], 'FW-10c field aditif feature_capabilities ada dan kosong sebelum penugasan');
    $profilLogin = $loginApi['json']['data']['profile'] ?? [];
    $assert(array_key_exists('feature_capabilities', $profilLogin) && array_key_exists('default_mode', $profilLogin['capabilities'] ?? []) && $profilLogin['capabilities']['default_mode'] === null, 'FW-10d respons login memuat field lama (default_mode guru biasa tetap null) dan field aditif');
    $loginPengurusApi = $api('/api/v1/auth/login', null, ['username' => 'fw.pengurus.' . $kecil, 'password' => $sandi, 'device_name' => 'uji-penugasan']);
    $tokenApi['pengurus'] = $loginPengurusApi['json']['data']['token'] ?? null;
    $profilPengurusSebelum = $api('/api/v1/profile', $tokenApi['pengurus'])['json']['data'] ?? [];
    $assert(($profilPengurusSebelum['capabilities']['default_mode'] ?? null) === 'pengurus', 'FW-10e default_mode pengurus sebelum penugasan');

    // ------------------------------------------------------------- FW-1
    $anonim = new KlienPenugasan($baseUrl, 'anonim');
    $tanpaSesi = $anonim->request('/admin/admin_penugasan.php');
    $assert($tanpaSesi['status'] === 302 && str_contains((string) $tanpaSesi['location'], '/portal/index.php'), 'FW-1a tanpa sesi diarahkan ke pintu masuk [' . $tanpaSesi['status'] . ']');

    $admin = new KlienPenugasan($baseUrl, 'admin');
    $assert($admin->masuk('fw.admin.' . $kecil, $sandi) === 302, 'FW-11a login web admin berhasil');
    foreach (['murobi', 'pembimbing', 'guru_mapel', 'pendidikan', 'bendahara_bulanan', 'panitia_psb', 'bendahara_psb', 'mata_pelajaran'] as $tab) {
        $halaman = $admin->request('/admin/admin_penugasan.php?jenis=' . $tab);
        $assert(
            $halaman['status'] === 200 && str_contains($halaman['body'], '<h1>Pusat Penugasan</h1>') && !str_contains($halaman['body'], 'Fatal error') && !str_contains($halaman['body'], 'Warning:'),
            'FW-1b admin membuka tab ' . $tab . ' [' . $halaman['status'] . ']'
        );
    }
    $beranda = $admin->request('/admin/admin_penugasan.php?jenis=tidak_ada');
    $assert($beranda['status'] === 200 && str_contains($beranda['body'], 'Murobi adalah penugasan, bukan role'), 'FW-1c jenis tak dikenal jatuh ke tab murobi, bukan galat');
    $assert(str_contains($beranda['body'], 'href="/admin/admin_penugasan.php"') && str_contains($beranda['body'], 'Pusat Penugasan</span>'), 'FW-1d sidebar admin memuat menu Pusat Penugasan');
    $assert(str_contains($beranda['body'], '/admin/admin_murobi.php') && str_contains($beranda['body'], '/admin/admin_pembimbing.php'), 'FW-1e menu murobi dan pembimbing lama tetap ada');

    // ------------------------------------------------------------- FW-2
    foreach (['guru' => 'fw.guru.' . $kecil, 'pengurus' => 'fw.pengurus.' . $kecil, 'orang tua' => 'fw.ortu.' . $kecil] as $label => $username) {
        $klien = new KlienPenugasan($baseUrl, str_replace(' ', '', $label));
        $assert($klien->masuk($username, $sandi) === 302, 'FW-11b login web ' . $label . ' berhasil');
        $ditolak = $klien->request('/admin/admin_penugasan.php?jenis=guru_mapel');
        $assert($ditolak['status'] === 403 && str_contains($ditolak['body'], 'Akses ditolak'), 'FW-2a ' . $label . ' DITOLAK membuka Pusat Penugasan [' . $ditolak['status'] . ']');
        $token = $klien->csrf('/portal/index.php');
        $sebelum = $angka('SELECT COUNT(*) n FROM pendidikan_assignments');
        $kirim = $klien->request('/admin/admin_penugasan.php', ['_csrf' => $token, 'jenis' => 'pendidikan', 'action' => 'buat', 'pengurus_id' => (string) $pengurus, 'tahun_ajaran_id' => (string) $tahunAktif, 'jenjang' => '', 'tanggal_mulai' => $hari(0)]);
        $assert($kirim['status'] === 403 && $angka('SELECT COUNT(*) n FROM pendidikan_assignments') === $sebelum, 'FW-2b POST ' . $label . ' ke Pusat Penugasan ditolak tanpa mengubah data [' . $kirim['status'] . ']');
        if ($label === 'guru') {
            $lama = $klien->request('/admin/admin_murobi.php');
            $assert($lama['status'] === 403, 'FW-2c guru tetap ditolak pada halaman murobi lama [' . $lama['status'] . ']');
        }
    }

    // ------------------------------------------------------------- FW-3 / FW-4
    $csrf = $admin->csrf('/admin/admin_penugasan.php?jenis=mata_pelajaran');
    $sebelumMapel = $angka('SELECT COUNT(*) n FROM mata_pelajaran');
    $tanpaToken = $admin->request('/admin/admin_penugasan.php', ['jenis' => 'mata_pelajaran', 'action' => 'mapel_simpan', 'nama' => 'Tanpa Token ' . $suffix]);
    $assert($tanpaToken['status'] === 419 && $angka('SELECT COUNT(*) n FROM mata_pelajaran') === $sebelumMapel, 'FW-3a POST tanpa token CSRF ditolak 419 tanpa perubahan');
    $tokenPalsu = $admin->request('/admin/admin_penugasan.php', ['_csrf' => 'palsu', 'jenis' => 'mata_pelajaran', 'action' => 'mapel_simpan', 'nama' => 'Token Palsu ' . $suffix]);
    $assert($tokenPalsu['status'] === 419 && $angka('SELECT COUNT(*) n FROM mata_pelajaran') === $sebelumMapel, 'FW-3b POST dengan token CSRF palsu ditolak 419 tanpa perubahan');
    $lewatGet = $admin->request('/admin/admin_penugasan.php?jenis=mata_pelajaran&action=mapel_simpan&nama=LewatGET' . $suffix . '&_csrf=' . rawurlencode($csrf));
    $assert($lewatGet['status'] === 200 && $angka('SELECT COUNT(*) n FROM mata_pelajaran') === $sebelumMapel, 'FW-4 parameter aksi lewat GET tidak memutasi data');

    // ------------------------------------------------------------- FW-5 / FW-12
    $namaXss = 'Fiqih <script>alert(1)</script> ' . $suffix;
    $simpanMapel = $admin->request('/admin/admin_penugasan.php', ['_csrf' => $csrf, 'jenis' => 'mata_pelajaran', 'action' => 'mapel_simpan', 'nama' => $namaXss, 'kode' => 'FW' . $suffix, 'kategori' => 'Agama']);
    $mapelId = (int) ($satu('SELECT id FROM mata_pelajaran WHERE kode = \'FW' . $suffix . '\'')['id'] ?? 0);
    $assert($simpanMapel['status'] === 302 && $mapelId > 0, 'FW-5a mata pelajaran tersimpan lewat formulir');
    $daftarMapel = $admin->request('/admin/admin_penugasan.php?jenis=mata_pelajaran&q=' . rawurlencode($suffix));
    $assert(!str_contains($daftarMapel['body'], '<script>alert(1)</script>') && str_contains($daftarMapel['body'], '&lt;script&gt;alert(1)&lt;/script&gt;'), 'FW-12 nama mata pelajaran berisi tag HTML di-escape');

    $buat = static function (string $jenis, array $isian) use ($admin, $csrf): array {
        return $admin->request('/admin/admin_penugasan.php', ['_csrf' => $csrf, 'jenis' => $jenis, 'action' => 'buat'] + $isian);
    };
    $terakhir = static fn (string $tabel): int => (int) ($satu('SELECT MAX(id) AS id FROM ' . $tabel)['id'] ?? 0);

    $r = $buat('guru_mapel', ['guru_id' => (string) $guru, 'mata_pelajaran_id' => (string) $mapelId, 'kelas_id' => (string) $kelas, 'tahun_ajaran_id' => (string) $tahunAktif, 'tanggal_mulai' => $hari(0), 'tanggal_selesai' => '', 'catatan' => 'via web']);
    $gmId = $terakhir('guru_mapel_assignments');
    $assert($r['status'] === 302 && $angka('SELECT COUNT(*) n FROM guru_mapel_assignments WHERE id = ' . $gmId . ' AND guru_id = ' . $guru . ' AND created_by = ' . $adminId) === 1, 'FW-5b penugasan guru mapel tersimpan lewat formulir');
    $daftarGm = $admin->request('/admin/admin_penugasan.php?jenis=guru_mapel&q=' . rawurlencode('Guru Web ' . $suffix));
    $assert(str_contains($daftarGm['body'], 'ah-badge--ok">Aktif') && str_contains($daftarGm['body'], 'nilai.input') && str_contains($daftarGm['body'], '@fw.guru.' . $kecil), 'FW-5c daftar menampilkan status Aktif dan capability efektif akun guru');
    $assert(str_contains($daftarGm['body'], 'Kelas Web ' . $suffix) && str_contains($daftarGm['body'], $hari(0) . ' — seterusnya'), 'FW-5d daftar menampilkan cakupan dan masa berlaku');

    $r = $buat('guru_mapel', ['guru_id' => (string) $guru2, 'mata_pelajaran_id' => (string) $mapelId, 'kelas_id' => (string) $kelas, 'tahun_ajaran_id' => (string) $tahunAktif, 'tanggal_mulai' => $hari(0)]);
    $daftarGm2 = $admin->request('/admin/admin_penugasan.php?jenis=guru_mapel&q=' . rawurlencode('Guru Web Dua ' . $suffix));
    $assert($r['status'] === 302 && str_contains($daftarGm2['body'], 'Belum punya akun') && str_contains($daftarGm2['body'], 'belum mempunyai akun'), 'FW-5e guru tanpa akun: penugasan tersimpan dengan pesan yang jelas');

    $r = $buat('pendidikan', ['pengurus_id' => (string) $pengurus, 'tahun_ajaran_id' => (string) $tahunAktif, 'jenjang' => $jenjang, 'tanggal_mulai' => $hari(0)]);
    $pendidikanId = $terakhir('pendidikan_assignments');
    $assert($r['status'] === 302 && $angka('SELECT COUNT(*) n FROM pendidikan_assignments WHERE id = ' . $pendidikanId . ' AND pengurus_id = ' . $pengurus) === 1, 'FW-5f penugasan Bagian Pendidikan tersimpan');
    $r = $buat('bendahara_bulanan', ['pengurus_id' => (string) $pengurus, 'tahun_ajaran_id' => (string) $tahunAktif, 'jenjang' => '', 'tanggal_mulai' => $hari(0)]);
    $assert($r['status'] === 302 && $angka('SELECT COUNT(*) n FROM bendahara_bulanan_assignments WHERE pengurus_id = ' . $pengurus) === 1, 'FW-5g penugasan bendahara bulanan tersimpan');
    $r = $buat('panitia_psb', ['pengurus_id' => (string) $pengurus, 'tahun_ajaran_id' => (string) $tahunAktif, 'gelombang' => '1', 'tanggal_mulai' => $hari(0)]);
    $psbId = $terakhir('panitia_psb_assignments');
    $assert($r['status'] === 302 && $angka('SELECT COUNT(*) n FROM panitia_psb_assignments WHERE id = ' . $psbId . " AND gelombang = '1'") === 1, 'FW-5h penugasan panitia PSB tersimpan');
    $r = $buat('bendahara_psb', ['pengurus_id' => (string) $pengurus, 'tahun_ajaran_id' => (string) $tahunAktif, 'gelombang' => '', 'tanggal_mulai' => $hari(0)]);
    $assert($r['status'] === 302 && $angka('SELECT COUNT(*) n FROM bendahara_psb_assignments WHERE pengurus_id = ' . $pengurus) === 1, 'FW-5i penugasan bendahara PSB tersimpan');
    $r = $buat('murobi', ['guru_id' => (string) $guru, 'tahun_ajaran_id' => (string) $tahunAktif, 'target_type' => 'Kamar', 'kamar_id' => (string) $kamar, 'tanggal_mulai' => $hari(0)]);
    $murobiId = $terakhir('murobi_assignments');
    $assert($r['status'] === 302 && $angka('SELECT COUNT(*) n FROM murobi_assignments WHERE id = ' . $murobiId . ' AND guru_id = ' . $guru . ' AND kamar_id = ' . $kamar) === 1, 'FW-5j penugasan murobi tersimpan dari pusat');
    $r = $buat('pembimbing', ['pengurus_id' => (string) $pengurus, 'tahun_ajaran_id' => (string) $tahunAktif, 'target_type' => 'Kelas', 'kelas_id' => (string) $kelas, 'tanggal_mulai' => $hari(0)]);
    $pembimbingId = $terakhir('pembimbing_assignments');
    $assert($r['status'] === 302 && $angka('SELECT COUNT(*) n FROM pembimbing_assignments WHERE id = ' . $pembimbingId . ' AND pengurus_id = ' . $pengurus) === 1, 'FW-5k penugasan pembimbing tersimpan dari pusat');
    $daftarPsb = $admin->request('/admin/admin_penugasan.php?jenis=panitia_psb&q=' . rawurlencode('Pengurus Web ' . $suffix));
    $assert(str_contains($daftarPsb['body'], 'Gelombang 1') && str_contains($daftarPsb['body'], 'psb.pendaftar') && !str_contains($daftarPsb['body'], 'psb_keuangan.tagihan'), 'FW-5l tab panitia PSB hanya menampilkan capability panitia, bukan keuangan');
    $daftarFilter = $admin->request('/admin/admin_penugasan.php?jenis=pendidikan&status=nonaktif&q=' . rawurlencode('Pengurus Web ' . $suffix));
    $assert(str_contains($daftarFilter['body'], 'Tidak ada penugasan sesuai filter'), 'FW-5m filter status Dinonaktifkan mengembalikan kosong sebelum ada yang dinonaktifkan');

    // ------------------------------------------------------------- FW-6
    $sebelumGm = $angka('SELECT COUNT(*) n FROM guru_mapel_assignments');
    $r = $buat('guru_mapel', ['guru_id' => (string) $guru, 'mata_pelajaran_id' => (string) $mapelId, 'kelas_id' => (string) $kelas, 'tahun_ajaran_id' => (string) $tahunAktif, 'tanggal_mulai' => $hari(2)]);
    $setelahDup = $admin->request('/admin/admin_penugasan.php?jenis=guru_mapel');
    $assert($r['status'] === 302 && $angka('SELECT COUNT(*) n FROM guru_mapel_assignments') === $sebelumGm && str_contains($setelahDup['body'], 'bertumpang tindih'), 'FW-6a penugasan bertumpang tindih ditolak lewat formulir dengan pesan');
    $r = $buat('pendidikan', ['pengurus_id' => (string) $pengurus, 'tahun_ajaran_id' => (string) $tahunAktif, 'jenjang' => $jenjang, 'tanggal_mulai' => $hari(0)]);
    $assert($r['status'] === 302 && $angka('SELECT COUNT(*) n FROM pendidikan_assignments WHERE pengurus_id = ' . $pengurus) === 1, 'FW-6b penugasan identik ditolak lewat formulir');
    $r = $buat('guru_mapel', ['guru_id' => (string) $guru, 'mata_pelajaran_id' => (string) $mapelId, 'kelas_id' => (string) $kelas, 'tahun_ajaran_id' => (string) $tahunAktif, 'tanggal_mulai' => $hari(5), 'tanggal_selesai' => $hari(1)]);
    $setelahTanggal = $admin->request('/admin/admin_penugasan.php?jenis=guru_mapel');
    $assert(str_contains($setelahTanggal['body'], 'mendahului tanggal mulai') && str_contains($setelahTanggal['body'], 'ah-field-error'), 'FW-6c tanggal selesai mendahului tanggal mulai ditolak dengan pesan pada kolom');

    // ------------------------------------------------------------- FW-7
    $r = $admin->request('/admin/admin_penugasan.php', ['_csrf' => $csrf, 'jenis' => 'pendidikan', 'action' => 'ubah', 'id' => (string) $pendidikanId, 'jenjang' => '', 'tanggal_mulai' => $hari(0), 'tanggal_selesai' => $hari(30), 'catatan' => 'diperluas', 'alasan' => 'Perluas ke seluruh unit lewat web']);
    $barisUbah = $satu('SELECT jenjang, tanggal_selesai, catatan, alasan_perubahan, updated_by FROM pendidikan_assignments WHERE id = ' . $pendidikanId);
    $assert($r['status'] === 302 && $barisUbah['jenjang'] === null && $barisUbah['tanggal_selesai'] === $hari(30) && $barisUbah['catatan'] === 'diperluas' && (int) $barisUbah['updated_by'] === $adminId, 'FW-7a ubah cakupan dan masa berlaku tercermin di data');
    $r = $admin->request('/admin/admin_penugasan.php', ['_csrf' => $csrf, 'jenis' => 'pendidikan', 'action' => 'ubah', 'id' => (string) $pendidikanId, 'jenjang' => '', 'tanggal_mulai' => $hari(0), 'alasan' => 'x']);
    $editUlang = $admin->request('/admin/admin_penugasan.php?jenis=pendidikan&edit=' . $pendidikanId);
    $assert($r['status'] === 302 && str_contains((string) $r['location'], 'edit=' . $pendidikanId) && str_contains($editUlang['body'], 'Ubah penugasan #' . $pendidikanId), 'FW-7b ubah tanpa alasan ditolak dan kembali ke formulir ubah');
    $r = $admin->request('/admin/admin_penugasan.php', ['_csrf' => $csrf, 'jenis' => 'panitia_psb', 'action' => 'akhiri', 'id' => (string) $psbId, 'tanggal_selesai' => $hari(0), 'alasan' => 'Diakhiri lewat web']);
    $barisAkhir = $satu('SELECT tanggal_selesai, diakhiri_oleh, alasan_pengakhiran FROM panitia_psb_assignments WHERE id = ' . $psbId);
    $assert($r['status'] === 302 && $barisAkhir['tanggal_selesai'] === $hari(0) && (int) $barisAkhir['diakhiri_oleh'] === $adminId && $barisAkhir['alasan_pengakhiran'] === 'Diakhiri lewat web', 'FW-7c akhiri lewat formulir mencatat tanggal, pelaku, dan alasan');
    $r = $admin->request('/admin/admin_penugasan.php', ['_csrf' => $csrf, 'jenis' => 'murobi', 'action' => 'nonaktifkan', 'id' => (string) $murobiId, 'alasan' => 'Nonaktif lewat web']);
    $assert($r['status'] === 302 && (int) $satu('SELECT is_active FROM murobi_assignments WHERE id = ' . $murobiId)['is_active'] === 0, 'FW-7d nonaktifkan murobi lewat pusat tercermin di tabel lama');
    $daftarMurobi = $admin->request('/admin/admin_penugasan.php?jenis=murobi&status=nonaktif&q=' . rawurlencode('Guru Web ' . $suffix));
    $assert(str_contains($daftarMurobi['body'], 'Dinonaktifkan') && str_contains($daftarMurobi['body'], 'Aktifkan…'), 'FW-7e filter Dinonaktifkan menampilkan penugasan dengan tombol Aktifkan');
    $r = $admin->request('/admin/admin_penugasan.php', ['_csrf' => $csrf, 'jenis' => 'murobi', 'action' => 'aktifkan', 'id' => (string) $murobiId, 'alasan' => 'Aktif lagi lewat web']);
    $assert($r['status'] === 302 && (int) $satu('SELECT is_active FROM murobi_assignments WHERE id = ' . $murobiId)['is_active'] === 1, 'FW-7f aktifkan kembali lewat formulir');
    $assert($angka("SELECT COUNT(*) n FROM audit_logs WHERE actor_user_id = " . $adminId . " AND action IN ('penugasan.buat','penugasan.ubah','penugasan.akhiri','penugasan.nonaktifkan','penugasan.aktifkan') AND ip_address = '127.0.0.1' AND user_agent IS NOT NULL") >= 10, 'FW-7g audit web menyimpan IP dan user agent pelaku');

    // ------------------------------------------------------------- FW-13
    // ID selalu ditafsirkan pada tabel jenis yang diminta; ID yang tidak ada di
    // tabel itu dijawab "tidak ditemukan" dan tidak menyentuh baris mana pun.
    $potretSebelum = $satu('SELECT GROUP_CONCAT(CONCAT(id, \':\', COALESCE(tanggal_selesai, \'-\'), \':\', is_active) ORDER BY id) AS p FROM bendahara_psb_assignments');
    $r = $admin->request('/admin/admin_penugasan.php', ['_csrf' => $csrf, 'jenis' => 'bendahara_psb', 'action' => 'akhiri', 'id' => '999999999', 'tanggal_selesai' => $hari(0), 'alasan' => 'IDOR ID tidak ada']);
    $potretSesudah = $satu('SELECT GROUP_CONCAT(CONCAT(id, \':\', COALESCE(tanggal_selesai, \'-\'), \':\', is_active) ORDER BY id) AS p FROM bendahara_psb_assignments');
    $idorMuat = $admin->request('/admin/admin_penugasan.php?jenis=bendahara_psb');
    $assert(
        $r['status'] === 302 && $potretSesudah === $potretSebelum && str_contains($idorMuat['body'], 'tidak ditemukan'),
        'FW-13a ID yang tidak ada pada tabel jenis itu dijawab tidak ditemukan tanpa mengubah baris mana pun'
    );
    $r = $admin->request('/admin/admin_penugasan.php', ['_csrf' => $csrf, 'jenis' => 'guru_mapel', 'action' => 'ubah', 'id' => (string) $gmId, 'guru_id' => (string) $guru2, 'mata_pelajaran_id' => (string) $mapelId, 'kelas_id' => (string) $kelas, 'tanggal_mulai' => $hari(0), 'alasan' => 'Coba ganti orang lewat web']);
    $assert($r['status'] === 302 && (int) $satu('SELECT guru_id FROM guru_mapel_assignments WHERE id = ' . $gmId)['guru_id'] === $guru, 'FW-13b guru_id yang disuntikkan pada ubah diabaikan');

    // ------------------------------------------------------------- FW-8
    foreach (['admin_murobi.php', 'admin_pembimbing.php'] as $lama) {
        $halamanLama = $admin->request('/admin/' . $lama);
        $assert($halamanLama['status'] === 200 && str_contains($halamanLama['body'], 'admin_penugasan.php?jenis='), 'FW-8a ' . $lama . ' tetap terbuka dan menaut ke Pusat Penugasan');
    }
    $csrfLama = $admin->csrf('/admin/admin_murobi.php');
    $r = $admin->request('/admin/admin_murobi.php', ['_csrf' => $csrfLama, 'action' => 'save', 'guru_id' => (string) $guru2, 'tahun_ajaran_id' => (string) $tahunAktif, 'target_type' => 'Kamar', 'kamar_id' => (string) $kamar, 'tanggal_mulai' => $hari(0), 'tanggal_selesai' => '']);
    $assert($r['status'] === 302 && $angka('SELECT COUNT(*) n FROM murobi_assignments WHERE guru_id = ' . $guru2 . ' AND kamar_id = ' . $kamar) === 1, 'FW-8b formulir lama admin_murobi.php masih menyimpan penugasan');
    $daftarMurobiLama = $admin->request('/admin/admin_penugasan.php?jenis=murobi&q=' . rawurlencode('Guru Web Dua ' . $suffix));
    $assert(str_contains($daftarMurobiLama['body'], 'Guru Web Dua ' . $suffix), 'FW-8c penugasan dari halaman lama tampil di Pusat Penugasan');
    $r = $admin->request('/admin/admin_pembimbing.php', ['_csrf' => $csrfLama, 'action' => 'save', 'pengurus_id' => (string) $pengurus, 'tahun_ajaran_id' => (string) $tahunAktif, 'target_type' => 'Kamar', 'kamar_id' => (string) $kamar, 'tanggal_mulai' => $hari(0), 'tanggal_selesai' => '']);
    $assert($r['status'] === 302 && $angka('SELECT COUNT(*) n FROM pembimbing_assignments WHERE pengurus_id = ' . $pengurus . ' AND kamar_id = ' . $kamar) === 1, 'FW-8d formulir lama admin_pembimbing.php masih menyimpan penugasan');

    // ------------------------------------------------------------- FW-9
    $akunHalaman = $admin->request('/admin/admin_akun.php?q=' . rawurlencode('fw.pengurus.' . $kecil));
    $assert($akunHalaman['status'] === 200 && str_contains($akunHalaman['body'], 'Role dasar dan penugasan adalah dua hal berbeda'), 'FW-9a halaman akun menjelaskan role dasar vs penugasan');
    $assert(str_contains($akunHalaman['body'], 'Penugasan efektif') && str_contains($akunHalaman['body'], 'Bagian Pendidikan') && str_contains($akunHalaman['body'], 'Kelola di Pusat Penugasan'), 'FW-9b halaman akun menampilkan ringkasan penugasan efektif dan tautan ke pusat');
    $assert(!preg_match('/<option[^>]*>\s*(Bendahara|Panitia PSB|Bagian Pendidikan)/', $akunHalaman['body']), 'FW-9c penugasan tidak muncul sebagai pilihan role dasar');

    // ------------------------------------------------------------- FW-10 (sesudah penugasan)
    $profilGuruSesudah = $api('/api/v1/profile', $tokenApi['guru'])['json']['data'] ?? [];
    $assert(($profilGuruSesudah['capabilities'] ?? null) !== null && array_keys($profilGuruSesudah) === array_keys($profilGuruSebelum), 'FW-10f field profil guru sebelum dan sesudah penugasan identik urutan dan namanya');
    $tanpaMode = $profilGuruSesudah['capabilities'];
    $assert(array_keys($tanpaMode) === ['list', 'default_mode', 'konteks', 'menus', 'aksi'] && array_column($tanpaMode['menus'], 'key') === ['jadwal', 'laporan', 'izin_murobi'] && $tanpaMode['default_mode'] === 'murobi', 'FW-10g menu/mode guru tetap: jadwal, laporan, dan murobi (karena penugasan murobi), tanpa menu nilai');
    $assert(in_array('nilai.input', $profilGuruSesudah['feature_capabilities']['list'] ?? [], true), 'FW-10h field aditif memuat capability nilai dari penugasan guru mapel');
    $profilPengurusSesudah = $api('/api/v1/profile', $tokenApi['pengurus'])['json']['data'] ?? [];
    $assert($profilPengurusSesudah['capabilities'] === $profilPengurusSebelum['capabilities'], 'FW-10i struktur capabilities pengurus IDENTIK sebelum dan sesudah empat penugasan');
    $assert(in_array('rapor.finalisasi', $profilPengurusSesudah['feature_capabilities']['list'] ?? [], true) && !in_array('pendidikan', $profilPengurusSesudah['capabilities']['list'], true), 'FW-10j capability fondasi hanya pada field aditif; tidak ada mode pendidikan/bendahara');
    $capApi = $api('/api/v1/me/capabilities', $tokenApi['pengurus'])['json']['data'] ?? [];
    $assert(array_keys($capApi) === ['list', 'default_mode', 'konteks', 'label'] && $capApi['default_mode'] === 'pengurus', 'FW-10k endpoint /me/capabilities tidak berubah bentuk');
    $jadwalPengurus = $api('/api/v1/schedules/today', $tokenApi['pengurus']);
    $assert($jadwalPengurus['status'] === 403, 'FW-10l endpoint jadwal V1 tetap 403 untuk pengurus');
    $jadwalGuru = $api('/api/v1/schedules/today', $tokenApi['guru']);
    $assert($jadwalGuru['status'] === 200, 'FW-10m endpoint jadwal V1 tetap 200 untuk guru');
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach ($dibuat['users'] as $id) {
        $db->query('DELETE FROM api_tokens WHERE user_id = ' . (int) $id);
        $db->query('DELETE FROM audit_logs WHERE actor_user_id = ' . (int) $id . " OR (entity_type = 'user' AND entity_id = " . (int) $id . ')');
    }
    $db->query("DELETE FROM audit_logs WHERE created_at >= '" . $mulaiUji . "' AND (action LIKE 'penugasan.%' OR action LIKE 'mata_pelajaran.%' OR action = 'master.relation.create' OR action = 'pembimbing_assignment_created')");
    foreach ($dibuat['guru'] as $id) {
        $db->query('DELETE FROM guru_mapel_assignments WHERE guru_id = ' . (int) $id);
        $db->query('DELETE FROM murobi_assignments WHERE guru_id = ' . (int) $id);
    }
    foreach ($dibuat['pengurus'] as $id) {
        foreach (['pembimbing_assignments', 'pendidikan_assignments', 'bendahara_bulanan_assignments', 'panitia_psb_assignments', 'bendahara_psb_assignments'] as $t) {
            $db->query('DELETE FROM ' . $t . ' WHERE pengurus_id = ' . (int) $id);
        }
    }
    $db->query("DELETE FROM mata_pelajaran WHERE kode = 'FW" . $suffix . "' OR nama LIKE '%" . $suffix . "'");
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
    foreach ($dibuat['wali'] as $id) {
        $db->query('DELETE FROM wali WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['kelas'] as $id) {
        $db->query('DELETE FROM kelas WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['kamar'] as $id) {
        $db->query('DELETE FROM kamar WHERE id = ' . (int) $id);
    }
}

echo PHP_EOL . 'Total gagal: ' . count($failures) . PHP_EOL;
exit($failures === [] ? 0 : 1);
