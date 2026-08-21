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

/**
 * Konteks request untuk riwayat status perizinan (tanpa credential).
 *
 * @return array{ip:?string, user_agent:?string}
 */
$requestMeta = static fn (): array => [
    'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
    'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null,
];

try {
    if ($method === 'GET' && $path === '/') {
        JsonResponse::success(['name' => 'Al Hasan API', 'version' => 'v1']);
    }

    if ($method === 'POST' && $path === '/auth/login') {
        JsonResponse::success(api_auth_service()->login(Request::json()));
    }

    // Autentikasi sekali; penjaga hak akses diterapkan per endpoint di bawah.
    // Endpoint jadwal/laporan V1 tetap memakai penjaga admin/guru yang sama
    // seperti sebelumnya, sehingga kontrak aplikasi guru tidak berubah.
    $user = api_authenticator()->authenticate();

    if ($method === 'GET' && $path === '/profile') {
        JsonResponse::success(api_auth_service()->profile($user));
    }
    if ($method === 'POST' && $path === '/auth/logout') {
        api_auth_service()->logout($user);
        JsonResponse::success(['message' => 'Logout berhasil.']);
    }
    if ($method === 'GET' && $path === '/me/capabilities') {
        JsonResponse::success(izin_api_service()->capabilities($user));
    }

    // ---------------------------------------------------------------------
    // V2 Fase 3: perizinan multi-peran (aditif; tidak mengubah endpoint V1).
    // ---------------------------------------------------------------------
    if ($method === 'GET' && $path === '/izin/santri') {
        JsonResponse::success(izin_api_service()->santri($user, $_GET));
    }
    if ($method === 'GET' && $path === '/izin/anak') {
        JsonResponse::success(izin_api_service()->anak($user));
    }
    if ($method === 'GET' && $path === '/izin/filters') {
        JsonResponse::success(izin_api_service()->filters($user, $_GET));
    }
    if ($method === 'GET' && $path === '/izin/antrean') {
        JsonResponse::success(izin_api_service()->index($user, $_GET, true));
    }
    if ($method === 'GET' && $path === '/izin/admin/monitor') {
        JsonResponse::success(izin_api_service()->adminMonitor($user, $_GET));
    }
    if ($method === 'GET' && $path === '/izin/pengajuan') {
        JsonResponse::success(izin_api_service()->index($user, $_GET));
    }
    if ($method === 'POST' && $path === '/izin/pengajuan') {
        $result = izin_api_service()->create($user, Request::json(), $requestMeta(), $_GET);
        JsonResponse::success($result['data'], $result['status']);
    }
    if ($method === 'GET' && preg_match('#^/izin/pengajuan/(\d+)$#', $path, $matches)) {
        JsonResponse::success(izin_api_service()->show($user, (int) $matches[1], $_GET));
    }
    if ($method === 'GET' && preg_match('#^/izin/pengajuan/(\d+)/riwayat$#', $path, $matches)) {
        JsonResponse::success(izin_api_service()->history($user, (int) $matches[1], $_GET));
    }
    if ($method === 'GET' && preg_match('#^/izin/pengajuan/(\d+)/routing$#', $path, $matches)) {
        JsonResponse::success(izin_api_service()->routing($user, (int) $matches[1]));
    }
    if ($method === 'POST' && preg_match('#^/izin/pengajuan/(\d+)/penetapan-murobi$#', $path, $matches)) {
        $result = izin_api_service()->assign($user, (int) $matches[1], Request::json(), $requestMeta());
        JsonResponse::success($result['data'], $result['status']);
    }
    if ($method === 'POST' && preg_match('#^/izin/pengajuan/(\d+)/keputusan$#', $path, $matches)) {
        $result = izin_api_service()->decide($user, (int) $matches[1], Request::json(), $requestMeta(), $_GET);
        JsonResponse::success($result['data'], $result['status']);
    }
    if ($method === 'POST' && preg_match('#^/izin/pengajuan/(\d+)/pembatalan$#', $path, $matches)) {
        $result = izin_api_service()->cancel($user, (int) $matches[1], Request::json(), $requestMeta(), $_GET);
        JsonResponse::success($result['data'], $result['status']);
    }
    if ($method === 'POST' && preg_match('#^/izin/pengajuan/(\d+)/koreksi$#', $path, $matches)) {
        $result = izin_api_service()->correct($user, (int) $matches[1], Request::json(), $requestMeta());
        JsonResponse::success($result['data'], $result['status']);
    }

    // ---------------------------------------------------------------------
    // V1: jadwal, pertemuan, absensi, dan laporan (kontrak tidak berubah).
    // ---------------------------------------------------------------------
    if ($method === 'GET' && $path === '/schedules/today') {
        JsonResponse::success(teacher_api_service()->today(api_authenticator()->assertScheduleAccess($user)));
    }
    if ($method === 'GET' && $path === '/schedules') {
        JsonResponse::success(teacher_api_service()->schedules(api_authenticator()->assertScheduleAccess($user), $_GET));
    }
    if ($method === 'GET' && $path === '/meetings') {
        JsonResponse::success(teacher_api_service()->meetings(api_authenticator()->assertScheduleAccess($user), $_GET));
    }
    if ($method === 'GET' && $path === '/reports') {
        JsonResponse::success(report_service()->report($_GET, api_authenticator()->assertScheduleAccess($user)));
    }
    if ($method === 'GET' && $path === '/reports/filters') {
        JsonResponse::success(report_service()->options(api_authenticator()->assertScheduleAccess($user)));
    }
    if ($method === 'GET' && $path === '/reports/print') {
        JsonResponse::success([
            'html' => PrintRenderer::report(report_service()->exportRows($_GET, api_authenticator()->assertScheduleAccess($user))),
        ]);
    }
    if ($method === 'GET' && preg_match('#^/reports/meetings/(\d+)$#', $path, $matches)) {
        JsonResponse::success(report_service()->meeting((int) $matches[1], api_authenticator()->assertScheduleAccess($user)));
    }
    if ($method === 'GET' && preg_match('#^/schedules/(\d+)$#', $path, $matches)) {
        JsonResponse::success(teacher_api_service()->schedule((int) $matches[1], isset($_GET['date']) ? (string) $_GET['date'] : null, api_authenticator()->assertScheduleAccess($user)));
    }
    if ($method === 'POST' && preg_match('#^/schedules/(\d+)/meetings$#', $path, $matches)) {
        $result = teacher_api_service()->openMeeting((int) $matches[1], Request::json(), api_authenticator()->assertScheduleAccess($user));
        JsonResponse::success($result['data'], $result['replayed'] ? 200 : 201);
    }
    if ($method === 'GET' && preg_match('#^/meetings/(\d+)(?:/attendance)?$#', $path, $matches)) {
        JsonResponse::success(teacher_api_service()->meeting((int) $matches[1], api_authenticator()->assertScheduleAccess($user)));
    }
    if ($method === 'PUT' && preg_match('#^/meetings/(\d+)/attendance$#', $path, $matches)) {
        $result = teacher_api_service()->saveAttendance((int) $matches[1], Request::json(), api_authenticator()->assertScheduleAccess($user));
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
