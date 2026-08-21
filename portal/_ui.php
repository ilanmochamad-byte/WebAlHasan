<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Http\Csrf;

require_once __DIR__ . '/_guard.php';

function portal_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function portal_csrf(): string
{
    return Csrf::input();
}

function portal_capability_label(string $capability): string
{
    return match ($capability) {
        Capabilities::ADMIN => 'Admin',
        Capabilities::PENGURUS => 'Pengurus',
        Capabilities::MUROBI => 'Murobi',
        Capabilities::ORANG_TUA => 'Orang Tua',
        default => $capability,
    };
}

function portal_query(array $replace = []): string
{
    $query = array_merge($_GET, $replace);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }

    return http_build_query($query);
}

/**
 * @param array<int, string> $capabilities
 */
function portal_header(string $title, array $capabilities, string $activeMode, array $user): void
{
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= portal_e($title) ?> - Portal Perizinan Al Hasan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="<?= portal_e(app_url('/portal/index.php')) ?>">
            <i class="fas fa-mosque me-2"></i>Portal Perizinan
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#portalNav" aria-controls="portalNav" aria-expanded="false" aria-label="Buka navigasi">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="portalNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="<?= portal_e(app_url('/portal/index.php')) ?>">Ringkasan</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= portal_e(app_url('/portal/izin.php')) ?>">Daftar Perizinan</a></li>
                <?php if (in_array(Capabilities::ADMIN, $capabilities, true)): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= portal_e(app_url('/admin/admin_dashboard.php')) ?>">Panel Admin</a></li>
                <?php endif; ?>
            </ul>
            <span class="navbar-text text-white-50 me-3 small">
                <?= portal_e($user['name']) ?> —
                <?php foreach ($capabilities as $capability): ?>
                    <span class="badge text-bg-<?= $capability === $activeMode ? 'success' : 'secondary' ?>"><?= portal_e(portal_capability_label($capability)) ?></span>
                <?php endforeach; ?>
            </span>
            <a class="btn btn-sm btn-outline-light" href="<?= portal_e(app_url('/admin/logout.php')) ?>">Keluar</a>
        </div>
    </div>
</nav>
<main class="container-fluid px-md-4 py-4">
    <?php
}

function portal_footer(): void
{
    ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    <?php
}

/**
 * Pemilih cakupan untuk akun dengan lebih dari satu kemampuan.
 * Satu sesi, tanpa login ulang (PRD 5.6).
 *
 * @param array<int, string> $capabilities
 */
function portal_mode_switcher(array $capabilities, string $activeMode, string $page): void
{
    if (count($capabilities) < 2) {
        return;
    }
    ?>
    <div class="btn-group mb-3" role="group" aria-label="Pilih cakupan">
        <?php foreach ($capabilities as $capability): ?>
            <a class="btn btn-sm btn-<?= $capability === $activeMode ? 'success' : 'outline-success' ?>"
               href="<?= portal_e($page . '?' . portal_query(['mode' => $capability, 'page' => null])) ?>">
                <?= portal_e(portal_capability_label($capability)) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
}

function portal_pagination(int $total, int $page, int $perPage): void
{
    $pages = max(1, (int) ceil($total / $perPage));
    if ($pages <= 1) {
        return;
    }
    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    ?>
    <nav aria-label="Navigasi halaman"><ul class="pagination justify-content-center mt-4">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?<?= portal_e(portal_query(['page' => max(1, $page - 1)])) ?>">Sebelumnya</a></li>
        <?php for ($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= portal_e(portal_query(['page' => $i])) ?>"><?= $i ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="?<?= portal_e(portal_query(['page' => min($pages, $page + 1)])) ?>">Berikutnya</a></li>
    </ul></nav>
    <?php
}

function portal_status_badge(string $status): string
{
    $class = match ($status) {
        'Disetujui' => 'success',
        'Ditolak' => 'danger',
        'Dibatalkan' => 'secondary',
        'Perlu Penetapan Admin' => 'warning',
        default => 'primary',
    };

    return '<span class="badge text-bg-' . $class . '">' . portal_e($status) . '</span>';
}
