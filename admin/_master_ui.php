<?php

declare(strict_types=1);

use App\Http\Csrf;

require_once __DIR__ . '/_guard.php';

function master_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function master_flash(string $type, string $message): void
{
    $_SESSION['_master_flash'] = compact('type', 'message');
}

function master_redirect(string $path): never
{
    header('Location: ' . app_url('/admin/' . ltrim($path, '/')));
    exit;
}

function master_query(array $replace = []): string
{
    $query = array_merge($_GET, $replace);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }
    return http_build_query($query);
}

function master_header(string $title): void
{
    $flash = $_SESSION['_master_flash'] ?? null;
    unset($_SESSION['_master_flash']);
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= master_e($title) ?> - Admin Al Hasan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-light">
<div class="container-fluid"><div class="row">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
<?php if (is_array($flash)): ?>
    <div class="alert alert-<?= master_e($flash['type']) ?>" role="alert"><?= master_e($flash['message']) ?></div>
<?php endif; ?>
<?php
}

function master_footer(): void
{
    ?>
</main></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
<?php
}

function master_csrf(): string
{
    return Csrf::input();
}

function master_pagination(int $total, int $page, int $perPage): void
{
    $pages = max(1, (int) ceil($total / $perPage));
    if ($pages <= 1) {
        return;
    }
    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    ?>
    <nav aria-label="Navigasi halaman"><ul class="pagination justify-content-center mt-4">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?<?= master_e(master_query(['page' => max(1, $page - 1)])) ?>">Sebelumnya</a></li>
        <?php for ($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= master_e(master_query(['page' => $i])) ?>"><?= $i ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="?<?= master_e(master_query(['page' => min($pages, $page + 1)])) ?>">Berikutnya</a></li>
    </ul></nav>
    <?php
}
