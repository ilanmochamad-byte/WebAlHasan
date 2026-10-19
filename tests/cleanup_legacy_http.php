<?php

declare(strict_types=1);

// Real HTTP probes plus before/after preservation checks; no fixture teardown.
if (PHP_SAPI !== 'cli' || getenv('CLEANUP_RUN_TESTS') !== '1') exit(77);
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (app_config('env') !== 'testing' || !str_ends_with((string) app_config('database.database'), '_test')) exit(2);
$base = (string) getenv('CLEANUP_TEST_URL');
if (!preg_match('#^http://127\.0\.0\.1:\d+$#D', $base)) exit(2);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = app_db();
$failed = 0; $passed = 0;
$ok = static function (bool $value, string $label) use (&$failed, &$passed): void {
    $value ? $passed++ : $failed++;
    echo ($value ? '[lulus] ' : '[gagal] ') . rtrim($label) . "\n";
};
$jars = [];
$request = static function (string $actor, string $path, string $method = 'GET', ?array $body = null) use ($base, &$jars): array {
    $jars[$actor] ??= tempnam(sys_get_temp_dir(), 'cleanup-cookie-');
    chmod($jars[$actor], 0600);
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEFILE => $jars[$actor], CURLOPT_COOKIEJAR => $jars[$actor],
        CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => $method === 'HEAD']);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
    $raw = curl_exec($ch);
    if ($raw === false) throw new RuntimeException('Local HTTP transport failed.');
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
};
$snapshot = static function () use ($db): array {
    $tables = array_column($db->query("SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC), 'TABLE_NAME');
    $result = [];
    foreach ($tables as $table) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Invalid table identifier.');
        $rows = $db->query('SELECT * FROM `' . $table . '`')->fetch_all(MYSQLI_ASSOC);
        // Login was completed before the snapshot; include all account columns.
        $encoded = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
        sort($encoded, SORT_STRING);
        $result[$table] = ['count' => count($rows), 'sha256' => hash('sha256', implode("\n", $encoded))];
    }
    $relations = [
        'santri_wali_santri' => 'SELECT COUNT(*) n FROM santri_wali x LEFT JOIN santri s ON s.id=x.santri_id WHERE s.id IS NULL',
        'santri_wali_wali' => 'SELECT COUNT(*) n FROM santri_wali x LEFT JOIN wali w ON w.id=x.wali_id WHERE w.id IS NULL',
        'akun_wali' => 'SELECT COUNT(*) n FROM users u LEFT JOIN wali w ON w.id=u.wali_id WHERE u.wali_id IS NOT NULL AND w.id IS NULL',
        'izin_santri' => 'SELECT COUNT(*) n FROM perizinan p LEFT JOIN santri s ON s.id=p.id_santri WHERE s.id IS NULL',
        'pelanggaran_santri' => 'SELECT COUNT(*) n FROM pelanggaran p LEFT JOIN santri s ON s.id=p.id_santri WHERE s.id IS NULL',
        'izin_migrasi' => 'SELECT COUNT(*) n FROM perizinan p LEFT JOIN izin_pengajuan i ON i.id=p.id AND i.legacy_perizinan_id=p.id WHERE i.id IS NULL',
        'izin_nilai_warisan' => "SELECT COUNT(*) n FROM perizinan p JOIN izin_pengajuan i ON i.legacy_perizinan_id=p.id
            WHERE i.id<>p.id OR i.santri_id<>p.id_santri OR i.tgl_izin<>p.tgl_izin OR i.tgl_kembali<>p.tgl_kembali
            OR BINARY i.alasan<>BINARY p.alasan OR i.status<>CASE p.status WHEN 'Pending' THEN 'Diajukan' ELSE p.status END",
        'psb_pembayaran' => 'SELECT COUNT(*) n FROM psb_pembayaran p LEFT JOIN psb_pendaftar s ON s.no_pendaftaran=p.no_pendaftaran WHERE s.id IS NULL',
    ];
    foreach (['kelas','kamar'] as $kind) {
        foreach (['santri' => 'santri', $kind => $kind, 'tahun' => 'tahun_ajaran'] as $column => $target) {
            $relations['plotting_' . $kind . '_' . $column] = 'SELECT COUNT(*) n FROM plotting_' . $kind
                . ' p LEFT JOIN ' . $target . ' t ON t.id=p.id_' . $column . ' WHERE t.id IS NULL';
        }
    }
    foreach ($relations as $label => $sql) $result['integrity'][$label] = (int) $db->query($sql)->fetch_assoc()['n'];
    $result['wali_accounts'] = (int) $db->query('SELECT COUNT(*) n FROM users WHERE wali_id IS NOT NULL')->fetch_assoc()['n'];
    return $result;
};

try {
    foreach (['admin','pengurus_a','murobi_a','guru_biasa','ortu_a','ortu_b'] as $actor) {
        $r = $request($actor, '/portal/index.php');
        preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m);
        $r = $request($actor, '/admin/cek_login.php', 'POST', ['_csrf' => $m[1] ?? '',
            'username' => 'sbx_' . $actor, 'password' => 'Sandbox#123']);
        $ok($r['status'] === 302 || $r['status'] === 303, 'Login fixture ' . $actor);
    }
    $before = $snapshot();
    $legacyId = (int) $db->query('SELECT id FROM perizinan ORDER BY id LIMIT 1')->fetch_assoc()['id'];
    $ok($legacyId > 0 && $before['pelanggaran']['count'] > 0 && $before['psb_pendaftar']['count'] > 0
        && $before['alumni']['count'] > 0 && $before['wali_accounts'] > 0, 'Sentinel nonempty untuk riwayat, PSB, alumni dan akun wali');
    $old = '/admin/admin_izin.php';
    foreach (['', '?legacy=1', '?legacy[]=1', '?id[]=1', '?id=0', '?id=-1', '?id=https://example.com', '?id=999999999999999999999999'] as $query) {
        $r = $request('admin', $old . $query);
        $ok($r['status'] === 302 && str_contains($r['headers'], 'Location: ' . app_url('/portal/izin.php') . "\r\n"), 'GET kompatibilitas ' . ($query ?: 'daftar'));
    }
    foreach (['GET','HEAD'] as $method) {
        $r = $request('admin', $old . '?legacy=1&id=' . $legacyId, $method);
        $ok($r['status'] === 302 && str_contains($r['headers'], '/portal/izin_detail.php?id=' . $legacyId), $method . ' bookmark mempertahankan ID');
    }
    $r = $request('admin', '/admin/admin_akun.php');
    preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m);
    $csrf = $m[1] ?? '';
    $ok($csrf !== '', 'Token CSRF admin tersedia untuk probe POST bertoken');
    foreach (['', '?legacy=1', '?legacy=1&id=' . $legacyId . '&status=Disetujui', '?legacy=1&hapus=' . $legacyId] as $query) {
        foreach ([[], ['_csrf' => $csrf]] as $token) {
            $r = $request('admin', $old . $query, 'POST', $token + ['tambah' => '1', 'id_santri' => '1',
                'tgl_izin' => '2027-01-01', 'tgl_kembali' => '2027-01-02', 'alasan' => 'Synthetic forbidden write']);
            $ok($r['status'] === 410 && !str_contains($r['headers'], 'Location:')
                && str_contains($r['body'], 'Tidak ada data izin yang diubah'), 'POST lama ditolak tanpa penerusan, ' . ($token ? 'CSRF sah' : 'tanpa CSRF') . ' ' . $query);
        }
    }
    foreach (['?status=Disetujui&id=' . $legacyId, '?hapus=' . $legacyId, '?legacy=1&status[]=Disetujui', '?hapus[]=1'] as $query) {
        $r = $request('admin', $old . $query);
        $ok($r['status'] === 410 && !str_contains($r['headers'], 'Location:'), 'Mutasi GET lama ditolak ' . $query);
    }
    foreach (['PUT','PATCH','DELETE','OPTIONS'] as $method) {
        $r = $request('admin', $old . '?legacy=1', $method);
        $ok($r['status'] === 410 && str_contains($r['headers'], 'Allow: GET, HEAD'), $method . ' lama ditolak');
    }
    foreach (['pengurus_a','murobi_a','guru_biasa','ortu_a'] as $actor) {
        foreach (['GET','POST'] as $method) {
            $ok($request($actor, $old . '?legacy=1', $method)['status'] === 403, $actor . ' ' . $method . ' lama tetap khusus admin');
        }
    }
    foreach (['GET','POST'] as $method) {
        $r = $request('anonymous', $old . '?legacy=1', $method);
        $ok($r['status'] === 302 && str_contains($r['headers'], '/portal/index.php?pesan=sesi'), 'Anonim ' . $method . ' wajib login');
    }
    $r = $request('admin', '/portal/izin_detail.php?id=' . $legacyId);
    $ok($r['status'] === 200 && str_contains($r['body'], 'Data warisan'), 'Riwayat ID lama terbaca melalui portal V2');
    $r = $request('admin', '/portal/izin.php?source=legacy');
    $ok($r['status'] === 200 && str_contains($r['body'], 'CLEANUP synthetic history'), 'Filter Data warisan V2 tetap tersedia');
    $r = $request('admin', '/admin/admin_pelanggaran.php');
    $ok($r['status'] === 200 && str_contains($r['body'], 'Data warisan') && str_contains($r['body'], '&lt;script&gt;synthetic&lt;/script&gt;'), 'Arsip pelanggaran tetap terbaca dan escape XSS');
    foreach ([['GET','?hapus=1',null,405], ['POST','',[],419], ['POST','',['_csrf' => $csrf,'tambah'=>'1'],405]] as [$method,$query,$body,$expected]) {
        $ok($request('admin', '/admin/admin_pelanggaran.php' . $query, $method, $body)['status'] === $expected, 'Arsip pelanggaran menolak ' . $method . ' ' . $expected);
    }
    foreach (['admin_master_santri','admin_wali','admin_penempatan_santri','admin_pengajian','admin_alumni','admin_data','admin_pembayaran_psb','admin_rekap_keuangan','admin_rekap_santri'] as $page) {
        $ok($request('admin', '/admin/' . $page . '.php')['status'] === 200, 'Halaman dipertahankan ' . $page);
    }
    foreach (['/','/psb.php','/berita.php','/galeri.php','/download.php'] as $path) {
        $ok($request('anonymous', $path)['status'] === 200, 'Situs publik ' . $path);
    }
    $r = $request('admin', '/portal/index.php');
    $ok(str_contains($r['body'], '/portal/izin.php') && !str_contains($r['body'], '/admin/admin_izin.php'), 'Menu perizinan hanya menuju alur V2');
    $ok(str_contains($r['body'], '/admin/admin_pelanggaran.php') && str_contains($r['body'], '/admin/admin_data.php'), 'Menu arsip pelanggaran dan PSB dipertahankan');
    $after = $snapshot();
    foreach ($before as $table => $state) {
        $ok($after[$table] === $state, 'Preservasi jumlah dan seluruh nilai/ID ' . $table);
        if (is_array($state) && isset($state['count'])) echo '[data sintetis] ' . $table . ' before=' . $state['count'] . ' after=' . $after[$table]['count'] . ' fingerprint=identik' . "\n";
    }
    $ok(array_sum($after['integrity']) === 0, 'Referensi yatim eksplisit = 0');
    echo '[integritas] ' . json_encode($after['integrity']) . "\n";
} finally {
    foreach ($jars as $jar) unlink($jar);
}
echo "Hasil cleanup HTTP/preservasi: $passed lulus, $failed gagal.\n";
exit($failed ? 1 : 0);
