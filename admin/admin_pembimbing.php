<?php

declare(strict_types=1);

use App\Izin\IzinException;

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = pembimbing_service();
$master = master_data_service();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            $service->create($_POST, (int) $currentUser['id']);
            master_flash('success', 'Penugasan pembimbing berhasil disimpan.');
        } else {
            $service->setState((int) ($_POST['id'] ?? 0), $action, (int) $currentUser['id']);
            master_flash('success', 'Status penugasan pembimbing diperbarui.');
        }
    } catch (IzinException $exception) {
        master_flash('danger', $exception->getMessage());
    }
    master_redirect('admin_pembimbing.php');
}

$q = App\Database\PageQuery::term($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = $service->page($q, $page);
$rows = $result['rows'];
$page = $result['page'];

$pengurusOptions = $service->activePengurus();
$years = $master->years();
$classes = $master->classes();
$rooms = $master->kamarOptions();

master_header('Penugasan Pembimbing', ['show_heading' => false]);
?>
<div class="border-bottom pb-3 mb-4">
    <h1 class="h3">Penugasan Pembimbing</h1>
    <p class="text-muted mb-0">
        Pembimbing adalah tugas inti atau tambahan <strong>pengurus</strong> — bukan guru dan bukan murobi.
        Cakupan penugasan ini menentukan santri yang boleh diajukan izinnya oleh pengurus tersebut.
    </p>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white"><strong>Tambah Penugasan</strong></div>
    <div class="card-body">
        <?php if ($pengurusOptions === []): ?>
            <p class="text-muted mb-0">Belum ada pengurus aktif. Tambahkan data pengurus terlebih dahulu pada menu Pengurus.</p>
        <?php else: ?>
        <form method="post" class="row g-3">
            <?= master_csrf() ?>
            <input type="hidden" name="action" value="save">
            <div class="col-md-4">
                <label class="form-label" for="pengurus_id">Pengurus</label>
                <select class="form-select" id="pengurus_id" name="pengurus_id" required>
                    <option value="">Pilih pengurus aktif</option>
                    <?php foreach ($pengurusOptions as $option): ?>
                        <option value="<?= (int) $option['id'] ?>"><?= master_e($option['nama'] . ' — ' . $option['jabatan']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="tahun_ajaran_id">Tahun ajaran</label>
                <select class="form-select" id="tahun_ajaran_id" name="tahun_ajaran_id" required>
                    <?php foreach ($years as $year): if ($year['archived_at']) { continue; } ?>
                        <option value="<?= (int) $year['id'] ?>" <?= $year['status'] === 'Aktif' ? 'selected' : '' ?>>
                            <?= master_e($year['tahun'] . ' ' . $year['semester'] . ($year['status'] === 'Aktif' ? ' — Aktif' : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="target_type">Jenis target</label>
                <select class="form-select" id="target_type" name="target_type">
                    <option>Kamar</option>
                    <option>Kelas</option>
                </select>
            </div>
            <div class="col-md-3 target-kamar">
                <label class="form-label" for="kamar_id">Kamar</label>
                <select class="form-select" id="kamar_id" name="kamar_id">
                    <option value="">Pilih kamar</option>
                    <?php foreach ($rooms as $room): ?>
                        <option value="<?= (int) $room['id'] ?>"><?= master_e($room['nama_kamar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 target-kelas d-none">
                <label class="form-label" for="kelas_id">Kelas</label>
                <select class="form-select" id="kelas_id" name="kelas_id">
                    <option value="">Pilih kelas</option>
                    <?php foreach ($classes as $class): if ($class['archived_at'] || (int) $class['is_active'] !== 1) { continue; } ?>
                        <option value="<?= (int) $class['id'] ?>"><?= master_e($class['nama_kelas']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="tanggal_mulai">Tanggal mulai</label>
                <input class="form-control" type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="tanggal_selesai">Tanggal selesai (opsional)</label>
                <input class="form-control" type="date" id="tanggal_selesai" name="tanggal_selesai">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button class="btn btn-success">Simpan Penugasan</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php ah_list_search($q, 'Cari nama, tahun, atau kelompok'); ?>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Pengurus</th><th>Semester</th><th>Cakupan</th><th>Rentang</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= master_e($row['pengurus_nama']) ?><br><span class="text-muted small"><?= master_e($row['jabatan']) ?></span></td>
                    <td><?= master_e($row['tahun'] . ' ' . $row['semester']) ?></td>
                    <td><?= master_e($row['target_type'] . ': ' . ($row['target_name'] ?? '—')) ?></td>
                    <td class="small"><?= master_e($row['tanggal_mulai'] . ' — ' . ($row['tanggal_selesai'] ?: 'seterusnya')) ?></td>
                    <td><?= $row['archived_at'] ? 'Arsip' : ((int) $row['is_active'] === 1 ? 'Aktif' : 'Nonaktif') ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <form method="post"><?= master_csrf() ?>
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" name="action" value="<?= (int) $row['is_active'] === 1 ? 'deactivate' : 'activate' ?>">
                                    <?= (int) $row['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?>
                                </button>
                            </form>
                            <form method="post"><?= master_csrf() ?>
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" name="action" value="<?= $row['archived_at'] ? 'restore' : 'archive' ?>">
                                    <?= $row['archived_at'] ? 'Pulihkan' : 'Arsipkan' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="text-muted text-center py-4"><?= $q !== '' ? 'Tidak ada hasil sesuai pencarian. Coba kata lain atau bersihkan pencarian.' : 'Belum ada penugasan pembimbing.' ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var type = document.getElementById('target_type');
    if (!type) { return; }
    var sync = function () {
        document.querySelector('.target-kamar').classList.toggle('d-none', type.value !== 'Kamar');
        document.querySelector('.target-kelas').classList.toggle('d-none', type.value !== 'Kelas');
    };
    type.addEventListener('change', sync);
    sync();
});
</script>
<?php master_pagination((int) $result['total'], $page, 20); master_footer(); ?>
