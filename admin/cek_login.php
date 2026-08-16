<?php

declare(strict_types=1);

use App\Auth\AuthService;
use App\Http\Csrf;

require_once dirname(__DIR__) . '/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . app_url('/admin/admin_login.php'));
    exit;
}

Csrf::requireValid($_POST['_csrf'] ?? null);

$service = new AuthService(auth_repository(), audit_logger());
$authenticated = $service->attempt((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));

if (!$authenticated) {
    header('Location: ' . app_url('/admin/admin_login.php?pesan=gagal'));
    exit;
}

if (!empty($_SESSION['force_password_change'])) {
    header('Location: ' . app_url('/admin/ubah_password.php'));
    exit;
}

if (!in_array('admin', $_SESSION['roles'] ?? [], true)) {
    http_response_code(403);
    exit('Login berhasil, tetapi akun ini tidak memiliki hak untuk membuka panel admin.');
}

header('Location: ' . app_url('/admin/admin_dashboard.php'));
exit;
