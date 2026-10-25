<?php

declare(strict_types=1);

/**
 * Pekerja konkurensi uji V6 Fase 3. Satu proses = satu koneksi DB = satu
 * permintaan. Menunggu berkas aba-aba agar seluruh pekerja mulai bersamaan,
 * lalu mencetak satu baris JSON hasil.
 *
 *   DB_NAME=test_v6_f3_test php tests/v6_phase3_worker.php <aba> <user_id> belanja <santri_id> <kunci> <nominal>
 *   … <aba> <user_id> penarikan <santri_id> <kunci> <nominal>
 *   … <aba> <user_id> bayartab <tagihan_id> <kunci> <nominal>
 *   … <aba> <user_id> bayartunai <tagihan_id> <kunci> <nominal>
 *   … <aba> <user_id> verifikasi <setoran_transfer_id> <kunci>
 *   … <aba> <user_id> koreksi <transaksi_id> <kunci>
 *   … <aba> <user_id> batal <pembayaran_id> <kunci>
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
    $tab = tabungan_service();
    $tag = tagihan_service();
    $hasil = match ($op) {
        'belanja', 'penarikan' => $tab->penarikan($user, (int) $a[0], ['idempotency_key' => $a[1], 'jenis' => $op === 'belanja' ? 'Belanja' : 'Penarikan', 'nominal' => $a[2], 'metode' => 'Tunai', 'tujuan' => 'Uji konkurensi'], 'api'),
        'bayartab' => $tag->bayarDariTabungan($user, (int) $a[0], ['idempotency_key' => $a[1], 'nominal' => $a[2], 'setuju' => '1'], 'api'),
        'bayartunai' => $tag->catatPembayaran($user, (int) $a[0], ['idempotency_key' => $a[1], 'nominal' => $a[2], 'sumber' => 'Tunai', 'tanggal_transaksi' => date('Y-m-d')], 'api'),
        'verifikasi' => $tab->verifikasiSetoran($user, (int) $a[0], ['idempotency_key' => $a[1]], 'api'),
        'koreksi' => $tab->koreksi($user, (int) $a[0], ['idempotency_key' => $a[1], 'alasan' => 'Uji konkurensi koreksi'], 'api'),
        'batal' => $tag->batalkanPembayaran($user, (int) $a[0], ['idempotency_key' => $a[1], 'alasan' => 'Uji konkurensi pembatalan'], 'api'),
        default => throw new RuntimeException('operasi'),
    };
    echo json_encode(['ok' => true] + $hasil) . PHP_EOL;
} catch (\App\Bsa\BsaException $e) {
    echo json_encode(['ok' => false, 'status' => $e->status, 'errno' => $e->errno]) . PHP_EOL;
}
