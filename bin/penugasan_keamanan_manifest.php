<?php

declare(strict_types=1);

/**
 * Manifest rilis penugasan lintas guru/pengurus dan Keamanan (Fase 5):
 * SHA-256 seluruh migrasi 001–033, rollback 032–033, kunci dependensi, dan
 * baseline Git. Hanya membaca berkas; tidak menyentuh basis data.
 *
 *   php bin/penugasan_keamanan_manifest.php [--mobile=/jalur/alhasanApps] > docs/penugasan-keamanan/fase-5/manifest-migrasi.json
 *
 * Berkas mobile di-hash dari HEAD Git (`git show HEAD:…`), bukan salinan
 * kerja, sebab salinan kerja dapat memuat perubahan pekerjaan lain yang belum
 * di-commit. Dipakai `bin/penugasan_keamanan_preflight.php --rilis=…` agar
 * berkas di server sama dengan yang diaudit sebelum migrasi.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/_penugasan_keamanan_rilis.php';

$mobile = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--mobile=')) {
        $mobile = rtrim(substr($a, 9), '/');
    }
}
$berkas = static fn (string $rel): array => ['file' => $rel, 'sha256' => (string) hash_file('sha256', APP_ROOT . '/' . $rel)];
$git = static function (string $dir, string $args): ?string {
    $out = [];
    exec('git -C ' . escapeshellarg($dir) . ' ' . $args . ' 2>/dev/null', $out, $kode);

    return $kode === 0 ? implode("\n", $out) : null;
};

$migrasi = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
sort($migrasi, SORT_STRING);
$manifest = [
    'rilis' => 'Penugasan lintas guru/pengurus dan Keamanan (Fase 1–5): identitas pengurus milik guru, pemisahan tugas per akun, Keamanan hanya baca di website dan aplikasi',
    'schema_required_before' => PK_PRASYARAT,
    'catatan_urutan' => 'bin/migrate.php up menjalankan seluruh migrasi tertunda berurutan: bila 024–031 (V5/V6/V7) belum terpasang di server, semuanya ikut terpasang sebelum 032–033. Preflight mewajibkan 031 sudah tercatat.',
    'pk_migrations' => PK_MIGRASI,
    'new_migration_in_phase5' => null,
    'web' => ['branch' => trim((string) $git(APP_ROOT, 'rev-parse --abbrev-ref HEAD')), 'baseline' => PK_BASELINE_WEB],
    'migrations' => array_map(static fn (string $f): array => $berkas('database/migrations/' . basename($f)), $migrasi),
    'rollbacks' => array_map(static fn (string $m): array => $berkas('database/rollbacks/' . $m), PK_MIGRASI),
    'dependencies' => array_values(array_filter([is_file(APP_ROOT . '/composer.lock') ? $berkas('composer.lock') : null])),
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
        'baseline' => PK_BASELINE_MOBILE,
        'catatan' => 'Hash berkas HEAD Git; perubahan salinan kerja milik pekerjaan lain tidak termasuk.',
        'files' => $files,
    ];
}
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
