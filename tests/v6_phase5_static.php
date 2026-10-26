<?php

declare(strict_types=1);

/**
 * Uji statis PRD V6 Fase 5 — laporan, audit, dan kesiapan rilis bersama.
 * Tanpa basis data. Membaca checkout mobile `~/alhasanApps` (atau `V6F5_MOBILE`).
 *
 *   php tests/v6_phase5_static.php
 *
 *   X-1  tanpa migrasi baru; migrasi/rollback ≤ 029 tidak berubah dari baseline
 *        audit Fase 4; berkas berubah hanya dalam daftar yang diizinkan (Auth,
 *        Penugasan, Navigation, layanan mutasi BSA, PSB, V1–V5 tetap);
 *   X-2  laporan hanya membaca: repository tanpa INSERT/UPDATE/DELETE, satu
 *        snapshot, setiap kueri memakai syarat hak bersama; layanan menghitung
 *        hak lewat `BsaService::wajibBaca` dan menyembunyikan bagian internal
 *        bagi wali; kas tidak memuat sumber Tabungan/Internal;
 *   X-3  ekspor: jenis tertutup, wali tanpa koreksi, ditolak bila terlalu
 *        besar (bukan dipotong), audit tanpa nominal, sel formula diberi kutip,
 *        rincian transaksi tanpa referensi/alasan/catatan;
 *   X-4  web/API: halaman memakai penjaga `_bsa.php`, ekspor CSV berkepala
 *        unduhan + nosniff, rute API laporan/ekspor, navigasi "Laporan";
 *   X-5  alat rilis: hanya CLI, verifier/preflight/manifest hanya membaca,
 *        latihan hanya DB `test_*_test` dengan V6_DRILL=1 dan menolak produksi,
 *        manifest ter-commit = berkas di disk;
 *   X-6  aplikasi: layar laporan hanya GET, tanpa ekspor/berkas/log, di navigator
 *        keuangan yang dilindungi tangkapan layar;
 *   X-7  lint PHP berkas baru/berubah.
 */

$root = dirname(__DIR__);
$baseline = getenv('V6F5_BASELINE') ?: '75cb144685d02d10df563f67eb1e23a67eec030b';
$mobile = getenv('V6F5_MOBILE') ?: (getenv('HOME') . '/alhasanApps');
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $f): string => (string) @file_get_contents($root . '/' . $f);
$git = static fn (string $args): string => (string) shell_exec('cd ' . escapeshellarg($root) . ' && git ' . $args . ' 2>/dev/null');
$berubah = array_values(array_filter(array_map('trim', explode("\n", $git("diff --name-only $baseline") . $git('ls-files --others --exclude-standard')))));
$berubah = array_values(array_unique(array_filter($berubah, static fn (string $f): bool => !str_starts_with($f, 'docs/panduan-pengguna-v4/') && !str_starts_with($f, 'docs/presentasi-al-hasan-v4/') && !str_starts_with($f, 'output/'))));

// ===================================================================== X-1
echo '=== X-1. Tanpa migrasi baru; batas berkas ===' . PHP_EOL;
$assert(trim($git("cat-file -t $baseline")) === 'commit', "Baseline audit Fase 4 $baseline tersedia");
$assert(array_filter($berubah, static fn (string $f): bool => str_starts_with($f, 'database/')) === [], 'Tidak ada berkas database/ (migrasi/rollback) yang ditambah atau diubah');
$migrasi = glob($root . '/database/migrations/*.sql') ?: [];
sort($migrasi);
$assert(basename((string) end($migrasi)) === '029_v6_fase3_tabungan.sql', 'Migrasi terakhir tetap 029');
$izin = '#^(app/Bsa/Laporan(Service|Repository)\.php|app/bootstrap\.php|api/v1/index\.php|portal/_bsa\.php|portal/bsa_laporan(_ekspor)?\.php|bin/_v6_rilis\.php|bin/v6_(preflight|verify|manifest|backup_restore_drill)\.php'
    . '|tests/v6_phase5_[a-z]+\.(php|sh)|tests/browser/uji-v6-fase5(-app)?\.mjs|docs/phase-v6-5/.+|docs/api-v1\.md|AGENTS\.md|PRD-V6\.md|docs/prd-v6/.+)$#';
$luar = array_values(array_filter($berubah, static fn (string $f): bool => preg_match($izin, $f) !== 1));
$assert($luar === [], 'Berkas berubah hanya dalam daftar Fase 5' . ($luar === [] ? '' : ': ' . implode(', ', $luar)));
foreach (['app/Auth/', 'app/Penugasan/', 'app/Ui/Navigation.php', 'app/Bsa/BsaService.php', 'app/Bsa/BsaRepository.php', 'app/Bsa/TagihanService.php', 'app/Bsa/TagihanRepository.php',
    'app/Bsa/TabunganService.php', 'app/Bsa/TabunganRepository.php', 'app/Bsa/BsaNotifikasi.php', 'app/Audit/', 'app/Kesehatan/', 'portal/bsa_rekonsiliasi.php'] as $jalur) {
    $assert(trim($git("diff --name-only $baseline -- " . escapeshellarg($jalur))) === '', "$jalur tidak berubah (layanan mutasi, hak, audit, dan modul lain tetap)");
}
$bootDiff = $git("diff $baseline -- app/bootstrap.php");
$assert(preg_match_all('/^-[^-]/m', $bootDiff) === 0 && str_contains($bootDiff, '+function laporan_bsa_service()'), 'bootstrap.php hanya menambah pabrik laporan_bsa_service()');
$apiDiff = $git("diff $baseline -- api/v1/index.php");
$assert(preg_match_all('/^-[^-]/m', $apiDiff) === 0 && str_contains($apiDiff, "'/v6/bsa/laporan'") && str_contains($apiDiff, "'/v6/bsa/laporan/ekspor'"), 'API hanya menambah dua rute GET laporan dan ekspor');

// ===================================================================== X-2
echo PHP_EOL . '=== X-2. Laporan hanya membaca dan menurut hak ===' . PHP_EOL;
$repo = $baca('app/Bsa/LaporanRepository.php');
$layanan = $baca('app/Bsa/LaporanService.php');
$assert(preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|TRUNCATE)\b/', $repo) === 0, 'LaporanRepository tanpa INSERT/UPDATE/DELETE/DDL');
$assert(str_contains($repo, 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY') && str_contains($repo, "SET TRANSACTION ISOLATION LEVEL REPEATABLE READ") && !str_contains($repo, 'FOR UPDATE'),
    'Satu snapshot konsisten hanya-baca, tanpa kunci baris');
$publik = preg_match_all('/public function (\w+)\(array \$hak/', $repo, $m) ? $m[1] : [];
$tanpaHak = array_filter($publik, static function (string $fn) use ($repo): bool {
    $i = strpos($repo, "public function $fn(");
    $j = strpos($repo, 'public function ', $i + 10);
    $isi = substr($repo, $i, ($j === false ? strlen($repo) : $j) - $i);

    return !str_contains($isi, '$this->batas(') && !str_contains($isi, 'sqlPerSantri(');
});
$assert(count($publik) >= 10 && $tanpaHak === [], 'Setiap kueri laporan berhak (' . count($publik) . ' fungsi) memasang syarat hak bersama (batas/syaratHak)');
$assert(str_contains($repo, '$this->tagihan->syaratHak($hak, $params)') && str_contains($repo, "\$hak['mode'] !== 'wali'"), 'Syarat hak = TagihanRepository::syaratHak (petugas cakupan, admin semua, wali anak)');
$assert(str_contains($layanan, '$this->bsa->wajibBaca($user)') && str_contains($layanan, '$this->bsa->santriTerbaca($user, $akses, $santriId)')
    && str_contains($layanan, "throw new BsaException('Unit ini di luar cakupan penugasan BSA Anda.', 404)"), 'Layanan: hak dihitung ulang, santri di luar hak 404, unit di luar cakupan 404');
foreach (["'kas' => \$wali ? null", "'internal' => \$wali ? null", "'koreksi' => \$wali ? null", "'selisih' => \$wali ? null", "'menunggu' => \$wali ? null"] as $p) {
    $assert(str_contains($layanan, $p), "Bagian internal disembunyikan bagi wali: $p");
}
$assert(str_contains($layanan, "\$admin && \$f['unit'] === null ? \$this->repo->nonaktifBersaldo"), 'Daftar santri nonaktif bersaldo (R-10) hanya admin');
$kas = substr($layanan, (int) strpos($layanan, 'private function kas('), 1200);
$assert(str_contains($kas, "foreach (['Tunai', 'Transfer'] as \$cara)") && !str_contains($kas, 'Tabungan') && !str_contains($kas, 'Internal'), 'Rumus kas hanya Tunai/Transfer (tabungan/internal bukan kas)');
$assert(str_contains($layanan, "\$kas['Tunai']['setoran'] + \$kas['Tunai']['pembayaran_tagihan'] + \$kas['Transfer']['setoran'] + \$kas['Transfer']['pembayaran_tagihan']"),
    'Penerimaan kas baru = setoran + pembayaran tagihan tunai/transfer');
$assert(preg_match('/RENTANG_MAKS_BULAN = 12;/', $layanan) === 1 && str_contains($layanan, "'Periode laporan tidak boleh di masa depan.'"), 'Rentang paling panjang 12 bulan, tidak di masa depan');

// ===================================================================== X-3
echo PHP_EOL . '=== X-3. Ekspor ===' . PHP_EOL;
$assert(str_contains($layanan, "public const EKSPOR = ['santri', 'transaksi', 'koreksi'];") && str_contains($layanan, "if (\$wali && \$jenis === 'koreksi')"), 'Jenis ekspor tertutup; wali tanpa ekspor koreksi (403)');
$assert(str_contains($layanan, 'count($rows) > LaporanRepository::EKSPOR_MAKS') && str_contains($repo, 'EKSPOR_MAKS + 1'), 'Ekspor terlalu besar ditolak (bukan dipotong diam-diam)');
$auditEkspor = substr($layanan, (int) strpos($layanan, "wajibAudit('bsa.laporan.ekspor'"), 400);
$assert($auditEkspor !== '' && preg_match('/nominal|saldo|alasan|nama|referensi/', $auditEkspor) === 0, 'Ekspor diaudit (bsa.laporan.ekspor) tanpa nominal/nama/alasan');
$assert(str_contains($layanan, "preg_match('/^[\\x00-\\x20]*[=+@-]/u'") && str_contains($layanan, "\"\\xEF\\xBB\\xBF\""), 'CSV: BOM UTF-8; sel teks berawalan formula diberi kutip');
$csvT = substr($layanan, (int) strpos($layanan, 'private function csvTransaksi'), 1600);
$assert(!preg_match("/'referensi'|'catatan'|'alasan'/", $csvT) && !str_contains($repo . '', "x.referensi AS"), 'Rincian transaksi ekspor tanpa referensi, catatan, atau alasan');

// ===================================================================== X-4
echo PHP_EOL . '=== X-4. Web dan API ===' . PHP_EOL;
$hal = $baca('portal/bsa_laporan.php');
$eks = $baca('portal/bsa_laporan_ekspor.php');
$assert(str_contains($hal, "require_once __DIR__ . '/_bsa.php';") && str_contains($eks, "require_once __DIR__ . '/_bsa.php';"), 'Halaman laporan dan ekspor memakai penjaga _bsa.php (masuk, CSRF POST, no-store, hak)');
$assert(!str_contains($hal, '$_POST') && str_contains($hal, '<form') === false && str_contains($hal, 'ah_filter_open('), 'Halaman laporan hanya GET (saringan GET, tanpa POST)');
$assert(str_contains($eks, "header('Content-Type: text/csv; charset=UTF-8');") && str_contains($eks, 'Content-Disposition: attachment') && str_contains($eks, 'X-Content-Type-Options: nosniff'),
    'Ekspor web: text/csv lampiran + nosniff');
$api = $baca('api/v1/index.php');
$assert(str_contains($api, "\$method === 'GET' && \$path === '/v6/bsa/laporan'") && str_contains($api, "\$method === 'GET' && \$path === '/v6/bsa/laporan/ekspor'"), 'API: GET laporan dan GET ekspor');
$assert(str_contains($baca('portal/_bsa.php'), "\$laporan = \$fase3 ? ['bsa_laporan.php' => 'Laporan'] : [];"), 'Navigasi keuangan: "Laporan" bila tagihan dan tabungan terpasang (internal dan wali)');
$assert(str_contains($baca('docs/api-v1.md'), '/v6/bsa/laporan'), 'docs/api-v1.md mendokumentasikan laporan dan ekspor');

// ===================================================================== X-5
echo PHP_EOL . '=== X-5. Alat rilis ===' . PHP_EOL;
foreach (['bin/_v6_rilis.php', 'bin/v6_preflight.php', 'bin/v6_verify.php', 'bin/v6_manifest.php', 'bin/v6_backup_restore_drill.php'] as $f) {
    $isi = $baca($f);
    $assert(str_contains($isi, "if (PHP_SAPI !== 'cli') {"), "$f hanya CLI");
}
foreach (['bin/_v6_rilis.php', 'bin/v6_preflight.php', 'bin/v6_verify.php', 'bin/v6_manifest.php'] as $f) {
    $sql = implode("\n", preg_match_all('/"([^"]*)"|\'([^\']*)\'/', $baca($f), $m) ? array_merge($m[1], $m[2]) : []);
    $assert(preg_match('/\b(INSERT INTO|UPDATE \w+ SET|DELETE FROM|DROP (TABLE|DATABASE)|ALTER TABLE|TRUNCATE)\b/i', $sql) === 0, "$f hanya membaca (tanpa INSERT/UPDATE/DELETE/DDL)");
}
$drill = $baca('bin/v6_backup_restore_drill.php');
$assert(str_contains($drill, "getenv('V6_DRILL') !== '1'") && str_contains($drill, "'/^test_[A-Za-z0-9_]*_test\$/D'") && str_contains($drill, "=== 'production'"), 'Latihan restore: V6_DRILL=1, hanya DB test_*_test, menolak APP_ENV=production');
$manifest = json_decode($baca('docs/phase-v6-5/manifest-migrasi.json'), true);
$segar = json_decode((string) shell_exec('php ' . escapeshellarg($root . '/bin/v6_manifest.php')), true);
$assert(is_array($manifest) && $manifest['migrations'] === ($segar['migrations'] ?? null) && $manifest['rollbacks'] === ($segar['rollbacks'] ?? null) && count($manifest['migrations']) === 29 && count($manifest['rollbacks']) === 6,
    'Manifest rilis ter-commit = hash 29 migrasi + 6 rollback di disk');
$verify = $baca('bin/v6_verify.php');
foreach (['Saldo akun = setoran − penarikan − belanja', 'Setiap tagihan: total − Σ pembayaran sah = sisa', 'Penerimaan kas baru laporan', 'Setiap koreksi tabungan punya audit',
    'Setiap pembatalan/penolakan pembayaran: aktor, waktu, alasan, asal, dan audit', '0 notifikasi V6 kanal WhatsApp', 'Manifest memuat tepat seluruh tabel lama'] as $label) {
    $assert(str_contains($verify, $label), "Verifier memeriksa: $label");
}
foreach (['runbook-rilis-pemulihan.md', 'checklist-smoke.md', 'README.md', 'perintah-uji.sh', 'perintah-regresi.sh'] as $f) {
    $assert(is_file($root . '/docs/phase-v6-5/' . $f), "docs/phase-v6-5/$f ada");
}
$checklist = $baca('docs/phase-v6-5/checklist-smoke.md');
$assert($checklist !== '' && !str_contains(strtolower($checklist), '| lulus produksi') && str_contains($checklist, 'Menunggu pelaksanaan'), 'Checklist smoke: kolom produksi "Menunggu pelaksanaan", tanpa klaim lulus produksi');

// ===================================================================== X-6
echo PHP_EOL . '=== X-6. Aplikasi ===' . PHP_EOL;
if (!is_dir($mobile . '/src/app/keuangan')) {
    echo "[dilewati] Checkout mobile tidak ditemukan ($mobile)\n";
} else {
    $layar = (string) @file_get_contents($mobile . '/src/app/keuangan/laporan.tsx');
    $klien = (string) @file_get_contents($mobile . '/src/api/keuangan.ts');
    $tata = (string) @file_get_contents($mobile . '/src/app/keuangan/_layout.tsx');
    $assert($layar !== '' && str_contains($layar, 'keuangan.laporan(') && !preg_match('/keuangan\.(setoran|keluar|bayar|koreksi|putus|revisi|batal|terbitkan)\(/', $layar), 'Layar laporan hanya memanggil GET laporan');
    $assert(!preg_match('/console\.|AsyncStorage|SecureStore|FileSystem|Sharing|localStorage/', $layar), 'Layar laporan tanpa log, penyimpanan, atau berkas');
    $assert(str_contains($klien, "laporan: (f: { dari: string; sampai: string; santri?: number; q?: string }, page = 1) =>") && !str_contains($klien, '/laporan/ekspor'), 'Klien: laporan JSON saja; aplikasi tidak mengunduh ekspor');
    $assert(str_contains($tata, '<Stack.Screen name="laporan"') && str_contains($tata, 'preventScreenCaptureAsync'), 'Layar laporan di navigator keuangan yang dilindungi tangkapan layar (T-1)');
    $assert(str_contains($layar, 'usePrivateResource') && str_contains($layar, 'key={profile?.id}'), 'Data hanya di memori layar; berganti akun me-reset layar');
}

// ===================================================================== X-7
echo PHP_EOL . '=== X-7. Lint PHP ===' . PHP_EOL;
$php = array_filter($berubah, static fn (string $f): bool => str_ends_with($f, '.php') && is_file($root . '/' . $f));
$salah = [];
foreach ($php as $f) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $o, $k);
    if ($k !== 0) {
        $salah[] = $f;
    }
}
$assert(count($php) >= 15 && $salah === [], 'Lint ' . count($php) . ' berkas PHP baru/berubah' . ($salah === [] ? '' : ': ' . implode(', ', $salah)));

echo PHP_EOL . "Ringkasan: {$lulus} lulus, " . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
