<?php

declare(strict_types=1);

// Uji perintah backup runbook, termasuk trigger/routine sintetis. Bukan produksi.
if (getenv('V5_RUN_OPERATOR_DRILL') !== '1' || !preg_match('/^test_.*_test$/D', getenv('DB_NAME') ?: '')) exit(2);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$source = (string) app_config('database.database');
$target = 'test_codex_v5_f5_operator_test';
$probeRoutine = getenv('V5_DRILL_ROUTINE') === '1';
if ($source === $target || app_config('env') === 'production') exit(2);
$db = app_db();
$config = app_config('database');
$dir = sys_get_temp_dir() . '/v5-operator-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$quote = static fn (string $s): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
file_put_contents($dir . '/client.cnf', "[client]\nprotocol=TCP\nhost=" . $quote($config['host']) . "\nport=" . $config['port']
    . "\nuser=" . $quote($config['username']) . "\npassword=" . $quote($config['password']) . "\n");
chmod($dir . '/client.cnf', 0600);
$run = static function (string $cmd): array {
    // Stderr harus tetap terpisah dari berkas SQL hasil redirect stdout.
    $out = []; exec('(' . $cmd . ') 2>&1', $out, $exit); return [$exit, implode("\n", $out)];
};
$pass = $fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$php = static fn (string $name, string $dbName, string $args = ''): string => 'DB_NAME=' . escapeshellarg($dbName) . ' ' . escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg(APP_ROOT . '/bin/' . $name) . ' ' . $args;
$hashes = static function (mysqli $conn): array {
    $tables = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetch_all(MYSQLI_NUM);
    $hashes = [];
    foreach ($tables as [$t]) {
        $rows = $conn->query('SELECT * FROM `' . str_replace('`', '``', $t) . '`')->fetch_all(MYSQLI_NUM);
        $rows = array_map('serialize', $rows); sort($rows);
        $hashes[$t] = hash('sha256', serialize($rows));
    }
    ksort($hashes); return $hashes;
};
try {
    $db->query('CREATE TRIGGER v5_f5_operator_probe AFTER INSERT ON kesehatan_akses_log FOR EACH ROW SET @v5_operator_probe = 1');
    if ($probeRoutine) $db->query('CREATE PROCEDURE v5_f5_operator_probe() SELECT 1');
    [$exit] = $run($php('v5_preflight.php', $source, escapeshellarg($dir . '/manifest.json')));
    $check($exit === 0, 'Preflight menghasilkan manifest sumber');
    $before = $hashes($db);
    [$exit] = $run('mariadb-dump --defaults-extra-file=' . escapeshellarg($dir . '/client.cnf')
        . ' --single-transaction --routines --triggers ' . escapeshellarg($source) . ' > ' . escapeshellarg($dir . '/backup.sql'));
    $check($exit === 0 && filesize($dir . '/backup.sql') > 0, 'Backup runbook mariadb-dump --single-transaction --routines --triggers berhasil');
    $sha = hash_file('sha256', $dir . '/backup.sql');
    $db->query('CREATE DATABASE `' . $target . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    [$exit, $restoreError] = $run('mariadb --defaults-extra-file=' . escapeshellarg($dir . '/client.cnf') . ' ' . escapeshellarg($target)
        . ' < ' . escapeshellarg($dir . '/backup.sql'));
    if ($exit !== 0) echo '[diagnostik] ' . $restoreError . PHP_EOL;
    $check($exit === 0 && hash_equals($sha, hash_file('sha256', $dir . '/backup.sql')), 'Restore klien mariadb berhasil; hash backup tetap');
    $restored = new mysqli($config['host'], $config['username'], $config['password'], $target, (int) $config['port']);
    $restored->set_charset($config['charset']);
    $check($hashes($restored) === $before && $hashes($db) === $before, 'Isi seluruh tabel V1–V5 pulihan = sumber dan sumber tidak berubah');
    $check((int) $restored->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='v5_f5_operator_probe'")->fetch_row()[0] === 1,
        'Trigger sintetis ikut pulih');
    if ($probeRoutine) {
        $check((int) $restored->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='v5_f5_operator_probe'")->fetch_row()[0] === 1,
            'Routine sintetis ikut pulih');
    }
    [$exit, $out] = $run($php('v5_verify.php', $target, escapeshellarg($dir . '/manifest.json')));
    $check($exit === 0, 'Verifier + manifest sumber lulus pada pulihan perintah runbook');
    [$exit] = $run($php('migrate.php', $target, 'rollback'));
    $check($exit !== 0 && $hashes($restored) === $before, 'Rollback DB pulihan berisi ditolak; isi seluruh tabel tetap');
} finally {
    $db->query('DROP TRIGGER IF EXISTS v5_f5_operator_probe');
    $db->query('DROP PROCEDURE IF EXISTS v5_f5_operator_probe');
    $db->query('DROP DATABASE IF EXISTS `' . $target . '`');
    foreach (glob($dir . '/*') ?: [] as $f) unlink($f);
    rmdir($dir);
}
echo "Ringkasan: $pass lulus, $fail gagal\n";
exit($fail ? 1 : 0);
