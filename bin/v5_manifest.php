<?php

declare(strict_types=1);

/**
 * Manifest rilis PRD V5 (Fase 5): SHA-256 seluruh migrasi 001–026, rollback
 * 024–026, kunci dependensi, dan baseline Git. Hanya membaca berkas; tidak
 * menyentuh basis data.
 *
 *   php bin/v5_manifest.php [--mobile=/jalur/alhasanApps] > docs/phase-v5-5/manifest-migrasi.json
 *
 * Dipakai `bin/v5_preflight.php --rilis=…` untuk memastikan berkas di server
 * sama dengan yang diaudit sebelum migrasi.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('V5_MANIFEST_ROOT', dirname(__DIR__));

$mobile = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--mobile=')) {
        $mobile = rtrim(substr($a, 9), '/');
    }
}
$berkas = static function (string $root, string $rel): array {
    return ['file' => $rel, 'sha256' => (string) hash_file('sha256', $root . '/' . $rel)];
};
$git = static function (string $dir, string $args): ?string {
    $out = [];
    exec('git -C ' . escapeshellarg($dir) . ' ' . $args . ' 2>/dev/null', $out, $kode);

    return $kode === 0 ? trim(implode("\n", $out)) : null;
};

$migrasi = glob(V5_MANIFEST_ROOT . '/database/migrations/*.sql') ?: [];
sort($migrasi, SORT_STRING);
$manifest = [
    'rilis' => 'PRD V5 kesehatan santri (Fase 1–5)',
    'schema_required_before' => '023_redesain_web_tahap6_data_pesantren.sql',
    'v5_migrations' => ['024_v5_fase1_kesehatan.sql', '025_v5_fase2_rincian_kesehatan.sql', '026_v5_fase3_ringkasan_wali.sql'],
    'new_migration_in_phase5' => null,
    'web' => [
        'branch' => $git(V5_MANIFEST_ROOT, 'rev-parse --abbrev-ref HEAD'),
        'baseline' => '40ed0770148fd51aeba487380bb1d8ef07808ae0',
    ],
    'migrations' => array_map(static fn (string $f): array => $berkas(V5_MANIFEST_ROOT, 'database/migrations/' . basename($f)), $migrasi),
    'rollbacks' => array_map(static fn (string $m): array => $berkas(V5_MANIFEST_ROOT, 'database/rollbacks/' . $m),
        ['024_v5_fase1_kesehatan.sql', '025_v5_fase2_rincian_kesehatan.sql', '026_v5_fase3_ringkasan_wali.sql']),
    'dependencies' => array_values(array_filter([
        is_file(V5_MANIFEST_ROOT . '/composer.lock') ? $berkas(V5_MANIFEST_ROOT, 'composer.lock') : null,
    ])),
];
if ($mobile !== null && is_file($mobile . '/package-lock.json')) {
    $manifest['mobile'] = [
        'branch' => $git($mobile, 'rev-parse --abbrev-ref HEAD'),
        'baseline' => '33522f7',
        'files' => [
            ['file' => 'package-lock.json', 'sha256' => (string) hash_file('sha256', $mobile . '/package-lock.json')],
            ['file' => 'app.json', 'sha256' => (string) hash_file('sha256', $mobile . '/app.json')],
        ],
    ];
}
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
