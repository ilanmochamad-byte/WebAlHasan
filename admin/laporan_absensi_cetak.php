<?php

declare(strict_types=1);

require_once __DIR__ . '/_guard.php';

use App\Api\ApiException;
use App\Report\PrintRenderer;

try {
    $report = report_service()->exportRows($_GET, $currentUser);
} catch (ApiException $exception) {
    http_response_code($exception->status());
    exit(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'));
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
echo PrintRenderer::report($report);
