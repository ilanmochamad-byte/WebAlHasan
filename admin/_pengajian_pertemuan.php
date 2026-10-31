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
 * Tab "Pertemuan" pada modul Pengajian (redesain website Tahap 4, J-2/J-3).
 *
 * Dimuat hanya oleh `admin/admin_pengajian.php`, yang sudah lebih dulu
 * menjalankan guard server.
 *
 * Tanpa `id`: daftar pertemuan (J-3); "Buka pertemuan" di panel samping.
 * Dengan `id`: pertemuan & kehadiran (J-2). Keputusan R: absensi tidak
 * dicatat di website — kehadiran guru dan santri tampil hanya-baca dari
 * aplikasi; web hanya membuka (membekukan peserta) dan menyelesaikan
 * pertemuan seperti sebelumnya. Keputusan S: santri yang izinnya disetujui
 * pada tanggal pertemuan diberi label "Sedang izin"; status tidak diisi
 * otomatis. Keunikan jadwal–tanggal, status Draf/Dibuka/Selesai, snapshot
 * peserta, dan audit tetap ditegakkan `App\Schedule\ScheduleService`.
 *
 * @var array<string, mixed> $currentUser
 * @var \App\Schedule\ScheduleService $service
 * @var array<int, array<string, mixed>> $scheduleOptions
 * @var array<int, array<string, mixed>> $meetings
 * @var array<string, mixed> $meetingResult
 * @var array<string, mixed>|null $selectedMeeting
 * @var array<string, mixed>|null $kehadiran
 * @var array<int, array{id:int, tgl_izin:string, tgl_kembali:string}> $labelIzin
 * @var int $selectedScheduleId
 * @var string $meetingQuery
 * @var string $mode
 */

$statusTone = static fn (string $status): string => match ($status) {
    'Selesai' => 'ok', 'Dibuka' => 'info', default => 'muted',
};
$tutup = '?' . ah_query(['tab' => 'pertemuan', 'action' => null, 'schedule_id' => null]);
?>

<?php if ($selectedMeeting === null && ($mode === 'create' || $selectedScheduleId > 0)): ?>
<?php ah_panel_open(['title' => 'Buka pertemuan', 'close_url' => $tutup, 'description' => 'Pelaksanaan jadwal pada tanggal tertentu.']); ?>
    <?php if ($scheduleOptions === []): ?>
        <?= ah_empty(
            'Tidak ada jadwal yang dapat dibuka',
            'Belum ada jadwal aktif dengan hari dan waktu terstruktur pada semester berjalan untuk akun ini.',
            '<a class="btn btn-sm btn-outline-primary" href="' . ah_e(app_url('/admin/admin_pengajian.php')) . '">Lihat jadwal</a>'
        ) ?>
    <?php else: ?>
        <?= ah_error_summary('_meeting_old', 'Pertemuan belum dibuat.') ?>
        <form method="post" action="<?= ah_e(app_url('/admin/admin_pengajian.php') . '?tab=pertemuan') ?>" class="row g-3">
            <?= ah_csrf() ?>
            <input type="hidden" name="tab" value="pertemuan">
            <div class="col-12">
                <label class="form-label" for="schedule_id">Jadwal aktif semester ini</label>
                <select class="form-select" id="schedule_id" name="schedule_id" required aria-describedby="bantuan_tanggal">
                    <option value="">Pilih jadwal</option>
                    <?php $pilihJadwal = (int) (ah_old('schedule_id', null, '_meeting_old') ?: $selectedScheduleId); ?>
                    <?php foreach ($scheduleOptions as $schedule): ?>
                        <option value="<?= (int) $schedule['id'] ?>" <?= $pilihJadwal === (int) $schedule['id'] ? 'selected' : '' ?>>
                            <?= ah_e(ah_hari((string) $schedule['hari']) . ' ' . substr($schedule['waktu_mulai'], 0, 5) . ' · ' . $schedule['fan_ilmu'] . ' · ' . ($schedule['kelas_label'] ?? $schedule['nama_kelas']) . ' · ' . $schedule['nama_guru']) ?>
                        </option>
                    <?php endforeach; ?>
                </select><?= ah_field_error('schedule_id', '_meeting_old') ?>
                <div class="form-text" id="bantuan_tanggal">Tanggal wajib jatuh pada hari sesuai pola jadwal.</div>
            </div>
            <div class="col-12"><label class="form-label" for="tanggal_pertemuan">Tanggal pertemuan</label>
                <input class="form-control" id="tanggal_pertemuan" type="date" name="tanggal_pertemuan" value="<?= ah_e(ah_old('tanggal_pertemuan', ['tanggal_pertemuan' => date('Y-m-d')], '_meeting_old')) ?>" required><?= ah_field_error('tanggal_pertemuan', '_meeting_old') ?></div>
            <div class="col-12"><label class="form-label" for="catatan">Catatan pertemuan</label>
                <textarea class="form-control" id="catatan" name="catatan" rows="2" maxlength="2000" placeholder="Opsional, mis. materi: QS Al-Baqarah 1–5"><?= ah_e(ah_old('catatan', null, '_meeting_old')) ?></textarea><?= ah_field_error('catatan', '_meeting_old') ?></div>
            <div class="col-12"><?php ah_note('info', 'Membuka pertemuan membekukan daftar peserta saat itu juga. Santri yang berpindah kelas sesudahnya tidak mengubah peserta pertemuan ini. Kehadiran dicatat guru lewat aplikasi Al Hasan.'); ?></div>
            <div class="col-12 d-flex flex-wrap gap-2 ah-sticky-sm">
                <button class="btn btn-outline-secondary" name="action" value="draft">Simpan draf</button>
                <button class="btn btn-primary" name="action" value="open">Buka &amp; bekukan peserta</button>
            </div>
        </form>
    <?php endif; ?>
<?php ah_panel_close(); ?>
<?php endif; ?>

<?php if ($selectedMeeting !== null):
    $status = (string) $selectedMeeting['status'];
    $siswa = [];
    foreach (($kehadiran['students'] ?? []) as $student) {
        $siswa[(int) $student['student_id']] = $student;
    }
    $ringkas = array_fill_keys(['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpa'], 0);
    $belum = 0;
    foreach ($selectedMeeting['participants'] as $participant) {
        $catatan = $siswa[(int) $participant['santri_id']]['status'] ?? null;
        if ($catatan !== null && isset($ringkas[$catatan])) {
            $ringkas[$catatan]++;
        } else {
            $belum++;
        }
    }
    $jumlah = count($selectedMeeting['participants']);
    // Kelas gabungan: kelas asal tiap peserta ditampilkan bila lebih dari satu kelas.
    $banyakKelas = count(array_unique(array_map(static fn (array $p): int => (int) $p['kelas_id_snapshot'], $selectedMeeting['participants']))) > 1
        || str_contains((string) ($selectedMeeting['kelas_label'] ?? ''), ',');
    $guruHadir = $kehadiran['teacher_attendance'] ?? null;
    $bolehBukaIzin = in_array('admin', $currentUser['roles'], true);
    ?>
    <ol class="ah-steps ah-stepper" aria-label="Status pertemuan">
        <?php foreach (['Draf', 'Dibuka', 'Selesai'] as $langkah): ?>
            <?php $urut = ['Draf' => 0, 'Dibuka' => 1, 'Selesai' => 2]; ?>
            <li<?= $langkah === $status ? ' aria-current="step"' : ($urut[$langkah] < ($urut[$status] ?? 0) ? ' class="is-done"' : '') ?>><?= ah_e($langkah) ?></li>
        <?php endforeach; ?>
    </ol>
    <?php ah_note('info', 'Kehadiran dicatat guru lewat aplikasi Al Hasan. Halaman ini hanya menampilkan hasilnya; koreksi sesudah pertemuan selesai juga dilakukan di aplikasi dengan alasan koreksi.'); ?>

    <div class="ah-split">
        <div class="ah-split__col">
            <section class="ah-card" aria-labelledby="ah-hadir-guru">
                <div class="ah-card__head"><span id="ah-hadir-guru">Kehadiran guru</span><?= $guruHadir === null ? ah_badge('Belum dicatat', 'muted') : ah_status((string) $guruHadir['status']) ?></div>
                <div class="ah-card__body"><p class="mb-0"><?= ah_e($selectedMeeting['nama_guru']) ?>
                    <?php if ($guruHadir !== null): ?><small class="d-block text-muted">Dicatat <?= ah_e((string) ($guruHadir['recorded_by'] ?? 'aplikasi')) ?> · <?= ah_e(ah_waktu((string) ($guruHadir['updated_at'] ?? $guruHadir['recorded_at'] ?? ''))) ?><?= ($guruHadir['notes'] ?? '') !== '' ? ' · ' . ah_e((string) $guruHadir['notes']) : '' ?></small><?php endif; ?></p></div>
            </section>

            <section class="ah-card" aria-labelledby="ah-peserta">
                <div class="ah-card__head"><span id="ah-peserta">Peserta (<?= $jumlah ?>)</span>
                    <?php if ($jumlah > 0): ?><a class="btn btn-sm btn-outline-primary" href="<?= ah_e(app_url('/admin/laporan_absensi_detail.php?id=' . (int) $selectedMeeting['id'])) ?>">Rincian kehadiran</a><?php endif; ?></div>
                <?php if ($jumlah === 0): ?>
                    <div class="ah-card__body"><p class="text-muted mb-0"><?= $status === 'Draf'
                        ? 'Peserta dibekukan saat pertemuan dibuka.'
                        : 'Tidak ada keanggotaan aktif di kelas ini saat pertemuan dibuka.' ?></p></div>
                <?php else: ?>
                    <div class="ah-sumline"><span><b><?= $ringkas['Hadir'] ?> hadir</b> · <?= $ringkas['Terlambat'] ?> terlambat · <?= $ringkas['Izin'] ?> izin · <?= $ringkas['Sakit'] ?> sakit · <?= $ringkas['Alpa'] ?> alpa<?= $belum > 0 ? ' · ' . $belum . ' belum dicatat' : '' ?></span>
                        <span class="text-muted small"><?= count($labelIzin) ?> sedang izin</span></div>
                    <div class="ah-card__body pb-0 ah-no-print" hidden>
                        <label class="ah-visually-hidden" for="cari-peserta">Cari santri</label>
                        <input class="form-control" type="search" id="cari-peserta" placeholder="Cari santri…" data-ah-list-filter="daftar-peserta" autocomplete="off">
                    </div>
                    <ul class="ah-people mt-2" id="daftar-peserta">
                        <?php foreach ($selectedMeeting['participants'] as $participant):
                            $sid = (int) $participant['santri_id'];
                            $catat = $siswa[$sid] ?? null;
                            $izin = $labelIzin[$sid] ?? null;
                            ?>
                            <li data-ah-filter-text="<?= ah_e($participant['nama_santri_snapshot'] . ' ' . $participant['nis_snapshot']) ?>">
                                <span class="ah-people__main"><b><?= ah_e($participant['nama_santri_snapshot']) ?></b>
                                    <small>NIS <?= ah_e($participant['nis_snapshot']) ?><?= $banyakKelas && ($participant['kelas_asal'] ?? '') !== '' ? ' · ' . ah_e((string) $participant['kelas_asal']) : '' ?><?= ($catat['notes'] ?? '') !== '' && $catat['notes'] !== null ? ' · ' . ah_e((string) $catat['notes']) : '' ?></small>
                                    <?php if ($izin !== null): ?>
                                        <small><?php $teksIzin = 'Sedang izin #' . $izin['id'] . ' · ' . ah_rentang($izin['tgl_izin'], $izin['tgl_kembali'], false); ?>
                                            <?= $bolehBukaIzin ? '<a href="' . ah_e(app_url('/portal/izin_detail.php?id=' . $izin['id'])) . '">' . ah_badge($teksIzin, 'info') . '</a>' : ah_badge($teksIzin, 'info') ?></small>
                                    <?php endif; ?>
                                </span>
                                <?= $catat === null || $catat['status'] === null ? ah_badge('Belum dicatat', 'muted') : ah_status((string) $catat['status']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
        <div class="ah-split__col">
            <section class="ah-card" aria-labelledby="ah-info-pertemuan">
                <div class="ah-card__head"><span id="ah-info-pertemuan">Pertemuan</span><?= ah_badge($status, $statusTone($status)) ?></div>
                <div class="ah-card__body">
                    <dl class="ah-kv">
                        <dt>Jadwal</dt><dd><?= ah_e(ah_hari((string) $selectedMeeting['hari']) . ', ' . substr((string) $selectedMeeting['waktu_mulai'], 0, 5) . '–' . substr((string) $selectedMeeting['waktu_selesai'], 0, 5)) ?></dd>
                        <dt>Kitab</dt><dd><?= ah_e($selectedMeeting['nama_kitab']) ?></dd>
                        <dt>Kelas</dt><dd><?= ah_e((string) ($selectedMeeting['kelas_label'] ?? $selectedMeeting['nama_kelas'])) ?></dd>
                        <dt>Dibuka</dt><dd><?= ah_e($selectedMeeting['opened_at'] ? ah_waktu((string) $selectedMeeting['opened_at']) : '—') ?></dd>
                        <dt>Selesai</dt><dd><?= ah_e($selectedMeeting['completed_at'] ? ah_waktu((string) $selectedMeeting['completed_at']) : '—') ?></dd>
                        <dt>Catatan pertemuan</dt><dd><?= ah_e($selectedMeeting['catatan'] ?: '—') ?></dd>
                    </dl>
                    <p class="mt-3 mb-0"><a href="<?= ah_e(app_url('/admin/admin_pengajian.php') . '?' . http_build_query(['tab' => 'jadwal', 'action' => 'detail', 'id' => (int) $selectedMeeting['jadwal_id']])) ?>">Lihat jadwalnya</a></p>
                </div>
            </section>
        </div>
    </div>

    <?php if ($status === 'Draf'): ?>
        <form method="post" action="<?= ah_e(app_url('/admin/admin_pengajian.php') . '?tab=pertemuan') ?>" class="ah-actbar">
            <?= ah_csrf() ?>
            <input type="hidden" name="tab" value="pertemuan">
            <input type="hidden" name="schedule_id" value="<?= (int) $selectedMeeting['jadwal_id'] ?>">
            <input type="hidden" name="tanggal_pertemuan" value="<?= ah_e($selectedMeeting['tanggal_pertemuan']) ?>">
            <input type="hidden" name="catatan" value="<?= ah_e($selectedMeeting['catatan']) ?>">
            <span class="small text-muted">Draf · peserta belum dibekukan</span>
            <button class="btn btn-primary" name="action" value="open">Buka &amp; bekukan peserta</button>
        </form>
    <?php elseif ($status === 'Dibuka'): ?>
        <form method="post" action="<?= ah_e(app_url('/admin/admin_pengajian.php') . '?tab=pertemuan') ?>" class="ah-actbar"
              data-confirm="Waktu penyelesaian tercatat dan pertemuan tidak dibuka ulang. Kehadiran yang belum dicatat tetap dapat dikoreksi di aplikasi dengan alasan."
              data-confirm-title="Selesaikan pertemuan <?= ah_e($selectedMeeting['fan_ilmu']) ?>?" data-confirm-ok="Selesaikan">
            <?= ah_csrf() ?>
            <input type="hidden" name="tab" value="pertemuan">
            <input type="hidden" name="meeting_id" value="<?= (int) $selectedMeeting['id'] ?>">
            <span class="small text-muted"><?= $jumlah ?> peserta · <?= $ringkas['Hadir'] + $ringkas['Terlambat'] ?> hadir</span>
            <button class="btn btn-primary" name="action" value="complete">Selesaikan pertemuan</button>
        </form>
    <?php endif; ?>
<?php else: ?>
    <?php ah_filter_open($meetingQuery !== '' ? 1 : 0, ['tab' => 'pertemuan']); ?>
        <div class="ah-filter__wide"><label class="form-label" for="q">Cari</label>
            <input class="form-control" type="search" id="q" name="q" maxlength="100" value="<?= ah_e($meetingQuery) ?>" placeholder="Tanggal, guru, kelas, kitab, atau status"></div>
    <?php ah_filter_close(['tab' => 'pertemuan']); ?>
    <section class="ah-card" aria-labelledby="ah-riwayat-pertemuan">
        <div class="ah-card__head"><span id="ah-riwayat-pertemuan">Daftar pertemuan</span>
            <span class="text-muted small"><?= (int) $meetingResult['total'] ?> pertemuan</span></div>
        <?php if ($meetings === []): ?>
            <div class="ah-card__body"><?= $meetingQuery !== ''
                ? ah_empty('Tidak ada hasil sesuai pencarian', 'Coba kata lain atau bersihkan pencarian untuk melihat daftar lengkap.')
                : ah_empty('Belum ada pertemuan', 'Pertemuan yang dibuka akan muncul di sini beserta status dan jumlah pesertanya.',
                    $scheduleOptions !== [] ? '<a class="btn btn-sm btn-primary" href="?' . ah_e(ah_query(['tab' => 'pertemuan', 'action' => 'create'])) . '">Buka pertemuan</a>' : null) ?></div>
        <?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table ah-table--cards">
                <caption class="ah-visually-hidden">Daftar pertemuan dalam cakupan Anda</caption>
                <thead><tr><th scope="col">Tanggal</th><th scope="col">Jadwal</th><th scope="col">Kelas</th><th scope="col" class="ah-col-secondary">Guru</th><th scope="col">Peserta</th><th scope="col">Status</th></tr></thead>
                <tbody>
                <?php foreach ($meetings as $meeting): ?>
                    <tr>
                        <td><a class="ah-row-link" href="?<?= ah_e(http_build_query(['tab' => 'pertemuan', 'id' => (int) $meeting['id']])) ?>"><?= ah_e(ah_tanggal((string) $meeting['tanggal_pertemuan'])) ?></a></td>
                        <td><?= ah_e($meeting['fan_ilmu']) ?><span class="ah-cell-sub"><?= ah_e(ah_hari((string) $meeting['hari']) . ' ' . substr((string) $meeting['waktu_mulai'], 0, 5)) ?></span></td>
                        <td><?= ah_e($meeting['kelas_label'] ?? $meeting['nama_kelas']) ?></td>
                        <td class="ah-col-secondary"><?= ah_e($meeting['nama_guru']) ?></td>
                        <td><?= (int) $meeting['participant_count'] ?></td>
                        <td><?= ah_badge((string) $meeting['status'], $statusTone((string) $meeting['status'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <?php ah_pagination((int) $meetingResult['total'], (int) $meetingResult['page'], 20); ?>
    <p class="small text-muted ah-no-print">Guru hanya melihat pertemuannya sendiri; admin melihat semua. <a href="<?= ah_e(app_url('/admin/admin_laporan_absensi.php')) ?>">Laporan kehadiran</a></p>
<?php endif; ?>

<?php ah_old_clear('_meeting_old'); ?>
