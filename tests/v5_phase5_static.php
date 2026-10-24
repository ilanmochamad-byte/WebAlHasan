<?php

declare(strict_types=1);

/**
 * Uji statis PRD V5 Fase 5 (laporan dan kesiapan rilis). Tanpa basis data.
 *
 *   php tests/v5_phase5_static.php
 *
 *   U-1  laporan hanya membaca dan tidak memilih kolom klinis/teks;
 *   U-2  hak laporan memakai `KesehatanService::akses()` dan penyaring baris
 *        binaan yang sama dengan daftar; pengawasan hanya `riwayat_akses`;
 *   U-3  API hanya GET `/v5/kesehatan/laporan` di blok no-store; halaman web
 *        lewat penjaga bersama, tanpa formulir POST; ikon ada di FA 6.0.0;
 *   U-4  tidak ada migrasi baru; rollback 024–026 berpenjaga CHECK sebelum
 *        DROP; alat rilis hanya CLI; latihan restore hanya DB uji;
 *   U-5  lint PHP seluruh berkas Fase 5.
 */

$root = dirname(__DIR__);
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $rel): string => (string) file_get_contents($root . '/' . $rel);

echo '=== U-1. Laporan hanya membaca, tanpa kolom klinis ===' . PHP_EOL;
$repo = $baca('app/Kesehatan/LaporanRepository.php');
$assert(preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|DROP|ALTER|CREATE)\b/', $repo) !== 1, 'LaporanRepository tanpa pernyataan tulis/DDL');
$kodeRepo = (string) preg_replace('/^\s*(\*|\/\/).*$/m', '', $repo);
foreach (['keluhan', 'catatan_klinis', 'nama_obat', 'petunjuk', 'uraian', 'tujuan', 'tindakan', 'tanggal_hasil', 'ringkasan_versi', 'no_hp', 'data_json', 'judul', 'isi'] as $kolom) {
    $assert(preg_match('/[\s.,(]' . preg_quote($kolom, '/') . '\b/', $kodeRepo) !== 1, "LaporanRepository tidak menyebut kolom `$kolom`");
}
// "hasil" juga bagian literal status 'Menunggu hasil'; yang dilarang kolomnya.
$assert(preg_match('/\.hasil\b|(?<![$\w])hasil\s*(,|FROM\b)|SELECT\s+hasil\b/', $kodeRepo) !== 1, 'LaporanRepository tidak memilih kolom `hasil` rujukan');
$assert(substr_count($repo, 'alasan IS NOT NULL') >= 2 && preg_match('/SELECT[^;]*\bv\.alasan\s*,/', $repo) !== 1, 'Alasan hanya diperiksa keberadaannya (IS NOT NULL), tidak dipilih sebagai teks');
$assert(str_contains($repo, 'kunjungan_id IN (SELECT k.id {$from})') && str_contains($repo, 'EXISTS (SELECT 1 FROM {$tabel} x WHERE x.kunjungan_id = k.id'), 'Rincian dihitung lewat IN/EXISTS, tidak JOIN yang menggandakan kunjungan');

echo PHP_EOL . '=== U-2. Hak laporan ===' . PHP_EOL;
$svc = $baca('app/Kesehatan/LaporanService.php');
$assert(str_contains($svc, '$this->kesehatan->akses($user)') && str_contains($svc, "if (!\$akses['baca'])"), 'Hak dihitung ulang lewat KesehatanService::akses() setiap panggilan; tanpa baca → 403');
$assert(str_contains($svc, 'saringBinaan(array_keys(array_filter($akses[\'binaan\'])), (int) $user[\'id\'])') && substr_count($svc, '$saring)') >= 3, 'Pembaca binaan memakai penyaring baris yang sama dengan daftar pada seluruh basis');
$assert(str_contains($svc, "if (\$akses['riwayat_akses'])") && str_contains($svc, "'pengawasan' => null"), 'Pengawasan hanya untuk riwayat_akses (admin)');
$assert(str_contains($svc, "\$kunjungan['draf'] = null"), 'Angka Draf disembunyikan bagi pembaca binaan');
$assert(!str_contains($svc, 'catatAkses') && !str_contains($svc, 'audit'), 'Laporan tidak menulis log akses/audit');

echo PHP_EOL . '=== U-3. API dan halaman ===' . PHP_EOL;
$api = $baca('api/v1/index.php');
$blok = substr($api, (int) strpos($api, "if (str_starts_with(\$path, '/v5/kesehatan'))"), 1500);
$assert(str_contains($blok, "header('Cache-Control: private, no-store')") && str_contains($blok, "\$method === 'GET' && \$path === '/v5/kesehatan/laporan'"), 'API GET /v5/kesehatan/laporan di blok no-store');
$assert(substr_count($api, '/v5/kesehatan/laporan') === 1, 'Tidak ada rute tulis laporan');
$hal = $baca('portal/kesehatan_laporan.php');
$assert(str_contains($hal, "require_once __DIR__ . '/_kesehatan.php'") && str_contains($hal, 'kesehatan_laporan_service()->laporan($currentUser'), 'Halaman memakai penjaga bersama (sesi, CSRF, no-store) dan layanan yang sama dengan API');
$assert(stripos($hal, 'method="post"') === false && !str_contains($hal, '$_POST'), 'Halaman laporan tanpa formulir POST');
$fa = array_flip(array_map('trim', file($root . '/tests/fixtures/font_awesome_6_0_0_solid.txt')));
foreach (['portal/kesehatan_laporan.php', 'portal/kesehatan.php'] as $f) {
    preg_match_all('/\bfa-([a-z0-9-]+)/', $baca($f), $m);
    $hilang = array_values(array_filter(array_unique($m[1]), static fn (string $n): bool => !isset($fa[$n])));
    $assert($hilang === [], "$f: seluruh ikon ada di Font Awesome 6.0.0" . ($hilang === [] ? '' : ' (tidak ada: ' . implode(', ', $hilang) . ')'));
}
$assert(str_contains($baca('portal/kesehatan.php'), "kesehatan_url('kesehatan_laporan.php')"), 'Daftar kunjungan menaut ke laporan');

echo PHP_EOL . '=== U-4. Rilis ===' . PHP_EOL;
$migrasi = glob($root . '/database/migrations/*.sql');
sort($migrasi);
$assert(basename((string) end($migrasi)) === '026_v5_fase3_ringkasan_wali.sql', 'Fase 5 tidak menambah migrasi (026 terakhir)');
foreach (['024_v5_fase1_kesehatan.sql' => 1, '025_v5_fase2_rincian_kesehatan.sql' => 2, '026_v5_fase3_ringkasan_wali.sql' => 3] as $f => $n) {
    $sql = preg_replace('/^--.*$/m', '', $baca('database/rollbacks/' . $f));
    $penjaga = strpos($sql, "INSERT INTO v5_fase{$n}_rollback_guard");
    $assert(str_contains($sql, "CREATE TEMPORARY TABLE v5_fase{$n}_rollback_guard") && str_contains($sql, 'CHECK (jumlah = 0)') && $penjaga !== false
        && $penjaga < (int) strpos($sql, 'DROP TABLE IF EXISTS kesehatan_'), "Rollback $f: penjaga CHECK berjalan sebelum DROP tabel kesehatan");
}
$assert(str_contains($baca('database/rollbacks/026_v5_fase3_ringkasan_wali.sql'), "notifikasi_outbox WHERE event_type LIKE 'v5\\\\_kesehatan\\\\_%'"), 'Penjaga 026 juga menghitung notifikasi V5');
foreach (['bin/v5_preflight.php', 'bin/v5_verify.php', 'bin/v5_manifest.php', 'bin/v5_backup_restore_drill.php', 'bin/_v5_rilis.php'] as $f) {
    $assert(str_contains($baca($f), "if (PHP_SAPI !== 'cli')"), "$f hanya CLI");
}
$drill = $baca('bin/v5_backup_restore_drill.php');
$assert(str_contains($drill, "getenv('V5_DRILL') !== '1'") && str_contains($drill, "'production'") && str_contains($drill, '/^test_[A-Za-z0-9_]*_test$/D'), 'Latihan restore: opt-in, menolak produksi, hanya DB uji');
$assert(str_contains($baca('bin/v5_preflight.php'), "array_merge(\$m['migrations'] ?? [], \$m['rollbacks'] ?? [])"), 'Preflight --rilis memeriksa SHA migrasi DAN rollback');
$assert(str_contains($baca('bin/v5_phase1_verify.php'), "TABLE_NAME IN ('\" . implode(\"', '\", \$kesehatan) . \"')"), 'Verifier Fase 1 menghitung kunci asing lima tabel 024 saja');

echo PHP_EOL . '=== U-5. Lint ===' . PHP_EOL;
foreach (['app/Kesehatan/LaporanService.php', 'app/Kesehatan/LaporanRepository.php', 'portal/kesehatan_laporan.php', 'portal/kesehatan.php', 'api/v1/index.php', 'app/bootstrap.php',
    'bin/v5_preflight.php', 'bin/v5_verify.php', 'bin/v5_manifest.php', 'bin/v5_backup_restore_drill.php', 'bin/_v5_rilis.php', 'bin/v5_phase1_verify.php',
    'tests/v5_phase5_seed.php', 'tests/v5_phase5_integration.php', 'tests/v5_phase5_http.php', 'tests/v5_phase5_rilis.php'] as $f) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $o, $kode);
    $assert($kode === 0, "php -l $f");
}

echo PHP_EOL . 'Ringkasan: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
