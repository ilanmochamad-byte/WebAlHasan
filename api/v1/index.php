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

/**
 * Cakupan yang DIMINTA klien (V2 Fase 5).
 *
 * Ini hanya preferensi tampilan, bukan hak akses: `IzinService::scopeFor()`
 * memilih di antara kemampuan yang benar-benar dimiliki akun dan mengabaikan
 * nilai yang tidak dimiliki. Mengirim `mode=admin` tidak memberi hak admin.
 */
$modePreferensi = static fn (): ?string => isset($_GET['mode']) && trim((string) $_GET['mode']) !== ''
    ? (string) $_GET['mode']
    : null;

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
        // V2 Fase 4: logout mencabut registrasi perangkat push agar sesi lama
        // tidak meninggalkan perangkat yang masih menerima push. Aplikasi
        // mengirim `push_token` miliknya; bila tidak dikirim, seluruh perangkat
        // akun ini dicabut. Pencabutan tidak pernah menggagalkan logout.
        $logoutBody = Request::json();
        $pushToken = isset($logoutBody['push_token']) ? (string) $logoutBody['push_token'] : null;
        $perangkatDicabut = notification_api_service()->revokeOnLogout($user, $pushToken);
        api_auth_service()->logout($user);
        JsonResponse::success([
            'message' => 'Logout berhasil.',
            'perangkat_push_dicabut' => $perangkatDicabut,
        ]);
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
    // V2 Fase 5: laporan perizinan, cetak/PDF, dan ekspor CSV.
    //
    // Aditif; tidak mengubah endpoint laporan absensi V1 (`/reports*`) yang
    // dipakai aplikasi guru. Aplikasi mobile memakai endpoint DI BAWAH INI —
    // aturan cakupannya tidak diduplikasi di sisi aplikasi. Cakupan dihitung
    // ulang server pada setiap permintaan oleh `izin_report_service()`.
    // ---------------------------------------------------------------------
    if ($method === 'GET' && $path === '/izin/laporan') {
        JsonResponse::success(izin_report_service()->report($user, $_GET, $modePreferensi()));
    }
    if ($method === 'GET' && $path === '/izin/laporan/filters') {
        JsonResponse::success(izin_report_service()->options($user, $_GET, $modePreferensi()));
    }
    if ($method === 'GET' && $path === '/izin/laporan/cetak') {
        // HTML ramah cetak; aplikasi mengubahnya menjadi PDF dengan `expo-print`
        // sehingga dokumen web dan dokumen aplikasi identik.
        JsonResponse::success(izin_report_service()->printHtml($user, $_GET, $modePreferensi()));
    }
    if ($method === 'GET' && $path === '/izin/laporan/csv') {
        // Envelope JSON dipertahankan (konvensi API V1). Isi CSV dikirim sebagai
        // string agar aplikasi dapat menyimpannya sendiri; jumlah baris disertakan
        // supaya klien dapat memverifikasi bahwa ia menerima SELURUH hasil filter.
        JsonResponse::success(izin_report_service()->csv($user, $_GET, $modePreferensi()));
    }
    if ($method === 'GET' && $path === '/izin/laporan/explain') {
        // Hanya admin (dijaga di dalam layanan, bukan oleh urutan rute).
        JsonResponse::success(izin_report_service()->explain($user, $_GET, $modePreferensi()));
    }

    // ---------------------------------------------------------------------
    // V2 Fase 4: notifikasi in-app, perangkat push, dan panel kanal admin.
    //
    // Aditif; tidak mengubah satu pun endpoint V1 atau Fase 3. Rute admin
    // berada di bawah `/notifikasi/admin/...` dan penjaga aksesnya ada di
    // `NotificationAdminService` (capability admin dihitung ulang di server),
    // bukan pada urutan rute di berkas ini.
    // ---------------------------------------------------------------------
    if ($method === 'GET' && $path === '/notifikasi') {
        JsonResponse::success(notification_api_service()->index($user, $_GET));
    }
    if ($method === 'GET' && $path === '/notifikasi/belum-dibaca') {
        JsonResponse::success(notification_api_service()->unreadCount($user));
    }
    if ($method === 'POST' && $path === '/notifikasi/dibaca-semua') {
        JsonResponse::success(notification_api_service()->markAllRead($user));
    }

    // Perangkat push (registrasi, daftar, pencabutan, sakelar per perangkat).
    if ($method === 'GET' && $path === '/notifikasi/perangkat') {
        JsonResponse::success(notification_api_service()->devices($user));
    }
    if ($method === 'POST' && $path === '/notifikasi/perangkat') {
        JsonResponse::success(notification_api_service()->registerDevice($user, Request::json()), 201);
    }
    if ($method === 'POST' && $path === '/notifikasi/perangkat/pencabutan') {
        JsonResponse::success(notification_api_service()->revokeDevice($user, Request::json()));
    }
    if ($method === 'POST' && preg_match('#^/notifikasi/perangkat/(\d+)/push$#', $path, $matches)) {
        JsonResponse::success(notification_api_service()->setDevicePush($user, (int) $matches[1], Request::json()));
    }

    // Panel kanal admin.
    if ($method === 'GET' && $path === '/notifikasi/admin/status') {
        JsonResponse::success(notification_api_service()->adminStatus($user));
    }
    if ($method === 'GET' && $path === '/notifikasi/admin/kegagalan') {
        JsonResponse::success(notification_api_service()->adminFailures($user, $_GET));
    }
    if ($method === 'GET' && $path === '/notifikasi/admin/audit') {
        JsonResponse::success(notification_api_service()->adminAudit($user, $_GET));
    }
    if ($method === 'POST' && $path === '/notifikasi/admin/pemeriksaan') {
        JsonResponse::success(notification_api_service()->adminCheck($user, Request::json(), $requestMeta()));
    }
    if ($method === 'POST' && $path === '/notifikasi/admin/sakelar') {
        JsonResponse::success(notification_api_service()->adminToggle($user, Request::json(), $requestMeta()));
    }
    if ($method === 'POST' && $path === '/notifikasi/admin/pesan-uji') {
        JsonResponse::success(notification_api_service()->adminTestMessage($user, Request::json(), $requestMeta()));
    }
    if ($method === 'POST' && $path === '/notifikasi/admin/worker') {
        JsonResponse::success(notification_api_service()->adminRunWorker($user, Request::json()));
    }
    if ($method === 'POST' && preg_match('#^/notifikasi/admin/kegagalan/(\d+)/coba-ulang$#', $path, $matches)) {
        JsonResponse::success(notification_api_service()->adminRetry($user, (int) $matches[1], $requestMeta()));
    }

    // Detail dan penandaan baca satu notifikasi. Diletakkan SETELAH rute
    // literal di atas agar `/notifikasi/perangkat` tidak pernah tertangkap
    // sebagai id. Cakupan tetap dijaga server: id yang bukan milik pengguna
    // dijawab 403, bukan isi notifikasi orang lain.
    if ($method === 'GET' && preg_match('#^/notifikasi/(\d+)$#', $path, $matches)) {
        JsonResponse::success(notification_api_service()->show($user, (int) $matches[1]));
    }
    if ($method === 'POST' && preg_match('#^/notifikasi/(\d+)/dibaca$#', $path, $matches)) {
        JsonResponse::success(notification_api_service()->markRead($user, (int) $matches[1]));
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
