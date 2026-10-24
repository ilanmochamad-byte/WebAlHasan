<?php

declare(strict_types=1);

/**
 * Preflight PRD V5 Fase 3 (migrasi 026, ringkasan wali). Hanya membaca.
 *
 *   php bin/v5_phase3_preflight.php [manifest.json]
 *
 * Memeriksa bahwa migrasi 024 dan 025 tercatat, tabel yang dirujuk kunci
 * asing 026 serta tabel notifikasi V2 tersedia, lalu menulis manifest jumlah
 * baris seluruh tabel lama — termasuk sembilan tabel kesehatan 024–025 dan
 * `notifikasi_outbox` — selain empat tabel baru 026, schema_migrations, dan
 * audit_logs, untuk dibandingkan `bin/v5_phase3_verify.php` sesudah migrasi
 * atau rollback.
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
$baru = ['kesehatan_ringkasan', 'kesehatan_ringkasan_versi', 'kesehatan_ringkasan_peristiwa', 'kesehatan_ringkasan_penerima'];
$nama = (string) $db->query('SELECT DATABASE() AS name')->fetch_assoc()['name'];
echo '[info] Basis data terpilih: ' . $nama . ' (preflight hanya membaca; migrasi produksi memerlukan otorisasi operator).' . PHP_EOL;

foreach (['users', 'santri', 'wali', 'santri_wali', 'user_roles', 'roles', 'kesehatan_kunjungan', 'kesehatan_kunjungan_versi', 'kesehatan_idempotensi',
    'kesehatan_akses_log', 'kesehatan_versi_rincian', 'notifikasi_outbox', 'perangkat_push', 'pengaturan_notifikasi', 'schema_migrations'] as $tabel) {
    $s = $db->prepare('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->bind_param('s', $tabel);
    $s->execute();
    $periksa((int) $s->get_result()->fetch_assoc()['n'] === 1, 'Tabel ' . $tabel);
    $s->close();
}
foreach (['024_v5_fase1_kesehatan.sql', '025_v5_fase2_rincian_kesehatan.sql'] as $m) {
    $s = $db->prepare('SELECT COUNT(*) AS n FROM schema_migrations WHERE migration = ?');
    $s->bind_param('s', $m);
    $s->execute();
    $periksa((int) $s->get_result()->fetch_assoc()['n'] === 1, 'Migrasi ' . substr($m, 0, 3) . ' tercatat');
    $s->close();
}
$unik = (int) $db->query("SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND NON_UNIQUE = 0
    AND INDEX_NAME <> 'PRIMARY' AND COLUMN_NAME IN ('event_key', 'kanal', 'penerima_user_id')")->fetch_assoc()['n'];
$periksa($unik === 3, 'Kunci unik (event_key, kanal, penerima) notifikasi_outbox tersedia untuk deduplikasi');
$v5 = (int) $db->query("SELECT COUNT(*) AS n FROM notifikasi_outbox WHERE event_type LIKE 'v5\\_%'")->fetch_assoc()['n'];
echo '[info] Baris outbox V5 yang sudah ada: ' . $v5 . PHP_EOL;

$sudah = 0;
foreach ($baru as $t) {
    $s = $db->prepare('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->bind_param('s', $t);
    $s->execute();
    $sudah += (int) $s->get_result()->fetch_assoc()['n'];
    $s->close();
}
echo '[info] Tabel 026 yang sudah ada: ' . $sudah . ($sudah === 0 ? ' (migrasi 026 belum dijalankan)' : ' (migrasi 026 idempoten; CREATE TABLE IF NOT EXISTS)') . PHP_EOL;

$lama = [];
$tabel = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
    AND TABLE_NAME NOT IN ('kesehatan_ringkasan', 'kesehatan_ringkasan_versi', 'kesehatan_ringkasan_peristiwa', 'kesehatan_ringkasan_penerima', 'schema_migrations', 'audit_logs')
    ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC);
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
