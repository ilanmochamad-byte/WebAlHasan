<?php

declare(strict_types=1);

use App\Izin\IzinException;
use App\Izin\IzinRepository;

require_once __DIR__ . '/_ui.php';

$requestedMode = isset($_GET['mode']) ? (string) $_GET['mode'] : null;
$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = [
    'q' => (string) ($_GET['q'] ?? ''),
    'status' => (string) ($_GET['status'] ?? ''),
    'source' => (string) ($_GET['source'] ?? ''),
    'date_from' => (string) ($_GET['date_from'] ?? ''),
    'date_to' => (string) ($_GET['date_to'] ?? ''),
];

try {
    $result = izin_service()->list($currentUser, $filters, $page, 20, $requestedMode);
} catch (IzinException $exception) {
    http_response_code($exception->status());
    exit(portal_e($exception->getMessage()));
}

$scope = $result['scope'];
portal_header('Daftar Perizinan', $userCapabilities, $scope['mode'], $currentUser);
?>
<div class="border-bottom pb-3 mb-4">
    <h1 class="h3 mb-1">Daftar Perizinan</h1>
    <p class="text-muted mb-0"><?= portal_e($scope['label']) ?> — <?= (int) $result['total'] ?> pengajuan.</p>
</div>

<?php portal_mode_switcher($userCapabilities, $scope['mode'], app_url('/portal/izin.php')); ?>

<form method="get" class="card border-0 shadow-sm mb-4"><div class="card-body row g-3">
    <input type="hidden" name="mode" value="<?= portal_e($scope['mode']) ?>">
    <div class="col-md-3">
        <label class="form-label" for="q">Pencarian</label>
        <input class="form-control" id="q" name="q" value="<?= portal_e($filters['q']) ?>" placeholder="Nama santri, NIS, atau alasan">
    </div>
    <div class="col-md-2">
        <label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="">Semua status</option>
            <?php foreach (IzinRepository::STATUSES as $status): ?>
                <option value="<?= portal_e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= portal_e($status) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label" for="source">Sumber data</label>
        <select class="form-select" id="source" name="source">
            <option value="">Semua sumber</option>
            <option value="legacy" <?= $filters['source'] === 'legacy' ? 'selected' : '' ?>>Data warisan</option>
            <option value="v2" <?= $filters['source'] === 'v2' ? 'selected' : '' ?>>V2</option>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label" for="date_from">Mulai dari</label>
        <input class="form-control" type="date" id="date_from" name="date_from" value="<?= portal_e($filters['date_from']) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label" for="date_to">Sampai</label>
        <input class="form-control" type="date" id="date_to" name="date_to" value="<?= portal_e($filters['date_to']) ?>">
    </div>
    <div class="col-md-1 d-flex align-items-end gap-2">
        <button class="btn btn-success w-100">Filter</button>
    </div>
    <div class="col-12">
        <a class="btn btn-sm btn-outline-secondary" href="<?= portal_e(app_url('/portal/izin.php') . '?mode=' . rawurlencode($scope['mode'])) ?>">Bersihkan filter</a>
    </div>
</div></form>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th>ID</th><th>Santri</th><th>Rentang izin</th><th>Alasan</th>
                <th>Pengurus</th><th>Murobi</th><th>Status</th><th>Keputusan</th><th>Sumber</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $row): ?>
                <tr>
                    <td>#<?= (int) $row['id'] ?></td>
                    <td><?= portal_e($row['nama_santri']) ?><br><span class="text-muted small"><?= portal_e($row['nis']) ?></span></td>
                    <td class="small"><?= portal_e($row['tgl_izin']) ?><br>→ <?= portal_e($row['tgl_kembali']) ?></td>
                    <td class="small"><?= portal_e(mb_strimwidth((string) $row['alasan'], 0, 70, '…')) ?></td>
                    <td class="small"><?= portal_e($row['pengurus_label']) ?></td>
                    <td class="small"><?= portal_e($row['murobi_label']) ?></td>
                    <td><?= portal_status_badge((string) $row['status']) ?></td>
                    <td class="small"><?= portal_e($row['keputusan_label']) ?></td>
                    <td><span class="badge text-bg-light border"><?= portal_e($row['sumber_label']) ?></span></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="<?= portal_e(app_url('/portal/izin_detail.php') . '?id=' . (int) $row['id'] . '&mode=' . rawurlencode($scope['mode'])) ?>">Detail</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($result['rows'] === []): ?>
                <tr><td colspan="10" class="text-center text-muted py-5">
                    Tidak ada pengajuan yang cocok dengan filter dalam cakupan Anda.
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php portal_pagination((int) $result['total'], (int) $result['page'], (int) $result['per_page']); ?>
<?php portal_footer(); ?>
