<?php

declare(strict_types=1);

/**
 * Uji statis Fase 5 penugasan lintas guru/pengurus — alat rilis, dokumen
 * rilis, dan batas perubahan. Tanpa basis data.
 *
 *   php tests/penugasan_keamanan_f5_static.php
 *
 *   S-1 alat rilis: hanya CLI (404 bila lewat web); preflight, verifier,
 *       preflight rollback, dan manifest tanpa SQL tulis; latihan restore
 *       hanya DB `test_*_test` dengan PK_DRILL=1 dan menolak produksi
 *   S-2 konstanta: migrasi/rollback 032–033 ada; baseline Fase 4 leluhur HEAD;
 *       kunci audit `keamanan.*` verifier = penyaring `AuditLogger`; tabel
 *       yang dibaca Keamanan tidak dikecualikan dari irisan baca
 *   S-3 manifest rilis ter-commit = SHA-256 berkas di disk; daftar lengkap
 *   S-4 batas: tanpa migrasi baru, tanpa perubahan kode aplikasi
 *       (app/portal/admin/api/database/assets) terhadap baseline
 *   S-5 dokumen: runbook belum dieksekusi di produksi; setiap baris checklist
 *       produksi "Menunggu pelaksanaan"
 *   S-6 lint PHP berkas baru
 */

$root = dirname(__DIR__);
$baseline = getenv('PKF5_BASELINE') ?: '39cc644';
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $f): string => (string) file_get_contents($root . '/' . $f);
/** Kode tanpa komentar, agar pemindaian tidak tertipu teks penjelasan. */
$kode = static function (string $isi): string {
    $hasil = '';
    foreach (token_get_all($isi) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $hasil .= is_array($t) ? $t[1] : $t;
    }

    return $hasil;
};
$alat = ['bin/penugasan_keamanan_preflight.php', 'bin/penugasan_keamanan_verify.php', 'bin/penugasan_keamanan_rollback_preflight.php',
    'bin/penugasan_keamanan_manifest.php', 'bin/penugasan_keamanan_backup_restore_drill.php'];
$hanyaBaca = ['bin/_penugasan_keamanan_rilis.php', 'bin/penugasan_keamanan_preflight.php', 'bin/penugasan_keamanan_verify.php',
    'bin/penugasan_keamanan_rollback_preflight.php', 'bin/penugasan_keamanan_manifest.php'];

echo "=== S-1 alat rilis ===\n";
foreach ($alat as $f) {
    $isi = $kode($baca($f));
    $guard = strpos($isi, "if (PHP_SAPI !== 'cli') {\n    http_response_code(404);\n    exit;\n}");
    $assert($guard !== false && $guard < strpos($isi, 'require_once'), $f . ': menolak non-CLI (404) sebelum memuat aplikasi');
}
foreach ($hanyaBaca as $f) {
    $isi = $kode($baca($f));
    preg_match_all('/["\']\s*(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE|RENAME|GRANT)\s/i', $isi, $m);
    $assert($m[1] === [] && !preg_match('/begin_transaction|->commit\(|file_put_contents\(APP_ROOT/', $isi),
        $f . ': tanpa SQL tulis/transaksi' . ($m[1] === [] ? '' : ' (' . implode(', ', $m[1]) . ')'));
}
$drill = $kode($baca('bin/penugasan_keamanan_backup_restore_drill.php'));
$assert(str_contains($drill, "getenv('PK_DRILL') !== '1'") && str_contains($drill, "preg_match('/^test_[A-Za-z0-9_]*_test\$/D', \$sumber)")
    && str_contains($drill, "=== 'production'") && str_contains($drill, '$target === $sumber'),
    'Latihan restore: wajib PK_DRILL=1, sumber test_*_test, tujuan berbeda, menolak APP_ENV=production');
preg_match_all('/DROP DATABASE IF EXISTS `\' \. \$(\w+)/', $drill, $m);
$assert($m[1] !== [] && array_unique($m[1]) === ['target'], 'Latihan restore hanya menghapus DB tujuan pulihan, tidak pernah DB sumber');
$verify = $kode($baca('bin/penugasan_keamanan_verify.php'));
$assert(str_contains($verify, "bin/penugasan_verify.php") && str_contains($verify, 'new Capabilities($db)') && str_contains($verify, 'pk_akun_keamanan_sql($db)'),
    'Verifier menjalankan verifier fondasi dan membandingkan resolver Capabilities dengan SQL independen');
$helper = $kode($baca('bin/_penugasan_keamanan_rilis.php'));
$assert(!preg_match('/Capabilities|KeamananAkses|featureSource/', substr($helper, (int) strpos($helper, 'function pk_akun_keamanan_sql'))),
    'Hitungan SQL independen tidak memanggil resolver yang diperiksanya');

echo "\n=== S-2 konstanta ===\n";
require_once $root . '/bin/_v7_phase1.php';
require_once $root . '/bin/_penugasan_keamanan_rilis.php';
foreach (PK_MIGRASI as $m) {
    $assert(is_file($root . '/database/migrations/' . $m) && is_file($root . '/database/rollbacks/' . $m), 'Migrasi dan rollback berpasangan: ' . $m);
}
$semua = array_map('basename', glob($root . '/database/migrations/*.sql') ?: []);
sort($semua);
$assert(end($semua) === PK_MIGRASI[1] && in_array(PK_PRASYARAT, $semua, true), 'Migrasi terakhir di pohon kerja = 033; prasyarat 031 ada (tanpa migrasi baru Fase 5)');
exec('git -C ' . escapeshellarg($root) . ' merge-base --is-ancestor ' . escapeshellarg(PK_BASELINE_WEB) . ' HEAD', $o, $k);
$assert($k === 0, 'Baseline web ' . substr(PK_BASELINE_WEB, 0, 7) . ' adalah leluhur HEAD');
preg_match('/function metadataKeamanan\(array \$data\): array\s*\{\s*\$boleh = \[([^\]]*)\]/', $baca('app/Audit/AuditLogger.php'), $m);
$kunci = array_map(static fn (string $s): string => trim($s, " '\n"), explode(',', $m[1] ?? ''));
$assert($kunci === PK_KUNCI_AUDIT_KEAMANAN, 'Kunci audit keamanan.* verifier = penyaring AuditLogger::metadataKeamanan (' . count($kunci) . ' kunci)');
$dibaca = ['izin_pengajuan', 'izin_keputusan', 'izin_riwayat_status', 'izin_pembimbing_tinjauan', 'izin_wali_perbaikan', 'v3_pelanggaran', 'v3_konseling_kasus',
    'v3_konseling_sesi', 'v3_lampiran', 'jadwal_ngaji', 'pertemuan_pengajian', 'pertemuan_peserta', 'santri', 'santri_wali', 'guru'];
$assert(array_intersect($dibaca, PK_IRISAN_KECUALI) === [] && array_filter($dibaca, static fn (string $t): bool => str_ends_with($t, '_assignments')) === [],
    'Tabel yang dibaca Keamanan tidak dikecualikan dari irisan baca');

echo "\n=== S-3 manifest rilis ===\n";
$manifest = json_decode($baca('docs/penugasan-keamanan/fase-5/manifest-migrasi.json'), true);
$assert(is_array($manifest) && ($manifest['pk_migrations'] ?? null) === PK_MIGRASI && array_key_exists('new_migration_in_phase5', $manifest) && $manifest['new_migration_in_phase5'] === null
    && ($manifest['web']['baseline'] ?? null) === PK_BASELINE_WEB && ($manifest['mobile']['baseline'] ?? null) === PK_BASELINE_MOBILE,
    'Manifest rilis: migrasi 032–033, tanpa migrasi Fase 5, baseline web/mobile');
$file = array_column(array_merge($manifest['migrations'] ?? [], $manifest['rollbacks'] ?? []), 'sha256', 'file');
$harap = [];
foreach ($semua as $f) {
    $harap['database/migrations/' . $f] = hash_file('sha256', $root . '/database/migrations/' . $f);
}
foreach (PK_MIGRASI as $f) {
    $harap['database/rollbacks/' . $f] = hash_file('sha256', $root . '/database/rollbacks/' . $f);
}
ksort($file);
ksort($harap);
$assert($file === $harap, 'Manifest = SHA-256 di disk untuk ' . count($harap) . ' berkas (33 migrasi + 2 rollback), daftar lengkap');

echo "\n=== S-4 batas perubahan terhadap baseline ===\n";
exec('git -C ' . escapeshellarg($root) . ' diff --name-only ' . escapeshellarg($baseline), $ubah);
exec('git -C ' . escapeshellarg($root) . ' ls-files --others --exclude-standard', $baru);
$pekerjaanLain = ['docs/panduan-pengguna-v4/', 'docs/presentasi-al-hasan-v4/', 'output/', 'tests/browser/tangkapan-penugasan-keamanan-f1/'];
$berkas = array_values(array_filter(array_unique(array_merge($ubah, $baru)), static function (string $f) use ($pekerjaanLain): bool {
    foreach ($pekerjaanLain as $p) {
        if (str_starts_with($f, $p)) {
            return false;
        }
    }

    return true;
}));
$diluar = array_values(array_filter($berkas, static fn (string $f): bool => !preg_match(
    '#^(bin/_penugasan_keamanan_rilis\.php|bin/penugasan_keamanan_[a-z_]+\.php|tests/penugasan_keamanan_f5_[a-z0-9]+\.(php|sh)|docs/penugasan-keamanan/|AGENTS\.md$)#', $f)));
$assert($diluar === [], 'Hanya alat rilis, uji Fase 5, dokumen, dan AGENTS.md yang berubah (' . count($berkas) . ' berkas)' . ($diluar === [] ? '' : ': ' . implode(', ', $diluar)));
$assert(array_filter($berkas, static fn (string $f): bool => (bool) preg_match('#^(app|portal|admin|api|database|assets|includes)/#', $f)) === [],
    'Tanpa perubahan app/, portal/, admin/, api/, database/, assets/');

echo "\n=== S-5 dokumen ===\n";
$runbook = $baca('docs/penugasan-keamanan/fase-5/runbook-rilis-pemulihan.md');
$assert(str_contains($runbook, '**Belum dieksekusi pada produksi.**') && str_contains($runbook, 'penugasan_keamanan_preflight.php') && str_contains($runbook, 'penugasan_keamanan_rollback_preflight.php'),
    'Runbook: belum dieksekusi pada produksi; memuat preflight, verifier, dan preflight rollback');
$checklist = $baca('docs/penugasan-keamanan/fase-5/checklist-smoke.md');
preg_match_all('/^\| S-\d+ \|.*\| ([^|]+) \|$/m', $checklist, $m);
$assert(count($m[1]) >= 15 && array_unique(array_map('trim', $m[1])) === ['Menunggu pelaksanaan'], 'Checklist: ' . count($m[1]) . ' baris, seluruh kolom produksi "Menunggu pelaksanaan"');
$assert(is_file($root . '/docs/penugasan-keamanan/fase-5/README.md'), 'README Fase 5 ada');

echo "\n=== S-6 lint ===\n";
foreach (array_merge($alat, ['bin/_penugasan_keamanan_rilis.php', 'tests/penugasan_keamanan_f5_static.php', 'tests/penugasan_keamanan_f5_rilis.php', 'tests/penugasan_keamanan_f5_e2e.php']) as $f) {
    $o = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $o, $k);
    $assert($k === 0, 'php -l ' . $f);
}

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
