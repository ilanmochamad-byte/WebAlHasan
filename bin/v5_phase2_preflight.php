<?php

declare(strict_types=1);

/**
 * Preflight PRD V5 Fase 2 (migrasi 025, rincian kesehatan). Hanya membaca.
 *
 *   php bin/v5_phase2_preflight.php [manifest.json]
 *
 * Memeriksa bahwa migrasi 024 tercatat dan lima tabel induknya ada, tabel
 * yang dirujuk kunci asing 025 tersedia, lalu menulis manifest jumlah baris
 * seluruh tabel lama — termasuk lima tabel kesehatan 024 — selain empat tabel
 * baru 025, schema_migrations, dan audit_logs, untuk dibandingkan
 * `bin/v5_phase2_verify.php` sesudah migrasi atau rollback.
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
$baru = ['kesehatan_obat', 'kesehatan_rujukan', 'kesehatan_tindak_lanjut', 'kesehatan_versi_rincian'];
$nama = (string) $db->query('SELECT DATABASE() AS name')->fetch_assoc()['name'];
echo '[info] Basis data terpilih: ' . $nama . ' (preflight hanya membaca; migrasi produksi memerlukan otorisasi operator).' . PHP_EOL;

foreach (['users', 'santri', 'kesehatan_assignments', 'kesehatan_kunjungan', 'kesehatan_kunjungan_versi', 'kesehatan_idempotensi', 'kesehatan_akses_log',
    'pembimbing_assignments', 'murobi_assignments', 'plotting_kamar', 'plotting_kelas', 'schema_migrations'] as $tabel) {
    $s = $db->prepare('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->bind_param('s', $tabel);
    $s->execute();
    $periksa((int) $s->get_result()->fetch_assoc()['n'] === 1, 'Tabel ' . $tabel);
    $s->close();
}
$s = $db->prepare('SELECT COUNT(*) AS n FROM schema_migrations WHERE migration = ?');
$m = '024_v5_fase1_kesehatan.sql';
$s->bind_param('s', $m);
$s->execute();
$periksa((int) $s->get_result()->fetch_assoc()['n'] === 1, 'Migrasi 024 tercatat');
$s->close();
$unik = (int) $db->query("SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kesehatan_kunjungan_versi' AND INDEX_NAME = 'kesehatan_versi_unique'")->fetch_assoc()['n'];
$periksa($unik === 2, 'Kunci unik (kunjungan_id, versi) pada kesehatan_kunjungan_versi tersedia untuk kunci asing snapshot');

$sudah = 0;
foreach ($baru as $t) {
    $s = $db->prepare('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->bind_param('s', $t);
    $s->execute();
    $sudah += (int) $s->get_result()->fetch_assoc()['n'];
    $s->close();
}
echo '[info] Tabel 025 yang sudah ada: ' . $sudah . ($sudah === 0 ? ' (migrasi 025 belum dijalankan)' : ' (migrasi 025 idempoten; CREATE TABLE IF NOT EXISTS)') . PHP_EOL;

$lama = [];
$tabel = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
    AND TABLE_NAME NOT IN ('kesehatan_obat', 'kesehatan_rujukan', 'kesehatan_tindak_lanjut', 'kesehatan_versi_rincian', 'schema_migrations', 'audit_logs') ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC);
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
