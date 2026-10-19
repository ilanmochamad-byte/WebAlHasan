<?php
declare(strict_types=1);

// Independent Codex probes: methods, CSRF transport/session, parameter precedence,
// forged filenames and full-table preservation. Synthetic disposable sandbox only.
if (PHP_SAPI !== 'cli' || getenv('GETMUTASI_RUN_TESTS') !== '1') exit(77);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$base = (string) getenv('GETMUTASI_TEST_URL');
$root = realpath((string) getenv('GETMUTASI_WEB_ROOT'));
if (app_config('env') !== 'testing' || !str_ends_with((string) app_config('database.database'), '_test')
    || !preg_match('#^http://127\.0\.0\.1:\d+$#D', $base) || !$root || file_exists($root . '/.git')) exit(2);
$db = app_db();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$passed = 0; $failed = 0; $jars = []; $files = [];
$ok = static function (bool $v, string $label) use (&$passed, &$failed): void {
    $v ? $passed++ : $failed++;
    echo ($v ? '[lulus] ' : '[gagal] ') . $label . "\n";
};
$request = static function (string $actor, string $path, string $method = 'GET', array $body = [], array $headers = []) use ($base, &$jars): array {
    $jars[$actor] ??= tempnam(sys_get_temp_dir(), 'gm-audit-');
    chmod($jars[$actor], 0600);
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE => $jars[$actor], CURLOPT_COOKIEJAR => $jars[$actor], CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_HTTPHEADER => $headers]);
    if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
    $raw = curl_exec($ch);
    if ($raw === false) throw new RuntimeException('Local HTTP failed.');
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $result = ['status' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($ch);
    return $result;
};
$token = static fn (array $r): string => preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m) ? $m[1] : '';
$snapshot = static function () use ($db): array {
    $result = [];
    foreach ($db->query('SHOW TABLES')->fetch_all() as [$table]) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Unsafe table name.');
        $rows = array_map(static fn ($row) => json_encode($row, JSON_THROW_ON_ERROR), $db->query('SELECT * FROM `' . $table . '`')->fetch_all(MYSQLI_ASSOC));
        sort($rows, SORT_STRING);
        $result[$table] = count($rows) . ':' . hash('sha256', implode("\n", $rows));
    }
    ksort($result);
    return $result;
};
$scalar = static fn (string $sql) => $db->query($sql)->fetch_row()[0] ?? null;
$touch = static function (string $path) use ($root, &$files): string {
    $full = $root . '/' . $path;
    if (!is_dir(dirname($full))) mkdir(dirname($full), 0700, true);
    file_put_contents($full, 'Synthetic Codex audit');
    $files[] = $full;
    return $full;
};

try {
    foreach (['a', 'b'] as $actor) {
        $r = $request($actor, '/portal/index.php');
        $r = $request($actor, '/admin/cek_login.php', 'POST', ['_csrf' => $token($r), 'username' => 'sbx_admin', 'password' => 'Sandbox#123']);
        $ok($r['status'] === 302, 'Login independent admin session ' . $actor);
    }
    $csrf = $token($request('a', '/admin/admin_data.php'));
    $otherCsrf = $token($request('b', '/admin/admin_data.php'));
    $ok($csrf !== '' && $otherCsrf !== '' && $csrf !== $otherCsrf, 'CSRF differs between admin sessions');
    $id = (int) $scalar("SELECT id FROM psb_pendaftar WHERE no_pendaftaran='CLEANUP-PSB'");
    $pay = (int) $scalar("SELECT id FROM psb_pembayaran WHERE no_pendaftaran='CLEANUP-PSB'");
    if (!$id || !$pay) throw new RuntimeException('Fresh synthetic cleanup fixture required.');
    $cases = [
        ['/admin/hapus_siswa.php', ['id' => $id]],
        ['/admin/proses_psb_admin.php', ['act' => 'status', 'id' => $id, 'val' => 'Diterima']],
        ['/admin/proses_psb_admin.php', ['act' => 'migrasi', 'id' => $id]],
        ['/admin/admin_pembayaran_psb.php', ['act' => 'lunas', 'id' => $pay]],
        ['/admin/admin_pembayaran_psb.php', ['act' => 'batal', 'id' => $pay]],
        ['/admin/proses_berita.php?act=hapus', ['id' => 0]],
        ['/admin/admin_galeri.php', ['hapus' => 0]],
        ['/admin/admin_download.php', ['hapus' => 0]],
    ];
    $before = $snapshot();
    foreach ($cases as [$path, $body]) {
        $legacy = $path . (str_contains($path, '?') ? '&' : '?') . http_build_query($body);
        foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $r = $request('a', $legacy, $method, $body + ['_csrf' => $csrf, '_method' => 'POST'], ['X-HTTP-Method-Override: POST']);
            $ok($r['status'] === 405 && str_contains($r['headers'], 'Allow: POST'), $method . ' with body/override refused: ' . $path);
        }
        $r = $request('a', $path, 'POST', $body + ['_csrf' => $otherCsrf]);
        $ok($r['status'] === 419, 'Cross-session CSRF refused: ' . $path);
        $queryToken = $path . (str_contains($path, '?') ? '&' : '?') . '_csrf=' . $csrf;
        $ok($request('a', $queryToken, 'POST', $body)['status'] === 419, 'Query-only CSRF refused: ' . $path);
        $ok($request('a', $path, 'POST', $body + ['_csrf' => 'invalid'], ['X-CSRF-Token: ' . $csrf])['status'] === 419,
            'Invalid body CSRF cannot be bypassed by header: ' . $path);
    }
    $ok($snapshot() === $before, 'All tables including users/audit/tokens preserved after refused requests');
    $r = $request('a', '/admin/proses_psb_admin.php?act=migrasi&id=0&val=Ditolak', 'POST',
        ['act' => 'status', 'id' => $id, 'val' => 'Cadangan'], ['X-CSRF-Token: ' . $csrf]);
    $ok($r['status'] === 200 && $scalar('SELECT status FROM psb_pendaftar WHERE id=' . $id) === 'Cadangan',
        'Header CSRF accepted; status/id/val come from POST; Cadangan remains valid');
    $request('a', '/admin/proses_psb_admin.php', 'POST', ['_csrf' => $csrf, 'act' => 'status', 'id' => $id, 'val' => 'Diterima']);
    $after = $snapshot();
    unset($before['psb_pendaftar'], $after['psb_pendaftar']);
    $ok($before === $after, 'Valid status mutation leaves every other table unchanged');

    $tag = 'CA-' . bin2hex(random_bytes(4));
    foreach (['berita' => ['gambar', 'admin/gambar/', '/admin/proses_berita.php?act=hapus', 'id'],
        'galeri' => ['gambar', 'gambar_galeri/', '/admin/admin_galeri.php', 'hapus'],
        'download' => ['nama_file', 'admin/file_download/', '/admin/admin_download.php', 'hapus']] as $table => [$col, $dir, $path, $field]) {
        $targetName = $tag . '-' . $table . '-target.txt';
        $baitName = $tag . '-' . $table . '-bait.txt';
        $target = $touch($dir . $targetName); $bait = $touch($dir . $baitName);
        $extra = $table === 'berita' ? ', isi_berita' : ($table === 'galeri' ? ', keterangan' : '');
        $value = $extra === '' ? '' : ", 'Synthetic audit'";
        $stmt = $db->prepare('INSERT INTO `' . $table . '` (judul, `' . $col . '`' . $extra . ') VALUES (?, ?' . $value . ')');
        $stmt->bind_param('ss', $tag, $targetName); $stmt->execute(); $targetId = (int) $db->insert_id;
        $before = $snapshot();
        $r = $request('a', $path . (str_contains($path, '?') ? '&' : '?') . $field . '=0&file=' . $baitName . '&gbr=' . $baitName,
            'POST', ['_csrf' => $csrf, $field => $targetId, 'file' => $baitName, 'gbr' => $baitName]);
        $ok(in_array($r['status'], [200, 302], true) && !$scalar('SELECT id FROM `' . $table . '` WHERE id=' . $targetId)
            && !is_file($target) && is_file($bait), $table . ': forged request filename ignored, POST target and DB filename used');
        $after = $snapshot(); unset($before[$table], $after[$table]);
        $ok($before === $after, $table . ': every other table preserved');
    }
} finally {
    foreach (array_merge($jars, $files) as $path) if (is_file($path)) unlink($path);
}
echo "Independent Codex audit: {$passed} passed, {$failed} failed.\n";
exit($failed ? 1 : 0);
