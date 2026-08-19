<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;
use App\Schedule\ScheduleException;

$root = dirname(__DIR__);
if (getenv('PHASE3_RUN_INTEGRATION') !== '1') {
    fwrite(STDOUT, "[lewati] Set PHASE3_RUN_INTEGRATION=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}
require_once $root . '/app/bootstrap.php';
if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Pengujian integrasi ditolak: DB_NAME wajib berakhiran _test.\n");
    exit(2);
}

$db = app_db();
$scheduleService = schedule_service();
$masterService = master_data_service();
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) { $failures[] = $message; }
};
$admin = $db->query("SELECT u.id, u.name, u.guru_id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.slug='admin' AND u.is_active=1 LIMIT 1")?->fetch_assoc();
$activeYear = $db->query("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1")?->fetch_assoc();
if (!$admin || !$activeYear) { fwrite(STDERR, "Fixture admin atau semester aktif tidak tersedia.\n"); exit(2); }
$admin['id'] = (int) $admin['id']; $admin['guru_id'] = $admin['guru_id'] === null ? null : (int) $admin['guru_id']; $admin['roles'] = ['admin'];
$_SESSION['user_id'] = $admin['id'];
$suffix = strtoupper(bin2hex(random_bytes(3)));
$created = ['schedule' => [], 'meeting' => [], 'guru' => [], 'santri' => [], 'kelas' => []];
$monday = (new DateTimeImmutable('next monday'))->format('Y-m-d');

try {
    $guru1 = $masterService->saveGuru(['nip' => 'J1' . $suffix, 'nama_guru' => 'Guru Jadwal 1 ' . $suffix, 'no_hp' => '', 'status' => 'Guru']);
    $guru2 = $masterService->saveGuru(['nip' => 'J2' . $suffix, 'nama_guru' => 'Guru Jadwal 2 ' . $suffix, 'no_hp' => '', 'status' => 'Guru']);
    $created['guru'] = [$guru1, $guru2];
    $class1 = $masterService->saveClass(['nama_kelas' => 'Jadwal A ' . $suffix, 'jenjang' => 'Uji']);
    $class2 = $masterService->saveClass(['nama_kelas' => 'Jadwal B ' . $suffix, 'jenjang' => 'Uji']);
    $created['kelas'] = [$class1, $class2];
    $santri = $masterService->saveSantri(['nis'=>'J'.$suffix,'nama_santri'=>'Santri Jadwal '.$suffix,'jenis_kelamin'=>'L','tempat_lahir'=>'Ciamis','tgl_lahir'=>'2010-01-02','alamat'=>'','desa'=>'','kecamatan'=>'','kab_kota'=>'','provinsi'=>'','nama_ayah'=>'','no_hp_ayah'=>'','nama_ibu'=>'','no_hp_ibu'=>'','asal_sekolah'=>'','sekolah_saat_ini'=>'','foto'=>'default.jpg']);
    $created['santri'][] = $santri;
    $masterService->assignActiveClass(['santri_id'=>$santri,'kelas_id'=>$class1,'tanggal_mulai'=>date('Y-m-d')], $admin['id']);

    $base = ['id_tahun'=>(int)$activeYear['id'],'waktu_sholat'=>"Ba'da Isya",'hari'=>'Senin','waktu_mulai'=>'23:00','waktu_selesai'=>'23:30','id_kelas'=>$class1,'fan_ilmu'=>'Fiqih '.$suffix,'nama_kitab'=>'Kitab '.$suffix,'id_guru'=>$guru1,'tempat'=>'Ruang '.$suffix];
    $saved = $scheduleService->save($base, $admin['id']); $scheduleId = (int) $saved['id']; $created['schedule'][] = $scheduleId;
    $listed = $scheduleService->list(['year_id'=>$activeYear['id'],'q'=>$suffix,'state'=>'active'], 1, 100);
    $assert($listed['total'] === 1 && (int)$listed['rows'][0]['id'] === $scheduleId, 'Admin dapat membuat jadwal dan melihatnya pada filter semester aktif');
    $base['nama_kitab'] = 'Kitab Diubah ' . $suffix; $scheduleService->save($base, $admin['id'], $scheduleId);
    $assert(str_contains((string)$scheduleService->find($scheduleId)['nama_kitab'], 'Diubah'), 'Admin dapat mengubah jadwal dari service yang dipakai UI');

    try { $scheduleService->save([...$base, 'waktu_mulai'=>'23:15','waktu_selesai'=>'23:45','fan_ilmu'=>'Bentrok'], $admin['id']); $assert(false, 'Bentrok guru ditolak'); }
    catch (ScheduleException $exception) { $assert(str_contains($exception->getMessage(), 'Bentrok guru'), 'Bentrok guru ditolak'); }

    $warning = $scheduleService->save([...$base, 'id_guru'=>$guru2, 'fan_ilmu'=>'Peringatan '.$suffix], $admin['id']);
    $created['schedule'][] = (int) $warning['id'];
    $assert($warning['warnings'] !== [], 'Bentrok kelas dan tempat ditampilkan sebagai peringatan tanpa kehilangan jadwal');

    $meetingId = $scheduleService->open($scheduleId, $monday, 'Pertemuan uji', $admin); $created['meeting'][] = $meetingId;
    $meeting = $scheduleService->meeting($meetingId, $admin);
    $assert($meeting && $meeting['status'] === 'Dibuka' && count($meeting['participants']) === 1, 'Pertemuan dibuka dan menyimpan snapshot peserta kelas');
    try { $scheduleService->open($scheduleId, $monday, '', $admin); $assert(false, 'Pertemuan kedua untuk jadwal dan tanggal sama ditolak'); }
    catch (ScheduleException) { $assert(true, 'Pertemuan kedua untuk jadwal dan tanggal sama ditolak'); }

    $masterService->assignActiveClass(['santri_id'=>$santri,'kelas_id'=>$class2,'tanggal_mulai'=>date('Y-m-d')], $admin['id']);
    $snapshot = $scheduleService->meeting($meetingId, $admin)['participants'];
    $assert(count($snapshot) === 1 && (int)$snapshot[0]['kelas_id_snapshot'] === $class1, 'Perubahan kelas setelah pembukaan tidak mengubah snapshot pertemuan');
    $scheduleService->complete($meetingId, $admin);
    $assert($scheduleService->meeting($meetingId, $admin)['status'] === 'Selesai', 'Status pertemuan berubah ke Selesai dan tercatat');

    $scheduleService->setState($scheduleId, 'deactivate', $admin['id']);
    $activeIds = array_map(static fn(array $row): int => (int)$row['id'], $scheduleService->activeScheduleOptions($admin));
    $assert(!in_array($scheduleId, $activeIds, true), 'Jadwal nonaktif tidak muncul sebagai tugas aktif');
    $duplicates = $db->query('SELECT jadwal_id,tanggal_pertemuan,COUNT(*) total FROM pertemuan_pengajian GROUP BY jadwal_id,tanggal_pertemuan HAVING COUNT(*)>1')?->fetch_all(MYSQLI_ASSOC) ?? [];
    $assert($duplicates === [], 'Constraint dan alur aplikasi menjaga duplikasi jadwal–tanggal tetap nol');
} catch (MasterDataException|ScheduleException $exception) {
    fwrite(STDERR, 'Fixture/pengujian gagal: ' . $exception->getMessage() . PHP_EOL);
    $failures[] = $exception->getMessage();
} finally {
    foreach (array_reverse($created['meeting']) as $id) { $db->query('DELETE FROM pertemuan_peserta WHERE pertemuan_id='.(int)$id); $db->query('DELETE FROM pertemuan_pengajian WHERE id='.(int)$id); }
    foreach (array_reverse($created['schedule']) as $id) { $db->query('DELETE FROM jadwal_ngaji WHERE id='.(int)$id); }
    foreach (array_reverse($created['santri']) as $id) { $db->query('DELETE FROM santri_wali WHERE santri_id='.(int)$id); $db->query('DELETE FROM plotting_kelas WHERE id_santri='.(int)$id); $db->query('DELETE FROM santri WHERE id='.(int)$id); }
    foreach (array_reverse($created['kelas']) as $id) { $db->query('DELETE FROM kelas WHERE id='.(int)$id); }
    foreach (array_reverse($created['guru']) as $id) { $db->query('DELETE FROM guru WHERE id='.(int)$id); }
}
exit($failures === [] ? 0 : 1);
