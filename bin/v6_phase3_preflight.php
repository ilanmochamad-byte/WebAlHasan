<?php

declare(strict_types=1);

/**
 * Preflight PRD V6 Fase 3 (migrasi 029, tabungan lengkap dan pembayaran
 * silang). Hanya membaca.
 *
 *   php bin/v6_phase3_preflight.php [manifest.json]
 *
 * Memeriksa versi server (CHECK yang ditegakkan: MariaDB 10.2.1+/MySQL
 * 8.0.16+), binlog yang kompatibel dengan READ COMMITTED, InnoDB, migrasi 027
 * dan 028 sudah tercatat dan tabelnya ada, lalu menulis manifest jumlah baris
 * SELURUH tabel lama (termasuk tabel 027/028; selain tabel 029,
 * schema_migrations, audit_logs) serta sidik jari ID identitas dan isi uang
 * 027/028 untuk dibandingkan `bin/v6_phase3_verify.php` sesudah migrasi atau
 * rollback.
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
$periksa($maria ? $angka >= [10, 2, 1] : $angka >= [8, 0, 16], 'Server ' . $versi . ' menegakkan CHECK (saldo tidak negatif, status setoran transfer) dan kolom terbangkit 028');
$binlog = $db->query('SELECT @@log_bin AS aktif, @@binlog_format AS format')->fetch_assoc();
$periksa(!((int) $binlog['aktif'] === 1 && strtoupper((string) $binlog['format']) === 'STATEMENT'),
    'Binary log ' . ((int) $binlog['aktif'] === 1 ? 'aktif, format ' . $binlog['format'] : 'tidak aktif') . ' — kompatibel dengan transaksi READ COMMITTED');
$innodb = (string) ($db->query("SELECT SUPPORT AS s FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'")->fetch_assoc()['s'] ?? '');
$periksa(in_array($innodb, ['YES', 'DEFAULT'], true), 'Mesin InnoDB tersedia (transaksi dan kunci baris)');

foreach (['users', 'pengurus', 'santri', 'kelas', 'plotting_kelas', 'bendahara_bulanan_assignments', 'audit_logs', 'schema_migrations',
    ...\App\Bsa\BsaRepository::TABEL, ...\App\Bsa\TagihanRepository::TABEL] as $tabel) {
    $periksa($tabelAda($tabel), 'Tabel ' . $tabel);
}
foreach (['027_v6_fase1_bsa.sql' => 'fondasi BSA', '028_v6_fase2_tagihan_bulanan.sql' => 'tagihan bulanan lengkap'] as $m => $label) {
    $s = $db->prepare('SELECT COUNT(*) AS n FROM schema_migrations WHERE migration = ?');
    $s->bind_param('s', $m);
    $s->execute();
    $periksa((int) $s->get_result()->fetch_assoc()['n'] === 1, 'Migrasi ' . substr($m, 0, 3) . ' (' . $label . ') tercatat');
    $s->close();
}
$sudah = $tabelAda('bsa_setoran_transfer');
echo '[info] Tabel Fase 3 sudah ada: ' . ($sudah ? 'ya (migrasi 029 idempoten; CREATE TABLE IF NOT EXISTS)' : 'tidak (migrasi 029 belum dijalankan)') . PHP_EOL;
if ($tabelAda('bsa_tabungan_transaksi')) {
    $f3 = (int) $db->query("SELECT COUNT(*) AS n FROM bsa_tabungan_transaksi WHERE jenis IN ('Belanja', 'Pembalik', 'PembayaranTagihan') OR metode = 'Internal'")->fetch_assoc()['n']
        + (int) $db->query("SELECT COUNT(*) AS n FROM bsa_pembayaran WHERE sumber = 'Tabungan'")->fetch_assoc()['n'];
    echo '[info] Baris berjenis Fase 3 di tabel 027 sebelum 029: ' . $f3 . ' (kode Fase 1–2 tidak menulisnya; diharapkan 0).' . PHP_EOL;
}

$fase2 = \App\Bsa\TabunganRepository::TABEL;
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
// Isi uang 027/028 (tagihan, komponen, ledger, saldo, pembayaran) tidak boleh berubah oleh 029.
$sidik['bsa_tagihan'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, santri_id, periode, total, terbayar, status, versi) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM bsa_tagihan")->fetch_assoc()['h'];
$sidik['bsa_tagihan_komponen'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, tagihan_id, urutan, nominal) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM bsa_tagihan_komponen")->fetch_assoc()['h'];
$sidik['bsa_tabungan'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, santri_id, jenis, nominal, saldo_sesudah, status) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM bsa_tabungan_transaksi")->fetch_assoc()['h'];
$sidik['bsa_saldo'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', santri_id, saldo) ORDER BY santri_id SEPARATOR ','), ''), 256) AS h FROM bsa_tabungan_akun")->fetch_assoc()['h'];
$sidik['bsa_pembayaran'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, tagihan_id, nominal, sumber, status, nomor_kuitansi, COALESCE(tabungan_transaksi_id, '-')) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM bsa_pembayaran")->fetch_assoc()['h'];
$manifest = json_encode(['database' => $nama, 'fase' => 'v6-fase3', 'legacy_counts' => $lama, 'id_fingerprints' => $sidik, 'captured_at' => date(DATE_ATOM)],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (isset($argv[1])) {
    file_put_contents($argv[1], $manifest . PHP_EOL);
    echo 'Manifest ditulis: ' . $argv[1] . PHP_EOL;
} else {
    echo $manifest . PHP_EOL;
}
echo "Backup DB terverifikasi dan manifest ini wajib disimpan sebelum migrasi.\n";
exit($gagal > 0 ? 1 : 0);
