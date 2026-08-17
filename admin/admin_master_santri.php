<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;
use App\MasterData\PhotoStorage;

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = master_data_service();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        $id = isset($_POST['id']) ? (int) $_POST['id'] : null;
        if ($action === 'save') {
            $existing = $id ? $service->santri($id) : null;
            $_POST['foto'] = (new PhotoStorage(APP_ROOT . '/gambar_galeri'))->store($_FILES['foto_upload'] ?? null, (string) ($existing['foto'] ?? 'default.jpg'));
            $savedId = $service->saveSantri($_POST, $id ?: null);
            master_flash('success', ($id ? 'Perubahan' : 'Santri baru') . ' berhasil disimpan. ID santri: ' . $savedId . '.');
        } else {
            $service->setSantriState((int) $id, $action);
            master_flash('success', 'Status santri diperbarui tanpa menghapus riwayat.');
        }
    } catch (MasterDataException $exception) {
        master_flash('danger', $exception->getMessage());
    }
    master_redirect('admin_master_santri.php');
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = ['q' => $_GET['q'] ?? '', 'state' => $_GET['state'] ?? 'active', 'gender' => $_GET['gender'] ?? '', 'kelas_id' => $_GET['kelas_id'] ?? ''];
$result = $service->santriList($filters, $page);
$selected = isset($_GET['id']) ? $service->santri((int) $_GET['id']) : null;
$history = $selected ? $service->membershipHistory((int) $selected['id']) : [];
$classes = $service->classes();
$mode = (string) ($_GET['action'] ?? '');
master_header('Master Data Santri');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center border-bottom pb-3 mb-4 gap-2"><div><h1 class="h3 mb-1">Master Data Santri</h1><p class="text-muted mb-0">ID, data lama, foto, dan seluruh relasi tetap dipertahankan.</p></div><div class="d-flex gap-2"><button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#importModal"><i class="fas fa-file-import me-1"></i> Impor Lama</button><a class="btn btn-outline-success" href="export_master.php?entity=santri&amp;<?= master_e(http_build_query($filters)) ?>"><i class="fas fa-file-csv me-1"></i> Ekspor CSV</a><a class="btn btn-success" href="admin_master_santri.php?action=create"><i class="fas fa-plus me-1"></i> Tambah Santri</a></div></div>

<?php if ($mode === 'create' || ($mode === 'edit' && $selected)): $record = $selected ?? []; ?>
<div class="card shadow-sm border-0 mb-4"><div class="card-header bg-white"><strong><?= $selected ? 'Ubah Santri #' . (int) $selected['id'] : 'Tambah Santri' ?></strong></div><div class="card-body">
<form method="post" enctype="multipart/form-data" class="row g-3"><?= master_csrf() ?><input type="hidden" name="action" value="save"><?php if ($selected): ?><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><?php endif; ?>
    <div class="col-md-3"><label class="form-label">NIS</label><input class="form-control" name="nis" maxlength="20" required value="<?= master_e($record['nis'] ?? '') ?>"></div>
    <div class="col-md-6"><label class="form-label">Nama santri</label><input class="form-control" name="nama_santri" maxlength="100" required value="<?= master_e($record['nama_santri'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label">Jenis kelamin</label><select class="form-select" name="jenis_kelamin" required><option value="L" <?= ($record['jenis_kelamin'] ?? '') === 'L' ? 'selected' : '' ?>>Laki-laki</option><option value="P" <?= ($record['jenis_kelamin'] ?? '') === 'P' ? 'selected' : '' ?>>Perempuan</option></select></div>
    <div class="col-md-4"><label class="form-label">Tempat lahir</label><input class="form-control" name="tempat_lahir" maxlength="50" value="<?= master_e($record['tempat_lahir'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label">Tanggal lahir</label><input class="form-control" type="date" name="tgl_lahir" required value="<?= master_e($record['tgl_lahir'] ?? '') ?>"></div>
    <div class="col-md-5"><label class="form-label">Foto baru (opsional, maks. 2 MB)</label><input class="form-control" type="file" name="foto_upload" accept="image/jpeg,image/png,image/webp"><small class="text-muted">Foto lama tidak dihapus saat diganti.</small></div>
    <div class="col-12"><label class="form-label">Alamat</label><textarea class="form-control" name="alamat" rows="2"><?= master_e($record['alamat'] ?? '') ?></textarea></div>
    <?php foreach (['desa'=>'Desa/Kelurahan','kecamatan'=>'Kecamatan','kab_kota'=>'Kabupaten/Kota','provinsi'=>'Provinsi'] as $name=>$label): ?><div class="col-md-3"><label class="form-label"><?= $label ?></label><input class="form-control" name="<?= $name ?>" maxlength="50" value="<?= master_e($record[$name] ?? '') ?>"></div><?php endforeach; ?>
    <div class="col-md-3"><label class="form-label">Nama ayah (kolom lama)</label><input class="form-control" name="nama_ayah" maxlength="100" value="<?= master_e($record['nama_ayah'] ?? '') ?>"></div><div class="col-md-3"><label class="form-label">HP ayah</label><input class="form-control" name="no_hp_ayah" inputmode="tel" value="<?= master_e($record['no_hp_ayah'] ?? '') ?>"></div><div class="col-md-3"><label class="form-label">Nama ibu (kolom lama)</label><input class="form-control" name="nama_ibu" maxlength="100" value="<?= master_e($record['nama_ibu'] ?? '') ?>"></div><div class="col-md-3"><label class="form-label">HP ibu</label><input class="form-control" name="no_hp_ibu" inputmode="tel" value="<?= master_e($record['no_hp_ibu'] ?? '') ?>"></div>
    <div class="col-md-6"><label class="form-label">Sekolah asal</label><input class="form-control" name="asal_sekolah" maxlength="100" value="<?= master_e($record['asal_sekolah'] ?? '') ?>"></div><div class="col-md-6"><label class="form-label">Sekolah saat ini</label><input class="form-control" name="sekolah_saat_ini" maxlength="50" value="<?= master_e($record['sekolah_saat_ini'] ?? '') ?>"></div>
    <div class="col-12"><div class="alert alert-info py-2">Relasi orang tua/wali terpusat dikelola melalui menu <strong>Orang Tua/Wali</strong>. Kolom ayah/ibu lama tetap tersedia untuk kompatibilitas.</div><button class="btn btn-success">Simpan</button> <a class="btn btn-light" href="admin_master_santri.php">Batal</a></div>
</form></div></div>
<?php elseif ($mode === 'detail' && $selected): ?>
<div class="card shadow-sm border-0 mb-4"><div class="card-header bg-white d-flex justify-content-between"><strong>Detail Santri #<?= (int) $selected['id'] ?></strong><a href="?action=edit&amp;id=<?= (int) $selected['id'] ?>">Ubah</a></div><div class="card-body"><div class="row g-3"><div class="col-md-2"><small class="text-muted d-block">NIS</small><?= master_e($selected['nis']) ?></div><div class="col-md-5"><small class="text-muted d-block">Nama</small><?= master_e($selected['nama_santri']) ?></div><div class="col-md-2"><small class="text-muted d-block">Jenis kelamin</small><?= $selected['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan' ?></div><div class="col-md-3"><small class="text-muted d-block">Status data</small><?= $selected['archived_at'] ? 'Arsip' : ((int) $selected['is_active'] === 1 ? 'Aktif' : 'Nonaktif') ?></div><div class="col-md-6"><small class="text-muted d-block">Tempat, tanggal lahir</small><?= master_e($selected['tempat_lahir']) ?>, <?= master_e($selected['tgl_lahir']) ?></div><div class="col-md-6"><small class="text-muted d-block">Sekolah saat ini</small><?= master_e($selected['sekolah_saat_ini'] ?: '-') ?></div><div class="col-12"><small class="text-muted d-block">Alamat</small><?= master_e($selected['alamat']) ?>, <?= master_e($selected['desa']) ?>, <?= master_e($selected['kecamatan']) ?>, <?= master_e($selected['kab_kota']) ?>, <?= master_e($selected['provinsi']) ?></div></div>
<h2 class="h6 mt-4">Riwayat keanggotaan kelas</h2><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Tahun</th><th>Kelas</th><th>Mulai</th><th>Selesai</th><th>Status</th></tr></thead><tbody><?php foreach ($history as $membership): ?><tr><td><?= master_e($membership['tahun'] . ' ' . $membership['semester']) ?></td><td><?= master_e($membership['nama_kelas']) ?></td><td><?= master_e($membership['tanggal_mulai'] ?: '-') ?></td><td><?= master_e($membership['tanggal_selesai'] ?: '-') ?></td><td><?= master_e($membership['status']) ?></td></tr><?php endforeach; ?><?php if (!$history): ?><tr><td colspan="5" class="text-muted">Belum ada riwayat kelas.</td></tr><?php endif; ?></tbody></table></div></div></div>
<?php endif; ?>

<form method="get" class="card card-body border-0 shadow-sm mb-3"><div class="row g-2 align-items-end"><div class="col-md-4"><label class="form-label">Pencarian</label><input class="form-control" name="q" value="<?= master_e($filters['q']) ?>" placeholder="Nama, NIS, atau sekolah"></div><div class="col-md-2"><label class="form-label">Status</label><select class="form-select" name="state"><?php foreach (['active'=>'Aktif','inactive'=>'Nonaktif','archived'=>'Arsip','all'=>'Semua'] as $value=>$label): ?><option value="<?= $value ?>" <?= $filters['state'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">Jenis kelamin</label><select class="form-select" name="gender"><option value="">Semua</option><option value="L" <?= $filters['gender'] === 'L' ? 'selected' : '' ?>>Laki-laki</option><option value="P" <?= $filters['gender'] === 'P' ? 'selected' : '' ?>>Perempuan</option></select></div><div class="col-md-2"><label class="form-label">Kelas aktif</label><select class="form-select" name="kelas_id"><option value="">Semua</option><?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>" <?= (int) $filters['kelas_id'] === (int) $class['id'] ? 'selected' : '' ?>><?= master_e($class['nama_kelas']) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><button class="btn btn-primary">Terapkan</button> <a class="btn btn-light" href="admin_master_santri.php">Reset</a></div></div></form>

<div class="card shadow-sm border-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>ID</th><th>NIS</th><th>Nama</th><th>L/P</th><th>Kelas</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php foreach ($result['rows'] as $row): ?><tr><td><?= (int) $row['id'] ?></td><td><?= master_e($row['nis']) ?></td><td class="fw-semibold"><?= master_e($row['nama_santri']) ?></td><td><?= master_e($row['jenis_kelamin']) ?></td><td><?= master_e($row['nama_kelas'] ?: '-') ?></td><td><?= $row['archived_at'] ? '<span class="badge text-bg-dark">Arsip</span>' : ((int) $row['is_active'] === 1 ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Nonaktif</span>') ?></td><td><div class="d-flex flex-wrap gap-1"><a class="btn btn-sm btn-outline-primary" href="?action=detail&amp;id=<?= (int) $row['id'] ?>">Detail</a><a class="btn btn-sm btn-outline-warning" href="?action=edit&amp;id=<?= (int) $row['id'] ?>">Ubah</a><form method="post"><?= master_csrf() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-outline-secondary" name="action" value="<?= (int) $row['is_active'] === 1 ? 'deactivate' : 'activate' ?>"><?= (int) $row['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button></form><form method="post" onsubmit="return confirm('Arsipkan tanpa menghapus riwayat?')"><?= master_csrf() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-outline-danger" name="action" value="<?= $row['archived_at'] ? 'restore' : 'archive' ?>"><?= $row['archived_at'] ? 'Pulihkan' : 'Arsipkan' ?></button></form></div></td></tr><?php endforeach; ?><?php if (!$result['rows']): ?><tr><td colspan="7" class="text-center text-muted py-5">Tidak ada santri yang sesuai filter.</td></tr><?php endif; ?></tbody></table></div><div class="card-footer bg-white">Menampilkan <?= count($result['rows']) ?> dari <?= (int) $result['total'] ?> data.</div></div>
<?php master_pagination((int) $result['total'], $page, 20); ?>
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importTitle" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="importTitle">Impor Format Santri Lama</h2><button class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div><div class="modal-body"><p class="text-muted">Urutan kolom: NIS, nama, L/P, tempat lahir, tanggal lahir (YYYY-MM-DD), alamat, desa, kecamatan, kabupaten/kota, provinsi, ayah, HP ayah, ibu, HP ibu, sekolah asal, sekolah saat ini.</p><input class="form-control" type="file" id="importFile" accept=".xlsx,.xls"><pre class="bg-light border rounded p-3 mt-3 mb-0" id="importResult">Baris valid akan disimpan; baris gagal dilaporkan tanpa membatalkan baris lain.</pre></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button><button class="btn btn-primary" id="importButton">Validasi dan Impor</button></div></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
document.getElementById('importButton').addEventListener('click', async function () {
    const file = document.getElementById('importFile').files[0];
    const result = document.getElementById('importResult');
    if (!file) { result.textContent = 'Pilih berkas Excel terlebih dahulu.'; return; }
    this.disabled = true; result.textContent = 'Memvalidasi dan mengimpor...';
    try {
        const workbook = XLSX.read(await file.arrayBuffer(), {type: 'array', cellDates: false});
        const rows = XLSX.utils.sheet_to_json(workbook.Sheets[workbook.SheetNames[0]], {header: 1, raw: false, defval: ''});
        const body = new FormData(); body.append('payload', JSON.stringify(rows)); body.append('_csrf', window.ALHASAN_CSRF);
        const response = await fetch('proses_import_santri.php', {method: 'POST', body});
        result.textContent = await response.text();
    } catch (error) { result.textContent = 'Impor gagal dibaca atau dikirim. Periksa format berkas lalu coba lagi.'; }
    finally { this.disabled = false; }
});
</script>
<?php master_footer(); ?>
