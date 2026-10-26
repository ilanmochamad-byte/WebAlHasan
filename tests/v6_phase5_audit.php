<?php

declare(strict_types=1);

// Probe auditor: fixture sudah siap. Manifest sementara dan CHECK sesi diuji;
// saldo fixture diubah Rp1 lalu dipulihkan dalam finally untuk kontrol negatif.
if (getenv('V6_RUN_AUDIT') !== '1' || !preg_match('/^test_[A-Za-z0-9_]*_test$/D', (string) getenv('DB_NAME'))) {
    fwrite(STDERR, "Gunakan V6_RUN_AUDIT=1 DB_NAME=test_*_test (fixture Fase 5 berisi).\n");
    exit(2);
}
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
require_once $root . '/bin/_v6_rilis.php';
$dir = sys_get_temp_dir() . '/v6f5-audit-' . bin2hex(random_bytes(5));
mkdir($dir, 0700);
register_shutdown_function(static function () use ($dir): void {
    foreach (glob($dir . '/*') ?: [] as $f) { unlink($f); }
    rmdir($dir);
});
$run = static function (array $args): array {
    $p = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};
$ok = 0;
$bad = 0;
$assert = static function (bool $pass, string $label) use (&$ok, &$bad): void {
    echo ($pass ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    $pass ? $ok++ : $bad++;
};
$mfile = $dir . '/manifest.json';
[$code] = $run([PHP_BINARY, $root . '/bin/v6_preflight.php', $mfile]);
$m = json_decode((string) file_get_contents($mfile), true);
$assert($code === 0 && $m['v6_terpasang'] === array_fill_keys(V6_MIGRASI, true), 'Kontrol: preflight pada fixture 027–029 lengkap');
[$code] = $run([PHP_BINARY, $root . '/bin/v6_verify.php', $mfile]);
$assert($code === 0, 'Kontrol: manifest lengkap dan verifier lulus');
foreach (['seluruh uang', 'satu tabel uang', 'status hilang', 'status palsu sebelum V6'] as $case) {
    $x = $m;
    if ($case === 'seluruh uang') {
        $x['sidik_jari'] = array_intersect_key($x['sidik_jari'], array_flip(['santri', 'wali', 'santri_wali', 'users']));
    } elseif ($case === 'satu tabel uang') {
        unset($x['sidik_jari']['bsa_tabungan_transaksi']);
    } elseif ($case === 'status hilang') {
        unset($x['v6_terpasang']);
    } else {
        $x['v6_terpasang'] = array_fill_keys(V6_MIGRASI, false);
    }
    $f = $dir . '/rusak.json';
    file_put_contents($f, json_encode($x));
    [$code, $out] = $run([PHP_BINARY, $root . '/bin/v6_verify.php', $f]);
    $assert($code === 1 && str_contains($out, '[gagal] Kelengkapan sidik jari sesuai status migrasi sumber'), 'Manifest kehilangan ' . $case . ' ditolak tanpa melewati pemeriksaan uang');
}
$db = app_db();
$santri = (int) $db->query("SELECT id FROM santri WHERE nis = 'V6S0001'")->fetch_row()[0];
$admin = ['id' => (int) $db->query("SELECT id FROM users WHERE username = 'sbx_admin'")->fetch_row()[0]];
$db->query("UPDATE bsa_tabungan_akun SET saldo = saldo + 1 WHERE santri_id = $santri");
try {
    $lap = laporan_bsa_service();
    $penuh = $lap->laporan($admin, []);
    $cari = $lap->laporan($admin, ['q' => 'Tsanawi']);
    $assert(!$penuh['selisih']['nol'] && $penuh['selisih']['santri_akun'] === 1, 'Kontrol: saldo akun SMP +Rp1 terdeteksi laporan');
    $assert(!$cari['selisih']['nol'] && $cari['selisih']['santri_akun'] === 1 && $cari['per_santri']['total'] === 1,
        'Pencarian Tsanawi tidak menyembunyikan selisih akun SMP dari rekonsiliasi agregat');
    $petugasT = ['id' => (int) $db->query("SELECT id FROM users WHERE username = 'sbx_v6_bsa_tsanawi'")->fetch_row()[0]];
    $terbatas = $lap->laporan($petugasT, ['q' => 'Tsanawi']);
    $assert($terbatas['selisih']['nol'] && $terbatas['selisih']['santri_akun'] === 0,
        'Rekonsiliasi pencarian petugas Tsanawi tidak membaca selisih SMP di luar hak');
} finally {
    $db->query("UPDATE bsa_tabungan_akun SET saldo = saldo - 1 WHERE santri_id = $santri");
}
if (stripos((string) $db->query('SELECT VERSION()')->fetch_row()[0], 'mariadb') !== false) {
    $php = 'require ' . var_export($root . '/app/bootstrap.php', true) . '; app_db()->query("SET SESSION check_constraint_checks = OFF"); $argv = ["preflight"]; require ' . var_export($root . '/bin/v6_preflight.php', true) . ';';
    [$code, $out] = $run([PHP_BINARY, '-r', $php]);
    $assert($code === 1 && str_contains($out, '[gagal] CHECK aktif pada sesi dan default global'), 'CHECK sesi dinonaktifkan → preflight gagal walau versi server mendukung');
    $assert((int) $db->query('SELECT @@session.check_constraint_checks')->fetch_row()[0] === 1, 'CHECK koneksi induk tetap aktif (tanpa perubahan global)');
}
echo "Ringkasan: $ok lulus, $bad gagal\n";
exit($bad === 0 ? 0 : 1);
