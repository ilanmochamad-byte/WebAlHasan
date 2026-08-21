<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Izin\IzinException;

require_once __DIR__ . '/_ui.php';

/**
 * Antrean tindakan per peran (Fase 2 §6, §7, §13).
 *
 *   - Murobi   : pengajuan berstatus `Diajukan` yang DIARAHKAN kepadanya.
 *   - Admin    : pengajuan berstatus `Perlu Penetapan Admin` (routing tidak tunggal).
 *   - Pengurus : pengajuan miliknya yang belum diputus.
 *   - Orang tua: tidak memiliki antrean tindakan (hanya membaca status).
 *
 * Cakupan tetap dipaksakan di server oleh IzinRepository::conditions().
 */

$requestedMode = isset($_GET['mode']) ? (string) $_GET['mode'] : null;
$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = [
    'antrean' => '1',
    'q' => (string) ($_GET['q'] ?? ''),
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
$judulAntrean = match ($scope['mode']) {
    Capabilities::MUROBI => 'Menunggu keputusan Anda',
    Capabilities::ADMIN => 'Menunggu penetapan murobi oleh admin',
    Capabilities::PENGURUS => 'Pengajuan Anda yang belum diputus',
    default => 'Tidak ada antrean tindakan untuk peran ini',
};

portal_header('Antrean Perizinan', $userCapabilities, $scope['mode'], $currentUser);
?>
<div class="border-bottom pb-3 mb-4">
    <h1 class="h3 mb-1">Antrean Perizinan</h1>
    <p class="text-muted mb-0"><?= portal_e($judulAntrean) ?> — <?= (int) $result['total'] ?> pengajuan.</p>
</div>

<?php portal_flash_render(); ?>
<?php portal_mode_switcher($userCapabilities, $scope['mode'], app_url('/portal/izin_antrean.php')); ?>

<?php if ($scope['mode'] === Capabilities::ORANG_TUA): ?>
    <div class="alert alert-info">
        Orang tua hanya dapat melihat status dan riwayat izin santri yang terhubung, tanpa tombol tindakan.
        Buka <a href="<?= portal_e(app_url('/portal/izin.php') . '?mode=' . rawurlencode($scope['mode'])) ?>">daftar perizinan</a>.
    </div>
<?php endif; ?>

<form method="get" class="card border-0 shadow-sm mb-4"><div class="card-body row g-3">
    <input type="hidden" name="mode" value="<?= portal_e($scope['mode']) ?>">
    <div class="col-md-5">
        <label class="form-label" for="q">Pencarian</label>
        <input class="form-control" id="q" name="q" value="<?= portal_e($filters['q']) ?>" placeholder="Nama santri, NIS, atau alasan">
    </div>
    <div class="col-md-3">
        <label class="form-label" for="date_from">Mulai dari</label>
        <input class="form-control" type="date" id="date_from" name="date_from" value="<?= portal_e($filters['date_from']) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label" for="date_to">Sampai</label>
        <input class="form-control" type="date" id="date_to" name="date_to" value="<?= portal_e($filters['date_to']) ?>">
    </div>
    <div class="col-md-1 d-flex align-items-end">
        <button class="btn btn-success w-100">Filter</button>
    </div>
</div></form>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th>ID</th><th>Santri</th><th>Rentang izin</th><th>Alasan</th>
                <th>Pengurus</th><th>Murobi</th><th>Status</th><th>Catatan routing</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $row): ?>
                <tr>
                    <td>#<?= (int) $row['id'] ?></td>
                    <td><?= portal_e($row['nama_santri']) ?><br><span class="text-muted small"><?= portal_e($row['nis']) ?></span></td>
                    <td class="small"><?= portal_e($row['tgl_izin']) ?><br>→ <?= portal_e($row['tgl_kembali']) ?></td>
                    <td class="small"><?= portal_e(mb_strimwidth((string) $row['alasan'], 0, 60, '…')) ?></td>
                    <td class="small"><?= portal_e($row['pengurus_label']) ?></td>
                    <td class="small"><?= portal_e($row['murobi_label']) ?></td>
                    <td><?= portal_status_badge((string) $row['status']) ?></td>
                    <td class="small text-muted"><?= portal_e(mb_strimwidth((string) ($row['routing_catatan'] ?? '—'), 0, 70, '…')) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-primary" href="<?= portal_e(app_url('/portal/izin_detail.php') . '?id=' . (int) $row['id'] . '&mode=' . rawurlencode($scope['mode'])) ?>">Proses</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($result['rows'] === []): ?>
                <tr><td colspan="9" class="text-center text-muted py-5">
                    Tidak ada pengajuan yang menunggu tindakan Anda saat ini.
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php portal_pagination((int) $result['total'], (int) $result['page'], (int) $result['per_page']); ?>
<?php portal_footer(); ?>
