<?php

declare(strict_types=1);

/**
 * Proses anak untuk pengujian perlindungan admin terakhir pada permintaan
 * bersamaan (paket perapihan V1–V2, koreksi ke-1).
 *
 * Dijalankan `tests/perapihan_akun_concurrency.php` sebagai beberapa proses PHP
 * NYATA yang mencoba mencabut hak admin pada detik yang sama. Setiap proses
 * mencabut admin dari akun yang BERBEDA, sehingga tanpa penguncian baris di
 * dalam transaksi semuanya bisa lolos bersama dan menyisakan nol admin.
 *
 * Argumen:
 *   --at=<unix float>  waktu mulai bersama
 *   --user=<id>        akun yang hak adminnya dicabut proses ini
 *   --actor=<id>       pelaku (untuk audit)
 *   --aksi=revoke|nonaktif
 *
 * Keluaran: satu baris JSON pada stdout.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z_]+)=(.*)$/', $argument, $matches) === 1) {
        $options[$matches[1]] = $matches[2];
    }
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDOUT, json_encode(['ok' => false, 'pesan' => 'DB bukan _test']) . PHP_EOL);
    exit(2);
}

$userId = (int) ($options['user'] ?? 0);
$actorId = (int) ($options['actor'] ?? 0);
$aksi = (string) ($options['aksi'] ?? 'revoke');
$mulai = (float) ($options['at'] ?? microtime(true));

// Menunggu detik yang sama supaya benar-benar bersamaan.
$jeda = $mulai - microtime(true);
if ($jeda > 0) {
    usleep((int) ($jeda * 1_000_000));
}

$hasil = ['user' => $userId, 'aksi' => $aksi, 'berhasil' => false, 'pesan' => null];
try {
    if ($aksi === 'nonaktif') {
        account_service()->setActive($userId, false, $actorId);
    } else {
        account_service()->revokeRole($userId, 'admin', $actorId);
    }
    $hasil['berhasil'] = true;
} catch (Throwable $exception) {
    $hasil['pesan'] = $exception->getMessage();
}

fwrite(STDOUT, json_encode($hasil, JSON_UNESCAPED_UNICODE) . PHP_EOL);
exit(0);
