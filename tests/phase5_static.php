<?php

declare(strict_types=1);

use App\Report\CsvExport;
use App\Report\PrintRenderer;

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) { $failures[] = $message; }
};
$source = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$repository = $source('app/Report/ReportRepository.php');
$filter = $source('app/Report/ReportFilter.php');
$service = $source('app/Report/ReportService.php');
$router = $source('api/v1/index.php');
$admin = $source('admin/admin_laporan_absensi.php');
$print = $source('app/Report/PrintRenderer.php');
$layout = $source('app/Report/PrintLayout.php');
$migration = $source('database/migrations/005_phase5_reporting_indexes.sql');
$rollback = $source('database/rollbacks/005_phase5_reporting_indexes.sql');

$assert(str_contains($repository, 'UNION ALL') && str_contains($repository, 'COUNT(DISTINCT report.meeting_id)'), 'Ringkasan guru dan santri berasal dari sumber baris detail yang sama');
$assert(str_contains($repository, '$statement->execute($params)') && !preg_match('/\$_(?:GET|POST|REQUEST)/', $repository), 'Query laporan memakai parameter terikat dan repository tidak membaca input global');
$assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b\s+(?:INTO|FROM|[a-z_])/i', preg_replace('/explainPage|updated_at/i', '', $repository)), 'Repository laporan tidak menulis atau menghapus data absensi');
$assert(str_contains($filter, '$this->teacherId !== null && $this->teacherId !== $guruId') && str_contains($filter, '$guruId'), 'Filter guru dipaksa ke guru dari token/session pada sisi server');
$assert(str_contains($service, "in_array('admin'") && str_contains($service, "(int) \$meeting['id_guru'] !== \$guruId"), 'Detail pertemuan memeriksa role dan kepemilikan di server');

foreach (['date_from', 'date_to', 'academic_year_id', 'teacher_id', 'class_id', 'schedule_id', 'status'] as $required) {
    $assert(str_contains($admin, 'name="' . $required . '"'), 'UI admin menyediakan filter ' . $required);
}
foreach (['/reports', '/reports/filters', '/reports/print', '/reports/meetings/'] as $route) {
    $assert(str_contains($router, $route), 'API V1 menyediakan endpoint ' . $route);
}

$csv = CsvExport::encode([[
    'meeting_id' => 1, 'meeting_date' => '2026-08-20', 'schedule_id' => 2,
    'academic_year' => '2026/2027 - Ganjil', 'teacher_name' => '=FORMULA', 'class_name' => 'A',
    'subject' => 'Fikih', 'book' => 'Kitab', 'place' => 'Masjid', 'subject_type' => 'Santri',
    'identity_number' => '+123', 'subject_name' => '@nama', 'attendance_status' => 'Hadir',
    'notes' => '-bahaya', 'recorder_name' => 'Admin', 'recorded_at' => '2026-08-20 10:00:00',
    'updated_at' => '2026-08-20 10:00:00',
]]);
$firstLine = strtok(substr($csv, 3), "\r\n");
$assert(str_starts_with($csv, "\xEF\xBB\xBF") && str_getcsv((string) $firstLine, ',', '"', '') === CsvExport::HEADERS, 'CSV memiliki BOM UTF-8 dan header terdokumentasi');
$assert(str_contains($csv, "'=FORMULA") && str_contains($csv, "'+123") && str_contains($csv, "'@nama") && str_contains($csv, "'-bahaya"), 'CSV menetralkan formula berbahaya untuk aplikasi spreadsheet');

$html = PrintRenderer::report([
    'active_filters' => ['Rentang tanggal' => '2026-08-01 s.d. 2026-08-20'],
    'items' => [],
    'summary' => ['meeting_count' => 0, 'detail_count' => 0, 'statuses' => array_fill_keys(['Hadir','Terlambat','Izin','Sakit','Alpa'], 0)],
    'generated_at' => '2026-08-20 12:00:00 WIB', 'created_by' => 'Admin Uji',
]);
foreach (['Pesantren Al Hasan', 'Laporan Absensi Pengajian', 'Rentang tanggal', 'Dibuat:', 'Pembuat:', '@media print', '.report-nav,.petunjuk-cetak{display:none'] as $required) {
    $assert(str_contains($html, $required), 'HTML cetak memuat ' . $required);
}

// Nomor halaman: laporan kosong pun harus berupa SATU halaman bernomor 1.
// Pemeriksaan lama hanya mencari string `counter(page)`; itu tidak pernah
// membuktikan apa pun karena `counter(page)` di dalam elemen `position:fixed`
// justru dievaluasi WebKit menjadi 0 dan mencetak "Halaman 0".
$assert(str_contains($html, 'Halaman 1 dari 1'), 'HTML cetak memberi nomor halaman "Halaman 1 dari 1" pada laporan kosong');
$assert(!str_contains($html, 'Halaman 0'), 'HTML cetak tidak pernah memuat "Halaman 0"');
$assert(substr_count($html, '<section class="lembar">') === 1, 'Laporan kosong menghasilkan tepat satu lembar');
$assert(
    !str_contains($html, 'counter(page)') && !str_contains($html, 'position:fixed'),
    'Nomor halaman dihitung server, bukan oleh counter(page) di elemen position:fixed'
);

// Nomor halaman harus benar pula pada dokumen banyak halaman.
$banyak = [];
for ($i = 0; $i < 400; $i++) {
    $banyak[] = [
        'meeting_date' => '2026-08-20', 'schedule_id' => 7, 'subject' => 'Fikih Muamalah Kontemporer',
        'teacher_name' => 'USTADZ ABDURAHMAN', 'class_name' => 'IBTIDA PA', 'subject_type' => 'Santri',
        'identity_number' => '2026' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'subject_name' => 'MUHAMMAD FATHUROHMAN',
        'attendance_status' => 'Hadir', 'notes' => 'Mengikuti seluruh rangkaian pengajian tanpa keterlambatan.',
        'recorder_name' => 'Admin Uji', 'updated_at' => '2026-08-20 10:00:00',
    ];
}
$htmlBanyak = PrintRenderer::report([
    'active_filters' => ['Rentang tanggal' => '2026-08-01 s.d. 2026-08-20'],
    'items' => $banyak,
    'summary' => ['meeting_count' => 400, 'detail_count' => 400, 'statuses' => array_fill_keys(['Hadir','Terlambat','Izin','Sakit','Alpa'], 80)],
    'generated_at' => '2026-08-20 12:00:00 WIB', 'created_by' => 'Admin Uji',
]);
$jumlahLembar = substr_count($htmlBanyak, '<section class="lembar">');
$assert($jumlahLembar > 1, 'Dokumen 400 baris terpecah menjadi lebih dari satu lembar (' . $jumlahLembar . ')');
$assert(!str_contains($htmlBanyak, 'Halaman 0'), 'Dokumen banyak halaman tidak memuat "Halaman 0"');
preg_match_all('/Halaman (\d+) dari (\d+)/', $htmlBanyak, $nomor);
$assert(
    $nomor[1] === array_map('strval', range(1, $jumlahLembar))
        && $nomor[2] === array_fill(0, $jumlahLembar, (string) $jumlahLembar),
    'Nomor halaman berurutan 1..n dan total sama dengan jumlah lembar'
);

$assert(str_contains($layout, 'A4 landscape') && str_contains($layout, 'table-layout:fixed') && str_contains($layout, 'break-inside:avoid'), 'CSS cetak menjaga kolom utama dan baris pada halaman landscape');
$assert(
    !str_contains($layout, 'overflow-wrap:anywhere') && str_contains($layout, 'overflow-wrap:break-word'),
    'CSS cetak tidak memakai overflow-wrap:anywhere yang memotong kata di tengah'
);
$assert(str_contains($html, 'Lanskap (Landscape)'), 'Halaman cetak memberi petunjuk orientasi lanskap');
$assert(str_contains($migration, 'pertemuan_date_schedule_report_index') && str_contains($migration, 'absensi_guru_status_meeting_report_index') && str_contains($migration, 'absensi_santri_status_meeting_report_index'), 'Migrasi indeks Fase 5 mengikuti pola scan EXPLAIN');
$assert(!preg_match('/\b(?:DELETE|TRUNCATE|DROP\s+TABLE)\b/i', $migration) && substr_count($rollback, 'DROP INDEX') === 3, 'Migrasi indeks aditif dan rollback hanya melepas indeks');

$mobileRoot = getenv('MOBILE_APP_ROOT') ?: dirname($root, 4) . '/alhasanApps';
$mobileReport = (string) file_get_contents($mobileRoot . '/src/app/(app)/(reports)/reports.tsx');
$mobileDocument = (string) file_get_contents($mobileRoot . '/src/report/report-document.ts');
$mobilePackage = (string) file_get_contents($mobileRoot . '/package.json');
$assert(str_contains($mobileReport, 'api.report(') && str_contains($mobileReport, "pathname: '/report/[id]'"), 'Aplikasi guru menyediakan laporan dan detail pertemuan');
$assert(str_contains($mobileDocument, 'Print.printAsync') && str_contains($mobileDocument, 'Print.printToFileAsync') && str_contains($mobileDocument, 'Sharing.shareAsync'), 'Aplikasi dapat membuka dialog cetak dan berbagi PDF');
$assert(str_contains($mobilePackage, '"expo-print": "~57.') && str_contains($mobilePackage, '"expo-sharing": "~57.'), 'Dependency cetak dan berbagi mengikuti Expo 57');

foreach (['docs/api-v1.md', 'docs/phase-5/release-runbook.md', 'docs/phase-5/csv-format.md', 'docs/phase-5/acceptance-checklist.md'] as $path) {
    $assert(is_file($root . '/' . $path), 'Dokumentasi Fase 5 tersedia: ' . $path);
}

exit($failures === [] ? 0 : 1);
