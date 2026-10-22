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

    /** @var array<int, array<string, mixed>> */
    private static array $bottomBar = [];

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array{
     *     title:string,
     *     heading?:string,
     *     show_heading?:bool,
     *     description?:string,
     *     user:array<string, mixed>,
     *     capabilities?:array<int, string>,
     *     v3_capabilities?:array<string,array<string,mixed>>,
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
        $groups = Navigation::forUser($user, $capabilities, $options['unread'] ?? null, $options['v3_capabilities'] ?? []);
        $bottomBar = Navigation::bottomBar($groups, $active);
        $notifikasi = Navigation::find($groups, 'notifikasi');
        $password = Navigation::find($groups, 'akun.password');
        $title = (string) $options['title'];
        $heading = (string) ($options['heading'] ?? $title);
        $options['breadcrumbs'] ??= [
            ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
            ['label' => $title],
        ];
        $name = (string) ($user['name'] ?? '');
        $roleText = implode(' · ', array_map(static fn (string $c): string => self::capabilityLabel($c), $capabilities));
        $logoutUrl = app_url('/admin/logout.php');
        $e = static fn (mixed $value): string => self::escape($value);
        ?>
<!DOCTYPE html>
<html lang="id" class="ah-nojs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($title) ?> — Sistem Al Hasan</title>
    <script>document.documentElement.className = document.documentElement.className.replace('ah-nojs', 'ah-js');</script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= $e(self::asset('/assets/ui/alhasan.css')) ?>">
</head>
<body class="ah<?= $bottomBar !== [] ? ' ah-has-bottombar' : '' ?>">
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
        <span class="ah-topbar__title" aria-hidden="true"><?= $e($heading) ?></span>
        <span class="ah-topbar__spacer"></span>
        <?php if ($notifikasi !== null): ?>
            <a class="ah-topbar__btn ah-topbar__bell" href="<?= $e($notifikasi['url']) ?>"<?= $active === 'notifikasi' ? ' aria-current="page"' : '' ?>>
                <i class="fas fa-bell" aria-hidden="true"></i>
                <span class="ah-visually-hidden">Notifikasi<?= !empty($notifikasi['badge_label']) ? ', ' . $e($notifikasi['badge_label']) : '' ?></span>
                <?php if (!empty($notifikasi['badge'])): ?>
                    <span class="ah-topbar__count" aria-hidden="true"><?= $e($notifikasi['badge']) ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
        <details class="ah-menu ah-profile">
            <summary class="ah-topbar__btn">
                <span class="ah-avatar" aria-hidden="true"><?= $e(self::initials($name)) ?></span>
                <span class="ah-profile__name"><?= $e($name) ?></span>
                <span class="ah-visually-hidden">Menu akun</span>
                <i class="fas fa-chevron-down ah-profile__caret" aria-hidden="true"></i>
            </summary>
            <div class="ah-menu__list ah-menu__list--end">
                <div class="ah-menu__info">
                    <b><?= $e($name) ?></b>
                    <?php if ($roleText !== ''): ?><span>Peran: <?= $e($roleText) ?></span><?php endif; ?>
                </div>
                <?php if ($password !== null): ?>
                    <a class="ah-menu__item" href="<?= $e($password['url']) ?>"><i class="fas fa-key" aria-hidden="true"></i>Ganti password</a>
                <?php endif; ?>
                <a class="ah-menu__item" href="<?= $e($logoutUrl) ?>"><i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i>Keluar</a>
            </div>
        </details>
    </header>

    <div class="ah-body">
        <nav class="ah-rail" aria-label="Menu ringkas">
            <ul class="list-unstyled mb-0">
                <?php foreach ($groups as $groupIndex => $group): ?>
                    <?php if ($groupIndex > 0): ?><li class="ah-rail__sep" aria-hidden="true"></li><?php endif; ?>
                    <?php foreach ($group['items'] as $item): ?>
                        <li>
                            <a href="<?= $e($item['url']) ?>" title="<?= $e($item['label']) ?>" data-ah-tip="<?= $e($item['label']) ?>"<?= $item['key'] === $active ? ' aria-current="page"' : '' ?>>
                                <i class="fas <?= $e($item['icon']) ?>" aria-hidden="true"></i>
                                <span class="ah-visually-hidden"><?= $e($item['label']) ?><?= !empty($item['badge_label']) ? ', ' . $e($item['badge_label']) : '' ?></span>
                                <?php if (!empty($item['badge'])): ?><span class="ah-rail__dot" aria-hidden="true"></span><?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
        </nav>

        <nav class="ah-sidebar" id="ah-sidebar" aria-label="Menu utama">
            <div class="ah-drawer-head">
                <span class="ah-avatar" aria-hidden="true"><?= $e(self::initials($name)) ?></span>
                <div>
                    <b><?= $e($name) ?></b>
                    <?php if ($roleText !== ''): ?><span>Peran: <?= $e($roleText) ?></span><?php endif; ?>
                </div>
            </div>
            <?php foreach ($groups as $groupIndex => $group): ?>
                <?php
                $groupId = 'ah-grup-' . $groupIndex;
                $hasActive = in_array($active, array_column($group['items'], 'key'), true);
                ?>
                <div class="ah-nav-sec" data-ah-group="<?= $e(self::slug($group['label'])) ?>"<?= $hasActive ? ' data-ah-active' : '' ?>>
                    <p class="ah-nav-group">
                        <button class="ah-nav-group__btn" type="button" aria-expanded="true" aria-controls="<?= $e($groupId) ?>">
                            <span><?= $e($group['label']) ?></span>
                            <i class="fas fa-chevron-down" aria-hidden="true"></i>
                        </button>
                        <span class="ah-nav-group__text"><?= $e($group['label']) ?></span>
                    </p>
                    <ul class="ah-nav list-unstyled mb-0" id="<?= $e($groupId) ?>">
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
                </div>
            <?php endforeach; ?>
            <ul class="ah-nav ah-drawer-foot list-unstyled mb-0">
                <li><a href="<?= $e($logoutUrl) ?>"><i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i><span>Keluar</span></a></li>
            </ul>
        </nav>
        <div class="ah-scrim" id="ah-scrim" hidden></div>

        <main class="ah-main" id="ah-konten" tabindex="-1">
            <?php if (!empty($options['breadcrumbs'])): ?>
                <nav aria-label="Jalur halaman">
                    <ol class="ah-crumbs">
                        <?php foreach ($options['breadcrumbs'] as $crumbIndex => $crumb): ?>
                            <li>
                                <?php if (!empty($crumb['url'])): ?>
                                    <a href="<?= $e($crumb['url']) ?>"><?= $e($crumb['label']) ?></a>
                                <?php else: ?>
                                    <span<?= $crumbIndex === array_key_last($options['breadcrumbs']) ? ' aria-current="page"' : '' ?>><?= $e($crumb['label']) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </nav>
            <?php endif; ?>

            <?php if ($options['show_heading'] ?? true): ?>
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
            <?php endif; ?>

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
                self::note((string) $flash['type'], (string) $flash['message'], $flash['extra'] ?? null, true);
            }
            self::$bottomBar = $bottomBar;
    }

    public static function close(): void
    {
        if (!self::$opened) {
            return;
        }
        self::$opened = false;
        $e = static fn (mixed $value): string => self::escape($value);
        ?>
        </main>
    </div>
    <?php if (self::$bottomBar !== []): ?>
        <nav class="ah-bottombar" aria-label="Menu cepat">
            <ul class="list-unstyled mb-0">
                <?php foreach (self::$bottomBar as $item): ?>
                    <li>
                        <a href="<?= $e($item['url']) ?>"<?= $item['current'] ? ' aria-current="page"' : '' ?>>
                            <i class="fas <?= $e($item['icon']) ?>" aria-hidden="true"></i>
                            <span><?= $e($item['short']) ?></span>
                            <?php if (!empty($item['badge'])): ?>
                                <span class="ah-bottombar__count"><?= $e($item['badge']) ?><?php if (!empty($item['badge_label'])): ?><span class="ah-visually-hidden"> — <?= $e($item['badge_label']) ?></span><?php endif; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>
<div class="ah-toasts ah-no-print" id="ah-toasts" aria-live="polite"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
window.ALHASAN_CSRF = <?= json_encode(Csrf::token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= $e(self::asset('/assets/ui/alhasan.js')) ?>"></script>
</body>
</html>
        <?php
        self::$bottomBar = [];
    }

    /**
     * Alamat aset bersama dengan penanda versi, agar peramban tidak memakai
     * salinan lama setelah kerangka berubah.
     */
    public static function asset(string $path): string
    {
        $file = APP_ROOT . $path;
        $version = is_file($file) ? (string) filemtime($file) : '';

        return app_url($path) . ($version !== '' ? '?v=' . $version : '');
    }

    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $initials !== '' ? $initials : '?';
    }

    private static function slug(string $label): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-');
    }

    /**
     * Pesan berstatus. Selalu memakai kata, bukan warna saja.
     */
    public static function note(string $type, string $message, ?string $extraHtml = null, bool $toast = false): void
    {
        [$class, $icon, $label] = match ($type) {
            'success', 'sukses', 'ok' => ['ah-note--ok', 'fa-circle-check', 'Berhasil'],
            'danger', 'gagal', 'error' => ['ah-note--danger', 'fa-circle-exclamation', 'Gagal'],
            'warning', 'peringatan' => ['ah-note--warn', 'fa-triangle-exclamation', 'Perhatian'],
            default => ['ah-note--info', 'fa-circle-info', 'Informasi'],
        };
        // Pesan hasil tindakan juga dimunculkan sebagai toast oleh alhasan.js;
        // salinan di halaman tetap ada sampai dimuat ulang (K-3).
        echo '<div class="ah-note ' . $class . '" role="' . ($class === 'ah-note--danger' ? 'alert' : 'status') . '"'
            . ($toast ? ' data-ah-toast="' . substr($class, 9) . '"' : '') . '>'
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
