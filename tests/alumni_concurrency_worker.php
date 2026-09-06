<?php

declare(strict_types=1);

/**
 * Proses anak untuk pengujian alumni pada PERMINTAAN BERSAMAAN
 * (paket "Koreksi Pengelolaan Alumni", keputusan pengguna 6 September 2026).
 *
 * Dijalankan `tests/alumni_concurrency.php` sebagai beberapa proses PHP NYATA —
 * masing-masing dengan koneksi basis datanya sendiri — yang mencoba memproses
 * kelulusan santri yang sama pada detik yang sama. Inilah bentuk paling jujur
 * dari "klik ganda" dan "retry jaringan".
 *
 * Argumen:
 *   --at=<unix float>   waktu mulai bersama
 *   --santri=<id>       daftar ID santri, dipisah koma
 *   --actor=<id>        pelaku (untuk audit)
 *   --status=<status>   status keluar
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

$santriIds = array_values(array_filter(array_map('intval', explode(',', (string) ($options['santri'] ?? '')))));
$actorId = (int) ($options['actor'] ?? 0);
$status = (string) ($options['status'] ?? 'Lulus');
$mulai = (float) ($options['at'] ?? microtime(true));

$_SESSION = ['user_id' => $actorId];

// Menunggu detik yang sama supaya benar-benar bersamaan.
$jeda = $mulai - microtime(true);
if ($jeda > 0) {
    usleep((int) ($jeda * 1_000_000));
}

$hasil = ['santri' => $santriIds, 'berhasil' => false, 'alumni_id' => null, 'pesan' => null];
try {
    $keluaran = alumni_service()->terapkan($santriIds, [
        'status_keluar' => $status,
        'tgl_keluar' => '2026-06-30',
        'tahun_angkatan' => '2025/2026',
        'tingkat' => 'Tsanawi',
        'catatan' => 'Uji bersamaan',
    ], $actorId);
    $hasil['berhasil'] = true;
    $hasil['alumni_id'] = $keluaran['alumni_id'];
} catch (Throwable $exception) {
    $hasil['pesan'] = $exception->getMessage();
}

fwrite(STDOUT, json_encode($hasil, JSON_UNESCAPED_UNICODE) . PHP_EOL);
exit(0);
