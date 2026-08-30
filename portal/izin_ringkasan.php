<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Izin\IzinException;

/**
 * Ringkasan modul perizinan.
 *
 * Sebelum paket perapihan V1–V2 isi halaman ini berada di `portal/index.php`.
 * Alamat itu kini menjadi SATU PINTU MASUK dan beranda seluruh sistem
 * (koreksi ke-7), sehingga ringkasan perizinan pindah ke berkas tersendiri.
 * Cakupan, mode, filter, dan seluruh aturan otorisasinya tidak berubah:
 * halaman ini tetap dijaga `portal/_guard.php` lewat `_ui.php`.
 */

require_once __DIR__ . '/_ui.php';

$requestedMode = isset($_GET['mode']) ? (string) $_GET['mode'] : null;

try {
    $overview = izin_service()->list($currentUser, [], 1, 5, $requestedMode);
    $santri = izin_service()->santriInScope($currentUser, $requestedMode);
    $antrean = izin_service()->queueCount($currentUser, $requestedMode);
} catch (IzinException $exception) {
    http_response_code($exception->status());
    exit(portal_e($exception->getMessage()));
}

$scope = $overview['scope'];
$summary = $overview['summary'];
$mode = rawurlencode($scope['mode']);

$aksi = '<a class="btn btn-outline-primary" href="' . portal_e(app_url('/portal/izin_antrean.php') . '?mode=' . $mode) . '">'
    . 'Antrean <span class="ah-badge ah-badge--muted">' . (int) $antrean . '</span></a>';
if (in_array($scope['mode'], [Capabilities::PENGURUS, Capabilities::ADMIN], true)) {
    $aksi .= '<a class="btn btn-primary" href="' . portal_e(app_url('/portal/izin_buat.php') . '?mode=' . $mode) . '">Buat pengajuan</a>';
}
$aksi .= '<a class="btn btn-outline-secondary" href="' . portal_e(app_url('/portal/izin.php') . '?mode=' . $mode) . '">Daftar lengkap</a>';

portal_header('Ringkasan Perizinan', $userCapabilities, $scope['mode'], $currentUser, [
    'description' => $scope['label'],
    'actions' => $aksi,
    'active' => 'izin.ringkasan',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Perizinan'],
        ['label' => 'Ringkasan'],
    ],
]);
?>
<?php portal_flash_render(); ?>
<?php portal_mode_switcher($userCapabilities, $scope['mode'], app_url('/portal/izin_ringkasan.php')); ?>

<?php ah_note('info', $scope['mode'] === Capabilities::ORANG_TUA
    ? 'Akun orang tua bersifat baca-saja: Anda dapat melihat status dan riwayat izin santri yang terhubung, tetapi tidak dapat membuat, mengubah, menyetujui, atau menolak pengajuan.'
    : 'Alur perizinan: pengajuan oleh pengurus, routing otomatis ke murobi, penetapan admin bila routing tidak tunggal, keputusan, pembatalan, dan koreksi. Setiap perubahan tercatat pada riwayat dan audit.',
    '<p class="small mb-0 mt-2">Data izin sebelum V2 ditandai <span class="ah-badge ah-badge--muted">Data warisan</span> karena sistem lama tidak mencatat pelakunya.</p>'
); ?>

<div class="ah-stats">
    <div class="ah-stat">
        <p class="ah-stat__label">Total</p>
        <p class="ah-stat__value"><?= (int) $summary['total'] ?></p>
        <p class="ah-stat__hint">pengajuan dalam cakupan Anda</p>
    </div>
    <?php foreach ($summary['per_status'] as $status => $count): ?>
        <div class="ah-stat">
            <p class="ah-stat__label"><?= portal_e($status) ?></p>
            <p class="ah-stat__value"><?= (int) $count ?></p>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <section class="ah-card h-100 mb-0" aria-labelledby="ah-terbaru">
            <div class="ah-card__head"><span id="ah-terbaru">Pengajuan terbaru</span>
                <a class="btn btn-sm btn-outline-primary" href="<?= portal_e(app_url('/portal/izin.php') . '?mode=' . $mode) ?>">Lihat semua</a>
            </div>
            <?php if ($overview['rows'] === []): ?>
                <div class="ah-card__body"><?= ah_empty(
                    'Belum ada pengajuan',
                    'Belum ada pengajuan izin dalam cakupan Anda.',
                    in_array($scope['mode'], [Capabilities::PENGURUS, Capabilities::ADMIN], true)
                        ? '<a class="btn btn-sm btn-primary" href="' . portal_e(app_url('/portal/izin_buat.php') . '?mode=' . $mode) . '">Buat pengajuan pertama</a>'
                        : null
                ) ?></div>
            <?php else: ?>
                <div class="ah-table-wrap"><table class="ah-table">
                    <caption class="ah-visually-hidden">Lima pengajuan izin terbaru dalam cakupan Anda</caption>
                    <thead><tr>
                        <th scope="col">ID</th><th scope="col">Santri</th><th scope="col">Rentang</th>
                        <th scope="col">Status</th><th scope="col">Sumber</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($overview['rows'] as $row): ?>
                        <tr>
                            <td><a href="<?= portal_e(app_url('/portal/izin_detail.php') . '?id=' . (int) $row['id'] . '&mode=' . $mode) ?>">#<?= (int) $row['id'] ?></a></td>
                            <td><?= portal_e($row['nama_santri']) ?><span class="ah-cell-sub"><?= portal_e($row['nis']) ?></span></td>
                            <td><?= portal_e($row['tgl_izin']) ?> → <?= portal_e($row['tgl_kembali']) ?></td>
                            <td><?= portal_status_badge((string) $row['status']) ?></td>
                            <td><?= ah_badge((string) $row['sumber_label'], 'muted') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="ah-card h-100 mb-0" aria-labelledby="ah-santri">
            <div class="ah-card__head"><span id="ah-santri"><?= $scope['mode'] === Capabilities::ORANG_TUA ? 'Santri yang terhubung dengan Anda' : 'Santri dalam cakupan Anda' ?></span></div>
            <div class="ah-card__body">
                <?php if ($scope['mode'] === Capabilities::ADMIN): ?>
                    <p class="text-muted mb-0">Admin melihat seluruh santri melalui menu master data.</p>
                <?php elseif ($scope['mode'] === Capabilities::MUROBI): ?>
                    <p class="text-muted mb-0">Murobi melihat santri melalui pengajuan yang diarahkan kepadanya.</p>
                <?php elseif ($santri === []): ?>
                    <p class="text-muted mb-0">
                        <?= $scope['mode'] === Capabilities::PENGURUS
                            ? 'Belum ada penugasan pembimbing aktif untuk akun ini. Hubungi admin.'
                            : 'Belum ada santri dengan relasi wali aktif untuk akun ini.' ?>
                    </p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($santri as $item): ?>
                            <li class="d-flex justify-content-between align-items-center gap-2 py-2 border-bottom">
                                <span><?= portal_e($item['nama_santri']) ?> <span class="text-muted small">(<?= portal_e($item['nis']) ?>)</span></span>
                                <span class="text-muted small"><?= portal_e($item['target_name'] ?? $item['hubungan'] ?? '') ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
<?php portal_footer(); ?>
