<?php

declare(strict_types=1);

// Probe independen alat preservasi. Hanya fixture sintetis dan SELECT sementara;
// setiap perubahan audit dipulihkan di finally, keluaran tanpa isi/credential.
if (getenv('V7_RUN_AUDIT') !== '1' || !preg_match('/^test_[A-Za-z0-9_]*_test$/D', (string) getenv('DB_NAME'))) exit(2);
require_once dirname(__DIR__) . '/bin/_v7_rilis.php';
$db = app_db();
$pass = $fail = 0;
$assert = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$run = static function (string $file, ?string $manifest = null): array {
    $lines = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/bin/' . $file)
        . ($manifest === null ? '' : ' ' . escapeshellarg($manifest)) . ' 2>&1', $lines, $code);
    return [$code, implode("\n", $lines)];
};
$left = v7_hash_kueri($db, "SELECT CONCAT('a', CHAR(31), 'b'), 'c'");
$right = v7_hash_kueri($db, "SELECT 'a', CONCAT('b', CHAR(31), 'c')");
$assert($left !== $right, 'Hash membedakan pemisah kolom di dalam nilai teks');
$assert(v7_hash_kueri($db, "SELECT NULL, 'a'") !== v7_hash_kueri($db, "SELECT CHAR(0), 'a'"), 'Hash membedakan NULL dan string byte NUL');
$assert(v7_hash_kueri($db, "SELECT 'a' UNION ALL SELECT 'b'") === v7_hash_kueri($db, "SELECT 'b' UNION ALL SELECT 'a'"), 'Hash tetap tidak bergantung urutan baris');
$assert(v7_hash_kueri($db, "SELECT 'a'") !== v7_hash_kueri($db, "SELECT 'a' UNION ALL SELECT 'a'"), 'Baris kembar tetap dihitung');
$manifest = tempnam(sys_get_temp_dir(), 'v7f5-codex-');
try {
    [$code] = $run('v7_preflight.php', $manifest);
    [$verify] = $run('v7_verify.php', $manifest);
    $assert($code === 0 && $verify === 0, 'Kontrol positif: preflight dan verifier manifest asli lulus');
    $old = $db->query("SELECT id, after_json FROM audit_logs WHERE action NOT LIKE 'izin\\_wali\\_%' ORDER BY id LIMIT 1")->fetch_assoc();
    if ($old === null) throw new RuntimeException('Fixture audit lama wajib tersedia.');
    try {
        $db->query("UPDATE audit_logs SET after_json = '{\"probe_preservasi\":true}' WHERE id = " . (int) $old['id']);
        [$code, $out] = $run('v7_verify.php', $manifest);
        $assert($code === 1 && str_contains($out, '[gagal] Isi seluruh') && str_contains($out, 'audit_logs'), 'Perubahan isi audit lama tanpa perubahan jumlah wajib ditolak verifier');
    } finally {
        $stmt = $db->prepare('UPDATE audit_logs SET after_json = ? WHERE id = ?');
        $stmt->bind_param('si', $old['after_json'], $old['id']); $stmt->execute(); $stmt->close();
    }
    [$code] = $run('v7_verify.php', $manifest);
    $assert($code === 0, 'Kontrol pemulihan: isi audit dikembalikan, verifier kembali lulus');
    $data = json_decode((string) file_get_contents($manifest), true);
    unset($data['isi_tabel_lama']['audit_logs'], $data['legacy_counts']['audit_logs']);
    file_put_contents($manifest, json_encode($data));
    [$code] = $run('v7_verify.php', $manifest);
    $assert($code === 1, 'Manifest yang menghilangkan audit_logs wajib ditolak');
} finally {
    @unlink($manifest);
}
echo "Hasil: $pass lulus, $fail gagal\n";
exit($fail === 0 ? 0 : 1);
