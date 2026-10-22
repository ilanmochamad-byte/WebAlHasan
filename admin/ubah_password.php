<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Http\SafeRedirect;
use App\Ui\Layout;

/**
 * Ganti kata sandi (redesain website Tahap 2, A-3).
 *
 * Wajib diselesaikan lebih dulu oleh pemegang kata sandi sementara sebelum
 * fungsi operasional lain terbuka (`Authorization::requireWebUser()`). Halaman
 * memakai kerangka biasa; saat penggantian wajib, kerangka tampil polos (tanpa
 * menu, pencarian, peran, dan lonceng) dan hanya menyisakan Keluar, karena
 * pengguna dengan kata sandi sementara belum boleh berpindah modul.
 *
 * Syarat tetap diperiksa server: minimal 12 karakter, huruf besar, huruf
 * kecil, dan angka. Daftar centang di peramban hanya bantuan tampilan.
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
        $error = 'Kata sandi saat ini tidak benar.'; $errorField = 'password_saat_ini';
    } elseif (strlen($password) < 12 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) {
        $error = 'Kata sandi baru minimal 12 karakter dan memuat huruf besar, huruf kecil, serta angka.'; $errorField = 'password_baru';
    } elseif ($password !== $confirmation) {
        $error = 'Ulangan kata sandi baru tidak sama.'; $errorField = 'konfirmasi_password';
    } elseif (!auth_repository()->updatePassword($user['id'], password_hash($password, PASSWORD_DEFAULT))) {
        $error = 'Kata sandi gagal diubah. Silakan coba lagi.';
    } else {
        $_SESSION['force_password_change'] = false;
        audit_logger()->log('password_changed', 'user', $user['id'], null, ['force_password_change' => false], $user['id']);
        $success = true;
    }
}

$wajib = !$success && !empty($user['force_password_change']);
$syarat = [
    'panjang' => 'Minimal 12 karakter',
    'besar' => 'Huruf besar',
    'kecil' => 'Huruf kecil',
    'angka' => 'Angka',
];

ah_page_open([
    'title' => 'Ganti kata sandi',
    'user' => $user,
    'flash' => null,
    'bare' => $wajib,
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Akun'],
        ['label' => 'Ganti kata sandi'],
    ],
]);
?>
<div class="ah-narrow">
<?php if ($success): ?>
    <?php Layout::note('success', 'Kata sandi berhasil diperbarui. Gunakan kata sandi baru pada saat masuk berikutnya.'); ?>
    <a class="btn btn-primary" href="<?= ah_e($continue[0]) ?>"><?= ah_e($continue[1]) ?></a>
<?php else: ?>
    <?php if ($wajib): ?>
        <?php Layout::note('warning', 'Ganti kata sandi sebelum melanjutkan. Kata sandi sementara wajib diganti sebelum menu lain dapat dibuka.'); ?>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <?php Layout::note('danger', $error); ?>
    <?php endif; ?>
    <section class="ah-card" aria-labelledby="ah-sandi-baru">
        <div class="ah-card__head"><span id="ah-sandi-baru">Kata sandi baru</span></div>
        <div class="ah-card__body">
            <form method="post" novalidate data-ah-password>
                <?= Csrf::input() ?>
                <?php if ($next !== null): ?><input type="hidden" name="next" value="<?= ah_e($next) ?>"><?php endif; ?>
                <div class="mb-3">
                    <label for="password_saat_ini" class="form-label">Kata sandi saat ini <span class="text-danger" aria-hidden="true">*</span></label>
                    <input id="password_saat_ini" <?= $errorField === 'password_saat_ini' ? 'aria-invalid="true"' : '' ?> class="form-control" type="password" name="password_saat_ini" autocomplete="current-password" aria-describedby="error-password_saat_ini" required><span class="ah-field-error" id="error-password_saat_ini"><?= $errorField === 'password_saat_ini' ? ah_e($error) : '' ?></span>
                </div>
                <div class="mb-3">
                    <label for="password_baru" class="form-label">Kata sandi baru <span class="text-danger" aria-hidden="true">*</span></label>
                    <input id="password_baru" <?= $errorField === 'password_baru' ? 'aria-invalid="true"' : '' ?> class="form-control" type="password" name="password_baru" autocomplete="new-password"
                           aria-describedby="syarat_password error-password_baru" required><span class="ah-field-error" id="error-password_baru"><?= $errorField === 'password_baru' ? ah_e($error) : '' ?></span>
                    <ul class="ah-checklist" id="syarat_password" aria-label="Syarat kata sandi">
                        <?php foreach ($syarat as $kunci => $label): ?>
                            <li data-ah-rule="<?= ah_e($kunci) ?>"><i class="fas fa-circle" aria-hidden="true"></i><span><?= ah_e($label) ?></span><span class="ah-visually-hidden" data-ah-rule-state></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="mb-3">
                    <label for="konfirmasi_password" class="form-label">Ulangi kata sandi baru <span class="text-danger" aria-hidden="true">*</span></label>
                    <input id="konfirmasi_password" <?= $errorField === 'konfirmasi_password' ? 'aria-invalid="true"' : '' ?> class="form-control" type="password" name="konfirmasi_password" autocomplete="new-password" aria-describedby="error-konfirmasi_password" required><span class="ah-field-error" id="error-konfirmasi_password"><?= $errorField === 'konfirmasi_password' ? ah_e($error) : '' ?></span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php if (!$wajib): ?>
                        <a class="btn btn-outline-secondary" href="<?= ah_e(app_url('/portal/index.php')) ?>">Batal</a>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="submit">Simpan kata sandi</button>
                </div>
            </form>
        </div>
    </section>
<?php endif; ?>
</div>
<?php
ah_page_close();
