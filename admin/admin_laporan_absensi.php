<?php

declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

use App\Api\ApiException;

$error = null;
try {
    $options = report_service()->options($currentUser);
    $report = report_service()->report($_GET, $currentUser);
} catch (ApiException $exception) {
    http_response_code($exception->status());
    $error = $exception->getMessage();
    $options = report_service()->options($currentUser);
    $report = null;
}

$filter = $report['filters'] ?? [
    'date_from' => (new DateTimeImmutable('first day of this month'))->format('Y-m-d'),
    'date_to' => date('Y-m-d'), 'academic_year_id' => null, 'teacher_id' => null,
    'class_id' => null, 'schedule_id' => null, 'status' => null,
];
$selected = static fn (mixed $actual, mixed $expected): string => (string) $actual === (string) $expected ? ' selected' : '';
$query = $_GET;
unset($query['page']);

master_header('Laporan Absensi Pengajian');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div><h1 class="h2 fw-bold mb-1">Laporan Absensi Pengajian</h1><p class="text-muted mb-0">Ringkasan dan baris detail selalu memakai filter yang sama.</p></div>
    <?php if ($report !== null): ?><div class="d-flex gap-2">
        <a class="btn btn-outline-success" href="export_laporan_absensi.php?<?= master_e(http_build_query($query)) ?>"><i class="fas fa-file-csv me-1"></i> Ekspor CSV</a>
        <a class="btn btn-success" target="_blank" rel="noopener" href="laporan_absensi_cetak.php?<?= master_e(http_build_query($query)) ?>"><i class="fas fa-print me-1"></i> Cetak / PDF</a>
    </div><?php endif; ?>
</div>

<?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= master_e($error) ?></div><?php endif; ?>

<form method="get" class="card border-0 shadow-sm mb-4"><div class="card-body">
    <div class="row g-3">
        <div class="col-md-3"><label class="form-label" for="date_from">Tanggal mulai</label><input class="form-control" id="date_from" name="date_from" type="date" required value="<?= master_e($filter['date_from']) ?>"></div>
        <div class="col-md-3"><label class="form-label" for="date_to">Tanggal akhir</label><input class="form-control" id="date_to" name="date_to" type="date" required value="<?= master_e($filter['date_to']) ?>"></div>
        <div class="col-md-3"><label class="form-label" for="academic_year_id">Tahun ajaran</label><select class="form-select" id="academic_year_id" name="academic_year_id"><option value="">Semua tahun ajaran</option><?php foreach ($options['academic_years'] as $row): ?><option value="<?= $row['id'] ?>"<?= $selected($filter['academic_year_id'], $row['id']) ?>><?= master_e($row['year'] . ' - ' . $row['semester']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label" for="teacher_id">Guru</label><select class="form-select" id="teacher_id" name="teacher_id"><option value="">Semua guru</option><?php foreach ($options['teachers'] as $row): ?><option value="<?= $row['id'] ?>"<?= $selected($filter['teacher_id'], $row['id']) ?>><?= master_e($row['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label" for="class_id">Kelas</label><select class="form-select" id="class_id" name="class_id"><option value="">Semua kelas</option><?php foreach ($options['classes'] as $row): ?><option value="<?= $row['id'] ?>"<?= $selected($filter['class_id'], $row['id']) ?>><?= master_e($row['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5"><label class="form-label" for="schedule_id">Jadwal</label><select class="form-select" id="schedule_id" name="schedule_id"><option value="">Semua jadwal</option><?php foreach ($options['schedules'] as $row): ?><option value="<?= $row['id'] ?>"<?= $selected($filter['schedule_id'], $row['id']) ?>><?= master_e($row['label']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">Semua status</option><?php foreach ($options['statuses'] as $status): ?><option value="<?= master_e($status) ?>"<?= $selected($filter['status'], $status) ?>><?= master_e($status) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Terapkan filter</button></div>
    </div>
</div></form>

<?php if ($report !== null): $summary = $report['summary']; ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-2"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Pertemuan</div><div class="fs-3 fw-bold"><?= $summary['meeting_count'] ?></div></div></div></div>
    <div class="col-6 col-lg-2"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Baris detail</div><div class="fs-3 fw-bold"><?= $summary['detail_count'] ?></div></div></div></div>
    <?php foreach ($summary['statuses'] as $status => $count): ?><div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small"><?= master_e($status) ?></div><div class="fs-3 fw-bold"><?= $count ?></div></div></div></div><?php endforeach; ?>
</div>

<div class="alert alert-info py-2" role="status">Kontrol konsistensi: <?= array_sum($summary['statuses']) ?> status = <?= $summary['detail_count'] ?> baris detail.</div>

<div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Ringkasan per jadwal</h2></div><div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0">
    <thead><tr><th>Jadwal</th><th>Guru</th><th>Kelas</th><th>Pertemuan</th><th>Detail</th><th>H</th><th>T</th><th>I</th><th>S</th><th>A</th></tr></thead><tbody>
    <?php if ($report['schedules'] === []): ?><tr><td colspan="10" class="text-center text-muted py-4">Tidak ada data sesuai filter.</td></tr><?php endif; ?>
    <?php foreach ($report['schedules'] as $row): ?><tr><td>#<?= $row['schedule_id'] ?> · <?= master_e($row['subject']) ?><br><small class="text-muted"><?= master_e($row['book']) ?></small></td><td><?= master_e($row['teacher']['name']) ?></td><td><?= master_e($row['class']['name']) ?></td><td><?= $row['meeting_count'] ?></td><td><?= $row['detail_count'] ?></td><td><?= $row['statuses']['Hadir'] ?></td><td><?= $row['statuses']['Terlambat'] ?></td><td><?= $row['statuses']['Izin'] ?></td><td><?= $row['statuses']['Sakit'] ?></td><td><?= $row['statuses']['Alpa'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>

<div class="card border-0 shadow-sm"><div class="card-header bg-white d-flex justify-content-between"><h2 class="h5 mb-0">Detail kehadiran</h2><span class="text-muted">Halaman <?= $report['pagination']['current_page'] ?> dari <?= max(1, $report['pagination']['total_pages']) ?></span></div><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Tanggal</th><th>Jadwal</th><th>Guru / Kelas</th><th>Peserta</th><th>Status</th><th>Pencatat</th><th></th></tr></thead><tbody>
    <?php if ($report['items'] === []): ?><tr><td colspan="7" class="text-center text-muted py-4">Tidak ada baris absensi sesuai filter.</td></tr><?php endif; ?>
    <?php foreach ($report['items'] as $row): ?><tr><td><?= master_e($row['meeting_date']) ?></td><td>#<?= $row['schedule_id'] ?> · <?= master_e($row['subject']) ?></td><td><?= master_e($row['teacher_name']) ?><br><small><?= master_e($row['class_name']) ?></small></td><td><span class="badge text-bg-secondary"><?= master_e($row['subject_type']) ?></span> <?= master_e($row['subject_name']) ?><br><small class="text-muted"><?= master_e($row['identity_number']) ?></small></td><td><span class="badge text-bg-light border"><?= master_e($row['attendance_status']) ?></span><br><small><?= master_e($row['notes'] ?? '') ?></small></td><td><?= master_e($row['recorder_name'] ?? '-') ?><br><small class="text-muted"><?= master_e($row['updated_at'] ?? '-') ?></small></td><td><a class="btn btn-sm btn-outline-primary" href="laporan_absensi_detail.php?id=<?= $row['meeting_id'] ?>">Pertemuan</a></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
<?php master_pagination($report['pagination']['total'], $report['pagination']['current_page'], $report['pagination']['per_page']); endif; ?>
<?php master_footer(); ?>
