<?php

declare(strict_types=1);

use App\Http\Csrf;

require_once dirname(__DIR__) . '/app/bootstrap.php';

/**
 * Guard portal perizinan berbasis kemampuan.
 *
 * Halaman portal wajib memanggil file ini sebelum menghasilkan output apa pun.
 * Variabel yang tersedia setelahnya: $currentUser, $userCapabilities, $koneksi.
 */
$portalContext = portal_guard()->requireAnyPerizinan();
$currentUser = $portalContext['user'];
$userCapabilities = $portalContext['capabilities'];
$koneksi = app_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
}
