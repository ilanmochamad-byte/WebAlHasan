<?php

declare(strict_types=1);

/**
 * Latihan backup → restore → verifikasi penugasan lintas guru/pengurus dan
 * Keamanan (Fase 5). HANYA DB uji.
 *
 *   PK_DRILL=1 DB_NAME=test_pk_f5_test php bin/penugasan_keamanan_backup_restore_drill.php [--metode=writer|dump] [--target=test_..._restore]
 *
 *   a. preflight sumber → manifest privat (jumlah baris dan isi seluruh tabel
 *      lama, irisan baca, identitas guru dan keamanan_assignments);
 *   b. backup: `writer` = `BackupWriter` aplikasi; `dump` = `mariadb-dump`/
 *      `mysqldump --single-transaction --routines --triggers` (jalur runbook
 *      cPanel); SHA-256 berkas;
 *   c. restore ke DB `_test` KEDUA lewat klien baris perintah, credential lewat
 *      berkas opsi 0600;
 *   d. `bin/penugasan_keamanan_verify.php` + manifest pada DB pulihan;
 *   e. hash isi tabel identitas, akun/role, seluruh penugasan, izin, pembinaan
 *      V3, kehadiran, audit, dan schema_migrations sumber = pulihan; himpunan
 *      akun ber-`keamanan.pantau` sama;
 *   f. rollback 033 pada DB pulihan berisi data DITOLAK (preflight dan penjaga
 *      SQL `bin/migrate.php rollback`); data dan catatan migrasi tetap.
 *
 * Penjaga: hanya CLI, `PK_DRILL=1`, sumber `test_*_test` dan tujuan
 * `test_*_test[_restore]` berbeda, menolak `APP_ENV=production`. Backup ditulis
 * di `storage/backups/pk-drill/` (di luar Git); isinya fixture sintetis.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/_penugasan_keamanan_rilis.php';

use App\Database\BackupWriter;
use App\Penugasan\PenugasanJenis;

if (getenv('PK_DRILL') !== '1') {
    fwrite(STDERR, "Tolak: setel PK_DRILL=1 untuk menjalankan latihan backup/restore.\n");
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
$metode = 'writer';
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--target=')) {
        $target = substr($a, 9);
    } elseif (str_starts_with($a, '--metode=')) {
        $metode = substr($a, 9);
    }
}
if (!preg_match('/^test_[A-Za-z0-9_]*_test(_restore)?$/D', $target) || $target === $sumber || !in_array($metode, ['writer', 'dump'], true)) {
    fwrite(STDERR, "Tolak: DB tujuan wajib test_*_test[_restore] berbeda dari sumber; metode writer atau dump.\n");
    exit(2);
}

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$php = static function (string $skrip, string $db, string $arg = ''): array {
    $out = [];
    $kode = 1;
    exec('DB_NAME=' . escapeshellarg($db) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/' . $skrip) . ($arg !== '' ? ' ' . $arg : '') . ' 2>&1', $out, $kode);

    return [$kode, $out];
};
$klienDb = static function (array $nama): ?string {
    foreach ($nama as $k) {
        $c = [];
        exec('command -v ' . escapeshellarg($k) . ' 2>/dev/null', $c, $ck);
        if ($ck === 0 && ($c[0] ?? '') !== '') {
            return $c[0];
        }
    }

    return null;
};
$config = app_config('database');
$opsi = tempnam(sys_get_temp_dir(), 'pkdrill');
chmod($opsi, 0600);
$kutip = static fn (string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
file_put_contents($opsi, "[client]\nprotocol=TCP\nhost=" . $kutip((string) $config['host']) . "\nport=" . (int) $config['port']
    . "\nuser=" . $kutip((string) $config['username']) . "\npassword=" . $kutip((string) $config['password']) . "\n");
register_shutdown_function(static fn () => @unlink($opsi));

echo "=== Latihan backup → restore → verifikasi penugasan Keamanan ===\nSumber : {$sumber}\nTujuan : {$target}\nMetode : {$metode}\n\n";
$db = app_db();
$folder = APP_ROOT . '/storage/backups/pk-drill/' . date('Ymd_His') . '-' . $metode;
if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
    fwrite(STDERR, "Folder backup tidak dapat dibuat.\n");
    exit(2);
}

echo "--- a. Preflight sumber ---\n";
[$kode] = $php('bin/penugasan_keamanan_preflight.php', $sumber, escapeshellarg($folder . '/manifest.json'));
$assert($kode === 0 && is_file($folder . '/manifest.json'), 'Preflight sumber lulus; manifest privat ditulis');
$manifest = json_decode((string) file_get_contents($folder . '/manifest.json'), true);
$assert(is_array($manifest) && $manifest['pk_terpasang'] === array_fill_keys(PK_MIGRASI, true) && ($manifest['isi_pk']['identitas_guru']['baris'] ?? 0) > 0
    && ($manifest['isi_pk'][PK_TABEL]['baris'] ?? 0) > 0, 'Sumber memuat migrasi 032–033, identitas guru, dan penugasan Keamanan');

echo "\n--- b. Backup ({$metode}) ---\n";
$berkas = $folder . '/database.sql';
if ($metode === 'writer') {
    $counts = (new BackupWriter($db))->write($berkas);
} else {
    $dump = $klienDb(['mariadb-dump', 'mysqldump']);
    if ($dump === null) {
        fwrite(STDERR, "mariadb-dump/mysqldump tidak ditemukan.\n");
        exit(2);
    }
    $err = $folder . '/dump.err';
    exec(escapeshellarg($dump) . ' --defaults-extra-file=' . escapeshellarg($opsi) . ' --single-transaction --routines --triggers --hex-blob ' . escapeshellarg($sumber)
        . ' > ' . escapeshellarg($berkas) . ' 2> ' . escapeshellarg($err), $o, $kodeDump);
    $assert($kodeDump === 0, basename($dump) . ' --single-transaction --routines --triggers tanpa galat');
    $counts = [];
    foreach (v7_semua_tabel($db) as $t) {
        $counts[$t] = (int) v7f1_nilai($db, 'SELECT COUNT(*) FROM `' . $t . '`');
    }
}
@chmod($berkas, 0600);
$hash = (string) hash_file('sha256', $berkas);
file_put_contents($berkas . '.sha256', $hash . '  database.sql' . PHP_EOL);
$keamananAudit = (int) v7f1_nilai($db, "SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'keamanan.%'");
$assert(($counts[PK_TABEL] ?? 0) > 0 && ($counts['pengurus'] ?? 0) > 0 && $keamananAudit > 0,
    'Backup memuat ' . count($counts) . ' tabel termasuk ' . ($counts[PK_TABEL] ?? 0) . ' penugasan Keamanan, ' . ($counts['pengurus'] ?? 0) . ' pengurus, '
    . $keamananAudit . ' jejak keamanan.*; SHA-256 ' . substr($hash, 0, 16) . '…');

echo "\n--- c. Restore ke `{$target}` ---\n";
$admin = new mysqli((string) $config['host'], (string) $config['username'], (string) $config['password'], '', (int) $config['port']);
$admin->set_charset((string) $config['charset']);
$admin->query('DROP DATABASE IF EXISTS `' . $target . '`');
if ($admin->query('CREATE DATABASE `' . $target . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci') === false) {
    fwrite(STDERR, "DB tujuan tidak dapat dibuat.\n");
    exit(2);
}
$klien = $klienDb(['mariadb', 'mysql']);
if ($klien === null) {
    fwrite(STDERR, "Klien mariadb/mysql tidak ditemukan.\n");
    exit(2);
}
$keluaran = [];
exec(escapeshellarg($klien) . ' --defaults-extra-file=' . escapeshellarg($opsi) . ' ' . escapeshellarg($target) . ' < ' . escapeshellarg($berkas) . ' 2>&1', $keluaran, $kodeRestore);
$assert($kodeRestore === 0, 'Restore lewat klien ' . basename($klien) . ' tanpa galat' . ($kodeRestore === 0 ? '' : ': ' . implode(' | ', array_slice($keluaran, 0, 3))));
$pulih = new mysqli((string) $config['host'], (string) $config['username'], (string) $config['password'], $target, (int) $config['port']);
$pulih->set_charset((string) $config['charset']);
$beda = [];
foreach ($counts as $t => $c) {
    $r = $pulih->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', (string) $t) . '`');
    if (!$r || (int) $r->fetch_row()[0] !== (int) $c) {
        $beda[] = $t;
    }
}
$assert($beda === [], 'Jumlah baris seluruh ' . count($counts) . ' tabel pulihan = backup' . ($beda === [] ? '' : ' (berbeda: ' . implode(', ', $beda) . ')'));

echo "\n--- d. Verifier pada DB pulihan ---\n";
[$kode, $out] = $php('bin/penugasan_keamanan_verify.php', $target, escapeshellarg($folder . '/manifest.json'));
foreach (array_filter($out, static fn (string $l): bool => str_starts_with($l, '[gagal]') || str_starts_with($l, 'Ringkasan verifier') || str_starts_with($l, '    [gagal]')) as $l) {
    echo '    ' . $l . PHP_EOL;
}
$assert($kode === 0, 'bin/penugasan_keamanan_verify.php + manifest pada DB pulihan lulus (fondasi, skema, identitas, Keamanan, pemisahan tugas, isi tabel lama/irisan/032–033)');

echo "\n--- e. Hash isi dan hak sumber = pulihan ---\n";
$tabelCek = ['pengurus', 'guru', 'users', 'user_roles', 'roles', 'izin_pengajuan', 'izin_keputusan', 'izin_riwayat_status', 'izin_pembimbing_tinjauan',
    'v3_pelanggaran', 'v3_konseling_kasus', 'v3_lampiran', 'pertemuan_pengajian', 'pertemuan_peserta', 'audit_logs', 'schema_migrations'];
foreach (PenugasanJenis::semua() as $definisi) {
    $tabelCek[] = $definisi['tabel'];
}
$tabelCek = array_values(array_unique($tabelCek));
$hashIsi = static function (mysqli $c, array $tabel): array {
    $hasil = [];
    foreach ($tabel as $t) {
        $hasil[$t] = v7_hash_kueri($c, 'SELECT * FROM `' . $t . '`');
    }

    return $hasil;
};
$hSumber = $hashIsi($db, $tabelCek);
$hPulih = $hashIsi($pulih, $tabelCek);
$assert($hSumber === $hPulih, 'Hash isi ' . count($tabelCek) . ' tabel (identitas, akun/role, seluruh penugasan, izin, pembinaan V3, kehadiran, audit, migrasi) sumber = pulihan'
    . ($hSumber === $hPulih ? '' : ' (berbeda: ' . implode(', ', array_keys(array_diff_key($hSumber, array_intersect_assoc($hSumber, $hPulih)))) . ')'));
$akunSumber = pk_akun_keamanan_sql($db);
$assert($akunSumber !== [] && $akunSumber === pk_akun_keamanan_sql($pulih), 'Himpunan akun ber-keamanan.pantau sumber = pulihan (' . count($akunSumber) . ' akun)');

echo "\n--- f. Rollback berisi data ditolak pada DB pulihan ---\n";
[$kode, $out] = $php('bin/penugasan_keamanan_rollback_preflight.php', $target);
$assert($kode === 1 && in_array('Rollback 033 DITOLAK. Riwayat penugasan Keamanan tidak boleh hilang; akhiri penugasan lewat halaman Penugasan, pulihkan dari backup, atau buat migrasi korektif.', $out, true),
    'Preflight rollback 033 memblokir (ada penugasan Keamanan)');
[$kode, $out] = $php('bin/migrate.php', $target, 'rollback');
$assert($kode !== 0 && str_contains(implode(' ', $out), 'penugasan_fase3_rollback_guard_kosong'), 'migrate.php rollback tanpa preflight ditolak penjaga SQL 033');
$assert($hashIsi($pulih, $tabelCek) === $hPulih
    && (int) $pulih->query("SELECT COUNT(*) FROM schema_migrations WHERE migration = '" . PK_MIGRASI[1] . "'")->fetch_row()[0] === 1
    && (int) $pulih->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'penugasan\\_fase%guard'")->fetch_row()[0] === 0,
    'Sesudah penolakan: hash isi tetap, 033 tetap tercatat, tidak ada tabel penjaga tersisa');

$admin->query('DROP DATABASE IF EXISTS `' . $target . '`');
echo "\n[info] DB pulihan `{$target}` dihapus; backup sintetis tetap di " . str_replace(APP_ROOT . '/', '', $folder) . " (diabaikan Git).\n";
echo "\nRingkasan latihan: {$lulus} lulus, " . count($gagal) . " gagal\n";
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
