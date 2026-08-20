<?php

declare(strict_types=1);

use App\Api\ApiException;
use App\Api\TeacherRepository;
use App\Database\BackupWriter;
use App\Report\CsvExport;
use App\Report\PrintRenderer;
use App\Report\ReportRepository;
use App\Report\ReportService;

$root = dirname(__DIR__);
if (getenv('PHASE5_RUN_INTEGRATION') !== '1') {
    fwrite(STDOUT, "[lewati] Set PHASE5_RUN_INTEGRATION=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}
require_once $root . '/app/bootstrap.php';
if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Pengujian integrasi ditolak: DB_NAME wajib berakhiran _test.\n");
    exit(2);
}

$db = app_db();
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) { $failures[] = $message; }
};
$suffix = strtoupper(bin2hex(random_bytes(4)));
$keepFixture = getenv('PHASE5_KEEP_FIXTURE') === '1';
$fixtureManifest = getenv('PHASE5_FIXTURE_MANIFEST') ?: sys_get_temp_dir() . '/alhasan-phase5-manual-fixture.json';
$created = ['users'=>[], 'gurus'=>[], 'classes'=>[], 'students'=>[], 'schedules'=>[], 'meetings'=>[]];
$restoreDatabase = null;
$backupDirectory = sys_get_temp_dir() . '/alhasan-phase5-' . strtolower($suffix);
$auditStart = (int) ($db->query('SELECT COALESCE(MAX(id),0) max_id FROM audit_logs')?->fetch_assoc()['max_id'] ?? 0);
$master = master_data_service();
$teacherRepository = new TeacherRepository($db);
$reportService = new ReportService(new ReportRepository($db), (string) app_config('timezone'));

try {
    $activeYear = $db->query("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1")?->fetch_assoc();
    $role = $db->query("SELECT id FROM roles WHERE slug='guru' LIMIT 1")?->fetch_assoc();
    if (!$activeYear || !$role) {
        throw new RuntimeException('Fixture tahun ajaran aktif atau role guru tidak tersedia.');
    }

    $guruA = $master->saveGuru(['nip'=>'RPA'.$suffix,'nama_guru'=>'Guru Laporan A '.$suffix,'no_hp'=>'','status'=>'Guru']);
    $guruB = $master->saveGuru(['nip'=>'RPB'.$suffix,'nama_guru'=>'Guru Laporan B '.$suffix,'no_hp'=>'','status'=>'Guru']);
    $created['gurus'] = [$guruA, $guruB];
    $classA = $master->saveClass(['nama_kelas'=>'Laporan A '.$suffix,'jenjang'=>'Uji']);
    $classB = $master->saveClass(['nama_kelas'=>'Laporan B '.$suffix,'jenjang'=>'Uji']);
    $created['classes'] = [$classA, $classB];

    for ($i = 1; $i <= 20; $i++) {
        $student = $master->saveSantri([
            'nis'=>'RP'.str_pad((string)$i, 2, '0', STR_PAD_LEFT).$suffix,
            'nama_santri'=>'Santri Laporan '.str_pad((string)$i, 2, '0', STR_PAD_LEFT).' '.$suffix,
            'jenis_kelamin'=>$i % 2 === 0 ? 'P' : 'L', 'tempat_lahir'=>'Ciamis', 'tgl_lahir'=>'2010-01-02',
            'alamat'=>'', 'desa'=>'', 'kecamatan'=>'', 'kab_kota'=>'', 'provinsi'=>'', 'nama_ayah'=>'',
            'no_hp_ayah'=>'', 'nama_ibu'=>'', 'no_hp_ibu'=>'', 'asal_sekolah'=>'', 'sekolah_saat_ini'=>'',
            'foto'=>'default.jpg',
        ]);
        $created['students'][] = $student;
        $master->assignActiveClass(['santri_id'=>$student,'kelas_id'=>$classA,'tanggal_mulai'=>'2025-08-01'], 1);
    }

    $insertUser = $db->prepare('INSERT INTO users (name, username, password, guru_id, is_active, force_password_change, created_at, updated_at) VALUES (?, ?, ?, ?, 1, 0, NOW(), NOW())');
    foreach ([['Guru Laporan A','report_a_'.$suffix,$guruA], ['Guru Laporan B','report_b_'.$suffix,$guruB]] as [$name,$username,$guruId]) {
        $hash = password_hash('Uji-' . $suffix, PASSWORD_DEFAULT);
        $insertUser->bind_param('sssi', $name, $username, $hash, $guruId);
        $insertUser->execute();
        $userId = (int) $db->insert_id;
        $created['users'][] = $userId;
        $roleId = (int) $role['id'];
        $assign = $db->prepare('INSERT INTO user_roles (user_id, role_id, assigned_at) VALUES (?, ?, NOW())');
        $assign->bind_param('ii', $userId, $roleId);
        $assign->execute();
        $assign->close();
    }
    $insertUser->close();
    [$userAId, $userBId] = $created['users'];

    $scheduleA = (int) schedule_service()->save([
        'id_tahun'=>(int)$activeYear['id'], 'waktu_sholat'=>"Ba'da Isya", 'hari'=>'Rabu',
        'waktu_mulai'=>'20:00', 'waktu_selesai'=>'21:00', 'id_kelas'=>$classA,
        'fan_ilmu'=>'Fikih '.$suffix, 'nama_kitab'=>'Kitab A '.$suffix, 'id_guru'=>$guruA,
        'tempat'=>'Ruang A '.$suffix,
    ], $userAId)['id'];
    $scheduleB = (int) schedule_service()->save([
        'id_tahun'=>(int)$activeYear['id'], 'waktu_sholat'=>"Ba'da Isya", 'hari'=>'Rabu',
        'waktu_mulai'=>'20:00', 'waktu_selesai'=>'21:00', 'id_kelas'=>$classB,
        'fan_ilmu'=>'Nahwu '.$suffix, 'nama_kitab'=>'Kitab B '.$suffix, 'id_guru'=>$guruB,
        'tempat'=>'Ruang B '.$suffix,
    ], $userBId)['id'];
    $created['schedules'] = [$scheduleA, $scheduleB];

    $statuses = ['Hadir','Terlambat','Izin','Sakit','Alpa'];
    $startDate = new DateTimeImmutable('2025-09-03');
    foreach (range(0, 49) as $week) {
        $date = $startDate->modify('+' . $week . ' weeks')->format('Y-m-d');
        $meetingId = $teacherRepository->createOpenedMeeting($scheduleA, $date, 'Fixture performa Fase 5', $userAId);
        $created['meetings'][] = $meetingId;
        $teacherRepository->snapshotParticipants($meetingId, $classA, (int) $activeYear['id']);
        $teacherRepository->upsertTeacherAttendance($meetingId, $guruA, 'Hadir', null, $userAId);
        foreach ($created['students'] as $index => $studentId) {
            $teacherRepository->upsertStudentAttendance($meetingId, $studentId, $statuses[($week + $index) % 5], null, $userAId);
        }
        $teacherRepository->completeMeeting($meetingId, $userAId);
    }
    $meetingB = $teacherRepository->createOpenedMeeting($scheduleB, '2026-08-12', 'Fixture otorisasi', $userBId);
    $created['meetings'][] = $meetingB;
    $teacherRepository->snapshotParticipants($meetingB, $classB, (int) $activeYear['id']);
    $teacherRepository->upsertTeacherAttendance($meetingB, $guruB, 'Hadir', null, $userBId);
    $teacherRepository->completeMeeting($meetingB, $userBId);

    $admin = ['id'=>$userAId,'name'=>'Admin Uji '.$suffix,'guru_id'=>$guruA,'roles'=>['admin']];
    $teacherA = ['id'=>$userAId,'name'=>'Guru A','guru_id'=>$guruA,'roles'=>['guru']];
    $filters = [
        'date_from'=>'2025-09-01', 'date_to'=>'2026-08-20', 'academic_year_id'=>(int)$activeYear['id'],
        'teacher_id'=>$guruA, 'class_id'=>$classA, 'schedule_id'=>$scheduleA, 'page'=>1, 'per_page'=>25,
    ];
    $attendanceCountBefore = (int) ($db->query('SELECT (SELECT COUNT(*) FROM absensi_guru) + (SELECT COUNT(*) FROM absensi_santri) total')?->fetch_assoc()['total'] ?? -1);
    $started = hrtime(true);
    $report = $reportService->report($filters, $admin);
    $elapsedMs = (hrtime(true) - $started) / 1_000_000;
    $export = $reportService->exportRows($filters, $admin);
    $html = PrintRenderer::report($export);
    $attendanceCountAfter = (int) ($db->query('SELECT (SELECT COUNT(*) FROM absensi_guru) + (SELECT COUNT(*) FROM absensi_santri) total')?->fetch_assoc()['total'] ?? -1);

    $assert($report['summary']['meeting_count'] === 50, 'Filter admin menampilkan 50 pertemuan guru/kelas/jadwal/tahun ajaran yang dipilih');
    $assert($report['summary']['detail_count'] === 1050 && count($report['items']) === 25, 'UI dipaginasi tetapi total mencakup 1.050 absensi sintetis');
    $assert(array_sum($report['summary']['statuses']) === $report['summary']['detail_count'], 'Total ringkasan status sama dengan jumlah seluruh baris detail');
    $assert(count($export['items']) === $report['summary']['detail_count'], 'Ekspor mengambil seluruh hasil filter tanpa terpotong pagination');
    $csv = CsvExport::encode($export['items']);
    $assert(substr_count($csv, "\n") === count($export['items']) + 1, 'CSV memiliki satu header dan seluruh baris hasil filter');
    $assert(str_contains($html, 'Pesantren Al Hasan') && str_contains($html, 'counter(page)') && !str_contains($html, 'sidebarMenu'), 'HTML cetak memuat identitas/nomor halaman tanpa navigasi admin');
    $assert($attendanceCountBefore === $attendanceCountAfter, 'Membaca, mengekspor, dan mencetak laporan tidak mengubah data absensi');
    $assert($elapsedMs < 2000, sprintf('Halaman pertama selesai %.2f ms (< 2.000 ms)', $elapsedMs));

    $hadir = $reportService->report([...$filters, 'status'=>'Hadir'], $admin);
    $assert($hadir['summary']['detail_count'] > 0 && $hadir['summary']['detail_count'] === $hadir['summary']['statuses']['Hadir'], 'Filter status hanya menghitung status yang dipilih');
    foreach (['academic_year_id','teacher_id','class_id','schedule_id'] as $key) {
        $singleFilter = $reportService->report(['date_from'=>'2025-09-01','date_to'=>'2026-08-20',$key=>$filters[$key]], $admin);
        $assert($singleFilter['summary']['detail_count'] >= 1050, 'Filter admin valid dan menghasilkan data untuk ' . $key);
    }

    $teacherReport = $reportService->report(['date_from'=>'2025-09-01','date_to'=>'2026-08-20'], $teacherA);
    $assert($teacherReport['summary']['detail_count'] === 1050, 'Laporan guru hanya memuat jadwal milik guru dari token');
    try {
        $reportService->report(['date_from'=>'2025-09-01','date_to'=>'2026-08-20','teacher_id'=>$guruB], $teacherA);
        $assert(false, 'Manipulasi teacher_id guru lain ditolak');
    } catch (ApiException $exception) {
        $assert($exception->status() === 403, 'Manipulasi teacher_id guru lain menerima 403');
    }
    $foreignSchedule = $reportService->report(['date_from'=>'2025-09-01','date_to'=>'2026-08-20','schedule_id'=>$scheduleB], $teacherA);
    $assert($foreignSchedule['summary']['detail_count'] === 0, 'Manipulasi schedule_id guru lain tidak mengembalikan data');
    try {
        $reportService->meeting($meetingB, $teacherA);
        $assert(false, 'Detail pertemuan guru lain ditolak');
    } catch (ApiException $exception) {
        $assert($exception->status() === 403, 'Manipulasi ID detail pertemuan guru lain menerima 403');
    }
    $ownDetail = $reportService->meeting($created['meetings'][0], $teacherA);
    $assert(count($ownDetail['students']) === 20 && $ownDetail['teacher_attendance']['status'] === 'Hadir', 'Detail pertemuan memuat guru dan seluruh peserta snapshot');

    $explain = $reportService->explain($filters, $admin);
    $explainStatus = $reportService->explain([...$filters, 'status'=>'Alpa'], $admin);
    $assert($explain !== [] && count(array_filter($explain, static fn(array $row): bool => isset($row['key']) && $row['key'] !== null)) > 0, 'EXPLAIN nyata menghasilkan rencana dengan indeks terpilih');
    echo '[ukur] phase5_first_page_ms=' . number_format($elapsedMs, 2, '.', '') . PHP_EOL;
    echo '[ukur] phase5_explain=' . json_encode($explain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo '[ukur] phase5_explain_status=' . json_encode($explainStatus, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
        throw new RuntimeException('Direktori backup uji tidak dapat dibuat.');
    }
    $backupPath = $backupDirectory . '/database.sql';
    $counts = (new BackupWriter($db))->write($backupPath);
    $restoreDatabase = 'alhasan_restore_' . strtolower($suffix) . '_test';
    if (!preg_match('/^[a-z0-9_]+_test$/', $restoreDatabase)) {
        throw new RuntimeException('Nama database restore tidak aman.');
    }
    if (!$db->query('CREATE DATABASE `' . $restoreDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')) {
        throw new RuntimeException('Database restore uji gagal dibuat: ' . $db->error);
    }
    $config = app_config('database');
    $restore = new mysqli((string)$config['host'], (string)$config['username'], (string)$config['password'], $restoreDatabase, (int)$config['port']);
    $restore->set_charset('utf8mb4');
    $restoreSql = (string) file_get_contents($backupPath);
    if (!$restore->multi_query($restoreSql)) {
        throw new RuntimeException('Restore SQL gagal: ' . $restore->error);
    }
    do {
        if ($result = $restore->store_result()) { $result->free(); }
    } while ($restore->more_results() && $restore->next_result());
    if ($restore->errno) {
        throw new RuntimeException('Restore SQL tidak tuntas: ' . $restore->error);
    }
    $coreTables = ['users','guru','santri','jadwal_ngaji','pertemuan_pengajian','pertemuan_peserta','absensi_guru','absensi_santri'];
    $restoreMatches = true;
    foreach ($coreTables as $table) {
        $actual = (int) ($restore->query('SELECT COUNT(*) total FROM `' . $table . '`')?->fetch_assoc()['total'] ?? -1);
        $restoreMatches = $restoreMatches && $actual === (int) ($counts[$table] ?? -2);
    }
    $restore->close();
    $assert($restoreMatches, 'Backup dipulihkan pada database uji dengan jumlah baris tabel inti yang sama');
    echo '[ukur] phase5_backup_core_counts=' . json_encode(array_intersect_key($counts, array_flip($coreTables))) . PHP_EOL;
    if ($keepFixture) {
        file_put_contents($fixtureManifest, json_encode([
            'database'=>(string) app_config('database.database'), 'suffix'=>$suffix,
            'username'=>'report_a_'.$suffix, 'password'=>'Uji-'.$suffix,
            'created'=>$created, 'audit_start'=>$auditStart,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        echo '[fixture] manifest=' . $fixtureManifest . PHP_EOL;
        echo '[fixture] username=report_a_' . $suffix . PHP_EOL;
        echo '[fixture] password=Uji-' . $suffix . PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, '[gagal] Fixture/pengujian Fase 5: ' . $exception->getMessage() . PHP_EOL);
    $failures[] = $exception->getMessage();
} finally {
    if ($restoreDatabase !== null && preg_match('/^[a-z0-9_]+_test$/', $restoreDatabase)) {
        $db->query('DROP DATABASE IF EXISTS `' . $restoreDatabase . '`');
    }
    if (!$keepFixture) {
        foreach (array_reverse($created['meetings']) as $id) {
            $db->query('DELETE FROM absensi_santri WHERE pertemuan_id='.(int)$id);
            $db->query('DELETE FROM absensi_guru WHERE pertemuan_id='.(int)$id);
            $db->query('DELETE FROM pertemuan_peserta WHERE pertemuan_id='.(int)$id);
            $db->query('DELETE FROM pertemuan_pengajian WHERE id='.(int)$id);
        }
        foreach (array_reverse($created['schedules']) as $id) { $db->query('DELETE FROM jadwal_ngaji WHERE id='.(int)$id); }
        foreach (array_reverse($created['users']) as $id) { $db->query('DELETE FROM users WHERE id='.(int)$id); }
        foreach (array_reverse($created['students']) as $id) {
            $db->query('DELETE FROM santri_wali WHERE santri_id='.(int)$id);
            $db->query('DELETE FROM plotting_kelas WHERE id_santri='.(int)$id);
            $db->query('DELETE FROM santri WHERE id='.(int)$id);
        }
        foreach (array_reverse($created['classes']) as $id) { $db->query('DELETE FROM kelas WHERE id='.(int)$id); }
        foreach (array_reverse($created['gurus']) as $id) { $db->query('DELETE FROM guru WHERE id='.(int)$id); }
        $db->query('DELETE FROM audit_logs WHERE id > ' . $auditStart);
    }
    foreach (['database.sql'] as $file) {
        $path = $backupDirectory . '/' . $file;
        if (is_file($path)) { unlink($path); }
    }
    if (is_dir($backupDirectory)) { rmdir($backupDirectory); }
}

exit($failures === [] ? 0 : 1);
