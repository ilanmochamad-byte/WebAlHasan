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

// Tujuan pasca-login ditentukan LandingRouter dari capability nyata akun, bukan
// dari role mentah: guru dengan penugasan murobi aktif mendarat di antrean
// keputusan izin, guru tanpa penugasan tetap mendarat di jadwal mengajar.
// Kemampuan tetap diverifikasi ulang oleh guard halaman tujuan di sisi server.
$user = authorization()->currentUser();
$destination = $user === null ? null : landing_router()->url($user);

if ($destination !== null) {
    header('Location: ' . $destination);
    exit;
}

http_response_code(403);
exit('Login berhasil, tetapi akun ini tidak memiliki hak jadwal, portal perizinan, atau panel admin.');
