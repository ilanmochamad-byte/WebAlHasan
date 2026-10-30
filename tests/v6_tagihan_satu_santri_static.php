<?php

declare(strict_types=1);

/**
 * Uji statis perbaikan "Terbitkan tagihan" satu santri dari tarif. Tanpa basis data.
 *
 *   php tests/v6_tagihan_satu_santri_static.php
 *
 *   Z-1  batas: tanpa migrasi; berkas berubah dari baseline `main` hanya daftar
 *        perbaikan; BsaService, hak, audit, notifikasi, API rute, mobile tetap;
 *   Z-2  layanan: santri_id opsional di normalisasi, sasaran, hash (tanpa santri
 *        hash massal sama), kunci idempotensi, cakupan santri sebelum replay;
 *        manual hanya bila tanpa kelas / unit tanpa tarif;
 *   Z-3  halaman: jalan tarif lewat pratinjau + terbitkanMassal; kiriman manual
 *        diperiksa ulang server; sebelum 028 perilaku Fase 1;
 *   Z-4  lint PHP berkas baru/berubah.
 */

$root = dirname(__DIR__);
$baseline = getenv('V6SS_BASELINE') ?: '0b2fcd1';
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $f): string => (string) @file_get_contents($root . '/' . $f);
$git = static fn (string $args): string => (string) shell_exec('cd ' . escapeshellarg($root) . ' && git ' . $args . ' 2>/dev/null');
$berubah = array_values(array_unique(array_filter(array_map('trim', explode("\n", $git("diff --name-only $baseline") . $git('ls-files --others --exclude-standard'))),
    static fn (string $f): bool => $f !== '' && !str_starts_with($f, 'docs/panduan-pengguna-v4/') && !str_starts_with($f, 'docs/presentasi-al-hasan-v4/') && !str_starts_with($f, 'output/'))));

echo '=== Z-1. Batas ===' . PHP_EOL;
$assert(trim($git("cat-file -t $baseline")) === 'commit', "Baseline main $baseline tersedia");
$izin = '#^(app/Bsa/Tagihan(Service|Repository)\.php|portal/bsa_tagihan_buat\.php|tests/v6_tagihan_satu_santri_[a-z]+\.php|tests/browser/uji-tagihan-satu-santri\.mjs'
    . '|docs/perbaikan-tagihan-satu-santri/.+|docs/api-v1\.md|AGENTS\.md)$#';
$luar = array_values(array_filter($berubah, static fn (string $f): bool => preg_match($izin, $f) !== 1));
$assert($luar === [], 'Berkas berubah hanya daftar perbaikan' . ($luar === [] ? '' : ': ' . implode(', ', $luar)));
foreach (['database/', 'app/Bsa/BsaService.php', 'app/Bsa/BsaRepository.php', 'app/Bsa/TabunganService.php', 'app/Bsa/LaporanService.php', 'app/Bsa/BsaNotifikasi.php',
    'app/Auth/', 'app/Penugasan/', 'app/Audit/', 'api/', 'portal/bsa_penerbitan.php', 'portal/_bsa.php'] as $jalur) {
    $assert(trim($git("diff --name-only $baseline -- " . escapeshellarg($jalur))) === '', "$jalur tidak berubah");
}

echo PHP_EOL . '=== Z-2. Layanan ===' . PHP_EOL;
$svc = $baca('app/Bsa/TagihanService.php');
$repo = $baca('app/Bsa/TagihanRepository.php');
$assert(str_contains($repo, 'public function sasaranPenerbitan(?array $cakupan, ?string $unit, ?int $santriId = null): array') && str_contains($repo, "\$sql .= ' AND s.id = ?';"),
    'Sasaran penerbitan: saringan santri opsional (parameter terikat), pemanggil lama tetap sah');
$assert(str_contains($svc, "'santri_id' => \$santriId];") && str_contains($svc, "\$this->bsa->id(\$input['santri_id'])"), 'Normalisasi penerbitan membaca santri_id opsional dengan validasi ID');
$assert(str_contains($svc, "...((\$data['santri_id'] ?? null) === null ? [] : [\$data['santri_id']])") && str_contains($svc, "...(\$data['santri_id'] === null ? [] : [\$data['santri_id']])]);"),
    'Hash pratinjau dan hash idempotensi menambah santri hanya bila ada (hash massal lama tetap)');
$pos = strpos($svc, '$this->bsa->santriDalamCakupan($data[\'santri_id\'], $penugasan);');
$assert($pos !== false && $pos < strpos($svc, "if (\$klaim['hasil_id'] !== null) {\n                \$lama = \$this->repo->penerbitan("), 'Santri diperiksa masih aktif dalam cakupan sebelum replay');
$manual = substr($svc, (int) strpos($svc, 'public function alasanTagihanManual'), 1400);
$assert(str_contains($manual, 'self::DILEWATI_TANPA_TARIF[0]') && str_contains($manual, 'tarifEfektif($periode)') && str_contains($manual, "throw new BsaException('Santri tidak ditemukan.', 404)")
    && str_contains($manual, '$this->bsa->wajibKelola($user)'), 'Manual hanya tanpa kelas / unit tanpa tarif; hak ditegakkan (kelola, 404 di luar cakupan)');

echo PHP_EOL . '=== Z-3. Halaman ===' . PHP_EOL;
$hal = $baca('portal/bsa_tagihan_buat.php');
$assert(str_contains($hal, "require_once __DIR__ . '/_bsa.php';") && str_contains($hal, "if (!\$aksesBsa['kelola'])"), 'Halaman memakai penjaga _bsa.php dan hanya petugas berpenugasan');
$assert(str_contains($hal, '$tagihanBsa->terbitkanMassal($currentUser, [\'santri_id\' => $santriId] + $_POST') && str_contains($hal, 'name="pratinjau_hash"'),
    'Jalan tarif: pratinjau berhash lalu penerbitan dengan santri_id');
$assert(str_contains($hal, '$tagihanBsa->alasanTagihanManual($currentUser, $santriId, $_POST[\'periode\'] ?? null) === null') && str_contains($hal, '$bolehManual = !$tarifTersedia || $alasanManual !== null;'),
    'Kiriman manual diperiksa ulang server; isian manual hanya ditampilkan bila boleh');
$assert(str_contains($hal, '$tarifTersedia = $tagihanBsa->tersedia();'), 'Sebelum migrasi 028: isian manual Fase 1 tetap');

echo PHP_EOL . '=== Z-4. Lint ===' . PHP_EOL;
$salah = [];
foreach (array_filter($berubah, static fn (string $f): bool => str_ends_with($f, '.php') && is_file($root . '/' . $f)) as $f) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $o, $k);
    if ($k !== 0) {
        $salah[] = $f;
    }
}
$assert($salah === [], 'Lint PHP berkas baru/berubah' . ($salah === [] ? '' : ': ' . implode(', ', $salah)));

echo PHP_EOL . "Ringkasan: {$lulus} lulus, " . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
