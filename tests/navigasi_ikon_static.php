<?php

declare(strict_types=1);

/**
 * Pemeriksaan statis ikon menu navigasi.
 *
 * Tidak memerlukan basis data, server, maupun jaringan. Memeriksa:
 *   I-1 halaman berkerangka masih memuat Font Awesome 6.0.0 (versi fixture);
 *   I-2 setiap nama ikon 'fa-…' di app/Ui/Navigation.php ada di Font Awesome
 *       Free 6.0.0 Solid (dirender dengan kelas "fas"), sehingga tidak ada
 *       menu tanpa ikon di sidebar, rel ikon tablet, atau bilah bawah HP;
 *   I-3 Perizinan dan Izin anak memakai fa-door-open.
 *
 * Daftar nama ikon: tests/fixtures/font_awesome_6_0_0_solid.txt.
 *
 * Jalankan:
 *   php tests/navigasi_ikon_static.php
 */

$root = dirname(__DIR__);

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};
$source = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

echo '=== I-1. Versi Font Awesome ===' . PHP_EOL;
$cdn = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css';
foreach (['app/Ui/Layout.php', 'app/Ui/Denial.php', 'portal/index.php'] as $berkas) {
    $isi = $source($berkas);
    $versi = preg_match_all('#font-awesome/([0-9.]+)/css/#', $isi, $m) ? array_unique($m[1]) : [];
    $assert(str_contains($isi, $cdn) && $versi === ['6.0.0'], "{$berkas} memuat Font Awesome 6.0.0 saja");
}

echo PHP_EOL . '=== I-2. Ikon navigasi tersedia ===' . PHP_EOL;
$fixture = file($root . '/tests/fixtures/font_awesome_6_0_0_solid.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$tersedia = array_flip(array_filter($fixture, static fn (string $baris): bool => $baris[0] !== '#'));
$assert(count($tersedia) > 1000 && isset($tersedia['house'], $tersedia['bars']), 'Fixture memuat daftar ikon Solid (' . count($tersedia) . ' nama)');

$navigasi = $source('app/Ui/Navigation.php');
preg_match_all("/'fa-([a-z0-9-]+)'/", $navigasi, $m);
$dipakai = array_values(array_unique($m[1]));
sort($dipakai);
$assert(count($dipakai) >= 20, 'Navigation.php memakai ' . count($dipakai) . ' nama ikon');
foreach ($dipakai as $nama) {
    $assert(isset($tersedia[$nama]), "fa-{$nama} ada di Font Awesome Free 6.0.0 Solid");
}

echo PHP_EOL . '=== I-3. Ikon Perizinan dan Izin anak ===' . PHP_EOL;
$assert(preg_match("/self::item\\('izin', 'Perizinan', '[^']+', 'fa-door-open'/", $navigasi) === 1, 'Perizinan memakai fa-door-open');
$assert(preg_match("/self::item\\('izin_anak', 'Izin anak', '[^']+', 'fa-door-open'/", $navigasi) === 1, 'Izin anak memakai fa-door-open');

echo PHP_EOL . (count($failures) === 0 ? 'Semua pemeriksaan lulus.' : count($failures) . ' pemeriksaan gagal.') . PHP_EOL;
exit($failures === [] ? 0 : 1);
