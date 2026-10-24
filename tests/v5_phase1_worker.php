<?php

declare(strict_types=1);

/**
 * Pekerja konkurensi uji V5 Fase 1. Satu proses = satu koneksi DB = satu
 * permintaan pencatatan. Menunggu berkas aba-aba agar seluruh pekerja mulai
 * bersamaan, lalu mencetak satu baris JSON hasil.
 *
 *   DB_NAME=test_v5_f1_test php tests/v5_phase1_worker.php <user_id> <santri_id> <kunci> <keluhan> <waktu> <berkas-aba-aba>
 */

if (PHP_SAPI !== 'cli' || $argc < 7) {
    exit(2);
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (!preg_match('/^test_.*_test$/', (string) app_config('database.database'))) {
    exit(2);
}
[$_, $userId, $santriId, $kunci, $keluhan, $waktu, $aba] = $argv;
$batas = microtime(true) + 20;
while (!is_file($aba) && microtime(true) < $batas) {
    usleep(2000);
}
try {
    $hasil = kesehatan_service()->buat(['id' => (int) $userId, 'roles' => ['pengurus']], [
        'idempotency_key' => $kunci,
        'santri_id' => $santriId,
        'waktu_kunjungan' => $waktu,
        'keluhan' => $keluhan,
    ], 'api');
    echo json_encode(['ok' => true] + $hasil) . PHP_EOL;
} catch (\App\Kesehatan\KesehatanException $e) {
    echo json_encode(['ok' => false, 'status' => $e->status]) . PHP_EOL;
}
