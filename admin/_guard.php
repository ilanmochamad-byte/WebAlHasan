<?php

declare(strict_types=1);

use App\Http\Csrf;

require_once dirname(__DIR__) . '/app/bootstrap.php';

$currentUser = authorization()->requireWebRole('admin');
$koneksi = app_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
}
