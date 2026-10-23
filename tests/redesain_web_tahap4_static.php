<?php

declare(strict_types=1);

/**
 * Uji statis redesain website Tahap 4 — perizinan, pengajian & cetak.
 *
 * Memeriksa kode tanpa basis data: sintaks, pola bersama, batas perubahan
 * hak akses keputusan H, dan bahwa keputusan M, O, R, S, T, AS diwujudkan di
 * tempat yang benar (server, bukan hanya tampilan).
 *
 *   php tests/redesain_web_tahap4_static.php
 */

$root = dirname(__DIR__);
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$berkas = [
    'portal/_ui.php', 'portal/izin.php', 'portal/izin_ringkasan.php', 'portal/izin_antrean.php', 'portal/izin_buat.php', 'portal/izin_detail.php',
    'portal/izin_aksi.php', 'portal/laporan.php', 'portal/v3_konseling_cetak.php',
    'admin/admin_pengajian.php', 'admin/_pengajian_jadwal.php', 'admin/_pengajian_pertemuan.php', 'admin/admin_laporan_absensi.php',
    'admin/laporan_absensi_detail.php', 'admin/_laporan_guard.php',
    'app/Ui/functions.php', 'app/Ui/Periode.php', 'app/Ui/Navigation.php', 'app/Ui/Status.php',
    'app/Izin/IzinRepository.php', 'app/Izin/IzinService.php', 'app/Izin/IzinWorkflowService.php',
    'app/Report/ReportFilter.php', 'app/Report/ReportService.php', 'app/Report/PrintLayout.php', 'app/Report/PrintRenderer.php',
    'app/Report/IzinPrintRenderer.php', 'app/Report/IzinReportRepository.php', 'app/Report/IzinReportService.php', 'app/Schedule/ScheduleRepository.php',
    'tests/redesain_web_tahap4_seed.php', 'tests/redesain_web_tahap4_http.php', 'tests/redesain_web_tahap4_cetak.php',
];

echo '=== S4-1. Sintaks ===' . PHP_EOL;
foreach ($berkas as $path) {
    exec('php -l ' . escapeshellarg($root . '/' . $path) . ' 2>&1', $out, $kode);
    $assert($kode === 0, "php -l $path");
}
foreach (['assets/ui/alhasan.js', 'tests/browser/uji-redesain-web-tahap4.mjs'] as $path) {
    exec('node --check ' . escapeshellarg($root . '/' . $path) . ' 2>&1', $out, $kode);
    $assert($kode === 0, "node --check $path");
}

echo PHP_EOL . '=== S4-2. Tanpa dialog bawaan, tanpa label versi ===' . PHP_EOL;
foreach (array_filter($berkas, static fn (string $p): bool => str_starts_with($p, 'portal/') || str_starts_with($p, 'admin/')) as $path) {
    $isi = $baca($path);
    $assert(!preg_match('/\b(confirm|alert)\s*\(/', $isi), "$path tanpa confirm()/alert()");
}
foreach (['portal/izin.php', 'portal/izin_ringkasan.php', 'portal/izin_antrean.php', 'portal/izin_detail.php', 'portal/laporan.php'] as $path) {
    $isi = $baca($path);
    $assert(!preg_match('/>\s*V2\s*</', $isi) && !str_contains($isi, "'Data warisan'</") && !str_contains($isi, 'Data warisan</span>'), "$path tanpa label \"V2\"/\"Data warisan\" di layar");
}

echo PHP_EOL . '=== S4-3. Keputusan H: batas perubahan hak akses ===' . PHP_EOL;
$guard = $baca('admin/_laporan_guard.php');
$assert(str_contains($guard, "featureSource(\$currentUser, App\\Penugasan\\PenugasanJenis::CAP_RAPOR_VERIFIKASI)") && str_contains($guard, 'SUMBER_PENUGASAN') && str_contains($guard, "in_array('pengurus'"), 'Guard laporan: Bagian Pendidikan = pengurus + penugasan Pendidikan milik sendiri');
$assert(str_contains($guard, "['GET', 'HEAD']") && str_contains($guard, 'http_response_code(405)'), 'Guard laporan tetap hanya-baca (GET/HEAD)');
$pemasang = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
    $p = str_replace($root . '/', '', $f->getPathname());
    if (!str_ends_with($p, '.php') || preg_match('#^(vendor|tests|docs|output|storage)/#', $p)) {
        continue;
    }
    if (preg_match('/\[\s*ReportFilter::BACA_SEMUA_JADWAL\s*\]\s*=/', (string) file_get_contents($f->getPathname()))) {
        $pemasang[] = $p;
    }
}
$assert($pemasang === ['admin/_laporan_guard.php'], 'Penanda baca-semua hanya dipasang di admin/_laporan_guard.php (' . implode(', ', $pemasang) . ')');
$assert(!str_contains($baca('api/v1/index.php'), 'BACA_SEMUA') && !str_contains($baca('app/Api/TeacherService.php'), 'BACA_SEMUA'), 'REST API tidak memasang penanda (kontrak tidak melebar)');
$filter = $baca('app/Report/ReportFilter.php');
$layanan = $baca('app/Report/ReportService.php');
$assert(str_contains($filter, 'if (self::bacaSemua($user)) {') && substr_count($layanan, 'ReportFilter::bacaSemua($user)') === 2, 'Cakupan laporan (filter, pilihan, detail pertemuan) memakai satu pemeriksaan bacaSemua');
$pengajian = $baca('admin/admin_pengajian.php');
$assert(str_contains($pengajian, "in_array('admin', \$currentUser['roles'], true) && !in_array('guru', \$currentUser['roles'], true)") && !str_contains($pengajian, 'BACA_SEMUA'), 'Modul jadwal/pertemuan tetap admin/guru; Bagian Pendidikan tidak masuk');
$nav = $baca('app/Ui/Navigation.php');
$assert(str_contains($nav, "self::item('absensi', 'Laporan absensi pengajian'") && str_contains($nav, '$c->own(PenugasanJenis::CAP_RAPOR_VERIFIKASI)') && str_contains($nav, "['label' => 'Laporan', 'items' => \$laporan]"), 'Menu "Laporan absensi pengajian" di grup Laporan, hanya untuk penugasan Pendidikan sendiri');

echo PHP_EOL . '=== S4-4. Peta menu & tab ===' . PHP_EOL;
$assert(str_contains($nav, "self::tab('Jadwal', '/admin/admin_pengajian.php'") && str_contains($nav, "self::tab('Pertemuan', '/admin/admin_pengajian.php?tab=pertemuan'") && str_contains($nav, "self::tab('Kehadiran', '/admin/admin_laporan_absensi.php'"), 'Pengajian: tab Jadwal · Pertemuan · Kehadiran');
$assert(str_contains($nav, "self::tab('Aktif', '/portal/izin.php'") && str_contains($nav, "self::tab('Riwayat', '/portal/izin.php?tab=riwayat'"), 'Izin anak (O): tab Aktif · Riwayat');
$assert(!str_contains($pengajian, "'tabs' => \$tabs"), 'Modul Pengajian tidak lagi membuat tab halaman sendiri (tab modul dari peta menu)');

echo PHP_EOL . '=== S4-5. Keputusan perizinan (M, N, O, Q) ===' . PHP_EOL;
$buat = $baca('portal/izin_buat.php');
$assert(substr_count($buat, 'data-ah-prefix="<?= portal_e($cepat) ?>: "') === 1 && str_contains($buat, "['Pulang', 'Berobat', 'Acara keluarga', 'Lainnya']"), 'M: alasan cepat Pulang/Berobat/Acara keluarga/Lainnya mengisi awal teks');
exec('git -C ' . escapeshellarg($root) . ' diff --name-only 11ed4ba -- database 2>/dev/null', $diffDb);
$assert($diffDb === [], 'Tanpa perubahan skema/migrasi (database/ tidak berubah sejak Tahap 3)');
$aksi = $baca('portal/izin_aksi.php');
$assert(str_contains($aksi, "\$keAntrean = (\$_POST['kembali'] ?? '') === 'antrean';") && str_contains($aksi, "\$keAntrean && \$aksi === 'putuskan' ? '/portal/izin_antrean.php' : '/portal/izin_detail.php'"), 'N: keputusan dari antrean kembali ke antrean; tujuan lain tidak diterima');
$antrean = $baca('portal/izin_antrean.php');
$assert(str_contains($antrean, 'Berikutnya: #') && !preg_match('/Location:.*berikutnya/i', $antrean), 'N: tombol "Berikutnya", tanpa pindah otomatis');
$repo = $baca('app/Izin/IzinRepository.php');
$assert(str_contains($repo, "(\$filters['aktif'] ?? '') === '1'") && str_contains($repo, 'function santriSemesterCount') && str_contains($repo, 'function approvedCovering'), 'O/Q/S: filter Aktif/Riwayat, jumlah semester, dan label izin dibaca di repositori');
$svc = $baca('app/Izin/IzinService.php');
$assert(str_contains($svc, 'throw IzinException::forbidden();') && str_contains($svc, 'pembimbingAssignmentFor') && str_contains($svc, 'murobiHasSantri'), 'Q: jumlah izin semester ini memeriksa cakupan akun di server');
$assert(str_contains($baca('app/Izin/IzinWorkflowService.php'), 'function routingPreview') && str_contains($buat, "izin_workflow_service()->routingPreview("), 'I-4: pratinjau tujuan memakai routing dan cakupan yang sama dengan pengajuan');

echo PHP_EOL . '=== S4-6. Pengajian (R, S, T) ===' . PHP_EOL;
$pertemuan = $baca('admin/_pengajian_pertemuan.php');
$jadwal = $baca('admin/_pengajian_jadwal.php');
$assert(!preg_match('/name="(status|kehadiran)/', $pertemuan) && str_contains($pertemuan, 'Kehadiran dicatat guru lewat aplikasi'), 'R: tidak ada isian kehadiran di web');
$assert(str_contains($pengajian, 'izin_service()->approvedCovering(') && str_contains($pertemuan, 'Sedang izin #'), 'S: label "Sedang izin" dari izin disetujui; status tidak diisi');
$assert(!preg_match('/ah_e\(\$(row|schedule|selected|meeting|selectedMeeting)\[\'hari\'\]\)/', $jadwal . $pertemuan) && substr_count($jadwal, 'ah_hari(') >= 5, 'T: hari ditampilkan lewat ah_hari() ("Minggu" → "Ahad")');
$fungsi = $baca('app/Ui/functions.php');
$assert(str_contains($fungsi, "\$hari === 'Minggu' ? 'Ahad' : \$hari") && str_contains($baca('app/Ui/Periode.php'), "((int) \$hari->format('N') - 1)"), 'T: Ahad dan pekan dimulai Senin');

echo PHP_EOL . '=== S4-7. Cetak (X-1, AS) ===' . PHP_EOL;
$layout = $baca('app/Report/PrintLayout.php');
$assert(str_contains($layout, 'public static function tandaTangan(') && str_contains($layout, 'TINGGI_TANDA_TANGAN_MM') && str_contains($layout, 'float $cadanganAkhirMm = 0.0'), 'PrintLayout: tanda tangan dan anggaran tinggi lembar terakhir');
foreach (['app/Report/IzinPrintRenderer.php', 'app/Report/PrintRenderer.php'] as $path) {
    $isi = $baca($path);
    $assert(str_contains($isi, "PrintLayout::tandaTangan('Bagian Pendidikan')") && str_contains($isi, 'PrintLayout::TINGGI_TANDA_TANGAN_MM') && str_contains($isi, 'Kode laporan') && str_contains($isi, 'PrintLayout::kop('), "$path: kop bersama, tanda tangan Bagian Pendidikan dianggarkan, kode laporan");
}
$assert(!str_contains(substr($layout, (int) strpos($layout, 'public static function cssDasar')), 'counter(page)') && str_contains($layout, 'public static function footerHalaman('), 'Penomoran tetap dihitung server (CSS cetak tanpa counter(page))');

echo PHP_EOL . '=== S4-8. Komponen bersama ===' . PHP_EOL;
$js = $baca('assets/ui/alhasan.js');
$css = $baca('assets/ui/alhasan.css');
foreach (['details[data-ah-dialog]', '[data-ah-prefix]', 'input[data-ah-option-filter]', '[data-ah-izin-info]', 'input[data-ah-list-filter]', '[data-ah-print]'] as $pola) {
    $assert(str_contains($js, $pola), "alhasan.js: $pola");
}
foreach (['.ah-split', '.ah-seg', '.ah-tl', '.ah-week', '.ah-actbar', '.ah-qlist', '.ah-icard', '.ah-stack', '.ah-filter__more', '.ah-ribbon'] as $kelas) {
    $assert(str_contains($css, $kelas . ' ') || str_contains($css, $kelas . '{') || str_contains($css, $kelas . ','), "alhasan.css: $kelas");
}

echo PHP_EOL . 'Ringkasan: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
