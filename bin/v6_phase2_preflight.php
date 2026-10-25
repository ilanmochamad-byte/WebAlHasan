<?php

declare(strict_types=1);

/**
 * Preflight PRD V6 Fase 2 (migrasi 028, tagihan bulanan lengkap). Hanya membaca.
 *
 *   php bin/v6_phase2_preflight.php [manifest.json]
 *
 * Memeriksa versi server (CHECK dan kolom terbangkit STORED berindeks unik:
 * MariaDB 10.2.1+/MySQL 8.0.16+), binlog yang kompatibel dengan READ
 * COMMITTED, InnoDB, migrasi 027 sudah tercatat dan keenam tabelnya ada, lalu
 * menulis manifest jumlah baris SELURUH tabel lama (termasuk enam tabel 027;
 * selain sembilan tabel 028, schema_migrations, audit_logs) serta sidik jari
 * ID identitas, tagihan, dan pembayaran untuk dibandingkan
 * `bin/v6_phase2_verify.php` sesudah migrasi atau rollback.
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
$tabelAda = static function (string $t) use ($db): bool {
    $s = $db->prepare('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->bind_param('s', $t);
    $s->execute();
    $ada = (int) $s->get_result()->fetch_assoc()['n'] === 1;
    $s->close();

    return $ada;
};
$nama = (string) $db->query('SELECT DATABASE() AS name')->fetch_assoc()['name'];
echo '[info] Basis data terpilih: ' . $nama . ' (preflight hanya membaca; migrasi produksi memerlukan otorisasi operator).' . PHP_EOL;

$versi = (string) $db->query('SELECT VERSION() AS v')->fetch_assoc()['v'];
$maria = stripos($versi, 'mariadb') !== false;
preg_match('/^(\d+)\.(\d+)\.(\d+)/', $versi, $v);
$angka = isset($v[1]) ? [(int) $v[1], (int) $v[2], (int) $v[3]] : [0, 0, 0];
$periksa($maria ? $angka >= [10, 2, 1] : $angka >= [8, 0, 16], 'Server ' . $versi . ' menegakkan CHECK dan mendukung kolom terbangkit STORED berindeks unik (tarif berlaku tunggal per unit/periode)');
$binlog = $db->query('SELECT @@log_bin AS aktif, @@binlog_format AS format')->fetch_assoc();
$periksa(!((int) $binlog['aktif'] === 1 && strtoupper((string) $binlog['format']) === 'STATEMENT'),
    'Binary log ' . ((int) $binlog['aktif'] === 1 ? 'aktif, format ' . $binlog['format'] : 'tidak aktif') . ' — kompatibel dengan transaksi READ COMMITTED');
$innodb = (string) ($db->query("SELECT SUPPORT AS s FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'")->fetch_assoc()['s'] ?? '');
$periksa(in_array($innodb, ['YES', 'DEFAULT'], true), 'Mesin InnoDB tersedia (transaksi dan kunci baris)');

foreach (['users', 'pengurus', 'santri', 'kelas', 'plotting_kelas', 'bendahara_bulanan_assignments', 'audit_logs', 'schema_migrations',
    'bsa_tagihan', 'bsa_tagihan_komponen', 'bsa_tabungan_akun', 'bsa_tabungan_transaksi', 'bsa_pembayaran', 'bsa_idempotensi'] as $tabel) {
    $periksa($tabelAda($tabel), 'Tabel ' . $tabel);
}
$s = $db->prepare('SELECT COUNT(*) AS n FROM schema_migrations WHERE migration = ?');
$m = '027_v6_fase1_bsa.sql';
$s->bind_param('s', $m);
$s->execute();
$periksa((int) $s->get_result()->fetch_assoc()['n'] === 1, 'Migrasi 027 (fondasi BSA) tercatat');
$s->close();
$sudah = (int) $db->query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('" . implode("', '", \App\Bsa\TagihanRepository::TABEL) . "')")->fetch_assoc()['n'];
echo '[info] Tabel Fase 2 yang sudah ada: ' . $sudah . ($sudah === 0 ? ' (migrasi 028 belum dijalankan)' : ' (migrasi 028 idempoten; CREATE TABLE IF NOT EXISTS)') . PHP_EOL;
if ($tabelAda('bsa_pembayaran')) {
    echo '[info] Baris bsa_pembayaran (027) sebelum 028: ' . (int) $db->query('SELECT COUNT(*) AS n FROM bsa_pembayaran')->fetch_assoc()['n'] . ' (Fase 1 tidak menulis pembayaran; diharapkan 0).' . PHP_EOL;
}

$fase2 = \App\Bsa\TagihanRepository::TABEL;
$lama = [];
$tabel = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
    AND TABLE_NAME NOT IN ('schema_migrations', 'audit_logs', '" . implode("', '", $fase2) . "') ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC);
foreach ($tabel as $row) {
    $t = (string) $row['TABLE_NAME'];
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $t)) {
        exit(2);
    }
    $lama[$t] = (int) $db->query('SELECT COUNT(*) AS n FROM `' . $t . '`')->fetch_assoc()['n'];
}
$db->query('SET SESSION group_concat_max_len = 4294967295');
$sidik = [];
foreach (['santri', 'wali', 'santri_wali', 'users'] as $t) {
    $sidik[$t] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(id ORDER BY id SEPARATOR ','), ''), 256) AS h FROM `$t`")->fetch_assoc()['h'];
}
$sidik['santri_wali_relasi'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, santri_id, wali_id, COALESCE(archived_at, '-')) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM santri_wali")->fetch_assoc()['h'];
// Isi uang 027 (tagihan, komponen, ledger, saldo) tidak boleh berubah oleh 028.
$sidik['bsa_tagihan'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, santri_id, periode, total, terbayar, status, versi) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM bsa_tagihan")->fetch_assoc()['h'];
$sidik['bsa_tagihan_komponen'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, tagihan_id, urutan, nominal) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM bsa_tagihan_komponen")->fetch_assoc()['h'];
$sidik['bsa_tabungan'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, santri_id, jenis, nominal, saldo_sesudah, status) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM bsa_tabungan_transaksi")->fetch_assoc()['h'];
$sidik['bsa_saldo'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', santri_id, saldo) ORDER BY santri_id SEPARATOR ','), ''), 256) AS h FROM bsa_tabungan_akun")->fetch_assoc()['h'];
$manifest = json_encode(['database' => $nama, 'fase' => 'v6-fase2', 'legacy_counts' => $lama, 'id_fingerprints' => $sidik, 'captured_at' => date(DATE_ATOM)],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (isset($argv[1])) {
    file_put_contents($argv[1], $manifest . PHP_EOL);
    echo 'Manifest ditulis: ' . $argv[1] . PHP_EOL;
} else {
    echo $manifest . PHP_EOL;
}
echo "Backup DB terverifikasi dan manifest ini wajib disimpan sebelum migrasi.\n";
exit($gagal > 0 ? 1 : 0);
