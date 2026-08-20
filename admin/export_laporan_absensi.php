<?php

declare(strict_types=1);

require_once __DIR__ . '/_guard.php';

use App\Api\ApiException;
use App\Report\CsvExport;

try {
    $report = report_service()->exportRows($_GET, $currentUser);
} catch (ApiException $exception) {
    http_response_code($exception->status());
    exit($exception->getMessage());
}

$filename = sprintf('laporan-absensi-%s-%s.csv', $report['filters']['date_from'], $report['filters']['date_to']);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
echo CsvExport::encode($report['items']);
