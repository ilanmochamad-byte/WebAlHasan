<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};

$loginSource = (string) file_get_contents($root . '/admin/cek_login.php');
$connectionSource = (string) file_get_contents($root . '/koneksi.php');
$assert(!preg_match('/\$username\s*==|\$password\s*==/', $loginSource), 'Login tidak membandingkan username/password dengan nilai literal');
$assert(str_contains($loginSource, 'AuthService'), 'Login lama memakai service autentikasi database');
$assert(!preg_match('/\$pass\s*=\s*[\'\"][^\'\"]+[\'\"]/', $connectionSource), 'Koneksi tidak memuat password database literal');
$guardSource = (string) file_get_contents($root . '/admin/_guard.php');
$assert(str_contains($guardSource, 'Csrf::requireValid'), 'Seluruh POST pada route admin dijaga CSRF di server');

foreach (['index.php', 'berita.php', 'detail.php', 'download.php', 'galeri.php'] as $publicPage) {
    $source = (string) file_get_contents($root . '/' . $publicPage);
    $connectionPosition = strpos($source, 'koneksi.php');
    $headerPosition = strpos($source, 'header.php');
    $assert(
        $connectionPosition !== false && $headerPosition !== false && $connectionPosition < $headerPosition,
        $publicPage . ' memuat bootstrap/koneksi sebelum menghasilkan header HTML'
    );
}

$protectedExceptions = ['SimpleXLSX.php', 'SimpleXLSXGen.php', 'admin_login.php', 'cek_login.php', 'logout.php', 'ubah_password.php', 'pertemuan_pengajian.php'];
foreach (glob($root . '/admin/*.php') ?: [] as $file) {
    if (in_array(basename($file), $protectedExceptions, true)) {
        continue;
    }
    $source = (string) file_get_contents($file);
    $assert(str_contains($source, '_guard.php') || basename($file) === '_guard.php', basename($file) . ' memakai guard role admin');
}
$meetingSource = (string) file_get_contents($root . '/admin/pertemuan_pengajian.php');
$assert(
    str_contains($meetingSource, 'requireWebUser()') && str_contains($meetingSource, "in_array('admin'") && str_contains($meetingSource, "in_array('guru'"),
    'pertemuan_pengajian.php memakai guard pengguna dan membatasi role admin/guru'
);

$migration = (string) file_get_contents($root . '/database/migrations/001_phase1_security.sql');
foreach (['roles', 'user_roles', 'api_tokens', 'audit_logs', 'users_guru_unique', 'force_password_change'] as $required) {
    $assert(str_contains($migration, $required), 'Migrasi memuat ' . $required);
}

$hash = password_hash('UjiPassword123!', PASSWORD_DEFAULT);
$assert(password_verify('UjiPassword123!', $hash), 'Runtime mendukung password_hash/password_verify');

require_once $root . '/app/Http/Csrf.php';
$_SESSION = [];
$token = App\Http\Csrf::token();
$assert(strlen($token) === 64 && App\Http\Csrf::validate($token), 'Token CSRF valid dapat dibuat dan diverifikasi');
$assert(!App\Http\Csrf::validate(str_repeat('0', 64)), 'Token CSRF salah ditolak');

exit($failures === [] ? 0 : 1);
