<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$manifestPath = $argv[1] ?? '';
if ($manifestPath === '' || !is_file($manifestPath)) {
    fwrite(STDERR, "Pemakaian: php bin/verify_restore.php /path/manifest.json\n");
    exit(1);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
$failed = false;
foreach ($manifest['row_counts'] as $table => $expected) {
    $identifier = '`' . str_replace('`', '``', $table) . '`';
    $result = app_db()->query('SELECT COUNT(*) total FROM ' . $identifier);
    $actual = $result ? (int) $result->fetch_assoc()['total'] : -1;
    $ok = $actual === (int) $expected;
    echo ($ok ? '[sesuai] ' : '[berbeda] ') . $table . ': harapan=' . $expected . ', aktual=' . $actual . "\n";
    $failed = $failed || !$ok;
}

exit($failed ? 2 : 0);

