<?php

declare(strict_types=1);

/**
 * Pekerja konkurensi PRD V7 Fase 1: satu proses = satu koneksi DB.
 *
 *   DB_NAME=test_v7_f1_test php tests/v7_phase1_worker.php <jalur> <username> <santri_id> <dari> <sampai> <kunci> <mulai_unix_ms> [alasan]
 *
 * jalur: `wali` (IzinWaliService::buat) atau `pengurus` (IzinWorkflowService::create
 * V2, peran pengurus). Semua pekerja menunggu sampai `mulai_unix_ms` agar
 * benar-benar berangkat bersamaan. Mencetak satu baris JSON hasil.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

[$jalur, $username, $santri, $dari, $sampai, $kunci, $mulai] = array_slice($argv, 1, 7);
$alasan = $argv[8] ?? 'Uji konkurensi V7';
if (!preg_match('/^test_.*_test$/', (string) app_config('database.database'))) {
    exit(2);
}
$db = app_db();
$stmt = $db->prepare('SELECT id, guru_id FROM users WHERE username = ?');
$stmt->bind_param('s', $username);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$user = ['id' => (int) $row['id'], 'roles' => $jalur === 'wali' ? ['orang_tua'] : ['pengurus'], 'guru_id' => null];
$meta = ['ip' => '127.0.0.1', 'user_agent' => 'v7-worker'];

while ((int) floor(microtime(true) * 1000) < (int) $mulai) {
    usleep(500);
}
try {
    $hasil = $jalur === 'wali'
        ? izin_wali_service()->buat($user, ['santri_id' => (int) $santri, 'tgl_izin' => $dari, 'tgl_kembali' => $sampai, 'alasan' => $alasan], $kunci, $meta)
        : izin_workflow_service()->create($user, ['santri_id' => (int) $santri, 'tgl_izin' => $dari, 'tgl_kembali' => $sampai, 'alasan' => $alasan], $kunci, $meta, 'pengurus');
    echo json_encode(['ok' => true, 'id' => (int) $hasil['id'], 'replay' => (bool) ($hasil['idempotent_replay'] ?? false), 'status' => $hasil['status']]) . PHP_EOL;
} catch (\App\Izin\IzinException $e) {
    echo json_encode(['ok' => false, 'http' => $e->status(), 'pesan' => $e->getMessage()]) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'http' => 500, 'pesan' => get_class($e) . ': ' . $e->getMessage()]) . PHP_EOL;
}
