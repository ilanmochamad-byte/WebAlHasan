<?php

declare(strict_types=1);

/**
 * Tab "Jadwal" pada modul Pengajian terpadu.
 *
 * Dimuat hanya oleh `admin/admin_pengajian.php`, yang sudah lebih dulu
 * menjalankan guard server dan menghitung `$bolehKelolaJadwal`.
 *
 * Pola mingguan. Penyimpanan jadwal dan pertemuan tetap TERPISAH: berkas ini
 * tidak pernah menyentuh tabel pertemuan, ia hanya menautkan ke tab Pertemuan
 * dengan konteks jadwal terbawa.
 *
 * Kewenangan:
 *   - admin  : mengelola jadwal (tambah, ubah, nonaktifkan, arsipkan);
 *   - guru   : hanya melihat jadwal miliknya sendiri, tanpa hak pengelolaan.
 *     Pembatasan itu ditegakkan di server oleh `admin_pengajian.php`
 *     (filter guru_id dipaksa, aksi POST jadwal ditolak untuk non-admin),
 *     bukan dengan menyembunyikan tombol.
 *
 * @var array<string, mixed> $currentUser
 * @var \App\Schedule\ScheduleService $service
 * @var bool $bolehKelolaJadwal
 * @var array<string, mixed> $filters
 * @var array<string, mixed> $result
 * @var array<string, mixed>|null $selected
 * @var string $mode
 * @var array<int, array<string, mixed>> $years
 * @var array<int, array<string, mixed>> $teachers
 * @var array<int, array<string, mixed>> $classes
 * @var array<string, mixed>|null $activeYear
 */

$tautanPertemuan = static fn (int $jadwalId): string => app_url('/admin/admin_pengajian.php') . '?'
    . ah_query(['tab' => 'pertemuan', 'schedule_id' => $jadwalId, 'action' => null, 'id' => null, 'page' => null]);
?>

<?php if ($bolehKelolaJadwal && ($mode === 'create' || ($mode === 'edit' && $selected))): $record = $selected ?? ['id_tahun' => $activeYear['id'] ?? '']; ?>
<section class="ah-card" aria-labelledby="ah-form-jadwal">
    <div class="ah-card__head"><span id="ah-form-jadwal"><?= $selected ? 'Ubah jadwal #' . (int) $selected['id'] : 'Tambah jadwal' ?></span></div>
    <div class="ah-card__body">
        <form method="post" class="row g-3">
            <?= ah_csrf() ?>
            <input type="hidden" name="tab" value="jadwal">
            <input type="hidden" name="action" value="save">
            <?php if ($selected): ?><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><?php endif; ?>

            <div class="col-12">
                <fieldset class="ah-fieldset">
                    <legend>Waktu pelaksanaan</legend>
                    <p class="ah-fieldset__hint">Pola mingguan. Tanggal pelaksanaan diatur pada tab Pertemuan.</p>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label" for="id_tahun">Tahun ajaran / semester</label>
                            <select class="form-select" id="id_tahun" name="id_tahun" required>
                                <option value="">Pilih semester</option>
                                <?php foreach ($years as $year): ?>
                                    <option value="<?= (int) $year['id'] ?>" <?= (int) ($record['id_tahun'] ?? 0) === (int) $year['id'] ? 'selected' : '' ?>><?= ah_e($year['tahun'] . ' ' . $year['semester'] . ($year['status'] === 'Aktif' ? ' — Aktif' : '')) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div class="col-md-2"><label class="form-label" for="hari">Hari</label>
                            <select class="form-select" id="hari" name="hari" required>
                                <option value="">Pilih hari</option>
                                <?php foreach ($service->days() as $day): ?><option <?= ($record['hari'] ?? '') === $day ? 'selected' : '' ?>><?= ah_e($day) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-md-3"><label class="form-label" for="waktu_sholat">Waktu</label>
                            <select class="form-select" id="waktu_sholat" name="waktu_sholat" required>
                                <?php foreach ($service->prayerTimes() as $prayerTime): ?><option <?= ($record['waktu_sholat'] ?? "Ba'da Shubuh") === $prayerTime ? 'selected' : '' ?>><?= ah_e($prayerTime) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-md-3 col-6"><label class="form-label" for="waktu_mulai">Mulai</label>
                            <input class="form-control" id="waktu_mulai" type="time" name="waktu_mulai" required value="<?= ah_e(isset($record['waktu_mulai']) ? substr((string) $record['waktu_mulai'], 0, 5) : '') ?>"></div>
                        <div class="col-md-3 col-6"><label class="form-label" for="waktu_selesai">Selesai</label>
                            <input class="form-control" id="waktu_selesai" type="time" name="waktu_selesai" required value="<?= ah_e(isset($record['waktu_selesai']) ? substr((string) $record['waktu_selesai'], 0, 5) : '') ?>"></div>
                    </div>
                    <?php if ($selected): ?>
                        <p class="ah-fieldset__hint mt-3 mb-0">
                            Nilai jam lama: <strong><?= ah_e($selected['jam']) ?></strong>.
                            Status migrasi: <?= ah_e($selected['jam_migration_status']) ?>.
                            Nilai ini tetap disimpan untuk audit dan kompatibilitas.
                        </p>
                    <?php endif; ?>
                </fieldset>
            </div>

            <div class="col-12">
                <fieldset class="ah-fieldset">
                    <legend>Materi dan pengampu</legend>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label" for="id_kelas">Kelas</label>
                            <select class="form-select" id="id_kelas" name="id_kelas" required>
                                <option value="">Pilih kelas</option>
                                <?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>" <?= (int) ($record['id_kelas'] ?? 0) === (int) $class['id'] ? 'selected' : '' ?>><?= ah_e($class['nama_kelas'] . ' (' . $class['jenjang'] . ')') ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-md-4"><label class="form-label" for="id_guru">Guru pengampu</label>
                            <select class="form-select" id="id_guru" name="id_guru" required>
                                <option value="">Pilih guru</option>
                                <?php foreach ($teachers as $teacher): ?><option value="<?= (int) $teacher['id'] ?>" <?= (int) ($record['id_guru'] ?? 0) === (int) $teacher['id'] ? 'selected' : '' ?>><?= ah_e($teacher['nama_guru']) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-md-4"><label class="form-label" for="tempat">Tempat</label>
                            <input class="form-control" id="tempat" name="tempat" maxlength="100" required value="<?= ah_e($record['tempat'] ?? '') ?>"></div>
                        <div class="col-md-4"><label class="form-label" for="fan_ilmu">Fan ilmu</label>
                            <input class="form-control" id="fan_ilmu" name="fan_ilmu" maxlength="100" required value="<?= ah_e($record['fan_ilmu'] ?? '') ?>"></div>
                        <div class="col-md-5"><label class="form-label" for="nama_kitab">Nama kitab</label>
                            <input class="form-control" id="nama_kitab" name="nama_kitab" maxlength="100" required value="<?= ah_e($record['nama_kitab'] ?? '') ?>"></div>
                    </div>
                </fieldset>
            </div>

            <div class="col-12 d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Simpan jadwal</button>
                <a class="btn btn-outline-secondary" href="<?= ah_e(app_url('/admin/admin_pengajian.php?tab=jadwal')) ?>">Batal</a>
            </div>
        </form>
    </div>
</section>
<?php elseif ($mode === 'detail' && $selected): ?>
<section class="ah-card" aria-labelledby="ah-detail-jadwal">
    <div class="ah-card__head">
        <span id="ah-detail-jadwal">Detail jadwal #<?= (int) $selected['id'] ?></span>
        <span class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-primary" href="<?= ah_e($tautanPertemuan((int) $selected['id'])) ?>">Buka pertemuan jadwal ini</a>
            <?php if ($bolehKelolaJadwal): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= ah_e(ah_query(['tab' => 'jadwal', 'action' => 'edit', 'id' => (int) $selected['id']])) ?>">Ubah</a><?php endif; ?>
        </span>
    </div>
    <div class="ah-card__body row g-3">
        <div class="col-md-3"><small class="text-muted d-block">Semester</small><?= ah_e($selected['tahun'] . ' ' . $selected['semester']) ?></div>
        <div class="col-md-3"><small class="text-muted d-block">Pola waktu</small><?= ah_e(($selected['hari'] ?: 'Hari belum dilengkapi') . ', ' . ($selected['waktu_mulai'] ? substr($selected['waktu_mulai'], 0, 5) . '–' . substr($selected['waktu_selesai'], 0, 5) : $selected['jam'])) ?></div>
        <div class="col-md-3"><small class="text-muted d-block">Kelas</small><?= ah_e($selected['nama_kelas'] . ' (' . $selected['jenjang'] . ')') ?></div>
        <div class="col-md-3"><small class="text-muted d-block">Guru</small><?= ah_e($selected['nama_guru']) ?></div>
        <div class="col-md-3"><small class="text-muted d-block">Fan ilmu</small><?= ah_e($selected['fan_ilmu']) ?></div>
        <div class="col-md-3"><small class="text-muted d-block">Kitab</small><?= ah_e($selected['nama_kitab']) ?></div>
        <div class="col-md-3"><small class="text-muted d-block">Tempat</small><?= ah_e($selected['tempat']) ?></div>
        <div class="col-md-3"><small class="text-muted d-block">Status parsing jam</small><?= ah_e($selected['jam_migration_status']) ?></div>
    </div>
</section>
<?php endif; ?>

<form method="get" class="ah-card ah-no-print">
    <input type="hidden" name="tab" value="jadwal">
    <div class="ah-card__body">
        <fieldset class="ah-fieldset mb-0">
            <legend>Filter jadwal</legend>
            <div class="row g-2 align-items-end">
                <div class="col-lg-3 col-md-6"><label class="form-label" for="q">Pencarian</label>
                    <input class="form-control" id="q" name="q" value="<?= ah_e($filters['q']) ?>" placeholder="Fan, kitab, atau tempat"></div>
                <div class="col-lg-2 col-md-6"><label class="form-label" for="year_id">Semester</label>
                    <select class="form-select" id="year_id" name="year_id"><option value="">Semua</option>
                        <?php foreach ($years as $year): ?><option value="<?= (int) $year['id'] ?>" <?= (int) $filters['year_id'] === (int) $year['id'] ? 'selected' : '' ?>><?= ah_e($year['tahun'] . ' ' . $year['semester']) ?></option><?php endforeach; ?>
                    </select></div>
                <?php if ($bolehKelolaJadwal): ?>
                    <div class="col-lg-2 col-md-6"><label class="form-label" for="teacher_id">Guru</label>
                        <select class="form-select" id="teacher_id" name="teacher_id"><option value="">Semua</option>
                            <?php foreach ($teachers as $teacher): ?><option value="<?= (int) $teacher['id'] ?>" <?= (int) $filters['teacher_id'] === (int) $teacher['id'] ? 'selected' : '' ?>><?= ah_e($teacher['nama_guru']) ?></option><?php endforeach; ?>
                        </select></div>
                <?php endif; ?>
                <div class="col-lg-2 col-md-6"><label class="form-label" for="class_id">Kelas</label>
                    <select class="form-select" id="class_id" name="class_id"><option value="">Semua</option>
                        <?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>" <?= (int) $filters['class_id'] === (int) $class['id'] ? 'selected' : '' ?>><?= ah_e($class['nama_kelas']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-lg-1 col-md-6"><label class="form-label" for="day">Hari</label>
                    <select class="form-select" id="day" name="day"><option value="">Semua</option>
                        <?php foreach ($service->days() as $day): ?><option <?= $filters['day'] === $day ? 'selected' : '' ?>><?= ah_e($day) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-lg-2 col-md-6"><label class="form-label" for="state">Status</label>
                    <select class="form-select" id="state" name="state">
                        <?php foreach (['active' => 'Aktif', 'inactive' => 'Nonaktif', 'archived' => 'Arsip', 'all' => 'Semua'] as $value => $label): ?>
                            <option value="<?= ah_e($value) ?>" <?= $filters['state'] === $value ? 'selected' : '' ?>><?= ah_e($label) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-12 d-flex flex-wrap gap-2 mt-2">
                    <button class="btn btn-primary" type="submit">Terapkan filter</button>
                    <a class="btn btn-outline-secondary" href="<?= ah_e(app_url('/admin/admin_pengajian.php?tab=jadwal')) ?>">Bersihkan filter</a>
                </div>
            </div>
        </fieldset>
    </div>
</form>

<section class="ah-card" aria-labelledby="ah-daftar-jadwal">
    <div class="ah-card__head"><span id="ah-daftar-jadwal">Daftar jadwal</span>
        <span class="text-muted small"><?= count($result['rows']) ?> dari <?= (int) $result['total'] ?> jadwal</span></div>
    <?php if ($result['rows'] === []): ?>
        <div class="ah-card__body"><?= ah_empty(
            'Tidak ada jadwal yang sesuai filter',
            $bolehKelolaJadwal
                ? 'Ubah atau bersihkan filter di atas, atau tambahkan jadwal baru.'
                : 'Belum ada jadwal mengajar untuk akun Anda pada filter ini. Hubungi admin bila jadwal Anda belum terdaftar.',
            $bolehKelolaJadwal
                ? '<a class="btn btn-sm btn-primary" href="' . ah_e(app_url('/admin/admin_pengajian.php?tab=jadwal&action=create')) . '">Tambah jadwal</a>'
                : null
        ) ?></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table">
            <caption class="ah-visually-hidden">Daftar jadwal pengajian sesuai filter</caption>
            <thead><tr>
                <th scope="col">Hari &amp; waktu</th><th scope="col">Semester</th><th scope="col">Kelas</th>
                <th scope="col">Fan &amp; kitab</th><th scope="col">Guru</th><th scope="col">Tempat</th>
                <th scope="col">Status</th><th scope="col">Aksi</th>
            </tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $row): ?>
                <tr>
                    <td><strong><?= ah_e($row['hari'] ?: 'Hari belum diisi') ?></strong>
                        <span class="ah-cell-sub"><?= ah_e($row['waktu_mulai'] ? substr($row['waktu_mulai'], 0, 5) . '–' . substr($row['waktu_selesai'], 0, 5) : $row['jam']) ?></span>
                        <?php if ($row['jam_migration_status'] === 'Gagal'): ?><?= ah_badge('Jam perlu ditinjau', 'warn') ?><?php endif; ?></td>
                    <td><?= ah_e($row['tahun'] . ' ' . $row['semester']) ?><?= $row['tahun_status'] === 'Aktif' ? '<span class="ah-cell-sub">Semester aktif</span>' : '' ?></td>
                    <td><?= ah_e($row['nama_kelas']) ?><span class="ah-cell-sub"><?= ah_e($row['jenjang']) ?></span></td>
                    <td><?= ah_e($row['fan_ilmu']) ?><span class="ah-cell-sub"><?= ah_e($row['nama_kitab']) ?></span></td>
                    <td><?= ah_e($row['nama_guru']) ?></td>
                    <td><?= ah_e($row['tempat']) ?></td>
                    <td><?= ah_state_badge($row) ?></td>
                    <td><div class="ah-actions">
                        <a class="btn btn-sm btn-outline-primary" href="?<?= ah_e(ah_query(['tab' => 'jadwal', 'action' => 'detail', 'id' => (int) $row['id']])) ?>">Detail</a>
                        <?php if ((int) $row['is_active'] === 1 && !$row['archived_at'] && $row['tahun_status'] === 'Aktif' && $row['hari']): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= ah_e($tautanPertemuan((int) $row['id'])) ?>">Pertemuan</a>
                        <?php endif; ?>
                        <?php if ($bolehKelolaJadwal): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="?<?= ah_e(ah_query(['tab' => 'jadwal', 'action' => 'edit', 'id' => (int) $row['id']])) ?>">Ubah</a>
                            <form method="post"><?= ah_csrf() ?><input type="hidden" name="tab" value="jadwal"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" name="action" value="<?= (int) $row['is_active'] === 1 ? 'deactivate' : 'activate' ?>"><?= (int) $row['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
                            <form method="post" onsubmit="return confirm('Arsipkan jadwal ini? Jadwal berhenti muncul pada daftar aktif, tetapi seluruh pertemuan, peserta, dan absensi lama TIDAK dihapus dan tetap dapat dibaca pada laporan.')">
                                <?= ah_csrf() ?><input type="hidden" name="tab" value="jadwal"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" name="action" value="<?= $row['archived_at'] ? 'restore' : 'archive' ?>"><?= $row['archived_at'] ? 'Pulihkan' : 'Arsipkan' ?></button></form>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php ah_pagination((int) $result['total'], (int) $filters['page'], 20); ?>
