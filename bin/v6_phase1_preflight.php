<?php

declare(strict_types=1);

/**
 * Preflight PRD V6 Fase 1 (migrasi 027, fondasi BSA). Hanya membaca.
 *
 *   php bin/v6_phase1_preflight.php [manifest.json]
 *
 * Memeriksa versi server (CHECK ditegakkan: MariaDB 10.2+/MySQL 8.0.16+),
 * mesin InnoDB, tabel induk yang dirujuk kunci asing migrasi 027 (termasuk
 * `bendahara_bulanan_assignments` fondasi 012), bahwa migrasi 026 sudah
 * tercatat, dan menulis manifest jumlah baris SERTA sidik jari ID tabel
 * identitas (santri, wali, santri_wali, users) dan seluruh tabel lama
 * (selain tabel bsa_*, schema_migrations, audit_logs) untuk dibandingkan
 * `bin/v6_phase1_verify.php` sesudah migrasi atau rollback.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = app_db();
$gagal = 0;
$periksa = static function (bool $ok, string $label) use (&$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    if (!$ok) {
        $gagal++;
    }
};
$nama = (string) $db->query('SELECT DATABASE() AS name')->fetch_assoc()['name'];
echo '[info] Basis data terpilih: ' . $nama . ' (preflight hanya membaca; migrasi produksi memerlukan otorisasi operator).' . PHP_EOL;

$versi = (string) $db->query('SELECT VERSION() AS v')->fetch_assoc()['v'];
$maria = stripos($versi, 'mariadb') !== false;
preg_match('/^(\d+)\.(\d+)\.(\d+)/', $versi, $v);
$angka = isset($v[1]) ? [(int) $v[1], (int) $v[2], (int) $v[3]] : [0, 0, 0];
$checkDitegakkan = $maria ? $angka >= [10, 2, 1] : $angka >= [8, 0, 16];
$periksa($checkDitegakkan, 'Server ' . $versi . ' menegakkan CHECK (saldo tidak negatif dan penjaga rollback bergantung padanya)');
// Transaksi mutasi BSA memakai READ COMMITTED (`BsaRepository::transaction()`);
// bila binary log aktif dengan format STATEMENT, InnoDB menolak menulis pada
// isolasi itu. ROW/MIXED (bawaan MySQL 5.7.7+/MariaDB) atau binlog mati aman.
$binlog = $db->query('SELECT @@log_bin AS aktif, @@binlog_format AS format')->fetch_assoc();
$periksa(!((int) $binlog['aktif'] === 1 && strtoupper((string) $binlog['format']) === 'STATEMENT'),
    'Binary log ' . ((int) $binlog['aktif'] === 1 ? 'aktif, format ' . $binlog['format'] : 'tidak aktif') . ' — kompatibel dengan transaksi READ COMMITTED');
$innodb = (string) ($db->query("SELECT SUPPORT AS s FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'")->fetch_assoc()['s'] ?? '');
$periksa(in_array($innodb, ['YES', 'DEFAULT'], true), 'Mesin InnoDB tersedia (transaksi dan kunci baris)');

foreach (['users', 'user_roles', 'roles', 'pengurus', 'tahun_ajaran', 'santri', 'wali', 'santri_wali', 'kelas', 'plotting_kelas',
    'bendahara_bulanan_assignments', 'audit_logs', 'schema_migrations'] as $tabel) {
    $s = $db->prepare('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->bind_param('s', $tabel);
    $s->execute();
    $periksa((int) $s->get_result()->fetch_assoc()['n'] === 1, 'Tabel ' . $tabel);
    $s->close();
}
$s = $db->prepare('SELECT COUNT(*) AS n FROM schema_migrations WHERE migration = ?');
$m = '026_v5_fase3_ringkasan_wali.sql';
$s->bind_param('s', $m);
$s->execute();
$periksa((int) $s->get_result()->fetch_assoc()['n'] === 1, 'Migrasi 026 tercatat');
$s->close();
$s = $db->prepare("SELECT COUNT(*) AS n FROM roles WHERE slug IN ('admin', 'pengurus', 'orang_tua')");
$s->execute();
$periksa((int) $s->get_result()->fetch_assoc()['n'] === 3, 'Role dasar admin, pengurus, dan orang tua tersedia (tanpa role baru)');
$s->close();

$sudah = (int) $db->query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'bsa\\_%'")->fetch_assoc()['n'];
echo '[info] Tabel BSA yang sudah ada: ' . $sudah . ($sudah === 0 ? ' (migrasi 027 belum dijalankan)' : ' (migrasi 027 idempoten; CREATE TABLE IF NOT EXISTS)') . PHP_EOL;
$penugasan = (int) $db->query('SELECT COUNT(*) AS n FROM bendahara_bulanan_assignments WHERE is_active = 1 AND (tanggal_selesai IS NULL OR tanggal_selesai >= CURDATE())')->fetch_assoc()['n'];
echo '[info] Penugasan bendahara bulanan lama yang masih aktif/berlaku: ' . $penugasan
    . ($penugasan === 0 ? '' : ' — sesudah rilis kode V6 baris ini menjadi Petugas BSA aktif (tidak lagi gugur per semester). Tinjau di Pusat Penugasan sebelum rilis.') . PHP_EOL;

$lama = [];
$tabel = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
    AND TABLE_NAME NOT LIKE 'bsa\\_%' AND TABLE_NAME NOT IN ('schema_migrations', 'audit_logs') ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC);
foreach ($tabel as $row) {
    $t = (string) $row['TABLE_NAME'];
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $t)) {
        exit(2);
    }
    $lama[$t] = (int) $db->query('SELECT COUNT(*) AS n FROM `' . $t . '`')->fetch_assoc()['n'];
}
// Sidik jari ID identitas: jumlah sama saja tidak cukup membuktikan ID terjaga.
$db->query('SET SESSION group_concat_max_len = 4294967295');
$sidik = [];
foreach (['santri' => 'id', 'wali' => 'id', 'santri_wali' => 'id', 'users' => 'id'] as $t => $kolom) {
    $sidik[$t] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(`$kolom` ORDER BY `$kolom` SEPARATOR ','), ''), 256) AS h FROM `$t`")->fetch_assoc()['h'];
}
$sidik['santri_wali_relasi'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, santri_id, wali_id, COALESCE(archived_at, '-')) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM santri_wali")->fetch_assoc()['h'];
$manifest = json_encode(['database' => $nama, 'legacy_counts' => $lama, 'id_fingerprints' => $sidik, 'captured_at' => date(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (isset($argv[1])) {
    file_put_contents($argv[1], $manifest . PHP_EOL);
    echo 'Manifest ditulis: ' . $argv[1] . PHP_EOL;
} else {
    echo $manifest . PHP_EOL;
}
echo "Backup DB terverifikasi dan manifest ini wajib disimpan sebelum migrasi.\n";
exit($gagal > 0 ? 1 : 0);
