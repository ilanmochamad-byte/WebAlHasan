<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Izin\IzinException;

require_once __DIR__ . '/_ui.php';

/**
 * Form pengajuan izin (Fase 2 §2).
 *
 * Daftar santri yang tampil DIBATASI SERVER pada cakupan penugasan pembimbing aktif
 * pengurus. Memilih santri di luar cakupan lewat manipulasi form tetap ditolak
 * `403` oleh IzinWorkflowService::create(); daftar ini hanya kenyamanan tampilan.
 */

$requestedMode = isset($_GET['mode']) ? (string) $_GET['mode'] : null;
$page = max(1, (int) ($_GET['page'] ?? 1));
$query = trim((string) ($_GET['q'] ?? ''));

try {
    $pilihan = izin_workflow_service()->selectableSantri($currentUser, $query, $page, 20, $requestedMode);
} catch (IzinException $exception) {
    http_response_code($exception->status());
    portal_header('Akses ditolak', $userCapabilities, $userCapabilities[0] ?? '', $currentUser);
    echo '<div class="alert alert-danger"><strong>' . (int) $exception->status() . '</strong> — ' . portal_e($exception->getMessage()) . '</div>';
    echo '<a class="btn btn-outline-secondary" href="' . portal_e(app_url('/portal/izin.php')) . '">Kembali ke daftar</a>';
    portal_footer();
    exit;
}

$scope = $pilihan['scope'];
$today = date('Y-m-d');
$santriTerpilih = (int) ($_GET['santri_id'] ?? 0);

portal_header('Buat Pengajuan Izin', $userCapabilities, $scope['mode'], $currentUser, ['show_heading' => false]);
?>
<div class="border-bottom pb-3 mb-4">
    <h1 class="h3 mb-1">Buat Pengajuan Izin</h1>
    <p class="text-muted mb-0"><?= portal_e($scope['label']) ?></p>
</div>

<?php portal_flash_render(); ?>
<?php portal_mode_switcher($userCapabilities, $scope['mode'], app_url('/portal/izin_buat.php')); ?>

<?php if ($scope['mode'] === Capabilities::ADMIN): ?>
    <div class="alert alert-warning small">
        <strong>Mode admin.</strong> Admin dapat mengajukan izin untuk santri mana pun bila diperlukan.
        Tindakan ini tercatat pada audit sebagai pengajuan oleh admin, tanpa pengurus pengaju.
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <strong><?= $scope['mode'] === Capabilities::ADMIN ? 'Cari santri' : 'Santri dalam cakupan Anda' ?></strong>
                <span class="text-muted small">(<?= (int) $pilihan['total'] ?>)</span>
            </div>
            <div class="card-body">
                <form method="get" class="d-flex gap-2 mb-3">
                    <input type="hidden" name="mode" value="<?= portal_e($scope['mode']) ?>">
                    <input class="form-control form-control-sm" name="q" value="<?= portal_e($query) ?>" placeholder="Nama santri atau NIS" aria-label="Cari santri">
                    <button class="btn btn-sm btn-success">Cari</button>
                </form>
                <?php if ($pilihan['rows'] === []): ?>
                    <p class="text-muted mb-0">
                        <?= $query !== ''
                            ? 'Tidak ada santri yang cocok dengan pencarian di dalam cakupan Anda.'
                            : ($scope['mode'] === Capabilities::PENGURUS
                                ? 'Belum ada penugasan pembimbing aktif untuk akun ini, sehingga belum ada santri yang dapat diajukan. Hubungi admin.'
                                : 'Belum ada santri aktif pada master data.') ?>
                    </p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($pilihan['rows'] as $row): ?>
                            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= (int) $row['id'] === $santriTerpilih ? 'active' : '' ?>"
                               href="?<?= portal_e(portal_query(['santri_id' => (int) $row['id']])) ?>#form-pengajuan">
                                <span><?= portal_e($row['nama_santri']) ?><br><span class="small opacity-75"><?= portal_e($row['nis']) ?></span></span>
                                <span class="small opacity-75"><?= portal_e($row['target_name'] ?? '') ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100" id="form-pengajuan">
            <div class="card-header bg-white"><strong>Rincian izin</strong></div>
            <div class="card-body">
                <form method="post" action="<?= portal_e(app_url('/portal/izin_aksi.php')) ?>">
                    <?= portal_csrf() ?>
                    <input type="hidden" name="aksi" value="buat">
                    <input type="hidden" name="mode" value="<?= portal_e($scope['mode']) ?>">
                    <input type="hidden" name="idempotency_key" value="<?= portal_e(portal_idempotency_key()) ?>">

                    <div class="mb-3">
                        <label class="form-label" for="santri_id">Santri</label>
                        <select class="form-select" id="santri_id" name="santri_id" required>
                            <option value="">— Pilih santri —</option>
                            <?php foreach ($pilihan['rows'] as $row): ?>
                                <option value="<?= (int) $row['id'] ?>" <?= (int) $row['id'] === $santriTerpilih ? 'selected' : '' ?>>
                                    <?= portal_e($row['nis'] . ' — ' . $row['nama_santri']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Hanya santri dalam cakupan Anda yang dapat diajukan; server memeriksa ulang setiap pengiriman.</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="tgl_izin">Tanggal izin</label>
                            <input class="form-control" type="date" id="tgl_izin" name="tgl_izin" value="<?= portal_e($today) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="tgl_kembali">Tanggal kembali</label>
                            <input class="form-control" type="date" id="tgl_kembali" name="tgl_kembali" value="<?= portal_e($today) ?>" required>
                            <div class="form-text">Tidak boleh mendahului tanggal izin.</div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label" for="alasan">Alasan izin</label>
                        <textarea class="form-control" id="alasan" name="alasan" rows="3" required minlength="3" maxlength="2000" placeholder="Contoh: menghadiri acara keluarga"></textarea>
                    </div>

                    <div class="mt-3">
                        <label class="form-label" for="catatan_pengurus">Catatan pengurus <span class="text-muted">(opsional)</span></label>
                        <textarea class="form-control" id="catatan_pengurus" name="catatan_pengurus" rows="2" maxlength="1000"></textarea>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button class="btn btn-success" type="submit">Kirim pengajuan</button>
                        <a class="btn btn-outline-secondary" href="<?= portal_e(app_url('/portal/izin.php') . '?mode=' . rawurlencode($scope['mode'])) ?>">Batal</a>
                    </div>
                    <p class="text-muted small mt-3 mb-0">
                        Setelah dikirim, sistem mencari murobi dari penugasan aktif yang cocok dengan kamar/kelas santri.
                        Bila tidak ada tepat satu murobi, pengajuan masuk ke antrean penetapan admin.
                    </p>
                </form>
            </div>
        </div>
    </div>
</div>
<?php portal_pagination((int) $pilihan['total'], (int) $pilihan['page'], (int) $pilihan['per_page']); ?>
<?php portal_footer(); ?>
