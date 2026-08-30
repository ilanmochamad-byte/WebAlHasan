<?php
declare(strict_types=1);

// Audit A-01: tidak memerlukan database atau sesi.
require_once dirname(__DIR__) . '/app/Http/SafeRedirect.php';
function app_url(string $path = ''): string { return ($GLOBALS['base'] ?? '') . '/' . ltrim($path, '/'); }

$failed = 0;
foreach (['', '/pesantren'] as $base) {
    $GLOBALS['base'] = $base;
    $bad = [
        '/admin/../etc/passwd', '%2f%2fjahat', '/admin/admin_akun.php%00',
        '/admin/' . str_repeat('x', 600) . '.php', '\\\\host\\share',
        'javascript:alert(1)', 'data:text/html,hello', '//jahat/evil.php',
        '/admin/%2e%2e/evil.php', '/admin/%2E%2E/%2fjahat/evil.php',
        '/admin/%00admin_akun.php', '/admin/\\evil.php',
        '/admin/%252e%252e/evil.php', '/admin/./admin_akun.php',
        '/admin/logout.php', '/portal/index.php',
    ];
    foreach ($bad as $value) {
        $ok = App\Http\SafeRedirect::sanitize($value) === null;
        echo ($ok ? '[lulus]' : '[gagal]') . ' A-01 menolak ' . json_encode($value) . ' base=' . $base . PHP_EOL;
        $failed += !$ok;
    }
    foreach (['/admin/admin_akun.php', '/portal/izin_detail.php?id=5&mode=orang_tua'] as $value) {
        $ok = App\Http\SafeRedirect::sanitize($base . $value) === $base . $value;
        echo ($ok ? '[lulus]' : '[gagal]') . ' A-01 mempertahankan tujuan internal base=' . $base . PHP_EOL;
        $failed += !$ok;
    }
}
exit($failed ? 1 : 0);
