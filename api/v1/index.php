<?php

declare(strict_types=1);

use App\Api\ApiException;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Report\PrintRenderer;

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$requestPath = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/api/v1'), PHP_URL_PATH));
$prefixPosition = strpos($requestPath, '/api/v1');
$path = $prefixPosition === false ? '/' : substr($requestPath, $prefixPosition + strlen('/api/v1'));
$path = preg_replace('#^/index\.php#', '', $path) ?? $path;
$path = '/' . trim($path, '/');
if ($path === '//') {
    $path = '/';
}

try {
    if ($method === 'GET' && $path === '/') {
        JsonResponse::success(['name' => 'Al Hasan API', 'version' => 'v1']);
    }

    if ($method === 'POST' && $path === '/auth/login') {
        JsonResponse::success(api_auth_service()->login(Request::json()));
    }

    $user = api_authenticator()->requireScheduleAccess();

    if ($method === 'GET' && $path === '/profile') {
        JsonResponse::success(api_auth_service()->profile($user));
    }
    if ($method === 'POST' && $path === '/auth/logout') {
        api_auth_service()->logout($user);
        JsonResponse::success(['message' => 'Logout berhasil.']);
    }
    if ($method === 'GET' && $path === '/schedules/today') {
        JsonResponse::success(teacher_api_service()->today($user));
    }
    if ($method === 'GET' && $path === '/schedules') {
        JsonResponse::success(teacher_api_service()->schedules($user, $_GET));
    }
    if ($method === 'GET' && $path === '/meetings') {
        JsonResponse::success(teacher_api_service()->meetings($user, $_GET));
    }
    if ($method === 'GET' && $path === '/reports') {
        JsonResponse::success(report_service()->report($_GET, $user));
    }
    if ($method === 'GET' && $path === '/reports/filters') {
        JsonResponse::success(report_service()->options($user));
    }
    if ($method === 'GET' && $path === '/reports/print') {
        JsonResponse::success([
            'html' => PrintRenderer::report(report_service()->exportRows($_GET, $user)),
        ]);
    }
    if ($method === 'GET' && preg_match('#^/reports/meetings/(\d+)$#', $path, $matches)) {
        JsonResponse::success(report_service()->meeting((int) $matches[1], $user));
    }
    if ($method === 'GET' && preg_match('#^/schedules/(\d+)$#', $path, $matches)) {
        JsonResponse::success(teacher_api_service()->schedule((int) $matches[1], isset($_GET['date']) ? (string) $_GET['date'] : null, $user));
    }
    if ($method === 'POST' && preg_match('#^/schedules/(\d+)/meetings$#', $path, $matches)) {
        $result = teacher_api_service()->openMeeting((int) $matches[1], Request::json(), $user);
        JsonResponse::success($result['data'], $result['replayed'] ? 200 : 201);
    }
    if ($method === 'GET' && preg_match('#^/meetings/(\d+)(?:/attendance)?$#', $path, $matches)) {
        JsonResponse::success(teacher_api_service()->meeting((int) $matches[1], $user));
    }
    if ($method === 'PUT' && preg_match('#^/meetings/(\d+)/attendance$#', $path, $matches)) {
        $result = teacher_api_service()->saveAttendance((int) $matches[1], Request::json(), $user);
        JsonResponse::success($result['data']);
    }

    throw new ApiException('NOT_FOUND', 'Endpoint tidak ditemukan.', 404);
} catch (ApiException $exception) {
    JsonResponse::error($exception->errorCode(), $exception->getMessage(), $exception->status(), $exception->details());
} catch (Throwable $exception) {
    error_log((string) $exception);
    JsonResponse::error(
        'SERVER_ERROR',
        app_config('debug') ? $exception->getMessage() : 'Terjadi kesalahan pada server.',
        500
    );
}
