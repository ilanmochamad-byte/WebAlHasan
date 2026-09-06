<?php

declare(strict_types=1);

/**
 * Proses anak untuk pengujian penempatan pada PERMINTAAN BERSAMAAN
 * (keputusan pengguna 6 September 2026).
 *
 * Dijalankan `tests/penempatan_concurrency.php` sebagai beberapa proses PHP
 * NYATA — masing-masing dengan koneksi basis datanya sendiri — yang mencoba
 * mengisi tempat terakhir sebuah kamar pada detik yang sama.
 *
 * Argumen:
 *   --at=<unix float>   waktu mulai bersama
 *   --santri=<id>       santri yang ditempatkan proses ini
 *   --kamar=<id>        kamar tujuan
 *   --actor=<id>        pelaku (untuk audit)
 *   --aksi=kamar|kelas  jenis penempatan
 *   --kelas=<id>        kelas tujuan (untuk aksi=kelas)
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

use App\MasterData\PenempatanService;

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDOUT, json_encode(['ok' => false, 'pesan' => 'DB bukan _test']) . PHP_EOL);
    exit(2);
}

$santriId = (int) ($options['santri'] ?? 0);
$kamarId = (int) ($options['kamar'] ?? 0);
$kelasId = (int) ($options['kelas'] ?? 0);
$actorId = (int) ($options['actor'] ?? 0);
$aksi = (string) ($options['aksi'] ?? 'kamar');
$mulai = (float) ($options['at'] ?? microtime(true));

$_SESSION = ['user_id' => $actorId];

// Menunggu detik yang sama supaya benar-benar bersamaan.
$jeda = $mulai - microtime(true);
if ($jeda > 0) {
    usleep((int) ($jeda * 1_000_000));
}

$hasil = ['santri' => $santriId, 'aksi' => $aksi, 'berhasil' => false, 'pesan' => null];
try {
    if ($aksi === 'kelas') {
        penempatan_service()->apply(PenempatanService::AKSI_KELAS_TETAPKAN, [$santriId], ['kelas_id' => $kelasId], $actorId);
    } else {
        penempatan_service()->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santriId], ['kamar_id' => $kamarId], $actorId);
    }
    $hasil['berhasil'] = true;
} catch (Throwable $exception) {
    $hasil['pesan'] = $exception->getMessage();
}

fwrite(STDOUT, json_encode($hasil, JSON_UNESCAPED_UNICODE) . PHP_EOL);
exit(0);
