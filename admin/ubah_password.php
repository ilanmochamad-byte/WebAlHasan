<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Http\SafeRedirect;
use App\Ui\Layout;

/**
 * Ganti password.
 *
 * Wajib diselesaikan lebih dulu oleh pemegang password sementara sebelum
 * fungsi operasional lain terbuka (`Authorization::requireWebUser()`).
 * Halaman ini sengaja berdiri sendiri tanpa sidebar: pengguna dengan password
 * sementara belum boleh berpindah modul.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = authorization()->requireWebUser();
$error = null;
$errorField = null;
$success = false;

$next = SafeRedirect::sanitize($_GET['next'] ?? $_POST['next'] ?? null);

// Tujuan setelah ganti password memakai LandingRouter yang sama dengan alur
// masuk, sehingga tidak ada dua pendapat tentang ke mana pengguna melanjutkan.
$destination = landing_router()->destination($user);
$continue = $destination['url'] === null
    ? [app_url('/admin/logout.php'), 'Keluar']
    : [$next ?? $destination['url'], $destination['label']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? null);
    $currentPassword = (string) ($_POST['password_saat_ini'] ?? '');
    $password = (string) ($_POST['password_baru'] ?? '');
    $confirmation = (string) ($_POST['konfirmasi_password'] ?? '');

    if (!password_verify($currentPassword, $user['password'])) {
        $error = 'Password saat ini tidak benar.'; $errorField = 'password_saat_ini';
    } elseif (strlen($password) < 12 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) {
        $error = 'Password minimal 12 karakter dan memuat huruf besar, huruf kecil, serta angka.'; $errorField = 'password_baru';
    } elseif ($password !== $confirmation) {
        $error = 'Konfirmasi password tidak sama.'; $errorField = 'konfirmasi_password';
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
    <meta name="robots" content="noindex, nofollow">
    <title>Ganti Password — Sistem Al Hasan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ah_e(app_url('/assets/ui/alhasan.css')) ?>">
</head>
<body class="ah">
<main class="container py-5" style="max-width: 40rem">
    <div class="ah-card"><div class="ah-card__body">
        <h1 class="h4 mb-3">Ganti password</h1>
        <?php if ($success): ?>
            <?php Layout::note('success', 'Password berhasil diperbarui. Gunakan password baru pada login berikutnya.'); ?>
            <a class="btn btn-primary" href="<?= ah_e($continue[0]) ?>"><?= ah_e($continue[1]) ?></a>
        <?php else: ?>
            <?php if ($user['force_password_change']): ?>
                <?php Layout::note('warning', 'Password sementara wajib diganti sebelum fungsi operasional lain dapat dibuka.'); ?>
            <?php endif; ?>
            <?php if ($error !== null): ?>
                <?php Layout::note('danger', $error); ?>
            <?php endif; ?>
            <form method="post" novalidate>
                <?= Csrf::input() ?>
                <?php if ($next !== null): ?><input type="hidden" name="next" value="<?= ah_e($next) ?>"><?php endif; ?>
                <div class="mb-3">
                    <label for="password_saat_ini" class="form-label">Password saat ini</label>
                    <input id="password_saat_ini" <?= $errorField === 'password_saat_ini' ? 'aria-invalid="true"' : '' ?> class="form-control" type="password" name="password_saat_ini" autocomplete="current-password" aria-describedby="error-password_saat_ini" required><span class="ah-field-error" id="error-password_saat_ini"><?= $errorField === 'password_saat_ini' ? ah_e($error) : '' ?></span>
                </div>
                <div class="mb-3">
                    <label for="password_baru" class="form-label">Password baru</label>
                    <input id="password_baru" <?= $errorField === 'password_baru' ? 'aria-invalid="true"' : '' ?> class="form-control" type="password" name="password_baru" autocomplete="new-password"
                           aria-describedby="syarat_password error-password_baru" required><span class="ah-field-error" id="error-password_baru"><?= $errorField === 'password_baru' ? ah_e($error) : '' ?></span>
                    <div class="form-text" id="syarat_password">Minimal 12 karakter, dengan huruf besar, huruf kecil, dan angka.</div>
                </div>
                <div class="mb-3">
                    <label for="konfirmasi_password" class="form-label">Konfirmasi password baru</label>
                    <input id="konfirmasi_password" <?= $errorField === 'konfirmasi_password' ? 'aria-invalid="true"' : '' ?> class="form-control" type="password" name="konfirmasi_password" autocomplete="new-password" aria-describedby="error-konfirmasi_password" required><span class="ah-field-error" id="error-konfirmasi_password"><?= $errorField === 'konfirmasi_password' ? ah_e($error) : '' ?></span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">Simpan password</button>
                    <?php if (!$user['force_password_change']): ?>
                        <a class="btn btn-outline-secondary" href="<?= ah_e(app_url('/portal/index.php')) ?>">Batal</a>
                    <?php else: ?>
                        <a class="btn btn-outline-secondary" href="<?= ah_e(app_url('/admin/logout.php')) ?>">Keluar</a>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </div></div>
</main>
</body>
</html>
