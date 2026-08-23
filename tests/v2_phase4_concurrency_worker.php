<?php

declare(strict_types=1);

/**
 * Proses anak untuk pengujian concurrency worker notifikasi (V2 Fase 4).
 *
 * Dijalankan `tests/v2_phase4_concurrency.php` sebagai DUA proses PHP nyata
 * yang memulai putaran worker pada detik yang sama. Setiap proses memakai
 * adapter uji WhatsApp yang menulis jurnal ber-lock, sehingga induk dapat
 * menghitung berapa pesan yang benar-benar "terkirim" secara keseluruhan.
 *
 * Argumen:
 *   --at=<unix float>   waktu mulai bersama
 *   --journal=<path>    berkas jurnal adapter uji
 *   --batas=<n>         jumlah baris per putaran
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

// Adapter uji: TIDAK menghubungi penyedia mana pun.
putenv('WHATSAPP_PROVIDER=fake');
putenv('WHATSAPP_FAKE_MODE=ok');
putenv('WHATSAPP_FAKE_JOURNAL=' . (string) ($options['journal'] ?? ''));
putenv('PUSH_TOKEN_KEY=' . base64_encode(str_repeat("\x2b", 32)));

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Notification\NotificationChannel;

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Worker uji ditolak: DB_NAME wajib berakhiran _test.\n");
    exit(2);
}

$startAt = (float) ($options['at'] ?? 0);
if ($startAt > 0) {
    $sisa = $startAt - microtime(true);
    if ($sisa > 0) {
        usleep((int) round($sisa * 1_000_000));
    }
}

try {
    $hasil = notification_dispatcher()->run(
        NotificationChannel::WHATSAPP,
        max(1, (int) ($options['batas'] ?? 25))
    );
    echo json_encode([
        'ok' => true,
        'dijalankan' => $hasil['dijalankan'],
        'diproses' => $hasil['diproses'],
        'terkirim' => $hasil['terkirim'],
        'gagal' => $hasil['gagal'],
        'alasan' => $hasil['alasan'],
    ], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'pesan' => $exception->getMessage()], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(1);
}
