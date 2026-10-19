<?php

declare(strict_types=1);

// Static guard: V1 admin mutations stay POST + CSRF, without request data in SQL or unlink paths.
$root = dirname(__DIR__);
$failed = 0; $passed = 0;
$ok = static function (bool $value, string $label) use (&$failed, &$passed): void {
    $value ? $passed++ : $failed++;
    echo ($value ? '[lulus] ' : '[gagal] ') . $label . "\n";
};
$read = static fn (string $file): string => (string) file_get_contents($root . '/admin/' . $file);

$guard = $read('_guard.php');
$ok(str_contains($guard, "Csrf::requireValid(\$_POST['_csrf']") && str_contains($guard, 'function admin_require_post')
    && str_contains($guard, 'http_response_code(405)') && str_contains($guard, "header('Allow: POST')"), '_guard memvalidasi CSRF POST dan menyediakan penolakan 405');

$targets = ['hapus_siswa.php', 'proses_psb_admin.php', 'admin_pembayaran_psb.php', 'proses_berita.php', 'admin_galeri.php', 'admin_download.php'];
foreach ($targets as $file) {
    $src = $read($file);
    $ok(str_contains($src, 'admin_require_post()'), $file . ' memanggil admin_require_post()');
    $ok(!preg_match('/mysqli_query\([^;]*(DELETE|UPDATE|INSERT)[^;]*\$_(GET|POST|REQUEST)/s', $src)
        && !preg_match('/\$_GET\[\'(id|val|file|gbr)\'\]/', $src), $file . ' tanpa $_GET/$_POST mentah dalam SQL mutasi');
    $ok(!preg_match('/unlink\([^;]*\$_(GET|POST|REQUEST)/', $src), $file . ' tanpa path unlink dari permintaan');
}
foreach (['admin_galeri.php', 'admin_download.php', 'proses_berita.php'] as $file) {
    $ok(substr_count($read($file), 'basename((string)') === 1, $file . ' membatasi nama berkas dari DB dengan basename()');
}
$ok(str_contains($read('proses_psb_admin.php'), "in_array(\$status, ['Diterima', 'Ditolak', 'Cadangan'], true)"), 'Status PSB dibatasi daftar putih');

$callers = ['admin_data.php' => ['hapus_siswa.php?', 'proses_psb_admin.php?act='], 'admin_pembayaran_psb.php' => ['admin_pembayaran_psb.php?act='],
    'admin_berita.php' => ['proses_berita.php?act=hapus&'], 'admin_galeri.php' => ['?hapus='], 'admin_download.php' => ['?hapus=']];
foreach ($callers as $file => $needles) {
    $src = $read($file);
    $hasLink = false;
    foreach ($needles as $needle) $hasLink = $hasLink || str_contains($src, $needle);
    $ok(!$hasLink && str_contains($src, 'Csrf::input()'), $file . ' memakai formulir POST + Csrf::input(), tanpa tautan bermutasi');
}

echo "Ringkasan: {$passed} lulus, {$failed} gagal\n";
exit($failed === 0 ? 0 : 1);
