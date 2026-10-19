<?php

declare(strict_types=1);

use App\Http\Csrf;

require_once dirname(__DIR__) . '/app/bootstrap.php';

$currentUser = authorization()->requireWebRole('admin');
$koneksi = app_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
}

/**
 * Mutasi admin V1 hanya boleh lewat POST (CSRF sudah divalidasi di atas).
 * GET/HEAD/metode lain ditolak 405 tanpa menyentuh data.
 */
function admin_require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Metode tidak diizinkan. Gunakan tombol pada halaman admin.');
    }
}
