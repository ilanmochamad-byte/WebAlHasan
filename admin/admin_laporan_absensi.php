<?php

declare(strict_types=1);

use App\Api\ApiException;
use App\Report\ReportFilter;
use App\Ui\Periode;

/**
 * Laporan Kehadiran Pengajian (koreksi ke-5; redesain website Tahap 4,
 * J-4 dan J-6).
 *
 * J-4 (admin, guru): tab Pengajian › Kehadiran. J-6 (Bagian Pendidikan,
 * keputusan H): menu "Laporan absensi pengajian" untuk SELURUH jadwal,
 * hanya-baca, tanpa tab Jadwal/Pertemuan. Isi keduanya sama.
 *
 * Penyajian kehadiran santri dan guru tetap DIPISAHKAN dengan tampilan awal
 * "Santri". Filter yang sama — termasuk penyajian dan periode yang sudah
 * diterjemahkan menjadi tanggal — dipakai oleh ringkasan, detail, CSV, dan
 * cetak/PDF, karena keempatnya melewati `ReportFilter`/`ReportRepository`
 * yang sama.
 *
 * Kontrak API lama dipertahankan: `subject_scope` hanya untuk web dan
 * REST API tetap `gabungan`. Halaman web ini yang secara eksplisit meminta
 * `santri` sebagai tampilan awal.
 */

require_once __DIR__ . '/_laporan_guard.php';

$j6 = !empty($currentUser[ReportFilter::BACA_SEMUA_JADWAL]);
// Semester aktif untuk pilihan "Semester ini" (tahun ajaran tanpa tanggal).
$tahunAktif = null;
foreach (schedule_service()->years() as $row) {
    if ($row['status'] === 'Aktif') {
        $tahunAktif = ['tahun' => (string) $row['tahun'], 'semester' => (string) $row['semester']];
        break;
    }
}
[$masukan, $periode] = Periode::terapkan($_GET, $tahunAktif, $j6 ? 'semester' : 'bulan');

$error = null;
$options = report_service()->options($currentUser);
try {
    $report = report_service()->report($masukan, $currentUser, ReportFilter::SCOPE_SANTRI);
} catch (ApiException $exception) {
    http_response_code($exception->status());
    $error = $exception->getMessage();
    $report = null;
}

$filter = $report['filters'] ?? [
    'date_from' => (string) ($masukan['date_from'] ?? date('Y-m-01')),
    'date_to' => (string) ($masukan['date_to'] ?? date('Y-m-d')), 'academic_year_id' => null, 'teacher_id' => null,
    'class_id' => null, 'schedule_id' => null, 'status' => null,
    'subject_scope' => ReportFilter::SCOPE_SANTRI,
];
// Preserve safe filter input for correction; this never feeds a successful report.
if ($report === null) {
    foreach (array_keys($filter) as $field) {
        if (isset($masukan[$field]) && is_scalar($masukan[$field])) { $filter[$field] = (string) $masukan[$field]; }
    }
    $_SESSION['_report_filter_errors'] = $exception->details();
}
$scope = (string) ($filter['subject_scope'] ?? ReportFilter::SCOPE_SANTRI);
$selected = static fn (mixed $actual, mixed $expected): string => (string) $actual === (string) $expected ? ' selected' : '';
// Ekspor dan cetak memakai query string yang SAMA persis, termasuk penyajian
// dan tanggal hasil periode, sehingga jumlah barisnya tidak mungkin berbeda
// dari yang tampil di layar.
$query = $masukan;
unset($query['page']);
$query['subject_scope'] = $scope;
$isGuruSaja = !in_array('admin', $currentUser['roles'], true) && !$j6;

$judul = $j6 ? 'Laporan absensi pengajian' : 'Laporan kehadiran';
ah_page_open(['title' => $judul, 'user' => $currentUser,
    'description' => ($j6 ? 'Seluruh jadwal pengajian · hanya-baca · ' : '')
        . ah_rentang((string) $filter['date_from'], (string) $filter['date_to'], false),
    'active' => $j6 ? 'absensi' : 'pengajian',
    'breadcrumbs' => $j6
        ? [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => $judul]]
        : [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Pengajian', 'url' => app_url('/admin/admin_pengajian.php')], ['label' => 'Kehadiran']],
    'actions' => $report === null ? '' :
        '<a class="btn btn-outline-primary" href="export_laporan_absensi.php?' . ah_e(http_build_query($query)) . '"><i class="fas fa-file-csv" aria-hidden="true"></i> Ekspor CSV</a>'
        . '<a class="btn btn-primary" target="_blank" rel="noopener" href="laporan_absensi_cetak.php?' . ah_e(http_build_query($query)) . '"><i class="fas fa-print" aria-hidden="true"></i> Cetak / PDF</a>',
]);

if ($error !== null) {
    ah_note('danger', $error, '<p class="small mb-0 mt-2"><a href="admin_laporan_absensi.php">Bersihkan filter</a> lalu coba lagi.</p>');
}

echo ah_seg([
    ['label' => 'Santri', 'url' => '?' . ah_query(['subject_scope' => ReportFilter::SCOPE_SANTRI, 'page' => null]), 'active' => $scope === ReportFilter::SCOPE_SANTRI],
    ['label' => 'Guru', 'url' => '?' . ah_query(['subject_scope' => ReportFilter::SCOPE_GURU, 'page' => null]), 'active' => $scope === ReportFilter::SCOPE_GURU],
    ['label' => 'Gabungan', 'url' => '?' . ah_query(['subject_scope' => ReportFilter::SCOPE_GABUNGAN, 'page' => null]), 'active' => $scope === ReportFilter::SCOPE_GABUNGAN],
], 'Penyajian laporan');

$lanjutan = (int) ($periode === 'rentang') + (int) (($filter['academic_year_id'] ?? null) !== null && $filter['academic_year_id'] !== '')
    + (int) (($filter['schedule_id'] ?? null) !== null && $filter['schedule_id'] !== '') + (int) (($filter['status'] ?? null) !== null && $filter['status'] !== '');
$utama = (int) ($periode !== ($j6 ? 'semester' : 'bulan')) + (int) (($filter['class_id'] ?? null) !== null && $filter['class_id'] !== '')
    + (int) (!$isGuruSaja && ($filter['teacher_id'] ?? null) !== null && $filter['teacher_id'] !== '');
ah_filter_open($utama + $lanjutan, ['subject_scope' => $scope]);
?>
    <div><label class="form-label" for="periode">Periode</label>
        <select class="form-select" id="periode" name="periode">
            <?php foreach (Periode::PILIHAN as $kode => $label): ?>
                <option value="<?= ah_e($kode) ?>"<?= $periode === $kode ? ' selected' : '' ?>><?= ah_e($label) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="class_id">Kelas</label>
        <select class="form-select" id="class_id" name="class_id"><option value="">Semua kelas</option>
            <?php foreach ($options['classes'] as $row): ?>
                <option value="<?= $row['id'] ?>"<?= $selected($filter['class_id'], $row['id']) ?>><?= ah_e($row['name']) ?></option>
            <?php endforeach; ?>
        </select><?= ah_field_error('class_id', '_report_filter') ?></div>
    <?php if (!$isGuruSaja): ?>
        <div><label class="form-label" for="teacher_id">Guru</label>
            <select class="form-select" id="teacher_id" name="teacher_id"><option value="">Semua guru</option>
                <?php foreach ($options['teachers'] as $row): ?>
                    <option value="<?= $row['id'] ?>"<?= $selected($filter['teacher_id'], $row['id']) ?>><?= ah_e($row['name']) ?></option>
                <?php endforeach; ?>
            </select><?= ah_field_error('teacher_id', '_report_filter') ?></div>
    <?php endif; ?>
    <details class="ah-filter__more"<?= $lanjutan > 0 ? ' open' : '' ?>>
        <summary>Filter lanjutan<?= $lanjutan > 0 ? ' (' . $lanjutan . ')' : '' ?></summary>
        <div class="ah-filter__more-body">
            <div><label class="form-label" for="date_from">Tanggal mulai</label>
                <input class="form-control" id="date_from" name="date_from" type="date" value="<?= ah_e($filter['date_from']) ?>"><?= ah_field_error('date_from', '_report_filter') ?></div>
            <div><label class="form-label" for="date_to">Tanggal akhir</label>
                <input class="form-control" id="date_to" name="date_to" type="date" value="<?= ah_e($filter['date_to']) ?>"><?= ah_field_error('date_to', '_report_filter') ?></div>
            <p class="small text-muted mb-0">Tanggal dipakai bila Periode = Rentang sendiri.</p>
            <div><label class="form-label" for="academic_year_id">Tahun ajaran</label>
                <select class="form-select" id="academic_year_id" name="academic_year_id"><option value="">Semua tahun ajaran</option>
                    <?php foreach ($options['academic_years'] as $row): ?>
                        <option value="<?= $row['id'] ?>"<?= $selected($filter['academic_year_id'], $row['id']) ?>><?= ah_e($row['year'] . ' ' . $row['semester']) ?></option>
                    <?php endforeach; ?>
                </select><?= ah_field_error('academic_year_id', '_report_filter') ?></div>
            <div class="ah-filter__wide"><label class="form-label" for="schedule_id">Jadwal</label>
                <select class="form-select" id="schedule_id" name="schedule_id"><option value="">Semua jadwal</option>
                    <?php foreach ($options['schedules'] as $row): ?>
                        <option value="<?= $row['id'] ?>"<?= $selected($filter['schedule_id'], $row['id']) ?>><?= ah_e($row['label']) ?></option>
                    <?php endforeach; ?>
                </select><?= ah_field_error('schedule_id', '_report_filter') ?></div>
            <div><label class="form-label" for="status">Status kehadiran</label>
                <select class="form-select" id="status" name="status"><option value="">Semua status</option>
                    <?php foreach ($options['statuses'] as $status): ?>
                        <option value="<?= ah_e($status) ?>"<?= $selected($filter['status'], $status) ?>><?= ah_e($status) ?></option>
                    <?php endforeach; ?>
                </select><?= ah_field_error('status', '_report_filter') ?></div>
        </div>
    </details>
<?php ah_filter_close(['subject_scope' => $scope]); ?>

<?php if ($report !== null):
    $summary = $report['summary'];
    $total = max(0, (int) $summary['detail_count']);
    $persen = static fn (int $n): string => $total === 0 ? '0%' : (string) round(100 * $n / $total) . '%';
    $st = $summary['statuses'];
    echo ah_stats([
        ['label' => 'Pertemuan', 'value' => (string) $summary['meeting_count'], 'hint' => $total . ' catatan ' . strtolower($report['active_filters']['Penyajian'] ?? '')],
        ['label' => 'Hadir', 'value' => $persen($st['Hadir']), 'hint' => (string) $st['Hadir']],
        ['label' => 'Terlambat', 'value' => $persen($st['Terlambat']), 'hint' => (string) $st['Terlambat']],
        ['label' => 'Izin/Sakit', 'value' => $persen($st['Izin'] + $st['Sakit']), 'hint' => $st['Izin'] . ' izin · ' . $st['Sakit'] . ' sakit'],
        ['label' => 'Alpa', 'value' => $persen($st['Alpa']), 'hint' => (string) $st['Alpa'], 'warn' => $st['Alpa'] > 0],
    ], 'Ringkasan kehadiran');
    ?>
<?php if ($report['schedules'] === []): ?>
    <section class="ah-card"><div class="ah-card__body"><?= ah_empty('Tidak ada data sesuai saringan', 'Ubah periode, penyajian, atau saringan lain di atas.') ?></div></section>
<?php else: ?>
<section class="ah-card" aria-labelledby="ah-komposisi">
    <div class="ah-card__head"><span id="ah-komposisi">Komposisi kehadiran per jadwal</span></div>
    <div class="ah-card__body">
        <ul class="ah-stack">
            <?php foreach (array_slice($report['schedules'], 0, 12) as $row):
                $n = max(1, (int) $row['detail_count']);
                ?>
                <li><span class="ah-stack__label"><span><?= ah_e($row['subject'] . ' · ' . $row['class']['name']) ?></span><span><?= (int) round(100 * $row['statuses']['Hadir'] / $n) ?>% hadir</span></span>
                    <span class="ah-stack__bar" role="img" aria-label="<?= ah_e(implode(', ', array_map(static fn ($k, $v) => $k . ' ' . $v, array_keys($row['statuses']), $row['statuses']))) ?>">
                        <?php foreach (['Hadir' => 'hadir', 'Terlambat' => 'terlambat', 'Izin' => 'izin', 'Sakit' => 'sakit', 'Alpa' => 'alpa'] as $label => $kelas): if ($row['statuses'][$label] < 1) { continue; } ?>
                            <span class="ah-k-<?= $kelas ?>" style="width:<?= round(100 * $row['statuses'][$label] / $n, 2) ?>%"></span>
                        <?php endforeach; ?>
                    </span></li>
            <?php endforeach; ?>
        </ul>
        <ul class="ah-stack__key" aria-hidden="true">
            <li><i class="ah-k-hadir"></i>Hadir</li><li><i class="ah-k-terlambat"></i>Terlambat</li><li><i class="ah-k-izin"></i>Izin</li><li><i class="ah-k-sakit"></i>Sakit</li><li><i class="ah-k-alpa"></i>Alpa</li>
        </ul>
        <?php if (count($report['schedules']) > 12): ?><p class="small text-muted mb-0 mt-2">Menampilkan 12 dari <?= count($report['schedules']) ?> jadwal; semuanya ada di tabel di bawah.</p><?php endif; ?>
    </div>
</section>

<section class="ah-card" aria-labelledby="ah-ringkas-jadwal">
    <div class="ah-card__head"><span id="ah-ringkas-jadwal">Ringkasan per jadwal</span><span class="text-muted small"><?= count($report['schedules']) ?> jadwal</span></div>
    <div class="ah-table-wrap"><table class="ah-table ah-table--cards">
        <caption class="ah-visually-hidden">Ringkasan kehadiran per jadwal sesuai saringan</caption>
        <thead><tr><th scope="col">Jadwal</th><th scope="col">Guru</th><th scope="col">Kelas</th><th scope="col">Pertemuan</th>
            <th scope="col">Hadir</th><th scope="col" class="ah-col-secondary">Terlambat</th><th scope="col" class="ah-col-secondary">Izin</th><th scope="col" class="ah-col-secondary">Sakit</th><th scope="col">Alpa</th></tr></thead>
        <tbody>
        <?php foreach ($report['schedules'] as $row): ?>
            <tr>
                <td><?= ah_e($row['subject']) ?><span class="ah-cell-sub"><?= ah_e($row['book']) ?></span></td>
                <td><?= ah_e($row['teacher']['name']) ?></td>
                <td><?= ah_e($row['class']['name']) ?></td>
                <td><?= $row['meeting_count'] ?></td>
                <td><?= $row['statuses']['Hadir'] ?></td><td class="ah-col-secondary"><?= $row['statuses']['Terlambat'] ?></td>
                <td class="ah-col-secondary"><?= $row['statuses']['Izin'] ?></td><td class="ah-col-secondary"><?= $row['statuses']['Sakit'] ?></td><td><?= $row['statuses']['Alpa'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>

<details class="ah-fold" id="rincian"<?= isset($_GET['page']) ? ' open' : '' ?>>
    <summary>Rincian baris kehadiran <b><?= (int) $summary['detail_count'] ?></b></summary>
    <div class="px-3 pb-3">
        <p class="small text-muted">Kontrol konsistensi: <?= array_sum($summary['statuses']) ?> status = <?= (int) $summary['detail_count'] ?> baris<?= $scope === ReportFilter::SCOPE_GABUNGAN ? ' (' . $summary['student_attendance_count'] . ' santri + ' . $summary['teacher_attendance_count'] . ' guru)' : '' ?>.
            <?= ah_e(match ($scope) {
                ReportFilter::SCOPE_GURU => 'Penyajian Guru: hanya kehadiran guru pengampu.',
                ReportFilter::SCOPE_GABUNGAN => 'Penyajian Gabungan: santri dan guru dengan penanda jenis.',
                default => 'Penyajian Santri: guru tampil sebagai pengampu, tidak dihitung sebagai santri.',
            }) ?></p>
        <?php if ($report['items'] === []): ?>
            <?= ah_empty('Tidak ada baris kehadiran sesuai saringan', 'Ubah penyajian atau saringan di atas. Mode Santri tidak menampilkan absensi guru, dan sebaliknya.') ?>
        <?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table ah-table--cards">
                <caption class="ah-visually-hidden">Baris kehadiran sesuai saringan</caption>
                <thead><tr><th scope="col">Tanggal</th><th scope="col">Jadwal</th><th scope="col" class="ah-col-secondary">Guru / kelas</th>
                    <th scope="col">Peserta</th><th scope="col">Status</th><th scope="col" class="ah-col-secondary">Pencatat</th><th scope="col"><span class="ah-visually-hidden">Pertemuan</span></th></tr></thead>
                <tbody>
                <?php foreach ($report['items'] as $row): ?>
                    <tr>
                        <td><?= ah_e(ah_tanggal((string) $row['meeting_date'])) ?></td>
                        <td><?= ah_e($row['subject']) ?></td>
                        <td class="ah-col-secondary"><?= ah_e($row['teacher_name']) ?><span class="ah-cell-sub"><?= ah_e($row['class_name']) ?></span></td>
                        <td><?= ah_e($row['subject_name']) ?><span class="ah-cell-sub"><?= ah_e($row['subject_type'] . ' · ' . $row['identity_number']) ?></span></td>
                        <td><?= ah_status((string) $row['attendance_status']) ?><span class="ah-cell-sub"><?= ah_e($row['notes'] ?? '') ?></span></td>
                        <td class="ah-col-secondary"><?= ah_e($row['recorder_name'] ?? '-') ?><span class="ah-cell-sub"><?= ah_e($row['updated_at'] ? ah_waktu((string) $row['updated_at']) : '-') ?></span></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="laporan_absensi_detail.php?id=<?= $row['meeting_id'] ?>">Pertemuan</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php ah_pagination($report['pagination']['total'], $report['pagination']['current_page'], $report['pagination']['per_page']); ?>
        <?php endif; ?>
    </div>
</details>
<?php endif; ?>
<?php ah_old_clear('_report_filter'); ah_page_close(); ?>
