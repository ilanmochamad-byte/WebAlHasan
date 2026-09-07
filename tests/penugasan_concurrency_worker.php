<?php

declare(strict_types=1);

/**
 * Proses anak untuk pengujian penugasan pada PERMINTAAN BERSAMAAN
 * (audit fondasi penugasan V3–V6, 7 September 2026).
 *
 * Dijalankan `tests/penugasan_concurrency.php` sebagai beberapa proses PHP
 * NYATA — masing-masing dengan koneksi basis datanya sendiri — yang mencoba
 * membuat/mengaktifkan penugasan yang saling bertumpang tindih pada detik yang
 * sama. Inilah bentuk paling jujur dari "klik ganda" dan "dua admin bersamaan".
 *
 * Argumen:
 *   --at=<unix float>    waktu mulai bersama
 *   --aksi=buat|aktifkan
 *   --jenis=<jenis>
 *   --isian=<json>       isian formulir (buat) atau {"id":N} (aktifkan)
 *   --actor=<id>
 *
 * Keluaran: satu baris JSON pada stdout.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z_]+)=(.*)$/s', $argument, $matches) === 1) {
        $options[$matches[1]] = $matches[2];
    }
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDOUT, json_encode(['berhasil' => false, 'pesan' => 'DB bukan _test']) . PHP_EOL);
    exit(2);
}

$actorId = (int) ($options['actor'] ?? 0);
$jenis = (string) ($options['jenis'] ?? '');
$aksi = (string) ($options['aksi'] ?? 'buat');
$isian = json_decode((string) ($options['isian'] ?? '{}'), true) ?: [];
$mulai = (float) ($options['at'] ?? microtime(true));

$_SESSION = ['user_id' => $actorId];

$jeda = $mulai - microtime(true);
if ($jeda > 0) {
    usleep((int) ($jeda * 1_000_000));
}

$hasil = ['berhasil' => false, 'id' => null, 'status' => null, 'pesan' => null];
try {
    if ($aksi === 'aktifkan') {
        penugasan_service()->aktifkan($jenis, (int) ($isian['id'] ?? 0), 'Uji bersamaan aktifkan', $actorId);
        $hasil['id'] = (int) ($isian['id'] ?? 0);
    } else {
        $hasil['id'] = penugasan_service()->buat($jenis, $isian, $actorId);
    }
    $hasil['berhasil'] = true;
} catch (App\Penugasan\PenugasanException $exception) {
    $hasil['status'] = $exception->status();
    $hasil['pesan'] = $exception->getMessage();
} catch (Throwable $exception) {
    $hasil['pesan'] = get_class($exception) . ': ' . $exception->getMessage();
}

fwrite(STDOUT, json_encode($hasil, JSON_UNESCAPED_UNICODE) . PHP_EOL);
exit(0);
