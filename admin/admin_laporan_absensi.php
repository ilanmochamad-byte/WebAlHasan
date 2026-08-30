<?php

declare(strict_types=1);

use App\Api\ApiException;
use App\Report\ReportFilter;

/**
 * Laporan Kehadiran Pengajian (koreksi ke-5, keputusan pengguna 30 Agustus 2026).
 *
 * Penyajian kehadiran santri dan guru DIPISAHKAN, dengan tampilan awal
 * "Santri". Guru tetap muncul sebagai pengampu pada laporan santri, tetapi
 * tidak dihitung sebagai santri. Mode Gabungan menampilkan penanda jenis dan
 * jumlah masing-masing.
 *
 * Filter yang sama — termasuk penyajian — dipakai oleh ringkasan, detail, CSV,
 * dan cetak/PDF, karena keempatnya melewati `ReportFilter`/`ReportRepository`
 * yang sama.
 *
 * Kontrak API lama TIDAK berubah: `subject_scope` bersifat aditif dan default
 * REST API tetap `gabungan`. Halaman web ini yang secara eksplisit meminta
 * `santri` sebagai tampilan awal.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$error = null;
try {
    $options = report_service()->options($currentUser);
    $report = report_service()->report($_GET, $currentUser, ReportFilter::SCOPE_SANTRI);
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
    'subject_scope' => ReportFilter::SCOPE_SANTRI,
];
$scope = (string) ($filter['subject_scope'] ?? ReportFilter::SCOPE_SANTRI);
$selected = static fn (mixed $actual, mixed $expected): string => (string) $actual === (string) $expected ? ' selected' : '';
$query = $_GET;
unset($query['page']);
// Ekspor dan cetak memakai query string yang SAMA persis, termasuk penyajian,
// sehingga jumlah barisnya tidak mungkin berbeda dari yang tampil di layar.
$query['subject_scope'] = $scope;

$tabs = [];
foreach ([
    ReportFilter::SCOPE_SANTRI => 'Santri',
    ReportFilter::SCOPE_GURU => 'Guru',
    ReportFilter::SCOPE_GABUNGAN => 'Gabungan',
] as $nilai => $label) {
    $tabs[] = [
        'label' => $label,
        'url' => 'admin_laporan_absensi.php?' . ah_query(['subject_scope' => $nilai, 'page' => null]),
        'active' => $scope === $nilai,
    ];
}

master_header('Laporan Kehadiran Pengajian', [
    'description' => 'Ringkasan, detail, CSV, dan cetak selalu memakai filter yang sama — termasuk pilihan penyajian.',
    'active' => 'kehadiran',
    'tabs' => $tabs,
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Pengajian', 'url' => app_url('/admin/admin_pengajian.php')],
        ['label' => 'Laporan Kehadiran'],
    ],
    'actions' => $report === null ? '' :
        '<a class="btn btn-outline-primary" href="export_laporan_absensi.php?' . master_e(http_build_query($query)) . '">Ekspor CSV</a>'
        . '<a class="btn btn-primary" target="_blank" rel="noopener" href="laporan_absensi_cetak.php?' . master_e(http_build_query($query)) . '">Cetak / PDF</a>',
]);

if ($error !== null) {
    ah_note('danger', $error, '<p class="small mb-0 mt-2"><a href="admin_laporan_absensi.php">Bersihkan filter</a> lalu coba lagi.</p>');
}
?>

<?php ah_note('info', match ($scope) {
    ReportFilter::SCOPE_GURU => 'Penyajian Guru: hanya kehadiran guru pengampu yang dihitung.',
    ReportFilter::SCOPE_GABUNGAN => 'Penyajian Gabungan: kehadiran santri dan guru ditampilkan bersama, masing-masing dengan penanda jenis dan jumlahnya sendiri.',
    default => 'Penyajian Santri: hanya kehadiran santri yang dihitung. Guru tetap tampil sebagai pengampu pada setiap baris, tetapi tidak ikut dihitung sebagai santri.',
}, '<p class="small mb-0 mt-2">Absensi guru tidak pernah dihapus — penyajian hanya mengubah apa yang ditampilkan dan dihitung.</p>'); ?>

<form method="get" class="ah-card ah-no-print">
    <div class="ah-card__body">
        <fieldset class="ah-fieldset mb-0">
            <legend>Filter laporan</legend>
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label" for="subject_scope">Penyajian</label>
                    <select class="form-select" id="subject_scope" name="subject_scope">
                        <?php foreach ($options['subject_scopes'] as $opsi): ?>
                            <option value="<?= master_e($opsi['value']) ?>"<?= $selected($scope, $opsi['value']) ?>><?= master_e($opsi['label']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-3"><label class="form-label" for="date_from">Tanggal mulai</label>
                    <input class="form-control" id="date_from" name="date_from" type="date" required value="<?= master_e($filter['date_from']) ?>"></div>
                <div class="col-md-3"><label class="form-label" for="date_to">Tanggal akhir</label>
                    <input class="form-control" id="date_to" name="date_to" type="date" required value="<?= master_e($filter['date_to']) ?>"></div>
                <div class="col-md-3"><label class="form-label" for="academic_year_id">Tahun ajaran</label>
                    <select class="form-select" id="academic_year_id" name="academic_year_id"><option value="">Semua tahun ajaran</option>
                        <?php foreach ($options['academic_years'] as $row): ?>
                            <option value="<?= $row['id'] ?>"<?= $selected($filter['academic_year_id'], $row['id']) ?>><?= master_e($row['year'] . ' - ' . $row['semester']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-3"><label class="form-label" for="teacher_id">Guru</label>
                    <select class="form-select" id="teacher_id" name="teacher_id"><option value="">Semua guru</option>
                        <?php foreach ($options['teachers'] as $row): ?>
                            <option value="<?= $row['id'] ?>"<?= $selected($filter['teacher_id'], $row['id']) ?>><?= master_e($row['name']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-3"><label class="form-label" for="class_id">Kelas</label>
                    <select class="form-select" id="class_id" name="class_id"><option value="">Semua kelas</option>
                        <?php foreach ($options['classes'] as $row): ?>
                            <option value="<?= $row['id'] ?>"<?= $selected($filter['class_id'], $row['id']) ?>><?= master_e($row['name']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-4"><label class="form-label" for="schedule_id">Jadwal</label>
                    <select class="form-select" id="schedule_id" name="schedule_id"><option value="">Semua jadwal</option>
                        <?php foreach ($options['schedules'] as $row): ?>
                            <option value="<?= $row['id'] ?>"<?= $selected($filter['schedule_id'], $row['id']) ?>><?= master_e($row['label']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-2"><label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status"><option value="">Semua status</option>
                        <?php foreach ($options['statuses'] as $status): ?>
                            <option value="<?= master_e($status) ?>"<?= $selected($filter['status'], $status) ?>><?= master_e($status) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">Terapkan filter</button>
                    <a class="btn btn-outline-secondary" href="admin_laporan_absensi.php">Bersihkan filter</a>
                </div>
            </div>
        </fieldset>
    </div>
</form>

<?php if ($report !== null): $summary = $report['summary']; ?>
<div class="ah-stats">
    <div class="ah-stat"><p class="ah-stat__label">Pertemuan</p><p class="ah-stat__value"><?= $summary['meeting_count'] ?></p></div>
    <div class="ah-stat"><p class="ah-stat__label">Baris detail</p><p class="ah-stat__value"><?= $summary['detail_count'] ?></p>
        <p class="ah-stat__hint"><?= master_e($report['active_filters']['Penyajian'] ?? '') ?></p></div>
    <?php if ($scope !== ReportFilter::SCOPE_GURU): ?>
        <div class="ah-stat"><p class="ah-stat__label">Catatan santri</p><p class="ah-stat__value"><?= $summary['student_attendance_count'] ?></p></div>
    <?php endif; ?>
    <?php if ($scope !== ReportFilter::SCOPE_SANTRI): ?>
        <div class="ah-stat"><p class="ah-stat__label">Catatan guru</p><p class="ah-stat__value"><?= $summary['teacher_attendance_count'] ?></p></div>
    <?php endif; ?>
    <?php foreach ($summary['statuses'] as $status => $count): ?>
        <div class="ah-stat"><p class="ah-stat__label"><?= master_e($status) ?></p><p class="ah-stat__value"><?= $count ?></p></div>
    <?php endforeach; ?>
</div>

<?php ah_note('info', 'Kontrol konsistensi: ' . array_sum($summary['statuses']) . ' status = ' . $summary['detail_count'] . ' baris detail'
    . ($scope === ReportFilter::SCOPE_GABUNGAN
        ? ' (' . $summary['student_attendance_count'] . ' santri + ' . $summary['teacher_attendance_count'] . ' guru).'
        : '.')); ?>

<section class="ah-card" aria-labelledby="ah-ringkas-jadwal">
    <div class="ah-card__head"><span id="ah-ringkas-jadwal">Ringkasan per jadwal</span></div>
    <?php if ($report['schedules'] === []): ?>
        <div class="ah-card__body"><?= ah_empty('Tidak ada data sesuai filter', 'Ubah rentang tanggal, penyajian, atau filter lain di atas.') ?></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table">
            <caption class="ah-visually-hidden">Ringkasan kehadiran per jadwal sesuai filter</caption>
            <thead><tr><th scope="col">Jadwal</th><th scope="col">Guru pengampu</th><th scope="col">Kelas</th><th scope="col">Pertemuan</th><th scope="col">Detail</th>
                <th scope="col">Hadir</th><th scope="col">Terlambat</th><th scope="col">Izin</th><th scope="col">Sakit</th><th scope="col">Alpa</th></tr></thead>
            <tbody>
            <?php foreach ($report['schedules'] as $row): ?>
                <tr>
                    <td>#<?= $row['schedule_id'] ?> · <?= master_e($row['subject']) ?><span class="ah-cell-sub"><?= master_e($row['book']) ?></span></td>
                    <td><?= master_e($row['teacher']['name']) ?></td>
                    <td><?= master_e($row['class']['name']) ?></td>
                    <td><?= $row['meeting_count'] ?></td>
                    <td><?= $row['detail_count'] ?></td>
                    <td><?= $row['statuses']['Hadir'] ?></td><td><?= $row['statuses']['Terlambat'] ?></td>
                    <td><?= $row['statuses']['Izin'] ?></td><td><?= $row['statuses']['Sakit'] ?></td><td><?= $row['statuses']['Alpa'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<section class="ah-card" aria-labelledby="ah-detail-kehadiran">
    <div class="ah-card__head"><span id="ah-detail-kehadiran">Detail kehadiran</span>
        <span class="text-muted small">Halaman <?= $report['pagination']['current_page'] ?> dari <?= max(1, $report['pagination']['total_pages']) ?></span></div>
    <?php if ($report['items'] === []): ?>
        <div class="ah-card__body"><?= ah_empty('Tidak ada baris kehadiran sesuai filter', 'Ubah penyajian atau filter di atas. Mode Santri tidak menampilkan absensi guru, dan sebaliknya.') ?></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table">
            <caption class="ah-visually-hidden">Baris kehadiran sesuai filter</caption>
            <thead><tr><th scope="col">Tanggal</th><th scope="col">Jadwal</th><th scope="col">Guru pengampu / Kelas</th>
                <th scope="col">Jenis</th><th scope="col">Peserta</th><th scope="col">Status</th><th scope="col">Pencatat</th><th scope="col"></th></tr></thead>
            <tbody>
            <?php foreach ($report['items'] as $row): ?>
                <tr>
                    <td><?= master_e($row['meeting_date']) ?></td>
                    <td>#<?= $row['schedule_id'] ?> · <?= master_e($row['subject']) ?></td>
                    <td><?= master_e($row['teacher_name']) ?><span class="ah-cell-sub"><?= master_e($row['class_name']) ?></span></td>
                    <td><?= ah_badge($row['subject_type'], $row['subject_type'] === 'Guru' ? 'info' : 'muted') ?></td>
                    <td><?= master_e($row['subject_name']) ?><span class="ah-cell-sub"><?= master_e($row['identity_number']) ?></span></td>
                    <td><?= ah_badge($row['attendance_status'], match ($row['attendance_status']) {
                            'Hadir' => 'ok', 'Alpa' => 'danger', 'Terlambat' => 'warn', default => 'info',
                        }) ?><span class="ah-cell-sub"><?= master_e($row['notes'] ?? '') ?></span></td>
                    <td><?= master_e($row['recorder_name'] ?? '-') ?><span class="ah-cell-sub"><?= master_e($row['updated_at'] ?? '-') ?></span></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="laporan_absensi_detail.php?id=<?= $row['meeting_id'] ?>">Pertemuan</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php master_pagination($report['pagination']['total'], $report['pagination']['current_page'], $report['pagination']['per_page']); endif; ?>
<?php master_footer(); ?>
