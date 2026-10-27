<?php

declare(strict_types=1);

/**
 * Preflight PRD V7 Fase 2 (migrasi 031, tinjauan pembimbing dan perbaikan
 * wali). Hanya membaca.
 *
 *   php bin/v7_phase2_preflight.php [manifest.json]
 *
 * Memeriksa CHECK ditegakkan (sesi dan default global MariaDB), InnoDB,
 * binlog yang cocok untuk transaksi READ COMMITTED, migrasi 030 tercatat dan
 * skemanya lengkap, tabel induk serta tipe kolom kunci asing 031 (guru.id
 * INT, lainnya BIGINT UNSIGNED), lalu menulis manifest jumlah baris dan sidik
 * jari ID/isi untuk `bin/v7_phase2_verify.php`.
 */

require_once __DIR__ . '/_v7_phase2.php';

$db = app_db();
$gagal = 0;
$periksa = static function (bool $ok, string $label) use (&$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    if (!$ok) {
        $gagal++;
    }
};
$nama = (string) v7f1_nilai($db, 'SELECT DATABASE()');
echo '[info] Basis data terpilih: ' . $nama . ' (preflight hanya membaca; migrasi produksi memerlukan otorisasi operator).' . PHP_EOL;

$versi = (string) v7f1_nilai($db, 'SELECT VERSION()');
$maria = stripos($versi, 'mariadb') !== false;
preg_match('/^(\d+)\.(\d+)\.(\d+)/', $versi, $v);
$angka = isset($v[1]) ? [(int) $v[1], (int) $v[2], (int) $v[3]] : [0, 0, 0];
$periksa($maria ? $angka >= [10, 2, 1] : $angka >= [8, 0, 16], 'Server ' . $versi . ' menegakkan CHECK (pasangan tindakan/status dan penjaga rollback bergantung padanya)');
$periksa(v7f1_check_aktif($db), 'CHECK aktif pada sesi dan default global (MariaDB)');
$binlog = $db->query('SELECT @@log_bin AS aktif, @@binlog_format AS format')->fetch_assoc();
$periksa(!((int) $binlog['aktif'] === 1 && strtoupper((string) $binlog['format']) === 'STATEMENT'),
    'Binary log ' . ((int) $binlog['aktif'] === 1 ? 'aktif, format ' . $binlog['format'] : 'tidak aktif') . ' — kompatibel dengan transaksi READ COMMITTED');
$periksa(in_array((string) v7f1_nilai($db, "SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'"), ['YES', 'DEFAULT'], true), 'Mesin InnoDB tersedia');

$periksa((int) v7f1_nilai($db, "SELECT COUNT(*) FROM schema_migrations WHERE migration = '030_v7_fase1_pengajuan_wali.sql'") === 1, 'Migrasi 030 tercatat');
$periksa(v7f1_ada_kolom($db, 'izin_pengajuan', 'asal_pengajuan') && v7f1_ada_tabel($db, 'izin_pembimbing_penetapan'), 'Skema 030 lengkap (kolom asal dan tabel penetapan)');
$tipe = static fn (string $tabel, string $kolom): string => strtolower((string) v7f1_nilai($db,
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tabel' AND COLUMN_NAME = '$kolom'"));
foreach (['izin_pengajuan', 'pengurus', 'pembimbing_assignments', 'users', 'wali'] as $tabel) {
    $periksa(str_starts_with($tipe($tabel, 'id'), 'bigint') && str_contains($tipe($tabel, 'id'), 'unsigned'), 'Kolom ' . $tabel . '.id BIGINT UNSIGNED (induk kunci asing 031)');
}
$periksa(preg_match('/^int(\(\d+\))?$/', $tipe('guru', 'id')) === 1, 'Kolom guru.id INT bertanda (induk kunci asing murobi 031: ' . $tipe('guru', 'id') . ')');
$terpasang = v7f1_ada_tabel($db, 'izin_pembimbing_tinjauan');
echo '[info] Migrasi 031 ' . ($terpasang ? 'sudah terpasang (idempoten; CREATE TABLE IF NOT EXISTS)' : 'belum dijalankan') . PHP_EOL;
echo '[info] Pengajuan wali per status: ' . json_encode($db->query("SELECT status, COUNT(*) AS n FROM izin_pengajuan WHERE asal_pengajuan = 'Wali' GROUP BY status")->fetch_all(MYSQLI_ASSOC)) . PHP_EOL;

$manifest = json_encode(['database' => $nama, 'captured_at' => date(DATE_ATOM)] + v7f2_snapshot($db), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (isset($argv[1])) {
    file_put_contents($argv[1], $manifest . PHP_EOL);
    echo 'Manifest ditulis: ' . $argv[1] . PHP_EOL;
} else {
    echo $manifest . PHP_EOL;
}
echo "Backup DB terverifikasi dan manifest ini wajib disimpan sebelum migrasi.\n";
exit($gagal > 0 ? 1 : 0);
