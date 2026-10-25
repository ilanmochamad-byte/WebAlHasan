<?php

declare(strict_types=1);

/**
 * Pekerja konkurensi uji V6 Fase 2. Satu proses = satu koneksi DB = satu
 * permintaan. Menunggu berkas aba-aba agar seluruh pekerja mulai bersamaan,
 * lalu mencetak satu baris JSON hasil.
 *
 *   DB_NAME=test_v6_f2_test php tests/v6_phase2_worker.php <berkas-aba-aba> <user_id> bayar <tagihan_id> <kunci> <nominal>
 *   … <aba> <user_id> verifikasi <pembayaran_id> <kunci>
 *   … <aba> <user_id> batal <pembayaran_id> <kunci>
 *   … <aba> <user_id> massal <periode> <jatuh_tempo> <hash> <kunci>
 *   … <aba> <user_id> revisi <tagihan_id> <versi> <kunci> <nominal>
 */

if (PHP_SAPI !== 'cli' || $argc < 5) {
    exit(2);
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (!preg_match('/^test_.*_test$/', (string) app_config('database.database'))) {
    exit(2);
}
$aba = $argv[1];
$user = ['id' => (int) $argv[2], 'roles' => ['pengurus']];
$op = $argv[3];
$a = array_slice($argv, 4);
$batas = microtime(true) + 20;
while (!is_file($aba) && microtime(true) < $batas) {
    usleep(2000);
}
try {
    $svc = tagihan_service();
    $hasil = match ($op) {
        'bayar' => $svc->catatPembayaran($user, (int) $a[0], ['idempotency_key' => $a[1], 'nominal' => $a[2], 'sumber' => 'Tunai', 'tanggal_transaksi' => date('Y-m-d')], 'api'),
        'verifikasi' => $svc->verifikasiPembayaran($user, (int) $a[0], ['idempotency_key' => $a[1]], 'api'),
        'batal' => $svc->batalkanPembayaran($user, (int) $a[0], ['idempotency_key' => $a[1], 'alasan' => 'Uji konkurensi pembatalan'], 'api'),
        'massal' => $svc->terbitkanMassal($user, ['periode' => $a[0], 'jatuh_tempo' => $a[1], 'pratinjau_hash' => $a[2], 'idempotency_key' => $a[3]], 'api'),
        'revisi' => $svc->revisiTagihan($user, (int) $a[0], ['versi' => $a[1], 'idempotency_key' => $a[2], 'jenis' => 'Potongan', 'nominal' => $a[3], 'alasan' => 'Uji konkurensi revisi'], 'api'),
        default => throw new RuntimeException('operasi'),
    };
    echo json_encode(['ok' => true] + $hasil) . PHP_EOL;
} catch (\App\Bsa\BsaException $e) {
    echo json_encode(['ok' => false, 'status' => $e->status, 'errno' => $e->errno]) . PHP_EOL;
}
