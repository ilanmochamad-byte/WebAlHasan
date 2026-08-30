<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Http\Csrf;
use App\Http\SafeRedirect;
use App\Izin\IzinException;
use App\Schedule\ScheduleException;
use App\Ui\Layout;

/**
 * SATU PINTU MASUK Sistem Al Hasan (koreksi ke-7, keputusan 30 Agustus 2026).
 *
 * Berkas ini punya dua wajah, ditentukan oleh keadaan sesi:
 *
 *   1. Belum masuk  → halaman "Masuk Sistem Al Hasan".
 *   2. Sudah masuk  → beranda yang menyusun panel dari kemampuan NYATA akun.
 *
 * Yang PENTING dan menjadi inti koreksi ini: halaman ini TIDAK memakai
 * `portal/_guard.php`. Guard itu menuntut kemampuan perizinan, sehingga guru
 * tanpa penugasan murobi selalu ditolak 403 di beranda umum. Halaman ini hanya
 * menuntut "sudah masuk"; setiap modul di dalamnya tetap memeriksa hak dan
 * cakupannya sendiri di server. Menyembunyikan panel BUKAN kontrol akses.
 *
 * Autentikasi memakai sistem yang sudah ada (`AuthService`, `AuthRepository`,
 * sesi, CSRF). Tidak ada sistem login kedua: formulir di bawah mengirim ke
 * `admin/cek_login.php`, penangan POST login yang sama dengan alamat lama.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

/**
 * Lencana status pengajuan untuk beranda.
 *
 * Beranda tidak memuat `portal/_ui.php` (berkas itu membawa guard kemampuan
 * perizinan), sehingga pemetaan status disediakan di sini dengan aturan yang
 * sama: teks status selalu ikut, warna hanya penguat.
 */
function portal_status_badge_beranda(string $status): string
{
    return ah_badge($status, match ($status) {
        'Disetujui' => 'ok',
        'Ditolak' => 'danger',
        'Dibatalkan' => 'muted',
        'Perlu Penetapan Admin' => 'warn',
        default => 'info',
    });
}

$currentUser = authorization()->currentUser();
$next = SafeRedirect::sanitize($_GET['next'] ?? null);

// ---------------------------------------------------------------------------
// 1. Belum masuk: halaman masuk.
// ---------------------------------------------------------------------------
if ($currentUser === null) {
    $pesan = match ((string) ($_GET['pesan'] ?? '')) {
        'gagal' => ['danger', 'Username atau password salah, atau akun sedang tidak aktif.'],
        'sesi' => ['warning', 'Sesi Anda berakhir. Silakan masuk kembali untuk melanjutkan.'],
        'logout' => ['success', 'Anda telah keluar dengan aman.'],
        'terkunci' => ['danger', login_throttle()->pesan()],
        'tanpa_akses' => ['warning', 'Akun Anda berhasil masuk, tetapi belum memiliki peran atau hubungan data yang sah. Hubungi admin pesantren.'],
        default => null,
    };
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Masuk Sistem Al Hasan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= ah_e(app_url('/assets/ui/alhasan.css')) ?>">
    <style>
        body.ah-masuk { min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 1.25rem; background: var(--ah-green-050); }
        .ah-masuk__card { width: 100%; max-width: 26rem; }
    </style>
</head>
<body class="ah ah-masuk">
<main class="ah-masuk__card">
    <div class="text-center mb-3">
        <span class="ah-brand__mark d-inline-flex mb-2" style="width:52px;height:52px;background:var(--ah-green-800);color:#fff" aria-hidden="true">
            <i class="fas fa-mosque fa-lg"></i>
        </span>
        <h1 class="h4 mb-1">Masuk Sistem Al Hasan</h1>
        <p class="text-muted small mb-0">Satu pintu masuk untuk admin, guru, murobi, pengurus, dan orang tua.</p>
    </div>

    <div class="ah-card"><div class="ah-card__body">
        <?php if ($pesan !== null) {
            Layout::note($pesan[0], $pesan[1]);
        } ?>

        <form action="<?= ah_e(app_url('/admin/cek_login.php')) ?>" method="post" novalidate>
            <?= Csrf::input() ?>
            <?php if ($next !== null): ?>
                <input type="hidden" name="next" value="<?= ah_e($next) ?>">
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label" for="username">Username</label>
                <input class="form-control" id="username" name="username" type="text"
                       autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" id="password" name="password" type="password"
                       autocomplete="current-password" required>
            </div>
            <button class="btn btn-primary w-100" type="submit">Masuk</button>
        </form>
    </div></div>

    <p class="text-center small text-muted mt-3 mb-0">
        Lupa password? Hubungi admin pesantren untuk mendapatkan password sementara.<br>
        <a class="link-secondary" href="<?= ah_e(app_url('/index.php')) ?>">← Kembali ke website utama</a>
    </p>
</main>
</body>
</html>
    <?php
    exit;
}

// ---------------------------------------------------------------------------
// 2. Sudah masuk: password sementara wajib diselesaikan lebih dulu.
// ---------------------------------------------------------------------------
if (!empty($currentUser['force_password_change'])) {
    header('Location: ' . app_url('/admin/ubah_password.php')
        . ($next === null ? '' : '?next=' . rawurlencode($next)));
    exit;
}

// Tujuan yang tersimpan sebelum masuk dipulihkan sekali. Guard halaman tujuan
// tetap memeriksa haknya sendiri, termasuk setelah berganti akun.
if ($next !== null) {
    header('Location: ' . $next);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
}

$context = ui_context($currentUser);
$capabilities = $context['capabilities'];
$roles = $currentUser['roles'] ?? [];
$shortcuts = landing_router()->shortcuts($currentUser);

// ---------------------------------------------------------------------------
// 3. Data panel. Setiap pemanggilan layanan menghitung ulang cakupannya sendiri;
//    kegagalan satu panel tidak boleh menjatuhkan seluruh beranda.
// ---------------------------------------------------------------------------
$panelIzin = null;
if (array_intersect(Capabilities::ALL, $capabilities) !== []) {
    try {
        $ringkas = izin_service()->list($currentUser, [], 1, 5);
        $panelIzin = [
            'scope' => $ringkas['scope'],
            'summary' => $ringkas['summary'],
            'rows' => $ringkas['rows'],
            'antrean' => izin_service()->queueCount($currentUser),
        ];
    } catch (IzinException $exception) {
        $panelIzin = ['error' => $exception->getMessage()];
    }
}

$panelPengajian = null;
if (in_array('guru', $roles, true) || in_array('admin', $roles, true)) {
    try {
        $jadwalAktif = schedule_service()->activeScheduleOptions($currentUser);
        $pertemuan = schedule_service()->meetings($currentUser);
        $panelPengajian = [
            'jadwal_aktif' => count($jadwalAktif),
            'pertemuan' => array_slice($pertemuan, 0, 5),
        ];
    } catch (ScheduleException $exception) {
        $panelPengajian = ['error' => $exception->getMessage()];
    }
}

$tanpaAkses = $shortcuts === [];

ah_page_open([
    'title' => 'Beranda',
    'heading' => 'Selamat datang, ' . ($currentUser['name'] ?? ''),
    'description' => 'Beranda Sistem Al Hasan menampilkan seluruh kemampuan yang benar-benar Anda miliki. '
        . 'Berpindah modul tidak memerlukan login ulang.',
    'user' => $currentUser,
    'capabilities' => $capabilities,
    'unread' => $context['unread'],
    'active' => 'beranda',
    'breadcrumbs' => [['label' => 'Beranda']],
]);
?>

<?php if ($tanpaAkses): ?>
    <?php Layout::note(
        'warning',
        'Akun Anda belum memiliki peran atau hubungan data yang sah, sehingga belum ada modul yang dapat dibuka.',
        '<p class="mb-0 mt-2 small">Yang perlu disiapkan admin: role akun, dan hubungan ke data master '
            . '(guru untuk pengajian, pengurus untuk perizinan, atau wali untuk akun orang tua). '
            . 'Selama itu belum ada, akun ini tidak memperoleh akses tambahan apa pun.</p>'
    ); ?>
<?php endif; ?>

<?php if ($shortcuts !== []): ?>
    <section aria-labelledby="ah-pintasan">
        <h2 class="h6 text-uppercase text-muted" id="ah-pintasan">Pintasan sesuai kemampuan Anda</h2>
        <div class="row g-3 mb-4">
            <?php foreach ($shortcuts as $shortcut): ?>
                <div class="col-md-6 col-xl-4">
                    <div class="ah-card h-100 mb-0"><div class="ah-card__body d-flex flex-column h-100">
                        <h3 class="h6 mb-1"><?= ah_e($shortcut['label']) ?></h3>
                        <p class="text-muted small flex-grow-1"><?= ah_e($shortcut['description']) ?></p>
                        <a class="btn btn-sm btn-outline-primary align-self-start" href="<?= ah_e($shortcut['url']) ?>">Buka</a>
                    </div></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<div class="row g-3">
    <?php if ($panelPengajian !== null): ?>
        <div class="col-lg-6">
            <section class="ah-card mb-0 h-100" aria-labelledby="ah-panel-pengajian">
                <div class="ah-card__head">
                    <span id="ah-panel-pengajian">Pengajian</span>
                    <a class="btn btn-sm btn-outline-primary" href="<?= ah_e(app_url('/admin/admin_pengajian.php')) ?>">Buka modul</a>
                </div>
                <?php if (isset($panelPengajian['error'])): ?>
                    <div class="ah-card__body"><?php Layout::note('danger', $panelPengajian['error']); ?></div>
                <?php elseif ($panelPengajian['pertemuan'] === []): ?>
                    <div class="ah-card__body"><?= ah_empty(
                        'Belum ada pertemuan',
                        $panelPengajian['jadwal_aktif'] > 0
                            ? 'Anda memiliki ' . $panelPengajian['jadwal_aktif'] . ' jadwal aktif. Buka modul Pengajian untuk membuka pertemuan pada tanggal tertentu.'
                            : 'Belum ada jadwal aktif untuk akun ini pada semester berjalan.',
                        '<a class="btn btn-sm btn-primary" href="' . ah_e(app_url('/admin/admin_pengajian.php?tab=jadwal')) . '">Lihat jadwal</a>'
                    ) ?></div>
                <?php else: ?>
                    <div class="ah-table-wrap"><table class="ah-table">
                        <caption class="ah-visually-hidden">Lima pertemuan pengajian terbaru dalam cakupan Anda</caption>
                        <thead><tr><th scope="col">Tanggal</th><th scope="col">Kelas</th><th scope="col">Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($panelPengajian['pertemuan'] as $pertemuan): ?>
                            <tr>
                                <td><?= ah_e($pertemuan['tanggal_pertemuan']) ?>
                                    <span class="ah-cell-sub"><?= ah_e($pertemuan['fan_ilmu'] ?? '') ?></span></td>
                                <td><?= ah_e($pertemuan['nama_kelas']) ?></td>
                                <td><?= ah_badge((string) $pertemuan['status'], match ((string) $pertemuan['status']) {
                                        'Selesai' => 'muted', 'Dibuka' => 'ok', default => 'info',
                                    }) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>

    <?php if ($panelIzin !== null): ?>
        <div class="col-lg-6">
            <section class="ah-card mb-0 h-100" aria-labelledby="ah-panel-izin">
                <div class="ah-card__head">
                    <span id="ah-panel-izin">Perizinan</span>
                    <a class="btn btn-sm btn-outline-primary" href="<?= ah_e(app_url('/portal/izin_ringkasan.php')) ?>">Buka modul</a>
                </div>
                <?php if (isset($panelIzin['error'])): ?>
                    <div class="ah-card__body"><?php Layout::note('danger', $panelIzin['error']); ?></div>
                <?php else: ?>
                    <div class="ah-card__body">
                        <p class="text-muted small mb-2">Cakupan aktif: <?= ah_e($panelIzin['scope']['label']) ?></p>
                        <div class="ah-stats mb-3">
                            <div class="ah-stat"><p class="ah-stat__label">Total</p><p class="ah-stat__value"><?= (int) $panelIzin['summary']['total'] ?></p></div>
                            <div class="ah-stat"><p class="ah-stat__label">Antrean</p><p class="ah-stat__value"><?= (int) $panelIzin['antrean'] ?></p></div>
                        </div>
                        <?php if ($panelIzin['rows'] === []): ?>
                            <p class="text-muted small mb-0">Belum ada pengajuan izin dalam cakupan Anda.</p>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach (array_slice($panelIzin['rows'], 0, 4) as $baris): ?>
                                    <li class="d-flex justify-content-between align-items-center gap-2 py-1 border-bottom">
                                        <a href="<?= ah_e(app_url('/portal/izin_detail.php?id=' . (int) $baris['id'])) ?>">
                                            #<?= (int) $baris['id'] ?> · <?= ah_e($baris['nama_santri']) ?>
                                        </a>
                                        <?= portal_status_badge_beranda((string) $baris['status']) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
</div>

<?php
ah_page_close();
