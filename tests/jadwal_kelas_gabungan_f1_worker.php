<?php

declare(strict_types=1);

/**
 * Pekerja uji konkurensi Fase 1 kelas gabungan. Dijalankan sebagai proses
 * terpisah oleh `tests/jadwal_kelas_gabungan_f1_integration.php`:
 *
 *   php tests/jadwal_kelas_gabungan_f1_worker.php <mode> <json-argumen>
 *
 * mode: `web-open` (ScheduleService::open), `api-open` (TeacherService::openMeeting),
 *       `save` (ScheduleService::save). Menunggu sampai `mulai` (microtime) agar
 *       seluruh pekerja menembak bersamaan, lalu mencetak satu baris JSON hasil.
 */

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Ditolak: DB_NAME wajib berakhiran _test.\n");
    exit(2);
}
$mode = (string) ($argv[1] ?? '');
$arg = json_decode((string) ($argv[2] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
app_db();
while (microtime(true) < (float) $arg['mulai']) {
    usleep(500);
}
try {
    $hasil = match ($mode) {
        'web-open' => ['meeting_id' => schedule_service()->open((int) $arg['jadwal'], (string) $arg['tanggal'], '', $arg['user'])],
        'api-open' => ['meeting_id' => (int) teacher_api_service()->openMeeting((int) $arg['jadwal'], [
            'date' => (string) $arg['tanggal'], 'idempotency_key' => (string) $arg['kunci'],
        ], $arg['user'])['data']['id']],
        'save' => schedule_service()->save($arg['input'], (int) $arg['aktor'], (int) $arg['jadwal']),
        default => throw new InvalidArgumentException('Mode tidak dikenal.'),
    };
    echo json_encode(['ok' => true, 'mode' => $mode] + $hasil), PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'mode' => $mode, 'jenis' => get_class($e), 'pesan' => $e->getMessage()]), PHP_EOL;
}
