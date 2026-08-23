<?php

declare(strict_types=1);

use App\Notification\NotificationException;

require_once __DIR__ . '/_ui.php';

/**
 * Pusat notifikasi website (V2 Fase 4).
 *
 * Terbuka bagi SELURUH peran perizinan: admin, pengurus, murobi, dan orang tua.
 * Halaman ini tidak memiliki pemilih peran karena notifikasi selalu milik satu
 * akun — bukan milik satu cakupan. Penerima diambil dari sesi; parameter URL
 * tidak pernah dipakai untuk menentukan pemilik (PRD Fase 4 kriteria 2).
 *
 * Menyediakan: jumlah belum dibaca, daftar, pagination, detail, tandai dibaca,
 * serta keadaan loading, kosong, dan galat.
 */

$center = notification_center_service();
$perPage = 20;

// --- Mutasi (POST) ----------------------------------------------------------
// CSRF sudah divalidasi `_guard.php` untuk setiap POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string) ($_POST['aksi'] ?? '');
    try {
        if ($aksi === 'tandai_dibaca') {
            $center->markRead($currentUser, (int) ($_POST['id'] ?? 0));
            portal_flash_set('sukses', 'Notifikasi ditandai sudah dibaca.');
        } elseif ($aksi === 'tandai_semua') {
            $hasil = $center->markAllRead($currentUser);
            portal_flash_set('sukses', $hasil['ditandai'] . ' notifikasi ditandai sudah dibaca.');
        } else {
            portal_flash_set('gagal', 'Aksi tidak dikenal.');
        }
    } catch (NotificationException $exception) {
        portal_flash_set('gagal', $exception->getMessage());
    }

    $kembali = app_url('/portal/notifikasi.php') . '?' . portal_query(['aksi' => null, 'id' => null]);
    header('Location: ' . $kembali);
    exit;
}

// --- Tampilan (GET) ---------------------------------------------------------
$galat = null;
$data = null;
$detail = null;
$detailId = (int) ($_GET['detail'] ?? 0);

try {
    $data = $center->index($currentUser, [
        'status' => (string) ($_GET['status'] ?? 'semua'),
        'page' => (int) ($_GET['page'] ?? 1),
        'per_page' => $perPage,
    ]);
    if ($detailId > 0) {
        // Membuka detail sekaligus menandai dibaca: satu langkah, satu maksud.
        $detail = $center->markRead($currentUser, $detailId)['notifikasi'];
        $data['jumlah_belum_dibaca'] = $center->unreadCount($currentUser)['jumlah'];
    }
} catch (NotificationException $exception) {
    // 403 karena ID milik pengguna lain TIDAK boleh menjatuhkan seluruh halaman;
    // daftar tetap tampil dengan pesan galat yang jelas.
    $galat = $exception->getMessage();
    if ($data === null) {
        http_response_code($exception->status());
    }
}

$filterAktif = (string) ($data['filters']['status'] ?? 'semua');
$belumDibaca = (int) ($data['jumlah_belum_dibaca'] ?? 0);
$modeAktif = $userCapabilities[0] ?? '';
portal_header('Notifikasi', $userCapabilities, $modeAktif, $currentUser);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center border-bottom pb-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Notifikasi</h1>
        <p class="text-muted mb-0">
            Pemberitahuan perizinan untuk akun Anda.
            <?php if ($belumDibaca > 0): ?>
                <span class="badge text-bg-danger ms-1"><?= $belumDibaca ?> belum dibaca</span>
            <?php else: ?>
                <span class="badge text-bg-secondary ms-1">Semua sudah dibaca</span>
            <?php endif; ?>
        </p>
    </div>
    <?php // Formulir ini selalu dirender (tombolnya yang dinonaktifkan saat
          // tidak ada yang perlu ditandai) sehingga token CSRF halaman selalu
          // tersedia, termasuk ketika daftar sedang kosong. ?>
    <form method="post" class="mb-0">
        <?= portal_csrf() ?>
        <input type="hidden" name="aksi" value="tandai_semua">
        <button class="btn btn-outline-success" <?= $belumDibaca > 0 ? '' : 'disabled' ?>>
            Tandai semua sudah dibaca
        </button>
    </form>
</div>

<?php portal_flash_render(); ?>

<?php if ($galat !== null): ?>
    <div class="alert alert-danger" role="alert">
        <strong>Notifikasi tidak dapat dibuka.</strong> <?= portal_e($galat) ?>
    </div>
<?php endif; ?>

<?php if ($detail !== null): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <span class="badge text-bg-light border mb-2"><?= portal_e($detail['event_label']) ?></span>
                    <h2 class="h5 mb-1"><?= portal_e($detail['judul']) ?></h2>
                    <p class="mb-2"><?= portal_e($detail['isi']) ?></p>
                    <p class="text-muted small mb-0">
                        Diterima <?= portal_e($detail['dibuat_pada']) ?>
                        <?php if ($detail['dibaca_pada'] !== null): ?>
                            · dibaca <?= portal_e($detail['dibaca_pada']) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($detail['pengajuan_id'] !== null): ?>
                        <?php // Tautan hanya membawa ID. Halaman detail izin tetap
                              // memeriksa cakupan pengguna di server sebelum menampilkan
                              // apa pun (PRD Fase 4 §5.10). ?>
                        <a class="btn btn-primary"
                           href="<?= portal_e(app_url('/portal/izin_detail.php') . '?id=' . (int) $detail['pengajuan_id']) ?>">
                            Buka detail izin
                        </a>
                    <?php endif; ?>
                    <a class="btn btn-outline-secondary" href="<?= portal_e(app_url('/portal/notifikasi.php')) ?>">Tutup</a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<ul class="nav nav-pills mb-3">
    <?php foreach ([
        'semua' => 'Semua',
        'belum_dibaca' => 'Belum dibaca',
        'sudah_dibaca' => 'Sudah dibaca',
    ] as $nilai => $label): ?>
        <li class="nav-item">
            <a class="nav-link <?= $filterAktif === $nilai ? 'active' : '' ?>"
               href="<?= portal_e(app_url('/portal/notifikasi.php') . '?' . portal_query(['status' => $nilai, 'page' => null, 'detail' => null])) ?>">
                <?= portal_e($label) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($data === null): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">
        Daftar notifikasi tidak dapat dimuat saat ini. Muat ulang halaman untuk mencoba lagi.
    </div></div>
<?php elseif ($data['items'] === []): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">
        <p class="mb-1 fw-semibold">Belum ada notifikasi</p>
        <p class="mb-0 small">
            <?= $filterAktif === 'belum_dibaca'
                ? 'Semua notifikasi Anda sudah dibaca.'
                : 'Pemberitahuan pengajuan, penetapan murobi, keputusan, pembatalan, dan koreksi akan tampil di sini.' ?>
        </p>
    </div></div>
<?php else: ?>
    <div class="card border-0 shadow-sm">
        <ul class="list-group list-group-flush">
            <?php foreach ($data['items'] as $item): ?>
                <li class="list-group-item <?= $item['dibaca'] ? '' : 'bg-light-subtle border-start border-4 border-success' ?>">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div class="pe-3">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <?php if (!$item['dibaca']): ?>
                                    <span class="badge text-bg-success">Baru</span>
                                <?php endif; ?>
                                <span class="badge text-bg-light border"><?= portal_e($item['event_label']) ?></span>
                                <?php if ($item['pengajuan_status'] !== null): ?>
                                    <?= portal_status_badge((string) $item['pengajuan_status']) ?>
                                <?php endif; ?>
                            </div>
                            <div class="fw-semibold"><?= portal_e($item['judul']) ?></div>
                            <div class="small"><?= portal_e($item['isi']) ?></div>
                            <div class="text-muted small mt-1"><?= portal_e($item['dibuat_pada']) ?></div>
                        </div>
                        <div class="d-flex gap-2 flex-shrink-0">
                            <a class="btn btn-sm btn-outline-primary"
                               href="<?= portal_e(app_url('/portal/notifikasi.php') . '?' . portal_query(['detail' => (int) $item['id']])) ?>">
                                Detail
                            </a>
                            <?php if (!$item['dibaca']): ?>
                                <form method="post" class="mb-0">
                                    <?= portal_csrf() ?>
                                    <input type="hidden" name="aksi" value="tandai_dibaca">
                                    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary">Tandai dibaca</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php portal_pagination(
        (int) $data['pagination']['total'],
        (int) $data['pagination']['current_page'],
        (int) $data['pagination']['per_page']
    ); ?>
<?php endif; ?>

<?php portal_footer(); ?>
