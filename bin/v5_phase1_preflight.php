<?php

declare(strict_types=1);

/**
 * Preflight PRD V5 Fase 1 (migrasi 024, kesehatan santri). Hanya membaca.
 *
 *   php bin/v5_phase1_preflight.php [manifest.json]
 *
 * Memeriksa tabel induk yang dirujuk kunci asing migrasi 024, bahwa migrasi
 * 023 sudah tercatat, dan menulis manifest jumlah baris seluruh tabel lama
 * (selain tabel kesehatan, schema_migrations, audit_logs) untuk dibandingkan
 * `bin/v5_phase1_verify.php` sesudah migrasi atau rollback.
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

foreach (['users', 'user_roles', 'roles', 'pengurus', 'tahun_ajaran', 'santri', 'wali', 'santri_wali', 'audit_logs', 'schema_migrations'] as $tabel) {
    $s = $db->prepare('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->bind_param('s', $tabel);
    $s->execute();
    $periksa((int) $s->get_result()->fetch_assoc()['n'] === 1, 'Tabel ' . $tabel);
    $s->close();
}
$s = $db->prepare('SELECT COUNT(*) AS n FROM schema_migrations WHERE migration = ?');
$m = '023_redesain_web_tahap6_data_pesantren.sql';
$s->bind_param('s', $m);
$s->execute();
$periksa((int) $s->get_result()->fetch_assoc()['n'] === 1, 'Migrasi 023 tercatat');
$s->close();
$s = $db->prepare("SELECT COUNT(*) AS n FROM roles WHERE slug IN ('admin', 'pengurus')");
$s->execute();
$periksa((int) $s->get_result()->fetch_assoc()['n'] === 2, 'Role dasar admin dan pengurus tersedia (tanpa role baru)');
$s->close();

$sudah = (int) $db->query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'kesehatan\\_%'")->fetch_assoc()['n'];
echo '[info] Tabel kesehatan yang sudah ada: ' . $sudah . ($sudah === 0 ? ' (migrasi 024 belum dijalankan)' : ' (migrasi 024 idempoten; CREATE TABLE IF NOT EXISTS)') . PHP_EOL;

$lama = [];
$tabel = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
    AND TABLE_NAME NOT LIKE 'kesehatan\\_%' AND TABLE_NAME NOT IN ('schema_migrations', 'audit_logs') ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC);
foreach ($tabel as $row) {
    $t = (string) $row['TABLE_NAME'];
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $t)) {
        exit(2);
    }
    $lama[$t] = (int) $db->query('SELECT COUNT(*) AS n FROM `' . $t . '`')->fetch_assoc()['n'];
}
$manifest = json_encode(['database' => $nama, 'legacy_counts' => $lama, 'captured_at' => date(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (isset($argv[1])) {
    file_put_contents($argv[1], $manifest . PHP_EOL);
    echo 'Manifest ditulis: ' . $argv[1] . PHP_EOL;
} else {
    echo $manifest . PHP_EOL;
}
echo "Backup DB terverifikasi dan manifest ini wajib disimpan sebelum migrasi.\n";
exit($gagal > 0 ? 1 : 0);
