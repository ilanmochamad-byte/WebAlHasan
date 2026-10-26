<?php

declare(strict_types=1);

/**
 * Manifest rilis bersama PRD V6 (Fase 5): SHA-256 seluruh migrasi 001–029,
 * rollback 024–029, kunci dependensi, dan baseline Git. Hanya membaca berkas;
 * tidak menyentuh basis data.
 *
 *   php bin/v6_manifest.php [--mobile=/jalur/alhasanApps] > docs/phase-v6-5/manifest-migrasi.json
 *
 * Berkas mobile di-hash dari HEAD Git (`git show HEAD:…`), bukan salinan
 * kerja, sebab salinan kerja dapat memuat perubahan pekerjaan lain yang belum
 * di-commit. Dipakai `bin/v6_preflight.php --rilis=…` agar berkas di server
 * sama dengan yang diaudit sebelum migrasi.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/_v6_rilis.php';
define('V6_MANIFEST_ROOT', dirname(__DIR__));

$mobile = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--mobile=')) {
        $mobile = rtrim(substr($a, 9), '/');
    }
}
$berkas = static fn (string $rel): array => ['file' => $rel, 'sha256' => (string) hash_file('sha256', V6_MANIFEST_ROOT . '/' . $rel)];
$git = static function (string $dir, string $args): ?string {
    $out = [];
    exec('git -C ' . escapeshellarg($dir) . ' ' . $args . ' 2>/dev/null', $out, $kode);

    return $kode === 0 ? implode("\n", $out) : null;
};

$migrasi = glob(V6_MANIFEST_ROOT . '/database/migrations/*.sql') ?: [];
sort($migrasi, SORT_STRING);
$rollback = ['024_v5_fase1_kesehatan.sql', '025_v5_fase2_rincian_kesehatan.sql', '026_v5_fase3_ringkasan_wali.sql', ...V6_MIGRASI];
$manifest = [
    'rilis' => 'PRD V6 keuangan santri/BSA — tagihan dan tabungan dirilis bersama (Fase 1–5)',
    'schema_required_before' => '023_redesain_web_tahap6_data_pesantren.sql',
    'catatan_urutan' => 'bin/migrate.php up menjalankan seluruh migrasi tertunda berurutan: bila 024–026 (V5) belum terpasang di server, keduanya ikut terpasang sebelum 027–029.',
    'v6_migrations' => V6_MIGRASI,
    'new_migration_in_phase5' => null,
    'web' => ['branch' => trim((string) $git(V6_MANIFEST_ROOT, 'rev-parse --abbrev-ref HEAD')), 'baseline' => V6_BASELINE_WEB],
    'migrations' => array_map(static fn (string $f): array => $berkas('database/migrations/' . basename($f)), $migrasi),
    'rollbacks' => array_map(static fn (string $m): array => $berkas('database/rollbacks/' . $m), $rollback),
    'dependencies' => array_values(array_filter([is_file(V6_MANIFEST_ROOT . '/composer.lock') ? $berkas('composer.lock') : null])),
];
if ($mobile !== null) {
    $files = [];
    foreach (['package.json', 'package-lock.json', 'app.json'] as $f) {
        // Keluaran mentah (shell_exec), agar baris kosong/akhir berkas tidak berubah.
        $isi = shell_exec('git -C ' . escapeshellarg($mobile) . ' show ' . escapeshellarg('HEAD:' . $f) . ' 2>/dev/null');
        if (is_string($isi) && $isi !== '') {
            $files[] = ['file' => $f, 'sha256_head' => hash('sha256', $isi)];
        }
    }
    $manifest['mobile'] = [
        'branch' => trim((string) $git($mobile, 'rev-parse --abbrev-ref HEAD')),
        'baseline' => V6_BASELINE_MOBILE,
        'catatan' => 'Hash berkas HEAD Git; perubahan salinan kerja milik pekerjaan lain tidak termasuk.',
        'files' => $files,
    ];
}
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
