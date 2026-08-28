<?php

declare(strict_types=1);

/**
 * Pengujian performa laporan V2 Fase 5.
 *
 * Kriteria penerimaan PRD: "Halaman pertama laporan selesai maksimal 2 detik
 * pada fixture minimal 1.000 pengajuan."
 *
 * Berkas ini adalah GERBANG OTOMATIS untuk kriteria tersebut. Ia:
 *   1. menolak berjalan bila fixture kurang dari 1.000 pengajuan — sehingga
 *      target tidak dapat "lulus" secara palsu pada basis data kosong;
 *   2. mengukur pekerjaan halaman pertama yang SESUNGGUHNYA (ringkasan +
 *      median durasi + detail satu halaman), bukan satu query pilihan;
 *   3. mengukur untuk seluruh cakupan peran, bukan hanya admin;
 *   4. memakai waktu TERBURUK dari beberapa pengulangan, bukan yang terbaik.
 *
 * Prasyarat:
 *   V2_PHASE5_FIXTURE=1 php bin/v2_phase5_fixture.php --jumlah=1000
 *
 * Jalankan:
 *   V2_PHASE5_RUN_PERF=1 php tests/v2_phase5_performance.php
 */

$root = dirname(__DIR__);
if (getenv('V2_PHASE5_RUN_PERF') !== '1') {
    fwrite(STDOUT, "[lewati] Set V2_PHASE5_RUN_PERF=1 dan siapkan fixture bin/v2_phase5_fixture.php.\n");
    exit(77);
}

require_once $root . '/app/bootstrap.php';

use App\Report\IzinReportFilter;

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Pengujian performa ditolak: DB_NAME wajib berakhiran _test.\n");
    exit(2);
}

/** Ambang PRD: halaman pertama laporan maksimal 2 detik. */
const AMBANG_MS = 2000.0;
/** Minimum data uji menurut PRD Fase 5 §6. */
const MINIMUM_PENGAJUAN = 1000;

$db = app_db();
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};

$totalPengajuan = (int) ($db->query('SELECT COUNT(*) FROM izin_pengajuan')?->fetch_row()[0] ?? 0);
$totalKeputusan = (int) ($db->query('SELECT COUNT(*) FROM izin_keputusan')?->fetch_row()[0] ?? 0);

echo '=== Performa laporan V2 Fase 5 ===' . PHP_EOL;
echo 'Database       : ' . app_config('database.database') . PHP_EOL;
echo 'izin_pengajuan : ' . $totalPengajuan . ' baris' . PHP_EOL;
echo 'izin_keputusan : ' . $totalKeputusan . ' baris' . PHP_EOL;
echo 'PHP / server   : ' . PHP_VERSION . ' / ' . $db->server_info . PHP_EOL;
echo 'Ambang PRD     : ' . (int) AMBANG_MS . ' ms' . PHP_EOL . PHP_EOL;

if ($totalPengajuan < MINIMUM_PENGAJUAN) {
    fwrite(STDERR, sprintf(
        "Fixture kurang dari %d pengajuan (aktual %d). Jalankan lebih dulu:\n"
        . "  V2_PHASE5_FIXTURE=1 php bin/v2_phase5_fixture.php --jumlah=1000\n",
        MINIMUM_PENGAJUAN,
        $totalPengajuan
    ));
    exit(2);
}
$assert(true, 'Fixture memenuhi minimum ' . MINIMUM_PENGAJUAN . ' pengajuan (' . $totalPengajuan . ')');

$repository = izin_report_repository();
$zona = (string) app_config('timezone');

$scope = static fn (string $mode, array $extra = []): array => array_merge([
    'mode' => $mode, 'pengurus_id' => null, 'guru_id' => null, 'wali_id' => null,
    'label' => 'Uji performa ' . $mode,
], $extra);

$pengurusId = (int) ($db->query("SELECT id FROM pengurus WHERE is_active = 1 ORDER BY id LIMIT 1")?->fetch_row()[0] ?? 0);
$guruId = (int) ($db->query("SELECT id FROM guru WHERE is_active = 1 ORDER BY id LIMIT 1")?->fetch_row()[0] ?? 0);
$waliId = (int) ($db->query("SELECT id FROM wali WHERE is_active = 1 ORDER BY id LIMIT 1")?->fetch_row()[0] ?? 0);
$kamarId = (int) ($db->query('SELECT id FROM kamar ORDER BY id LIMIT 1')?->fetch_row()[0] ?? 0);

$rentang = ['date_from' => '2024-01-01', 'date_to' => '2028-12-31'];

$skenario = [
    'admin — rentang penuh' => [$scope('admin'), $rentang],
    'admin — status Disetujui' => [$scope('admin'), $rentang + ['status' => 'Disetujui']],
    'admin — basis keputusan' => [$scope('admin'), $rentang + ['basis_tanggal' => 'keputusan']],
    'admin — durasi >= 24 jam' => [$scope('admin'), $rentang + ['durasi_min_jam' => '24']],
    'admin — filter kamar' => [$scope('admin'), $rentang + ['kamar_id' => (string) $kamarId]],
    'admin — pencarian teks' => [$scope('admin'), $rentang + ['q' => 'fixture']],
    'admin — halaman ke-10' => [$scope('admin'), $rentang + ['page' => '10']],
    'pengurus — cakupan sendiri' => [$scope('pengurus', ['pengurus_id' => $pengurusId]), $rentang],
    'murobi — cakupan sendiri' => [$scope('murobi', ['guru_id' => $guruId]), $rentang],
    'orang tua — cakupan sendiri' => [$scope('orang_tua', ['wali_id' => $waliId]), $rentang],
];

$ulang = max(3, (int) (getenv('V2_PHASE5_PERF_ULANG') ?: 5));
$terburukGlobal = 0.0;

foreach ($skenario as $nama => [$cakupan, $input]) {
    $filter = IzinReportFilter::fromInput($input, $zona)->forScope($cakupan);

    $waktu = [];
    for ($i = 0; $i < $ulang; $i++) {
        $mulai = microtime(true);
        // Persis pekerjaan halaman pertama laporan.
        $repository->summary($filter);
        $repository->decisionDuration($filter);
        $repository->page($filter);
        $waktu[] = (microtime(true) - $mulai) * 1000;
    }
    sort($waktu);
    $terburuk = $waktu[count($waktu) - 1];
    $median = $waktu[intdiv(count($waktu), 2)];
    $terburukGlobal = max($terburukGlobal, $terburuk);

    $assert(
        $terburuk <= AMBANG_MS,
        sprintf('%-30s terburuk %7.1f ms (median %7.1f ms) <= %d ms', $nama, $terburuk, $median, (int) AMBANG_MS)
    );
}

// Ekspor penuh tidak termasuk kriteria "halaman pertama", tetapi tetap diukur
// agar regresi besar terlihat sebelum sampai ke pengguna.
$filterEkspor = IzinReportFilter::fromInput($rentang, $zona)->forScope($scope('admin'));
$mulai = microtime(true);
$barisEkspor = $repository->allRows($filterEkspor);
$durasiEkspor = (microtime(true) - $mulai) * 1000;
echo PHP_EOL;
printf('Ekspor penuh (%d baris): %.1f ms%s', count($barisEkspor), $durasiEkspor, PHP_EOL);
$assert(
    count($barisEkspor) > 0,
    'Ekspor penuh mengembalikan baris (' . count($barisEkspor) . ')'
);

echo PHP_EOL;
printf('Waktu terburuk seluruh skenario halaman pertama: %.1f ms%s', $terburukGlobal, PHP_EOL);

if ($failures !== []) {
    echo PHP_EOL . 'GAGAL (' . count($failures) . '):' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}
echo 'SELURUH SKENARIO PERFORMA FASE 5 MEMENUHI TARGET 2 DETIK.' . PHP_EOL;
exit(0);
