<?php

declare(strict_types=1);

use App\Api\ApiAuthRepository;
use App\Api\ApiAuthService;
use App\Api\ApiException;
use App\Api\TeacherRepository;
use App\Api\TeacherService;
use App\Auth\ApiTokenAuthenticator;
use App\Auth\TokenHasher;
use App\MasterData\MasterDataException;
use App\Schedule\ScheduleException;

$root = dirname(__DIR__);
if (getenv('PHASE4_RUN_INTEGRATION') !== '1') {
    fwrite(STDOUT, "[lewati] Set PHASE4_RUN_INTEGRATION=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}
require_once $root . '/app/bootstrap.php';
if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Pengujian integrasi ditolak: DB_NAME wajib berakhiran _test.\n");
    exit(2);
}

$db = app_db();
foreach (['absensi_guru', 'absensi_santri', 'api_idempotency_keys'] as $table) {
    if (!$db->query('SELECT 1 FROM ' . $table . ' LIMIT 1')) {
        fwrite(STDERR, "Migrasi Fase 4 belum diterapkan pada database uji.\n");
        exit(2);
    }
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) { $failures[] = $message; }
};
$master = master_data_service();
$scheduleService = schedule_service();
$repository = new TeacherRepository($db);
$teacherService = new TeacherService($repository, audit_logger(), (string) app_config('timezone'));
$hasher = new TokenHasher('phase4-integration-secret', 'testing');
$authRepository = new ApiAuthRepository($db);
$authService = new ApiAuthService($authRepository, $hasher, audit_logger(), 30, (string) app_config('timezone'));
$activeYear = $db->query("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1")?->fetch_assoc();
$role = $db->query("SELECT id FROM roles WHERE slug='guru' LIMIT 1")?->fetch_assoc();
if (!$activeYear || !$role) {
    fwrite(STDERR, "Fixture semester aktif atau role guru tidak tersedia.\n");
    exit(2);
}

$suffix = strtoupper(bin2hex(random_bytes(4)));
$created = ['users' => [], 'gurus' => [], 'classes' => [], 'students' => [], 'schedules' => [], 'meetings' => []];
$auditStart = (int) ($db->query('SELECT COALESCE(MAX(id),0) max_id FROM audit_logs')?->fetch_assoc()['max_id'] ?? 0);
$meetingDate = (new DateTimeImmutable('next wednesday'))->format('Y-m-d');

try {
    $guruA = $master->saveGuru(['nip' => 'A' . $suffix, 'nama_guru' => 'Guru API A ' . $suffix, 'no_hp' => '', 'status' => 'Guru']);
    $guruB = $master->saveGuru(['nip' => 'B' . $suffix, 'nama_guru' => 'Guru API B ' . $suffix, 'no_hp' => '', 'status' => 'Guru']);
    $guruInactive = $master->saveGuru(['nip' => 'I' . $suffix, 'nama_guru' => 'Guru API Nonaktif ' . $suffix, 'no_hp' => '', 'status' => 'Guru']);
    $created['gurus'] = [$guruA, $guruB, $guruInactive];
    $classA = $master->saveClass(['nama_kelas' => 'API A ' . $suffix, 'jenjang' => 'Uji']);
    $classB = $master->saveClass(['nama_kelas' => 'API B ' . $suffix, 'jenjang' => 'Uji']);
    $created['classes'] = [$classA, $classB];
    for ($i = 1; $i <= 2; $i++) {
        $student = $master->saveSantri(['nis'=>'API'.$i.$suffix,'nama_santri'=>'Santri API '.$i.' '.$suffix,'jenis_kelamin'=>'L','tempat_lahir'=>'Ciamis','tgl_lahir'=>'2010-01-02','alamat'=>'','desa'=>'','kecamatan'=>'','kab_kota'=>'','provinsi'=>'','nama_ayah'=>'','no_hp_ayah'=>'','nama_ibu'=>'','no_hp_ibu'=>'','asal_sekolah'=>'','sekolah_saat_ini'=>'','foto'=>'default.jpg']);
        $created['students'][] = $student;
        $master->assignActiveClass(['santri_id'=>$student,'kelas_id'=>$classA,'tanggal_mulai'=>date('Y-m-d')], 1);
    }

    $password = 'Uji-' . $suffix;
    $insertUser = $db->prepare('INSERT INTO users (name, username, password, guru_id, is_active, force_password_change, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, NOW(), NOW())');
    foreach ([['Guru A','guru_a_'.$suffix,$guruA,1], ['Guru B','guru_b_'.$suffix,$guruB,1], ['Guru Nonaktif','guru_i_'.$suffix,$guruInactive,0]] as [$name,$username,$guruId,$active]) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insertUser->bind_param('sssii', $name, $username, $hash, $guruId, $active);
        $insertUser->execute();
        $userId = (int) $db->insert_id;
        $created['users'][] = $userId;
        $assign = $db->prepare('INSERT INTO user_roles (user_id, role_id, assigned_at) VALUES (?, ?, NOW())');
        $roleId = (int) $role['id'];
        $assign->bind_param('ii', $userId, $roleId);
        $assign->execute();
        $assign->close();
    }
    $insertUser->close();

    [$userAId, $userBId] = $created['users'];
    $loginA = $authService->login(['username'=>'guru_a_'.$suffix,'password'=>$password,'device_name'=>'Integration']);
    $assert(isset($loginA['token']) && !isset($loginA['profile']['password']) && !isset($loginA['profile']['token_hash']), 'Akun guru aktif login dan profil tidak membocorkan password/hash token');
    $storedHash = $db->query('SELECT token_hash FROM api_tokens WHERE user_id=' . $userAId . ' ORDER BY id DESC LIMIT 1')?->fetch_assoc()['token_hash'] ?? '';
    $assert($storedHash === $hasher->hash($loginA['token']) && $storedHash !== $loginA['token'], 'Server hanya menyimpan hash bearer token');
    foreach ([['guru_a_'.$suffix,'salah'], ['guru_i_'.$suffix,$password]] as [$username,$attemptPassword]) {
        try { $authService->login(['username'=>$username,'password'=>$attemptPassword]); $assert(false, 'Credential salah/nonaktif ditolak'); }
        catch (ApiException $exception) { $assert($exception->status() === 401 && $exception->errorCode() === 'INVALID_CREDENTIALS', 'Credential salah/nonaktif ditolak generik dengan 401'); }
    }

    $base = ['id_tahun'=>(int)$activeYear['id'],'waktu_sholat'=>"Ba'da Isya",'hari'=>'Rabu','waktu_mulai'=>'22:01','waktu_selesai'=>'22:29','fan_ilmu'=>'API '.$suffix,'nama_kitab'=>'Kitab '.$suffix,'tempat'=>'Ruang '.$suffix];
    $scheduleA = (int) $scheduleService->save([...$base,'id_kelas'=>$classA,'id_guru'=>$guruA], $userAId)['id'];
    $scheduleB = (int) $scheduleService->save([...$base,'id_kelas'=>$classB,'id_guru'=>$guruB,'tempat'=>'Ruang B '.$suffix], $userBId)['id'];
    $created['schedules'] = [$scheduleA, $scheduleB];
    $userA = ['id'=>$userAId,'guru_id'=>$guruA,'roles'=>['guru']];
    $userB = ['id'=>$userBId,'guru_id'=>$guruB,'roles'=>['guru']];
    $listed = $teacherService->schedules($userA, ['date_from'=>$meetingDate,'date_to'=>$meetingDate]);
    $assert(count($listed['items']) === 1 && $listed['items'][0]['id'] === $scheduleA, 'Beranda/rentang jadwal hanya memuat jadwal guru yang login pada semester aktif');
    try { $teacherService->schedule($scheduleB, $meetingDate, $userA); $assert(false, 'Guru A ditolak dari jadwal Guru B'); }
    catch (ApiException $exception) { $assert($exception->status() === 403, 'Guru A menerima 403 saat mengakses jadwal Guru B'); }

    $openKey = 'open-' . $suffix;
    $opened = $teacherService->openMeeting($scheduleA, ['date'=>$meetingDate,'notes'=>'Uji','idempotency_key'=>$openKey], $userA);
    $meetingA = (int) $opened['data']['id'];
    $created['meetings'][] = $meetingA;
    $reopened = $teacherService->openMeeting($scheduleA, ['date'=>$meetingDate,'notes'=>'Uji','idempotency_key'=>$openKey], $userA);
    $meetingCount = (int) ($db->query('SELECT COUNT(*) total FROM pertemuan_pengajian WHERE jadwal_id='.$scheduleA." AND tanggal_pertemuan='".$db->real_escape_string($meetingDate)."'")?->fetch_assoc()['total'] ?? 0);
    $assert($reopened['replayed'] && $meetingCount === 1, 'Retry pembukaan dengan idempotency key sama tidak membuat pertemuan tambahan');

    $students = array_map(static fn(array $row): array => ['student_id'=>$row['student_id'],'status'=>'Hadir','notes'=>''], $opened['data']['students']);
    $payload = ['idempotency_key'=>'save-'.$suffix,'teacher'=>['status'=>'Hadir','notes'=>''],'students'=>$students];
    $saved = $teacherService->saveAttendance($meetingA, $payload, $userA);
    $readBack = $teacherService->meeting($meetingA, $userA);
    $assert($saved['data']['status'] === 'Selesai' && $readBack['teacher_attendance']['status'] === 'Hadir' && count(array_filter($readBack['students'], static fn(array $row): bool => $row['attendance'] !== null)) === 2, 'Absensi guru dan seluruh santri tersimpan serta dapat dibaca kembali');
    $teacherService->saveAttendance($meetingA, $payload, $userA);
    $teacherCount = (int) ($db->query('SELECT COUNT(*) total FROM absensi_guru WHERE pertemuan_id='.$meetingA)?->fetch_assoc()['total'] ?? 0);
    $studentCount = (int) ($db->query('SELECT COUNT(*) total FROM absensi_santri WHERE pertemuan_id='.$meetingA)?->fetch_assoc()['total'] ?? 0);
    $assert($teacherCount === 1 && $studentCount === 2, 'Retry payload sama tidak membuat absensi tambahan');

    $students[0]['status'] = 'Izin';
    $corrected = $teacherService->saveAttendance($meetingA, ['idempotency_key'=>'edit-'.$suffix,'teacher'=>['status'=>'Hadir'],'students'=>$students,'correction_reason'=>'Koreksi hasil konfirmasi'], $userA);
    $assert($corrected['data']['students'][0]['attendance']['status'] === 'Izin' && (int)($db->query('SELECT COUNT(*) total FROM absensi_santri WHERE pertemuan_id='.$meetingA)?->fetch_assoc()['total'] ?? 0) === 2, 'Koreksi memperbarui baris yang sama dan nilai terbaru dapat dibaca');
    try { $teacherService->saveAttendance($meetingA, ['idempotency_key'=>'forbid-'.$suffix,'teacher'=>['status'=>'Hadir'],'students'=>$students,'correction_reason'=>'uji'], $userB); $assert(false, 'Guru B ditolak menyimpan jadwal Guru A'); }
    catch (ApiException $exception) { $assert($exception->status() === 403, 'Guru lintas kepemilikan menerima 403 saat menyimpan absensi'); }

    $openedB = $teacherService->openMeeting($scheduleB, ['date'=>$meetingDate,'idempotency_key'=>'open-b-'.$suffix], $userB);
    $meetingB = (int) $openedB['data']['id'];
    $created['meetings'][] = $meetingB;
    try {
        $teacherService->saveAttendance($meetingB, ['idempotency_key'=>'fail-'.$suffix,'teacher'=>['status'=>'Hadir'],'students'=>[]], $userB, static function (): void { throw new RuntimeException('simulasi gagal'); });
        $assert(false, 'Simulasi kegagalan transaksi melempar error');
    } catch (RuntimeException $exception) {
        $partial = (int) ($db->query('SELECT (SELECT COUNT(*) FROM absensi_guru WHERE pertemuan_id='.$meetingB.') + (SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id='.$meetingB.') total')?->fetch_assoc()['total'] ?? -1);
        $assert($partial === 0, 'Kegagalan di tengah transaksi tidak meninggalkan absensi sebagian');
    }

    $duplicateTeachers = $db->query('SELECT pertemuan_id,guru_id,COUNT(*) total FROM absensi_guru GROUP BY pertemuan_id,guru_id HAVING COUNT(*)>1')?->fetch_all(MYSQLI_ASSOC) ?? [];
    $duplicateStudents = $db->query('SELECT pertemuan_id,santri_id,COUNT(*) total FROM absensi_santri GROUP BY pertemuan_id,santri_id HAVING COUNT(*)>1')?->fetch_all(MYSQLI_ASSOC) ?? [];
    $assert($duplicateTeachers === [] && $duplicateStudents === [], 'Constraint unik menjaga duplikasi pertemuan–guru dan pertemuan–santri tetap nol');

    $tokenRow = $db->query('SELECT id FROM api_tokens WHERE user_id='.$userAId.' ORDER BY id DESC LIMIT 1')?->fetch_assoc();
    $authService->logout(['id'=>$userAId,'token_id'=>(int)$tokenRow['id']]);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $loginA['token'];
    try { (new ApiTokenAuthenticator($db, $hasher))->authenticate(); $assert(false, 'Token lama ditolak setelah logout'); }
    catch (ApiException $exception) { $assert($exception->status() === 401, 'Token lama menerima 401 setelah logout'); }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Fixture/pengujian gagal: ' . $exception->getMessage() . PHP_EOL);
    $failures[] = $exception->getMessage();
} finally {
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

exit($failures === [] ? 0 : 1);
