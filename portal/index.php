<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Izin\IzinException;

require_once __DIR__ . '/_ui.php';

$requestedMode = isset($_GET['mode']) ? (string) $_GET['mode'] : null;

try {
    $overview = izin_service()->list($currentUser, [], 1, 5, $requestedMode);
    $santri = izin_service()->santriInScope($currentUser, $requestedMode);
} catch (IzinException $exception) {
    http_response_code($exception->status());
    exit(portal_e($exception->getMessage()));
}

$scope = $overview['scope'];
$summary = $overview['summary'];

portal_header('Ringkasan', $userCapabilities, $scope['mode'], $currentUser);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center border-bottom pb-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Ringkasan Perizinan</h1>
        <p class="text-muted mb-0"><?= portal_e($scope['label']) ?></p>
    </div>
    <a class="btn btn-success" href="<?= portal_e(app_url('/portal/izin.php') . '?mode=' . rawurlencode($scope['mode'])) ?>">Buka daftar lengkap</a>
</div>

<?php portal_mode_switcher($userCapabilities, $scope['mode'], app_url('/portal/index.php')); ?>

<div class="alert alert-info small">
    Fase 1 bersifat <strong>baca-saja</strong>. Pengajuan, keputusan, dan pembatalan mulai tersedia pada Fase 2.
    Data izin sebelum V2 ditandai <span class="badge text-bg-light border">Data warisan</span> karena sistem lama tidak mencatat pelakunya.
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <p class="text-muted small mb-1">Total</p><p class="h4 mb-0"><?= (int) $summary['total'] ?></p>
        </div></div>
    </div>
    <?php foreach ($summary['per_status'] as $status => $count): ?>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <p class="text-muted small mb-1"><?= portal_e($status) ?></p><p class="h4 mb-0"><?= (int) $count ?></p>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><strong>Pengajuan terbaru</strong></div>
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead><tr><th>ID</th><th>Santri</th><th>Rentang</th><th>Status</th><th>Sumber</th></tr></thead>
                    <tbody>
                    <?php foreach ($overview['rows'] as $row): ?>
                        <tr>
                            <td><a href="<?= portal_e(app_url('/portal/izin_detail.php') . '?id=' . (int) $row['id'] . '&mode=' . rawurlencode($scope['mode'])) ?>">#<?= (int) $row['id'] ?></a></td>
                            <td><?= portal_e($row['nama_santri']) ?><br><span class="text-muted small"><?= portal_e($row['nis']) ?></span></td>
                            <td class="small"><?= portal_e($row['tgl_izin']) ?> → <?= portal_e($row['tgl_kembali']) ?></td>
                            <td><?= portal_status_badge((string) $row['status']) ?></td>
                            <td><span class="badge text-bg-light border"><?= portal_e($row['sumber_label']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($overview['rows'] === []): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">Belum ada pengajuan izin dalam cakupan Anda.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <strong><?= $scope['mode'] === Capabilities::ORANG_TUA ? 'Santri yang terhubung dengan Anda' : 'Santri dalam cakupan Anda' ?></strong>
            </div>
            <div class="card-body">
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
                    <ul class="list-group list-group-flush">
                        <?php foreach ($santri as $item): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span><?= portal_e($item['nama_santri']) ?> <span class="text-muted small">(<?= portal_e($item['nis']) ?>)</span></span>
                                <span class="text-muted small"><?= portal_e($item['target_name'] ?? $item['hubungan'] ?? '') ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php portal_footer(); ?>
