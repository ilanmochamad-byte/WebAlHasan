<?php

declare(strict_types=1);

/**
 * Pekerja konkurensi PRD V7 Fase 2: satu proses = satu koneksi DB.
 *
 *   DB_NAME=test_v7_f2_test php tests/v7_phase2_worker.php <aksi> <username> <pengajuan_id> <versi> <kunci> <mulai_unix_ms> [nilai]
 *
 * aksi:
 *   teruskan|minta_perbaikan|tolak  IzinWaliService::tinjau (pembimbing)
 *   batal                           IzinWaliService::batalkan (wali pengaju)
 *   perbaiki                        IzinWaliService::perbaiki; nilai = tgl_kembali baru
 *   putus                           IzinWorkflowService::decide V2 peran murobi; nilai = hasil
 *   putus_admin                     IzinWorkflowService::decide V2 admin pengganti
 *   tetapkan_murobi                 IzinWorkflowService::assignMurobi V2; nilai = guru_id
 * Semua pekerja menunggu sampai `mulai_unix_ms` agar benar-benar berangkat
 * bersamaan. Mencetak satu baris JSON hasil.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

[$aksi, $username, $id, $versi, $kunci, $mulai] = array_slice($argv, 1, 6);
$nilai = $argv[7] ?? null;
if (!preg_match('/^test_.*_test$/', (string) app_config('database.database'))) {
    exit(2);
}
$db = app_db();
$stmt = $db->prepare('SELECT u.id, u.guru_id, GROUP_CONCAT(r.slug) AS roles FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id WHERE u.username = ? GROUP BY u.id, u.guru_id');
$stmt->bind_param('s', $username);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$user = ['id' => (int) $row['id'], 'roles' => explode(',', (string) $row['roles']), 'guru_id' => $row['guru_id'] === null ? null : (int) $row['guru_id']];
$meta = ['ip' => '127.0.0.1', 'user_agent' => 'v7f2-worker'];
$id = (int) $id;
$versi = (int) $versi;

while ((int) floor(microtime(true) * 1000) < (int) $mulai) {
    usleep(500);
}
try {
    $w = izin_wali_service();
    $hasil = match ($aksi) {
        'teruskan', 'minta_perbaikan', 'tolak' => $w->tinjau($user, $id, $aksi, $aksi === 'teruskan' ? null : 'Catatan uji konkurensi', $versi, $kunci, $meta),
        'batal' => $w->batalkan($user, $id, 'Batal uji konkurensi', $versi, $kunci, $meta),
        'perbaiki' => $w->perbaiki($user, $id, ['tgl_izin' => '2027-03-01', 'tgl_kembali' => (string) $nilai, 'alasan' => 'Perbaikan uji konkurensi'], $versi, $kunci, $meta),
        'putus' => izin_workflow_service()->decide($user, $id, (string) ($nilai ?? 'Disetujui'), 'Keputusan uji konkurensi', null, $versi, $kunci, $meta, 'murobi'),
        'putus_admin' => izin_workflow_service()->decide($user, $id, (string) ($nilai ?? 'Disetujui'), 'Keputusan uji konkurensi', 'Murobi berhalangan', $versi, $kunci, $meta, 'admin'),
        'tetapkan_murobi' => izin_workflow_service()->assignMurobi($user, $id, (int) $nilai, 'Penetapan uji konkurensi', $versi, $kunci, $meta),
        default => throw new RuntimeException('Aksi tidak dikenal'),
    };
    echo json_encode(['ok' => true, 'aksi' => $aksi, 'id' => (int) $hasil['id'], 'replay' => (bool) ($hasil['idempotent_replay'] ?? false), 'status' => $hasil['status']]) . PHP_EOL;
} catch (\App\Izin\IzinException $e) {
    echo json_encode(['ok' => false, 'aksi' => $aksi, 'http' => $e->status(), 'pesan' => $e->getMessage()]) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'aksi' => $aksi, 'http' => 500, 'pesan' => get_class($e) . ': ' . $e->getMessage()]) . PHP_EOL;
}
