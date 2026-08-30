<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Http\Session;

/**
 * Keluar dari sistem.
 *
 * Sejak koreksi ke-7 logout mengakhiri sesi dan mengembalikan pengguna ke
 * pintu masuk yang SAMA dengan tempat ia masuk (`/portal/`), bukan ke halaman
 * masuk khusus admin.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = authorization()->requireWebUser();
$pintuMasuk = app_url('/portal/index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? null);
    audit_logger()->log('logout', 'user', $user['id'], null, ['session_ended' => true], $user['id']);
    Session::destroy();
    header('Location: ' . $pintuMasuk . '?pesan=logout');
    exit;
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Keluar — Sistem Al Hasan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ah_e(app_url('/assets/ui/alhasan.css')) ?>">
</head>
<body class="ah">
<main class="container py-5" style="max-width: 34rem">
    <div class="ah-card"><div class="ah-card__body">
        <h1 class="h4">Keluar dari sistem?</h1>
        <p class="text-muted">
            Sesi akun <strong><?= ah_e($user['name']) ?></strong> akan diakhiri pada peramban ini.
            Anda akan kembali ke halaman masuk dan perlu memasukkan username serta password lagi.
        </p>
        <form method="post" class="d-flex flex-wrap gap-2">
            <?= Csrf::input() ?>
            <button class="btn btn-danger" type="submit">Ya, keluar</button>
            <a class="btn btn-outline-secondary" href="<?= ah_e(app_url('/portal/index.php')) ?>">Batal, kembali ke beranda</a>
        </form>
    </div></div>
</main>
</body>
</html>
