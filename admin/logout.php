<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Http\Session;

require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = authorization()->requireWebUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? null);
    audit_logger()->log('logout', 'user', $user['id'], null, ['session_ended' => true], $user['id']);
    Session::destroy();
    header('Location: ' . app_url('/admin/admin_login.php?pesan=logout'));
    exit;
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Keluar - PP Al Hasan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5" style="max-width: 520px">
    <div class="card shadow-sm border-0"><div class="card-body p-4">
        <h1 class="h4">Keluar dari sistem?</h1>
        <p class="text-muted">Sesi akun <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?> akan diakhiri.</p>
        <form method="post" class="d-flex gap-2">
            <?= Csrf::input() ?>
            <button class="btn btn-danger" type="submit">Ya, keluar</button>
            <?php
            $cancelUrl = match (true) {
                in_array('admin', $user['roles'], true) => 'admin_dashboard.php',
                in_array('guru', $user['roles'], true) => 'pertemuan_pengajian.php',
                in_array('pengurus', $user['roles'], true), in_array('orang_tua', $user['roles'], true) => app_url('/portal/index.php'),
                default => 'ubah_password.php',
            };
            ?>
            <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($cancelUrl, ENT_QUOTES, 'UTF-8') ?>">Batal</a>
        </form>
    </div></div>
</main>
</body>
</html>
