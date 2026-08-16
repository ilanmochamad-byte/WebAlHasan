<?php

declare(strict_types=1);

use App\Http\Csrf;

require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = authorization()->requireWebUser();
$error = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? null);
    $currentPassword = (string) ($_POST['password_saat_ini'] ?? '');
    $password = (string) ($_POST['password_baru'] ?? '');
    $confirmation = (string) ($_POST['konfirmasi_password'] ?? '');

    if (!password_verify($currentPassword, $user['password'])) {
        $error = 'Password saat ini tidak benar.';
    } elseif (strlen($password) < 12 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) {
        $error = 'Password minimal 12 karakter dan memuat huruf besar, huruf kecil, serta angka.';
    } elseif ($password !== $confirmation) {
        $error = 'Konfirmasi password tidak sama.';
    } elseif (!auth_repository()->updatePassword($user['id'], password_hash($password, PASSWORD_DEFAULT))) {
        $error = 'Password gagal diubah. Silakan coba lagi.';
    } else {
        $_SESSION['force_password_change'] = false;
        audit_logger()->log('password_changed', 'user', $user['id'], null, ['force_password_change' => false], $user['id']);
        $success = true;
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ganti Password - PP Al Hasan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5" style="max-width: 620px">
    <div class="card shadow-sm border-0"><div class="card-body p-4 p-md-5">
        <h1 class="h3">Ganti password</h1>
        <?php if ($success): ?>
            <div class="alert alert-success">Password berhasil diperbarui.</div>
            <?php if (in_array('admin', $user['roles'], true)): ?>
                <a class="btn btn-success" href="admin_dashboard.php">Lanjut ke dashboard</a>
            <?php else: ?>
                <p class="text-muted">Akun guru sudah siap digunakan ketika aplikasi guru tersedia.</p>
                <a class="btn btn-outline-secondary" href="logout.php">Keluar</a>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($user['force_password_change']): ?><div class="alert alert-warning">Password sementara wajib diganti sebelum melanjutkan.</div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <form method="post">
                <?= Csrf::input() ?>
                <div class="mb-3">
                    <label for="password_saat_ini" class="form-label">Password saat ini</label>
                    <input id="password_saat_ini" class="form-control" type="password" name="password_saat_ini" autocomplete="current-password" required>
                </div>
                <div class="mb-3">
                    <label for="password_baru" class="form-label">Password baru</label>
                    <input id="password_baru" class="form-control" type="password" name="password_baru" autocomplete="new-password" required>
                    <div class="form-text">Minimal 12 karakter, dengan huruf besar, huruf kecil, dan angka.</div>
                </div>
                <div class="mb-3">
                    <label for="konfirmasi_password" class="form-label">Konfirmasi password baru</label>
                    <input id="konfirmasi_password" class="form-control" type="password" name="konfirmasi_password" autocomplete="new-password" required>
                </div>
                <button class="btn btn-success" type="submit">Simpan password</button>
                <?php if (!$user['force_password_change'] && in_array('admin', $user['roles'], true)): ?><a class="btn btn-outline-secondary" href="admin_dashboard.php">Batal</a><?php endif; ?>
            </form>
        <?php endif; ?>
    </div></div>
</main>
</body>
</html>
