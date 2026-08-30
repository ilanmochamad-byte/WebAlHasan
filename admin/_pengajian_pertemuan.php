<?php

declare(strict_types=1);

/**
 * Berkas ini adalah POTONGAN TAMPILAN, bukan halaman.
 *
 * Ia hanya boleh dimuat dari halaman yang sudah menjalankan guard servernya
 * sendiri. Permintaan langsung ke alamat berkas ini ditolak, sehingga tidak ada
 * jalur masuk yang melewati pemeriksaan otorisasi.
 */
if (!defined('AH_PARTIAL')) {
    http_response_code(404);
    exit;
}


/**
 * Tab "Pertemuan" pada modul Pengajian terpadu.
 *
 * Dimuat hanya oleh `admin/admin_pengajian.php`, yang sudah lebih dulu
 * menjalankan guard server.
 *
 * Pelaksanaan jadwal pada TANGGAL tertentu. Satu jadwal tetap dapat memiliki
 * banyak pertemuan; keunikan jadwal–tanggal, status Draf/Dibuka/Selesai,
 * snapshot peserta, dan auditnya ditegakkan oleh `App\Schedule\ScheduleService`
 * dan tidak diubah oleh paket perapihan ini. Snapshot peserta tetap dibekukan
 * saat pertemuan dibuka, sehingga santri yang berpindah kelas tidak mengubah
 * riwayat peserta pertemuan lama.
 *
 * @var array<string, mixed> $currentUser
 * @var \App\Schedule\ScheduleService $service
 * @var array<int, array<string, mixed>> $scheduleOptions
 * @var array<int, array<string, mixed>> $meetings
 * @var array<string, mixed>|null $selectedMeeting
 * @var int $selectedScheduleId
 */

$tautanJadwal = static fn (int $jadwalId): string => app_url('/admin/admin_pengajian.php') . '?'
    . http_build_query(['tab' => 'jadwal', 'action' => 'detail', 'id' => $jadwalId]);
?>

<section class="ah-card" aria-labelledby="ah-buat-pertemuan">
    <div class="ah-card__head"><span id="ah-buat-pertemuan">Buat atau buka pertemuan</span></div>
    <div class="ah-card__body">
        <?php if ($scheduleOptions === []): ?>
            <?= ah_empty(
                'Tidak ada jadwal yang dapat dibuka',
                'Belum ada jadwal aktif dengan hari dan waktu terstruktur pada semester berjalan untuk akun ini.',
                '<a class="btn btn-sm btn-outline-primary" href="' . ah_e(app_url('/admin/admin_pengajian.php?tab=jadwal')) . '">Lihat tab Jadwal</a>'
            ) ?>
        <?php else: ?>
            <form method="post" class="row g-3">
                <?= ah_csrf() ?>
                <input type="hidden" name="tab" value="pertemuan">
                <div class="col-lg-6">
                    <label class="form-label" for="schedule_id">Jadwal aktif semester ini</label>
                    <select class="form-select" id="schedule_id" name="schedule_id" required aria-describedby="bantuan_tanggal">
                        <option value="">Pilih jadwal</option>
                        <?php foreach ($scheduleOptions as $schedule): ?>
                            <option value="<?= (int) $schedule['id'] ?>" <?= $selectedScheduleId === (int) $schedule['id'] ? 'selected' : '' ?>>
                                <?= ah_e($schedule['hari'] . ' ' . substr($schedule['waktu_mulai'], 0, 5) . '–' . substr($schedule['waktu_selesai'], 0, 5) . ' — ' . $schedule['nama_kelas'] . ' — ' . $schedule['fan_ilmu'] . ' — ' . $schedule['nama_guru']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text" id="bantuan_tanggal">Tanggal wajib jatuh pada hari sesuai pola jadwal.</div>
                </div>
                <div class="col-lg-3"><label class="form-label" for="tanggal_pertemuan">Tanggal pertemuan</label>
                    <input class="form-control" id="tanggal_pertemuan" type="date" name="tanggal_pertemuan" value="<?= date('Y-m-d') ?>" required></div>
                <div class="col-lg-3"><label class="form-label" for="catatan">Catatan</label>
                    <input class="form-control" id="catatan" name="catatan" maxlength="2000" placeholder="Opsional"></div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-secondary" name="action" value="draft">Simpan draf</button>
                    <button class="btn btn-primary" name="action" value="open">Buka &amp; bekukan peserta</button>
                </div>
                <div class="col-12">
                    <?php ah_note('info', 'Membuka pertemuan membekukan daftar peserta saat itu juga (snapshot). Santri yang berpindah kelas setelahnya tidak mengubah peserta pertemuan yang sudah dibuka.'); ?>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php if ($selectedMeeting): ?>
<section class="ah-card" aria-labelledby="ah-detail-pertemuan">
    <div class="ah-card__head">
        <span id="ah-detail-pertemuan">Detail pertemuan #<?= (int) $selectedMeeting['id'] ?></span>
        <span class="d-flex flex-wrap gap-2 align-items-center">
            <?= ah_badge((string) $selectedMeeting['status'], match ((string) $selectedMeeting['status']) {
                'Selesai' => 'muted', 'Dibuka' => 'ok', default => 'info',
            }) ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= ah_e($tautanJadwal((int) $selectedMeeting['jadwal_id'])) ?>">Lihat jadwalnya</a>
        </span>
    </div>
    <div class="ah-card__body">
        <div class="row g-3 mb-4">
            <div class="col-md-3"><small class="text-muted d-block">Tanggal</small><?= ah_e($selectedMeeting['tanggal_pertemuan']) ?></div>
            <div class="col-md-3"><small class="text-muted d-block">Jadwal</small><?= ah_e($selectedMeeting['hari'] . ', ' . substr($selectedMeeting['waktu_mulai'], 0, 5) . '–' . substr($selectedMeeting['waktu_selesai'], 0, 5)) ?></div>
            <div class="col-md-3"><small class="text-muted d-block">Kelas</small><?= ah_e($selectedMeeting['nama_kelas']) ?></div>
            <div class="col-md-3"><small class="text-muted d-block">Guru</small><?= ah_e($selectedMeeting['nama_guru']) ?></div>
            <div class="col-md-3"><small class="text-muted d-block">Dibuka</small><?= ah_e($selectedMeeting['opened_at'] ?: '-') ?></div>
            <div class="col-md-3"><small class="text-muted d-block">Selesai</small><?= ah_e($selectedMeeting['completed_at'] ?: '-') ?></div>
            <div class="col-md-6"><small class="text-muted d-block">Catatan</small><?= ah_e($selectedMeeting['catatan'] ?: '-') ?></div>
        </div>

        <?php if ($selectedMeeting['status'] === 'Draf'): ?>
            <form method="post" class="mb-4">
                <?= ah_csrf() ?>
                <input type="hidden" name="tab" value="pertemuan">
                <input type="hidden" name="schedule_id" value="<?= (int) $selectedMeeting['jadwal_id'] ?>">
                <input type="hidden" name="tanggal_pertemuan" value="<?= ah_e($selectedMeeting['tanggal_pertemuan']) ?>">
                <input type="hidden" name="catatan" value="<?= ah_e($selectedMeeting['catatan']) ?>">
                <button class="btn btn-primary" name="action" value="open">Buka &amp; bekukan peserta</button>
            </form>
        <?php elseif ($selectedMeeting['status'] === 'Dibuka'): ?>
            <form method="post" class="mb-4" onsubmit="return confirm('Tandai pertemuan ini selesai? Setelah selesai, waktu penyelesaian tercatat dan pertemuan tidak dibuka ulang.')">
                <?= ah_csrf() ?>
                <input type="hidden" name="tab" value="pertemuan">
                <input type="hidden" name="meeting_id" value="<?= (int) $selectedMeeting['id'] ?>">
                <button class="btn btn-outline-secondary" name="action" value="complete">Selesaikan pertemuan</button>
            </form>
        <?php endif; ?>

        <h2 class="h6">Snapshot peserta (<?= count($selectedMeeting['participants']) ?>)</h2>
        <?php if ($selectedMeeting['participants'] === []): ?>
            <p class="text-muted mb-0"><?= $selectedMeeting['status'] === 'Draf'
                ? 'Peserta dibekukan saat pertemuan dibuka.'
                : 'Tidak ada keanggotaan aktif di kelas ini saat pertemuan dibuka.' ?></p>
        <?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table">
                <caption class="ah-visually-hidden">Peserta yang dibekukan saat pertemuan dibuka</caption>
                <thead><tr><th scope="col">NIS saat dibuka</th><th scope="col">Nama saat dibuka</th><th scope="col">ID santri</th><th scope="col">ID keanggotaan</th></tr></thead>
                <tbody>
                <?php foreach ($selectedMeeting['participants'] as $participant): ?>
                    <tr>
                        <td><?= ah_e($participant['nis_snapshot']) ?></td>
                        <td><?= ah_e($participant['nama_santri_snapshot']) ?></td>
                        <td><?= (int) $participant['santri_id'] ?></td>
                        <td><?= $participant['plotting_kelas_id'] === null ? '-' : (int) $participant['plotting_kelas_id'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php ah_list_search($meetingQuery, 'Cari tanggal, guru, kelas, pelajaran, atau status', ['tab' => 'pertemuan']); ?>
<section class="ah-card" aria-labelledby="ah-riwayat-pertemuan">
    <div class="ah-card__head"><span id="ah-riwayat-pertemuan">Riwayat pertemuan</span>
        <a class="btn btn-sm btn-outline-primary" href="<?= ah_e(app_url('/admin/admin_laporan_absensi.php')) ?>">Laporan kehadiran</a></div>
    <?php if ($meetings === []): ?>
        <div class="ah-card__body"><?= ah_empty('Belum ada pertemuan', 'Pertemuan yang Anda buat akan muncul di sini beserta status dan jumlah pesertanya.') ?></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table">
            <caption class="ah-visually-hidden">Riwayat pertemuan dalam cakupan Anda</caption>
            <thead><tr><th scope="col">Tanggal</th><th scope="col">Jadwal</th><th scope="col">Kelas</th><th scope="col">Guru</th><th scope="col">Status</th><th scope="col">Peserta</th><th scope="col">Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($meetings as $meeting): ?>
                <tr>
                    <td><?= ah_e($meeting['tanggal_pertemuan']) ?></td>
                    <td><?= ah_e($meeting['hari'] . ' ' . substr($meeting['waktu_mulai'], 0, 5)) ?><span class="ah-cell-sub"><?= ah_e($meeting['fan_ilmu']) ?></span></td>
                    <td><?= ah_e($meeting['nama_kelas']) ?></td>
                    <td><?= ah_e($meeting['nama_guru']) ?></td>
                    <td><?= ah_badge((string) $meeting['status'], match ((string) $meeting['status']) {
                            'Selesai' => 'muted', 'Dibuka' => 'ok', default => 'info',
                        }) ?></td>
                    <td><?= (int) $meeting['participant_count'] ?></td>
                    <td><div class="ah-actions">
                        <a class="btn btn-sm btn-outline-primary" href="?<?= ah_e(ah_query(['tab' => 'pertemuan', 'id' => (int) $meeting['id'], 'schedule_id' => null])) ?>">Detail</a>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<?php ah_pagination((int) $meetingResult['total'], (int) $meetingResult['page'], 20); ?>
