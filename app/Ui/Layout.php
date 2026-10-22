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

    /** @var array<int, array{label:string, context:string, url:string}> */
    private static array $search = [];

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
     *     bare?:bool,
     *     wide?:bool
     * } $options
     */
    public static function open(array $options): void
    {
        self::$opened = true;
        $user = $options['user'];
        $capabilities = $options['capabilities'] ?? [];
        $v3 = $options['v3_capabilities'] ?? [];
        $unread = $options['unread'] ?? null;
        // Kerangka polos: tanpa menu, pencarian, peran, dan lonceng. Dipakai saat
        // kata sandi sementara wajib diganti sebelum fungsi lain dibuka (A-3).
        $bare = !empty($options['bare']);

        $map = Navigation::build($user, $capabilities, $unread, $v3);
        $location = self::locate($map, $options['active'] ?? null);
        $switched = null;
        if ($location === null && !$bare) {
            // Halaman milik peran lain akun ini (mis. tautan lama atau tautan
            // notifikasi): peran aktif ikut beralih agar menu sesuai halaman.
            // Peran tidak memberi hak; halaman sudah melewati guard-nya sendiri.
            foreach (Peran::available($user) as $peran) {
                if ($peran === $map['peran']) {
                    continue;
                }
                $other = Navigation::build($user, $capabilities, $unread, $v3, $peran);
                $found = self::locate($other, null);
                if ($found !== null && Peran::set($user, $peran)) {
                    $map = $other;
                    $location = $found;
                    $switched = $peran;
                    break;
                }
            }
        }
        $groups = $bare ? [] : $map['groups'];
        $active = $location['key'] ?? '';
        $current = $location === null ? null
            : (Navigation::find($map['groups'], $active) ?? self::findExtra($map['extras'], $active));
        $bottomBar = $bare ? [] : Navigation::bottomBar($groups, $active, $map['peran'], $map['extras']);
        $notifikasi = Navigation::find($map['groups'], 'notifikasi') ?? self::findExtra($map['extras'], 'notifikasi');
        $password = self::findExtra($map['extras'], 'akun.password');
        $peranAktif = $map['peran'];
        $peranList = $bare ? [] : Peran::available($user);
        $title = (string) $options['title'];
        $heading = (string) ($options['heading'] ?? $title);
        if (!isset($options['breadcrumbs'])) {
            $options['breadcrumbs'] = [['label' => 'Beranda', 'url' => app_url('/portal/index.php')]];
            if ($current !== null && $current['key'] !== 'beranda' && $current['label'] !== $title) {
                $options['breadcrumbs'][] = ['label' => $current['label'], 'url' => $current['url']];
            }
            $options['breadcrumbs'][] = ['label' => $title];
        }
        $moduleTabs = ($current !== null && count($current['tabs']) > 1) ? $current['tabs'] : [];
        $name = (string) ($user['name'] ?? '');
        $roleText = $peranAktif === null ? '' : Peran::label($peranAktif);
        $logoutUrl = app_url('/admin/logout.php');
        $here = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $preview = $bare || $notifikasi === null ? null : self::notificationPreview($user);
        $search = $bare ? [] : Navigation::searchIndex($groups, $map['extras']);
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
<body class="ah<?= $bottomBar !== [] ? ' ah-has-bottombar' : '' ?><?= $bare ? ' ah-bare' : '' ?>">
<a class="ah-skip" href="#ah-konten">Lompat ke konten utama</a>
<div class="ah-shell" id="ah-shell">
    <header class="ah-topbar">
        <?php if (!$bare): ?>
        <button class="ah-topbar__btn ah-topbar__toggle" type="button" id="ah-nav-toggle"
                aria-controls="ah-sidebar" aria-expanded="false">
            <i class="fas fa-bars" aria-hidden="true"></i><span class="ah-visually-hidden">Buka menu navigasi</span>
        </button>
        <?php endif; ?>
        <a class="ah-brand" href="<?= $e(app_url('/portal/index.php')) ?>">
            <span class="ah-brand__mark" aria-hidden="true"><i class="fas fa-mosque"></i></span>
            <span class="ah-brand__text">Sistem Al Hasan<small>Pesantren Al Hasan Ciamis</small></span>
        </a>
        <span class="ah-topbar__title" aria-hidden="true"><?= $e($heading) ?></span>
        <?php if ($search !== []): ?>
            <?php self::searchBox('ah-cari-atas', 'ah-search--top'); ?>
        <?php endif; ?>
        <span class="ah-topbar__spacer"></span>
        <?php if ($search !== []): ?>
            <button class="ah-topbar__btn ah-topbar__find" type="button" data-ah-find aria-controls="ah-sidebar">
                <i class="fas fa-magnifying-glass" aria-hidden="true"></i><span class="ah-visually-hidden">Cari menu</span>
            </button>
        <?php endif; ?>
        <?php if (count($peranList) > 1): ?>
            <details class="ah-menu ah-peran">
                <summary class="ah-topbar__btn ah-peran__pill">
                    <span>Peran: <?= $e($roleText) ?></span>
                    <i class="fas fa-chevron-down ah-profile__caret" aria-hidden="true"></i>
                </summary>
                <div class="ah-menu__list ah-menu__list--end">
                    <?php self::roleChoices($peranList, $peranAktif, $here); ?>
                </div>
            </details>
        <?php endif; ?>
        <?php if ($notifikasi !== null && !$bare): ?>
            <details class="ah-menu ah-bell">
                <summary class="ah-topbar__btn ah-topbar__bell"<?= $active === 'notifikasi' ? ' data-current="page"' : '' ?>>
                    <i class="fas fa-bell" aria-hidden="true"></i>
                    <span class="ah-visually-hidden">Notifikasi<?= !empty($notifikasi['badge_label']) ? ', ' . $e($notifikasi['badge_label']) : '' ?></span>
                    <?php if (!empty($notifikasi['badge'])): ?>
                        <span class="ah-topbar__count" aria-hidden="true"><?= $e($notifikasi['badge']) ?></span>
                    <?php endif; ?>
                </summary>
                <div class="ah-menu__list ah-menu__list--end ah-bell__list">
                    <p class="ah-bell__head">Notifikasi terbaru</p>
                    <?php if ($preview === null): ?>
                        <p class="ah-bell__empty">Notifikasi belum dapat dimuat.</p>
                    <?php elseif ($preview === []): ?>
                        <p class="ah-bell__empty">Belum ada notifikasi.</p>
                    <?php else: ?>
                        <?php foreach ($preview as $item): ?>
                            <a class="ah-menu__item ah-bell__item<?= $item['dibaca'] ? '' : ' is-unread' ?>"
                               href="<?= $e(app_url('/portal/notifikasi.php?detail=' . (int) $item['id'])) ?>">
                                <span><b><?= $e($item['judul']) ?></b><small><?= $e(self::shortTime((string) $item['dibuat_pada'])) ?><?= $item['dibaca'] ? '' : ' · Belum dibaca' ?></small></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <hr class="ah-menu__sep">
                    <a class="ah-menu__item" href="<?= $e($notifikasi['url']) ?>"><i class="fas fa-list" aria-hidden="true"></i>Lihat semua notifikasi</a>
                </div>
            </details>
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
                <?php if ($password !== null && !$bare): ?>
                    <a class="ah-menu__item" href="<?= $e($password['url']) ?>"><i class="fas fa-key" aria-hidden="true"></i>Ganti kata sandi</a>
                <?php endif; ?>
                <?php self::logoutForm($logoutUrl, 'ah-menu__item'); ?>
            </div>
        </details>
    </header>

    <div class="ah-body">
        <?php if (!$bare): ?>
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
                    <?php if ($roleText !== '' && count($peranList) < 2): ?><span>Peran: <?= $e($roleText) ?></span><?php endif; ?>
                </div>
            </div>
            <?php if (count($peranList) > 1): ?>
                <details class="ah-drawer-peran">
                    <summary>Peran: <b><?= $e($roleText) ?></b> <i class="fas fa-chevron-down" aria-hidden="true"></i></summary>
                    <div class="ah-drawer-peran__list"><?php self::roleChoices($peranList, $peranAktif, $here); ?></div>
                </details>
            <?php endif; ?>
            <?php if ($search !== []): ?>
                <div class="ah-drawer-search"><?php self::searchBox('ah-cari-laci', 'ah-search--drawer'); ?></div>
            <?php endif; ?>
            <?php foreach ($groups as $groupIndex => $group): ?>
                <?php
                $groupId = 'ah-grup-' . $groupIndex;
                $hasActive = in_array($active, array_column($group['items'], 'key'), true);
                $label = $group['label'];
                ?>
                <div class="ah-nav-sec" data-ah-group="<?= $e($label === '' ? 'utama' : self::slug($label)) ?>"<?= $hasActive ? ' data-ah-active' : '' ?>>
                    <?php if ($label !== ''): ?>
                    <p class="ah-nav-group">
                        <button class="ah-nav-group__btn" type="button" aria-expanded="true" aria-controls="<?= $e($groupId) ?>">
                            <span><?= $e($label) ?></span>
                            <i class="fas fa-chevron-down" aria-hidden="true"></i>
                        </button>
                        <span class="ah-nav-group__text"><?= $e($label) ?></span>
                    </p>
                    <?php endif; ?>
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
                <?php if ($password !== null): ?>
                    <li><a href="<?= $e($password['url']) ?>"><i class="fas fa-key" aria-hidden="true"></i><span>Ganti kata sandi</span></a></li>
                <?php endif; ?>
                <li><?php self::logoutForm($logoutUrl, 'ah-drawer-foot__btn'); ?></li>
            </ul>
        </nav>
        <div class="ah-scrim" id="ah-scrim" hidden></div>
        <?php endif; ?>

        <main class="ah-main" id="ah-konten" tabindex="-1">
            <?php if (!empty($options['breadcrumbs']) && !$bare): ?>
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

            <?php if ($moduleTabs !== []): ?>
                <nav class="ah-modtabs" aria-label="<?= $e($current['label']) ?>">
                    <ul class="ah-tabs ah-tabs--module">
                        <?php foreach ($moduleTabs as $tabIndex => $tab): ?>
                            <li><a href="<?= $e($tab['url']) ?>"<?= ($location['tab'] ?? null) === $tabIndex ? ' aria-current="page"' : '' ?>><span><?= $e($tab['label']) ?></span></a></li>
                        <?php endforeach; ?>
                    </ul>
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
            if ($switched !== null) {
                self::note('info', 'Peran beralih ke ' . Peran::label($switched) . ' untuk membuka halaman ini.');
            }
            $flash = $options['flash'] ?? null;
            if (is_array($flash) && ($flash['message'] ?? '') !== '') {
                self::note((string) $flash['type'], (string) $flash['message'], $flash['extra'] ?? null, true);
            }
            self::$bottomBar = $bottomBar;
            self::$search = $search;
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
                        <?php if ($item['key'] === '_menu'): ?>
                            <a href="#ah-sidebar" role="button" data-ah-drawer aria-controls="ah-sidebar">
                                <i class="fas fa-bars" aria-hidden="true"></i><span>Menu</span>
                            </a>
                        <?php else: ?>
                        <a href="<?= $e($item['url']) ?>"<?= $item['current'] ? ' aria-current="page"' : '' ?>>
                            <i class="fas <?= $e($item['icon']) ?>" aria-hidden="true"></i>
                            <span><?= $e($item['short']) ?></span>
                            <?php if (!empty($item['badge'])): ?>
                                <span class="ah-bottombar__count"><?= $e($item['badge']) ?><?php if (!empty($item['badge_label'])): ?><span class="ah-visually-hidden"> — <?= $e($item['badge_label']) ?></span><?php endif; ?></span>
                            <?php endif; ?>
                        </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>
<div class="ah-toasts ah-no-print" id="ah-toasts" aria-live="polite"></div>
<?php if (self::$search !== []): ?>
<script type="application/json" id="ah-menu-data"><?= json_encode(self::$search, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
window.ALHASAN_CSRF = <?= json_encode(Csrf::token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= $e(self::asset('/assets/ui/alhasan.js')) ?>"></script>
</body>
</html>
        <?php
        self::$bottomBar = [];
        self::$search = [];
    }

    /**
     * Lokasi halaman pada peta menu. Kunci `active` dari halaman dipakai bila
     * memang ada di peta peran aktif; selain itu ditebak dari skrip + query.
     *
     * @param array{peran:?string, groups:array<int, array<string, mixed>>, extras:array<int, array<string, mixed>>} $map
     * @return array{key:string, tab:?int}|null
     */
    private static function locate(array $map, ?string $active): ?array
    {
        $found = Navigation::locate($map['groups'], $map['extras']);
        if ($found !== null) {
            return $found;
        }
        if ($active !== null && Navigation::find($map['groups'], $active) !== null) {
            return ['key' => $active, 'tab' => null];
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $extras
     * @return array<string, mixed>|null
     */
    private static function findExtra(array $extras, string $key): ?array
    {
        foreach ($extras as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Pilihan peran (A-2). Setiap pilihan adalah formulir POST ber-CSRF ke
     * `portal/peran.php`; server menolak peran di luar hak akun.
     *
     * @param array<int, string> $peranList
     */
    private static function roleChoices(array $peranList, ?string $aktif, string $kembali): void
    {
        $e = static fn (mixed $value): string => self::escape($value);
        echo '<p class="ah-peran__head">Lihat sebagai</p>';
        foreach ($peranList as $peran) {
            $isActive = $peran === $aktif;
            echo '<form method="post" action="' . $e(app_url('/portal/peran.php')) . '">' . Csrf::input()
                . '<input type="hidden" name="peran" value="' . $e($peran) . '">'
                . '<input type="hidden" name="kembali" value="' . $e($kembali) . '">'
                . '<button class="ah-menu__item ah-peran__item" type="submit"' . ($isActive ? ' aria-current="true"' : '') . '>'
                . '<span><b>' . $e(Peran::label($peran)) . '</b><small>' . $e(Peran::description($peran)) . '</small></span>'
                . ($isActive ? '<span class="ah-badge ah-badge--ok">Aktif</span>' : '<i class="fas fa-chevron-right" aria-hidden="true"></i>')
                . '</button></form>';
        }
        echo '<p class="ah-peran__note">Mengganti peran mengubah menu dan cakupan data. Hak akses tetap diperiksa server.</p>';
    }

    /**
     * Keluar lewat POST ber-CSRF dengan dialog konfirmasi (A-4). Tanpa
     * JavaScript formulir terkirim langsung; `admin/logout.php` tetap
     * menyediakan halaman konfirmasi untuk alamat lama.
     */
    private static function logoutForm(string $url, string $class): void
    {
        echo '<form method="post" action="' . self::escape($url) . '" class="ah-logout"'
            . ' data-confirm="Anda perlu masuk lagi untuk membuka data."'
            . ' data-confirm-title="Keluar dari Sistem Al Hasan?" data-confirm-ok="Ya, keluar">'
            . Csrf::input()
            . '<button class="' . self::escape($class) . '" type="submit"><i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i><span>Keluar</span></button>'
            . '</form>';
    }

    /**
     * Kotak pencarian menu (AQ). Hanya mencari nama menu dan tab yang boleh
     * dibuka akun ini, di peramban, tanpa permintaan ke server.
     */
    private static function searchBox(string $id, string $class): void
    {
        $e = static fn (mixed $value): string => self::escape($value);
        echo '<div class="ah-search ' . $e($class) . '" role="search">'
            . '<label class="ah-visually-hidden" for="' . $e($id) . '">Cari menu</label>'
            . '<i class="fas fa-magnifying-glass ah-search__icon" aria-hidden="true"></i>'
            . '<input class="ah-search__input" id="' . $e($id) . '" type="search" autocomplete="off" spellcheck="false"'
            . ' placeholder="Cari menu… (mis. penempatan)" role="combobox" aria-expanded="false" aria-autocomplete="list"'
            . ' aria-controls="' . $e($id) . '-hasil">'
            . '<ul class="ah-search__results" id="' . $e($id) . '-hasil" role="listbox" aria-label="Hasil pencarian menu" hidden></ul>'
            . '</div>';
    }

    /**
     * Lima notifikasi terbaru milik akun untuk pratinjau lonceng (N-3).
     *
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>|null null bila gagal dibaca.
     */
    private static function notificationPreview(array $user): ?array
    {
        try {
            return notification_center_service()->index($user, ['per_page' => 5])['items'];
        } catch (\Throwable $exception) {
            error_log('Pratinjau notifikasi gagal dibaca: ' . $exception->getMessage());

            return null;
        }
    }

    /**
     * Waktu ringkas: jam untuk hari ini, "Kemarin", nama hari dalam sepekan,
     * selain itu tanggal.
     */
    public static function shortTime(string $datetime): string
    {
        $time = strtotime($datetime);
        if ($time === false) {
            return $datetime;
        }
        $days = (int) floor((strtotime('today') - strtotime(date('Y-m-d', $time))) / 86400);
        if ($days <= 0) {
            return date('H.i', $time);
        }
        if ($days === 1) {
            return 'Kemarin';
        }
        if ($days < 7) {
            return self::dayName($time);
        }

        return self::date($time);
    }

    /**
     * Nama hari bahasa Indonesia; Minggu ditulis "Ahad" (keputusan T).
     */
    public static function dayName(int $time): string
    {
        return ['Ahad', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][(int) date('w', $time)];
    }

    public static function date(int $time, bool $withYear = true): string
    {
        $bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][(int) date('n', $time) - 1];

        return (int) date('j', $time) . ' ' . $bulan . ($withYear ? ' ' . date('Y', $time) : '');
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
