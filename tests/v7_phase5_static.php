<?php

declare(strict_types=1);

/**
 * Uji statis PRD V7 Fase 5 — regresi, keamanan, dan kesiapan rilis. Tanpa
 * basis data.
 *
 *   php tests/v7_phase5_static.php
 *
 *   Y-1  tanpa migrasi baru; kode aplikasi hanya koreksi F5-1 yang disetujui: berkas berubah
 *        sejak baseline audit Fase 4 hanya alat rilis, uji/dokumen dan
 *        daftar koreksi F5-1; admin, database dan aset tetap;
 *   Y-2  alat rilis: hanya CLI; preflight, verifier, manifest dan pembantu
 *        tanpa pernyataan tulis; latihan hanya `test_*_test` dengan
 *        V7_DRILL=1, menolak produksi, credential lewat berkas opsi 0600;
 *   Y-3  verifier: memanggil verifier Fase 2–3, transisi/rantai, audit,
 *        keputusan tunggal, yatim, laporan = sumber, manifest wajib lengkap;
 *   Y-4  manifest rilis ter-commit = berkas di disk (31 migrasi, rollback
 *        030–031), baseline web/mobile benar;
 *   Y-5  dokumen: README, runbook, checklist (baris produksi "Menunggu
 *        pelaksanaan"), tanpa kata sandi/token;
 *   Y-6  lint PHP berkas baru.
 */

$root = dirname(__DIR__);
$baseline = getenv('V7F5_BASELINE') ?: '851ef0a26d1b8dd4e1db80ecf4f4ce8104ee8da7';
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $f): string => (string) @file_get_contents($root . '/' . $f);
$git = static fn (string $args): string => (string) shell_exec('cd ' . escapeshellarg($root) . ' && git ' . $args . ' 2>/dev/null');
$berubah = array_values(array_filter(array_map('trim', explode("\n", $git("diff --name-only $baseline") . $git('ls-files --others --exclude-standard')))));
$berubah = array_values(array_unique(array_filter($berubah, static fn (string $f): bool => !str_starts_with($f, 'docs/panduan-pengguna-v4/')
    && !str_starts_with($f, 'docs/presentasi-al-hasan-v4/') && !str_starts_with($f, 'output/'))));

// ===================================================================== Y-1
echo '=== Y-1. Tanpa migrasi baru; koreksi F5-1 terarah ===' . PHP_EOL;
$assert(trim($git("cat-file -t $baseline")) === 'commit', "Baseline audit Fase 4 $baseline tersedia");
$migrasi = glob($root . '/database/migrations/*.sql') ?: [];
sort($migrasi);
$assert(basename((string) end($migrasi)) === '031_v7_fase2_tinjauan_pembimbing.sql' && count($migrasi) === 31, 'Migrasi terakhir tetap 031 (31 migrasi)');
$f51 = [
    'app/Izin/IzinRouter.php', 'app/Izin/IzinWorkflowService.php', 'app/Izin/IzinRepository.php', 'app/Izin/IzinService.php',
    'app/Izin/IzinWaliRepository.php', 'app/Izin/IzinWaliService.php', 'app/Izin/IzinWaliNotifikasi.php', 'app/Izin/IzinLaporanRepository.php',
    'app/Report/IzinReportRepository.php', 'app/Api/IzinApiService.php', 'portal/izin_detail.php',
    'tests/v7_phase2_integration.php', 'tests/v7_phase2_audit.php', 'tests/v7_phase3_integration.php', 'tests/v7_phase3_audit_notifications.php',
];
$izin = '#^(bin/_v7_rilis\.php|bin/v7_(preflight|verify|manifest|backup_restore_drill)\.php|tests/v7_phase5_[a-z0-9]+\.(php|sh)|tests/browser/uji-v7-fase5(?:-audit)?\.mjs'
    . '|docs/phase-v7-5/.+|AGENTS\.md|PRD-V7\.md)$#';
$luar = array_values(array_filter($berubah, static fn (string $f): bool => preg_match($izin, $f) !== 1 && !in_array($f, $f51, true)));
$assert($luar === [], 'Berkas berubah hanya alat rilis, uji/dokumen Fase 5 dan koreksi F5-1 yang disetujui' . ($luar === [] ? '' : ': ' . implode(', ', $luar)));
foreach (['app/', 'api/', 'portal/', 'admin/', 'database/', 'assets/', 'bin/migrate.php', 'bin/_v7_phase1.php', 'bin/_v7_phase2.php', 'bin/v7_phase1_verify.php',
    'bin/v7_phase2_verify.php', 'bin/v7_phase3_verify.php', 'bin/v7_phase1_rollback_preflight.php', 'bin/v7_phase2_rollback_preflight.php', 'tests/v7_phase1_worker.php',
    'tests/v7_phase2_worker.php', 'tests/v7_phase1_seed.php', 'tests/v7_phase2_seed.php'] as $jalur) {
    $paths = array_values(array_filter(explode("\n", trim($git("diff --name-only $baseline -- " . escapeshellarg($jalur))))));
    $assert(array_diff($paths, $f51) === [], "$jalur di luar koreksi F5-1 tidak berubah sejak baseline");
}

// ===================================================================== Y-2
echo PHP_EOL . '=== Y-2. Alat rilis ===' . PHP_EOL;
$alat = ['bin/_v7_rilis.php', 'bin/v7_preflight.php', 'bin/v7_verify.php', 'bin/v7_manifest.php', 'bin/v7_backup_restore_drill.php'];
foreach ($alat as $f) {
    $isi = $baca($f);
    $assert($isi !== '' && (str_contains($isi, "PHP_SAPI !== 'cli'") || str_contains($isi, "require_once __DIR__ . '/_v7_phase2.php'")), "$f hanya CLI (penjaga PHP_SAPI langsung atau lewat pembantu Fase 1)");
}
$assert(str_contains($baca('bin/_v7_phase1.php'), "PHP_SAPI !== 'cli'"), 'Pembantu Fase 1 yang dipakai bersama menolak non-CLI');
foreach (['bin/_v7_rilis.php', 'bin/v7_preflight.php', 'bin/v7_verify.php', 'bin/v7_manifest.php'] as $f) {
    $kode = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $baca($f));
    $assert(preg_match('/\b(INSERT\s+INTO|UPDATE\s+[a-z_`]+\s+SET|DELETE\s+FROM|ALTER\s+TABLE|DROP\s+(TABLE|DATABASE)|CREATE\s+(TABLE|DATABASE)|TRUNCATE)\b/i', $kode) !== 1
        && !str_contains($kode, '->begin_transaction') && preg_match('/(exec|proc_open|shell_exec|passthru|system)\([^;]*migrate\.php/', $kode) !== 1,
        "$f tanpa pernyataan tulis/DDL dan tanpa menjalankan migrasi");
}
$drill = $baca('bin/v7_backup_restore_drill.php');
$assert(str_contains($drill, "getenv('V7_DRILL') !== '1'") && str_contains($drill, "/^test_[A-Za-z0-9_]*_test\$/D") && str_contains($drill, "=== 'production'")
    && str_contains($drill, 'chmod($opsi, 0600)') && str_contains($drill, '--defaults-extra-file=') && !str_contains($drill, '-p" .') && !preg_match('/--password=/', $drill),
    'Latihan: V7_DRILL=1, sumber/tujuan test_*_test, menolak produksi, credential lewat berkas opsi 0600 (tidak di argumen proses)');
$assert(str_contains($drill, '--single-transaction --routines --triggers --hex-blob') && str_contains($drill, 'bin/v7_phase2_rollback_preflight.php')
    && str_contains($drill, 'v7_fase2_rollback_guard_kosong') && str_contains($drill, "DROP DATABASE IF EXISTS `' . \$target"), 'Latihan: dump transaksi tunggal, rollback berisi data ditolak, DB pulihan dihapus');
$pre = $baca('bin/v7_preflight.php');
$assert(str_contains($pre, '@chmod($tujuan, 0600)') && str_contains($pre, "str_starts_with(\$a, '--rilis=')")
    && str_contains($pre, 'Daftar lengkap dan unik manifest rilis') && str_contains($pre, 'Tidak ada pemasangan sebagian 030–031') && str_contains($pre, 'Jam basis data'),
    'Preflight: manifest privat 0600, daftar rilis lengkap, tolak pemasangan sebagian, jam DB = aplikasi');

// ===================================================================== Y-3
echo PHP_EOL . '=== Y-3. Verifier rilis ===' . PHP_EOL;
$ver = $baca('bin/v7_verify.php');
foreach ([
    "['v7_phase2_verify.php', 'v7_phase3_verify.php']" => 'menjalankan verifier Fase 2 dan 3 sebagai proses terpisah',
    'V7_TRANSISI' => 'memeriksa transisi status sah',
    'Rantai riwayat tiap pengajuan Wali bersambung' => 'memeriksa rantai riwayat sampai status kini',
    'V7_PERISTIWA_AUDIT' => 'memasangkan riwayat dengan audit',
    'izin_keputusan_pengajuan_unique' => 'memeriksa indeks keputusan tunggal',
    'Nol referensi yatim' => 'memeriksa referensi yatim',
    'IzinLaporanRepository' => 'membandingkan laporan dengan sumber',
    'Manifest memuat tepat seluruh tabel lama' => 'menolak manifest terpotong',
    '--irisan-v2' => 'mendukung pembandingan irisan V2 saat V7 dipakai',
] as $pola => $label) {
    $assert(str_contains($ver, $pola), 'Verifier ' . $label);
}
$rilis = $baca('bin/_v7_rilis.php');
$assert(str_contains($rilis, "const V7_BASELINE_WEB = '851ef0a26d1b8dd4e1db80ecf4f4ce8104ee8da7'") && str_contains($rilis, "const V7_BASELINE_MOBILE = '7478f7cb153e4f8af1a1b85791ce843a29733bd2'"),
    'Baseline web/mobile pada pembantu rilis = commit audit Fase 4');
$assert(str_contains($rilis, 'function v7_hash_kueri') && str_contains($rilis, '% $p') && !str_contains($rilis, 'ORDER BY 1'), 'Hash isi tidak bergantung urutan baris (multiset), bukan ORDER BY kolom pertama');

// ===================================================================== Y-4
echo PHP_EOL . '=== Y-4. Manifest rilis ===' . PHP_EOL;
$m = json_decode($baca('docs/phase-v7-5/manifest-migrasi.json'), true);
$assert(is_array($m) && count($m['migrations'] ?? []) === 31 && array_column($m['rollbacks'] ?? [], 'file') === ['database/rollbacks/030_v7_fase1_pengajuan_wali.sql', 'database/rollbacks/031_v7_fase2_tinjauan_pembimbing.sql']
    && ($m['web']['baseline'] ?? '') === $baseline && ($m['mobile']['baseline'] ?? '') === '7478f7cb153e4f8af1a1b85791ce843a29733bd2' && array_key_exists('new_migration_in_phase5', $m) && $m['new_migration_in_phase5'] === null,
    'Manifest: 31 migrasi, rollback 030–031, baseline web/mobile, tanpa migrasi baru Fase 5');
$cocok = 0;
foreach (array_merge($m['migrations'] ?? [], $m['rollbacks'] ?? [], $m['dependencies'] ?? []) as $e) {
    $cocok += is_file($root . '/' . $e['file']) && hash_equals($e['sha256'], (string) hash_file('sha256', $root . '/' . $e['file'])) ? 1 : 0;
}
$assert($cocok === count($m['migrations'] ?? []) + count($m['rollbacks'] ?? []) + count($m['dependencies'] ?? []), "SHA-256 manifest = berkas di disk ($cocok berkas)");

// ===================================================================== Y-5
echo PHP_EOL . '=== Y-5. Dokumen ===' . PHP_EOL;
foreach (['README.md', 'runbook-rilis-pemulihan.md', 'checklist-smoke.md', 'perintah-uji.sh', 'perintah-regresi.sh'] as $d) {
    $assert(is_file($root . '/docs/phase-v7-5/' . $d), "docs/phase-v7-5/$d tersedia");
}
$check = $baca('docs/phase-v7-5/checklist-smoke.md');
$baris = array_filter(explode("\n", $check), static fn (string $l): bool => preg_match('/^\| *S-\d+/', $l) === 1);
$assert(count($baris) >= 15 && count(array_filter($baris, static fn (string $l): bool => str_contains($l, 'Menunggu pelaksanaan'))) === count($baris),
    'Checklist smoke: setiap langkah produksi/cPanel berstatus "Menunggu pelaksanaan" (' . count($baris) . ' langkah)');
$dok = '';
foreach (glob($root . '/docs/phase-v7-5/*.md') ?: [] as $f) {
    $dok .= (string) file_get_contents($f);
}
$assert(!str_contains($dok, 'Sandbox#123') && preg_match('/Bearer [A-Za-z0-9]{20,}/', $dok) !== 1, 'Dokumen Fase 5 tanpa kata sandi fixture dan tanpa token mentah');
$readme = $baca('docs/phase-v7-5/README.md');
$assert(str_contains($readme, 'Belum diuji') || str_contains($readme, 'belum diuji'), 'README memisahkan bukti lokal dari yang belum diuji');

// ===================================================================== Y-6
echo PHP_EOL . '=== Y-6. Lint ===' . PHP_EOL;
$php = array_values(array_filter($berubah, static fn (string $f): bool => str_ends_with($f, '.php') && is_file($root . '/' . $f)));
$salah = [];
foreach ($php as $f) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $o, $k);
    if ($k !== 0) {
        $salah[] = $f;
    }
}
$assert($php !== [] && $salah === [], 'Lint ' . count($php) . ' berkas PHP baru' . ($salah === [] ? '' : ': ' . implode(', ', $salah)));

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
