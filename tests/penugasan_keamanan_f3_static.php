<?php

declare(strict_types=1);

/**
 * Uji statis Fase 3 penugasan lintas guru/pengurus — penugasan Keamanan,
 * hanya baca (tanpa basis data).
 *
 *   php tests/penugasan_keamanan_f3_static.php
 *
 *   S-1 katalog: jenis Keamanan, capability tunggal, sebelum kelompok "modul mendatang"
 *   S-2 capability baca tidak dipakai jalur tulis mana pun
 *   S-3 halaman Keamanan: guard bersama, hanya GET/HEAD, tanpa formulir POST/CSRF/CSV
 *   S-4 layanan: penanda diperiksa ulang; mode keamanan = santri aktif + penyaring kerahasiaan murobi
 *   S-5 audit: rincian/lampiran/cetak diaudit; penyaring metadata `keamanan.*`
 *   S-6 kehadiran: pola keputusan H, CSV ditolak bagi Keamanan
 *   S-7 migrasi 033 aditif; rollback berpenjaga data
 *   S-8 batas Fase 3 terhadap baseline (tanpa Auth, akun, mobile, REST)
 *   S-9 lint PHP seluruh berkas baru/berubah
 */

$root = dirname(__DIR__);
$baseline = getenv('PKF3_BASELINE') ?: '6c9d544';
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $p): string => (string) file_get_contents($root . '/' . $p);

// ===================================================================== S-1
echo PHP_EOL . '=== S-1. Katalog ===' . PHP_EOL;
$jenis = $baca('app/Penugasan/PenugasanJenis.php');
$assert(str_contains($jenis, "public const KEAMANAN = 'keamanan';") && str_contains($jenis, "public const CAP_KEAMANAN_PANTAU = 'keamanan.pantau';"),
    'Jenis `keamanan` dan capability `keamanan.pantau`');
preg_match("/self::KEAMANAN => \\[(.*?)\\n        \\],/s", $jenis, $m);
$def = $m[1] ?? '';
$assert(str_contains($def, "'tabel' => 'keamanan_assignments'") && str_contains($def, "'cakupan' => self::CAKUPAN_SELURUH")
    && str_contains($def, "'wajib_tahun_aktif' => false") && str_contains($def, "'role_dasar' => 'pengurus'")
    && str_contains($def, "'capabilities' => [self::CAP_KEAMANAN_PANTAU]"), 'Definisi: tabel sendiri, seluruh santri aktif, tidak gugur semester, subjek pengurus, satu capability');
$assert(strpos($jenis, "        self::KEAMANAN,\n        self::PANITIA_PSB,") !== false && str_contains($jenis, 'public const MENDATANG = [self::PANITIA_PSB, self::BENDAHARA_PSB];'),
    'Keamanan diurutkan sebelum PSB; bukan "modul mendatang"');
$assert(str_contains($baca('app/Penugasan/PenugasanService.php'), '[PenugasanJenis::KESEHATAN, PenugasanJenis::KEAMANAN]'),
    'Pusat penugasan memeriksa keberadaan tabel Keamanan (berjalan sebelum 033/sesudah rollback)');

// ===================================================================== S-2
echo PHP_EOL . '=== S-2. Capability baca tidak dipakai jalur tulis ===' . PHP_EOL;
$pemakai = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr((string) $f, strlen($root) + 1);
    if (!str_ends_with($rel, '.php') || preg_match('#^(tests|vendor|docs|output|storage)/#', $rel)) {
        continue;
    }
    // Kode saja: komentar yang menyebut kelas/capability tidak dihitung sebagai pemakaian.
    $isi = implode('', array_map(static fn ($t): string => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
        token_get_all((string) file_get_contents((string) $f))));
    if (str_contains($isi, 'CAP_KEAMANAN_PANTAU') || str_contains($isi, "'keamanan.pantau'") || str_contains($isi, 'KeamananAkses')) {
        $pemakai[] = $rel;
    }
}
sort($pemakai);
$diharapkan = ['admin/_laporan_guard.php', 'app/Izin/IzinLaporanService.php', 'app/Keamanan/KeamananAkses.php', 'app/Keamanan/KeamananService.php',
    'app/Penugasan/PenugasanJenis.php', 'app/Ui/Navigation.php', 'app/V3/KonselingService.php', 'app/V3/PelanggaranService.php', 'portal/_keamanan.php'];
$assert($pemakai === $diharapkan, 'Capability/penanda Keamanan hanya dipakai di guard baca, layanan baca, katalog, dan menu' . ($pemakai === $diharapkan ? '' : ': ' . implode(', ', $pemakai)));
$keamananSvc = $baca('app/Keamanan/KeamananService.php') . $baca('app/Keamanan/KeamananAkses.php');
$assert(!preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/', $keamananSvc), 'Modul App\\Keamanan hanya membaca (tanpa INSERT/UPDATE/DELETE)');
foreach (['app/V3/PelanggaranService.php', 'app/V3/KonselingService.php'] as $f) {
    $isi = $baca($f);
    preg_match_all('/KeamananAkses::/', $isi, $semuaPakai);
    $readMode = substr($isi, (int) strpos($isi, 'private function readMode('));
    $assert(substr_count($readMode, 'KeamananAkses::diminta') === 1 && substr_count($isi, 'KeamananAkses::diminta') === 1,
        "$f: penanda hanya dibaca di readMode (jalur baca)");
}

// ===================================================================== S-3
echo PHP_EOL . '=== S-3. Halaman Keamanan ===' . PHP_EOL;
$guard = $baca('portal/_keamanan.php');
$assert(str_contains($guard, 'KeamananAkses::sah(capabilities(), $currentUser)') && str_contains($guard, "['GET', 'HEAD']")
    && str_contains($guard, 'http_response_code(405)') && strpos($guard, 'KeamananAkses::sah') < strpos($guard, 'http_response_code(405)')
    && str_contains($guard, "Cache-Control: private, no-store"), 'Guard: hak penugasan sendiri, hanya GET/HEAD (405), tanpa cache');
$halaman = glob($root . '/portal/keamanan_*.php') ?: [];
$assert(count($halaman) === 10, 'Sepuluh halaman Keamanan (izin 3, pelanggaran 4, konseling 3)');
foreach ($halaman as $h) {
    $isi = (string) file_get_contents($h);
    $nama = basename($h);
    $assert(str_contains($isi, "require_once __DIR__ . '/_keamanan.php';") && !preg_match('/method="post"/i', $isi) && !str_contains($isi, 'Csrf::')
        && !preg_match('/csv/i', preg_replace('/\/\*\*.*?\*\//s', '', $isi)) && !preg_match('/->(create|correct|cancel|acknowledge|decide|tinjau|buat|ekspor)\(/', $isi),
        "$nama: guard bersama, tanpa POST/CSRF/CSV/mutasi");
}

// ===================================================================== S-4
echo PHP_EOL . '=== S-4. Layanan dan cakupan ===' . PHP_EOL;
$akses = $baca('app/Keamanan/KeamananAkses.php');
$assert(str_contains($akses, '$capabilities->forget($userId);') && str_contains($akses, '[Capabilities::SUMBER_PENUGASAN, Capabilities::SUMBER_KEDUANYA]'),
    'Hak dihitung ulang; pengawasan admin (sumber admin) tidak sah');
foreach (['app/V3/PelanggaranRepository.php', 'app/V3/KonselingRepository.php'] as $f) {
    $isi = $baca($f);
    $assert(str_contains($isi, 'skx.is_active=1 AND skx.archived_at IS NULL'), "$f: mode keamanan = santri aktif");
}
$konRepo = $baca('app/V3/KonselingRepository.php');
$privasi = substr($konRepo, (int) strpos($konRepo, 'private function privacySql('), 600);
$assert(str_contains($privasi, "if(\$mode==='admin')") && str_contains($privasi, "if(\$mode==='pembimbing')") && str_contains($privasi, "return [\"k.kerahasiaan<>'Rahasia'\",[]];"),
    'Konseling: penyaring kerahasiaan bawaan (murobi) berlaku untuk Keamanan');
$pelRepo = $baca('app/V3/PelanggaranRepository.php');
$assert(str_contains($pelRepo, "default => [\"k.kerahasiaan<>'Rahasia'\", []],"), 'Tautan konseling pada pelanggaran: Rahasia tersaring untuk Keamanan');
$assert(str_contains($baca('app/V3/KonselingService.php'), "!in_array(\$mode,['murobi',KeamananAkses::MODE],true)||(string)\$row['kerahasiaan']==='Internal'"),
    'Rincian kasus: isi internal hanya untuk kasus Internal bagi Keamanan');
$lapRepo = $baca('app/Izin/IzinLaporanRepository.php');
$assert(str_contains($lapRepo, "'keamanan' => [['s.is_active = 1 AND s.archived_at IS NULL'], []],"), 'Laporan izin: cakupan keamanan = santri aktif, kedua asal');
$lapSvc = $baca('app/Izin/IzinLaporanService.php');
$assert(str_contains($lapSvc, 'if (KeamananAkses::diminta($user)) {') && str_contains($lapSvc, 'KeamananAkses::sah($this->capabilities, $user)')
    && str_contains($lapSvc, "Ekspor CSV tidak tersedia bagi Keamanan"), 'Laporan izin: cakupan dipilih dari penanda + hak, bukan parameter; ekspor CSV ditolak');
$assert(str_contains($baca('app/Keamanan/KeamananService.php'), 'JOIN santri s ON s.id = p.santri_id AND s.is_active = 1 AND s.archived_at IS NULL'),
    'Rincian izin: santri aktif');

// ===================================================================== S-5
echo PHP_EOL . '=== S-5. Audit ===' . PHP_EOL;
$svc = $baca('app/Keamanan/KeamananService.php');
$assert(strpos($svc, "'keamanan.izin.dilihat'") < strpos($svc, "'keputusan' => \$this->izin->decision(\$id)") && str_contains($svc, "'keamanan.izin.dicetak'"),
    'Rincian izin diaudit sebelum isi dikembalikan; cetak diaudit');
$pel = $baca('app/V3/PelanggaranService.php');
$assert(str_contains($pel, "'keamanan.pelanggaran.dilihat'") && str_contains($pel, "'keamanan.pelanggaran.lampiran.diunduh'")
    && str_contains($baca('app/V3/KonselingService.php'), "'keamanan.konseling.kasus.dilihat'"), 'Rincian pelanggaran/kasus dan unduhan lampiran diaudit');
$audit = $baca('app/Audit/AuditLogger.php');
$assert(str_contains($audit, "str_starts_with(\$action, 'keamanan.')") && str_contains($audit, 'private function metadataKeamanan(array $data): array')
    && !str_contains(substr($audit, (int) strpos($audit, 'private function metadataKeamanan'), 400), "'alasan'"), 'Jejak `keamanan.*` disaring ke kunci metadata (tanpa alasan/uraian)');

// ===================================================================== S-6
echo PHP_EOL . '=== S-6. Kehadiran ===' . PHP_EOL;
$lg = $baca('admin/_laporan_guard.php');
$assert(str_contains($lg, '$isKeamanan = in_array(\'pengurus\', $currentUser[\'roles\'], true)') && str_contains($lg, 'App\\Ui\\Peran::active($currentUser) === App\\Ui\\Peran::PENGURUS')
    && str_contains($lg, "=== 'export_laporan_absensi.php'") && str_contains($lg, '$laporanCsv = !$kehadiranKeamanan;'), 'Guard laporan: Keamanan seluruh jadwal pada peran Pengurus; CSV ditolak');
$assert(str_contains($baca('admin/admin_laporan_absensi.php'), '($laporanCsv ? '), 'Tombol Ekspor CSV disembunyikan bagi Keamanan');
$nav = $baca('app/Ui/Navigation.php');
$assert(str_contains($nav, "['label' => 'Keamanan', 'items' => \$c->own(PenugasanJenis::CAP_KEAMANAN_PANTAU) ? self::keamanan() : []]")
    && substr_count($nav, "'label' => 'Keamanan'") === 1, 'Menu Keamanan hanya pada peran Pengurus, dari penugasan sendiri');

// ===================================================================== S-7
echo PHP_EOL . '=== S-7. Migrasi ===' . PHP_EOL;
$mig = $baca('database/migrations/033_penugasan_keamanan_fase3_keamanan.sql');
// Klausa definisi kolom/FK (`ON UPDATE CURRENT_TIMESTAMP`, `ON DELETE SET NULL`) bukan DML.
$tanpaKomentar = preg_replace(['/^--.*$/m', '/ON (UPDATE|DELETE) (CURRENT_TIMESTAMP|SET NULL)/'], '', $mig);
$assert(str_contains($tanpaKomentar, 'CREATE TABLE IF NOT EXISTS keamanan_assignments') && !preg_match('/\b(ALTER|UPDATE|DELETE|DROP|TRUNCATE|INSERT)\b/i', $tanpaKomentar),
    'Migrasi 033 aditif: satu tabel baru, tanpa ALTER/DML');
$rb = $baca('database/rollbacks/033_penugasan_keamanan_fase3_keamanan.sql');
$assert(strpos($rb, 'CHECK (jumlah = 0)') < strpos($rb, 'DROP TABLE IF EXISTS keamanan_assignments') && substr_count(preg_replace('/^--.*$/m', '', $rb), 'DROP TABLE IF EXISTS') === 1,
    'Rollback 033 berpenjaga data dan hanya melepas tabel Keamanan');

// ===================================================================== S-8
echo PHP_EOL . '=== S-8. Batas Fase 3 ===' . PHP_EOL;
exec('cd ' . escapeshellarg($root) . ' && git diff --name-only ' . escapeshellarg($baseline) . ' -- . 2>/dev/null', $berubah, $kode);
exec('cd ' . escapeshellarg($root) . ' && git ls-files --others --exclude-standard 2>/dev/null', $baru);
$semua = array_values(array_unique(array_merge($berubah, $baru)));
$boleh = static fn (string $f): bool => in_array($f, [
    'app/Penugasan/PenugasanJenis.php', 'app/Penugasan/PenugasanService.php', 'app/Penugasan/PenugasanRepository.php',
    'app/Izin/IzinLaporanService.php', 'app/Izin/IzinLaporanRepository.php',
    'app/V3/PelanggaranService.php', 'app/V3/PelanggaranRepository.php', 'app/V3/KonselingService.php', 'app/V3/KonselingRepository.php',
    'app/Audit/AuditLogger.php', 'app/Ui/Navigation.php', 'app/bootstrap.php',
    // Koreksi audit F-1/F-2: cakupan santri aktif dan audit rincian/cetak kehadiran.
    'app/Report/ReportFilter.php', 'app/Report/ReportRepository.php', 'app/Report/ReportService.php',
    'admin/laporan_absensi_detail.php', 'admin/laporan_absensi_cetak.php',
    'admin/_laporan_guard.php', 'admin/admin_laporan_absensi.php', 'admin/admin_guru.php',
    'database/migrations/033_penugasan_keamanan_fase3_keamanan.sql', 'database/rollbacks/033_penugasan_keamanan_fase3_keamanan.sql',
    'AGENTS.md',
], true) || str_starts_with($f, 'app/Keamanan/') || str_starts_with($f, 'portal/keamanan_') || $f === 'portal/_keamanan.php'
    || str_starts_with($f, 'tests/penugasan_keamanan_f3') || str_starts_with($f, 'tests/browser/uji-penugasan-keamanan-f3') || str_starts_with($f, 'tests/browser/tangkapan-penugasan-keamanan-f')
    || str_starts_with($f, 'docs/penugasan-keamanan/') || str_starts_with($f, 'docs/panduan-pengguna-v4/') || str_starts_with($f, 'docs/presentasi-al-hasan-v4/') || str_starts_with($f, 'output/');
$liar = array_values(array_filter($semua, static fn (string $f): bool => !$boleh($f)));
$assert($kode === 0 && $liar === [], 'Hanya berkas Fase 3 yang berubah terhadap baseline ' . $baseline . ($liar === [] ? '' : ': ' . implode(', ', $liar)));
$assert(array_filter($semua, static fn (string $f): bool => (bool) preg_match('#^(app/(Auth|Account|MasterData|Kesehatan|Bsa|Notification|Api|V4)/|api/|app/Izin/(IzinWorkflowService|IzinWaliService|IzinRepository|IzinRouter|PemisahanTugas)\.php)#', $f)) === [],
    'Auth, akun, master, kesehatan, BSA, notifikasi, REST, V4, dan alur tulis izin tidak diubah');

// ===================================================================== S-9
echo PHP_EOL . '=== S-9. Lint ===' . PHP_EOL;
foreach (array_filter($semua, static fn (string $f): bool => str_ends_with($f, '.php') && is_file($root . '/' . $f) && !str_starts_with($f, 'docs/') && !str_starts_with($f, 'output/')) as $berkas) {
    exec('php -l ' . escapeshellarg($root . '/' . $berkas) . ' 2>&1', $keluaran, $hasil);
    $assert($hasil === 0, "lint $berkas");
}

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal.' . PHP_EOL;
exit($gagal === [] ? 0 : 1);
