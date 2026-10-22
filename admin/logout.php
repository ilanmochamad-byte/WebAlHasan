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
 *
 * Redesain Tahap 2 (A-4): tombol Keluar di menu profil dan laci kini membuka
 * dialog lalu mengirim POST ber-CSRF ke berkas ini. GET tetap menampilkan
 * halaman konfirmasi (di dalam kerangka) untuk alamat lama dan peramban tanpa
 * JavaScript. Keluar tidak pernah terjadi lewat GET.
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

ah_page_open([
    'title' => 'Keluar',
    'user' => $user,
    'flash' => null,
    'bare' => !empty($user['force_password_change']),
    'show_heading' => false,
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Keluar'],
    ],
]);
?>
<div class="ah-narrow">
    <section class="ah-card" aria-labelledby="ah-keluar-judul"><div class="ah-card__body">
        <h1 class="h4" id="ah-keluar-judul">Keluar dari Sistem Al Hasan?</h1>
        <p class="text-muted">
            Sesi akun <strong><?= ah_e($user['name']) ?></strong> pada peramban ini akan diakhiri.
            Anda perlu masuk lagi untuk membuka data.
        </p>
        <form method="post" class="d-flex flex-wrap gap-2">
            <?= Csrf::input() ?>
            <a class="btn btn-outline-secondary" href="<?= ah_e(app_url('/portal/index.php')) ?>">Batal</a>
            <button class="btn btn-primary" type="submit">Ya, keluar</button>
        </form>
    </div></section>
</div>
<?php
ah_page_close();
