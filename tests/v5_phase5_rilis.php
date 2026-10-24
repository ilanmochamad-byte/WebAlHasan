<?php

declare(strict_types=1);

/**
 * Uji kesiapan rilis PRD V5 Fase 5 pada SALINAN database uji.
 *
 *   V5_RUN_RILIS=1 php tests/v5_phase5_rilis.php
 *
 *   S-1  salinan Tahap 7 (023 terakhir, tanpa V5): preflight V5 → migrate up
 *        (024–026) → verifier V5 + manifest lulus; up kedua tanpa migrasi
 *        baru; rollback 026 → 025 → 024 dengan preflight masing-masing pada
 *        tabel kosong; jumlah baris tabel lama dan sidik jari santri/wali/
 *        relasi sama dengan sebelum migrasi; migrasi ulang berhasil;
 *   S-2  manifest rilis: preflight `--rilis` lulus untuk berkas yang sama dan
 *        gagal bila satu SHA-256 berbeda;
 *   S-3  penjaga SQL rollback 024, 025, 026 pada salinan berisi data menolak
 *        (dieksekusi seperti Migrator), tanpa DROP dan tanpa tabel penjaga;
 *   S-4  latihan backup → restore → verifier (`bin/v5_backup_restore_drill.php`)
 *        pada fixture Fase 5 lulus.
 *
 * Membuat dan menghapus DB `test_v5_f5_rilis_*_test`.
 */

if (getenv('V5_RUN_RILIS') !== '1') {
    fwrite(STDERR, "Setel V5_RUN_RILIS=1 untuk menjalankan (membuat DB test_v5_f5_rilis_*_test).\n");
    exit(2);
}
$root = dirname(__DIR__);
$dbKosong = 'test_v5_f5_rilis_kosong_test';
$dbData = 'test_v5_f5_rilis_data_test';
putenv('DB_NAME=' . $dbKosong);
require_once $root . '/app/bootstrap.php';

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$sh = static function (string $cmd, string $db, array $env = []) use ($root): array {
    $pref = 'cd ' . escapeshellarg($root) . ' && export DB_NAME=' . escapeshellarg($db);
    foreach ($env as $k => $v) {
        $pref .= ' ' . $k . '=' . escapeshellarg($v);
    }
    $out = [];
    exec($pref . ' && ' . $cmd . ' 2>&1', $out, $kode);

    return [$kode, implode("\n", $out)];
};
$tmp = sys_get_temp_dir() . '/v5f5-rilis-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
$config = app_config('database');
$koneksi = static function (string $db) use ($config): mysqli {
    $c = new mysqli((string) $config['host'], (string) $config['username'], (string) $config['password'], $db, (int) $config['port']);
    $c->set_charset((string) $config['charset']);

    return $c;
};
$tabelV5 = ['kesehatan_assignments', 'kesehatan_kunjungan', 'kesehatan_kunjungan_versi', 'kesehatan_idempotensi', 'kesehatan_akses_log', 'kesehatan_obat', 'kesehatan_rujukan',
    'kesehatan_tindak_lanjut', 'kesehatan_versi_rincian', 'kesehatan_ringkasan', 'kesehatan_ringkasan_versi', 'kesehatan_ringkasan_peristiwa', 'kesehatan_ringkasan_penerima'];
$adaTabel = static fn (mysqli $c): int => (int) $c->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'kesehatan\\_%'")->fetch_row()[0];

// ===================================================================== S-1
echo PHP_EOL . '=== S-1. Migrasi 024–026 dan rollback kosong pada salinan Tahap 7 ===' . PHP_EOL;
[$kode] = $sh('TAHAP7_DB=' . escapeshellarg($dbKosong) . ' bash tests/redesain_web_tahap7_db.sh >/dev/null', $dbKosong);
$assert($kode === 0, 'Salinan Tahap 7 dibuat (023 terakhir)');
$c = $koneksi($dbKosong);
// Skrip Tahap 7 menjalankan `migrate up` (memasang 024–026 kosong); lepas dahulu agar salinan = keadaan sebelum V5.
foreach (['v5_phase3', 'v5_phase2', 'v5_phase1'] as $fase) {
    $sh("php bin/{$fase}_rollback_preflight.php >/dev/null && php bin/migrate.php rollback >/dev/null", $dbKosong);
}
$assert($adaTabel($c) === 0 && (int) $c->query("SELECT COUNT(*) FROM schema_migrations WHERE migration LIKE '02%v5%'")->fetch_row()[0] === 0
    && (string) $c->query('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1')->fetch_row()[0] === '023_redesain_web_tahap6_data_pesantren.sql', 'Keadaan sebelum V5: 023 terakhir, tanpa tabel kesehatan');
[$kode, $out] = $sh('php bin/v5_preflight.php ' . escapeshellarg($tmp . '/sebelum.json'), $dbKosong);
$assert($kode === 0 && str_contains($out, '024_v5_fase1_kesehatan.sql: belum'), 'Preflight V5 lulus; 024–026 belum tercatat; manifest privat ditulis');
$sebelum = json_decode((string) file_get_contents($tmp . '/sebelum.json'), true);
[$kode, $out] = $sh('php bin/migrate.php up', $dbKosong);
$assert($kode === 0 && substr_count($out, '_v5_') === 3, 'migrate up menerapkan tepat 024, 025, 026');
[$kode, $out] = $sh('php bin/v5_verify.php ' . escapeshellarg($tmp . '/sebelum.json'), $dbKosong);
$assert($kode === 0 && str_contains($out, 'Sidik jari santri_wali tetap'), 'Verifier V5 + manifest lulus sesudah migrasi (baris lama dan sidik jari santri/wali/relasi tetap)');
[$kode, $out] = $sh('php bin/migrate.php up', $dbKosong);
$assert($kode === 0 && !str_contains($out, '_v5_'), 'migrate up kedua tidak menerapkan apa pun (idempoten)');
foreach (['026' => 'v5_phase3', '025' => 'v5_phase2', '024' => 'v5_phase1'] as $no => $fase) {
    [$kode, $out] = $sh("php bin/{$fase}_rollback_preflight.php && php bin/migrate.php rollback", $dbKosong);
    $assert($kode === 0 && str_contains($out, 'Rollback selesai: ' . $no), "Preflight lalu rollback $no pada tabel kosong berhasil");
}
[$kode] = $sh('php bin/v5_phase1_verify.php --rollback', $dbKosong);
$assert($kode === 0 && $adaTabel($c) === 0, 'Sesudah rollback 024: tidak ada tabel kesehatan tersisa');
[$kode] = $sh('php bin/v5_preflight.php ' . escapeshellarg($tmp . '/sesudah.json'), $dbKosong);
$sesudah = json_decode((string) file_get_contents($tmp . '/sesudah.json'), true);
$assert($sesudah['legacy_counts'] === $sebelum['legacy_counts'] && $sesudah['sidik_jari'] === $sebelum['sidik_jari'],
    'Jumlah baris ' . count($sebelum['legacy_counts']) . ' tabel lama dan sidik jari santri/wali/relasi identik dengan sebelum migrasi');
[$kode, $out] = $sh('php bin/migrate.php up && php bin/v5_verify.php', $dbKosong);
$assert($kode === 0, 'Migrasi ulang sesudah rollback berhasil dan verifier lulus');

// ===================================================================== S-2
echo PHP_EOL . '=== S-2. Manifest rilis ===' . PHP_EOL;
[$kode, $json] = $sh('php bin/v5_manifest.php', $dbKosong);
file_put_contents($tmp . '/rilis.json', $json);
$rilis = json_decode($json, true);
$assert($kode === 0 && is_array($rilis) && count($rilis['migrations']) === count(glob($root . '/database/migrations/*.sql')) && count($rilis['rollbacks']) === 3,
    'Manifest rilis: ' . count($rilis['migrations'] ?? []) . ' migrasi + 3 rollback V5 ber-SHA-256');
[$kode] = $sh('php bin/v5_preflight.php --rilis=' . escapeshellarg($tmp . '/rilis.json') . ' ' . escapeshellarg($tmp . '/x.json'), $dbKosong);
$assert($kode === 0, 'Preflight --rilis lulus untuk berkas yang sama');
$rilis['rollbacks'][2]['sha256'] = str_repeat('0', 64);
file_put_contents($tmp . '/rilis-ubah.json', json_encode($rilis));
[$kode, $out] = $sh('php bin/v5_preflight.php --rilis=' . escapeshellarg($tmp . '/rilis-ubah.json') . ' ' . escapeshellarg($tmp . '/y.json'), $dbKosong);
$assert($kode === 1 && str_contains($out, '[gagal] SHA-256 sesuai manifest rilis: database/rollbacks/026_v5_fase3_ringkasan_wali.sql'), 'Preflight --rilis gagal bila SHA-256 satu berkas berbeda');

// ===================================================================== S-3
echo PHP_EOL . '=== S-3. Penjaga SQL rollback pada data ===' . PHP_EOL;
[$kode] = $sh('V5F5_DB=' . escapeshellarg($dbData) . ' bash tests/v5_phase5_db.sh >/dev/null', $dbData);
$assert($kode === 0, 'Salinan fixture Fase 5 dibuat (berisi kunjungan, rincian, ringkasan)');
$d = $koneksi($dbData);
$jumlah = static function (mysqli $c) use ($tabelV5): array {
    $h = [];
    foreach ($tabelV5 as $t) {
        $h[$t] = (int) $c->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
    }

    return $h;
};
$awal = $jumlah($d);
foreach (['026', '025', '024'] as $no) {
    $berkas = glob($root . "/database/rollbacks/{$no}_v5_*.sql")[0];
    $pesan = '';
    try {
        // Persis seperti Migrator::runSqlFile (multi_query berhenti pada galat pertama).
        if ($d->multi_query((string) file_get_contents($berkas))) {
            do {
                if ($r = $d->store_result()) {
                    $r->free();
                }
            } while ($d->more_results() && $d->next_result());
        }
        $pesan = $d->error;
    } catch (mysqli_sql_exception $e) {
        $pesan = $e->getMessage();
    }
    while ($d->more_results()) {
        $d->next_result();
    }
    $assert(str_contains($pesan, "v5_fase" . ['026' => 3, '025' => 2, '024' => 1][$no] . '_rollback_guard_kosong') && $jumlah($d) === $awal,
        "Rollback $no pada data ditolak penjaga CHECK; 13 tabel V5 dan barisnya tetap");
}
// Sesi lain: tabel penjaga TEMPORARY hanya terlihat oleh sesi yang gagal dan hilang saat sesi itu berakhir.
$assert((int) $koneksi($dbData)->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'v5\\_fase%guard'")->fetch_row()[0] === 0,
    'Tidak ada tabel penjaga permanen tersisa (dilihat dari sesi lain)');
[$kode, $out] = $sh('php bin/migrate.php rollback', $dbData);
$assert($kode !== 0 && $jumlah($d) === $awal && (int) $d->query("SELECT COUNT(*) FROM schema_migrations WHERE migration = '026_v5_fase3_ringkasan_wali.sql'")->fetch_row()[0] === 1,
    'bin/migrate.php rollback tanpa preflight ditolak; 026 tetap tercatat');

// ===================================================================== S-4
echo PHP_EOL . '=== S-4. Latihan backup → restore → verifier ===' . PHP_EOL;
[$kode, $out] = $sh('php bin/v5_backup_restore_drill.php', $dbData, ['V5_DRILL' => '1']);
echo '    ' . implode("\n    ", array_filter(explode("\n", $out), static fn (string $l): bool => str_starts_with($l, '[') || str_starts_with($l, 'Ringkasan'))) . PHP_EOL;
$assert($kode === 0, 'bin/v5_backup_restore_drill.php lulus pada fixture Fase 5');

$c->query('DROP DATABASE IF EXISTS `' . $dbKosong . '`');
$d->query('DROP DATABASE IF EXISTS `' . $dbData . '`');
array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);

echo PHP_EOL . 'Ringkasan: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
