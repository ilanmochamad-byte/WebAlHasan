<?php

declare(strict_types=1);

use App\Izin\IzinException;

require_once __DIR__ . '/_ui.php';

$requestedMode = isset($_GET['mode']) ? (string) $_GET['mode'] : null;
$id = (int) ($_GET['id'] ?? 0);

try {
    $detail = izin_service()->detail($currentUser, $id, $requestedMode);
} catch (IzinException $exception) {
    // Cakupan diperiksa di server: mengubah parameter ID tidak pernah membuka
    // pengajuan milik pengurus, murobi, atau orang tua lain.
    http_response_code($exception->status());
    portal_header('Akses ditolak', $userCapabilities, $userCapabilities[0] ?? '', $currentUser);
    echo '<div class="alert alert-danger"><strong>' . (int) $exception->status() . '</strong> — ' . portal_e($exception->getMessage()) . '</div>';
    echo '<a class="btn btn-outline-secondary" href="' . portal_e(app_url('/portal/izin.php')) . '">Kembali ke daftar</a>';
    portal_footer();
    exit;
}

$scope = $detail['scope'];
$izin = $detail['pengajuan'];
$keputusan = $detail['keputusan'];

portal_header('Detail Izin #' . (int) $izin['id'], $userCapabilities, $scope['mode'], $currentUser);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center border-bottom pb-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Detail Izin #<?= (int) $izin['id'] ?></h1>
        <p class="text-muted mb-0"><?= portal_e($scope['label']) ?></p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= portal_e(app_url('/portal/izin.php') . '?mode=' . rawurlencode($scope['mode'])) ?>">Kembali ke daftar</a>
</div>

<?php if ($izin['is_legacy']): ?>
    <div class="alert alert-warning">
        <strong>Data warisan.</strong> Pengajuan ini berasal dari tabel <code>perizinan</code> V1 dengan ID
        <?= (int) $izin['legacy_perizinan_id'] ?>. Sistem lama tidak mencatat pengurus pengaju, murobi tujuan,
        maupun pemberi keputusan, sehingga kolom pelaku sengaja dikosongkan dan tidak diisi akun pengganti.
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>Rincian pengajuan</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Santri</dt><dd class="col-sm-8"><?= portal_e($izin['nama_santri']) ?> (<?= portal_e($izin['nis']) ?>)</dd>
                    <dt class="col-sm-4">Tanggal izin</dt><dd class="col-sm-8"><?= portal_e($izin['tgl_izin']) ?></dd>
                    <dt class="col-sm-4">Tanggal kembali</dt><dd class="col-sm-8"><?= portal_e($izin['tgl_kembali']) ?></dd>
                    <dt class="col-sm-4">Alasan</dt><dd class="col-sm-8"><?= nl2br(portal_e($izin['alasan'])) ?></dd>
                    <dt class="col-sm-4">Catatan pengurus</dt><dd class="col-sm-8"><?= $izin['catatan_pengurus'] === null ? '<span class="text-muted">—</span>' : nl2br(portal_e($izin['catatan_pengurus'])) ?></dd>
                    <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?= portal_status_badge((string) $izin['status']) ?></dd>
                    <dt class="col-sm-4">Pengurus pengaju</dt><dd class="col-sm-8"><?= portal_e($izin['pengurus_label']) ?></dd>
                    <dt class="col-sm-4">Murobi tujuan</dt><dd class="col-sm-8"><?= portal_e($izin['murobi_label']) ?></dd>
                    <dt class="col-sm-4">Tahun ajaran</dt><dd class="col-sm-8"><?= $izin['tahun_ajaran'] === null ? '<span class="text-muted">Data warisan</span>' : portal_e($izin['tahun_ajaran'] . ' ' . $izin['semester']) ?></dd>
                    <dt class="col-sm-4">Diajukan pada</dt><dd class="col-sm-8"><?= portal_e($izin['diajukan_pada'] ?? 'Data warisan') ?></dd>
                </dl>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>Keputusan</strong></div>
            <div class="card-body">
                <?php if ($keputusan === null): ?>
                    <p class="text-muted mb-0">
                        <?= $izin['is_legacy']
                            ? 'Data warisan: sistem lama hanya menyimpan status akhir tanpa alasan atau pemberi keputusan.'
                            : 'Belum ada keputusan untuk pengajuan ini.' ?>
                    </p>
                <?php else: ?>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Hasil</dt><dd class="col-sm-8"><?= portal_e($keputusan['hasil']) ?></dd>
                        <dt class="col-sm-4">Alasan keputusan</dt><dd class="col-sm-8"><?= nl2br(portal_e($keputusan['alasan'])) ?></dd>
                        <dt class="col-sm-4">Kapasitas</dt><dd class="col-sm-8"><?= portal_e($keputusan['kapasitas']) ?></dd>
                        <dt class="col-sm-4">Pemberi keputusan</dt><dd class="col-sm-8"><?= portal_e($keputusan['pemberi_keputusan'] ?? 'Data warisan') ?></dd>
                        <dt class="col-sm-4">Waktu</dt><dd class="col-sm-8"><?= portal_e($keputusan['diputus_pada']) ?></dd>
                    </dl>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><strong>Riwayat status</strong></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Waktu</th><th>Peristiwa</th><th>Pelaku</th></tr></thead>
                    <tbody>
                    <?php foreach ($detail['riwayat'] as $entry): ?>
                        <tr>
                            <td class="small"><?= portal_e($entry['created_at']) ?></td>
                            <td class="small">
                                <?= portal_e($entry['peristiwa']) ?>
                                <?php if ($entry['status_sesudah'] !== null): ?>
                                    <br><span class="text-muted"><?= portal_e(($entry['status_sebelum'] ?? '—') . ' → ' . $entry['status_sesudah']) ?></span>
                                <?php endif; ?>
                                <?php if ($entry['alasan'] !== null): ?>
                                    <br><span class="text-muted"><?= portal_e($entry['alasan']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= portal_e($entry['pelaku_nama'] ?? 'Data warisan') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($detail['riwayat'] === []): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">Belum ada riwayat.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php portal_footer(); ?>
