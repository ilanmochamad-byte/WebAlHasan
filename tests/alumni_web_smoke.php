<?php

declare(strict_types=1);

/**
 * Smoke test web "Koreksi Pengelolaan Alumni"
 * (keputusan pengguna 6 September 2026).
 *
 * Yang hanya terlihat lewat HTTP dan karena itu diuji di sini:
 *
 *   AW-1  halaman alumni dan kelulusan tertutup bagi yang belum masuk;
 *   AW-2  pengguna non-admin (guru) DITOLAK, bukan sekadar tidak melihat menu;
 *   AW-3  admin melihat halaman lengkap dengan menu aktif dan breadcrumb;
 *   AW-4  POST tanpa token CSRF ditolak dan tidak mengubah data;
 *   AW-5  alamat lama `?hapus=ID` ditolak 405 dan TIDAK menghapus apa pun;
 *   AW-6  aksi proses lewat GET ditolak 405;
 *   AW-7  alamat lama proses_mutasi_alumni.php mengalihkan GET, MENOLAK POST 410;
 *   AW-8  alur nyata individual: tinjau lalu terapkan membuat catatan alumni;
 *   AW-9  mengirim ulang tinjauan yang sama tidak memproses dua kali;
 *   AW-10 alur nyata massal per kelas;
 *   AW-11 arsip dan pemulihan lewat POST beralasan;
 *   AW-12 nilai berbahaya pada filter dan pada data di-escape di HTML;
 *   AW-13 tidak ada respons yang membocorkan query atau jejak galat internal;
 *   AW-14 tautan "Luluskan / Mutasi keluar" tersedia dari Master Data Santri.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   ALUMNI_RUN_WEB=1 php tests/alumni_web_smoke.php
 */

$root = dirname(__DIR__);
if (getenv('ALUMNI_RUN_WEB') !== '1') {
    fwrite(STDOUT, "[lewati] Set ALUMNI_RUN_WEB=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

require_once $root . '/app/bootstrap.php';

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
final class KlienAlumni
{
    private string $jar;

    public function __construct(private string $baseUrl, string $label)
    {
        $this->jar = (string) tempnam(sys_get_temp_dir(), 'alumni-' . $label . '-');
    }

    /**
     * @param array<string, mixed>|null $post
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
        // Halaman yang tidak memuat formulir POST tetap menyediakan token lewat
        // kerangka bersama, sama seperti yang dipakai peramban sungguhan.
        if (preg_match('/window\.ALHASAN_CSRF = "([^"]+)"/', $response['body'], $cocok) === 1) {
            return $cocok[1];
        }
        throw new RuntimeException('Token CSRF tidak ditemukan pada ' . $path . ' (status ' . $response['status'] . ')');
    }
}

$db = app_db();
$port = (int) (getenv('ALUMNI_WEB_PORT') ?: 8943);
$baseUrl = 'http://127.0.0.1:' . $port;
$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$kecil = strtolower($suffix);
$sandiAdmin = 'UjiAlumniWeb123Aa';
$sandiGuru = 'UjiGuruWeb123Aa';

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
$satu = static function (string $sql) use ($db): array {
    $rs = $db->query($sql);

    return ($rs && $row = $rs->fetch_assoc()) ? $row : [];
};
$angka = static fn (string $sql): int => (int) ($satu($sql)['n'] ?? 0);

$dibuat = ['users' => [], 'guru' => [], 'santri' => [], 'kelas' => [], 'alumni' => []];
$server = null;

try {
    $adminId = $exec(
        'INSERT INTO users (name, username, password, is_active, force_password_change, created_at, updated_at)
         VALUES (?, ?, ?, 1, 0, NOW(), NOW())',
        ['Admin Web Alumni ' . $suffix, 'aw.admin.' . $kecil, password_hash($sandiAdmin, PASSWORD_DEFAULT)]
    );
    $dibuat['users'][] = $adminId;
    $exec("INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = 'admin'", [$adminId, $adminId]);

    // Akun GURU: bukan admin. Dipakai membuktikan penolakan otorisasi.
    $guruId = $exec(
        "INSERT INTO guru (nama_guru, nip, no_hp, status, is_active, created_at, updated_at)
         VALUES (?, ?, '081900000000', 'Guru', 1, NOW(), NOW())",
        ['Guru Web Alumni ' . $suffix, 'NIPAW' . $suffix]
    );
    $dibuat['guru'][] = $guruId;
    $akunGuruId = $exec(
        'INSERT INTO users (name, username, password, guru_id, is_active, force_password_change, created_at, updated_at)
         VALUES (?, ?, ?, ?, 1, 0, NOW(), NOW())',
        ['Akun Guru Alumni ' . $suffix, 'aw.guru.' . $kecil, password_hash($sandiGuru, PASSWORD_DEFAULT), $guruId]
    );
    $dibuat['users'][] = $akunGuruId;
    $exec("INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = 'guru'", [$akunGuruId, $adminId]);

    $year = alumni_service()->activeYear();
    if ($year === null) {
        fwrite(STDERR, "Tahun ajaran aktif tidak tersedia.\n");
        exit(2);
    }
    $yearId = (int) $year['id'];

    $kelasWeb = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active, created_at, updated_at) VALUES (?, 'Uji', 1, NOW(), NOW())", ['AW Kelas ' . $suffix]);
    $dibuat['kelas'][] = $kelasWeb;

    $santri = [];
    foreach ([1, 2, 3] as $i) {
        $santri[$i] = $exec(
            "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi,
                                 nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, foto, is_active, created_at, updated_at)
             VALUES (?, ?, 'L', 'Ciamis', '2011-04-01', 'Jl Uji', 'Desa', 'Kec', 'Kab', 'Prov', 'Ayah', 'Ibu', 'SD', ?, 'default.jpg', 1, NOW(), NOW())",
            ['AW' . $suffix . $i, 'Santri Alumni Web ' . $i . ' ' . $suffix, 'MTs Web ' . $suffix]
        );
        $dibuat['santri'][] = $santri[$i];
        $exec(
            "INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, tanggal_mulai, status, created_at, updated_at)
             VALUES (?, ?, ?, '2026-07-01', 'Aktif', NOW(), NOW())",
            [$santri[$i], $kelasWeb, $yearId]
        );
    }

    // Catatan alumni WARISAN yang namanya memuat muatan XSS.
    $alumniXss = $exec(
        "INSERT INTO alumni (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota,
                             provinsi, nama_ayah, nama_ibu, asal_sekolah, unit_terakhir, tahun_angkatan, tingkat,
                             status_keluar, tgl_keluar, foto)
         VALUES (?, ?, 'L', 'X', '2005-01-01', ?, 'X', 'X', 'X', 'X', 'X', 'X', 'X', 'X', '2015', 'Ibtida', 'Lulus', '2015-06-30', 'default.jpg')",
        ['XW' . $suffix, "<script>alert('aw')</script>" . $suffix, "\"><img src=x onerror=alert(1)>"]
    );
    $dibuat['alumni'][] = $alumniXss;

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

    // ================================================================= AW-1
    $tamu = new KlienAlumni($baseUrl, 'tamu');
    foreach ([
        '/admin/admin_alumni.php' => 'arsip alumni',
        '/admin/admin_kelulusan_santri.php' => 'kelulusan & mutasi',
    ] as $path => $label) {
        $tertutup = $tamu->request($path);
        $assert(
            $tertutup['status'] !== 200 && !str_contains($tertutup['body'], 'Daftar alumni'),
            'AW-1 halaman ' . $label . ' tertutup bagi yang belum masuk [' . $tertutup['status'] . ']'
        );
    }
    $tamuHapus = $tamu->request('/admin/admin_alumni.php?hapus=' . $alumniXss);
    $assert(
        $tamuHapus['status'] !== 200 && $angka('SELECT COUNT(*) n FROM alumni WHERE id = ' . $alumniXss) === 1,
        'AW-1 tamu tidak dapat memicu alamat hapus lama dan datanya tetap ada [' . $tamuHapus['status'] . ']'
    );

    // ================================================================= AW-2
    $guru = new KlienAlumni($baseUrl, 'guru');
    $tokenGuru = $guru->csrf('/portal/index.php');
    $masukGuru = $guru->request('/admin/cek_login.php', ['_csrf' => $tokenGuru, 'username' => 'aw.guru.' . $kecil, 'password' => $sandiGuru]);
    $assert($masukGuru['status'] === 302, 'AW-2a akun guru berhasil masuk [' . $masukGuru['status'] . ']');
    foreach (['/admin/admin_alumni.php', '/admin/admin_kelulusan_santri.php', '/admin/proses_mutasi_alumni.php'] as $path) {
        $ditolak = $guru->request($path);
        $assert(
            $ditolak['status'] !== 200 || !str_contains($ditolak['body'], 'Daftar alumni'),
            'AW-2b guru DITOLAK membuka ' . $path . ' [' . $ditolak['status'] . ']'
        );
    }
    $sebelumGuru = $angka('SELECT COUNT(*) n FROM alumni');
    $guruPost = $guru->request('/admin/admin_alumni.php', ['action' => 'arsip', 'id' => $alumniXss, 'alasan' => 'coba tembus']);
    $assert(
        $guruPost['status'] !== 200
        && $angka('SELECT COUNT(*) n FROM alumni WHERE id = ' . $alumniXss . ' AND archived_at IS NULL') === 1
        && $angka('SELECT COUNT(*) n FROM alumni') === $sebelumGuru,
        'AW-2c POST guru ke aksi alumni tidak mengubah data [' . $guruPost['status'] . ']'
    );

    // ================================================================= AW-3
    $klien = new KlienAlumni($baseUrl, 'admin');
    $token = $klien->csrf('/portal/index.php');
    $masuk = $klien->request('/admin/cek_login.php', ['_csrf' => $token, 'username' => 'aw.admin.' . $kecil, 'password' => $sandiAdmin]);
    $assert($masuk['status'] === 302, 'AW-3a admin uji berhasil masuk [' . $masuk['status'] . ']');

    $halaman = $klien->request('/admin/admin_alumni.php');
    $assert($halaman['status'] === 200, 'AW-3b halaman alumni terbuka untuk admin [' . $halaman['status'] . ']');
    $assert(str_contains($halaman['body'], 'Daftar alumni'), 'AW-3c daftar alumni tampil');
    $assert(preg_match('#<a href="[^"]*admin_alumni\.php" aria-current="page">#', $halaman['body']) === 1, 'AW-3d menu Data Alumni ditandai aktif');
    $assert(str_contains($halaman['body'], 'ah-crumbs'), 'AW-3e breadcrumb kerangka bersama tampil');
    $assert(str_contains($halaman['body'], 'ah-sidebar'), 'AW-3f sidebar aplikasi tampil (bukan halaman bergaya sendiri)');
    $assert(!str_contains($halaman['body'], 'datatables'), 'AW-3g halaman tidak lagi memuat DataTables lama');

    $halamanProses = $klien->request('/admin/admin_kelulusan_santri.php');
    $assert($halamanProses['status'] === 200, 'AW-3h halaman kelulusan terbuka untuk admin [' . $halamanProses['status'] . ']');
    $assert(preg_match('#<a href="[^"]*admin_kelulusan_santri\.php" aria-current="page">#', $halamanProses['body']) === 1, 'AW-3i menu Kelulusan & Mutasi ditandai aktif');

    // ================================================================= AW-14
    $master = $klien->request('/admin/admin_master_santri.php?q=AW' . $suffix);
    $assert(
        str_contains($master['body'], 'admin_kelulusan_santri.php?santri_id=' . $santri[1]),
        'AW-14 tautan "Luluskan / Mutasi keluar" tersedia pada baris santri aktif'
    );

    // ================================================================= AW-4
    $tanpaCsrf = $klien->request('/admin/admin_alumni.php', ['action' => 'arsip', 'id' => $alumniXss, 'alasan' => 'tanpa csrf sama sekali']);
    $assert($tanpaCsrf['status'] === 419, 'AW-4a POST tanpa token CSRF ditolak [' . $tanpaCsrf['status'] . ']');
    $assert(
        $angka('SELECT COUNT(*) n FROM alumni WHERE id = ' . $alumniXss . ' AND archived_at IS NULL') === 1,
        'AW-4b POST tanpa CSRF tidak mengubah satu baris pun'
    );
    $csrfPalsu = $klien->request('/admin/admin_kelulusan_santri.php', [
        '_csrf' => 'token-palsu', 'tahap' => 'terapkan', 'santri_ids' => [$santri[1]],
        'status_keluar' => 'Lulus', 'tgl_keluar' => '2026-06-30', 'tahun_angkatan' => '2026', 'tingkat' => 'Ibtida',
    ]);
    $assert($csrfPalsu['status'] === 419, 'AW-4c token CSRF palsu ditolak [' . $csrfPalsu['status'] . ']');
    $assert($angka("SELECT COUNT(*) n FROM alumni WHERE santri_id = {$santri[1]}") === 0, 'AW-4d token palsu tidak memproses santri');

    // ================================================================= AW-5
    $hapusLama = $klien->request('/admin/admin_alumni.php?hapus=' . $alumniXss);
    $assert($hapusLama['status'] === 405, 'AW-5a alamat hapus permanen lama ditolak 405 [' . $hapusLama['status'] . ']');
    $assert(
        $angka('SELECT COUNT(*) n FROM alumni WHERE id = ' . $alumniXss) === 1,
        'AW-5b alamat hapus lama TIDAK menghapus catatan alumni'
    );
    $assert(str_contains($hapusLama['body'], 'Arsipkan'), 'AW-5c pesan penolakan menunjukkan tindakan pengganti yang benar');

    // ================================================================= AW-6
    $prosesGet = $klien->request('/admin/admin_kelulusan_santri.php?action=terapkan&santri_id=' . $santri[1]);
    $assert($prosesGet['status'] === 405, 'AW-6 aksi proses lewat GET ditolak 405 [' . $prosesGet['status'] . ']');

    // ================================================================= AW-7
    $alihGet = $klien->request('/admin/proses_mutasi_alumni.php?id_kelas=' . $kelasWeb);
    $assert(
        $alihGet['status'] === 301 && str_contains((string) $alihGet['location'], 'admin_kelulusan_santri.php')
        && str_contains((string) $alihGet['location'], 'kelas_id=' . $kelasWeb),
        'AW-7a alamat lama mengalihkan GET beserta pemetaan kelasnya [' . $alihGet['status'] . ']'
    );
    $sebelumLama = $angka('SELECT COUNT(*) n FROM alumni');
    $alihPost = $klien->request('/admin/proses_mutasi_alumni.php', [
        '_csrf' => $klien->csrf('/admin/admin_alumni.php'), 'bulk_mutasi' => 1, 'id_kelas' => $kelasWeb,
        'tahun_angkatan' => '2026', 'tingkat' => 'Ibtida', 'status_keluar' => 'Lulus', 'tgl_keluar' => '2026-06-30',
    ]);
    $assert($alihPost['status'] === 410, 'AW-7b alamat lama MENOLAK POST dengan 410 [' . $alihPost['status'] . ']');
    $assert($angka('SELECT COUNT(*) n FROM alumni') === $sebelumLama, 'AW-7c POST ke alamat lama tidak memproses satu santri pun');

    // ================================================================= AW-8
    $formIndividu = $klien->request('/admin/admin_kelulusan_santri.php?santri_id=' . $santri[1]);
    $assert(
        $formIndividu['status'] === 200 && str_contains($formIndividu['body'], 'Santri Alumni Web 1 ' . $suffix)
        && str_contains($formIndividu['body'], 'AW Kelas ' . $suffix),
        'AW-8a formulir individual menampilkan ringkasan santri dan kelas aktifnya'
    );
    $csrf = $klien->csrf('/admin/admin_kelulusan_santri.php?santri_id=' . $santri[1]);
    $tinjau = $klien->request('/admin/admin_kelulusan_santri.php', [
        '_csrf' => $csrf, 'tahap' => 'tinjau', 'sumber' => 'santri', 'santri_id' => $santri[1],
        'santri_ids' => [$santri[1]], 'status_keluar' => 'Lulus', 'tgl_keluar' => '2026-06-30',
        'tahun_angkatan' => '2025/2026', 'tingkat' => 'Tsanawi', 'catatan' => 'Uji web ' . $suffix,
    ]);
    $assert(
        $tinjau['status'] === 200 && str_contains($tinjau['body'], 'Konfirmasi kelulusan')
        && str_contains($tinjau['body'], 'name="tahap" value="terapkan"'),
        'AW-8b tahap tinjau menampilkan layar konfirmasi [' . $tinjau['status'] . ']'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM alumni WHERE santri_id = {$santri[1]}") === 0,
        'AW-8c tahap tinjau TIDAK mengubah data apa pun'
    );
    preg_match('/name="form_token" value="([^"]+)"/', $tinjau['body'], $cocokToken);
    $formToken = $cocokToken[1] ?? '';
    $assert($formToken !== '', 'AW-8d layar konfirmasi memuat token sekali pakai');

    $terapkanPost = [
        '_csrf' => $csrf, 'tahap' => 'terapkan', 'form_token' => $formToken, 'sumber' => 'santri',
        'santri_id' => $santri[1], 'kelas_id' => 0, 'santri_ids' => [$santri[1]],
        'status_keluar' => 'Lulus', 'tgl_keluar' => '2026-06-30', 'tahun_angkatan' => '2025/2026',
        'tingkat' => 'Tsanawi', 'catatan' => 'Uji web ' . $suffix,
    ];
    $terapkan = $klien->request('/admin/admin_kelulusan_santri.php', $terapkanPost);
    $barisBaru = $satu("SELECT * FROM alumni WHERE santri_id = {$santri[1]}");
    $dibuat['alumni'][] = (int) ($barisBaru['id'] ?? 0);
    $assert($terapkan['status'] === 302, 'AW-8e penerapan mengalihkan setelah berhasil (POST-redirect-GET) [' . $terapkan['status'] . ']');
    $assert(
        $barisBaru !== [] && ($barisBaru['status_keluar'] ?? '') === 'Lulus'
        && (int) ($barisBaru['created_by'] ?? 0) === $adminId,
        'AW-8f catatan alumni tercipta dengan pelaku yang benar'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM santri WHERE id = {$santri[1]} AND is_active = 0 AND archived_at IS NOT NULL") === 1,
        'AW-8g santri sumber diarsipkan, bukan dihapus'
    );
    $assert(
        str_contains((string) $terapkan['location'], 'admin_alumni.php?action=detail&id=' . (int) $barisBaru['id']),
        'AW-8h admin diarahkan ke detail alumni yang dapat diverifikasi'
    );

    // ================================================================= AW-9
    $ulang = $klien->request('/admin/admin_kelulusan_santri.php', $terapkanPost);
    $assert($ulang['status'] === 302, 'AW-9a pengiriman ulang dijawab pengalihan, bukan galat [' . $ulang['status'] . ']');
    $assert(
        $angka("SELECT COUNT(*) n FROM alumni WHERE santri_id = {$santri[1]}") === 1,
        'AW-9b pengiriman ulang tinjauan TIDAK membuat catatan alumni kedua'
    );

    // ================================================================ AW-10
    $formMassal = $klien->request('/admin/admin_kelulusan_santri.php?kelas_id=' . $kelasWeb);
    $assert(
        $formMassal['status'] === 200 && str_contains($formMassal['body'], 'Santri Alumni Web 2 ' . $suffix)
        && str_contains($formMassal['body'], 'Santri Alumni Web 3 ' . $suffix)
        && !str_contains($formMassal['body'], 'Santri Alumni Web 1 ' . $suffix),
        'AW-10a daftar massal per kelas mengecualikan santri yang sudah menjadi alumni'
    );
    $csrfMassal = $klien->csrf('/admin/admin_kelulusan_santri.php?kelas_id=' . $kelasWeb);
    $tinjauMassal = $klien->request('/admin/admin_kelulusan_santri.php', [
        '_csrf' => $csrfMassal, 'tahap' => 'tinjau', 'sumber' => 'kelas', 'kelas_id' => $kelasWeb,
        'santri_ids' => [$santri[2], $santri[3]], 'status_keluar' => 'Lulus', 'tgl_keluar' => '2026-06-30',
        'tahun_angkatan' => '2025/2026', 'tingkat' => 'Tsanawi', 'catatan' => 'Massal web ' . $suffix,
    ]);
    $assert(
        str_contains($tinjauMassal['body'], 'memengaruhi SELURUH 2 santri'),
        'AW-10b layar konfirmasi massal memperingatkan cakupan tindakan'
    );
    preg_match('/name="form_token" value="([^"]+)"/', $tinjauMassal['body'], $cocokMassal);
    $terapkanMassal = $klien->request('/admin/admin_kelulusan_santri.php', [
        '_csrf' => $csrfMassal, 'tahap' => 'terapkan', 'form_token' => $cocokMassal[1] ?? '', 'sumber' => 'kelas',
        'kelas_id' => $kelasWeb, 'santri_id' => 0, 'santri_ids' => [$santri[2], $santri[3]],
        'status_keluar' => 'Lulus', 'tgl_keluar' => '2026-06-30', 'tahun_angkatan' => '2025/2026',
        'tingkat' => 'Tsanawi', 'catatan' => 'Massal web ' . $suffix,
    ]);
    $assert($terapkanMassal['status'] === 302, 'AW-10c penerapan massal berhasil [' . $terapkanMassal['status'] . ']');
    $assert(
        $angka("SELECT COUNT(*) n FROM alumni WHERE santri_id IN ({$santri[2]}, {$santri[3]})") === 2
        && $angka("SELECT COUNT(*) n FROM plotting_kelas WHERE id_kelas = {$kelasWeb} AND id_tahun = {$yearId} AND status = 'Aktif'") === 0,
        'AW-10d seluruh santri kelas diproses dan kelas aktifnya ditutup'
    );

    // ================================================================ AW-11
    $alumniSatu = (int) $barisBaru['id'];
    $csrfDetail = $klien->csrf('/admin/admin_alumni.php?action=detail&id=' . $alumniSatu);
    $arsipTanpaAlasan = $klien->request('/admin/admin_alumni.php', ['_csrf' => $csrfDetail, 'action' => 'arsip', 'id' => $alumniSatu, 'alasan' => '']);
    $assert($arsipTanpaAlasan['status'] === 422, 'AW-11a arsip tanpa alasan ditolak 422 [' . $arsipTanpaAlasan['status'] . ']');
    $assert($angka('SELECT COUNT(*) n FROM alumni WHERE id = ' . $alumniSatu . ' AND archived_at IS NULL') === 1, 'AW-11b penolakan alasan tidak mengarsipkan');

    $arsip = $klien->request('/admin/admin_alumni.php', ['_csrf' => $csrfDetail, 'action' => 'arsip', 'id' => $alumniSatu, 'alasan' => 'Uji arsip web ' . $suffix]);
    $assert($arsip['status'] === 302, 'AW-11c arsip beralasan berhasil [' . $arsip['status'] . ']');
    $assert(
        $angka('SELECT COUNT(*) n FROM alumni WHERE id = ' . $alumniSatu . ' AND archived_at IS NOT NULL') === 1
        && $angka('SELECT COUNT(*) n FROM alumni WHERE id = ' . $alumniSatu) === 1,
        'AW-11d catatan diarsipkan TANPA dihapus'
    );
    $pulih = $klien->request('/admin/admin_alumni.php', ['_csrf' => $csrfDetail, 'action' => 'pulihkan', 'id' => $alumniSatu, 'alasan' => 'Uji pulih web ' . $suffix]);
    $assert(
        $pulih['status'] === 302 && $angka('SELECT COUNT(*) n FROM alumni WHERE id = ' . $alumniSatu . ' AND archived_at IS NULL') === 1,
        'AW-11e catatan dapat dipulihkan kembali [' . $pulih['status'] . ']'
    );

    // ================================================================ AW-12
    $filterJahat = $klien->request('/admin/admin_alumni.php?q=' . rawurlencode("' OR 1=1 -- <script>alert(1)</script>") . '&state=all');
    $assert($filterJahat['status'] === 200, 'AW-12a filter berisi muatan SQL/XSS tetap dijawab 200 [' . $filterJahat['status'] . ']');
    $assert(!str_contains($filterJahat['body'], '<script>alert(1)</script>'), 'AW-12b muatan XSS pada filter tidak muncul mentah di HTML');
    $assert(str_contains($filterJahat['body'], '&lt;script&gt;'), 'AW-12c muatan XSS pada filter di-escape');

    $detailJahat = $klien->request('/admin/admin_alumni.php?action=detail&id=' . $alumniXss);
    $assert($detailJahat['status'] === 200, 'AW-12d detail alumni bermuatan XSS tetap terbuka [' . $detailJahat['status'] . ']');
    $assert(!str_contains($detailJahat['body'], "<script>alert('aw')</script>"), 'AW-12e nama bermuatan XSS tidak muncul mentah');
    $assert(!str_contains($detailJahat['body'], '<img src=x onerror=alert(1)>'), 'AW-12f alamat bermuatan XSS tidak muncul mentah');
    $assert(str_contains($detailJahat['body'], 'Belum terhubung'), 'AW-12g catatan warisan ditandai belum terhubung ke santri');

    // ================================================================ AW-13
    foreach ([$halaman, $filterJahat, $detailJahat, $arsipTanpaAlasan, $hapusLama, $prosesGet] as $index => $respons) {
        $bocor = false;
        foreach (['SELECT ', 'INSERT INTO', 'mysqli', 'Stack trace', '/app/MasterData/', 'SQLSTATE'] as $jejak) {
            if (str_contains($respons['body'], $jejak)) {
                $bocor = true;
            }
        }
        $assert(!$bocor, 'AW-13 respons #' . $index . ' tidak membocorkan query atau jejak galat internal');
    }
} catch (Throwable $exception) {
    echo '[gagal] Smoke test berhenti: ' . $exception->getMessage() . PHP_EOL;
    $failures[] = 'smoke test berhenti: ' . $exception->getMessage();
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    try {
        foreach ($dibuat['santri'] as $id) {
            $db->query('DELETE FROM alumni WHERE santri_id = ' . (int) $id);
        }
        foreach ($dibuat['alumni'] as $id) {
            $db->query('DELETE FROM alumni WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['santri'] as $id) {
            $db->query('DELETE FROM plotting_kamar WHERE id_santri = ' . (int) $id);
            $db->query('DELETE FROM plotting_kelas WHERE id_santri = ' . (int) $id);
            $db->query('DELETE FROM santri WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['kelas'] as $id) {
            $db->query('DELETE FROM plotting_kelas WHERE id_kelas = ' . (int) $id);
            $db->query('DELETE FROM kelas WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['users'] as $id) {
            $db->query('DELETE FROM user_roles WHERE user_id = ' . (int) $id);
            $db->query('DELETE FROM audit_logs WHERE actor_user_id = ' . (int) $id);
            $db->query('UPDATE alumni SET created_by = NULL, updated_by = NULL WHERE created_by = ' . (int) $id . ' OR updated_by = ' . (int) $id);
            $db->query('DELETE FROM users WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['guru'] as $id) {
            $db->query('DELETE FROM guru WHERE id = ' . (int) $id);
        }
    } catch (Throwable $exception) {
        echo '[gagal] Bersih-bersih fixture: ' . $exception->getMessage() . PHP_EOL;
        $failures[] = 'bersih-bersih fixture';
    }
}

echo PHP_EOL;
if ($failures === []) {
    echo 'SELURUH SMOKE TEST WEB ALUMNI LULUS.' . PHP_EOL;
    exit(0);
}
echo 'GAGAL (' . count($failures) . '):' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;
exit(1);
