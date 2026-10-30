<?php
declare(strict_types=1);

// Probe auditor Fase 3. DB sintetis bersih dari f3_db.sh; server HTTP lokal.
// Tidak memakai hasil suite implementator sebagai oracle.
$name = getenv('PKF3_DB') ?: 'test_pk_f3_probe_test';
if (!preg_match('/^test_[A-Za-z0-9_]+_test$/D', $name)) exit(2);
putenv('DB_NAME=' . $name);
require (getenv('PKF3_KODE') ?: dirname(__DIR__)) . '/app/bootstrap.php';
$db = app_db();
$ids = require __DIR__ . '/penugasan_keamanan_f3_seed.php';
$one = static fn (string $q): mixed => $db->query($q)->fetch_row()[0] ?? null;
$pass = $fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$port = (int) (getenv('PKF3_PORT') ?: 8994);
$log = tempnam(sys_get_temp_dir(), 'pkf3-audit-http-');
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, APP_ROOT . '/tests/redesain_web_tahap1_router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, APP_ROOT, getenv());
register_shutdown_function(static function () use ($server, $ids, $db): void {
    $db->query('DROP TRIGGER IF EXISTS pkf3_audit_reject');
    if (is_resource($server)) proc_terminate($server);
    @unlink($ids['lampiran_berkas']);
});
for ($i = 0; $i < 50; $i++) {
    if ($s = @fsockopen('127.0.0.1', $port)) { fclose($s); break; }
    usleep(100000);
}
$jar = tempnam(sys_get_temp_dir(), 'pkf3-audit-cookie-');
$req = static function (string $path, string $method = 'GET', array $data = []) use ($jar, $port): array {
    $c = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => $method]);
    if ($data !== []) curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($data));
    $out = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $len = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    return ['status' => $status, 'body' => substr($out, $len), 'headers' => substr($out, 0, $len)];
};
$csrf = static function (array $r): string {
    preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m);
    return $m[1] ?? '';
};
$req('/admin/cek_login.php', 'POST', ['_csrf' => $csrf($req('/portal/index.php')),
    'username' => 'sbx_pengurus_biasa', 'password' => 'Sandbox#123']);
$check($req('/portal/keamanan_izin.php')['status'] === 200, 'Kontrol login Keamanan');

// Santri peserta nyata diberi penanda snapshot unik; status kini berubah.
$sample = $db->query('SELECT a.santri_id, a.pertemuan_id, p.tanggal_pertemuan FROM absensi_santri a
    JOIN pertemuan_pengajian p ON p.id=a.pertemuan_id JOIN santri s ON s.id=a.santri_id
    WHERE s.is_active=1 AND s.archived_at IS NULL ORDER BY a.id LIMIT 1')->fetch_assoc();
if (!$sample) throw new RuntimeException('Fixture kehadiran kosong');
$sid = (int) $sample['santri_id']; $mid = (int) $sample['pertemuan_id'];
$db->query("UPDATE pertemuan_peserta SET nama_santri_snapshot='PKF3_AUDIT_PESERTA' WHERE santri_id=$sid");
$query = http_build_query(['date_from' => $sample['tanggal_pertemuan'], 'date_to' => $sample['tanggal_pertemuan'], 'per_page' => 100]);
$list = '/admin/admin_laporan_absensi.php?' . $query;
$print = '/admin/laporan_absensi_cetak.php?' . $query;
$detail = '/admin/laporan_absensi_detail.php?id=' . $mid;
$check(str_contains($req($print)['body'], 'PKF3_AUDIT_PESERTA'), 'Kontrol peserta aktif terbaca pada cetak');
$db->query("UPDATE santri SET is_active=0 WHERE id=$sid");
// Oracle peserta aktif berasal dari ID master kini, tidak dari helper cakupan laporan.
$input = ['date_from' => $sample['tanggal_pertemuan'], 'date_to' => $sample['tanggal_pertemuan'], 'per_page' => 100];
$admin = ['id' => $ids['admin'], 'roles' => ['admin']];
$marked = ['id' => $ids['k'], 'roles' => ['pengurus'],
    '_laporan_baca_semua_jadwal' => true, '_laporan_santri_aktif_saja' => true];
$all = report_service()->exportRows($input, $admin, 'santri');
$activeIds = array_map('intval', array_column($db->query('SELECT id FROM santri WHERE is_active=1 AND archived_at IS NULL')->fetch_all(MYSQLI_ASSOC), 'id'));
$expected = array_values(array_filter($all['items'], static fn (array $r): bool => in_array($r['subject_id'], $activeIds, true)));
$limited = report_service()->exportRows($input, $marked, 'santri');
$check(count($expected) < count($all['items']) && $limited['items'] === $expected, 'F-1 seluruh baris cetak tepat sama oracle master aktif');
$page = report_service()->report($input, $marked, 'santri');
$check($page['summary']['student_attendance_count'] === count($expected)
    && $page['pagination']['total'] === count($expected)
    && array_sum(array_column($page['schedules'], 'detail_count')) === count($expected),
    'F-1 ringkasan, halaman, dan angka per jadwal sama oracle');
$filter = App\Report\ReportFilter::fromInput($input, 'Asia/Jakarta', 'santri')->forUser($marked)->withPagination(1, 100);
$repo = new App\Report\ReportRepository($db);
$check((int) $repo->summary($filter)['detail_count'] === count($expected), 'F-1 batas peserta aktif bertahan saat pagination diganti');
$check(report_service()->exportRows($input + ['_laporan_santri_aktif_saja' => true, 'activeStudentsOnly' => true], $admin, 'santri')['items'] === $all['items'],
    'Kontrol admin tetap membaca riwayat nonaktif; parameter HTTP tidak memasang pembatas internal');
foreach ([$list, $print, $detail] as $path) {
    $r = $req($path);
    $check($r['status'] === 200 && !str_contains($r['body'], 'PKF3_AUDIT_PESERTA'), 'F-1 santri nonaktif disaring: ' . strtok($path, '?'));
}
$db->query("UPDATE santri SET is_active=1,archived_at=NOW() WHERE id=$sid");
$check(!str_contains($req($print)['body'], 'PKF3_AUDIT_PESERTA'), 'F-1 santri diarsipkan disaring dari cetak');
$db->query("UPDATE santri SET archived_at=NULL WHERE id=$sid");
$check(str_contains($req($detail)['body'], 'PKF3_AUDIT_PESERTA'), 'F-1 pemulihan status aktif langsung terbaca pada sesi sama');

$count = static fn (string $action): int => (int) $one("SELECT COUNT(*) FROM audit_logs WHERE action='$action'");
$before = $count('keamanan.kehadiran.dilihat');
$r = $req($detail);
$check($r['status'] === 200 && $count('keamanan.kehadiran.dilihat') === $before + 1,
    'F-2 setiap rincian pertemuan menulis audit Keamanan');
$before = $count('keamanan.kehadiran.dicetak');
$r = $req($print);
$check($r['status'] === 200 && $count('keamanan.kehadiran.dicetak') === $before + 1,
    'F-2 cetak kehadiran menulis audit Keamanan');

// Kegagalan INSERT audit nyata, tanpa mematikan DB pembacaan.
$db->query("CREATE TRIGGER pkf3_audit_reject BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture audit reject'");
foreach ([$detail, $print, '/portal/keamanan_izin_detail.php?id=' . $ids['izin_wali'],
    '/portal/keamanan_pelanggaran_detail.php?id=' . $ids['pelanggaran_internal'],
    '/portal/keamanan_konseling_detail.php?id=' . $ids['kasus_internal'],
    '/portal/keamanan_lampiran.php?id=' . $ids['lampiran']] as $path) {
    $r = $req($path);
    $check($r['status'] === 503 && !str_contains($r['body'], 'PKF3_AUDIT_PESERTA') && !str_contains($r['body'], $ids['alasan_wali']),
        'F-2 audit gagal menutup isi dengan 503: ' . strtok($path, '?'));
}
$db->query('DROP TRIGGER pkf3_audit_reject');

// Semua rute baru dan rute kehadiran menolak seluruh metode mutasi.
$paths = array_map(static fn (string $p): string => '/portal/' . basename($p), glob(APP_ROOT . '/portal/keamanan_*.php'));
$paths = array_merge($paths, ['/admin/admin_laporan_absensi.php', '/admin/laporan_absensi_detail.php', '/admin/laporan_absensi_cetak.php', '/admin/export_laporan_absensi.php']);
foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
    $bad = [];
    foreach ($paths as $path) if ($req($path, $method)['status'] !== 405) $bad[] = $path;
    $check($bad === [], $method . ' ditolak 405 pada seluruh ' . count($paths) . ' rute' . ($bad ? ': ' . implode(',', $bad) : ''));
}

// Tidak bergantung semester, tetapi tanggal/status penugasan tetap berlaku.
$db->query("UPDATE tahun_ajaran SET status='Nonaktif' WHERE id=" . $ids['tahun']);
$check($req('/portal/keamanan_izin.php')['status'] === 200 && $req($detail)['status'] === 200,
    'Penugasan Keamanan tetap berlaku setelah semester nonaktif');
$db->query("UPDATE keamanan_assignments SET tanggal_selesai=CURDATE()-INTERVAL 1 DAY,tanggal_mulai=CURDATE()-INTERVAL 2 DAY WHERE id=" . $ids['penugasan_k']);
$bad = [];
foreach (array_merge($paths, ['/portal/keamanan_izin_detail.php?id=' . $ids['izin_wali']]) as $path) {
    if ($req($path)['status'] !== 403) $bad[] = $path;
}
$check($bad === [], 'Penugasan berakhir menutup seluruh rute pada sesi lama');
$check(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', (string) file_get_contents($log)), 'Server probe tanpa galat PHP (audit gagal dicatat umum)');
echo "Hasil: $pass lulus, $fail gagal.\n";
exit($fail === 0 ? 0 : 1);
