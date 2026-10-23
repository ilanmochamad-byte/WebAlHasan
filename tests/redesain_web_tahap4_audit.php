<?php

declare(strict_types=1);

// Probe independen H: server lokal yang sudah berjalan, DB salinan Tahap 4.
// Mutasi fixture pendidikan/guru dipulihkan di finally; tidak mengirim notifikasi.
$root = dirname(__DIR__);
putenv('DB_NAME=test_redesain_t4_test');
require_once $root . '/app/bootstrap.php';
$base = getenv('BASE_URL') ?: 'http://127.0.0.1:8961';
if (!preg_match('#^http://127\.0\.0\.1:\d+$#', $base)
    || app_config('database.database') !== 'test_redesain_t4_test') {
    throw new RuntimeException('Hanya server lokal dan DB uji Tahap 4.');
}
$db = app_db();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$pass = 0; $fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    $ok ? $pass++ : $fail++;
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
};
$jar = tempnam(sys_get_temp_dir(), 't4-audit-');
$req = static function (string $path, ?array $post = null, array $headers = [], bool $json = false) use ($base, $jar): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 20]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json ? json_encode($post) : http_build_query($post));
    }
    if ($json) { $headers[] = 'Content-Type: application/json'; }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $status, 'body' => substr($raw, $size), 'headers' => substr($raw, 0, $size)];
};
$csrf = static function () use ($req): string {
    preg_match('/name="_csrf" value="([^"]+)"/', $req('/portal/index.php')['body'], $m);
    if (!isset($m[1])) { throw new RuntimeException('CSRF tidak terbaca.'); }
    return $m[1];
};
$scalar = static fn (string $sql): mixed => $db->query($sql)->fetch_row()[0];
$user = $db->query("SELECT * FROM users WHERE username='sbx_pendidikan'")->fetch_assoc();
$uid = (int) $user['id']; $pid = (int) $user['pengurus_id'];
$assignment = $db->query("SELECT * FROM pendidikan_assignments WHERE pengurus_id=$pid LIMIT 1")->fetch_assoc();
$aid = (int) $assignment['id']; $year = (int) $assignment['tahun_ajaran_id'];
$master = $db->query("SELECT * FROM pengurus WHERE id=$pid")->fetch_assoc();
$yearRow = $db->query("SELECT * FROM tahun_ajaran WHERE id=$year")->fetch_assoc();
$guruRole = (int) $scalar("SELECT id FROM roles WHERE slug='guru'");
if ($user['guru_id'] !== null || (int) $scalar("SELECT COUNT(*) FROM user_roles WHERE user_id=$uid AND role_id=$guruRole") !== 0) {
    throw new RuntimeException('Bangun ulang fixture Tahap 4: akun Pendidikan harus belum ber-role Guru.');
}
$meeting = (int) $scalar("SELECT id FROM pertemuan_pengajian WHERE status='Dibuka' ORDER BY id DESC LIMIT 1");
$paths = ['/admin/admin_laporan_absensi.php?periode=semester&subject_scope=gabungan',
    '/admin/export_laporan_absensi.php?date_from=2026-07-01&date_to=2026-12-31',
    '/admin/laporan_absensi_cetak.php?date_from=2026-07-01&date_to=2026-12-31',
    '/admin/laporan_absensi_detail.php?id=' . $meeting];
$snapshot = static function () use ($db): array {
    $out = [];
    foreach (['jadwal_ngaji', 'pertemuan_pengajian', 'pertemuan_peserta', 'absensi_santri', 'absensi_guru', 'izin_pengajuan', 'izin_keputusan'] as $table) {
        $out[$table] = hash('sha256', json_encode($db->query("SELECT * FROM $table ORDER BY id")->fetch_all(MYSQLI_ASSOC)));
    }
    return $out;
};
$before = $snapshot();
$restore = static function (string $table, array $row, array $columns) use ($db): void {
    $sets = [];
    foreach ($columns as $col) { $sets[] = "$col=" . ($row[$col] === null ? 'NULL' : "'" . $db->real_escape_string((string) $row[$col]) . "'"); }
    $db->query("UPDATE $table SET " . implode(',', $sets) . ' WHERE id=' . (int) $row['id']);
};
$gid = 0;
try {
    $login = $req('/admin/cek_login.php', ['_csrf' => $csrf(), 'username' => 'sbx_pendidikan', 'password' => 'Sandbox#123']);
    $check(in_array($login['status'], [302,303], true), 'Login akun Pendidikan sintetis');
    foreach ($paths as $path) { $check($req($path)['status'] === 200, 'H positif Pendidikan: ' . $path); }
    $tokenResult = $req('/api/v1/auth/login', ['username' => 'sbx_pendidikan', 'password' => 'Sandbox#123', 'device_name' => 'audit-t4-codex'], [], true);
    $token = json_decode($tokenResult['body'], true)['data']['token'] ?? '';
    $check($tokenResult['status'] === 200 && $token !== '', 'Login REST sungguhan Pendidikan');
    foreach (['/reports', '/reports/filters', '/reports/print', '/reports/meetings/' . $meeting] as $path) {
        $check($req('/api/v1' . $path . '?_laporan_baca_semua_jadwal=1', null, ['Authorization: Bearer ' . $token])['status'] === 403, 'REST tanpa perluasan hak: ' . $path);
    }
    foreach ($paths as $path) { $check($req($path, ['_csrf' => $csrf()])['status'] === 405, 'H hanya-baca POST: ' . $path); }
    $db->query("UPDATE pendidikan_assignments SET jenjang='Jenjang probe lain' WHERE id=$aid");
    foreach ($paths as $path) { $check($req($path)['status'] === 200, 'H seluruh jadwal walau jenjang terbatas: ' . $path); }
    $restore('pendidikan_assignments', $assignment, ['jenjang']);
    foreach (["is_active=0", "tanggal_selesai=CURDATE()-INTERVAL 1 DAY", "tanggal_mulai=CURDATE()+INTERVAL 1 DAY"] as $change) {
        $db->query("UPDATE pendidikan_assignments SET $change WHERE id=$aid");
        $codes = array_map(static fn ($path) => $req($path)['status'], $paths);
        $check($codes === [403,403,403,403], 'Hak dicabut request berikut pada daftar/CSV/cetak/detail: ' . $change);
        $restore('pendidikan_assignments', $assignment, ['is_active','tanggal_mulai','tanggal_selesai']);
    }
    foreach ([['pengurus', $pid, 'is_active=0'], ['pengurus', $pid, 'archived_at=NOW()'], ['tahun_ajaran', $year, "status='Non-Aktif'"], ['tahun_ajaran', $year, 'archived_at=NOW()']] as [$table,$id,$set]) {
        $db->query("UPDATE $table SET $set WHERE id=$id");
        $codes = array_map(static fn ($path) => $req($path)['status'], $paths);
        $check($codes === [403,403,403,403], "H menolak $table $set (" . implode('/', $codes) . ')');
        $restore($table, $table === 'pengurus' ? $master : $yearRow, $table === 'pengurus' ? ['is_active','archived_at'] : ['status','archived_at']);
    }
    // Guru baru tanpa jadwal: peran Guru harus kembali ke cakupan sendiri (0).
    $db->query("INSERT INTO guru (nip,nama_guru,status,is_active) VALUES ('AUDIT-T4-CODEX','Guru sintetis audit T4','Guru',1)");
    $gid = (int) $db->insert_id;
    $db->query("UPDATE users SET guru_id=$gid WHERE id=$uid");
    $db->query("INSERT INTO user_roles(user_id,role_id) VALUES($uid,$guruRole)");
    $req('/portal/peran.php', ['_csrf' => $csrf(), 'peran' => 'guru']);
    $r = $req($paths[0]);
    $check($r['status'] === 200 && preg_match('#<p class="ah-stat__label">Pertemuan</p><p class="ah-stat__value">0</p>#', $r['body']) === 1, 'Pendidikan+Guru sebagai Guru hanya jadwal sendiri (0), bukan seluruh jadwal');
    $check($req($paths[3])['status'] === 403, 'Pendidikan+Guru sebagai Guru menolak detail guru lain');
    $req('/portal/peran.php', ['_csrf' => $csrf(), 'peran' => 'pendidikan']);
    $check($req($paths[3])['status'] === 200, 'Peralihan eksplisit ke Pendidikan membuka detail seluruh jadwal');
    $r = $req('/admin/admin_pengajian.php?tab=pertemuan', ['_csrf' => $csrf(), 'tab' => 'pertemuan', 'action' => 'complete', 'meeting_id' => $meeting]);
    $check($r['status'] === 403, 'Pendidikan+Guru sebagai Pendidikan tidak menyelesaikan pertemuan lewat POST web');
    $db->query("UPDATE pendidikan_assignments SET is_active=0 WHERE id=$aid");
    $check($req($paths[3])['status'] === 403, 'Sesi Pendidikan lama tidak mempertahankan hak sesudah penugasan dicabut (akun juga Guru)');
} finally {
    $restore('pendidikan_assignments', $assignment, ['is_active','tanggal_mulai','tanggal_selesai','jenjang']);
    $restore('pengurus', $master, ['is_active','archived_at']);
    $restore('tahun_ajaran', $yearRow, ['status','archived_at']);
    $restore('users', $user, ['guru_id']);
    $db->query("DELETE FROM user_roles WHERE user_id=$uid AND role_id=$guruRole");
    $db->query("DELETE FROM api_tokens WHERE user_id=$uid AND name='audit-t4-codex'");
    if ($gid > 0) { $db->query("DELETE FROM guru WHERE id=$gid"); }
    @unlink($jar);
}
$check($snapshot() === $before, 'Jadwal/pertemuan/peserta/absensi/izin/keputusan tetap identik setelah baca dan POST ditolak');

// J-1 harus memuat seluruh pola mingguan, melampaui batas halaman layanan (100).
$scheduleIds = [];
try {
    $req('/admin/cek_login.php', ['_csrf' => $csrf(), 'username' => 'sbx_admin', 'password' => 'Sandbox#123']);
    $sourceId = (int) $scalar("SELECT id FROM jadwal_ngaji WHERE nama_kitab='Kitab SBX' AND archived_at IS NULL ORDER BY id LIMIT 1");
    for ($i = 1; $i <= 101; $i++) {
        $db->query("INSERT INTO jadwal_ngaji(id_tahun,id_kelas,id_guru,fan_ilmu,nama_kitab,tempat,hari,waktu_sholat,waktu_mulai,waktu_selesai,jam,is_active,jam_migration_status)
            SELECT id_tahun,id_kelas,id_guru,CONCAT('Audit T4 jadwal ', $i),'Kitab audit T4',tempat,'Minggu',waktu_sholat,'23:00:00','23:30:00',jam,0,'Berhasil'
            FROM jadwal_ngaji WHERE id=$sourceId");
        $scheduleIds[] = (int) $db->insert_id;
    }
    $r = $req('/admin/admin_pengajian.php');
    preg_match_all('/class="ah-block[^\"]*" href="[^\"]*?[&;?]id=(\d+)/', $r['body'], $m);
    $shown = array_map('intval', array_unique($m[1]));
    $check($r['status'] === 200 && count(array_intersect($scheduleIds, $shown)) === 101,
        'J-1 mingguan menampilkan semua 101 jadwal sintetis melewati batas 100 (terlihat ' . count(array_intersect($scheduleIds, $shown)) . ')');
    $teacherId = (int) $scalar("SELECT id_guru FROM jadwal_ngaji WHERE id=$sourceId");
    $r = $req('/admin/admin_pengajian.php?teacher_id=' . $teacherId);
    preg_match_all('/class="ah-block[^\"]*" href="[^\"]*?[&;?]id=(\d+)/', $r['body'], $m);
    $check(count(array_intersect($scheduleIds, array_map('intval', array_unique($m[1])))) === 101,
        'J-1 seluruh jadwal dalam saringan guru juga terbaca melewati 100');
} finally {
    if ($scheduleIds !== []) { $db->query('DELETE FROM jadwal_ngaji WHERE id IN (' . implode(',', $scheduleIds) . ')'); }
    @unlink($jar);
}
$check($snapshot() === $before, 'Fixture 101 jadwal dibersihkan; seluruh tabel bisnis kembali identik');
echo "Ringkasan: $pass lulus, $fail gagal" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
