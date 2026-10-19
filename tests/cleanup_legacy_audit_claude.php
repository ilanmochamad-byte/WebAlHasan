<?php

declare(strict_types=1);

// Audit independen cleanup V1–V4 (Claude Code, 19 Oktober 2026).
// Probe tambahan di luar tests/cleanup_legacy_http.php: variasi metode/header,
// HEAD bermutasi, path-info, header injection, cache, aksi V2 pada data warisan,
// lalu sidik jari seluruh tabel sebelum/sesudah. Hanya DB *_test dan 127.0.0.1.
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
    echo ($value ? '[lulus] ' : '[gagal] ') . $label . "\n";
};
$jars = [];
$request = static function (string $actor, string $path, string $method = 'GET', ?array $body = null, array $headers = []) use ($base, &$jars): array {
    $jars[$actor] ??= tempnam(sys_get_temp_dir(), 'cleanup-audit-cookie-');
    chmod($jars[$actor], 0600);
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEFILE => $jars[$actor], CURLOPT_COOKIEJAR => $jars[$actor],
        CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
    $raw = curl_exec($ch);
    if ($raw === false) throw new RuntimeException('Local HTTP transport failed.');
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
};
$csrfOf = static fn (array $r): string => preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m) ? $m[1] : '';
$snapshot = static function () use ($db): array {
    $tables = array_column($db->query("SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC), 'TABLE_NAME');
    $result = [];
    foreach ($tables as $table) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Invalid table identifier.');
        $rows = $db->query('SELECT * FROM `' . $table . '`')->fetch_all(MYSQLI_ASSOC);
        $encoded = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
        sort($encoded, SORT_STRING);
        $result[$table] = ['count' => count($rows), 'sha256' => hash('sha256', implode("\n", $encoded))];
    }
    $ai = $db->query("SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('perizinan','izin_pengajuan','izin_riwayat_status','izin_keputusan','audit_logs')")->fetch_all(MYSQLI_ASSOC);
    foreach ($ai as $row) $result['auto_increment'][$row['TABLE_NAME']] = $row['AUTO_INCREMENT'];
    if (count($result['auto_increment'] ?? []) !== 5) throw new RuntimeException('Tabel AUTO_INCREMENT yang diawasi tidak lengkap.');
    return $result;
};

try {
    foreach (['admin', 'pengurus_a', 'ortu_a', 'ortu_b'] as $actor) {
        $r = $request($actor, '/portal/index.php');
        $r = $request($actor, '/admin/cek_login.php', 'POST', ['_csrf' => $csrfOf($r),
            'username' => 'sbx_' . $actor, 'password' => 'Sandbox#123']);
        $ok(in_array($r['status'], [302, 303], true), 'Login fixture ' . $actor);
    }
    $ok(getenv('IZIN_LEGACY_ENABLED') === 'true', 'Proses uji memakai IZIN_LEGACY_ENABLED=true (server dijalankan dengan environment sama)');
    $before = $snapshot();
    $legacyId = (int) $db->query('SELECT id FROM perizinan ORDER BY id LIMIT 1')->fetch_assoc()['id'];
    $legacyRow = $db->query('SELECT status, version FROM izin_pengajuan WHERE id=' . $legacyId . ' AND is_legacy=1')->fetch_assoc();
    $ok($legacyId > 0 && $legacyRow !== null, 'Data warisan sintetis termigrasi dengan ID sama');
    $old = '/admin/admin_izin.php';

    // 1. HEAD yang membawa aksi mutasi lama harus ditolak, bukan dialihkan.
    foreach (['?legacy=1&id=' . $legacyId . '&status=Disetujui', '?legacy=1&hapus=' . $legacyId] as $q) {
        $r = $request('admin', $old . $q, 'HEAD');
        $ok($r['status'] === 410 && !str_contains($r['headers'], 'Location:'), 'HEAD mutasi lama ditolak ' . $q);
    }
    // 2. Override metode via header/parameter tidak membuka penulisan.
    foreach (['X-HTTP-Method-Override: GET', 'X-HTTP-Method: GET', 'X-Method-Override: GET'] as $h) {
        $r = $request('admin', $old . '?legacy=1', 'POST', ['tambah' => '1', 'id_santri' => '1', 'tgl_izin' => '2027-01-01',
            'tgl_kembali' => '2027-01-02', 'alasan' => 'Synthetic override'], [$h]);
        $ok($r['status'] === 410, 'POST dengan ' . $h . ' tetap 410');
    }
    $r = $request('admin', $old . '?legacy=1&_method=GET', 'POST', ['_method' => 'GET', 'tambah' => '1']);
    $ok($r['status'] === 410, 'POST dengan _method=GET tetap 410');
    // 3. Metode huruf kecil/tak dikenal tidak dianggap GET.
    foreach (['get', 'PROPFIND', 'TRACE'] as $m) {
        try {
            $status = $request('admin', $old . '?legacy=1', $m)['status'];
        } catch (RuntimeException) {
            $status = 0; // server pengembangan memutus metode tidak sah; tidak ada pemrosesan
        }
        $ok(!in_array($status, [200, 302], true), 'Metode ' . $m . ' tidak diperlakukan sebagai GET (' . $status . ')');
    }
    // 4. Path-info pada skrip lama tetap memakai guard yang sama.
    $r = $request('admin', $old . '/x?hapus=' . $legacyId);
    $ok($r['status'] === 410 || $r['status'] === 404, 'Path-info + hapus tidak memproses mutasi (' . $r['status'] . ')');
    // 5. Header injection / open redirect melalui id.
    foreach (['?id=1%0d%0aSet-Cookie:%20x=1', '?id=1%0aLocation:%20https://example.com', '?id=//example.com', '?id=' . $legacyId . '%23x'] as $q) {
        $r = $request('admin', $old . $q);
        $ok($r['status'] === 302 && !str_contains($r['headers'], 'example.com') && !str_contains($r['headers'], 'x=1')
            && preg_match('#\r\nLocation: ' . preg_quote(app_url('/portal/izin'), '#') . '(\.php|_detail\.php\?id=\d+)\r\n#', $r['headers']) === 1,
            'Location tetap aman untuk ' . $q);
    }
    // 6. Respons kompatibilitas tidak di-cache.
    foreach (['', '?hapus=' . $legacyId] as $q) {
        $r = $request('admin', $old . $q);
        $ok(stripos($r['headers'], 'Cache-Control: no-store') !== false, 'Cache-Control no-store ' . ($q ?: 'redirect'));
    }
    // 7. Anonim dan nonadmin dengan aksi mutasi: ditolak sebelum aksi, tanpa 410 yang membocorkan.
    foreach (['GET', 'HEAD', 'POST'] as $m) {
        $r = $request('anonymous', $old . '?hapus=' . $legacyId, $m);
        $ok($r['status'] === 302 && str_contains($r['headers'], '/portal/index.php'), 'Anonim ' . $m . ' hapus diarahkan ke login');
        $ok($request('ortu_a', $old . '?hapus=' . $legacyId, $m)['status'] === 403, 'Wali ' . $m . ' hapus 403');
    }
    // 8. Jalur V2 pengganti: CSRF wajib, dan aksi pada data warisan ditolak layanan.
    $r = $request('admin', '/portal/izin_aksi.php', 'POST', ['aksi' => 'putuskan', 'mode' => 'admin',
        'pengajuan_id' => $legacyId, 'hasil' => 'Disetujui', 'alasan' => 'Synthetic', 'version' => $legacyRow['version'],
        'idempotency_key' => bin2hex(random_bytes(16))]);
    $ok($r['status'] === 419, 'V2 izin_aksi tanpa CSRF ditolak 419 (' . $r['status'] . ')');
    // Detail warisan sengaja tanpa formulir aksi; token diambil dari formulir V2 lain.
    $ok(!str_contains($request('admin', '/portal/izin_detail.php?id=' . $legacyId)['body'], 'name="aksi"'), 'Detail warisan tidak menawarkan formulir aksi');
    $csrf = $csrfOf($request('admin', '/portal/izin_buat.php'));
    $ok($csrf !== '', 'Token CSRF portal tersedia');
    foreach (['putuskan', 'koreksi', 'batalkan', 'tetapkan'] as $aksi) {
        $r = $request('admin', '/portal/izin_aksi.php', 'POST', ['_csrf' => $csrf, 'aksi' => $aksi, 'mode' => 'admin',
            'pengajuan_id' => $legacyId, 'hasil' => 'Disetujui', 'alasan' => 'Synthetic audit', 'alasan_koreksi' => 'Synthetic audit', 'alasan_penggantian' => 'Synthetic audit',
            'murobi_guru_id' => '1', 'version' => $legacyRow['version'], 'idempotency_key' => bin2hex(random_bytes(16))]);
        // Harus berhenti di guard warisan, bukan sekadar galat validasi lain. Koreksi tidak
        // punya guard is_legacy eksplisit; ia ditolak karena warisan tidak pernah punya
        // izin_keputusan (invarian backfill + requirePending pada putuskan).
        $guard = $aksi === 'koreksi' ? 'belum memiliki keputusan yang dapat dikoreksi' : 'warisan V1 bersifat baca-saja';
        $ok($r['status'] === 409 && str_contains($r['body'], $guard),
            'V2 menolak ' . $aksi . ' pada data warisan dengan guard warisan (' . $r['status'] . ')');
    }
    // Cakupan wali dihitung dari santri_wali, bukan dari alamat lama.
    $owner = $db->query('SELECT u.username FROM perizinan p JOIN santri_wali sw ON sw.santri_id=p.id_santri
        JOIN users u ON u.wali_id=sw.wali_id WHERE p.id=' . $legacyId)->fetch_all(MYSQLI_ASSOC);
    $owners = array_column($owner, 'username');
    foreach (['ortu_a', 'ortu_b'] as $actor) {
        $status = $request($actor, '/portal/izin_detail.php?id=' . $legacyId)['status'];
        $inScope = in_array('sbx_' . $actor, $owners, true);
        $ok($inScope ? $status === 200 : in_array($status, [403, 404], true),
            'Detail warisan ' . $actor . ' ' . ($inScope ? 'dalam' : 'di luar') . ' cakupan (' . $status . ')');
    }

    $after = $snapshot();
    foreach ($before as $table => $state) {
        $ok($after[$table] === $state, 'Preservasi ' . $table);
    }
    $ok((int) $db->query('SELECT COUNT(*) n FROM izin_keputusan k JOIN izin_pengajuan p ON p.id=k.pengajuan_id
        WHERE p.is_legacy=1')->fetch_assoc()['n'] === 0, 'Tidak ada keputusan V2 yang menempel pada data warisan');
    $now = $db->query('SELECT status, version FROM izin_pengajuan WHERE id=' . $legacyId)->fetch_assoc();
    $ok($now === $legacyRow, 'Status/version data warisan tidak berubah');
} finally {
    foreach ($jars as $jar) @unlink($jar);
}
echo "Hasil audit Claude cleanup: $passed lulus, $failed gagal.\n";
exit($failed ? 1 : 0);
