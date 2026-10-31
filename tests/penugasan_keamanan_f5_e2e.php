<?php

declare(strict_types=1);

/**
 * Uji ujung-ke-ujung rilis penugasan lintas guru/pengurus dan Keamanan
 * (Fase 5): Fase 1–4 pada SATU DB uji sesudah migrasi, diperiksa verifier
 * rilis di setiap langkah. Lewat server PHP lokal (sesi web dan token API).
 *
 *   PKF5_DB=test_pk_f5_e2e_test bash tests/penugasan_keamanan_f5_db.sh
 *   PKF5_DB=test_pk_f5_e2e_test php tests/penugasan_keamanan_f5_e2e.php
 *
 *   E-1  DB sesudah migrasi tanpa data workstream: verifier lulus
 *   E-2  skenario resmi (pengurus biasa K dan guru G sebagai Keamanan, pengajuan
 *        wali, santri nonaktif, lampiran): identitas G dibentuk; verifier lulus
 *   E-3  seluruh halaman web dan endpoint REST Keamanan dibaca K dan G (daftar,
 *        rincian, cetak, kehadiran, lampiran); mutasi web/API ditolak 405;
 *        kasus Rahasia 404. Irisan data yang dibaca (izin V2/V7, pembinaan V3,
 *        kehadiran, santri, guru, pengurus biasa, …) = manifest sebelum
 *        membaca; jejak keamanan.* bertambah dan hanya metadata
 *   E-4  Data Guru menonaktifkan G (jalur resmi) → identitas tersinkron,
 *        sesi/token lama G ditolak, resolver = SQL; diaktifkan → pulih
 *   E-5  penugasan K dinonaktifkan → sesi/token lama K ditolak; verifier lulus
 *   E-6  log server tanpa galat PHP dan tanpa alasan izin
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

use App\Penugasan\PenugasanJenis;

$root = dirname(__DIR__);
$db = getenv('PKF5_DB') ?: 'test_pk_f5_e2e_test';
$port = (int) (getenv('PKF5_PORT') ?: 8959);
if (!preg_match('/^test_.*_test$/', $db)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('PKF3_DB=' . $db);
putenv('DB_NAME=' . $db);
require_once $root . '/app/bootstrap.php';
require_once $root . '/bin/_penugasan_keamanan_rilis.php';

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$conn = app_db();
$satu = static function (string $sql) use ($conn): mixed {
    $r = $conn->query($sql);
    if ($r === false) {
        throw new RuntimeException($conn->error . ' :: ' . $sql);
    }

    return $r->fetch_row()[0] ?? null;
};
$scratch = sys_get_temp_dir() . '/pkf5-e2e-' . bin2hex(random_bytes(3));
mkdir($scratch, 0700);
/** @return array{0:int, 1:string} */
$verifikasi = static function (string $arg = '') use ($root, $db): array {
    $out = [];
    exec('DB_NAME=' . escapeshellarg($db) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/penugasan_keamanan_verify.php') . ' ' . $arg . ' 2>&1', $out, $kode);

    return [$kode, implode("\n", $out)];
};
$manifest = static function (string $nama) use ($root, $db, $scratch): string {
    $berkas = $scratch . '/' . $nama . '.json';
    exec('DB_NAME=' . escapeshellarg($db) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/penugasan_keamanan_preflight.php') . ' ' . escapeshellarg($berkas) . ' > /dev/null 2>&1');

    return $berkas;
};
$ringkas = static fn (string $out): string => preg_match('/Ringkasan verifier penugasan Keamanan: (\d+ lulus, \d+ gagal)/', $out, $m) === 1 ? $m[1] : '?';

// ===================================================================== E-1
echo "\n=== E-1 sesudah migrasi, tanpa data workstream ===\n";
[$kode, $out] = $verifikasi();
$assert($kode === 0 && (int) $satu('SELECT COUNT(*) FROM keamanan_assignments') === 0 && (int) $satu('SELECT COUNT(*) FROM pengurus WHERE guru_id IS NOT NULL') === 0,
    'Verifier lulus pada DB 033 kosong (' . $ringkas($out) . ')');

// ===================================================================== E-2
echo "\n=== E-2 skenario resmi Fase 1–3 ===\n";
$ids = require __DIR__ . '/penugasan_keamanan_f3_seed.php';
register_shutdown_function(static fn () => @unlink($ids['lampiran_berkas']));
$gGuru = (int) $satu('SELECT guru_id FROM users WHERE id = ' . $ids['g']);
[$kode, $out] = $verifikasi();
$assert($kode === 0 && str_contains($out, 'Identitas pengurus milik guru: 1; terhubung ke akun: 1') && str_contains($out, 'hitungan SQL independen (2 akun)'),
    'Identitas guru G dibentuk dan terhubung; dua akun Keamanan; verifier lulus (' . $ringkas($out) . ')');

// ===================================================================== E-3
echo "\n=== E-3 baca seluruh jalur Keamanan web + API ===\n";
$m1 = $manifest('sebelum-baca');
$auditAwal = (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'keamanan.%'");
$log = tempnam(sys_get_temp_dir(), 'pkf5-server-');
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, $root . '/tests/redesain_web_tahap1_router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge(getenv(), ['DB_NAME' => $db]));
for ($i = 0; $i < 50; $i++) {
    $sock = @fsockopen('127.0.0.1', $port);
    if ($sock !== false) {
        fclose($sock);
        break;
    }
    usleep(100000);
}
register_shutdown_function(static function () use ($server, $port): void {
    if (is_resource($server)) {
        proc_terminate($server);
    }
    exec('pkill -f ' . escapeshellarg('php -S 127.0.0.1:' . $port . ' '));
});
$base = 'http://127.0.0.1:' . $port;
/** @return array{status:int, body:string} */
$http = static function (string $method, string $url, array $headers = [], ?string $body = null, ?string $jar = null): array {
    $h = curl_init($url);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
    if ($jar !== null) {
        curl_setopt_array($h, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    }
    if ($method !== 'GET') {
        curl_setopt($h, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($h, CURLOPT_POSTFIELDS, $body ?? '');
    }
    $isi = (string) curl_exec($h);
    $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    curl_close($h);

    return ['status' => $status, 'body' => $isi];
};
$web = static function (string $user, ?string $peran = null) use ($http, $base): string {
    $jar = tempnam(sys_get_temp_dir(), 'pkf5-jar-');
    $csrf = static function () use ($http, $base, $jar): string {
        preg_match('/name="_csrf" value="([^"]+)"/', $http('GET', $base . '/portal/index.php', [], null, $jar)['body'], $m);

        return $m[1] ?? '';
    };
    $form = ['Content-Type: application/x-www-form-urlencoded'];
    $http('POST', $base . '/admin/cek_login.php', $form, http_build_query(['_csrf' => $csrf(), 'username' => $user, 'password' => 'Sandbox#123']), $jar);
    if ($peran !== null) {
        $http('POST', $base . '/portal/peran.php', $form, http_build_query(['_csrf' => $csrf(), 'peran' => $peran, 'kembali' => '/portal/index.php']), $jar);
    }

    return $jar;
};
$api = static function (string $method, string $path, ?string $token, ?array $body = null) use ($http, $base): array {
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $r = $http($method, $base . '/api/v1' . $path, $headers, $method === 'GET' ? null : (string) json_encode($body ?? []));

    return $r + ['json' => json_decode($r['body'], true)];
};
$token = static fn (string $user): string => (string) ($api('POST', '/auth/login', null, ['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-pk-f5'])['json']['data']['token'] ?? '');
$jarK = $web('sbx_pengurus_biasa');
$jarG = $web('sbx_guru_biasa', 'pengurus');
$tK = $token('sbx_pengurus_biasa');
$tG = $token('sbx_guru_biasa');
$assert($tK !== '' && $tG !== '', 'K dan G masuk web (G memilih peran Pengurus) dan memperoleh token API');
$pertemuan = (int) $satu('SELECT MIN(id) FROM pertemuan_pengajian');
$halaman = ['/portal/keamanan_izin.php', '/portal/keamanan_izin_detail.php?id=' . $ids['izin_wali'], '/portal/keamanan_izin_detail.php?id=' . $ids['izin_aktif'],
    '/portal/keamanan_izin_cetak.php', '/portal/keamanan_pelanggaran.php', '/portal/keamanan_pelanggaran_detail.php?id=' . $ids['pelanggaran_lampiran'],
    '/portal/keamanan_pelanggaran_detail.php?id=' . $ids['pelanggaran_internal'], '/portal/keamanan_pelanggaran_cetak.php?id=' . $ids['pelanggaran_internal'],
    '/portal/keamanan_lampiran.php?id=' . $ids['lampiran'], '/portal/keamanan_konseling.php', '/portal/keamanan_konseling_detail.php?id=' . $ids['kasus_internal'],
    '/portal/keamanan_konseling_cetak.php?id=' . $ids['kasus_internal'], '/admin/admin_laporan_absensi.php', '/admin/laporan_absensi_detail.php?id=' . $pertemuan,
    '/admin/laporan_absensi_cetak.php'];
$rute = ['/keamanan/akses', '/keamanan/izin', '/keamanan/izin/' . $ids['izin_wali'], '/keamanan/izin/' . $ids['izin_aktif'], '/keamanan/pelanggaran',
    '/keamanan/pelanggaran/' . $ids['pelanggaran_lampiran'], '/keamanan/konseling', '/keamanan/konseling/' . $ids['kasus_internal'],
    '/keamanan/kehadiran', '/keamanan/kehadiran/pertemuan/' . $pertemuan];
foreach (['K' => [$jarK, $tK], 'G' => [$jarG, $tG]] as $siapa => [$jar, $tok]) {
    $bukan200 = [];
    foreach ($halaman as $p) {
        $r = $http('GET', $base . $p, [], null, $jar);
        if ($r['status'] !== 200) {
            $bukan200[] = $p . ' → ' . $r['status'];
        }
    }
    $assert($bukan200 === [], $siapa . ': ' . count($halaman) . ' halaman web Keamanan (daftar, rincian, cetak, lampiran, kehadiran) 200' . ($bukan200 === [] ? '' : ' (' . implode('; ', $bukan200) . ')'));
    $bukan200 = [];
    foreach ($rute as $p) {
        $r = $api('GET', $p, $tok);
        if ($r['status'] !== 200) {
            $bukan200[] = $p . ' → ' . $r['status'];
        }
    }
    $assert($bukan200 === [], $siapa . ': ' . count($rute) . ' endpoint REST Keamanan 200' . ($bukan200 === [] ? '' : ' (' . implode('; ', $bukan200) . ')'));
}
$mutasi = [];
foreach (['/portal/keamanan_izin_detail.php?id=' . $ids['izin_wali'], '/portal/keamanan_pelanggaran_detail.php?id=' . $ids['pelanggaran_internal'],
    '/portal/keamanan_konseling_detail.php?id=' . $ids['kasus_internal']] as $p) {
    $mutasi[] = $http('POST', $base . $p, ['Content-Type: application/x-www-form-urlencoded'], 'aksi=putuskan&hasil=Disetujui', $jarK)['status'];
}
foreach ($rute as $p) {
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $metode) {
        $mutasi[] = $api($metode, $p, $tK, ['hasil' => 'Disetujui', 'alasan' => 'PKF5'])['status'];
    }
}
$assert(array_unique($mutasi) === [405], count($mutasi) . ' kiriman mutasi web/API oleh K → seluruhnya 405');
$assert($api('GET', '/keamanan/konseling/' . $ids['kasus_rahasia'], $tK)['status'] === 404
    && $http('GET', $base . '/portal/keamanan_konseling_detail.php?id=' . $ids['kasus_rahasia'], [], null, $jarK)['status'] === 404,
    'Kasus Rahasia tetap tertutup bagi Keamanan (404 web dan API)');
[$kode, $out] = $verifikasi(escapeshellarg($m1) . ' --irisan-baca');
$assert($kode === 0 && str_contains($out, '[lulus] Isi irisan_baca ('), 'Irisan data yang dibaca Keamanan = manifest sebelum membaca (seluruh baca web/API tidak mengubah data modul)');
$auditAkhir = (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'keamanan.%'");
[$kode, $out] = $verifikasi();
$assert($auditAkhir > $auditAwal + 10 && $kode === 0 && str_contains($out, '[lulus] Audit keamanan.* hanya memuat kunci metadata'),
    'Jejak keamanan.* bertambah ' . ($auditAkhir - $auditAwal) . ' baris; verifier lulus (hanya metadata, pelaku pemegang Keamanan)');

// ===================================================================== E-4
echo "\n=== E-4 guru G dinonaktifkan lewat Data Guru ===\n";
$admin = (int) $satu("SELECT id FROM users WHERE username = 'sbx_admin'");
$_SESSION['user_id'] = $admin;
master_data_service()->setGuruState($gGuru, 'deactivate');
$assert((int) $satu('SELECT is_active FROM pengurus WHERE id = ' . $ids['g_pengurus']) === 0, 'Identitas pengurus G ikut nonaktif (sinkron dalam transaksi Data Guru)');
$assert($api('GET', '/keamanan/izin', $tG)['status'] === 403 && ($api('GET', '/keamanan/akses', $tG)['json']['data']['pantau'] ?? null) === false
    && $http('GET', $base . '/portal/keamanan_izin.php', [], null, $jarG)['status'] !== 200, 'Token dan sesi web lama G ditolak pada permintaan berikutnya');
[$kode, $out] = $verifikasi();
$assert($kode === 0 && str_contains($out, 'hitungan SQL independen (1 akun)') && str_contains($out, '[lulus] Identitas milik guru nonaktif/diarsipkan tidak memberi capability'),
    'Verifier lulus: identitas sinkron, resolver = SQL (1 akun), identitas nonaktif tanpa capability');
master_data_service()->setGuruState($gGuru, 'activate');
$assert($api('GET', '/keamanan/izin', $tG)['status'] === 200 && $verifikasi()[0] === 0, 'G diaktifkan kembali → akses pulih; verifier lulus');

// ===================================================================== E-5
echo "\n=== E-5 penugasan K dinonaktifkan ===\n";
penugasan_service()->nonaktifkan(PenugasanJenis::KEAMANAN, $ids['penugasan_k'], 'PKF5 uji pencabutan', $admin);
$assert($api('GET', '/keamanan/pelanggaran', $tK)['status'] === 403 && $http('GET', $base . '/portal/keamanan_pelanggaran.php', [], null, $jarK)['status'] !== 200,
    'Token dan sesi web lama K ditolak sesudah penugasan dinonaktifkan');
[$kode, $out] = $verifikasi();
$assert($kode === 0 && str_contains($out, 'hitungan SQL independen (1 akun)'), 'Verifier lulus sesudah pencabutan (' . $ringkas($out) . ')');

// ===================================================================== E-6
echo "\n=== E-6 log server ===\n";
proc_terminate($server);
usleep(300000);
$isiLog = (string) file_get_contents($log);
$assert(preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isiLog) !== 1, 'Log server tanpa galat PHP');
$assert(!str_contains($isiLog, $ids['alasan_wali']), 'Log server tanpa alasan izin');

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
