<?php

declare(strict_types=1);

/**
 * Pekerja konkurensi uji V5 Fase 2. Satu proses = satu koneksi DB = satu
 * permintaan koreksi atau hasil rujukan. Menunggu berkas aba-aba agar seluruh
 * pekerja mulai bersamaan, lalu mencetak satu baris JSON hasil (tanpa isi).
 *
 *   DB_NAME=test_v5_f2_test php tests/v5_phase2_worker.php <user_id> <kunjungan_id> <berkas-masukan.json> <berkas-aba-aba>
 */

if (PHP_SAPI !== 'cli' || $argc < 5) {
    exit(2);
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (!preg_match('/^test_.*_test$/', (string) app_config('database.database'))) {
    exit(2);
}
[$_, $userId, $kunjunganId, $berkas, $aba] = $argv;
$masukan = json_decode((string) file_get_contents($berkas), true, 32, JSON_THROW_ON_ERROR);
$batas = microtime(true) + 20;
while (!is_file($aba) && microtime(true) < $batas) {
    usleep(2000);
}
try {
    $user = ['id' => (int) $userId, 'roles' => ['pengurus']];
    $hasil = isset($masukan['rujukan_id'])
        ? kesehatan_service()->hasilRujukan($user, (int) $kunjunganId, (int) $masukan['rujukan_id'], $masukan, 'api')
        : kesehatan_service()->koreksi($user, (int) $kunjunganId, $masukan, 'api');
    echo json_encode(['ok' => true] + $hasil) . PHP_EOL;
} catch (\App\Kesehatan\KesehatanException $e) {
    echo json_encode(['ok' => false, 'status' => $e->status]) . PHP_EOL;
}
