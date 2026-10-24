<?php

declare(strict_types=1);

/**
 * Latihan backup → restore → verifikasi PRD V5 (Fase 5). HANYA DB uji.
 *
 *   V5_DRILL=1 DB_NAME=test_v5_f5_test php bin/v5_backup_restore_drill.php [--target=test_..._restore]
 *
 *   a. preflight V5 sumber → manifest privat (jumlah baris tabel lama +
 *      sidik jari santri/wali/relasi);
 *   b. backup seluruh DB (`BackupWriter`, jalur yang sama dengan preflight
 *      lama) + SHA-256 berkas;
 *   c. restore ke DB `_test` KEDUA lewat klien baris perintah
 *      (`mariadb`/`mysql`, prosedur cPanel), credential lewat berkas opsi 0600;
 *   d. verifier V5 (`bin/v5_verify.php`) dengan manifest pada DB pulihan;
 *   e. hash isi seluruh tabel kesehatan, santri, wali, relasi, outbox, dan
 *      schema_migrations sumber = pulihan; angka laporan seluruh waktu sama;
 *   f. rollback 026 pada DB pulihan berisi data DITOLAK (preflight dan penjaga
 *      SQL `bin/migrate.php rollback`); data dan catatan migrasi tetap.
 *
 * Penjaga: hanya CLI, `V5_DRILL=1`, sumber dan tujuan berakhiran `_test`/
 * `_test_restore`, menolak `APP_ENV=production`. Backup ditulis di
 * `storage/backups/v5-drill/` (di luar Git); isinya fixture sintetis.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/_v5_rilis.php';

use App\Database\BackupWriter;
use App\Kesehatan\LaporanRepository;

if (getenv('V5_DRILL') !== '1') {
    fwrite(STDERR, "Tolak: setel V5_DRILL=1 untuk menjalankan latihan backup/restore.\n");
    exit(2);
}
$sumber = (string) app_config('database.database');
if (!preg_match('/^test_[A-Za-z0-9_]*_test$/D', $sumber)) {
    fwrite(STDERR, "Tolak: DB_NAME (`{$sumber}`) wajib test_*_test.\n");
    exit(2);
}
if (strtolower((string) app_config('env')) === 'production') {
    fwrite(STDERR, "Tolak: APP_ENV=production. Latihan restore tidak pernah dijalankan pada produksi.\n");
    exit(2);
}
$target = $sumber . '_restore';
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--target=')) {
        $target = substr($a, 9);
    }
}
if (!preg_match('/^test_[A-Za-z0-9_]*_test(_restore)?$/D', $target) || $target === $sumber) {
    fwrite(STDERR, "Tolak: DB tujuan (`{$target}`) wajib test_*_test atau test_*_test_restore dan berbeda dari sumber.\n");
    exit(2);
}

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$php = static function (string $skrip, string $db, string $arg = '', array $env = []): array {
    $pref = 'DB_NAME=' . escapeshellarg($db);
    foreach ($env as $k => $v) {
        $pref .= ' ' . $k . '=' . escapeshellarg($v);
    }
    $out = [];
    $kode = 1;
    exec($pref . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/' . $skrip) . ($arg !== '' ? ' ' . $arg : '') . ' 2>&1', $out, $kode);

    return [$kode, $out];
};

echo "=== Latihan backup → restore → verifikasi V5 ===\nSumber : {$sumber}\nTujuan : {$target}\n\n";
$db = app_db();
$folder = APP_ROOT . '/storage/backups/v5-drill/' . date('Ymd_His');
if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
    fwrite(STDERR, "Folder backup tidak dapat dibuat.\n");
    exit(2);
}

echo "--- a. Preflight sumber ---\n";
[$kode] = $php('bin/v5_preflight.php', $sumber, escapeshellarg($folder . '/manifest.json'));
$assert($kode === 0 && is_file($folder . '/manifest.json'), 'Preflight V5 sumber lulus; manifest privat ditulis');
$manifest = json_decode((string) file_get_contents($folder . '/manifest.json'), true);
$assert(is_array($manifest) && $manifest['v5_terpasang'] === array_fill_keys(['024_v5_fase1_kesehatan.sql', '025_v5_fase2_rincian_kesehatan.sql', '026_v5_fase3_ringkasan_wali.sql'], true),
    'Sumber memuat migrasi 024–026');

echo "\n--- b. Backup ---\n";
$counts = (new BackupWriter($db))->write($folder . '/database.sql');
@chmod($folder . '/database.sql', 0600);
$hash = (string) hash_file('sha256', $folder . '/database.sql');
file_put_contents($folder . '/database.sql.sha256', $hash . '  database.sql' . PHP_EOL);
$assert(isset($counts['kesehatan_kunjungan'], $counts['kesehatan_ringkasan']) && $counts['kesehatan_kunjungan'] > 0,
    'Backup memuat ' . count($counts) . ' tabel termasuk tabel kesehatan berisi (' . $counts['kesehatan_kunjungan'] . ' kunjungan); SHA-256 ' . substr($hash, 0, 16) . '…');
$assert(hash_equals($hash, (string) hash_file('sha256', $folder . '/database.sql')), 'SHA-256 berkas backup stabil sebelum restore');

echo "\n--- c. Restore ke `{$target}` ---\n";
$config = app_config('database');
$admin = new mysqli((string) $config['host'], (string) $config['username'], (string) $config['password'], '', (int) $config['port']);
$admin->set_charset((string) $config['charset']);
$admin->query('DROP DATABASE IF EXISTS `' . $target . '`');
if ($admin->query('CREATE DATABASE `' . $target . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci') === false) {
    fwrite(STDERR, "DB tujuan tidak dapat dibuat.\n");
    exit(2);
}
$klien = null;
foreach (['mariadb', 'mysql'] as $k) {
    $c = [];
    exec('command -v ' . escapeshellarg($k) . ' 2>/dev/null', $c, $ck);
    if ($ck === 0 && ($c[0] ?? '') !== '') {
        $klien = $c[0];
        break;
    }
}
if ($klien === null) {
    fwrite(STDERR, "Klien mariadb/mysql tidak ditemukan.\n");
    exit(2);
}
$opsi = tempnam(sys_get_temp_dir(), 'v5drill');
chmod($opsi, 0600);
$kutip = static fn (string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
file_put_contents($opsi, "[client]\nprotocol=TCP\nhost=" . $kutip((string) $config['host']) . "\nport=" . (int) $config['port']
    . "\nuser=" . $kutip((string) $config['username']) . "\npassword=" . $kutip((string) $config['password']) . "\n");
$keluaran = [];
exec(escapeshellarg($klien) . ' --defaults-extra-file=' . escapeshellarg($opsi) . ' ' . escapeshellarg($target) . ' < ' . escapeshellarg($folder . '/database.sql') . ' 2>&1', $keluaran, $kodeRestore);
unlink($opsi);
$assert($kodeRestore === 0, 'Restore lewat klien ' . basename($klien) . ' tanpa galat' . ($kodeRestore === 0 ? '' : ': ' . implode(' | ', array_slice($keluaran, 0, 3))));
$pulih = new mysqli((string) $config['host'], (string) $config['username'], (string) $config['password'], $target, (int) $config['port']);
$pulih->set_charset((string) $config['charset']);
$beda = [];
foreach ($counts as $t => $n) {
    $r = $pulih->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', (string) $t) . '`');
    if (!$r || (int) $r->fetch_row()[0] !== (int) $n) {
        $beda[] = $t;
    }
}
$assert($beda === [], 'Jumlah baris seluruh ' . count($counts) . ' tabel pulihan = backup' . ($beda === [] ? '' : ' (berbeda: ' . implode(', ', $beda) . ')'));

echo "\n--- d. Verifier V5 pada DB pulihan ---\n";
[$kode, $out] = $php('bin/v5_verify.php', $target, escapeshellarg($folder . '/manifest.json'));
foreach (array_filter($out, static fn (string $l): bool => str_starts_with($l, '[gagal]') || str_starts_with($l, 'Ringkasan verifier')) as $l) {
    echo '    ' . $l . PHP_EOL;
}
$assert($kode === 0, 'bin/v5_verify.php + manifest pada DB pulihan lulus (termasuk sidik jari santri/wali/relasi)');

echo "\n--- e. Hash isi dan laporan sumber = pulihan ---\n";
$tabelCek = array_merge(array_values(array_filter(array_keys($counts), static fn (string $t): bool => str_starts_with($t, 'kesehatan_'))),
    ['santri', 'wali', 'santri_wali', 'notifikasi_outbox', 'schema_migrations', 'users', 'user_roles']);
// Hash isi (seluruh kolom, urut kolom pertama), bukan CHECKSUM TABLE: CHECKSUM
// berbeda untuk tabel dengan kolom ter-generate (santri_wali.active_guard)
// walaupun nilainya sama setelah restore.
$checksum = static function (mysqli $c, array $tabel): array {
    $hasil = [];
    foreach ($tabel as $t) {
        $ctx = hash_init('sha256');
        $r = $c->query('SELECT * FROM `' . $t . '` ORDER BY 1', MYSQLI_USE_RESULT);
        while ($row = $r->fetch_row()) {
            hash_update($ctx, implode("\x1f", array_map(static fn ($v): string => $v === null ? "\0" : (string) $v, $row)) . "\n");
        }
        $r->free();
        $hasil[$t] = hash_final($ctx);
    }

    return $hasil;
};
$csSumber = $checksum($db, $tabelCek);
$csPulih = $checksum($pulih, $tabelCek);
$assert($csSumber === $csPulih, 'Hash isi ' . count($tabelCek) . ' tabel (kesehatan, santri, wali, relasi, outbox, migrasi, akun) sumber = pulihan'
    . ($csSumber === $csPulih ? '' : ' (berbeda: ' . implode(', ', array_keys(array_diff_assoc($csSumber, $csPulih))) . ')'));
$angka = static function (mysqli $c): array {
    $lap = new LaporanRepository($c);
    $b = $lap->basis(['dari' => null, 'sampai' => null, 'kelas' => null], null);

    return [$lap->kunjungan($b), $lap->rincian($b), $lap->ringkasan($b)];
};
$assert($angka($db) === $angka($pulih), 'Angka laporan seluruh waktu sumber = pulihan');

echo "\n--- f. Rollback berisi data ditolak pada DB pulihan ---\n";
[$kode, $out] = $php('bin/v5_phase3_rollback_preflight.php', $target);
$assert($kode === 1 && in_array('Jangan rollback 026: ada ringkasan wali atau notifikasinya. Pulihkan backup terverifikasi atau buat migrasi korektif.', $out, true),
    'Preflight rollback 026 memblokir (ada ringkasan/notifikasi)');
[$kode, $out] = $php('bin/migrate.php', $target, 'rollback');
$assert($kode !== 0 && str_contains(implode(' ', $out), 'v5_fase3_rollback_guard_kosong'), 'migrate.php rollback tanpa preflight ditolak penjaga SQL 026');
$assert($checksum($pulih, $tabelCek) === $csPulih
    && (int) $pulih->query("SELECT COUNT(*) FROM schema_migrations WHERE migration = '026_v5_fase3_ringkasan_wali.sql'")->fetch_row()[0] === 1
    && (int) $pulih->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'v5\\_fase%guard'")->fetch_row()[0] === 0,
    'Sesudah penolakan: hash isi tetap, 026 tetap tercatat, tidak ada tabel penjaga tersisa');

$admin->query('DROP DATABASE IF EXISTS `' . $target . '`');
echo "\n[info] DB pulihan `{$target}` dihapus; backup sintetis tetap di " . str_replace(APP_ROOT . '/', '', $folder) . " (abaikan Git).\n";
echo "\nRingkasan latihan: {$lulus} lulus, " . count($gagal) . " gagal\n";
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
