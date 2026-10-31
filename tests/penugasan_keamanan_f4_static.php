<?php

declare(strict_types=1);

/**
 * Uji statis Fase 4 penugasan lintas guru/pengurus — REST baca-saja Keamanan,
 * identitas akun, dan layar aplikasi. Tanpa basis data. Membaca checkout
 * mobile `~/alhasanApps` (atau `PKF4_MOBILE`).
 *
 *   php tests/penugasan_keamanan_f4_static.php
 *
 *   S-1 router: blok /keamanan menolak metode selain GET sebelum layanan apa pun;
 *       sembilan rute GET; KeamananException ditangkap
 *   S-2 KeamananApiService hanya membaca: tanpa SQL tulis, tanpa CSV/unduhan,
 *       hak diperiksa di setiap metode data, kunci asing tidak diteruskan
 *   S-3 identitas lewat GET /profile/identitas; /profile dan login tidak berubah
 *   S-4 batas web terhadap baseline (tanpa migrasi, Auth, V3/Izin/Report, portal, admin)
 *   S-5 aplikasi: klien hanya GET, perlindungan layar sebelum data, tanpa
 *       formulir mutasi/unduhan/log; ubin, rute, dan profil terdaftar
 *   S-6 lint PHP berkas baru/berubah
 */

$root = dirname(__DIR__);
$baseline = getenv('PKF4_BASELINE') ?: '6ebdc24';
$mobile = getenv('PKF4_MOBILE') ?: (getenv('HOME') . '/alhasanApps');
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $f): string => (string) file_get_contents($root . '/' . $f);
/** Kode tanpa komentar, agar pemindaian tidak tertipu teks penjelasan. */
$kode = static function (string $isi): string {
    $hasil = '';
    foreach (token_get_all($isi) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $hasil .= is_array($t) ? $t[1] : $t;
    }

    return $hasil;
};

echo "=== S-1 router ===\n";
$router = $baca('api/v1/index.php');
$awal = strpos($router, "if (\$path === '/keamanan' || str_starts_with(\$path, '/keamanan/'))");
$akhir = $awal === false ? false : strpos($router, "throw new ApiException('NOT_FOUND'", $awal);
$blok = $awal === false || $akhir === false ? '' : substr($router, $awal, $akhir - $awal);
$assert($blok !== '', 'Blok /keamanan ada');
$tolak = strpos($blok, "if (\$method !== 'GET')");
$layanan = strpos($blok, 'keamanan_api_service()');
$assert($tolak !== false && $layanan !== false && $tolak < $layanan && str_contains($blok, "JsonResponse::error('METHOD_NOT_ALLOWED'") && str_contains($blok, "header('Allow: GET')"),
    'Metode selain GET ditolak 405 sebelum layanan dibuat');
preg_match_all('/JsonResponse::success\(\$keamanan->(\w+)\(/', $blok, $m);
$assert($m[1] === ['akses', 'izinDaftar', 'izinDetail', 'pelanggaranDaftar', 'pelanggaranDetail', 'konselingDaftar', 'konselingDetail', 'kehadiran', 'kehadiranPertemuan'],
    'Tepat sembilan rute baca: ' . implode(', ', $m[1]));
$assert(!preg_match('/Request::json|\$_POST|idempotency|\$method === \'(POST|PUT|PATCH|DELETE)\'/', $blok), 'Blok Keamanan tanpa badan permintaan, kunci idempotensi, atau cabang mutasi');
$assert(str_contains($router, 'catch (\App\Keamanan\KeamananException $exception)'), 'KeamananException diterjemahkan ke galat JSON');
$assert(strpos($router, "str_starts_with(\$path, '/keamanan/')") > strpos($router, 'api_authenticator()->authenticate()'), 'Blok Keamanan sesudah autentikasi token');

echo "\n=== S-2 layanan baca-saja ===\n";
$svcAsli = $baca('app/Keamanan/KeamananApiService.php');
$svc = $kode($svcAsli);
$assert(!preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)\b/i', $svc), 'Tanpa SQL tulis');
$assert(!preg_match('/->(ekspor|attachment|cetakKeamanan|create\w*|correct\w*|cancel|acknowledge|transition\w*|add\w+|buat|tinjau|putus\w*|koreksi\w*)\(/', $svc),
    'Tidak memanggil ekspor CSV, unduh lampiran, cetak, atau jalur tulis layanan mana pun');
preg_match_all('/public function (\w+)\(array \$user[^{]*\{(.*?)\n    \}/s', $svc, $fungsi, PREG_SET_ORDER);
$tanpaHak = [];
foreach ($fungsi as [$utuh, $nama, $isi]) {
    if ($nama === 'akses') {
        continue;
    }
    if (!preg_match('/pastikanAkses|pembacaKehadiran|->keamanan->izinDetail/', $isi)) {
        $tanpaHak[] = $nama;
    }
}
$assert(count($fungsi) === 9 && $tanpaHak === [], 'Delapan metode data memeriksa penugasan Keamanan sebelum membaca' . ($tanpaHak === [] ? '' : ': ' . implode(', ', $tanpaHak)));
$assert(str_contains($svc, "KeamananAkses::sah(\$this->capabilities, \$user)") && str_contains($svc, "'csv' => false"), 'Akses dihitung dari KeamananAkses::sah; csv selalu false');
$assert(substr_count($svc, '$this->hanya($query,') === 2, 'Saringan izin dan kehadiran diteruskan lewat daftar kunci yang dikenal');
$assert(str_contains($svc, "ReportFilter::BACA_SEMUA_JADWAL] = true") && str_contains($svc, "ReportFilter::SANTRI_AKTIF_SAJA] = true")
    && strpos($svc, '$this->pastikanAkses($user);', strpos($svc, 'function pembacaKehadiran')) < strpos($svc, 'BACA_SEMUA_JADWAL] = true'),
    'Penanda kehadiran dipasang hanya sesudah hak diperiksa');
$assert(str_contains($svc, "'keamanan.kehadiran.dilihat'") && str_contains($svc, 'Rincian tidak ditampilkan.'), 'Rincian pertemuan wajib berjejak audit (503 bila gagal)');
$assert(!preg_match("/'(rekomendasi|rekonsiliasi|peringatan_konfigurasi|catatan_murobi|sha256|path)' =>/", $svc), 'Proyeksi tanpa kunci internal yang tidak ditampilkan web');
$assert(substr_count($svc, '$this->bukanCakupan(') === 2 && str_contains($svc, 'new KeamananException($pesan, 404)'), 'Rincian di luar cakupan (Rahasia/nonaktif) = 404');

echo "\n=== S-3 identitas akun ===\n";
$auth = $kode($baca('app/Api/ApiAuthService.php'));
$badan = static fn (string $nama): string => preg_match('/public function ' . $nama . '\\(.*?\\n    \\}/s', $auth, $mm) ? $mm[0] : '';
$assert($badan('login') !== '' && $badan('profile') !== '' && !str_contains($badan('login') . $badan('profile'), 'identitas'),
    'Bentuk /profile dan respons login tidak bertambah kunci (oracle FI-19 tetap)');
$assert(str_contains($router, "\$path === '/profile/identitas'") && str_contains($router, 'api_auth_service()->identitasPayload($user)'), 'Identitas dilayani GET /profile/identitas');
$assert(str_contains($auth, '$this->capabilities->linkedPengurusId(') && str_contains($baca('app/Api/ApiAuthRepository.php'), 'function pengurusIdentitas'),
    'Identitas pengurus hanya dari ID yang disahkan Capabilities');

echo "\n=== S-4 batas web ===\n";
exec('cd ' . escapeshellarg($root) . ' && git diff --name-only ' . escapeshellarg($baseline) . ' -- . 2>/dev/null', $berubah, $hasil);
exec('cd ' . escapeshellarg($root) . ' && git ls-files --others --exclude-standard 2>/dev/null', $baru);
$semua = array_values(array_unique(array_merge($berubah, $baru)));
$boleh = static fn (string $f): bool => in_array($f, ['api/v1/index.php', 'app/Keamanan/KeamananApiService.php', 'app/Api/ApiAuthService.php',
    'app/Api/ApiAuthRepository.php', 'app/bootstrap.php', 'docs/api-v1.md', 'AGENTS.md'], true)
    || str_starts_with($f, 'docs/penugasan-keamanan/') || str_starts_with($f, 'tests/penugasan_keamanan_f4_')
    || str_starts_with($f, 'tests/browser/uji-penugasan-keamanan-f4')
    // Direktori pekerjaan lain yang belum terlacak sejak sebelum Fase 4 (tidak disentuh).
    || preg_match('#^(docs/panduan-pengguna-v4|docs/presentasi-al-hasan-v4|output|tests/browser/tangkapan-penugasan-keamanan-f1)/#', $f) === 1;
$liar = array_values(array_filter($semua, static fn (string $f): bool => !$boleh($f)));
$assert($hasil === 0 && $liar === [], 'Hanya berkas Fase 4 yang berubah terhadap ' . $baseline . ($liar === [] ? '' : ': ' . implode(', ', $liar)));
$assert(array_filter($semua, static fn (string $f): bool => preg_match('#^(database/|app/Auth/|app/V3/|app/Izin/|app/Report/|app/Penugasan/|portal/|admin/)#', $f) === 1) === [],
    'Tanpa migrasi; app/Auth, V3, Izin, Report, Penugasan, portal, dan admin tidak berubah');

echo "\n=== S-5 aplikasi ===\n";
if (!is_dir($mobile . '/src/app/keamanan')) {
    $assert(false, 'Checkout mobile dengan layar Keamanan tidak ditemukan di ' . $mobile);
} else {
    $m = static fn (string $f): string => (string) @file_get_contents($mobile . '/' . $f);
    $klien = $m('src/api/keamanan.ts');
    $assert($klien !== '' && !preg_match("/method:|'POST'|'PUT'|'PATCH'|'DELETE'/", $klien) && substr_count($klien, 'request<') === 9, 'Klien Keamanan: sembilan GET, tanpa metode tulis');
    $layar = glob($mobile . '/src/app/keamanan/{,*/}*.tsx', GLOB_BRACE) ?: [];
    $isiLayar = implode("\n", array_map('file_get_contents', $layar));
    $assert(count($layar) === 10, 'Sepuluh berkas navigator Keamanan (layout, pintu, 4 daftar, 4 rincian): ' . count($layar));
    $assert(!preg_match('/useMutationGuard|idempotency|request\(|method:|Linking|WebBrowser|expo-file-system|expo-sharing|expo-print|console\./', $isiLayar),
        'Layar Keamanan tanpa mutasi, idempotensi, tautan keluar, unduhan/berbagi/cetak, atau console');
    $assert(!preg_match('/<(TextInput|Field)[^>]*multiline/', $isiLayar) && substr_count($isiLayar, '<Field ') === 2, 'Satu-satunya isian: kolom cari santri (pelanggaran, konseling)');
    $bersama = $m('src/components/keamanan-shared.tsx');
    $tata = $m('src/app/keamanan/_layout.tsx');
    $assert(str_contains($tata, '<KeamananProtection>') && str_contains($bersama, 'preventScreenCaptureAsync') && str_contains($bersama, 'enableAppSwitcherProtectionAsync')
        && str_contains($bersama, "if (status !== 'ready') return <LoadingState"), 'Perlindungan layar dipasang sebelum layar Keamanan dimuat; gagal tertutup');
    $assert(!preg_match('/\sselectable(\s|=|\/?>)/', $isiLayar . $bersama), 'Isi Keamanan tidak dibuat selectable');
    $beranda = $m('src/app/(app)/(home)/index.tsx');
    $assert(str_contains($beranda, 'useKeamananAkses()') && str_contains($beranda, "router.push('/keamanan')") && str_contains($m('src/app/_layout.tsx'), '<Stack.Screen name="keamanan"'),
        'Ubin Beranda dari /keamanan/akses dan rute terdaftar di balik sesi');
    $profil = $m('src/app/(app)/(profil)/profil.tsx');
    $assert(str_contains($profil, 'Sebagai guru') && str_contains($profil, 'Sebagai pengurus') && str_contains($m('src/api/types.ts'), 'export type ProfileIdentitas') && str_contains($profil, '/profile/identitas') === false && str_contains($m('src/api/akun.ts'), "'/profile/identitas'"),
        'Profil menampilkan dua identitas dari GET /profile/identitas (klien src/api/akun.ts)');
}

echo "\n=== S-6 lint ===\n";
foreach (array_filter($semua, static fn (string $f): bool => str_ends_with($f, '.php') && is_file($root . '/' . $f) && !preg_match('#^(docs|output)/#', $f)) as $berkas) {
    $keluaran = [];
    exec('php -l ' . escapeshellarg($root . '/' . $berkas) . ' 2>&1', $keluaran, $kodeLint);
    $assert($kodeLint === 0, "lint $berkas");
}

echo PHP_EOL . "Hasil: {$lulus} lulus, " . count($gagal) . " gagal" . PHP_EOL;
foreach ($gagal as $g) {
    echo " - $g" . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
