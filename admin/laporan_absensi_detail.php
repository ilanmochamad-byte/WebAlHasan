<?php

declare(strict_types=1);

/**
 * Detail pertemuan dari laporan kehadiran (redesain website Tahap 4, J-5).
 *
 * Hanya-baca untuk admin, guru (pertemuan sendiri), dan Bagian Pendidikan
 * (seluruh jadwal, keputusan H). Kepemilikan diperiksa `ReportService::meeting`.
 * Tidak ada tombol ubah: koreksi kehadiran tetap di aplikasi (keputusan R).
 */

require_once __DIR__ . '/_laporan_guard.php';

use App\Api\ApiException;
use App\Report\ReportFilter;

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
// PK-5: setiap pembukaan rincian oleh Keamanan wajib berjejak, tanpa isi.
if ($kehadiranKeamanan && !audit_logger()->log('keamanan.kehadiran.dilihat', 'pertemuan_pengajian', (int) $id,
    null, ['pertemuan_id' => (int) $id], (int) $currentUser['id'])) {
    http_response_code(503);
    exit('Audit akses tidak dapat disimpan. Rincian tidak ditampilkan.');
}
$j6 = !empty($currentUser[ReportFilter::BACA_SEMUA_JADWAL]);
$task = $meeting['task'];
$labelIzin = izin_service()->approvedCovering(array_map(static fn (array $s): int => (int) $s['student_id'], $meeting['students']), (string) $meeting['date']);
$guru = $meeting['teacher_attendance'];

ah_page_open([
    'title' => $task['subject'] . ' · ' . $task['class']['name'],
    'description' => ah_tanggal((string) $meeting['date']) . ' · ' . $task['teacher']['name'],
    'user' => $currentUser,
    'active' => $j6 ? 'absensi' : 'pengajian',
    'actions' => '<button class="btn btn-outline-secondary" type="button" data-ah-print hidden><i class="fas fa-print" aria-hidden="true"></i> Cetak</button>'
        . '<a class="btn btn-outline-secondary" href="admin_laporan_absensi.php">Kembali ke laporan</a>',
    'breadcrumbs' => $j6
        ? [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Laporan absensi pengajian', 'url' => app_url('/admin/admin_laporan_absensi.php')], ['label' => 'Pertemuan ' . ah_tanggal((string) $meeting['date'], false, false)]]
        : [['label' => 'Pengajian', 'url' => app_url('/admin/admin_pengajian.php')], ['label' => 'Kehadiran', 'url' => app_url('/admin/admin_laporan_absensi.php')], ['label' => 'Pertemuan ' . ah_tanggal((string) $meeting['date'], false, false)]],
]);
?>
<div class="ah-split">
    <section class="ah-card" aria-labelledby="ah-peserta">
        <div class="ah-card__head"><span id="ah-peserta">Peserta</span><span class="text-muted small"><?= $meeting['student_summary']['recorded_count'] ?>/<?= $meeting['student_summary']['participant_count'] ?> tercatat</span></div>
        <?php if ($meeting['students'] === []): ?>
            <div class="ah-card__body"><p class="text-muted mb-0">Belum ada peserta yang dibekukan untuk pertemuan ini.</p></div>
        <?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table ah-table--cards">
                <caption class="ah-visually-hidden">Kehadiran peserta pertemuan</caption>
                <thead><tr><th scope="col">NIS</th><th scope="col">Nama</th><th scope="col">Kehadiran</th><th scope="col" class="ah-col-secondary">Catatan</th><th scope="col" class="ah-col-secondary">Dicatat oleh</th></tr></thead>
                <tbody>
                <?php foreach ($meeting['students'] as $student): $izin = $labelIzin[(int) $student['student_id']] ?? null; ?>
                    <tr>
                        <td><?= ah_e($student['nis']) ?></td>
                        <td><?= ah_e($student['name']) ?><?php if ($izin !== null): ?><span class="ah-cell-sub"><?= ah_badge('Sedang izin #' . $izin['id'], 'info') ?></span><?php endif; ?></td>
                        <td><?= $student['status'] === null ? ah_badge('Belum dicatat', 'muted') : ah_status((string) $student['status']) ?></td>
                        <td class="ah-col-secondary"><?= ah_e($student['notes'] ?? '—') ?></td>
                        <td class="ah-col-secondary"><?= ah_e($student['recorded_by'] ?? '—') ?><?php if ($student['updated_at'] !== null): ?><span class="ah-cell-sub" title="Perubahan terakhir"><i class="fas fa-clock" aria-hidden="true"></i> <?= ah_e(ah_waktu((string) $student['updated_at'])) ?></span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <section class="ah-card" aria-labelledby="ah-pertemuan">
        <div class="ah-card__head"><span id="ah-pertemuan">Pertemuan</span><?= ah_status((string) $meeting['status']) ?></div>
        <div class="ah-card__body">
            <dl class="ah-kv">
                <dt>Guru</dt><dd><?= ah_e($task['teacher']['name']) ?> · <?= $guru === null ? 'kehadiran belum dicatat' : ah_e($guru['status']) ?></dd>
                <dt>Jadwal</dt><dd><?= ah_e(ah_hari($task['day']) . ', ' . $task['start_time'] . '–' . $task['end_time']) ?></dd>
                <dt>Kitab</dt><dd><?= ah_e($task['book']) ?></dd>
                <dt>Tempat</dt><dd><?= ah_e($task['place']) ?></dd>
                <dt>Semester</dt><dd><?= ah_e($task['academic_year']['year'] . ' ' . $task['academic_year']['semester']) ?></dd>
                <dt>Catatan</dt><dd><?= ah_e($meeting['notes'] ?? '—') ?></dd>
            </dl>
            <p class="small text-muted mt-3 mb-0">Kehadiran dicatat dan dikoreksi lewat aplikasi Al Hasan. Waktu di kolom "Dicatat oleh" adalah perubahan terakhir.</p>
        </div>
    </section>
</div>
<?php ah_page_close(); ?>
