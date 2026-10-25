<?php

declare(strict_types=1);

/**
 * Pekerja konkurensi uji V6 Fase 1. Satu proses = satu koneksi DB = satu
 * permintaan setoran/penarikan. Menunggu berkas aba-aba agar seluruh pekerja
 * mulai bersamaan, lalu mencetak satu baris JSON hasil.
 *
 *   DB_NAME=test_v6_f1_test php tests/v6_phase1_worker.php <user_id> <santri_id> <setoran|penarikan> <kunci> <nominal> <berkas-aba-aba>
 */

if (PHP_SAPI !== 'cli' || $argc < 7) {
    exit(2);
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (!preg_match('/^test_.*_test$/', (string) app_config('database.database'))) {
    exit(2);
}
[$_, $userId, $santriId, $operasi, $kunci, $nominal, $aba] = $argv;
$batas = microtime(true) + 20;
while (!is_file($aba) && microtime(true) < $batas) {
    usleep(2000);
}
$input = ['idempotency_key' => $kunci, 'nominal' => $nominal, 'metode' => 'Tunai', 'tujuan' => 'Uji konkurensi'];
try {
    $svc = bsa_service();
    $user = ['id' => (int) $userId, 'roles' => ['pengurus']];
    $hasil = $operasi === 'penarikan'
        ? $svc->penarikan($user, (int) $santriId, $input, 'api')
        : $svc->setoran($user, (int) $santriId, $input, 'api');
    echo json_encode(['ok' => true] + $hasil) . PHP_EOL;
} catch (\App\Bsa\BsaException $e) {
    echo json_encode(['ok' => false, 'status' => $e->status, 'errno' => $e->errno]) . PHP_EOL;
}
