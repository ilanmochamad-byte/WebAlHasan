<?php

declare(strict_types=1);

require_once __DIR__ . '/_laporan_guard.php';

use App\Api\ApiException;

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(422);
    exit('ID pertemuan tidak valid.');
}
try {
    $meeting = report_service()->meeting((int) $id, $currentUser);
} catch (ApiException $exception) {
    http_response_code($exception->status());
    exit(ah_e($exception->getMessage()));
}
ah_page_open(['title' => 'Detail Pertemuan', 'user' => $currentUser, 'show_heading' => false]);
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><h1 class="h2 mb-1">Detail Pertemuan #<?= $meeting['id'] ?></h1><p class="text-muted mb-0"><?= ah_e($meeting['date'] . ' · ' . $meeting['task']['subject'] . ' · ' . $meeting['task']['class']['name']) ?></p></div><a class="btn btn-outline-secondary" href="admin_laporan_absensi.php">Kembali ke laporan</a></div>
<div class="row g-3 mb-4"><div class="col-lg-8"><div class="card border-0 shadow-sm h-100"><div class="card-body row g-3"><div class="col-sm-6"><strong>Guru</strong><br><?= ah_e($meeting['task']['teacher']['name']) ?></div><div class="col-sm-6"><strong>Jadwal</strong><br><?= ah_e($meeting['task']['day'] . ', ' . $meeting['task']['start_time'] . '–' . $meeting['task']['end_time']) ?></div><div class="col-sm-6"><strong>Kitab / Tempat</strong><br><?= ah_e($meeting['task']['book'] . ' · ' . $meeting['task']['place']) ?></div><div class="col-sm-6"><strong>Tahun ajaran</strong><br><?= ah_e($meeting['task']['academic_year']['year'] . ' - ' . $meeting['task']['academic_year']['semester']) ?></div><div class="col-12"><strong>Catatan pertemuan</strong><br><?= ah_e($meeting['notes'] ?? '-') ?></div></div></div></div><div class="col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><strong>Kehadiran guru</strong><?php if ($meeting['teacher_attendance']): ?><div class="fs-4 fw-bold mt-2"><?= ah_e($meeting['teacher_attendance']['status']) ?></div><div><?= ah_e($meeting['teacher_attendance']['notes'] ?? '-') ?></div><small class="text-muted">Dicatat <?= ah_e($meeting['teacher_attendance']['recorded_by'] ?? '-') ?> · diperbarui <?= ah_e($meeting['teacher_attendance']['updated_at'] ?? '-') ?></small><?php else: ?><p class="text-muted mt-2">Belum dicatat.</p><?php endif; ?></div></div></div></div>
<div class="card border-0 shadow-sm"><div class="card-header bg-white d-flex justify-content-between"><h2 class="h5 mb-0">Peserta snapshot</h2><span><?= $meeting['student_summary']['recorded_count'] ?>/<?= $meeting['student_summary']['participant_count'] ?> tercatat</span></div><div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead><tr><th>NIS</th><th>Nama</th><th>Status</th><th>Catatan</th><th>Pencatat</th><th>Perubahan</th></tr></thead><tbody><?php foreach ($meeting['students'] as $student): ?><tr><td><?= ah_e($student['nis']) ?></td><td><?= ah_e($student['name']) ?></td><td><?= ah_e($student['status'] ?? 'Belum dicatat') ?></td><td><?= ah_e($student['notes'] ?? '-') ?></td><td><?= ah_e($student['recorded_by'] ?? '-') ?></td><td><?= ah_e($student['updated_at'] ?? '-') ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php ah_page_close(); ?>
