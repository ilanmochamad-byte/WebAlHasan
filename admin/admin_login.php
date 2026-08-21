<?php

declare(strict_types=1);

use App\Http\Csrf;

require_once dirname(__DIR__) . '/app/bootstrap.php';

// Sesi yang masih hidup dikembalikan ke tujuan yang sama dengan alur login biasa
// (LandingRouter), sehingga murobi tidak pernah terlempar balik ke jadwal.
$activeUser = authorization()->currentUser();
if ($activeUser !== null && empty($_SESSION['force_password_change'])) {
    $destination = landing_router()->url($activeUser);
    if ($destination !== null) {
        header('Location: ' . $destination);
        exit;
    }
}

$message = match ($_GET['pesan'] ?? '') {
    'gagal' => 'Username atau password salah, atau akun sedang tidak aktif.',
    'sesi' => 'Sesi Anda berakhir. Silakan masuk kembali.',
    'logout' => 'Anda telah keluar dengan aman.',
    default => null,
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Pengguna - PP Al Hasan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { width: 100%; max-width: 400px; padding: 40px; border-radius: 15px; background: white; box-shadow: 0 10px 25px rgba(0,0,0,.1); }
        .btn-login { background-color: #0f5132; color: white; width: 100%; padding: 10px; border-radius: 8px; font-weight: bold; }
        .btn-login:hover { background-color: #0a3d25; color: white; }
    </style>
</head>
<body>
<main class="login-card">
    <div class="text-center mb-4">
        <h1 class="h4 fw-bold text-success">Login Sistem</h1>
        <p class="text-muted">Website Pesantren Al Hasan Ciamis</p>
    </div>

    <?php if ($message !== null): ?>
        <div class="alert <?= ($_GET['pesan'] ?? '') === 'logout' ? 'alert-success' : 'alert-danger' ?> text-center small" role="alert">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form action="cek_login.php" method="POST">
        <?= Csrf::input() ?>
        <div class="mb-3">
            <label for="username" class="form-label">Username</label>
            <input id="username" type="text" name="username" class="form-control" autocomplete="username" required autofocus>
        </div>
        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input id="password" type="password" name="password" class="form-control" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-login">MASUK</button>
        <div class="text-center mt-3">
            <a href="../index.php" class="text-decoration-none small text-muted">← Kembali ke Website Utama</a>
        </div>
    </form>
</main>
</body>
</html>
