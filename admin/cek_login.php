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

$roles = $_SESSION['roles'] ?? [];

if (in_array('admin', $roles, true)) {
    header('Location: ' . app_url('/admin/admin_dashboard.php'));
    exit;
}

if (in_array('guru', $roles, true)) {
    header('Location: ' . app_url('/admin/pertemuan_pengajian.php'));
    exit;
}

// V2: pengurus dan orang tua diarahkan ke portal perizinan. Kemampuan tetap
// diverifikasi ulang oleh guard portal di sisi server.
if (in_array('pengurus', $roles, true) || in_array('orang_tua', $roles, true)) {
    header('Location: ' . app_url('/portal/index.php'));
    exit;
}

http_response_code(403);
exit('Login berhasil, tetapi akun ini tidak memiliki hak jadwal, portal perizinan, atau panel admin.');
