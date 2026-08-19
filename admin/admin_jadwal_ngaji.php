<?php

declare(strict_types=1);

use App\Schedule\ScheduleException;

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = schedule_service();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'save') {
            $result = $service->save($_POST, (int) $currentUser['id'], $id > 0 ? $id : null);
            $message = ($id > 0 ? 'Perubahan jadwal' : 'Jadwal baru') . ' berhasil disimpan (ID ' . $result['id'] . ').';
            if ($result['warnings'] !== []) {
                master_flash('warning', $message . ' ' . implode(' ', $result['warnings']));
            } else {
                master_flash('success', $message);
            }
        } else {
            $result = $service->setState($id, $action, (int) $currentUser['id']);
            $message = 'Status jadwal diperbarui tanpa menghapus riwayat.';
            if ($result['warnings'] !== []) {
                master_flash('warning', $message . ' ' . implode(' ', $result['warnings']));
            } else {
                master_flash('success', $message);
            }
        }
    } catch (ScheduleException $exception) {
        master_flash('danger', $exception->getMessage());
    }
    master_redirect('admin_jadwal_ngaji.php');
}

$years = $service->years();
$teachers = $service->teachers();
$classes = $service->classes();
$activeYear = null;
foreach ($years as $year) {
    if ($year['status'] === 'Aktif') { $activeYear = $year; break; }
}
$filters = [
    'q' => $_GET['q'] ?? '',
    'year_id' => $_GET['year_id'] ?? ($activeYear['id'] ?? ''),
    'teacher_id' => $_GET['teacher_id'] ?? '',
    'class_id' => $_GET['class_id'] ?? '',
    'day' => $_GET['day'] ?? '',
    'state' => $_GET['state'] ?? 'active',
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = $service->list($filters, $page);
$selected = isset($_GET['id']) ? $service->find((int) $_GET['id']) : null;
$mode = (string) ($_GET['action'] ?? '');

master_header('Jadwal Pengajian');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center border-bottom pb-3 mb-4 gap-2">
    <div><h1 class="h3 mb-1">Jadwal Pengajian</h1><p class="text-muted mb-0">Pola mingguan terstruktur; jam asli jadwal lama tetap disimpan.</p></div>
    <div class="d-flex gap-2"><a class="btn btn-outline-primary" href="pertemuan_pengajian.php"><i class="fas fa-calendar-check me-1"></i> Pertemuan</a><a class="btn btn-success" href="?action=create"><i class="fas fa-plus me-1"></i> Tambah Jadwal</a></div>
</div>

<?php if ($mode === 'create' || ($mode === 'edit' && $selected)): $record = $selected ?? ['id_tahun' => $activeYear['id'] ?? '']; ?>
<div class="card shadow-sm border-0 mb-4"><div class="card-header bg-white"><strong><?= $selected ? 'Ubah Jadwal' : 'Tambah Jadwal' ?></strong></div><div class="card-body">
<form method="post" class="row g-3">
    <?= master_csrf() ?><input type="hidden" name="action" value="save"><?php if ($selected): ?><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><?php endif; ?>
    <div class="col-md-3"><label class="form-label" for="id_tahun">Tahun ajaran / semester</label><select class="form-select" id="id_tahun" name="id_tahun" required><option value="">Pilih semester</option><?php foreach ($years as $year): ?><option value="<?= (int) $year['id'] ?>" <?= (int) ($record['id_tahun'] ?? 0) === (int) $year['id'] ? 'selected' : '' ?>><?= master_e($year['tahun'] . ' ' . $year['semester'] . ($year['status'] === 'Aktif' ? ' — Aktif' : '')) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label" for="hari">Hari</label><select class="form-select" id="hari" name="hari" required><option value="">Pilih hari</option><?php foreach ($service->days() as $day): ?><option <?= ($record['hari'] ?? '') === $day ? 'selected' : '' ?>><?= $day ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label" for="waktu_sholat">Waktu pelaksanaan</label><select class="form-select" id="waktu_sholat" name="waktu_sholat" required><?php foreach ($service->prayerTimes() as $prayerTime): ?><option <?= ($record['waktu_sholat'] ?? "Ba'da Shubuh") === $prayerTime ? 'selected' : '' ?>><?= master_e($prayerTime) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label" for="waktu_mulai">Mulai</label><input class="form-control" id="waktu_mulai" type="time" name="waktu_mulai" required value="<?= master_e(isset($record['waktu_mulai']) ? substr((string) $record['waktu_mulai'], 0, 5) : '') ?>"></div>
    <div class="col-md-2"><label class="form-label" for="waktu_selesai">Selesai</label><input class="form-control" id="waktu_selesai" type="time" name="waktu_selesai" required value="<?= master_e(isset($record['waktu_selesai']) ? substr((string) $record['waktu_selesai'], 0, 5) : '') ?>"></div>
    <?php if ($selected): ?><div class="col-12"><div class="alert alert-light border mb-0"><strong>Nilai jam lama:</strong> <?= master_e($selected['jam']) ?>. Status migrasi: <?= master_e($selected['jam_migration_status']) ?>. Nilai ini tetap tersedia untuk audit dan kompatibilitas.</div></div><?php endif; ?>
    <div class="col-md-4"><label class="form-label" for="id_kelas">Kelas</label><select class="form-select" id="id_kelas" name="id_kelas" required><option value="">Pilih kelas</option><?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>" <?= (int) ($record['id_kelas'] ?? 0) === (int) $class['id'] ? 'selected' : '' ?>><?= master_e($class['nama_kelas'] . ' (' . $class['jenjang'] . ')') ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label" for="id_guru">Guru</label><select class="form-select" id="id_guru" name="id_guru" required><option value="">Pilih guru</option><?php foreach ($teachers as $teacher): ?><option value="<?= (int) $teacher['id'] ?>" <?= (int) ($record['id_guru'] ?? 0) === (int) $teacher['id'] ? 'selected' : '' ?>><?= master_e($teacher['nama_guru']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label" for="tempat">Tempat</label><input class="form-control" id="tempat" name="tempat" maxlength="100" required value="<?= master_e($record['tempat'] ?? '') ?>"></div>
    <div class="col-md-4"><label class="form-label" for="fan_ilmu">Fan ilmu</label><input class="form-control" id="fan_ilmu" name="fan_ilmu" maxlength="100" required value="<?= master_e($record['fan_ilmu'] ?? '') ?>"></div>
    <div class="col-md-5"><label class="form-label" for="nama_kitab">Nama kitab</label><input class="form-control" id="nama_kitab" name="nama_kitab" maxlength="100" required value="<?= master_e($record['nama_kitab'] ?? '') ?>"></div>
    <div class="col-md-3 d-flex align-items-end gap-2"><button class="btn btn-success" type="submit">Simpan</button><a class="btn btn-light" href="admin_jadwal_ngaji.php">Batal</a></div>
</form></div></div>
<?php elseif ($mode === 'detail' && $selected): ?>
<div class="card shadow-sm border-0 mb-4"><div class="card-header bg-white d-flex justify-content-between"><strong>Detail Jadwal #<?= (int) $selected['id'] ?></strong><a href="?action=edit&amp;id=<?= (int) $selected['id'] ?>">Ubah</a></div><div class="card-body row g-3">
    <div class="col-md-3"><small class="text-muted d-block">Semester</small><?= master_e($selected['tahun'] . ' ' . $selected['semester']) ?></div>
    <div class="col-md-3"><small class="text-muted d-block">Pola waktu</small><?= master_e(($selected['hari'] ?: 'Hari belum dilengkapi') . ', ' . ($selected['waktu_mulai'] ? substr($selected['waktu_mulai'], 0, 5) . '–' . substr($selected['waktu_selesai'], 0, 5) : $selected['jam'])) ?></div>
    <div class="col-md-3"><small class="text-muted d-block">Kelas</small><?= master_e($selected['nama_kelas'] . ' (' . $selected['jenjang'] . ')') ?></div><div class="col-md-3"><small class="text-muted d-block">Guru</small><?= master_e($selected['nama_guru']) ?></div>
    <div class="col-md-3"><small class="text-muted d-block">Fan ilmu</small><?= master_e($selected['fan_ilmu']) ?></div><div class="col-md-3"><small class="text-muted d-block">Kitab</small><?= master_e($selected['nama_kitab']) ?></div>
    <div class="col-md-3"><small class="text-muted d-block">Tempat</small><?= master_e($selected['tempat']) ?></div><div class="col-md-3"><small class="text-muted d-block">Status parsing jam</small><?= master_e($selected['jam_migration_status']) ?></div>
</div></div>
<?php endif; ?>

<form method="get" class="card card-body border-0 shadow-sm mb-3"><div class="row g-2 align-items-end">
    <div class="col-lg-3"><label class="form-label" for="q">Pencarian</label><input class="form-control" id="q" name="q" value="<?= master_e($filters['q']) ?>" placeholder="Fan, kitab, atau tempat"></div>
    <div class="col-lg-2"><label class="form-label" for="year_id">Semester</label><select class="form-select" id="year_id" name="year_id"><option value="">Semua</option><?php foreach ($years as $year): ?><option value="<?= (int) $year['id'] ?>" <?= (int) $filters['year_id'] === (int) $year['id'] ? 'selected' : '' ?>><?= master_e($year['tahun'] . ' ' . $year['semester']) ?></option><?php endforeach; ?></select></div>
    <div class="col-lg-2"><label class="form-label" for="teacher_id">Guru</label><select class="form-select" id="teacher_id" name="teacher_id"><option value="">Semua</option><?php foreach ($teachers as $teacher): ?><option value="<?= (int) $teacher['id'] ?>" <?= (int) $filters['teacher_id'] === (int) $teacher['id'] ? 'selected' : '' ?>><?= master_e($teacher['nama_guru']) ?></option><?php endforeach; ?></select></div>
    <div class="col-lg-2"><label class="form-label" for="class_id">Kelas</label><select class="form-select" id="class_id" name="class_id"><option value="">Semua</option><?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>" <?= (int) $filters['class_id'] === (int) $class['id'] ? 'selected' : '' ?>><?= master_e($class['nama_kelas']) ?></option><?php endforeach; ?></select></div>
    <div class="col-lg-1"><label class="form-label" for="day">Hari</label><select class="form-select" id="day" name="day"><option value="">Semua</option><?php foreach ($service->days() as $day): ?><option <?= $filters['day'] === $day ? 'selected' : '' ?>><?= $day ?></option><?php endforeach; ?></select></div>
    <div class="col-lg-2"><label class="form-label" for="state">Status</label><select class="form-select" id="state" name="state"><?php foreach (['active'=>'Aktif','inactive'=>'Nonaktif','archived'=>'Arsip','all'=>'Semua'] as $value=>$label): ?><option value="<?= $value ?>" <?= $filters['state'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
    <div class="col-12"><button class="btn btn-primary" type="submit">Terapkan filter</button> <a class="btn btn-light" href="admin_jadwal_ngaji.php">Reset ke semester aktif</a></div>
</div></form>

<div class="card shadow-sm border-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Hari & waktu</th><th>Semester</th><th>Kelas</th><th>Fan & kitab</th><th>Guru</th><th>Tempat</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($result['rows'] as $row): ?><tr>
    <td><strong><?= master_e($row['hari'] ?: 'Hari belum diisi') ?></strong><br><small><?= master_e($row['waktu_mulai'] ? substr($row['waktu_mulai'], 0, 5) . '–' . substr($row['waktu_selesai'], 0, 5) : $row['jam']) ?></small><?php if ($row['jam_migration_status'] === 'Gagal'): ?><br><span class="badge text-bg-warning">Jam perlu ditinjau</span><?php endif; ?></td>
    <td><?= master_e($row['tahun'] . ' ' . $row['semester']) ?><?= $row['tahun_status'] === 'Aktif' ? '<br><span class="badge text-bg-success">Semester aktif</span>' : '' ?></td>
    <td><?= master_e($row['nama_kelas']) ?><br><small class="text-muted"><?= master_e($row['jenjang']) ?></small></td><td><?= master_e($row['fan_ilmu']) ?><br><small class="text-muted"><?= master_e($row['nama_kitab']) ?></small></td>
    <td><?= master_e($row['nama_guru']) ?></td><td><?= master_e($row['tempat']) ?></td><td><?= $row['archived_at'] ? '<span class="badge text-bg-dark">Arsip</span>' : ((int) $row['is_active'] === 1 ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Nonaktif</span>') ?></td>
    <td><div class="d-flex flex-wrap gap-1"><a class="btn btn-sm btn-outline-primary" href="?action=detail&amp;id=<?= (int) $row['id'] ?>">Detail</a><a class="btn btn-sm btn-outline-warning" href="?action=edit&amp;id=<?= (int) $row['id'] ?>">Ubah</a>
        <?php if ((int) $row['is_active'] === 1 && !$row['archived_at'] && $row['tahun_status'] === 'Aktif' && $row['hari']): ?><a class="btn btn-sm btn-outline-success" href="pertemuan_pengajian.php?schedule_id=<?= (int) $row['id'] ?>">Buka pertemuan</a><?php endif; ?>
        <form method="post"><?= master_csrf() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-outline-secondary" name="action" value="<?= (int) $row['is_active'] === 1 ? 'deactivate' : 'activate' ?>"><?= (int) $row['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
        <form method="post" onsubmit="return confirm('Arsipkan jadwal tanpa menghapus data atau pertemuan?')"><?= master_csrf() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-outline-danger" name="action" value="<?= $row['archived_at'] ? 'restore' : 'archive' ?>"><?= $row['archived_at'] ? 'Pulihkan' : 'Arsipkan' ?></button></form>
    </div></td>
</tr><?php endforeach; ?>
<?php if ($result['rows'] === []): ?><tr><td colspan="8" class="text-center text-muted py-5">Tidak ada jadwal yang sesuai filter.</td></tr><?php endif; ?>
</tbody></table></div><div class="card-footer bg-white">Menampilkan <?= count($result['rows']) ?> dari <?= (int) $result['total'] ?> jadwal.</div></div>
<?php master_pagination((int) $result['total'], $page, 20); master_footer(); ?>
