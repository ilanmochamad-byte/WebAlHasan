<?php

declare(strict_types=1);

namespace App\Ui;

use App\Auth\Capabilities;
use App\Http\Csrf;

/**
 * Kerangka tampilan bersama Sistem Al Hasan (paket perapihan V1–V2).
 *
 * Satu kerangka untuk seluruh halaman internal: topbar, sidebar sesuai
 * kemampuan akun, breadcrumb, judul halaman, tindakan utama, tab modul, dan
 * pesan. Halaman tidak lagi menulis <html>/<head>/<nav> sendiri sehingga tidak
 * ada lagi tata letak berbeda per halaman.
 *
 * Kelas ini murni presentasi. Ia TIDAK memutuskan siapa boleh membuka apa —
 * halaman pemanggil sudah lebih dulu melewati guard-nya sendiri di server.
 *
 * Halaman cetak/PDF TIDAK memakai kerangka ini (tetap tanpa sidebar), sesuai
 * keputusan pengguna 30 Agustus 2026.
 */
final class Layout
{
    private static bool $opened = false;

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array{
     *     title:string,
     *     heading?:string,
     *     description?:string,
     *     user:array<string, mixed>,
     *     capabilities?:array<int, string>,
     *     unread?:int|null,
     *     breadcrumbs?:array<int, array{label:string, url?:string}>,
     *     actions?:string,
     *     tabs?:array<int, array{label:string, url:string, active?:bool, badge?:string}>,
     *     flash?:array{type:string, message:string}|null,
     *     active?:string,
     *     wide?:bool
     * } $options
     */
    public static function open(array $options): void
    {
        self::$opened = true;
        $user = $options['user'];
        $capabilities = $options['capabilities'] ?? [];
        $active = $options['active'] ?? Navigation::activeKey();
        $groups = Navigation::forUser($user, $capabilities, $options['unread'] ?? null);
        $title = (string) $options['title'];
        $heading = (string) ($options['heading'] ?? $title);
        $e = static fn (mixed $value): string => self::escape($value);
        ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($title) ?> — Sistem Al Hasan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= $e(app_url('/assets/ui/alhasan.css')) ?>">
</head>
<body class="ah">
<a class="ah-skip" href="#ah-konten">Lompat ke konten utama</a>
<div class="ah-shell" id="ah-shell">
    <header class="ah-topbar">
        <button class="ah-topbar__btn ah-topbar__toggle" type="button" id="ah-nav-toggle"
                aria-controls="ah-sidebar" aria-expanded="false">
            <i class="fas fa-bars" aria-hidden="true"></i><span class="ah-visually-hidden">Buka menu navigasi</span>
        </button>
        <a class="ah-brand" href="<?= $e(app_url('/portal/index.php')) ?>">
            <span class="ah-brand__mark" aria-hidden="true"><i class="fas fa-mosque"></i></span>
            <span class="ah-brand__text">Sistem Al Hasan<small>Pesantren Al Hasan Ciamis</small></span>
        </a>
        <span class="ah-topbar__spacer"></span>
        <span class="d-none d-md-inline text-white-50 small me-1"><?= $e($user['name'] ?? '') ?></span>
        <a class="ah-topbar__btn" href="<?= $e(app_url('/admin/logout.php')) ?>">
            <i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i><span>Keluar</span>
        </a>
    </header>

    <div class="ah-body">
        <nav class="ah-sidebar" id="ah-sidebar" aria-label="Menu utama">
            <?php if ($capabilities !== []): ?>
                <p class="ah-nav-group">Kemampuan aktif</p>
                <p class="px-2 mb-3 d-flex flex-wrap gap-1">
                    <?php foreach ($capabilities as $capability): ?>
                        <span class="ah-badge ah-badge--ok"><?= $e(self::capabilityLabel($capability)) ?></span>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <?php foreach ($groups as $group): ?>
                <p class="ah-nav-group"><?= $e($group['label']) ?></p>
                <ul class="ah-nav list-unstyled mb-0">
                    <?php foreach ($group['items'] as $item): ?>
                        <li>
                            <a href="<?= $e($item['url']) ?>"<?= $item['key'] === $active ? ' aria-current="page"' : '' ?>>
                                <i class="fas <?= $e($item['icon']) ?>" aria-hidden="true"></i>
                                <span><?= $e($item['label']) ?></span>
                                <?php if (!empty($item['badge'])): ?>
                                    <span class="ah-badge ah-badge--danger ah-nav__badge"><?= $e($item['badge']) ?>
                                        <?php if (!empty($item['badge_label'])): ?>
                                            <span class="ah-visually-hidden"><?= $e($item['badge_label']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </nav>
        <div class="ah-scrim" id="ah-scrim" hidden></div>

        <main class="ah-main" id="ah-konten" tabindex="-1">
            <?php if (!empty($options['breadcrumbs'])): ?>
                <nav aria-label="Jalur halaman">
                    <ol class="ah-crumbs">
                        <?php foreach ($options['breadcrumbs'] as $crumb): ?>
                            <li>
                                <?php if (!empty($crumb['url'])): ?>
                                    <a href="<?= $e($crumb['url']) ?>"><?= $e($crumb['label']) ?></a>
                                <?php else: ?>
                                    <span aria-current="page"><?= $e($crumb['label']) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </nav>
            <?php endif; ?>

            <div class="ah-page-head">
                <div>
                    <h1><?= $e($heading) ?></h1>
                    <?php if (!empty($options['description'])): ?>
                        <p><?= $e($options['description']) ?></p>
                    <?php endif; ?>
                </div>
                <?php if (!empty($options['actions'])): ?>
                    <div class="ah-page-head__actions"><?= $options['actions'] ?></div>
                <?php endif; ?>
            </div>

            <?php if (!empty($options['tabs'])): ?>
                <ul class="ah-tabs">
                    <?php foreach ($options['tabs'] as $tab): ?>
                        <li>
                            <a href="<?= $e($tab['url']) ?>"<?= !empty($tab['active']) ? ' aria-current="page"' : '' ?>>
                                <span><?= $e($tab['label']) ?></span>
                                <?php if (isset($tab['badge']) && $tab['badge'] !== ''): ?>
                                    <span class="ah-badge ah-badge--muted"><?= $e($tab['badge']) ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php
            $flash = $options['flash'] ?? null;
            if (is_array($flash) && ($flash['message'] ?? '') !== '') {
                self::note((string) $flash['type'], (string) $flash['message'], $flash['extra'] ?? null);
            }
    }

    public static function close(): void
    {
        if (!self::$opened) {
            return;
        }
        self::$opened = false;
        ?>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
window.ALHASAN_CSRF = <?= json_encode(Csrf::token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
(function () {
    // Laci navigasi ponsel. Tanpa JavaScript, sidebar tetap ada pada layar
    // besar dan seluruh tautannya tetap dapat dicapai lewat halaman Beranda,
    // sehingga tidak ada fungsi yang hilang.
    var shell = document.getElementById('ah-shell');
    var toggle = document.getElementById('ah-nav-toggle');
    var scrim = document.getElementById('ah-scrim');
    function setOpen(open) {
        shell.classList.toggle('is-nav-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (scrim) { scrim.hidden = !open; }
    }
    if (toggle) {
        toggle.addEventListener('click', function () { setOpen(!shell.classList.contains('is-nav-open')); });
    }
    if (scrim) { scrim.addEventListener('click', function () { setOpen(false); }); }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && shell.classList.contains('is-nav-open')) { setOpen(false); }
    });

    // Token CSRF disisipkan otomatis ke setiap formulir POST yang belum punya.
    document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(function (form) {
        if (!form.querySelector('input[name="_csrf"]')) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_csrf';
            input.value = window.ALHASAN_CSRF;
            form.appendChild(input);
        }
        // Klik ganda tidak mengirim dua permintaan. Pengaman sebenarnya tetap
        // kunci idempotensi dan transaksi di server; ini hanya bantuan tampilan.
        form.addEventListener('submit', function () {
            form.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (button) {
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
            });
        });
    });
    if (window.jQuery) {
        window.jQuery.ajaxSetup({headers: {'X-CSRF-Token': window.ALHASAN_CSRF}});
    }
})();
</script>
</body>
</html>
        <?php
    }

    /**
     * Pesan berstatus. Selalu memakai kata, bukan warna saja.
     */
    public static function note(string $type, string $message, ?string $extraHtml = null): void
    {
        [$class, $icon, $label] = match ($type) {
            'success', 'sukses', 'ok' => ['ah-note--ok', 'fa-circle-check', 'Berhasil'],
            'danger', 'gagal', 'error' => ['ah-note--danger', 'fa-circle-exclamation', 'Gagal'],
            'warning', 'peringatan' => ['ah-note--warn', 'fa-triangle-exclamation', 'Perhatian'],
            default => ['ah-note--info', 'fa-circle-info', 'Informasi'],
        };
        echo '<div class="ah-note ' . $class . '" role="' . ($class === 'ah-note--danger' ? 'alert' : 'status') . '">'
            . '<i class="fas ' . $icon . ' mt-1" aria-hidden="true"></i><div>'
            . '<strong class="ah-note__title">' . self::escape($label) . '</strong>'
            . '<span>' . self::escape($message) . '</span>'
            . ($extraHtml ?? '')
            . '</div></div>';
    }

    /**
     * Keadaan kosong yang menjelaskan langkah berikutnya, bukan tabel kosong.
     */
    public static function emptyState(string $title, string $message, ?string $actionHtml = null): string
    {
        return '<div class="ah-empty"><div class="ah-empty__icon" aria-hidden="true"><i class="fas fa-inbox"></i></div>'
            . '<p class="ah-empty__title">' . self::escape($title) . '</p>'
            . '<p class="mb-2">' . self::escape($message) . '</p>'
            . ($actionHtml ?? '') . '</div>';
    }

    public static function capabilityLabel(string $capability): string
    {
        return match ($capability) {
            Capabilities::ADMIN => 'Admin',
            Capabilities::PENGURUS => 'Pengurus',
            Capabilities::MUROBI => 'Murobi',
            Capabilities::ORANG_TUA => 'Orang Tua',
            default => $capability,
        };
    }
}
