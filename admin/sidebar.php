<?php

declare(strict_types=1);

use App\Ui\Navigation;

/**
 * Komponen navigasi kompatibilitas untuk halaman admin lama.
 *
 * Sebelum paket perapihan V1–V2 berkas ini memuat `admin/_guard.php` — guard
 * khusus role admin — sehingga komponen navigasi tidak dapat dipakai peran lain
 * dan setiap halaman menyusun menunya sendiri. Guard itu kini DILEPAS dari
 * komponen navigasi (keputusan 30 Agustus 2026): halaman pemanggil tetap
 * menjaga dirinya sendiri, dan menu hanyalah tampilan.
 *
 * Halaman yang sudah didesain ulang memakai `App\Ui\Layout` dan tidak lagi
 * memuat berkas ini. Berkas ini dipertahankan agar halaman lama di luar
 * cakupan desain ulang (PSB, keuangan, alumni, konten website) tetap
 * mendapatkan menu yang sama isinya, di dalam struktur kolom Bootstrap yang
 * sudah mereka pakai.
 *
 * Menyembunyikan menu BUKAN kontrol akses.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$sidebarUser = authorization()->currentUser();
if ($sidebarUser === null) {
    return;
}
$sidebarContext = ui_context($sidebarUser);
$sidebarGroups = Navigation::forUser($sidebarUser, $sidebarContext['capabilities'], $sidebarContext['unread']);
$sidebarActive = Navigation::activeKey();
?>
<link rel="stylesheet" href="<?= ah_e(app_url('/assets/ui/alhasan.css')) ?>">
<nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block collapse ah-sidebar ah-legacy-sidebar" aria-label="Menu utama">
    <a class="ah-brand text-decoration-none d-flex mb-3 pt-2 px-2" style="color:var(--ah-green-800)" href="<?= ah_e(app_url('/portal/index.php')) ?>">
        <span class="ah-brand__mark" style="background:var(--ah-green-800);color:#fff" aria-hidden="true"><i class="fas fa-mosque"></i></span>
        <span class="ah-brand__text">Sistem Al Hasan<small>Pesantren Al Hasan Ciamis</small></span>
    </a>
    <?php foreach ($sidebarGroups as $sidebarGroup): ?>
        <p class="ah-nav-group"><?= ah_e($sidebarGroup['label']) ?></p>
        <ul class="ah-nav list-unstyled mb-0">
            <?php foreach ($sidebarGroup['items'] as $sidebarItem): ?>
                <li>
                    <a href="<?= ah_e($sidebarItem['url']) ?>"<?= $sidebarItem['key'] === $sidebarActive ? ' aria-current="page"' : '' ?>>
                        <i class="fas <?= ah_e($sidebarItem['icon']) ?>" aria-hidden="true"></i>
                        <span><?= ah_e($sidebarItem['label']) ?></span>
                        <?php if (!empty($sidebarItem['badge'])): ?>
                            <span class="ah-badge ah-badge--danger ah-nav__badge"><?= ah_e($sidebarItem['badge']) ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
    <p class="ah-nav-group">Sesi</p>
    <ul class="ah-nav list-unstyled mb-0">
        <li><a href="<?= ah_e(app_url('/admin/logout.php')) ?>"><i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i><span>Keluar</span></a></li>
    </ul>
</nav>
<style>
/* Halaman lama memakai grid Bootstrap, bukan flex shell baru. */
.ah-legacy-sidebar { position: static; max-height: none; }
@media (max-width: 991.98px) { .ah-legacy-sidebar { transform: none; box-shadow: none; position: static; height: auto; inset: auto; } }
</style>
<script>
    window.ALHASAN_CSRF = <?= json_encode(\App\Http\Csrf::token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(function (form) {
            if (form.querySelector('input[name="_csrf"]')) return;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_csrf';
            input.value = window.ALHASAN_CSRF;
            form.appendChild(input);
        });
        if (window.jQuery) {
            window.jQuery.ajaxSetup({headers: {'X-CSRF-Token': window.ALHASAN_CSRF}});
        }
    });
</script>
