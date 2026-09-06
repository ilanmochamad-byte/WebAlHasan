<?php

declare(strict_types=1);

/**
 * Smoke test web "Penempatan Kelas & Kamar Santri"
 * (keputusan pengguna 6 September 2026).
 *
 * Yang hanya terlihat lewat HTTP dan karena itu diuji di sini:
 *
 *   PW-1  halaman penempatan tertutup bagi yang belum masuk;
 *   PW-2  admin melihat halaman lengkap dengan menu aktif dan breadcrumb;
 *   PW-3  POST tanpa token CSRF ditolak dan tidak mengubah data;
 *   PW-4  aksi lewat GET ditolak 405;
 *   PW-5  alamat lama mengalihkan GET beserta pemetaan filter lamanya;
 *   PW-6  alamat lama MENOLAK POST dengan 410 dan tidak mengubah data;
 *   PW-7  alur nyata: tinjau lalu terapkan mengubah penempatan;
 *   PW-8  mengirim ulang tinjauan yang sama tidak menerapkan dua kali;
 *   PW-9  kapasitas tidak cukup ditolak 422 tanpa mengubah satu baris pun;
 *   PW-10 tautan berfilter dari Data Kelas dan Data Kamar menyaring daftar;
 *   PW-11 tidak ada respons yang membocorkan query atau jejak galat internal;
 *   PW-12 tamu tetap tidak dapat memicu jawaban 410 pada alamat lama.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   PENEMPATAN_RUN_WEB=1 php tests/penempatan_web_smoke.php
 */

$root = dirname(__DIR__);
if (getenv('PENEMPATAN_RUN_WEB') !== '1') {
    fwrite(STDOUT, "[lewati] Set PENEMPATAN_RUN_WEB=1 dan arahkan DB_NAME ke database khusus *_test.\n");
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
final class KlienPenempatan
{
    private string $jar;

    public function __construct(private string $baseUrl, string $label)
    {
        $this->jar = (string) tempnam(sys_get_temp_dir(), 'penempatan-' . $label . '-');
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
        throw new RuntimeException('Token CSRF tidak ditemukan pada ' . $path . ' (status ' . $response['status'] . ')');
    }
}

$db = app_db();
$port = (int) (getenv('PENEMPATAN_WEB_PORT') ?: 8941);
$baseUrl = 'http://127.0.0.1:' . $port;
$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$kecil = strtolower($suffix);
$sandiAdmin = 'UjiPenempatanWeb123Aa';

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
$angka = static function (string $sql) use ($db): int {
    $rs = $db->query($sql);

    return (int) (($rs && $row = $rs->fetch_assoc()) ? ($row['n'] ?? 0) : 0);
};

$dibuat = ['users' => [], 'santri' => [], 'kamar' => [], 'kelas' => []];
$server = null;

try {
    $adminId = $exec(
        'INSERT INTO users (name, username, password, is_active, force_password_change, created_at, updated_at)
         VALUES (?, ?, ?, 1, 0, NOW(), NOW())',
        ['Admin Web Penempatan ' . $suffix, 'pw.admin.' . $kecil, password_hash($sandiAdmin, PASSWORD_DEFAULT)]
    );
    $dibuat['users'][] = $adminId;
    $exec("INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = 'admin'", [$adminId, $adminId]);

    $year = penempatan_service()->activeYear();
    if ($year === null) {
        fwrite(STDERR, "Tahun ajaran aktif tidak tersedia.\n");
        exit(2);
    }
    $yearId = (int) $year['id'];

    $kelasWeb = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active, created_at, updated_at) VALUES (?, 'Uji', 1, NOW(), NOW())", ['PW Kelas ' . $suffix]);
    $dibuat['kelas'][] = $kelasWeb;
    $kamarWeb = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 2)', ['PW Kamar ' . $suffix]);
    $kamarPenuh = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 1)', ['PW Kamar Penuh ' . $suffix]);
    $dibuat['kamar'] = [$kamarWeb, $kamarPenuh];

    $santri = [];
    foreach ([1, 2, 3] as $i) {
        $santri[$i] = $exec(
            "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi,
                                 nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, foto, is_active, created_at, updated_at)
             VALUES (?, ?, 'L', 'Ciamis', '2012-04-01', 'Jl Uji', 'Desa', 'Kec', 'Kab', 'Prov', '', '', '', ?, 'default.jpg', 1, NOW(), NOW())",
            ['PW' . $suffix . $i, 'Santri Web ' . $i . ' ' . $suffix, 'SMK Web ' . $suffix]
        );
        $dibuat['santri'][] = $santri[$i];
    }
    $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santri[3], $kamarPenuh, $yearId]);

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

    // ================================================================= PW-1
    $tamu = new KlienPenempatan($baseUrl, 'tamu');
    $tertutup = $tamu->request('/admin/admin_penempatan_santri.php');
    $assert(
        $tertutup['status'] !== 200 && !str_contains($tertutup['body'], 'Penempatan beberapa santri sekaligus'),
        'PW-1 halaman penempatan tertutup bagi yang belum masuk [' . $tertutup['status'] . ']'
    );
    $tertutupLama = $tamu->request('/admin/admin_santri.php');
    $assert(
        $tertutupLama['status'] !== 301 || !str_contains((string) $tertutupLama['location'], 'admin_penempatan_santri.php'),
        'PW-1 alamat lama pun tidak melayani tamu sebelum guard admin [' . $tertutupLama['status'] . ']'
    );

    // ================================================================= PW-2
    $klien = new KlienPenempatan($baseUrl, 'admin');
    $token = $klien->csrf('/portal/index.php');
    $masuk = $klien->request('/admin/cek_login.php', ['_csrf' => $token, 'username' => 'pw.admin.' . $kecil, 'password' => $sandiAdmin]);
    $assert($masuk['status'] === 302, 'PW-2a admin uji berhasil masuk [' . $masuk['status'] . ']');

    $halaman = $klien->request('/admin/admin_penempatan_santri.php?q=PW' . $suffix);
    $assert($halaman['status'] === 200, 'PW-2b halaman penempatan terbuka untuk admin [' . $halaman['status'] . ']');
    $assert(str_contains($halaman['body'], 'Penempatan Kelas &amp; Kamar') || str_contains($halaman['body'], 'Penempatan Kelas & Kamar'), 'PW-2c judul halaman tampil');
    $assert(preg_match('#<a href="[^"]*admin_penempatan_santri\.php" aria-current="page">#', $halaman['body']) === 1, 'PW-2d menu Master Data → Penempatan Kelas & Kamar ditandai aktif');
    $assert(str_contains($halaman['body'], 'Master Data') && str_contains($halaman['body'], 'ah-crumbs'), 'PW-2e breadcrumb tampil');
    $assert(substr_count($halaman['body'], 'Santri Web 1 ' . $suffix) >= 1, 'PW-2f pencarian NIS menyaring daftar santri');
    $assert(str_contains($halaman['body'], 'Belum ada kamar'), 'PW-2g santri tanpa kamar ditandai jelas');

    // ================================================================= PW-3
    $sebelumCsrf = $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_kamar = {$kamarWeb} AND id_tahun = {$yearId}");
    $tanpaCsrf = $klien->request('/admin/admin_penempatan_santri.php', [
        'action' => 'tempatkan_kamar', 'tahap' => 'langsung', 'santri_ids' => [$santri[1]], 'kamar_id' => $kamarWeb,
    ]);
    $sesudahCsrf = $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_kamar = {$kamarWeb} AND id_tahun = {$yearId}");
    $assert($tanpaCsrf['status'] === 419, 'PW-3a POST tanpa token CSRF ditolak [' . $tanpaCsrf['status'] . ']');
    $assert($sebelumCsrf === $sesudahCsrf, 'PW-3b POST tanpa CSRF tidak mengubah satu baris pun');

    // ================================================================= PW-4
    $lewatGet = $klien->request('/admin/admin_penempatan_santri.php?action=tempatkan_kamar&kamar_id=' . $kamarWeb);
    $assert($lewatGet['status'] === 405, 'PW-4 aksi mutasi lewat GET ditolak 405 [' . $lewatGet['status'] . ']');

    // ================================================================= PW-5
    $alamatLama = $klien->request('/admin/admin_santri.php?cari=PW' . $suffix . '&jk=L&filter_status=no_room');
    $assert($alamatLama['status'] === 301, 'PW-5a alamat lama mengalihkan permintaan GET [' . $alamatLama['status'] . ']');
    $assert(
        str_contains((string) $alamatLama['location'], 'admin_penempatan_santri.php')
        && str_contains((string) $alamatLama['location'], 'q=PW' . $suffix)
        && str_contains((string) $alamatLama['location'], 'status=tanpa_kamar')
        && str_contains((string) $alamatLama['location'], 'jk=L'),
        'PW-5b filter lama dipetakan ke parameter baru [' . (string) $alamatLama['location'] . ']'
    );

    // ================================================================= PW-6
    $csrfHalaman = $klien->csrf('/admin/admin_penempatan_santri.php');
    $sebelumLama = $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_tahun = {$yearId}");
    $postLama = $klien->request('/admin/admin_santri.php', [
        '_csrf' => $csrfHalaman, 'action' => 'update_plot', 'id_santri' => $santri[1], 'tipe' => 'kamar', 'id_val' => $kamarWeb,
    ]);
    $sesudahLama = $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_tahun = {$yearId}");
    $assert($postLama['status'] === 410, 'PW-6a endpoint POST lama dihentikan dengan 410 [' . $postLama['status'] . ']');
    $assert($postLama['location'] === null, 'PW-6b POST lama TIDAK dialihkan secara buta');
    $assert($sebelumLama === $sesudahLama, 'PW-6c POST lama tidak mengubah data penempatan');
    // Klien AJAX lama justru TIDAK mengirim token CSRF. Ia harus tetap menerima
    // penjelasan 410, bukan 419 yang tidak menyebut halaman penggantinya.
    $postLamaTanpaCsrf = $klien->request('/admin/admin_santri.php', [
        'action' => 'bulk_update_plot', 'id_santris' => '[1]', 'tipe' => 'kamar', 'id_val' => $kamarWeb,
    ]);
    $assert($postLamaTanpaCsrf['status'] === 410, 'PW-6d POST lama TANPA token CSRF pun dijawab 410 [' . $postLamaTanpaCsrf['status'] . ']');
    $assert(
        str_contains($postLamaTanpaCsrf['body'], 'admin_penempatan_santri.php'),
        'PW-6e jawaban 410 menyebut halaman pengganti sehingga klien lama tahu ke mana harus pindah'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_tahun = {$yearId}") === $sebelumLama,
        'PW-6f POST lama tanpa CSRF tetap tidak mengubah data'
    );

    // ================================================================= PW-7
    $tinjau = $klien->request('/admin/admin_penempatan_santri.php', [
        '_csrf' => $csrfHalaman, 'action' => 'tempatkan_kamar', 'tahap' => 'tinjau',
        'santri_ids' => [$santri[1], $santri[2]], 'kamar_id' => $kamarWeb,
    ]);
    $assert($tinjau['status'] === 200 && str_contains($tinjau['body'], 'Konfirmasi perubahan penempatan'), 'PW-7a tinjauan tampil sebelum perubahan diterapkan [' . $tinjau['status'] . ']');
    $assert(str_contains($tinjau['body'], '2 santri terpilih'), 'PW-7b tinjauan menyebut jumlah santri terpilih');
    $assert(str_contains($tinjau['body'], 'Kapasitas cukup'), 'PW-7c tinjauan menampilkan status kapasitas kamar');
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_kamar = {$kamarWeb} AND id_tahun = {$yearId}") === 0,
        'PW-7d tinjauan sama sekali tidak mengubah data'
    );

    if (preg_match('/name="form_token" value="([^"]+)"/', $tinjau['body'], $cocok) !== 1) {
        throw new RuntimeException('Token formulir tidak ditemukan pada layar tinjauan.');
    }
    $formToken = $cocok[1];
    $terapkan = $klien->request('/admin/admin_penempatan_santri.php', [
        '_csrf' => $csrfHalaman, 'action' => 'tempatkan_kamar', 'tahap' => 'terapkan', 'form_token' => $formToken,
        'santri_ids' => [$santri[1], $santri[2]], 'kamar_id' => $kamarWeb,
    ]);
    $assert($terapkan['status'] === 302, 'PW-7e penerapan memakai pola POST-redirect-GET [' . $terapkan['status'] . ']');
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_kamar = {$kamarWeb} AND id_tahun = {$yearId}") === 2,
        'PW-7f dua santri benar-benar tersimpan pada kamar tujuan'
    );
    $setelahRedirect = $klien->request('/admin/admin_penempatan_santri.php?q=PW' . $suffix);
    $assert(str_contains($setelahRedirect['body'], 'Berhasil'), 'PW-7g pesan hasil ditampilkan setelah pengalihan');

    // ================================================================= PW-8
    $ulang = $klien->request('/admin/admin_penempatan_santri.php', [
        '_csrf' => $csrfHalaman, 'action' => 'tempatkan_kamar', 'tahap' => 'terapkan', 'form_token' => $formToken,
        'santri_ids' => [$santri[1], $santri[2]], 'kamar_id' => $kamarWeb,
    ]);
    $assert($ulang['status'] === 302, 'PW-8a pengiriman ulang tinjauan yang sama dialihkan kembali [' . $ulang['status'] . ']');
    $pesanUlang = $klien->request('/admin/admin_penempatan_santri.php');
    $assert(str_contains($pesanUlang['body'], 'sudah pernah diterapkan'), 'PW-8b admin diberi tahu bahwa tinjauan itu sudah dipakai');
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_kamar = {$kamarWeb} AND id_tahun = {$yearId}") === 2,
        'PW-8c tidak ada penempatan ganda dari pengiriman ulang'
    );

    // ================================================================= PW-9
    $sebelumPenuh = $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_kamar = {$kamarPenuh} AND id_tahun = {$yearId}");
    $gagalKapasitas = $klien->request('/admin/admin_penempatan_santri.php', [
        '_csrf' => $csrfHalaman, 'action' => 'tempatkan_kamar', 'tahap' => 'langsung',
        'santri_ids' => [$santri[1]], 'kamar_id' => $kamarPenuh,
    ]);
    $assert($gagalKapasitas['status'] === 422, 'PW-9a penempatan ke kamar penuh ditolak 422 [' . $gagalKapasitas['status'] . ']');
    $assert(str_contains($gagalKapasitas['body'], 'Kapasitas kamar'), 'PW-9b pesan kapasitas dijelaskan kepada admin');
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_kamar = {$kamarPenuh} AND id_tahun = {$yearId}") === $sebelumPenuh,
        'PW-9c penolakan kapasitas tidak mengubah satu baris pun'
    );

    // ================================================================ PW-10
    $dariKamar = $klien->request('/admin/admin_penempatan_santri.php?kamar_id=' . $kamarWeb);
    $assert(
        str_contains($dariKamar['body'], 'Santri Web 1 ' . $suffix)
        && !str_contains($dariKamar['body'], 'Santri Web 3 ' . $suffix),
        'PW-10a filter kamar dari Data Kamar hanya menampilkan penghuni kamar itu'
    );
    $klien->request('/admin/admin_penempatan_santri.php', [
        '_csrf' => $csrfHalaman, 'action' => 'tempatkan_kelas', 'tahap' => 'langsung',
        'santri_ids' => [$santri[1]], 'kelas_id' => $kelasWeb, 'tanggal_mulai' => date('Y-m-d'),
    ]);
    $dariKelas = $klien->request('/admin/admin_penempatan_santri.php?kelas_id=' . $kelasWeb);
    $assert(
        str_contains($dariKelas['body'], 'Santri Web 1 ' . $suffix)
        && !str_contains($dariKelas['body'], 'Santri Web 2 ' . $suffix),
        'PW-10b filter kelas dari Data Kelas hanya menampilkan anggota kelas itu'
    );
    $halamanKamar = $klien->request('/admin/admin_kamar.php');
    $assert(str_contains($halamanKamar['body'], 'admin_penempatan_santri.php?kamar_id='), 'PW-10c Data Kamar menautkan penempatan berfilter');
    $halamanKelas = $klien->request('/admin/admin_kelas.php');
    $assert(str_contains($halamanKelas['body'], 'admin_penempatan_santri.php?kelas_id='), 'PW-10d Data Kelas menautkan penempatan berfilter');
    $halamanSantri = $klien->request('/admin/admin_master_santri.php');
    $assert(str_contains($halamanSantri['body'], 'admin_penempatan_santri.php'), 'PW-10e Data Santri menautkan halaman penempatan');

    // ================================================================ PW-11
    // ================================================================ PW-12
    $tamuPost = $tamu->request('/admin/admin_santri.php', ['action' => 'update_plot']);
    $assert(
        $tamuPost['status'] !== 410,
        'PW-12 tamu tidak menerima jawaban 410: guard admin berjalan lebih dahulu [' . $tamuPost['status'] . ']'
    );

    // ================================================================ PW-11
    $semuaBody = $halaman['body'] . $tinjau['body'] . $gagalKapasitas['body'] . $postLama['body'] . $lewatGet['body'];
    foreach (['SELECT ', 'INSERT INTO', 'UPDATE plotting', 'FOR UPDATE', 'mysqli', 'Stack trace', '/home/', 'SQLSTATE'] as $bocor) {
        $assert(!str_contains($semuaBody, $bocor), 'PW-11 respons tidak membocorkan detail internal: ' . $bocor);
    }
} catch (Throwable $exception) {
    $assert(false, 'Pengujian berhenti karena galat tak terduga: ' . $exception->getMessage());
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    try {
        foreach ($dibuat['santri'] as $id) {
            $db->query('DELETE FROM plotting_kamar WHERE id_santri = ' . (int) $id);
            $db->query('DELETE FROM plotting_kelas WHERE id_santri = ' . (int) $id);
            $db->query('DELETE FROM santri WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['kamar'] as $id) {
            $db->query('DELETE FROM plotting_kamar WHERE id_kamar = ' . (int) $id);
            $db->query('DELETE FROM kamar WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['kelas'] as $id) {
            $db->query('DELETE FROM plotting_kelas WHERE id_kelas = ' . (int) $id);
            $db->query('DELETE FROM kelas WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['users'] as $id) {
            $db->query('DELETE FROM user_roles WHERE user_id = ' . (int) $id);
            $db->query('DELETE FROM audit_logs WHERE actor_user_id = ' . (int) $id);
            $db->query('DELETE FROM users WHERE id = ' . (int) $id);
        }
    } catch (Throwable $exception) {
        echo '[gagal] Bersih-bersih fixture: ' . $exception->getMessage() . PHP_EOL;
        $failures[] = 'bersih-bersih fixture';
    }
}

echo PHP_EOL;
if ($failures === []) {
    echo 'SELURUH SMOKE TEST WEB PENEMPATAN LULUS.' . PHP_EOL;
    exit(0);
}
echo 'GAGAL (' . count($failures) . '):' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;
exit(1);
