<?php

declare(strict_types=1);

// Jalankan sesudah audit_probe pada DB yang sama; tidak mencetak bearer token.
$dbName = getenv('PKF1_DB') ?: 'test_pk_f1_audit_test';
if (!preg_match('/^test_.*_test$/', $dbName)) throw new RuntimeException('Hanya database test_*_test.');
putenv('DB_NAME=' . $dbName);
$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
$db = app_db();
$guru = (int) $db->query("SELECT guru_id FROM users WHERE username='sbx_guru_biasa'")->fetch_row()[0];
$pid = (int) $db->query("SELECT id FROM pengurus WHERE guru_id=$guru")->fetch_row()[0];
if ($pid < 1) throw new RuntimeException('Jalankan audit_probe lebih dahulu.');
$port = (int) (getenv('PKF1_TOKEN_PORT') ?: 8974);
$log = tempnam(sys_get_temp_dir(), 'pkf1-token-');
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, $root . '/tests/redesain_web_tahap1_router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, getenv());
$ok = 0; $bad = 0;
$assert = static function (bool $pass, string $label) use (&$ok, &$bad): void {
    $pass ? $ok++ : $bad++;
    echo ($pass ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
};
$request = static function (string $path, ?string $token = null, ?array $body = null) use ($port): array {
    $h = curl_init('http://127.0.0.1:' . $port . '/api/v1' . $path);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]);
    $raw = curl_exec($h); $status = curl_getinfo($h, CURLINFO_RESPONSE_CODE); curl_close($h);
    return ['status' => $status, 'data' => json_decode((string) $raw, true)['data'] ?? []];
};
try {
    for ($i = 0; $i < 50; $i++) {
        if ($socket = @fsockopen('127.0.0.1', $port)) { fclose($socket); break; }
        usleep(100000);
    }
    $login = $request('/auth/login', null, ['username' => 'sbx_guru_biasa', 'password' => 'Sandbox#123', 'device_name' => 'audit-pkf1']);
    $token = (string) ($login['data']['token'] ?? '');
    $assert($login['status'] === 200 && $token !== '', 'akun guru dua identitas mendapat token');
    $assert($request('/v6/bsa/santri', $token)['status'] === 200, 'token membuka BSA sesuai penugasan guru');
    $assert(in_array('pengurus', $request('/me/capabilities', $token)['data']['list'] ?? [], true), 'token memiliki capability pengurus');
    foreach (['is_active=0' => 'is_active=1', 'archived_at=NOW()' => 'archived_at=NULL'] as $set => $reset) {
        $db->query("UPDATE guru SET $set WHERE id=$guru");
        $assert($request('/v6/bsa/santri', $token)['status'] === 403, "token lama ditolak saat guru $set walau identitas masih aktif");
        $caps = $request('/me/capabilities', $token);
        $assert(!in_array('pengurus', $caps['data']['list'] ?? [], true) && ($caps['data']['konteks']['pengurus_id'] ?? null) === null,
            "token lama kehilangan capability dan konteks pengurus saat guru $set");
        $db->query("UPDATE guru SET $reset WHERE id=$guru");
        $assert($request('/v6/bsa/santri', $token)['status'] === 200, 'kontrol: token yang sama kembali memperoleh hak setelah guru dipulihkan');
    }
    $assert(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', (string) file_get_contents($log)), 'log server tanpa galat PHP');
} finally {
    $db->query("UPDATE guru SET is_active=1, archived_at=NULL WHERE id=$guru");
    proc_terminate($server); proc_close($server);
    unlink($log);
}
echo "\nHasil: $ok lulus, $bad gagal.\n";
exit($bad === 0 ? 0 : 1);
