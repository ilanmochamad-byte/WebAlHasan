<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = master_data_service();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        $id = isset($_POST['id']) ? (int) $_POST['id'] : null;
        if ($action === 'save') {
            $savedId = $service->saveGuru($_POST, $id ?: null);
            master_flash('success', ($id ? 'Perubahan' : 'Guru baru') . ' berhasil disimpan. ID guru: ' . $savedId . '.');
        } else {
            $service->setGuruState((int) $id, $action);
            master_flash('success', 'Status guru diperbarui tanpa menghapus riwayat.');
        }
    } catch (MasterDataException $exception) {
        master_flash('danger', $exception->getMessage());
    }
    master_redirect('admin_guru.php');
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = ['q' => $_GET['q'] ?? '', 'state' => $_GET['state'] ?? 'active'];
$result = $service->guruList($filters, $page);
$selected = isset($_GET['id']) ? $service->guru((int) $_GET['id']) : null;
$mode = (string) ($_GET['action'] ?? '');
master_header('Master Data Guru');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center border-bottom pb-3 mb-4 gap-2">
    <div><h1 class="h3 mb-1">Master Data Guru</h1><p class="text-muted mb-0">Kelola identitas guru tanpa mengubah ID dan relasi lama.</p></div>
    <div class="d-flex gap-2"><a class="btn btn-outline-success" href="export_master.php?entity=guru&amp;<?= master_e(http_build_query($filters)) ?>"><i class="fas fa-file-csv me-1"></i> Ekspor CSV</a><a class="btn btn-success" href="admin_guru.php?action=create"><i class="fas fa-plus me-1"></i> Tambah Guru</a></div>
</div>

<?php if ($mode === 'create' || ($mode === 'edit' && $selected)): $record = $selected ?? []; ?>
<div class="card shadow-sm border-0 mb-4"><div class="card-header bg-white"><strong><?= $selected ? 'Ubah Guru' : 'Tambah Guru' ?></strong></div><div class="card-body">
    <form method="post" class="row g-3"><?= master_csrf() ?><input type="hidden" name="action" value="save"><?php if ($selected): ?><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><?php endif; ?>
        <div class="col-md-3"><label class="form-label" for="nip">NIP (boleh kosong)</label><input class="form-control" id="nip" name="nip" maxlength="30" value="<?= master_e($record['nip'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="nama_guru">Nama guru</label><input class="form-control" id="nama_guru" name="nama_guru" maxlength="100" required value="<?= master_e($record['nama_guru'] ?? '') ?>"></div>
        <div class="col-md-3"><label class="form-label" for="no_hp">Nomor HP</label><input class="form-control" id="no_hp" name="no_hp" maxlength="20" inputmode="tel" value="<?= master_e($record['no_hp'] ?? '') ?>"></div>
        <div class="col-md-2"><label class="form-label" for="status">Jenis tugas lama</label><select class="form-select" id="status" name="status"><?php foreach (['Guru','Pembimbing','Keduanya'] as $status): ?><option <?= ($record['status'] ?? 'Guru') === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><button class="btn btn-success" type="submit">Simpan</button> <a class="btn btn-light" href="admin_guru.php">Batal</a></div>
    </form>
</div></div>
<?php elseif ($mode === 'detail' && $selected): ?>
<div class="card shadow-sm border-0 mb-4"><div class="card-header bg-white d-flex justify-content-between"><strong>Detail Guru #<?= (int) $selected['id'] ?></strong><a href="admin_guru.php?action=edit&amp;id=<?= (int) $selected['id'] ?>">Ubah</a></div><div class="card-body row g-3">
    <div class="col-md-3"><small class="text-muted d-block">NIP</small><?= master_e($selected['nip'] ?: '-') ?></div><div class="col-md-4"><small class="text-muted d-block">Nama</small><?= master_e($selected['nama_guru']) ?></div><div class="col-md-3"><small class="text-muted d-block">Nomor HP</small><?= master_e($selected['no_hp'] ?: '-') ?></div><div class="col-md-2"><small class="text-muted d-block">Status</small><?= $selected['archived_at'] ? 'Diarsipkan' : ((int) $selected['is_active'] === 1 ? 'Aktif' : 'Nonaktif') ?></div>
</div></div>
<?php endif; ?>

<form method="get" class="card card-body border-0 shadow-sm mb-3"><div class="row g-2 align-items-end"><div class="col-md-6"><label class="form-label" for="q">Pencarian</label><input class="form-control" id="q" name="q" value="<?= master_e($filters['q']) ?>" placeholder="Nama, NIP, atau nomor HP"></div><div class="col-md-3"><label class="form-label" for="state">Filter status</label><select class="form-select" id="state" name="state"><?php foreach (['active'=>'Aktif','inactive'=>'Nonaktif','archived'=>'Arsip','all'=>'Semua'] as $value=>$label): ?><option value="<?= $value ?>" <?= $filters['state'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div><div class="col-md-3"><button class="btn btn-primary" type="submit">Terapkan</button> <a class="btn btn-light" href="admin_guru.php">Reset</a></div></div></form>

<div class="card shadow-sm border-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>ID</th><th>NIP</th><th>Nama</th><th>HP</th><th>Tugas</th><th>Status data</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($result['rows'] as $row): ?><tr><td><?= (int) $row['id'] ?></td><td><?= master_e($row['nip'] ?: '-') ?></td><td class="fw-semibold"><?= master_e($row['nama_guru']) ?></td><td><?= master_e($row['no_hp'] ?: '-') ?></td><td><?= master_e($row['status']) ?></td><td><?= $row['archived_at'] ? '<span class="badge text-bg-dark">Arsip</span>' : ((int) $row['is_active'] === 1 ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Nonaktif</span>') ?></td><td><div class="d-flex flex-wrap gap-1">
    <a class="btn btn-sm btn-outline-primary" href="?action=detail&amp;id=<?= (int) $row['id'] ?>">Detail</a><a class="btn btn-sm btn-outline-warning" href="?action=edit&amp;id=<?= (int) $row['id'] ?>">Ubah</a>
    <form method="post"><?= master_csrf() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-outline-secondary" name="action" value="<?= (int) $row['is_active'] === 1 ? 'deactivate' : 'activate' ?>"><?= (int) $row['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
    <form method="post" onsubmit="return confirm('Arsipkan data ini tanpa menghapus relasinya?')"><?= master_csrf() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-outline-danger" name="action" value="<?= $row['archived_at'] ? 'restore' : 'archive' ?>"><?= $row['archived_at'] ? 'Pulihkan' : 'Arsipkan' ?></button></form>
</div></td></tr><?php endforeach; ?>
<?php if (!$result['rows']): ?><tr><td colspan="7" class="text-center text-muted py-5">Tidak ada guru yang sesuai filter.</td></tr><?php endif; ?>
</tbody></table></div><div class="card-footer bg-white">Menampilkan <?= count($result['rows']) ?> dari <?= (int) $result['total'] ?> data.</div></div>
<?php master_pagination((int) $result['total'], $page, 20); master_footer(); ?>
