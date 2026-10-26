<?php

declare(strict_types=1);

/**
 * Uji kesiapan rilis bersama PRD V6 (Fase 5) pada SALINAN database uji.
 *
 *   V6_RUN_RILIS=1 php tests/v6_phase5_rilis.php
 *
 * Langkah (DB `test_*_test` tersendiri, dibangun ulang):
 *   S-1  salinan V6 tanpa data uang diturunkan ke keadaan sebelum V6 lewat
 *        preflight rollback + `migrate rollback` 029 → 028 → 027 (berpenjaga,
 *        tabel kosong); preflight rilis menulis manifest privat; `migrate up`
 *        memasang 027–029 sekaligus; `bin/v6_verify.php` + manifest lulus
 *        (tabel lama dan identitas tetap) — naik–turun–naik;
 *   S-2  manifest rilis: hash berkas di disk = manifest ter-commit; rollback
 *        yang diubah, daftar terpotong, dan rollback tidak lengkap ditolak;
 *   S-3  pemasangan sebagian (028 tanpa 029) ditolak preflight rilis;
 *   S-4  jam basis data ≠ jam aplikasi (APP_TIMEZONE berbeda) ditolak;
 *   S-5  salinan berisi seluruh jenis transaksi: verifier lulus; preflight
 *        rollback 029 memblokir; penjaga SQL rollback 029, 028, dan 027
 *        menolak DROP saat dijalankan langsung lewat klien — tabel dan data
 *        tetap, tidak ada tabel penjaga tersisa;
 *   S-6  latihan backup → restore → verifikasi → rollback ditolak, dua
 *        metode (BackupWriter aplikasi dan mariadb-dump runbook).
 */

if (getenv('V6_RUN_RILIS') !== '1') {
    fwrite(STDERR, "Set V6_RUN_RILIS=1 untuk menjalankan.\n");
    exit(2);
}
$root = dirname(__DIR__);
$dbA = 'test_v6_f5_rilis_test';
$dbB = 'test_v6_f5_drill_test';

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
/** @return array{0:int, 1:string} */
$sh = static function (string $perintah, string $db, array $env = []) use ($root): array {
    $proc = proc_open(['bash', '-c', $perintah], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, array_merge(getenv(), ['DB_NAME' => $db], $env));
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $kode = proc_close($proc);

    return [$kode, (string) $out];
};
putenv('DB_NAME=' . $dbA);
require_once $root . '/app/bootstrap.php';
require_once $root . '/bin/_v6_rilis.php';
mysqli_report(MYSQLI_REPORT_OFF);
$buka = static fn (string $db): mysqli => new mysqli((string) app_config('database.host'), (string) app_config('database.username'), (string) app_config('database.password'), $db, (int) app_config('database.port'));
$scratch = sys_get_temp_dir() . '/v6f5-rilis-' . bin2hex(random_bytes(3));
mkdir($scratch);
$terakhir = static fn (mysqli $m): string => (string) $m->query('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1')->fetch_row()[0];
$tabelV6 = static fn (mysqli $m): int => (int) $m->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'bsa\\_%'")->fetch_row()[0];
$penjaga = static fn (mysqli $m): int => (int) $m->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%rollback\\_guard%'")->fetch_row()[0];

// ===================================================================== S-1
echo PHP_EOL . '=== S-1. Turun ke sebelum V6, lalu 027–029 sekaligus ===' . PHP_EOL;
[$kode] = $sh('V6F5_DB=' . $dbA . ' bash tests/v6_phase5_db.sh', $dbA);
$assert($kode === 0, 'Salinan V6 berfixture V1–V5 + penugasan BSA dibangun: ' . $dbA);
$a = $buka($dbA);
$assert((int) $a->query('SELECT COUNT(*) FROM bsa_tabungan_transaksi')->fetch_row()[0] + (int) $a->query('SELECT COUNT(*) FROM bsa_tagihan')->fetch_row()[0] === 0, 'Salinan tanpa data uang V6');
foreach ([['v6_phase3_rollback_preflight.php', '029'], ['v6_phase2_rollback_preflight.php', '028'], ['v6_phase1_rollback_preflight.php', '027']] as [$pre, $no]) {
    [$kp, $op] = $sh('php bin/' . $pre, $dbA);
    [$kr, $or] = $sh('php bin/migrate.php rollback', $dbA);
    $assert($kp === 0 && $kr === 0 && str_contains($or, 'Rollback selesai: ' . $no), "Rollback $no: preflight lulus (tabel kosong) dan migrate rollback berhasil");
}
$assert($terakhir($a) === '026_v5_fase3_ringkasan_wali.sql' && $tabelV6($a) === 0 && $penjaga($a) === 0, 'Keadaan sebelum V6: 026 terakhir, 0 tabel bsa_*, 0 tabel penjaga tersisa');
$manifestA = $scratch . '/manifest-a.json';
[$kode, $out] = $sh('php bin/v6_preflight.php ' . escapeshellarg($manifestA) . ' --rilis=docs/phase-v6-5/manifest-migrasi.json', $dbA);
$m = json_decode((string) @file_get_contents($manifestA), true);
$assert($kode === 0 && str_contains($out, 'Preflight V6: lulus.') && $m['v6_terpasang'] === array_fill_keys(V6_MIGRASI, false) && array_keys($m['sidik_jari']) === ['santri', 'wali', 'santri_wali', 'users']
    && (fileperms($manifestA) & 0777) === 0600, 'Preflight rilis sebelum migrasi lulus; manifest privat 0600 (027–029 belum, sidik jari identitas)');
$assert(substr_count($out, 'SHA-256 sesuai manifest rilis') === 35 && !str_contains($out, '[gagal]'), 'Preflight --rilis: 29 migrasi + 6 rollback = manifest ter-commit (35 SHA cocok)');
[$kode, $out] = $sh('php bin/migrate.php up', $dbA);
$assert($kode === 0 && preg_match_all('/^- 02[789]_v6_/m', $out) === 3 && !str_contains($out, '024_') && $terakhir($a) === '029_v6_fase3_tabungan.sql',
    'migrate up memasang tepat 027, 028, 029 berurutan dalam satu langkah');
[$kode, $out] = $sh('php bin/v6_verify.php ' . escapeshellarg($manifestA), $dbA);
$assert($kode === 0 && str_contains($out, 'Manifest memuat tepat seluruh tabel lama') && str_contains($out, '[lulus] Jumlah baris seluruh tabel lama tetap')
    && str_contains($out, '[lulus] Sidik jari santri_wali tetap') && str_contains($out, '[info] Manifest diambil sebelum tabel V6 ada'),
    'Verifier rilis + manifest sesudah migrasi: invarians, tabel lama, dan identitas tetap');
[$kode, $out] = $sh('php bin/v6_preflight.php', $dbA);
$assert($kode === 0 && str_contains($out, '029_v6_fase3_tabungan.sql: sudah tercatat'), 'Preflight sesudah migrasi: 027–029 tercatat, lulus');

// ===================================================================== S-2
echo PHP_EOL . '=== S-2. Manifest rilis ===' . PHP_EOL;
$asli = json_decode((string) file_get_contents($root . '/docs/phase-v6-5/manifest-migrasi.json'), true);
$segar = json_decode((string) shell_exec('php ' . escapeshellarg($root . '/bin/v6_manifest.php')), true);
$assert($asli['migrations'] === $segar['migrations'] && $asli['rollbacks'] === $segar['rollbacks'] && $asli['web']['baseline'] === V6_BASELINE_WEB,
    'Manifest ter-commit = hash berkas saat ini (migrasi dan rollback); baseline web ' . substr(V6_BASELINE_WEB, 0, 7));
$cobaManifest = static function (callable $ubah) use ($asli, $scratch, $sh, $dbA): array {
    $m = $asli;
    $ubah($m);
    $f = $scratch . '/manifest-' . bin2hex(random_bytes(3)) . '.json';
    file_put_contents($f, json_encode($m));

    return $sh('php bin/v6_preflight.php ' . escapeshellarg($scratch . '/x.json') . ' --rilis=' . escapeshellarg($f), $dbA);
};
[$kode, $out] = $cobaManifest(static function (array &$m): void {
    $m['rollbacks'][5]['sha256'] = str_repeat('0', 64);
});
$assert($kode === 1 && str_contains($out, '[gagal] SHA-256 sesuai manifest rilis: database/rollbacks/029_v6_fase3_tabungan.sql'), 'SHA rollback 029 yang berbeda → preflight gagal');
[$kode, $out] = $cobaManifest(static function (array &$m): void {
    array_pop($m['migrations']);
});
$assert($kode === 1 && str_contains($out, '[gagal] Daftar lengkap dan unik manifest rilis: migrations'), 'Daftar migrasi terpotong → preflight gagal (hash cocok bukan bukti kelengkapan)');
[$kode, $out] = $cobaManifest(static function (array &$m): void {
    $m['rollbacks'] = array_slice($m['rollbacks'], 3);
});
$assert($kode === 1 && str_contains($out, '[gagal] Daftar lengkap dan unik manifest rilis: rollbacks'), 'Rollback V5 hilang dari manifest → preflight gagal');

// ===================================================================== S-3 / S-4
echo PHP_EOL . '=== S-3. Pemasangan sebagian; S-4. Jam basis data ===' . PHP_EOL;
$sh('php bin/v6_phase3_rollback_preflight.php && php bin/migrate.php rollback', $dbA);
[$kode, $out] = $sh('php bin/v6_preflight.php', $dbA);
$assert($terakhir($a) === '028_v6_fase2_tagihan_bulanan.sql' && $kode === 1 && str_contains($out, '[gagal] Tidak ada pemasangan sebagian 027–029'), '028 tanpa 029 → preflight rilis gagal "pemasangan sebagian"');
$sh('php bin/migrate.php up', $dbA);
[$kode, $out] = $sh('php bin/v6_preflight.php', $dbA, ['APP_TIMEZONE' => 'Pacific/Kiritimati']);
$assert($kode === 1 && preg_match('/\[gagal\] Jam basis data .* \(Pacific\/Kiritimati\), selisih [0-9]+ detik/', $out) === 1, 'Zona waktu aplikasi ≠ basis data → preflight gagal (batas hari/periode)');
[$kode, $out] = $sh('php bin/v6_preflight.php', $dbA);
$assert($kode === 0, 'Kembali ke zona waktu yang sama → preflight lulus');

// ===================================================================== S-5
echo PHP_EOL . '=== S-5. Penjaga rollback dengan data ===' . PHP_EOL;
[$kode] = $sh('V6F5_DB=' . $dbB . ' bash tests/v6_phase5_db.sh', $dbB);
$b = $buka($dbB);
$id = static fn (string $sql): int => (int) $b->query($sql)->fetch_row()[0];
$aba = $scratch . '/aba';
touch($aba);
[$kode, $out] = $sh('php tests/v6_phase5_worker.php ' . escapeshellarg($aba) . ' ' . $id("SELECT id FROM users WHERE username = 'sbx_v6_bsa_b'") . ' fixture '
    . $id("SELECT id FROM santri WHERE nis = 'V6S0001'") . ' ' . $id("SELECT id FROM santri WHERE nis = 'V6S0002'") . ' ' . $id("SELECT id FROM santri WHERE nis = 'V6S0004'"), $dbB);
$assert($kode === 0 && str_contains($out, '"ok":true'), 'Salinan berisi seluruh jenis transaksi (setoran, belanja + koreksi, tagihan, bayar tabungan, pembatalan, transfer menunggu/ditolak, santri keluar)');
[$kode, $out] = $sh('php bin/v6_verify.php', $dbB);
$assert($kode === 0 && str_contains($out, '[info] Santri nonaktif bersaldo/bertunggakan (R-10, hanya dilaporkan): 1 santri, saldo Rp12000'), 'Verifier rilis lulus pada salinan berisi; R-10 dilaporkan');
[$kode, $out] = $sh('php bin/v6_phase3_rollback_preflight.php', $dbB);
$assert($kode === 1 && str_contains($out, '[blokir] bsa_setoran_transfer'), 'Preflight rollback 029 memblokir (setoran transfer, belanja, pembalik, pembayaran tabungan)');
$hashV6 = static fn (mysqli $m): string => hash('sha256', json_encode(v6_sidik_jari($m)));
$sebelum = $hashV6($b);
$klien = trim((string) shell_exec('command -v mariadb || command -v mysql'));
$opsi = $scratch . '/opsi.cnf';
file_put_contents($opsi, "[client]\nprotocol=TCP\nhost=\"" . app_config('database.host') . "\"\nport=" . (int) app_config('database.port') . "\nuser=\"" . app_config('database.username')
    . "\"\npassword=\"" . str_replace('"', '\\"', (string) app_config('database.password')) . "\"\n");
chmod($opsi, 0600);
foreach (['029_v6_fase3_tabungan.sql' => 'v6_fase3_rollback_guard_kosong', '028_v6_fase2_tagihan_bulanan.sql' => 'v6_fase2_rollback_guard_kosong', '027_v6_fase1_bsa.sql' => 'v6_fase1_rollback_guard_kosong'] as $f => $guard) {
    $out = (string) shell_exec(escapeshellarg($klien) . ' --defaults-extra-file=' . escapeshellarg($opsi) . ' ' . escapeshellarg($dbB) . ' < ' . escapeshellarg($root . '/database/rollbacks/' . $f) . ' 2>&1');
    $assert(str_contains($out, $guard) && $tabelV6($b) === 16 && $hashV6($b) === $sebelum && $penjaga($b) === 0,
        "Rollback $f dijalankan langsung (tanpa preflight) ditolak penjaga SQL; 16 tabel V6 dan isi uang tetap");
    if (!str_contains($out, $guard)) {
        echo '    keluaran klien: ' . trim($out) . PHP_EOL;
    }
}
$assert($terakhir($b) === '029_v6_fase3_tabungan.sql', 'Catatan migrasi 029 tetap');

// ===================================================================== S-6
echo PHP_EOL . '=== S-6. Latihan backup → restore → verifikasi ===' . PHP_EOL;
foreach (['writer', 'dump'] as $metode) {
    [$kode, $out] = $sh('php bin/v6_backup_restore_drill.php --metode=' . $metode, $dbB, ['V6_DRILL' => '1']);
    preg_match('/Ringkasan latihan: ([0-9]+) lulus, ([0-9]+) gagal/', $out, $r);
    $assert($kode === 0 && ($r[2] ?? '1') === '0', "Latihan metode $metode: " . ($r[1] ?? '?') . '/' . ($r[2] ?? '?') . ' (preflight, backup, restore, verifier, hash isi, laporan, rollback ditolak)');
    if ($kode !== 0) {
        echo implode(PHP_EOL, array_filter(explode("\n", $out), static fn (string $l): bool => str_contains($l, '[gagal]'))) . PHP_EOL;
    }
}
[$kode, $out] = $sh('php bin/v6_backup_restore_drill.php', $dbB);
$assert($kode === 2 && str_contains($out, 'V6_DRILL=1'), 'Latihan tanpa V6_DRILL=1 ditolak');
[$kode, $out] = $sh('php bin/v6_backup_restore_drill.php', 'webalhasan', ['V6_DRILL' => '1']);
$assert($kode === 2 && str_contains($out, 'wajib test_*_test'), 'Latihan pada DB bukan test_*_test ditolak');

array_map('unlink', glob($scratch . '/*') ?: []);
rmdir($scratch);
echo PHP_EOL . 'Ringkasan: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
