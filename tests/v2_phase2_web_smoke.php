<?php

declare(strict_types=1);

/**
 * Smoke test HTTP V2 Fase 2.
 *
 * Menjalankan alur perizinan lewat HTTP sungguhan (server PHP built-in) untuk
 * membuktikan bahwa guard, CSRF, kode status, dan alur POST/Redirect/GET bekerja
 * seperti yang dijanjikan — bukan hanya pada lapisan layanan.
 *
 * Yang diperiksa:
 *   - kode status per peran untuk setiap halaman portal Fase 2,
 *   - alur pengurus: buka form -> kirim pengajuan -> 303 ke detail,
 *   - idempotensi: mengirim ulang POST dengan kunci sama tidak menambah pengajuan,
 *   - murobi lain menerima 403; keputusan murobi yang sah berhasil,
 *   - keputusan kedua menerima 409; admin pengganti tanpa alasan menerima 422,
 *   - orang tua menerima 403 untuk santri yang tidak terhubung,
 *   - POST tanpa token CSRF ditolak 419,
 *   - `admin/admin_izin.php` mengalihkan (302) ke portal.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   V2_PHASE2_RUN_WEB=1 php tests/v2_phase2_web_smoke.php
 */

$root = dirname(__DIR__);
if (getenv('V2_PHASE2_RUN_WEB') !== '1') {
    fwrite(STDOUT, "[lewati] Set V2_PHASE2_RUN_WEB=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}
require_once $root . '/app/bootstrap.php';
if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Smoke test ditolak: DB_NAME wajib berakhiran _test.\n");
    exit(2);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "Ekstensi curl dibutuhkan untuk smoke test HTTP.\n");
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

$port = (int) (getenv('V2_PHASE2_WEB_PORT') ?: 8712);
$baseUrl = 'http://127.0.0.1:' . $port;
$sandi = 'UjiPassword123!Aa';
$suffix = strtoupper(bin2hex(random_bytes(3)));
$lower = strtolower($suffix);

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

/** Klien HTTP sederhana dengan cookie jar per peran. */
final class Klien
{
    private string $jar;

    public function __construct(private string $baseUrl, string $label)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'wah-' . $label . '-');
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

    /** Mengambil nilai field tersembunyi pertama dari halaman. */
    public function field(string $path, string $name): string
    {
        $response = $this->request($path);
        if (preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $response['body'], $matches) === 1) {
            return $matches[1];
        }
        throw new RuntimeException('Field ' . $name . ' tidak ditemukan pada ' . $path . ' (status ' . $response['status'] . ')');
    }

    public function login(string $username, string $password): array
    {
        $token = $this->csrf('/admin/admin_login.php');

        return $this->request('/admin/cek_login.php', [
            '_csrf' => $token,
            'username' => $username,
            'password' => $password,
        ]);
    }
}

$server = null;
$created = [
    'users' => [], 'pengurus' => [], 'wali' => [], 'santri_wali' => [], 'santri' => [],
    'guru' => [], 'kamar' => [], 'murobi' => [], 'pembimbing' => [], 'plotting_kamar' => [], 'izin' => [],
];

try {
    // ---------------------------------------------------------------- fixture
    $adminRow = $db->query(
        "SELECT u.id, u.username FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
          WHERE r.slug = 'admin' AND u.is_active = 1 LIMIT 1"
    )?->fetch_assoc();
    if (!$adminRow) {
        throw new RuntimeException('Akun admin fixture tidak tersedia.');
    }
    $adminId = (int) $adminRow['id'];
    $_SESSION = ['user_id' => $adminId];

    $yearRow = $db->query("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1")?->fetch_assoc();
    if (!$yearRow) {
        throw new RuntimeException('Tahun ajaran aktif tidak tersedia.');
    }
    $yearId = (int) $yearRow['id'];

    $kamar = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 20)', ['Kamar Web ' . $suffix]);
    $created['kamar'][] = $kamar;
    $santriSql = "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan,
                    kab_kota, provinsi, nama_ayah, no_hp_ayah, nama_ibu, no_hp_ibu, asal_sekolah, sekolah_saat_ini, is_active)
                  VALUES (?, ?, 'L', 'Ciamis', '2010-01-01', 'Alamat', 'Desa', 'Kec', 'Ciamis', 'Jabar', '', NULL, '', NULL, 'A', 'B', 1)";
    $santri = $exec($santriSql, ['WEB1' . $suffix, 'Santri Web ' . $suffix]);
    $santriLain = $exec($santriSql, ['WEB2' . $suffix, 'Santri Web Lain ' . $suffix]);
    $created['santri'] = [$santri, $santriLain];
    $created['plotting_kamar'][] = $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santri, $kamar, $yearId]);

    $guruMurobi = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['WG1' . $suffix, 'Guru Web Murobi ' . $suffix]);
    $guruLain = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['WG2' . $suffix, 'Guru Web Lain ' . $suffix]);
    $created['guru'] = [$guruMurobi, $guruLain];
    $kamarLain = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 10)', ['Kamar Web Lain ' . $suffix]);
    $created['kamar'][] = $kamarLain;
    $murobiSql = "INSERT INTO murobi_assignments (guru_id, tahun_ajaran_id, target_type, kamar_id, tanggal_mulai, is_active)
                  VALUES (?, ?, 'Kamar', ?, DATE_SUB(CURDATE(), INTERVAL 30 DAY), 1)";
    $created['murobi'][] = $exec($murobiSql, [$guruMurobi, $yearId, $kamar]);
    $created['murobi'][] = $exec($murobiSql, [$guruLain, $yearId, $kamarLain]);

    $pengurus = $exec("INSERT INTO pengurus (nama, nomor_identitas, jabatan, is_active) VALUES (?, ?, 'Keamanan', 1)", ['Pengurus Web ' . $suffix, 'WPA' . $suffix]);
    $created['pengurus'][] = $pengurus;
    $wali = $exec('INSERT INTO wali (nama, no_hp, is_active) VALUES (?, ?, 1)', ['Wali Web ' . $suffix, '081400000001']);
    $waliLain = $exec('INSERT INTO wali (nama, no_hp, is_active) VALUES (?, ?, 1)', ['Wali Web Lain ' . $suffix, '081400000002']);
    $created['wali'] = [$wali, $waliLain];
    $created['santri_wali'][] = $exec("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary) VALUES (?, ?, 'Ayah', 1)", [$santri, $wali]);
    $created['santri_wali'][] = $exec("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary) VALUES (?, ?, 'Ayah', 1)", [$santriLain, $waliLain]);

    $makeUser = static function (string $username, string $name, ?int $guruId, ?int $pengurusId, ?int $waliId, ?string $role) use ($exec, $adminId, $sandi): int {
        $id = $exec(
            'INSERT INTO users (name, username, password, guru_id, pengurus_id, wali_id, is_active, force_password_change, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW())',
            [$name, $username, password_hash($sandi, PASSWORD_DEFAULT), $guruId, $pengurusId, $waliId]
        );
        if ($role !== null) {
            $exec('INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = ?', [$id, $adminId, $role]);
        }
        return $id;
    };

    $userPengurus = $makeUser('web.pa.' . $lower, 'Akun Pengurus Web', null, $pengurus, null, 'pengurus');
    $userMurobi = $makeUser('web.m1.' . $lower, 'Akun Murobi Web', $guruMurobi, null, null, 'guru');
    $userMurobiLain = $makeUser('web.m2.' . $lower, 'Akun Murobi Web Lain', $guruLain, null, null, 'guru');
    $userOrtu = $makeUser('web.o1.' . $lower, 'Akun Ortu Web', null, null, $wali, 'orang_tua');
    $userOrtuLain = $makeUser('web.o2.' . $lower, 'Akun Ortu Web Lain', null, null, $waliLain, 'orang_tua');
    // Admin uji tersendiri: akun admin produksi/fixture yang sudah ada tidak disentuh.
    $adminUsername = 'web.ad.' . $lower;
    $userAdmin = $makeUser($adminUsername, 'Akun Admin Web', null, null, null, 'admin');
    $created['users'] = [$userPengurus, $userMurobi, $userMurobiLain, $userOrtu, $userOrtuLain, $userAdmin];

    $created['pembimbing'][] = pembimbing_service()->create([
        'pengurus_id' => $pengurus,
        'tahun_ajaran_id' => $yearId,
        'target_type' => 'Kamar',
        'kamar_id' => $kamar,
        'tanggal_mulai' => date('Y-m-d', strtotime('-10 days')),
        'tanggal_selesai' => '',
    ], $adminId);

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

    // --------------------------------------------------------------- anonim
    $anon = new Klien($baseUrl, 'anon');
    $anonPortal = $anon->request('/portal/izin.php');
    $assert($anonPortal['status'] === 302 && str_contains((string) $anonPortal['location'], 'admin_login.php'), 'WEB-1 Anonim diarahkan ke halaman masuk (302)');
    $anonBuat = $anon->request('/portal/izin_buat.php');
    $assert($anonBuat['status'] === 302, 'WEB-2 Halaman buat pengajuan tertutup bagi anonim');

    // ------------------------------------------------------------- pengurus
    $klienPengurus = new Klien($baseUrl, 'pengurus');
    $masuk = $klienPengurus->login('web.pa.' . $lower, $sandi);
    $assert($masuk['status'] === 302 && str_contains((string) $masuk['location'], '/portal/index.php'), 'WEB-3 Pengurus masuk dan diarahkan ke portal');
    foreach (['/portal/index.php', '/portal/izin.php', '/portal/izin_antrean.php', '/portal/izin_buat.php'] as $halaman) {
        $assert($klienPengurus->request($halaman)['status'] === 200, 'WEB-4 Pengurus dapat membuka ' . $halaman);
    }
    foreach (['/admin/admin_dashboard.php', '/admin/admin_pembimbing.php'] as $halaman) {
        $status = $klienPengurus->request($halaman)['status'];
        $assert($status === 403, 'WEB-5 Pengurus ditolak 403 pada ' . $halaman . ' [' . $status . ']');
    }

    // POST tanpa CSRF harus ditolak.
    $tanpaCsrf = $klienPengurus->request('/portal/izin_aksi.php', ['aksi' => 'buat', 'santri_id' => (string) $santri]);
    $assert($tanpaCsrf['status'] === 419, 'WEB-6 POST tanpa token CSRF ditolak 419 [' . $tanpaCsrf['status'] . ']');

    // Alur pengajuan.
    $halamanBuat = '/portal/izin_buat.php?mode=pengurus';
    $csrf = $klienPengurus->csrf($halamanBuat);
    $kunci = $klienPengurus->field($halamanBuat, 'idempotency_key');
    $formPengajuan = [
        '_csrf' => $csrf,
        'aksi' => 'buat',
        'mode' => 'pengurus',
        'idempotency_key' => $kunci,
        'santri_id' => (string) $santri,
        'tgl_izin' => date('Y-m-d', strtotime('+3 days')),
        'tgl_kembali' => date('Y-m-d', strtotime('+4 days')),
        'alasan' => 'Menghadiri pernikahan saudara',
        'catatan_pengurus' => 'Dijemput orang tua',
    ];
    $sebelum = (int) ($db->query('SELECT COUNT(*) AS jumlah FROM izin_pengajuan')?->fetch_assoc()['jumlah'] ?? 0);
    $kirim = $klienPengurus->request('/portal/izin_aksi.php', $formPengajuan);
    $assert($kirim['status'] === 303 && str_contains((string) $kirim['location'], 'izin_detail.php'), 'WEB-7 Pengajuan tersimpan dan dialihkan ke detail (303) [' . $kirim['status'] . ']');
    preg_match('/id=(\d+)/', (string) $kirim['location'], $cocok);
    $pengajuanId = (int) ($cocok[1] ?? 0);
    if ($pengajuanId > 0) {
        $created['izin'][] = $pengajuanId;
    }
    $assert($pengajuanId > 0, 'WEB-8 ID pengajuan baru terbaca dari redirect');

    // Kirim ulang POST yang sama (refresh/klik ganda) memakai kunci yang sama.
    $ulang = $klienPengurus->request('/portal/izin_aksi.php', $formPengajuan);
    $sesudah = (int) ($db->query('SELECT COUNT(*) AS jumlah FROM izin_pengajuan')?->fetch_assoc()['jumlah'] ?? 0);
    $assert($ulang['status'] === 303, 'WEB-9 Pengiriman ulang tetap dialihkan tanpa galat');
    $assert($sesudah === $sebelum + 1, 'WEB-10 Pengiriman ulang dengan kunci sama tidak menambah pengajuan');

    // Tanggal terbalik ditolak 422 lewat HTTP.
    $formSalah = $formPengajuan;
    $formSalah['_csrf'] = $klienPengurus->csrf($halamanBuat);
    $formSalah['idempotency_key'] = $klienPengurus->field($halamanBuat, 'idempotency_key');
    $formSalah['tgl_izin'] = date('Y-m-d', strtotime('+9 days'));
    $formSalah['tgl_kembali'] = date('Y-m-d', strtotime('+8 days'));
    $salah = $klienPengurus->request('/portal/izin_aksi.php', $formSalah);
    $assert($salah['status'] === 422, 'WEB-11 Tanggal kembali sebelum tanggal izin ditolak 422 lewat HTTP [' . $salah['status'] . ']');

    // Pengajuan tumpang tindih ditolak 409 lewat HTTP.
    $formBentrok = $formPengajuan;
    $formBentrok['_csrf'] = $klienPengurus->csrf($halamanBuat);
    $formBentrok['idempotency_key'] = $klienPengurus->field($halamanBuat, 'idempotency_key');
    $formBentrok['alasan'] = 'Rentang bentrok';
    $bentrok = $klienPengurus->request('/portal/izin_aksi.php', $formBentrok);
    $assert($bentrok['status'] === 409, 'WEB-12 Pengajuan tumpang tindih ditolak 409 lewat HTTP [' . $bentrok['status'] . ']');

    // ---------------------------------------------------------------- murobi
    $klienMurobiLain = new Klien($baseUrl, 'murobi-lain');
    $klienMurobiLain->login('web.m2.' . $lower, $sandi);
    $detailSilang = $klienMurobiLain->request('/portal/izin_detail.php?id=' . $pengajuanId);
    $assert($detailSilang['status'] === 403, 'WEB-13 Murobi lain menerima 403 pada detail pengajuan bukan miliknya [' . $detailSilang['status'] . ']');

    $klienMurobi = new Klien($baseUrl, 'murobi');
    $klienMurobi->login('web.m1.' . $lower, $sandi);
    $halamanDetail = '/portal/izin_detail.php?id=' . $pengajuanId . '&mode=murobi';
    $detail = $klienMurobi->request($halamanDetail);
    $assert($detail['status'] === 200 && str_contains($detail['body'], 'Keputusan murobi'), 'WEB-14 Murobi yang ditugaskan melihat panel keputusan');
    $antreanMurobi = $klienMurobi->request('/portal/izin_antrean.php?mode=murobi');
    $assert($antreanMurobi['status'] === 200 && str_contains($antreanMurobi['body'], '#' . $pengajuanId), 'WEB-15 Pengajuan muncul pada antrean murobi');

    $formKeputusan = [
        '_csrf' => $klienMurobi->csrf($halamanDetail),
        'aksi' => 'putuskan',
        'mode' => 'murobi',
        'pengajuan_id' => (string) $pengajuanId,
        'version' => $klienMurobi->field($halamanDetail, 'version'),
        'idempotency_key' => $klienMurobi->field($halamanDetail, 'idempotency_key'),
        'hasil' => 'Disetujui',
        'alasan' => 'Alasan izin dapat diterima',
    ];
    $keputusan = $klienMurobi->request('/portal/izin_aksi.php', $formKeputusan);
    $assert($keputusan['status'] === 303, 'WEB-16 Keputusan murobi tersimpan dan dialihkan (303) [' . $keputusan['status'] . ']');

    // Token CSRF bersifat per sesi, sehingga token yang sama tetap berlaku untuk
    // percobaan keputusan kedua (halaman detail sudah tidak menampilkan formulir).
    $keputusanKedua = $formKeputusan;
    $keputusanKedua['idempotency_key'] = bin2hex(random_bytes(16));
    $keputusanKedua['hasil'] = 'Ditolak';
    $kedua = $klienMurobi->request('/portal/izin_aksi.php', $keputusanKedua);
    $assert($kedua['status'] === 409, 'WEB-17 Keputusan kedua ditolak 409 lewat HTTP [' . $kedua['status'] . ']');

    // ----------------------------------------------------------------- admin
    $klienAdmin = new Klien($baseUrl, 'admin');
    $masukAdmin = $klienAdmin->login($adminUsername, $sandi);
    $assert($masukAdmin['status'] === 302 && str_contains((string) $masukAdmin['location'], 'admin_dashboard.php'), 'WEB-18 Alur login admin V1 tidak berubah');
    foreach (['/portal/izin.php', '/portal/izin_antrean.php', '/portal/izin_buat.php', '/admin/admin_dashboard.php'] as $halaman) {
        $assert($klienAdmin->request($halaman)['status'] === 200, 'WEB-19 Admin dapat membuka ' . $halaman);
    }
    $arsip = $klienAdmin->request('/admin/admin_izin.php');
    $assert(
        $arsip['status'] === 302 && str_contains((string) $arsip['location'], '/portal/izin.php'),
        'WEB-20 Modul izin lama mengalihkan ke portal (302) [' . $arsip['status'] . ']'
    );
    $arsipId = $klienAdmin->request('/admin/admin_izin.php?id=' . $pengajuanId);
    $assert(
        $arsipId['status'] === 302 && str_contains((string) $arsipId['location'], 'izin_detail.php?id=' . $pengajuanId),
        'WEB-21 Tautan lama dengan ID dialihkan ke detail pengajuan yang sama'
    );

    // Admin pengganti tanpa alasan penggantian: ditolak 422.
    $pengajuanAdmin = izin_workflow_service()->create(
        auth_repository()->findActiveById($userPengurus) ?? [],
        [
            'santri_id' => $santri,
            'tgl_izin' => date('Y-m-d', strtotime('+15 days')),
            'tgl_kembali' => date('Y-m-d', strtotime('+16 days')),
            'alasan' => 'Pengajuan untuk uji admin pengganti',
        ],
        bin2hex(random_bytes(16)),
        ['ip' => '127.0.0.1', 'user_agent' => 'smoke']
    );
    $created['izin'][] = (int) $pengajuanAdmin['id'];
    $detailAdmin = '/portal/izin_detail.php?id=' . (int) $pengajuanAdmin['id'] . '&mode=admin';
    $halamanAdmin = $klienAdmin->request($detailAdmin);
    $assert($halamanAdmin['status'] === 200 && str_contains($halamanAdmin['body'], 'Keputusan Admin Pengganti'), 'WEB-22 Admin melihat panel keputusan Admin Pengganti');
    $formAdmin = [
        '_csrf' => $klienAdmin->csrf($detailAdmin),
        'aksi' => 'putuskan',
        'mode' => 'admin',
        'pengajuan_id' => (string) (int) $pengajuanAdmin['id'],
        'version' => $klienAdmin->field($detailAdmin, 'version'),
        'idempotency_key' => bin2hex(random_bytes(16)),
        'hasil' => 'Disetujui',
        'alasan' => 'Keperluan mendesak',
        'alasan_penggantian' => '',
    ];
    $tanpaAlasan = $klienAdmin->request('/portal/izin_aksi.php', $formAdmin);
    $assert($tanpaAlasan['status'] === 422, 'WEB-23 Admin Pengganti tanpa alasan penggantian ditolak 422 [' . $tanpaAlasan['status'] . ']');

    $formAdmin['_csrf'] = $klienAdmin->csrf($detailAdmin);
    $formAdmin['idempotency_key'] = bin2hex(random_bytes(16));
    $formAdmin['alasan_penggantian'] = 'Murobi berhalangan hingga besok';
    $denganAlasan = $klienAdmin->request('/portal/izin_aksi.php', $formAdmin);
    $assert($denganAlasan['status'] === 303, 'WEB-24 Keputusan Admin Pengganti dengan alasan berhasil (303) [' . $denganAlasan['status'] . ']');

    // ------------------------------------------------------------- orang tua
    $klienOrtu = new Klien($baseUrl, 'ortu');
    $klienOrtu->login('web.o1.' . $lower, $sandi);
    $detailOrtu = $klienOrtu->request('/portal/izin_detail.php?id=' . $pengajuanId);
    $assert($detailOrtu['status'] === 200, 'WEB-25 Orang tua dapat membuka izin santri yang terhubung');
    $assert(
        !str_contains($detailOrtu['body'], 'name="aksi" value="putuskan"')
        && !str_contains($detailOrtu['body'], 'name="aksi" value="batalkan"'),
        'WEB-26 Halaman orang tua tidak memuat satu pun formulir mutasi'
    );
    $assert($klienOrtu->request('/portal/izin_buat.php')['status'] === 403, 'WEB-27 Orang tua ditolak 403 pada halaman buat pengajuan');

    $klienOrtuLain = new Klien($baseUrl, 'ortu-lain');
    $klienOrtuLain->login('web.o2.' . $lower, $sandi);
    $silangOrtu = $klienOrtuLain->request('/portal/izin_detail.php?id=' . $pengajuanId);
    $assert($silangOrtu['status'] === 403, 'WEB-28 Orang tua lain menerima 403 untuk santri yang tidak terhubung [' . $silangOrtu['status'] . ']');
} catch (Throwable $exception) {
    $failures[] = 'Kesalahan tak terduga: ' . $exception->getMessage();
    echo '[gagal] ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine() . PHP_EOL;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $db->query('SET FOREIGN_KEY_CHECKS=0');
    $idsIzin = array_values(array_unique(array_filter(array_map('intval', $created['izin']))));
    if ($idsIzin !== []) {
        $daftar = implode(',', $idsIzin);
        $db->query('DELETE FROM audit_logs WHERE entity_type = \'izin_pengajuan\' AND entity_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_keputusan_koreksi WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_keputusan WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_riwayat_status WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_idempotency_keys WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_pengajuan WHERE id IN (' . $daftar . ')');
    }
    $idsUser = array_values(array_filter(array_map('intval', $created['users'])));
    if ($idsUser !== []) {
        $db->query('DELETE FROM izin_idempotency_keys WHERE user_id IN (' . implode(',', $idsUser) . ')');
    }
    $cleanup = [
        'pembimbing_assignments' => ['id', $created['pembimbing']],
        'murobi_assignments' => ['id', $created['murobi']],
        'santri_wali' => ['id', $created['santri_wali']],
        'plotting_kamar' => ['id', $created['plotting_kamar']],
        'user_roles' => ['user_id', $created['users']],
        'users' => ['id', $created['users']],
        'wali' => ['id', $created['wali']],
        'pengurus' => ['id', $created['pengurus']],
        'guru' => ['id', $created['guru']],
        'santri' => ['id', $created['santri']],
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
        AND action IN ('pembimbing_assignment_created','login_success','login_failed')");
    $db->query('SET FOREIGN_KEY_CHECKS=1');
    echo '[bersih] Fixture smoke test dihapus.' . PHP_EOL;
}

exit($failures === [] ? 0 : 1);
