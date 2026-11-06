<?php

declare(strict_types=1);

// Dua urutan kunci terkendali pada fixture sintetis test_*_test.
// PPA_DB=test_ppa_codex_test php tests/perbaikan_peserta_absensi_konkurensi.php
use App\Api\ApiException;
use App\Schedule\PesertaPertemuan;

$dbName = getenv('PPA_DB') ?: 'test_ppa_codex_test';
if (!preg_match('/^test_.*_test$/', $dbName)) { exit(2); }
putenv('DB_NAME=' . $dbName);
require dirname(__DIR__) . '/app/bootstrap.php';
if (($argv[1] ?? '') === '--worker') {
    $input = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    echo "ready\n";
    flush();
    try {
        if ($input['operation'] === 'refresh') {
            teacher_api_service()->refreshParticipants($input['meeting'], $input['user']);
        } else {
            teacher_api_service()->saveAttendance($input['meeting'], $input['payload'], $input['user']);
        }
        echo "200\n";
    } catch (ApiException $e) { echo $e->status() . ' ' . $e->errorCode() . "\n"; }
    exit;
}
$ids = require __DIR__ . '/jadwal_kelas_gabungan_f1_seed.php';
$db = app_db();
$admin = ['id' => $ids['admin_id'], 'roles' => ['admin']];
$service = schedule_service();
$date = (new DateTimeImmutable('next thursday'))->format('Y-m-d');
$jadwal = (int) $service->save(['id_tahun' => $ids['tahun'], 'id_kelas' => $ids['kelas']['C'], 'id_guru' => $ids['guru_h'],
    'hari' => 'Kamis', 'waktu_mulai' => '03:00', 'waktu_selesai' => '03:30', 'waktu_sholat' => "Ba'da Isya",
    'fan_ilmu' => 'Konkurensi ' . $ids['sfx'], 'nama_kitab' => 'Uji', 'tempat' => 'Uji'], $ids['admin_id'])['id'];
$pass = 0; $fail = 0;
$ok = static function (bool $value, string $label) use (&$pass, &$fail): void {
    echo ($value ? '[lulus] ' : '[gagal] ') . $label . "\n";
    $value ? $pass++ : $fail++;
};
$start = static function (array $input) use ($ok): array {
    $file = tempnam(sys_get_temp_dir(), 'ppa-worker-');
    file_put_contents($file, json_encode($input, JSON_THROW_ON_ERROR));
    $proc = proc_open([PHP_BINARY, __FILE__, '--worker', $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_set_timeout($pipes[1], 10);
    $ok(trim((string) fgets($pipes[1])) === 'ready', 'Proses kedua siap mencoba transaksi');
    usleep(250000);
    $ok(proc_get_status($proc)['running'], 'Proses kedua menunggu kunci pertemuan');
    return [$proc, $pipes, $file];
};
$finish = static function (array $worker): string {
    [$proc, $pipes, $file] = $worker;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) { fclose($pipe); }
    proc_close($proc); unlink($file);
    return trim($out . $err);
};
$payload = static fn (array $students, string $key): array => ['idempotency_key' => $key, 'teacher' => ['status' => 'Hadir'],
    'students' => array_map(static fn (int $sid): array => ['student_id' => $sid, 'status' => 'Hadir'], $students)];
$meeting = $service->open($jadwal, $date, '', $admin);
$body = $payload($ids['santri']['C'], 'ppa-save-first-' . $ids['sfx']);
$worker = null;
teacher_api_service()->saveAttendance($meeting, $body, $admin, function () use (&$worker, $start, $meeting, $admin): void {
    $worker = $start(['operation' => 'refresh', 'meeting' => $meeting, 'user' => $admin]);
});
$ok($finish($worker) === '409 MEETING_NOT_OPEN', 'Simpan lebih dahulu: refresh menunggu lalu ditolak 409');
$ok((int) $db->query("SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id=$meeting")->fetch_row()[0] === 2, 'Seluruh absensi tetap tersimpan');

$meeting = $service->open($jadwal, (new DateTimeImmutable($date))->modify('+7 days')->format('Y-m-d'), '', $admin);
master_data_service()->endActiveClass($ids['santri']['C'][0]);
$db->begin_transaction();
try {
    $db->query("SELECT id FROM pertemuan_pengajian WHERE id=$meeting FOR UPDATE");
    $result = PesertaPertemuan::perbarui($db, $meeting, $ids['kelas']['C'], $ids['tahun']);
    $worker = $start(['operation' => 'save', 'meeting' => $meeting, 'user' => $admin,
        'payload' => $payload($ids['santri']['C'], 'ppa-refresh-first-' . $ids['sfx'])]);
    $db->commit();
} catch (Throwable $e) { $db->rollback(); throw $e; }
$ok($finish($worker) === '422 VALIDATION_FAILED', 'Refresh lebih dahulu: simpan daftar lama ditolak 422 setelah menunggu');
$ok($result['dikeluarkan'] === [$ids['santri']['C'][0]]
    && (int) $db->query("SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id=$meeting")->fetch_row()[0] === 0,
    'Peserta berubah dan penulisan absensi lama rollback utuh');
echo "Hasil: $pass lulus, $fail gagal\n";
exit($fail === 0 ? 0 : 1);
