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
 * Tab "Jadwal" pada modul Pengajian (redesain website Tahap 4, J-1).
 *
 * Dimuat hanya oleh `admin/admin_pengajian.php`, yang sudah lebih dulu
 * menjalankan guard server dan menghitung `$bolehKelolaJadwal`.
 *
 * Tampilan mingguan: baris = waktu, kolom = hari, Senin sampai Ahad
 * (keputusan T; nilai "Minggu" di basis data ditampilkan "Ahad"). Blok yang
 * bentrok guru atau kelasnya ditandai. HP dan tablet memilih hari lalu
 * melihat daftar per waktu. Tampilan daftar: tabel dengan saringan guru,
 * kelas, hari, dan status. Tambah/ubah/detail di panel samping.
 *
 * Kewenangan (tidak berubah):
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
 * @var array<int, array<string, mixed>> $mingguan
 * @var array<string, mixed>|null $selected
 * @var string $mode
 * @var string $tampilan
 * @var array<int, array<string, mixed>> $years
 * @var array<int, array<string, mixed>> $teachers
 * @var array<int, array<string, mixed>> $classes
 * @var array<string, mixed>|null $activeYear
 */

$tautanPertemuan = static fn (int $jadwalId): string => app_url('/admin/admin_pengajian.php') . '?'
    . http_build_query(['tab' => 'pertemuan', 'action' => 'create', 'schedule_id' => $jadwalId]);
$tutup = '?' . ah_query(['tab' => 'jadwal', 'action' => null, 'id' => null]);
$jam = static fn (array $row): string => $row['waktu_mulai'] ? substr((string) $row['waktu_mulai'], 0, 5) . '–' . substr((string) $row['waktu_selesai'], 0, 5) : (string) $row['jam'];
$days = $service->days();

/**
 * Bentrok di antara jadwal aktif: hari sama, jam beririsan, dan guru atau
 * kelas sama. Hanya penanda tampilan; aturan simpan tetap di ScheduleService.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<int, string>>
 */
$bentrok = static function (array $rows): array {
    $aktif = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['is_active'] === 1 && empty($r['archived_at']) && $r['hari'] && $r['waktu_mulai'] && $r['waktu_selesai']));
    $hasil = [];
    foreach ($aktif as $i => $a) {
        foreach (array_slice($aktif, $i + 1) as $b) {
            if ($a['hari'] !== $b['hari'] || !($a['waktu_mulai'] < $b['waktu_selesai'] && $b['waktu_mulai'] < $a['waktu_selesai'])) {
                continue;
            }
            foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
                if ((int) $x['id_guru'] === (int) $y['id_guru']) {
                    $hasil[(int) $x['id']][] = 'Guru sama dengan jadwal #' . (int) $y['id'] . ' (' . $y['fan_ilmu'] . ')';
                }
                if ((int) $x['id_kelas'] === (int) $y['id_kelas']) {
                    $hasil[(int) $x['id']][] = 'Kelas sama dengan jadwal #' . (int) $y['id'] . ' (' . $y['fan_ilmu'] . ')';
                }
            }
        }
    }

    return $hasil;
};
$konflik = $bentrok($mingguan);

$menuJadwal = static function (array $row) use ($bolehKelolaJadwal, $tautanPertemuan): string {
    $items = [['label' => 'Detail', 'icon' => 'fa-eye', 'url' => '?' . ah_query(['tab' => 'jadwal', 'action' => 'detail', 'id' => (int) $row['id']])]];
    if ((int) $row['is_active'] === 1 && !$row['archived_at'] && ($row['tahun_status'] ?? '') === 'Aktif' && $row['hari']) {
        $items[] = ['label' => 'Buka pertemuan', 'icon' => 'fa-door-open', 'url' => $tautanPertemuan((int) $row['id'])];
    }
    if ($bolehKelolaJadwal) {
        $items[] = ['label' => 'Ubah', 'icon' => 'fa-pen', 'url' => '?' . ah_query(['tab' => 'jadwal', 'action' => 'edit', 'id' => (int) $row['id']])];
        $label = (string) $row['fan_ilmu'] . ' · ' . (string) $row['nama_kelas'];
        $items[] = ['label' => (int) $row['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan', 'icon' => 'fa-power-off',
            'action' => app_url('/admin/admin_pengajian.php') . '?' . ah_query(['tab' => 'jadwal']),
            'fields' => ['tab' => 'jadwal', 'id' => (int) $row['id'], 'action' => (int) $row['is_active'] === 1 ? 'deactivate' : 'activate'],
            'confirm' => ['title' => ((int) $row['is_active'] === 1 ? 'Nonaktifkan ' : 'Aktifkan ') . $label . '?',
                'text' => 'Jadwal nonaktif tidak dapat membuka pertemuan baru. Pertemuan, peserta, dan absensi lama tetap tersimpan.',
                'ok' => (int) $row['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan']];
        $items[] = ['label' => $row['archived_at'] ? 'Pulihkan' : 'Arsipkan', 'icon' => 'fa-box-archive', 'danger' => !$row['archived_at'],
            'action' => app_url('/admin/admin_pengajian.php') . '?' . ah_query(['tab' => 'jadwal']),
            'fields' => ['tab' => 'jadwal', 'id' => (int) $row['id'], 'action' => $row['archived_at'] ? 'restore' : 'archive'],
            'confirm' => ['title' => ($row['archived_at'] ? 'Pulihkan ' : 'Arsipkan ') . $label . '?',
                'text' => 'Arsip mengeluarkan jadwal dari daftar aktif; Pulihkan mengembalikannya. Pertemuan, peserta, dan absensi lama TIDAK dihapus dan tetap dapat dibaca di laporan.',
                'ok' => $row['archived_at'] ? 'Pulihkan' : 'Arsipkan']];
    }

    return ah_menu($items, 'Tindakan jadwal ' . (int) $row['id']);
};
?>

<?php if ($bolehKelolaJadwal && ($mode === 'create' || ($mode === 'edit' && $selected))): $record = array_replace($selected ?? ['id_tahun' => $activeYear['id'] ?? ''], $_SESSION['_ah_old'] ?? []); ?>
<?php ah_panel_open(['title' => $selected ? 'Ubah jadwal' : 'Tambah jadwal', 'close_url' => $tutup,
    'description' => $selected ? $selected['fan_ilmu'] . ' · ' . $selected['nama_kelas'] : 'Pola mingguan; tanggal pelaksanaan diatur di tab Pertemuan.']); ?>
    <?= ah_error_summary('_ah_old', 'Jadwal belum tersimpan.') ?>
    <?php if ($selected && !empty($konflik[(int) $selected['id']])): ?>
        <?php ah_note('warning', 'Jadwal ini bentrok: ' . implode('; ', array_unique($konflik[(int) $selected['id']])) . '.'); ?>
    <?php endif; ?>
    <form method="post" action="<?= ah_e(app_url('/admin/admin_pengajian.php') . '?' . ah_query(['tab' => 'jadwal'])) ?>" class="row g-3">
        <?= ah_csrf() ?>
        <input type="hidden" name="tab" value="jadwal">
        <input type="hidden" name="action" value="save">
        <?php if ($selected): ?><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><?php endif; ?>
        <div class="col-12"><label class="form-label" for="id_tahun">Semester</label>
            <select class="form-select" id="id_tahun" name="id_tahun" required>
                <option value="">Pilih semester</option>
                <?php foreach ($years as $year): ?>
                    <option value="<?= (int) $year['id'] ?>" <?= (int) ($record['id_tahun'] ?? 0) === (int) $year['id'] ? 'selected' : '' ?>><?= ah_e($year['tahun'] . ' ' . $year['semester'] . ($year['status'] === 'Aktif' ? ' — Aktif' : '')) ?></option>
                <?php endforeach; ?>
            </select><?= ah_field_error('id_tahun', '_ah_old') ?></div>
        <div class="col-6"><label class="form-label" for="hari">Hari</label>
            <select class="form-select" id="hari" name="hari" required>
                <option value="">Pilih hari</option>
                <?php foreach ($days as $day): ?><option value="<?= ah_e($day) ?>" <?= ($record['hari'] ?? '') === $day ? 'selected' : '' ?>><?= ah_e(ah_hari($day)) ?></option><?php endforeach; ?>
            </select><?= ah_field_error('hari', '_ah_old') ?></div>
        <div class="col-6"><label class="form-label" for="waktu_sholat">Waktu</label>
            <select class="form-select" id="waktu_sholat" name="waktu_sholat" required>
                <?php foreach ($service->prayerTimes() as $prayerTime): ?><option <?= ($record['waktu_sholat'] ?? "Ba'da Shubuh") === $prayerTime ? 'selected' : '' ?>><?= ah_e($prayerTime) ?></option><?php endforeach; ?>
            </select><?= ah_field_error('waktu_sholat', '_ah_old') ?></div>
        <div class="col-6"><label class="form-label" for="waktu_mulai">Mulai</label>
            <input class="form-control" id="waktu_mulai" type="time" name="waktu_mulai" required value="<?= ah_e(isset($record['waktu_mulai']) ? substr((string) $record['waktu_mulai'], 0, 5) : '') ?>"><?= ah_field_error('waktu_mulai', '_ah_old') ?></div>
        <div class="col-6"><label class="form-label" for="waktu_selesai">Selesai</label>
            <input class="form-control" id="waktu_selesai" type="time" name="waktu_selesai" required value="<?= ah_e(isset($record['waktu_selesai']) ? substr((string) $record['waktu_selesai'], 0, 5) : '') ?>"><?= ah_field_error('waktu_selesai', '_ah_old') ?></div>
        <div class="col-12"><label class="form-label" for="id_kelas">Kelas</label>
            <select class="form-select" id="id_kelas" name="id_kelas" required>
                <option value="">Pilih kelas</option>
                <?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>" <?= (int) ($record['id_kelas'] ?? 0) === (int) $class['id'] ? 'selected' : '' ?>><?= ah_e($class['nama_kelas'] . ' (' . $class['jenjang'] . ')') ?></option><?php endforeach; ?>
            </select><?= ah_field_error('id_kelas', '_ah_old') ?></div>
        <div class="col-12"><label class="form-label" for="id_guru">Guru pengampu</label>
            <select class="form-select" id="id_guru" name="id_guru" required>
                <option value="">Pilih guru</option>
                <?php foreach ($teachers as $teacher): ?><option value="<?= (int) $teacher['id'] ?>" <?= (int) ($record['id_guru'] ?? 0) === (int) $teacher['id'] ? 'selected' : '' ?>><?= ah_e($teacher['nama_guru']) ?></option><?php endforeach; ?>
            </select><?= ah_field_error('id_guru', '_ah_old') ?></div>
        <div class="col-12"><label class="form-label" for="tempat">Tempat</label>
            <input class="form-control" id="tempat" name="tempat" maxlength="100" required value="<?= ah_e($record['tempat'] ?? '') ?>"><?= ah_field_error('tempat', '_ah_old') ?></div>
        <div class="col-12"><label class="form-label" for="fan_ilmu">Fan ilmu</label>
            <input class="form-control" id="fan_ilmu" name="fan_ilmu" maxlength="100" required value="<?= ah_e($record['fan_ilmu'] ?? '') ?>"><?= ah_field_error('fan_ilmu', '_ah_old') ?></div>
        <div class="col-12"><label class="form-label" for="nama_kitab">Nama kitab</label>
            <input class="form-control" id="nama_kitab" name="nama_kitab" maxlength="100" required value="<?= ah_e($record['nama_kitab'] ?? '') ?>"><?= ah_field_error('nama_kitab', '_ah_old') ?></div>
        <?php if ($selected): ?>
            <p class="ah-fieldset__hint col-12 mb-0">Nilai jam lama: <strong><?= ah_e($selected['jam']) ?></strong> (status migrasi <?= ah_e($selected['jam_migration_status']) ?>). Nilai ini tetap disimpan untuk audit dan kompatibilitas.</p>
        <?php endif; ?>
        <p class="small text-muted col-12 mb-0">Guru yang mengajar di jam beririsan pada hari yang sama ditolak saat menyimpan; kelas atau tempat yang sama diberi peringatan.</p>
        <div class="col-12 d-flex flex-wrap gap-2 ah-sticky-sm">
            <a class="btn btn-outline-secondary" href="<?= ah_e($tutup) ?>">Batal</a>
            <button class="btn btn-primary" type="submit">Simpan jadwal</button>
        </div>
    </form>
<?php ah_panel_close(); ?>
<?php elseif ($mode === 'detail' && $selected): ?>
<?php ah_panel_open(['title' => $selected['fan_ilmu'] . ' · ' . $selected['nama_kelas'], 'close_url' => $tutup,
    'description' => ah_hari((string) ($selected['hari'] ?: 'Hari belum dilengkapi')) . ', ' . $jam($selected)]); ?>
    <?php if (!empty($konflik[(int) $selected['id']])): ?>
        <?php ah_note('warning', 'Bentrok: ' . implode('; ', array_unique($konflik[(int) $selected['id']])) . '.'); ?>
    <?php endif; ?>
    <dl class="ah-kv">
        <dt>Semester</dt><dd><?= ah_e($selected['tahun'] . ' ' . $selected['semester']) ?></dd>
        <dt>Waktu</dt><dd><?= ah_e(ah_hari((string) $selected['hari']) . ' · ' . ($selected['waktu_sholat'] ?? '') . ' · ' . $jam($selected)) ?></dd>
        <dt>Kelas</dt><dd><?= ah_e($selected['nama_kelas'] . ' (' . $selected['jenjang'] . ')') ?></dd>
        <dt>Guru</dt><dd><?= ah_e($selected['nama_guru']) ?></dd>
        <dt>Kitab</dt><dd><?= ah_e($selected['nama_kitab']) ?></dd>
        <dt>Tempat</dt><dd><?= ah_e($selected['tempat']) ?></dd>
        <dt>Status</dt><dd><?= ah_state_badge($selected) ?></dd>
        <?php if ($selected['jam_migration_status'] !== 'Berhasil'): ?><dt>Status jam lama</dt><dd><?= ah_e($selected['jam_migration_status']) ?></dd><?php endif; ?>
    </dl>
    <div class="d-flex flex-wrap gap-2 mt-3">
        <?php if ((int) $selected['is_active'] === 1 && !$selected['archived_at'] && $selected['tahun_status'] === 'Aktif' && $selected['hari']): ?>
            <a class="btn btn-primary" href="<?= ah_e($tautanPertemuan((int) $selected['id'])) ?>">Buka pertemuan</a>
        <?php endif; ?>
        <a class="btn btn-outline-primary" href="<?= ah_e(app_url('/admin/admin_pengajian.php') . '?' . http_build_query(['tab' => 'pertemuan', 'q' => $selected['fan_ilmu']])) ?>">Riwayat pertemuan</a>
        <?php if ($bolehKelolaJadwal): ?>
            <a class="btn btn-outline-secondary" href="?<?= ah_e(ah_query(['tab' => 'jadwal', 'action' => 'edit', 'id' => (int) $selected['id']])) ?>">Ubah</a>
            <?= $menuJadwal($selected) ?>
        <?php endif; ?>
    </div>
<?php ah_panel_close(); ?>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
    <?= ah_seg([
        ['label' => 'Mingguan', 'url' => '?' . ah_query(['tab' => 'jadwal', 'tampilan' => null, 'page' => null, 'action' => null, 'id' => null]), 'active' => $tampilan === 'mingguan'],
        ['label' => 'Daftar', 'url' => '?' . ah_query(['tab' => 'jadwal', 'tampilan' => 'daftar', 'action' => null, 'id' => null]), 'active' => $tampilan === 'daftar'],
    ], 'Pilih tampilan jadwal') ?>
    <?php if ($tampilan === 'mingguan'): ?>
        <form method="get" class="d-flex flex-wrap gap-2 align-items-center ah-no-print">
            <input type="hidden" name="tab" value="jadwal">
            <label class="ah-visually-hidden" for="year_id">Semester</label>
            <select class="form-select" id="year_id" name="year_id" style="width:auto">
                <?php foreach ($years as $year): ?><option value="<?= (int) $year['id'] ?>" <?= (int) $filters['year_id'] === (int) $year['id'] ? 'selected' : '' ?>><?= ah_e('Semester ' . $year['semester'] . ' ' . $year['tahun']) ?></option><?php endforeach; ?>
            </select>
            <?php if ($bolehKelolaJadwal): ?>
                <label class="ah-visually-hidden" for="teacher_id">Guru</label>
                <select class="form-select" id="teacher_id" name="teacher_id" style="width:auto"><option value="">Semua guru</option>
                    <?php foreach ($teachers as $teacher): ?><option value="<?= (int) $teacher['id'] ?>" <?= (int) $filters['teacher_id'] === (int) $teacher['id'] ? 'selected' : '' ?>><?= ah_e($teacher['nama_guru']) ?></option><?php endforeach; ?>
                </select>
            <?php endif; ?>
            <button class="btn btn-outline-secondary" type="submit">Tampilkan</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($tampilan === 'mingguan'): ?>
    <?php
    $waktuList = $service->prayerTimes();
    $grid = [];
    $tanpaPola = [];
    foreach ($mingguan as $row) {
        if (!$row['hari'] || !$row['waktu_mulai']) {
            $tanpaPola[] = $row;
            continue;
        }
        $waktu = in_array($row['waktu_sholat'], $waktuList, true) ? (string) $row['waktu_sholat'] : 'Lainnya';
        $grid[$waktu][(string) $row['hari']][] = $row;
    }
    if (isset($grid['Lainnya'])) {
        $waktuList[] = 'Lainnya';
    }
    $hariIni = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][(int) date('w')];
    $hariPilih = in_array($_GET['hari'] ?? '', $days, true) ? (string) $_GET['hari'] : $hariIni;
    $blok = static function (array $row) use ($konflik, $jam): string {
        $warn = !empty($konflik[(int) $row['id']]);
        $off = (int) $row['is_active'] !== 1;
        return '<a class="ah-block' . ($warn ? ' ah-block--warn' : '') . ($off ? ' ah-block--off' : '') . '" href="?' . ah_e(ah_query(['tab' => 'jadwal', 'action' => 'detail', 'id' => (int) $row['id']])) . '">'
            . '<b>' . ah_e($row['nama_kitab'] !== '' ? $row['nama_kitab'] : $row['fan_ilmu']) . ' · ' . ah_e($row['nama_kelas']) . '</b>'
            . '<span>' . ah_e($jam($row)) . ' · ' . ah_e($row['nama_guru']) . '</span>'
            . ($warn ? '<span>' . ah_badge('Bentrok', 'warn') . '</span>' : '') . ($off ? '<span>Nonaktif</span>' : '') . '</a>';
    };
    ?>
    <?php if ($mingguan === []): ?>
        <section class="ah-card"><div class="ah-card__body"><?= ah_empty(
            'Belum ada jadwal pada semester ini',
            $bolehKelolaJadwal ? 'Tambahkan jadwal pertama atau pilih semester lain.' : 'Belum ada jadwal mengajar untuk akun Anda pada semester ini. Hubungi admin bila jadwal Anda belum terdaftar.',
            $bolehKelolaJadwal ? '<a class="btn btn-sm btn-primary" href="?' . ah_e(ah_query(['tab' => 'jadwal', 'action' => 'create'])) . '">Tambah jadwal</a>' : null
        ) ?></div></section>
    <?php else: ?>
        <?php if ($konflik !== []): ?>
            <?php ah_note('warning', count($konflik) . ' jadwal bentrok guru atau kelasnya. Blok yang bentrok ditandai kuning; buka blok untuk melihat rinciannya.'); ?>
        <?php endif; ?>
        <div class="ah-week--grid ah-week-wrap">
            <table class="ah-week">
                <caption class="ah-visually-hidden">Jadwal pengajian mingguan, Senin sampai Ahad</caption>
                <thead><tr><th scope="col">Waktu</th>
                    <?php foreach ($days as $day): ?><th scope="col"<?= $day === $hariIni ? ' class="is-today"' : '' ?>><?= ah_e(ah_hari($day)) ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($waktuList as $waktu): ?>
                    <tr><th scope="row"><?= ah_e($waktu) ?></th>
                        <?php foreach ($days as $day): ?>
                            <td><?php foreach ($grid[$waktu][$day] ?? [] as $row) { echo $blok($row); } ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="ah-week--day">
            <nav class="ah-daypick" aria-label="Pilih hari">
                <?php foreach ($days as $day): ?>
                    <a href="?<?= ah_e(ah_query(['tab' => 'jadwal', 'hari' => $day, 'action' => null, 'id' => null])) ?>"<?= $day === $hariPilih ? ' aria-current="page"' : '' ?>><?= ah_e(mb_substr(ah_hari($day), 0, 3)) ?></a>
                <?php endforeach; ?>
            </nav>
            <h2 class="h6"><?= ah_e(ah_hari($hariPilih)) ?></h2>
            <?php $adaHari = false; foreach ($waktuList as $waktu): if (empty($grid[$waktu][$hariPilih])) { continue; } $adaHari = true; ?>
                <section class="ah-card"><div class="ah-card__head"><span><?= ah_e($waktu) ?></span></div>
                    <div class="ah-card__body"><?php foreach ($grid[$waktu][$hariPilih] as $row) { echo $blok($row); } ?></div></section>
            <?php endforeach; ?>
            <?php if (!$adaHari): ?><p class="text-muted">Tidak ada jadwal pada hari <?= ah_e(ah_hari($hariPilih)) ?>.</p><?php endif; ?>
        </div>
        <?php if ($tanpaPola !== []): ?>
            <details class="ah-fold"><summary>Jadwal tanpa hari/jam terstruktur <b><?= count($tanpaPola) ?></b></summary>
                <ul class="ah-list-plain">
                    <?php foreach ($tanpaPola as $row): ?>
                        <li><a href="?<?= ah_e(ah_query(['tab' => 'jadwal', 'action' => 'detail', 'id' => (int) $row['id']])) ?>"><?= ah_e($row['fan_ilmu'] . ' · ' . $row['nama_kelas']) ?></a><span class="ah-cell-sub"><?= ah_e('Jam lama: ' . $row['jam']) ?></span></li>
                    <?php endforeach; ?>
                </ul></details>
        <?php endif; ?>
    <?php endif; ?>
<?php else: ?>
    <?php
    $aktif = (int) ($filters['q'] !== '') + (int) ($bolehKelolaJadwal && $filters['teacher_id'] !== '') + (int) ($filters['class_id'] !== '') + (int) ($filters['day'] !== '') + (int) ($filters['state'] !== 'active');
    ah_filter_open($aktif, ['tab' => 'jadwal', 'tampilan' => 'daftar']);
    ?>
        <div class="ah-filter__wide"><label class="form-label" for="q">Pencarian</label>
            <input class="form-control" type="search" id="q" name="q" maxlength="100" value="<?= ah_e($filters['q']) ?>" placeholder="Fan, kitab, atau tempat"></div>
        <div><label class="form-label" for="year_id">Semester</label>
            <select class="form-select" id="year_id" name="year_id"><option value="">Semua</option>
                <?php foreach ($years as $year): ?><option value="<?= (int) $year['id'] ?>" <?= (int) $filters['year_id'] === (int) $year['id'] ? 'selected' : '' ?>><?= ah_e($year['tahun'] . ' ' . $year['semester']) ?></option><?php endforeach; ?>
            </select></div>
        <?php if ($bolehKelolaJadwal): ?>
            <div><label class="form-label" for="teacher_id">Guru</label>
                <select class="form-select" id="teacher_id" name="teacher_id"><option value="">Semua</option>
                    <?php foreach ($teachers as $teacher): ?><option value="<?= (int) $teacher['id'] ?>" <?= (int) $filters['teacher_id'] === (int) $teacher['id'] ? 'selected' : '' ?>><?= ah_e($teacher['nama_guru']) ?></option><?php endforeach; ?>
                </select></div>
        <?php endif; ?>
        <div><label class="form-label" for="class_id">Kelas</label>
            <select class="form-select" id="class_id" name="class_id"><option value="">Semua</option>
                <?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>" <?= (int) $filters['class_id'] === (int) $class['id'] ? 'selected' : '' ?>><?= ah_e($class['nama_kelas']) ?></option><?php endforeach; ?>
            </select></div>
        <div><label class="form-label" for="day">Hari</label>
            <select class="form-select" id="day" name="day"><option value="">Semua</option>
                <?php foreach ($days as $day): ?><option value="<?= ah_e($day) ?>" <?= $filters['day'] === $day ? 'selected' : '' ?>><?= ah_e(ah_hari($day)) ?></option><?php endforeach; ?>
            </select></div>
        <div><label class="form-label" for="state">Status</label>
            <select class="form-select" id="state" name="state">
                <?php foreach (['active' => 'Aktif', 'inactive' => 'Nonaktif', 'archived' => 'Arsip', 'all' => 'Semua'] as $value => $label): ?>
                    <option value="<?= ah_e($value) ?>" <?= $filters['state'] === $value ? 'selected' : '' ?>><?= ah_e($label) ?></option>
                <?php endforeach; ?>
            </select></div>
    <?php ah_filter_close(['tab' => 'jadwal', 'tampilan' => 'daftar']); ?>

    <section class="ah-card" aria-labelledby="ah-daftar-jadwal">
        <div class="ah-card__head"><span id="ah-daftar-jadwal">Daftar jadwal</span>
            <span class="text-muted small"><?= count($result['rows']) ?> dari <?= (int) $result['total'] ?> jadwal</span></div>
        <?php if ($result['rows'] === []): ?>
            <div class="ah-card__body"><?= ah_empty(
                'Tidak ada jadwal yang sesuai saringan',
                $bolehKelolaJadwal ? 'Ubah atau bersihkan saringan, atau tambahkan jadwal baru.' : 'Belum ada jadwal mengajar untuk akun Anda pada saringan ini.',
                $bolehKelolaJadwal ? '<a class="btn btn-sm btn-primary" href="?' . ah_e(ah_query(['tab' => 'jadwal', 'action' => 'create'])) . '">Tambah jadwal</a>' : null
            ) ?></div>
        <?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table ah-table--cards">
                <caption class="ah-visually-hidden">Daftar jadwal pengajian sesuai saringan</caption>
                <thead><tr>
                    <th scope="col">Hari &amp; waktu</th><th scope="col">Kitab · kelas</th><th scope="col">Guru</th>
                    <th scope="col" class="ah-col-secondary">Tempat</th><th scope="col">Status</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th>
                </tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $row): ?>
                    <tr>
                        <td><strong><?= ah_e($row['hari'] ? ah_hari((string) $row['hari']) : 'Hari belum diisi') ?></strong>
                            <span class="ah-cell-sub"><?= ah_e(($row['waktu_sholat'] ?? '') . ' · ' . $jam($row)) ?></span>
                            <?php if ($row['jam_migration_status'] === 'Gagal'): ?><?= ah_badge('Jam perlu ditinjau', 'warn') ?><?php endif; ?></td>
                        <td><a class="ah-row-link" href="?<?= ah_e(ah_query(['tab' => 'jadwal', 'action' => 'detail', 'id' => (int) $row['id']])) ?>"><?= ah_e($row['nama_kitab'] !== '' ? $row['nama_kitab'] : $row['fan_ilmu']) ?></a>
                            <span class="ah-cell-sub"><?= ah_e($row['fan_ilmu'] . ' · ' . $row['nama_kelas'] . ' · ' . $row['tahun'] . ' ' . $row['semester']) ?></span></td>
                        <td><?= ah_e($row['nama_guru']) ?></td>
                        <td class="ah-col-secondary"><?= ah_e($row['tempat']) ?></td>
                        <td><?= ah_state_badge($row) ?></td>
                        <td><?= $menuJadwal($row) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <?php ah_pagination((int) $result['total'], (int) $filters['page'], 20); ?>
<?php endif; ?>

<?php ah_old_clear(); ?>
