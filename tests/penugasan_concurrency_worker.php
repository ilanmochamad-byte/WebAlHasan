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
    app_db()->query('SET innodb_lock_wait_timeout = 2');
    if ($aksi === 'ubah') {
        penugasan_service()->ubah($jenis, (int) $isian['id'], $isian + ['alasan' => 'Uji perubahan bersamaan'], $actorId);
    } elseif ($aksi === 'lama_buat') {
        $hasil['id'] = $jenis === 'murobi' ? master_data_service()->saveMurobi($isian, $actorId) : pembimbing_service()->create($isian, $actorId);
    } elseif ($aksi === 'lama_status') {
        if ($jenis === 'murobi') { master_data_service()->setMurobiState((int) $isian['id'], $isian['action'], $actorId, 'Uji status bersamaan'); }
        else { pembimbing_service()->setState((int) $isian['id'], $isian['action'], $actorId, 'Uji status bersamaan'); }
    } elseif ($aksi === 'lock_read' || $aksi === 'deadlock_read') {
        if ($aksi === 'deadlock_read') {
            app_db()->begin_transaction();
            app_db()->query('SELECT id FROM guru WHERE id = ' . (int) $isian['lock_id'] . ' FOR UPDATE');
            file_put_contents($isian['sync'] . '/' . (int) $isian['lock_id'], 'ready');
            $deadline = microtime(true) + 5;
            while (!is_file($isian['sync'] . '/' . (int) $isian['guru_id'])) {
                if (microtime(true) > $deadline) { throw new RuntimeException('Deadlock barrier timeout'); }
                usleep(10000);
            }
        }
        $repo = match ($jenis) {
            'murobi' => new App\MasterData\MasterDataRepository(app_db()),
            'pembimbing' => new App\Izin\PembimbingRepository(app_db()),
            'akun' => new App\Account\AccountRepository(app_db()),
            'penempatan' => new App\MasterData\PenempatanRepository(app_db()),
            'alumni' => new App\MasterData\AlumniRepository(app_db()),
            default => new App\Penugasan\PenugasanRepository(app_db()),
        };
        $method = new ReflectionMethod($repo, $jenis === 'pembimbing' ? 'select' : 'all');
        $method->invoke($repo, 'SELECT id FROM guru WHERE id = ? FOR UPDATE', [(int) $isian['guru_id']]);
        if ($aksi === 'deadlock_read') { app_db()->commit(); }
    } elseif ($aksi === 'aktifkan') {
        penugasan_service()->aktifkan($jenis, (int) ($isian['id'] ?? 0), 'Uji bersamaan aktifkan', $actorId);
        $hasil['id'] = (int) ($isian['id'] ?? 0);
    } else {
        $hasil['id'] = penugasan_service()->buat($jenis, $isian, $actorId);
    }
    $hasil['berhasil'] = true;
} catch (App\Penugasan\PenugasanException $exception) {
    $hasil['status'] = $exception->status();
    $hasil['pesan'] = $exception->getMessage();
} catch (App\Izin\IzinException $exception) {
    $hasil['status'] = $exception->status();
    $hasil['pesan'] = $exception->getMessage();
} catch (App\MasterData\MasterDataException $exception) {
    $hasil['pesan'] = $exception->getMessage();
} catch (Throwable $exception) {
    $hasil['pesan'] = get_class($exception) . ': ' . $exception->getMessage();
}

if ($aksi === 'deadlock_read') { app_db()->rollback(); }
fwrite(STDOUT, json_encode($hasil, JSON_UNESCAPED_UNICODE) . PHP_EOL);
exit(0);
