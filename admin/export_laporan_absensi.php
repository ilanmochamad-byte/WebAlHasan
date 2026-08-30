<?php

declare(strict_types=1);

/**
 * Ekspor CSV laporan kehadiran.
 *
 * Memakai `ReportFilter` dan repository yang SAMA dengan halaman laporan,
 * termasuk pilihan penyajian (`subject_scope`). Default halaman web adalah
 * `santri`, sama seperti tampilan awal layar, sehingga jumlah baris CSV tidak
 * mungkin berbeda dari yang dilihat pengguna. Default REST API tetap
 * `gabungan` dan tidak berubah.
 */

require_once __DIR__ . '/_laporan_guard.php';

use App\Api\ApiException;
use App\Report\ReportFilter;
use App\Report\CsvExport;
use App\Http\JsonResponse;

try {
    $report = report_service()->exportCsvRows($_GET, $currentUser, ReportFilter::SCOPE_SANTRI);
} catch (ApiException $exception) {
    JsonResponse::error($exception->errorCode(), $exception->getMessage(), $exception->status(), $exception->details());
}

$filename = sprintf('laporan-absensi-%s-%s.csv', $report['filters']['date_from'], $report['filters']['date_to']);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
echo CsvExport::encode($report['items']);
