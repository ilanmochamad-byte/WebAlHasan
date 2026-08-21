<?php

declare(strict_types=1);

use App\Auth\Capabilities;

/**
 * Pengujian navigasi murobi (hotfix V2 Fase 2).
 *
 * Membuktikan lewat HTTP sungguhan bahwa:
 *   - guru dengan capability `murobi` mendarat di antrean keputusan setelah login,
 *   - guru tanpa capability murobi tetap mendarat di jadwal mengajar,
 *   - halaman jadwal menampilkan tautan antrean HANYA kepada murobi aktif,
 *   - portal menyediakan jalan kembali ke jadwal bagi akun ber-role guru,
 *   - murobi dapat bolak-balik jadwal ⇄ antrean tanpa login ulang,
 *   - guru non-murobi tetap menerima 403 saat memaksa membuka portal perizinan,
 *   - antrean murobi hanya memuat pengajuan `Diajukan` miliknya sendiri,
 *   - pengarahan admin, pengurus, dan orang tua tidak mengalami regresi.
 *
 * Capability adalah sumber kebenaran; role `guru` saja TIDAK pernah cukup.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   V2_PHASE2_RUN_NAV=1 php tests/v2_phase2_navigasi_murobi.php
 */

$root = dirname(__DIR__);
if (getenv('V2_PHASE2_RUN_NAV') !== '1') {
    fwrite(STDOUT, "[lewati] Set V2_PHASE2_RUN_NAV=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}
require_once $root . '/app/bootstrap.php';
if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Pengujian navigasi ditolak: DB_NAME wajib berakhiran _test.\n");
    exit(2);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "Ekstensi curl dibutuhkan untuk pengujian navigasi.\n");
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

$port = (int) (getenv('V2_PHASE2_NAV_PORT') ?: 8714);
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
final class KlienNav
{
    private string $jar;

    public function __construct(private string $baseUrl, string $label)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'nav-' . $label . '-');
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

    public function login(string $username, string $password): array
    {
        $halaman = $this->request('/admin/admin_login.php');
        if (preg_match('/name="_csrf" value="([^"]+)"/', $halaman['body'], $matches) !== 1) {
            throw new RuntimeException('Token CSRF tidak ditemukan pada halaman masuk (status ' . $halaman['status'] . ').');
        }

        return $this->request('/admin/cek_login.php', [
            '_csrf' => $matches[1],
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
        "SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
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

    $kamarMurobi = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 20)', ['Kamar Nav A ' . $suffix]);
    $kamarLain = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 20)', ['Kamar Nav B ' . $suffix]);
    $created['kamar'] = [$kamarMurobi, $kamarLain];

    $santriSql = "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan,
                    kab_kota, provinsi, nama_ayah, no_hp_ayah, nama_ibu, no_hp_ibu, asal_sekolah, sekolah_saat_ini, is_active)
                  VALUES (?, ?, 'L', 'Ciamis', '2010-01-01', 'Alamat', 'Desa', 'Kec', 'Ciamis', 'Jabar', '', NULL, '', NULL, 'A', 'B', 1)";
    $santriA = $exec($santriSql, ['NAV1' . $suffix, 'Santri Nav A ' . $suffix]);
    $santriB = $exec($santriSql, ['NAV2' . $suffix, 'Santri Nav B ' . $suffix]);
    $created['santri'] = [$santriA, $santriB];
    $created['plotting_kamar'][] = $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santriA, $kamarMurobi, $yearId]);
    $created['plotting_kamar'][] = $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santriB, $kamarLain, $yearId]);

    $guruMurobi = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['NG1' . $suffix, 'Guru Murobi Nav ' . $suffix]);
    $guruMurobiLain = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['NG2' . $suffix, 'Guru Murobi Lain Nav ' . $suffix]);
    $guruBiasa = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['NG3' . $suffix, 'Guru Biasa Nav ' . $suffix]);
    $created['guru'] = [$guruMurobi, $guruMurobiLain, $guruBiasa];

    $murobiSql = "INSERT INTO murobi_assignments (guru_id, tahun_ajaran_id, target_type, kamar_id, tanggal_mulai, is_active)
                  VALUES (?, ?, 'Kamar', ?, DATE_SUB(CURDATE(), INTERVAL 30 DAY), 1)";
    $created['murobi'][] = $exec($murobiSql, [$guruMurobi, $yearId, $kamarMurobi]);
    $created['murobi'][] = $exec($murobiSql, [$guruMurobiLain, $yearId, $kamarLain]);

    $pengurus = $exec("INSERT INTO pengurus (nama, nomor_identitas, jabatan, is_active) VALUES (?, ?, 'Keamanan', 1)", ['Pengurus Nav ' . $suffix, 'NPA' . $suffix]);
    $created['pengurus'][] = $pengurus;
    $wali = $exec('INSERT INTO wali (nama, no_hp, is_active) VALUES (?, ?, 1)', ['Wali Nav ' . $suffix, '081500000001']);
    $created['wali'][] = $wali;
    $created['santri_wali'][] = $exec("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary) VALUES (?, ?, 'Ayah', 1)", [$santriA, $wali]);

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

    $userMurobi = $makeUser('nav.m1.' . $lower, 'Akun Murobi Nav', $guruMurobi, null, null, 'guru');
    $userMurobiLain = $makeUser('nav.m2.' . $lower, 'Akun Murobi Lain Nav', $guruMurobiLain, null, null, 'guru');
    $userGuruBiasa = $makeUser('nav.gb.' . $lower, 'Akun Guru Biasa Nav', $guruBiasa, null, null, 'guru');
    $userPengurus = $makeUser('nav.pa.' . $lower, 'Akun Pengurus Nav', null, $pengurus, null, 'pengurus');
    $userOrtu = $makeUser('nav.o1.' . $lower, 'Akun Ortu Nav', null, null, $wali, 'orang_tua');
    $userAdmin = $makeUser('nav.ad.' . $lower, 'Akun Admin Nav', null, null, null, 'admin');
    $created['users'] = [$userMurobi, $userMurobiLain, $userGuruBiasa, $userPengurus, $userOrtu, $userAdmin];

    $created['pembimbing'][] = pembimbing_service()->create([
        'pengurus_id' => $pengurus,
        'tahun_ajaran_id' => $yearId,
        'target_type' => 'Kamar',
        'kamar_id' => $kamarMurobi,
        'tanggal_mulai' => date('Y-m-d', strtotime('-10 days')),
        'tanggal_selesai' => '',
    ], $adminId);

    // Tiga pengajuan untuk menguji isi antrean:
    //   A. Diajukan, murobi = akun uji          -> HARUS tampil
    //   B. Diajukan, murobi = murobi lain       -> TIDAK boleh tampil
    //   C. Disetujui, murobi = akun uji         -> TIDAK boleh tampil (bukan antrean)
    $izinSql = "INSERT INTO izin_pengajuan (santri_id, pengurus_id, diajukan_oleh_user_id, murobi_guru_id,
                    tahun_ajaran_id, tgl_izin, tgl_kembali, alasan, status, diajukan_pada, is_legacy)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0)";
    $izinMilikku = $exec($izinSql, [$santriA, $pengurus, $userPengurus, $guruMurobi, $yearId, date('Y-m-d', strtotime('+3 days')), date('Y-m-d', strtotime('+4 days')), 'Antrean milik murobi uji', 'Diajukan']);
    $izinMurobiLain = $exec($izinSql, [$santriB, $pengurus, $userPengurus, $guruMurobiLain, $yearId, date('Y-m-d', strtotime('+3 days')), date('Y-m-d', strtotime('+4 days')), 'Antrean milik murobi lain', 'Diajukan']);
    $izinSudahDiputus = $exec($izinSql, [$santriA, $pengurus, $userPengurus, $guruMurobi, $yearId, date('Y-m-d', strtotime('+20 days')), date('Y-m-d', strtotime('+21 days')), 'Sudah diputus', 'Disetujui']);
    $created['izin'] = [$izinMilikku, $izinMurobiLain, $izinSudahDiputus];

    // Capability adalah sumber kebenaran — pastikan fixture memang seperti niatnya.
    $capabilities = capabilities();
    $loadUser = static fn (int $id): array => auth_repository()->findActiveById($id) ?? throw new RuntimeException('Akun uji tidak ditemukan: ' . $id);
    $assert(
        $capabilities->forUser($loadUser($userMurobi)) === [Capabilities::MUROBI],
        'NAV-0a Akun guru dengan penugasan murobi aktif memiliki capability murobi'
    );
    $assert(
        $capabilities->forUser($loadUser($userGuruBiasa)) === [],
        'NAV-0b Akun guru tanpa penugasan murobi aktif TIDAK memiliki capability apa pun'
    );

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

    // ================================================================
    // 1. Pengarahan setelah login
    // ================================================================
    $klienMurobi = new KlienNav($baseUrl, 'murobi');
    $masukMurobi = $klienMurobi->login('nav.m1.' . $lower, $sandi);
    $assert(
        $masukMurobi['status'] === 302 && str_contains((string) $masukMurobi['location'], '/portal/izin_antrean.php?mode=murobi'),
        'NAV-1 Murobi aktif diarahkan ke antrean keputusan setelah login [' . (string) $masukMurobi['location'] . ']'
    );

    $klienGuru = new KlienNav($baseUrl, 'guru');
    $masukGuru = $klienGuru->login('nav.gb.' . $lower, $sandi);
    $assert(
        $masukGuru['status'] === 302 && str_contains((string) $masukGuru['location'], '/admin/pertemuan_pengajian.php'),
        'NAV-2 Guru tanpa capability murobi tetap diarahkan ke jadwal mengajar [' . (string) $masukGuru['location'] . ']'
    );

    $klienAdmin = new KlienNav($baseUrl, 'admin');
    $masukAdmin = $klienAdmin->login('nav.ad.' . $lower, $sandi);
    $assert(
        $masukAdmin['status'] === 302 && str_contains((string) $masukAdmin['location'], '/admin/admin_dashboard.php'),
        'NAV-3 Admin tetap diarahkan ke dashboard admin (tanpa regresi)'
    );

    $klienPengurus = new KlienNav($baseUrl, 'pengurus');
    $masukPengurus = $klienPengurus->login('nav.pa.' . $lower, $sandi);
    $assert(
        $masukPengurus['status'] === 302 && str_contains((string) $masukPengurus['location'], '/portal/index.php'),
        'NAV-4 Pengurus tetap diarahkan ke portal perizinan (tanpa regresi)'
    );

    $klienOrtu = new KlienNav($baseUrl, 'ortu');
    $masukOrtu = $klienOrtu->login('nav.o1.' . $lower, $sandi);
    $assert(
        $masukOrtu['status'] === 302 && str_contains((string) $masukOrtu['location'], '/portal/index.php'),
        'NAV-5 Orang tua tetap diarahkan ke portal perizinan (tanpa regresi)'
    );

    // Membuka kembali halaman masuk saat sesi masih hidup tidak boleh
    // mengembalikan murobi ke halaman jadwal.
    $kunjungUlang = $klienMurobi->request('/admin/admin_login.php');
    $assert(
        $kunjungUlang['status'] === 302 && str_contains((string) $kunjungUlang['location'], '/portal/izin_antrean.php?mode=murobi'),
        'NAV-6 Membuka halaman masuk saat sesi murobi aktif mengarahkan ke antrean [' . (string) $kunjungUlang['location'] . ']'
    );

    // ================================================================
    // 2. Navigasi dua arah
    // ================================================================
    $jadwalMurobi = $klienMurobi->request('/admin/pertemuan_pengajian.php');
    $assert($jadwalMurobi['status'] === 200, 'NAV-7 Murobi tetap dapat membuka jadwal mengajar tanpa login ulang');
    $assert(
        str_contains($jadwalMurobi['body'], '/portal/izin_antrean.php?mode=murobi'),
        'NAV-8 Halaman jadwal menampilkan tautan Antrean Perizinan kepada murobi aktif'
    );

    $jadwalGuru = $klienGuru->request('/admin/pertemuan_pengajian.php');
    $assert($jadwalGuru['status'] === 200, 'NAV-9 Guru non-murobi tetap dapat membuka jadwal mengajar');
    $assert(
        !str_contains($jadwalGuru['body'], '/portal/izin_antrean.php')
        && !str_contains($jadwalGuru['body'], '/portal/izin.php'),
        'NAV-10 Halaman jadwal TIDAK menampilkan tautan perizinan kepada guru non-murobi'
    );

    $antreanMurobi = $klienMurobi->request('/portal/izin_antrean.php?mode=murobi');
    $assert($antreanMurobi['status'] === 200, 'NAV-11 Murobi dapat membuka antrean keputusan');
    $assert(
        str_contains($antreanMurobi['body'], '/admin/pertemuan_pengajian.php'),
        'NAV-12 Portal menyediakan jalan kembali ke jadwal mengajar bagi akun ber-role guru'
    );

    $kembaliKeJadwal = $klienMurobi->request('/admin/pertemuan_pengajian.php');
    $assert($kembaliKeJadwal['status'] === 200, 'NAV-13 Murobi bolak-balik jadwal ⇄ antrean tanpa login ulang');

    $portalPengurus = $klienPengurus->request('/portal/index.php');
    $assert($portalPengurus['status'] === 200, 'NAV-14 Portal pengurus tetap dapat dibuka');
    $assert(
        !str_contains($portalPengurus['body'], '/admin/pertemuan_pengajian.php'),
        'NAV-15 Portal TIDAK menampilkan tautan jadwal mengajar bagi akun tanpa role guru'
    );

    // ================================================================
    // 3. Otorisasi server tetap utuh
    // ================================================================
    foreach ([
        '/portal/index.php',
        '/portal/izin.php',
        '/portal/izin_antrean.php',
        '/portal/izin_antrean.php?mode=murobi',
        '/portal/izin_buat.php',
    ] as $halaman) {
        $status = $klienGuru->request($halaman)['status'];
        $assert($status === 403, 'NAV-16 Guru tanpa penugasan murobi menerima 403 pada ' . $halaman . ' [' . $status . ']');
    }
    $assert(
        $klienMurobi->request('/portal/izin_buat.php')['status'] === 403,
        'NAV-17 Murobi tetap tidak berhak membuat pengajuan (403)'
    );
    $assert(
        $klienMurobi->request('/admin/admin_dashboard.php')['status'] === 403,
        'NAV-18 Murobi tetap tidak berhak membuka panel admin (403)'
    );

    // ================================================================
    // 4. Isi antrean murobi
    // ================================================================
    $assert(
        str_contains($antreanMurobi['body'], '#' . $izinMilikku),
        'NAV-19 Antrean memuat pengajuan `Diajukan` yang diarahkan kepada murobi ini'
    );
    $assert(
        !str_contains($antreanMurobi['body'], '#' . $izinMurobiLain),
        'NAV-20 Antrean TIDAK memuat pengajuan milik murobi lain'
    );
    $assert(
        !str_contains($antreanMurobi['body'], '#' . $izinSudahDiputus),
        'NAV-21 Antrean TIDAK memuat pengajuan yang sudah diputus'
    );

    $antreanMurobiLain = (new KlienNav($baseUrl, 'murobi-lain'));
    $antreanMurobiLain->login('nav.m2.' . $lower, $sandi);
    $isiMurobiLain = $antreanMurobiLain->request('/portal/izin_antrean.php?mode=murobi');
    $assert(
        str_contains($isiMurobiLain['body'], '#' . $izinMurobiLain)
        && !str_contains($isiMurobiLain['body'], '#' . $izinMilikku),
        'NAV-22 Tidak ada kebocoran akses antarmurobi pada antrean'
    );
    $assert(
        $antreanMurobiLain->request('/portal/izin_detail.php?id=' . $izinMilikku)['status'] === 403,
        'NAV-23 Murobi lain tetap menerima 403 pada detail pengajuan bukan miliknya'
    );

    // ================================================================
    // 5. Ganti password awal juga mengarah ke antrean bagi murobi
    // ================================================================
    $exec('UPDATE users SET force_password_change = 1 WHERE id = ?', [$userMurobi]);
    $klienPaksa = new KlienNav($baseUrl, 'murobi-paksa');
    $masukPaksa = $klienPaksa->login('nav.m1.' . $lower, $sandi);
    $assert(
        $masukPaksa['status'] === 302 && str_contains((string) $masukPaksa['location'], '/admin/ubah_password.php'),
        'NAV-24 Akun dengan password awal tetap dipaksa mengganti password lebih dulu'
    );
    $halamanUbah = $klienPaksa->request('/admin/ubah_password.php');
    if (preg_match('/name="_csrf" value="([^"]+)"/', $halamanUbah['body'], $tokenUbah) !== 1) {
        throw new RuntimeException('Token CSRF tidak ditemukan pada halaman ganti password.');
    }
    $sandiBaru = 'UjiPasswordBaru123Aa';
    $hasilUbah = $klienPaksa->request('/admin/ubah_password.php', [
        '_csrf' => $tokenUbah[1],
        'password_saat_ini' => $sandi,
        'password_baru' => $sandiBaru,
        'konfirmasi_password' => $sandiBaru,
    ]);
    $assert(
        $hasilUbah['status'] === 200 && str_contains($hasilUbah['body'], '/portal/izin_antrean.php?mode=murobi'),
        'NAV-25 Setelah ganti password awal, murobi ditawari lanjut langsung ke antrean (bukan jadwal)'
    );
    $assert(
        !str_contains($hasilUbah['body'], 'Lanjut ke tugas pengajian'),
        'NAV-26 Tawaran lanjut untuk murobi tidak lagi mengarah ke halaman jadwal'
    );
    $exec('UPDATE users SET force_password_change = 0, password = ? WHERE id = ?', [password_hash($sandi, PASSWORD_DEFAULT), $userMurobi]);
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
    echo '[bersih] Fixture uji navigasi dihapus.' . PHP_EOL;
}

exit($failures === [] ? 0 : 1);
