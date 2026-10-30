<?php

declare(strict_types=1);

// Satu proses/koneksi untuk probe auditor. Hanya fixture, bukan produksi.
if (PHP_SAPI !== 'cli' || $argc !== 4 || !preg_match('/^test_.*_test$/D', (string) getenv('DB_NAME'))) { exit(2); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
$end = microtime(true) + 20;
while (!is_file($argv[1]) && microtime(true) < $end) { usleep(2000); }
try {
    $input = json_decode((string) base64_decode($argv[3], true), true, 512, JSON_THROW_ON_ERROR);
    $r = tagihan_service()->terbitkanMassal(['id' => (int) $argv[2]], $input, 'api');
    echo json_encode(['ok' => true] + $r) . PHP_EOL;
} catch (App\Bsa\BsaException $e) {
    echo json_encode(['ok' => false, 'status' => $e->status, 'errno' => $e->errno]) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'unexpected' => get_class($e)]) . PHP_EOL;
}
