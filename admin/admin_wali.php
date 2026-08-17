<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';
$service = master_data_service();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'save') {
            $service->saveWali($_POST, $id ?: null);
            master_flash('success', 'Data orang tua/wali berhasil disimpan.');
        } elseif ($action === 'attach') {
            $service->attachWali($id, $_POST, (int) $currentUser['id']);
            master_flash('success', 'Relasi santri berhasil ditambahkan.');
        } elseif ($action === 'detach') {
            $service->detachWali($id, (int) ($_POST['relation_id'] ?? 0));
            master_flash('success', 'Relasi dilepas; data wali dan santri tetap tersimpan.');
        } else {
            $service->setWaliState($id, $action);
            master_flash('success', 'Status wali berhasil diperbarui.');
        }
    } catch (MasterDataException $exception) { master_flash('danger', $exception->getMessage()); }
    master_redirect('admin_wali.php' . (!empty($_POST['id']) ? '?action=detail&id=' . (int) $_POST['id'] : ''));
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = ['q' => $_GET['q'] ?? '', 'state' => $_GET['state'] ?? 'active'];
$result = $service->waliList($filters, $page);
$selected = isset($_GET['id']) ? $service->wali((int) $_GET['id']) : null;
$mode = (string) ($_GET['action'] ?? '');
master_header('Orang Tua dan Wali');
?>
<div class="d-flex justify-content-between border-bottom pb-3 mb-4"><div><h1 class="h3">Orang Tua/Wali</h1><p class="text-muted mb-0">Satu wali dapat dihubungkan ke satu atau lebih santri.</p></div><a class="btn btn-success align-self-center" href="?action=create">Tambah Wali</a></div>
<?php if ($mode === 'create' || ($mode === 'edit' && $selected)): $record=$selected??[]; ?><div class="card border-0 shadow-sm mb-4"><div class="card-body"><form method="post" class="row g-3"><?= master_csrf() ?><input type="hidden" name="action" value="save"><?php if($selected): ?><input type="hidden" name="id" value="<?= (int)$selected['id'] ?>"><?php endif; ?><div class="col-md-4"><label class="form-label">Nama</label><input class="form-control" name="nama" required value="<?= master_e($record['nama']??'') ?>"></div><div class="col-md-3"><label class="form-label">Nomor HP</label><input class="form-control" name="no_hp" inputmode="tel" value="<?= master_e($record['no_hp']??'') ?>"></div><div class="col-md-5"><label class="form-label">Alamat</label><input class="form-control" name="alamat" value="<?= master_e($record['alamat']??'') ?>"></div><div><button class="btn btn-success">Simpan</button> <a class="btn btn-light" href="admin_wali.php">Batal</a></div></form></div></div><?php endif; ?>
<?php if ($mode === 'detail' && $selected): ?><div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white d-flex justify-content-between"><strong>Detail <?= master_e($selected['nama']) ?></strong><a href="?action=edit&amp;id=<?= (int)$selected['id'] ?>">Ubah</a></div><div class="card-body"><p><strong>HP:</strong> <?= master_e($selected['no_hp']?:'-') ?><br><strong>Alamat:</strong> <?= master_e($selected['alamat']?:'-') ?></p><h2 class="h6">Santri yang terhubung</h2><div class="table-responsive"><table class="table table-sm"><thead><tr><th>NIS</th><th>Santri</th><th>Hubungan</th><th>Utama</th><th>Status relasi</th><th></th></tr></thead><tbody><?php foreach($selected['relations'] as $relation): ?><tr><td><?= master_e($relation['nis']) ?></td><td><?= master_e($relation['nama_santri']) ?></td><td><?= master_e($relation['hubungan']) ?></td><td><?= (int)$relation['is_primary']===1?'Ya':'Tidak' ?></td><td><?= $relation['archived_at']?'Arsip':'Aktif' ?></td><td><?php if(!$relation['archived_at']): ?><form method="post" onsubmit="return confirm('Arsipkan relasi ini?')"><?= master_csrf() ?><input type="hidden" name="id" value="<?= (int)$selected['id'] ?>"><input type="hidden" name="relation_id" value="<?= (int)$relation['id'] ?>"><button class="btn btn-sm btn-outline-danger" name="action" value="detach">Arsipkan relasi</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$selected['relations']): ?><tr><td colspan="6" class="text-muted">Belum ada relasi.</td></tr><?php endif; ?></tbody></table></div>
<form method="post" class="row g-2 border-top pt-3"><?= master_csrf() ?><input type="hidden" name="id" value="<?= (int)$selected['id'] ?>"><div class="col-md-5"><label class="form-label">Tambahkan santri</label><select class="form-select" name="santri_id" required><option value="">Pilih santri</option><?php foreach($service->santriOptions() as $santri): ?><option value="<?= (int)$santri['id'] ?>"><?= master_e($santri['nis'].' — '.$santri['nama_santri']) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Hubungan</label><input class="form-control" name="hubungan" maxlength="30" placeholder="Ayah/Ibu/Wali" required></div><div class="col-md-2 d-flex align-items-end"><label><input type="checkbox" name="is_primary" value="1"> Kontak utama</label></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary" name="action" value="attach">Hubungkan</button></div></form></div></div><?php endif; ?>
<form method="get" class="card card-body border-0 shadow-sm mb-3"><div class="row g-2"><div class="col-md-6"><input class="form-control" name="q" value="<?= master_e($filters['q']) ?>" placeholder="Cari nama atau nomor HP"></div><div class="col-md-3"><select class="form-select" name="state"><?php foreach(['active'=>'Aktif','inactive'=>'Nonaktif','archived'=>'Arsip','all'=>'Semua'] as $v=>$l): ?><option value="<?= $v ?>" <?= $filters['state']===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div><div class="col-md-3"><button class="btn btn-primary">Terapkan</button></div></div></form>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Nama</th><th>HP</th><th>Santri terhubung</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php foreach($result['rows'] as $row): ?><tr><td><?= master_e($row['nama']) ?></td><td><?= master_e($row['no_hp']?:'-') ?></td><td><?= master_e($row['santri']?:'-') ?></td><td><?= $row['archived_at']?'Arsip':((int)$row['is_active']===1?'Aktif':'Nonaktif') ?></td><td><div class="d-flex gap-1"><a class="btn btn-sm btn-outline-primary" href="?action=detail&amp;id=<?= (int)$row['id'] ?>">Detail</a><a class="btn btn-sm btn-outline-warning" href="?action=edit&amp;id=<?= (int)$row['id'] ?>">Ubah</a><form method="post"><?= master_csrf() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline-secondary" name="action" value="<?= (int)$row['is_active']===1?'deactivate':'activate' ?>"><?= (int)$row['is_active']===1?'Nonaktifkan':'Aktifkan' ?></button></form><form method="post"><?= master_csrf() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline-danger" name="action" value="<?= $row['archived_at']?'restore':'archive' ?>"><?= $row['archived_at']?'Pulihkan':'Arsipkan' ?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php master_pagination((int)$result['total'],$page,20); master_footer(); ?>
