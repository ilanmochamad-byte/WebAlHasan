<?php
declare(strict_types=1);

// Probe auditor independen. Jalankan pada fixture baru TANPA 034:
// JKG_TANPA_034=1 JKG_DB=test_jkg_audit_test bash tests/jadwal_kelas_gabungan_f1_db.sh
// JKG_DB=test_jkg_audit_test php tests/jadwal_kelas_gabungan_f1_audit_probe.php
use App\Api\ApiException;
use App\Database\Migrator;
use App\Database\Schema;
use App\Schedule\KelasJadwal;
use App\Schedule\ScheduleException;

$root = dirname(__DIR__);
$name = getenv('JKG_DB') ?: 'test_jkg_audit_test';
if (!preg_match('/^test_.*_test$/', $name)) { exit(2); }
putenv('DB_NAME=' . $name);
require $root . '/app/bootstrap.php';
$db = app_db();
$ok = $bad = 0;
function check(bool $pass, string $label): void {
    global $ok, $bad;
    $pass ? $ok++ : $bad++;
    echo ($pass ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
}
function rows(string $sql): array {
    global $db;
    $r = $db->query($sql);
    if (!$r) { throw new RuntimeException($db->error); }
    return $r->fetch_all(MYSQLI_ASSOC);
}
function num(string $sql): int { return (int) (array_values(rows($sql)[0] ?? [0])[0]); }
function denied(callable $action): bool {
    try { $action(); return false; }
    catch (ApiException $e) { return $e->status() === 403; }
    catch (ScheduleException $e) { return str_contains($e->getMessage(), 'hanya dapat mengelola pertemuan dari jadwal miliknya'); }
}
// Hash SELURUH kolom dan baris, termasuk NULL serta ID. Tak menyimpan isi privat.
function hashes(array $tables): array {
    $out = [];
    foreach ($tables as $table) {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $data = array_map(static fn ($r) => json_encode($r, JSON_THROW_ON_ERROR), rows('SELECT * FROM ' . $quoted));
        sort($data, SORT_STRING);
        $out[$table] = ['rows' => count($data), 'sha256' => hash('sha256', implode("\n", $data))];
    }
    return $out;
}
// Dua urutan kunci yang dipaksakan, melengkapi balapan start-bersama suite utama.
if (($argv[1] ?? '') === 'locks') {
    $f = require __DIR__ . '/jadwal_kelas_gabungan_f1_seed.php';
    $k = $f['kelas'];
    $admin = ['id' => $f['admin_id'], 'roles' => ['admin'], 'guru_id' => null];
    $guru = ['id' => $f['guru_user_id'], 'roles' => ['guru'], 'guru_id' => $f['guru_h']];
    $date = (new DateTimeImmutable('next monday'))->format('Y-m-d');
    $base = ['id_tahun' => $f['tahun'], 'id_guru' => $f['guru_h'], 'id_kelas' => $k['PA'], 'kelas_gabungan' => [$k['PI']],
        'hari' => 'Senin', 'waktu_sholat' => "Ba'da Isya", 'waktu_mulai' => '01:00', 'waktu_selesai' => '01:30',
        'fan_ilmu' => 'Kunci ' . $f['sfx'], 'nama_kitab' => 'Uji', 'tempat' => 'Uji kunci'];
    $j = schedule_service()->save($base, $f['admin_id'])['id'];
    $repo = new App\Schedule\ScheduleRepository($db);
    $start = static function (string $mode, array $arg) use ($root, $name): array {
        $arg['mulai'] = microtime(true);
        $p = proc_open(['php', $root . '/tests/jadwal_kelas_gabungan_f1_worker.php', $mode, json_encode($arg)],
            [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, $root, array_merge(getenv(), ['DB_NAME' => $name]));
        return [$p, $pipes];
    };
    $waiting = static function (): bool {
        for ($i = 0; $i < 150; $i++) {
            // PROCESSLIST dapat melihat sesi milik akun DB yang sama tanpa hak PROCESS.
            if (num("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() AND DB=DATABASE() AND INFO LIKE '%FROM jadwal_ngaji j%' AND INFO LIKE '%FOR UPDATE%'") > 0) { return true; }
            usleep(20000);
        }
        return false;
    };
    $finish = static function (array $process): array {
        [$p, $pipes] = $process;
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        $exit = proc_close($p);
        return $exit === 0 && $err === '' ? (json_decode($out, true) ?? []) : [];
    };
    $classes = static fn (int $mid): array => array_map('intval', array_column(rows("SELECT kelas_id FROM pertemuan_kelas WHERE pertemuan_id=$mid ORDER BY kelas_id"), 'kelas_id'));
    // Simulasikan bagian simpan kelas di bawah kunci yang sama dengan layanan.
    $db->begin_transaction(); $repo->scheduleFind($j, true);
    $repo->syncScheduleClasses($j, [$k['PA'],$k['C']], $f['admin_id']);
    $p = $start('api-open', ['jadwal' => $j, 'tanggal' => $date, 'user' => $guru, 'kunci' => 'aud-lock-' . $f['sfx']]);
    check($waiting(), 'Simpan-dulu: SELECT jadwal FOR UPDATE milik pembukaan API teramati saat kunci masih ditahan');
    $db->commit();
    $res = $finish($p); $mid = (int) ($res['meeting_id'] ?? 0);
    check(($res['ok'] ?? false) && $classes($mid) === [$k['PA'],$k['C']], 'Simpan-dulu: pembukaan API memakai kelas baru PA+C setelah commit');
    check(num("SELECT COUNT(*) FROM pertemuan_peserta WHERE pertemuan_id=$mid") === 5
        && num("SELECT COUNT(*) FROM pertemuan_peserta WHERE pertemuan_id=$mid AND kelas_id_snapshot={$k['PI']}") === 0, 'Simpan-dulu: peserta PA+C utuh, PI tidak tertinggal');
    // Simulasikan bagian pembukaan di bawah kunci jadwal; layanan simpan menunggu.
    $next = (new DateTimeImmutable($date))->modify('+7 days')->format('Y-m-d');
    $db->begin_transaction(); $repo->scheduleFind($j, true);
    $m2 = $repo->meetingCreate($j, $next, '', $f['admin_id']);
    $repo->meetingOpen($m2, $f['admin_id']);
    $repo->snapshotParticipants($m2, $k['PA'], $f['tahun'], $j);
    $p = $start('save', ['jadwal' => $j, 'aktor' => $f['admin_id'], 'input' => $base]);
    check($waiting(), 'Buka-dulu: SELECT jadwal FOR UPDATE milik layanan simpan teramati saat kunci masih ditahan');
    $db->commit(); $res = $finish($p);
    check(($res['ok'] ?? false) && $classes($m2) === [$k['PA'],$k['C']], 'Buka-dulu: kelas historis tetap PA+C walau layanan simpan kemudian mengubah ke PA+PI');
    check(array_column(KelasJadwal::daftarJadwal($db, [$j])[$j], 'id') === [$k['PA'],$k['PI']], 'Buka-dulu: kelas jadwal berikutnya PA+PI');
    check(num("SELECT COUNT(*) FROM pertemuan_peserta WHERE pertemuan_id=$m2") === 5
        && num("SELECT COUNT(*) FROM pertemuan_peserta WHERE pertemuan_id=$m2 AND kelas_id_snapshot={$k['C']}") === 2, 'Buka-dulu: peserta beku PA+C tetap utuh');
    echo "Hasil: $ok lulus, $bad gagal\n";
    exit($bad ? 1 : 0);
}
// Kontrol pemulihan: kode lama + DB 034 tetap membuka hanya kelas utama.
// Mode terpisah agar bisa dijalankan pada DB probe sesudah skenario utama.
if (($argv[1] ?? '') === 'legacy') {
    $baseline = getenv('JKG_BASELINE');
    if (!$baseline || !is_file($baseline . '/app/bootstrap.php')) { exit(2); }
    $f = require __DIR__ . '/jadwal_kelas_gabungan_f1_seed.php';
    $k = $f['kelas'];
    $admin = ['id' => $f['admin_id'], 'roles' => ['admin'], 'guru_id' => null];
    $date = (new DateTimeImmutable('next sunday'))->format('Y-m-d');
    $j = schedule_service()->save(['id_tahun' => $f['tahun'], 'id_guru' => $f['guru_g'], 'id_kelas' => $k['PA'],
        'kelas_gabungan' => [$k['PI']], 'hari' => 'Minggu', 'waktu_sholat' => "Ba'da Isya", 'waktu_mulai' => '01:00',
        'waktu_selesai' => '01:30', 'fan_ilmu' => 'Pemulihan ' . $f['sfx'], 'nama_kitab' => 'Uji', 'tempat' => 'Uji pemulihan'], $f['admin_id'])['id'];
    $code = 'require getenv("JKG_BASELINE")."/app/bootstrap.php"; $a=json_decode($argv[1],true); $m=schedule_service()->open($a["j"],$a["date"],"",$a["admin"]); echo json_encode(["meeting"=>$m,"count"=>count(schedule_service()->meeting($m,$a["admin"])["participants"])]);';
    $proc = proc_open(['php', '-r', $code, json_encode(compact('j','date','admin'))], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, $baseline, array_merge(getenv(), ['DB_NAME' => $name]));
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    $exit = proc_close($proc);
    $result = json_decode($out, true);
    check($exit === 0 && $err === '' && ($result['count'] ?? 0) === 3, 'Kontrol baseline: kode lama pada 034 hanya membekukan 3 santri PA; 2 PI terlewat');
    $mid = (int) ($result['meeting'] ?? 0);
    check(num("SELECT COUNT(*) FROM jadwal_kelas WHERE jadwal_id=$j") === 2 && num("SELECT COUNT(*) FROM pertemuan_kelas WHERE pertemuan_id=$mid") === 0,
        'Kontrol baseline: relasi dua kelas tetap ada tetapi kode lama tidak membekukan kelas pertemuan');
    $newdate = (new DateTimeImmutable($date))->modify('+7 days')->format('Y-m-d');
    $newmid = schedule_service()->open($j, $newdate, '', $admin);
    check(num("SELECT COUNT(*) FROM pertemuan_peserta WHERE pertemuan_id=$newmid") === 5
        && num("SELECT COUNT(*) FROM pertemuan_kelas WHERE pertemuan_id=$newmid") === 2, 'Kontrol kode kini: jadwal yang sama membekukan 5 peserta dan dua kelas');
    echo "Hasil: $ok lulus, $bad gagal\n";
    exit($bad ? 1 : 0);
}
check(!KelasJadwal::terpasang($db), 'Prasyarat: salinan sebelum 034');
if (KelasJadwal::terpasang($db)) { exit(2); }
echo 'Lingkungan: PHP ' . PHP_VERSION . '; DB ' . rows('SELECT VERSION() v')[0]['v'] . '; ' . $name . PHP_EOL;
$f = require __DIR__ . '/jadwal_kelas_gabungan_f1_seed.php';
$k = $f['kelas'];
$admin = ['id' => $f['admin_id'], 'roles' => ['admin'], 'guru_id' => null, 'name' => 'Auditor'];
$guru = ['id' => $f['guru_user_id'], 'roles' => ['guru'], 'guru_id' => $f['guru_g']];
$other = $guru;
$other['guru_id'] = $f['guru_h'];
$s = schedule_service();
$api = teacher_api_service();
$report = report_service();
$date = (new DateTimeImmutable('next tuesday'))->format('Y-m-d');
$next = (new DateTimeImmutable($date))->modify('+7 days')->format('Y-m-d');
$base = ['id_tahun' => $f['tahun'], 'id_guru' => $f['guru_g'], 'id_kelas' => $k['PA'],
    'hari' => 'Selasa', 'waktu_sholat' => "Ba'da Isya", 'waktu_mulai' => '02:00', 'waktu_selesai' => '02:30',
    'fan_ilmu' => 'Audit ' . $f['sfx'], 'nama_kitab' => 'Audit', 'tempat' => 'Audit ' . $f['sfx']];
$oldA = $s->save($base, $f['admin_id'])['id'];
$oldM = $s->open($oldA, $date, '', $admin);
$s->save(array_replace($base, ['id_kelas' => $k['PI']]), $f['admin_id'], $oldA);
$oldB = $s->save(array_replace($base, ['id_kelas' => $k['PI'], 'waktu_mulai' => '02:30', 'waktu_selesai' => '03:00']), $f['admin_id'])['id'];
$oldIds = array_column(rows('SELECT id FROM jadwal_ngaji ORDER BY id'), 'id');
$tables = array_column(rows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME <> 'schema_migrations' ORDER BY TABLE_NAME"), 'TABLE_NAME');
$before = hashes($tables);
$m = new Migrator($db, $root . '/database/migrations', $root . '/database/rollbacks');
$migration = '034_jadwal_kelas_gabungan_fase1.sql';
check($m->up() === [$migration], 'Naik tepat 034');
Schema::forget();
foreach ($before as $t => $h) { check(hashes([$t])[$t] === $h, 'Preservasi seluruh kolom/ID setelah naik: ' . $t . ' (' . $h['rows'] . ' baris)'); }
check($oldIds === array_column(rows('SELECT id FROM jadwal_ngaji ORDER BY id'), 'id'), 'Seluruh ID jadwal tetap; dua jadwal lama tidak tergabung');
check(num("SELECT COUNT(*) FROM jadwal_kelas WHERE jadwal_id IN ($oldA,$oldB)") === 2
    && num("SELECT COUNT(*) FROM jadwal_kelas WHERE jadwal_id IN ($oldA,$oldB) AND kelas_id={$k['PI']} AND is_utama=1") === 2, 'Dua jadwal dengan kelas sama tetap dua relasi tunggal');
check(num("SELECT kelas_id FROM pertemuan_kelas WHERE pertemuan_id=$oldM") === $k['PA'], 'Kelas pertemuan lama berasal dari snapshot PA meski jadwal kini PI');
check($m->rollbackLast() === $migration, 'Rollback saat masih satu kelas berhasil');
Schema::forget();
check(hashes($tables) === $before, 'Rollback menjaga seluruh isi/ID semua tabel lama');
check(!KelasJadwal::terpasang($db) && count($api->meeting($oldM, $guru)['students']) === 3, 'Web/API tetap membaca setelah rollback');
check($m->up() === [$migration], 'Naik kembali 034');
Schema::forget();
check(hashes($tables) === $before, 'Naik ulang menjaga seluruh isi/ID semua tabel lama');
if ($out = getenv('JKG_HASH_OUT')) { file_put_contents($out, json_encode($before, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"); }
$base = array_replace($base, ['waktu_mulai' => '03:00', 'waktu_selesai' => '03:30', 'kelas_gabungan' => [$k['PI'], $k['PA'], $k['PI']]]);
$j = $s->save($base, $f['admin_id'])['id'];
check(num("SELECT COUNT(*) FROM pertemuan_pengajian WHERE jadwal_id=$j") === 0, 'Tidak membuka pertemuan otomatis saat menyimpan');
$draft = $s->createDraft($j, $next, '', $admin);
$first = $api->openMeeting($j, ['date' => $date, 'idempotency_key' => 'aud-open-' . $f['sfx']], $guru);
$mid = $first['data']['id'];
$expected = [];
foreach (['PA','PI'] as $part) { foreach ($f['santri'][$part] as $sid) { $expected[$sid] = $k[$part]; } }
$actual = [];
foreach ($first['data']['students'] as $st) { $actual[$st['student_id']] = $st['class']['id']; }
ksort($expected); ksort($actual);
check($expected === $actual && count($first['data']['students']) === 5, 'PA 3 + PI 2 tepat sekali dan kelas asal benar (pilihan kelas berulang dinormalisasi)');
check($api->openMeeting($j, ['date' => $date, 'idempotency_key' => 'aud-open-' . $f['sfx']], $guru)['replayed'] === true
    && num("SELECT COUNT(*) FROM pertemuan_pengajian WHERE jadwal_id=$j AND tanggal_pertemuan='$date'") === 1, 'Replay pembukaan API tetap satu pertemuan');
$statuses = ['Hadir','Terlambat','Izin','Sakit','Alpa'];
$students = [];
foreach ($first['data']['students'] as $i => $st) { $students[] = ['student_id' => $st['student_id'], 'status' => $statuses[$i]]; }
$input = ['idempotency_key' => 'aud-att-' . $f['sfx'], 'teacher' => ['status' => 'Hadir'], 'students' => $students];
foreach ([
    'jadwal API' => fn () => $api->schedule($j, $date, $other),
    'pertemuan API' => fn () => $api->meeting($mid, $other),
    'buka API' => fn () => $api->openMeeting($j, ['date' => $next, 'idempotency_key' => 'aud-foreign-' . $f['sfx']], $other),
    'absensi API' => fn () => $api->saveAttendance($mid, $input, $other),
    'pertemuan web' => fn () => $s->meeting($mid, $other),
    'buka web' => fn () => $s->open($j, $next, '', $other),
    'rincian laporan' => fn () => $report->meeting($mid, $other),
] as $label => $action) { check(denied($action), 'Guru lain ditolak: ' . $label); }
$api->saveAttendance($mid, $input, $guru);
$api->saveAttendance($mid, $input, $guru);
check(num("SELECT COUNT(*) FROM absensi_guru WHERE pertemuan_id=$mid") === 1
    && num("SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id=$mid") === 5, 'Replay absensi tetap satu guru dan satu absensi per santri');
$malformed = $input; $malformed['idempotency_key'] .= '-dup'; $malformed['students'][] = $students[0];
try { $api->saveAttendance($mid, $malformed, $guru); check(false, 'Peserta absensi berulang ditolak'); }
catch (ApiException $e) { check($e->errorCode() === 'VALIDATION_FAILED', 'Peserta absensi berulang ditolak'); }
$frozen = hashes(['pertemuan_peserta','pertemuan_kelas','absensi_santri','absensi_guru']);
master_data_service()->assignActiveClass(['santri_id' => $f['santri']['PA'][0], 'kelas_id' => $k['PI'], 'tanggal_mulai' => date('Y-m-d')], $f['admin_id']);
$new = array_replace($base, ['id_kelas' => $k['C'], 'kelas_gabungan' => []]);
$s->save($new, $f['admin_id'], $j);
check(hashes(['pertemuan_peserta','pertemuan_kelas','absensi_santri','absensi_guru']) === $frozen, 'Pindah santri dan ganti seluruh kelas jadwal tidak mengubah snapshot/absensi historis');
$schedule = $api->schedule($j, $next, $guru);
$meeting = $api->meeting($mid, $guru);
check($schedule['class']['id'] === $k['C'] && array_column($schedule['classes'], 'id') === [$k['C']], 'API jadwal: class lama tetap kelas utama kini, classes mengikuti pilihan baru');
check($meeting['task']['class']['id'] === $k['C'] && array_column($meeting['task']['classes'], 'id') === [$k['PA'],$k['PI']], 'API pertemuan: field class lama tetap kompatibel; classes menyimpan PA+PI historis');
check($s->open($j, $next, '', $admin) === $draft, 'Draf dibuka pada ID yang sama setelah perubahan kelas');
check(num("SELECT COUNT(*) FROM pertemuan_peserta WHERE pertemuan_id=$draft") === 2
    && num("SELECT COUNT(*) FROM pertemuan_peserta WHERE pertemuan_id=$draft AND kelas_id_snapshot={$k['C']}") === 2, 'Draf belum dibuka memakai kelas baru C, bukan kelas saat pembuatan draf');
$filters = ['date_from' => $date, 'date_to' => $date, 'schedule_id' => $j, 'per_page' => 100];
foreach ([null, $k['PA'], $k['PI'], $k['C']] as $class) {
    foreach (['guru','santri','gabungan'] as $scope) {
        $in = $filters + ['subject_scope' => $scope];
        if ($class !== null) { $in['class_id'] = $class; }
        $r = $report->report($in, $admin);
        $csv = $report->exportCsvRows($in, $admin);
        $expectedStudents = $scope === 'guru' ? 0 : ($class === null ? 5 : ($class === $k['PA'] ? 3 : ($class === $k['PI'] ? 2 : 0)));
        $expectedTeachers = $scope === 'santri' || $class === $k['C'] ? 0 : 1;
        $keys = array_map(static fn ($row) => $row['subject_type'] . ':' . $row['subject_id'], $csv['items']);
        check($r['summary']['student_attendance_count'] === $expectedStudents && $r['summary']['teacher_attendance_count'] === $expectedTeachers
            && count($r['items']) === $expectedStudents + $expectedTeachers && count($csv['items']) === count($r['items'])
            && count(array_unique($keys)) === count($keys) && count($r['schedules']) === ($expectedStudents + $expectedTeachers > 0 ? 1 : 0),
            'Laporan + ekspor tanpa ganda: kelas ' . ($class ?? 'semua') . ', scope ' . $scope);
    }
}
$foreign = $report->report($filters, $other);
check($foreign['summary']['detail_count'] === 0 && $report->exportCsvRows($filters, $other)['items'] === [], 'Guru lain tidak memperoleh baris laporan/ekspor');
$v = new App\V4\VerifikasiService($db, audit_logger());
foreach ($students as $st) {
    $att = $v->attendance(['tahun_ajaran_id' => $f['tahun']], $st['student_id']);
    // PA juga ikut oldM yang masih Dibuka: tidak boleh ikut hitungan rapor.
    check($att['jumlah_pertemuan'] === 1 && $att['hitungan'][$st['status']] === 1
        && array_sum($att['hitungan']) === 1 && $att['belum_dicatat'] === 0, 'Rapor tepat sekali untuk santri ' . $st['student_id'] . ' (' . $st['status'] . ')');
}
$state = hashes(['jadwal_kelas','pertemuan_kelas']);
try { $m->rollbackLast(); check(false, 'Rollback sejarah gabungan ditolak meski jadwal kini satu kelas'); }
catch (RuntimeException) { Schema::forget(); check(KelasJadwal::terpasang($db) && hashes(['jadwal_kelas','pertemuan_kelas']) === $state, 'Rollback sejarah gabungan ditolak meski jadwal kini satu kelas; data utuh'); }
echo "Hasil: $ok lulus, $bad gagal\n";
exit($bad ? 1 : 0);
