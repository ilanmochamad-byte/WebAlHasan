<?php

declare(strict_types=1);

/**
 * Preflight rilis PRD V5 (Fase 5, migrasi 024–026 sekaligus atau sisa yang
 * belum terpasang). Hanya membaca.
 *
 *   php bin/v5_preflight.php [manifest-privat.json] [--rilis=docs/phase-v5-5/manifest-migrasi.json]
 *
 * Memeriksa prasyarat (023 tercatat, role dasar, tabel yang dirujuk kunci
 * asing V5 dan tabel notifikasi V2), melaporkan migrasi V5 yang sudah/belum
 * tercatat, dan — bila `--rilis` diberikan — mencocokkan SHA-256 berkas
 * migrasi/rollback di disk dengan manifest rilis yang diaudit. Lalu menulis
 * manifest privat: jumlah baris seluruh tabel di luar `kesehatan_*`,
 * `schema_migrations`, dan `audit_logs`, serta sidik jari identitas santri,
 * wali, dan relasi santri–wali, untuk dibandingkan `bin/v5_verify.php`
 * sesudah migrasi atau rollback. Manifest berisi jumlah baris saja, tanpa
 * isi; tetap simpan di luar document root.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/_v5_rilis.php';

$db = app_db();
$args = array_slice($argv, 1);
$rilis = null;
$tujuan = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--rilis=')) {
        $rilis = substr($a, 8);
    } elseif (!str_starts_with($a, '--')) {
        $tujuan = $a;
    }
}
$gagal = 0;
$periksa = static function (bool $ok, string $label) use (&$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    if (!$ok) {
        $gagal++;
    }
};
$satu = static fn (string $sql): int => (int) $db->query($sql)->fetch_row()[0];
$tabelAda = static function (string $t) use ($db): bool {
    $s = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->bind_param('s', $t);
    $s->execute();
    $ada = (int) $s->get_result()->fetch_row()[0] === 1;
    $s->close();

    return $ada;
};
$nama = (string) $db->query('SELECT DATABASE()')->fetch_row()[0];
echo '[info] Basis data terpilih: ' . $nama . ' (preflight hanya membaca; migrasi produksi memerlukan otorisasi operator).' . PHP_EOL;

foreach (['users', 'user_roles', 'roles', 'pengurus', 'guru', 'tahun_ajaran', 'santri', 'wali', 'santri_wali', 'kelas', 'kamar', 'plotting_kelas', 'plotting_kamar',
    'pembimbing_assignments', 'murobi_assignments', 'notifikasi_outbox', 'perangkat_push', 'pengaturan_notifikasi', 'audit_logs', 'schema_migrations'] as $t) {
    $periksa($tabelAda($t), 'Tabel prasyarat ' . $t);
}
$periksa($satu("SELECT COUNT(*) FROM schema_migrations WHERE migration = '023_redesain_web_tahap6_data_pesantren.sql'") === 1, 'Migrasi 023 tercatat');
$periksa($satu("SELECT COUNT(*) FROM roles WHERE slug IN ('admin', 'pengurus', 'guru', 'orang_tua')") === 4, 'Role dasar admin, pengurus, guru, orang_tua tersedia (V5 tidak menambah role)');

$v5 = ['024_v5_fase1_kesehatan.sql', '025_v5_fase2_rincian_kesehatan.sql', '026_v5_fase3_ringkasan_wali.sql'];
$terpasang = [];
foreach ($v5 as $m) {
    $s = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = ?');
    $s->bind_param('s', $m);
    $s->execute();
    $terpasang[$m] = (int) $s->get_result()->fetch_row()[0] === 1;
    $s->close();
    echo '[info] ' . $m . ': ' . ($terpasang[$m] ? 'sudah tercatat' : 'belum') . PHP_EOL;
}
$berurutan = true;
$adaCelah = false;
foreach ($terpasang as $ada) {
    $berurutan = $berurutan && !($ada && $adaCelah);
    $adaCelah = $adaCelah || !$ada;
}
$periksa($berurutan, 'Migrasi V5 tercatat berurutan (tidak ada 025/026 tanpa pendahulunya)');
$sesudah = $satu("SELECT COUNT(*) FROM schema_migrations WHERE migration > '026_v5_fase3_ringkasan_wali.sql'");
$periksa($sesudah === 0, 'Tidak ada migrasi sesudah 026 yang tercatat (rilis ini tidak mengenal migrasi lebih baru)');

if ($rilis !== null) {
    $m = json_decode((string) @file_get_contents($rilis), true);
    $valid = is_array($m) && isset($m['migrations'], $m['rollbacks']) && is_array($m['migrations']) && is_array($m['rollbacks']);
    $periksa($valid, 'Manifest rilis terbaca: ' . $rilis);
    // Hash yang cocok tidak membuktikan kelengkapan: daftar kosong/terpotong
    // harus ditolak sebelum satu pun hasil dapat disebut terverifikasi.
    $wajib = [
        'migrations' => array_map(static fn (string $f): string => 'database/migrations/' . basename($f), glob(APP_ROOT . '/database/migrations/*.sql') ?: []),
        'rollbacks' => array_map(static fn (string $f): string => 'database/rollbacks/' . $f, $v5),
    ];
    foreach ($wajib as $jenis => $files) {
        $entries = $valid ? $m[$jenis] : [];
        $paths = array_map(static fn (mixed $entry): mixed => is_array($entry) ? ($entry['file'] ?? null) : null, $entries);
        sort($files);
        sort($paths);
        $periksa($paths === $files, 'Daftar lengkap dan unik manifest rilis: ' . $jenis);
    }
    foreach ($valid ? array_merge($m['migrations'] ?? [], $m['rollbacks'] ?? []) : [] as $berkas) {
        if (!is_array($berkas) || !is_string($berkas['file'] ?? null) || !in_array($berkas['file'], array_merge(...array_values($wajib)), true)
            || !is_string($berkas['sha256'] ?? null)) {
            $periksa(false, 'Entri manifest rilis tidak valid');
            continue;
        }
        $jalur = APP_ROOT . '/' . (string) ($berkas['file'] ?? '');
        $periksa(is_file($jalur) && hash_equals((string) ($berkas['sha256'] ?? ''), (string) hash_file('sha256', $jalur)), 'SHA-256 sesuai manifest rilis: ' . ($berkas['file'] ?? '?'));
    }
}

$lama = [];
foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
    AND TABLE_NAME NOT LIKE 'kesehatan\\_%' AND TABLE_NAME NOT IN ('schema_migrations', 'audit_logs') ORDER BY TABLE_NAME")->fetch_all(MYSQLI_NUM) as [$t]) {
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', (string) $t)) {
        exit(2);
    }
    $lama[$t] = $satu('SELECT COUNT(*) FROM `' . $t . '`');
}
$manifest = json_encode([
    'database' => $nama,
    'captured_at' => date(DATE_ATOM),
    'v5_terpasang' => $terpasang,
    'legacy_counts' => $lama,
    'sidik_jari' => v5_sidik_jari($db),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($tujuan !== null) {
    file_put_contents($tujuan, $manifest . PHP_EOL);
    @chmod($tujuan, 0600);
    echo 'Manifest ditulis: ' . $tujuan . PHP_EOL;
} else {
    echo $manifest . PHP_EOL;
}
echo "Backup DB terverifikasi dan manifest ini wajib disimpan sebelum migrasi.\n";
exit($gagal > 0 ? 1 : 0);

