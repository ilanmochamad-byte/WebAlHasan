<?php

declare(strict_types=1);

use App\Izin\IzinException;

/**
 * Pekerja uji konkurensi V2 Fase 2.
 *
 * Dipanggil oleh tests/v2_phase2_integration.php sebagai PROSES TERPISAH agar dua
 * request benar-benar berjalan bersamaan (bukan sekadar berurutan). Kedua proses
 * menunggu penanda waktu yang sama sebelum mengeksekusi mutasi, sehingga keduanya
 * masuk ke transaksi pada saat yang praktis sama.
 *
 * Keluaran: satu baris JSON pada STDOUT.
 *
 * Hanya berjalan pada database berakhiran `_test`.
 */

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Pekerja uji ditolak: DB_NAME wajib berakhiran _test.\n");
    exit(2);
}

$args = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z_]+)=(.*)$/', $argument, $matches) === 1) {
        $args[$matches[1]] = $matches[2];
    }
}

$userId = (int) ($args['user'] ?? 0);
$user = auth_repository()->findActiveById($userId);
if ($user === null) {
    fwrite(STDOUT, json_encode(['ok' => false, 'status' => 0, 'message' => 'Akun uji tidak ditemukan']) . PHP_EOL);
    exit(2);
}
$_SESSION = ['user_id' => $userId];

$meta = ['ip' => '127.0.0.1', 'user_agent' => 'uji-konkurensi/' . (string) ($args['label'] ?? 'x')];
$startAt = (float) ($args['at'] ?? 0);
while (microtime(true) < $startAt) {
    usleep(200);
}

$workflow = izin_workflow_service();

try {
    $response = match ((string) ($args['op'] ?? '')) {
        'decide' => $workflow->decide(
            $user,
            (int) $args['pengajuan'],
            (string) $args['hasil'],
            (string) ($args['alasan'] ?? 'Keputusan uji konkurensi'),
            isset($args['alasan_penggantian']) ? (string) $args['alasan_penggantian'] : null,
            isset($args['version']) && $args['version'] !== '' ? (int) $args['version'] : null,
            (string) $args['key'],
            $meta,
            isset($args['mode']) && $args['mode'] !== '' ? (string) $args['mode'] : null
        ),
        'create' => $workflow->create(
            $user,
            [
                'santri_id' => (int) $args['santri'],
                'tgl_izin' => (string) $args['from'],
                'tgl_kembali' => (string) $args['to'],
                'alasan' => (string) ($args['alasan'] ?? 'Pengajuan uji konkurensi'),
                'catatan_pengurus' => '',
            ],
            (string) $args['key'],
            $meta,
            isset($args['mode']) && $args['mode'] !== '' ? (string) $args['mode'] : null
        ),
        default => throw new RuntimeException('Operasi pekerja uji tidak dikenal.'),
    };

    fwrite(STDOUT, json_encode(['ok' => true, 'status' => 200, 'response' => $response]) . PHP_EOL);
    exit(0);
} catch (IzinException $exception) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'status' => $exception->status(),
        'message' => $exception->getMessage(),
    ]) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'status' => 500,
        'message' => $exception->getMessage(),
    ]) . PHP_EOL);
    exit(0);
}
