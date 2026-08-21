<?php

declare(strict_types=1);

/**
 * Worker concurrency V2 Fase 3.
 *
 * Dijalankan sebagai proses PHP terpisah oleh `tests/v2_phase3_api_contract.php`
 * agar dua request REST benar-benar tiba bersamaan (bukan berurutan dalam satu
 * proses). Worker hanya melakukan satu POST HTTP lalu mencetak satu baris JSON:
 *
 *   {"status":201,"body":{...}}
 *
 * Argumen:
 *   --url=<url absolut>   endpoint tujuan
 *   --token=<bearer>      token API pemanggil
 *   --payload=<base64>    body JSON dalam base64 (aman untuk argumen shell)
 *   --at=<microtime>      waktu mulai bersama (float, hasil microtime(true))
 */

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z_]+)=(.*)$/s', $argument, $matches) === 1) {
        $options[$matches[1]] = $matches[2];
    }
}

foreach (['url', 'token', 'payload', 'at'] as $required) {
    if (!isset($options[$required])) {
        fwrite(STDERR, 'Argumen --' . $required . ' wajib diisi.' . PHP_EOL);
        exit(2);
    }
}

$body = base64_decode((string) $options['payload'], true);
if ($body === false) {
    fwrite(STDERR, 'Payload tidak valid.' . PHP_EOL);
    exit(2);
}

// Menunggu sampai detik yang disepakati agar kedua proses berangkat bersamaan.
$startAt = (float) $options['at'];
while (microtime(true) < $startAt) {
    usleep(200);
}

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . (string) $options['token'],
        ]),
        'content' => $body,
        'timeout' => 30,
        'ignore_errors' => true,
    ],
]);

$response = @file_get_contents((string) $options['url'], false, $context);
$status = 0;
foreach ($http_response_header ?? [] as $line) {
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
        $status = (int) $matches[1];
    }
}

echo json_encode([
    'status' => $status,
    'body' => $response === false ? null : json_decode($response, true),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(0);
