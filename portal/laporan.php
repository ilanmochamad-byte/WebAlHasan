<?php

declare(strict_types=1);

use App\Api\ApiException;
use App\Auth\Capabilities;
use App\Izin\IzinException;
use App\Izin\IzinRepository;
use App\Report\IzinReportFilter;
use App\Ui\Chart;
use App\Ui\Periode;

require_once __DIR__ . '/_ui.php';

/**
 * Laporan perizinan V2 — halaman web berbasis cakupan (redesain Tahap 4, I-6).
 *
 * Empat saringan utama (Periode, Status, Kelas, Kamar); sembilan lainnya di
 * Filter lanjutan. Periode berupa pilihan cepat yang diterjemahkan menjadi
 * tanggal SEBELUM masukan diteruskan ke layanan laporan, sehingga ringkasan,
 * grafik, detail, cetak, dan CSV tetap memakai satu filter yang sama.
 *
 * Halaman ini TIDAK membangun query-nya sendiri. Seluruh pembacaan melewati
 * `izin_report_service()`, yang menghitung ulang cakupan dari akun yang sedang
 * masuk. Menyembunyikan kolom atau tombol di bawah bukan kontrol akses;
 * kontrol akses ada di server (PRD 5.2).
 */

$requestedMode = portal_requested_mode($currentUser, $userCapabilities);
$semesterAktif = izin_repository()->activeYear();
[$masukan, $periode] = Periode::terapkan($_GET, $semesterAktif, 'bulan');

try {
    $laporan = izin_report_service()->report($currentUser, $masukan, $requestedMode);
    $pilihan = izin_report_service()->options($currentUser, $masukan, $requestedMode);
    $grafik = izin_report_service()->charts($currentUser, $masukan, $requestedMode);
} catch (ApiException | IzinException $exception) {
    http_response_code($exception->status());
    portal_header('Laporan perizinan', $userCapabilities, $requestedMode ?? '', $currentUser, ['active' => 'izin']);
    ah_note('danger', 'Laporan tidak dapat ditampilkan. ' . $exception->getMessage(),
        '<p class="mb-0 mt-2"><a href="' . portal_e(app_url('/portal/laporan.php')) . '">Bersihkan filter</a> lalu coba lagi.</p>');
    portal_footer();
    exit;
}

$scope = $laporan['cakupan'];
$mode = (string) $scope['mode'];
$filter = $laporan['filter'];
$ringkasan = $laporan['ringkasan'];
$durasi = $laporan['durasi'];
$bolehPelaku = $mode === Capabilities::ADMIN || $mode === Capabilities::ORANG_TUA;

/** Nilai filter aktif untuk atribut form, sudah dinormalkan server. */
$nilai = static fn (string $key): string => $filter[$key] === null ? '' : (string) $filter[$key];
$pilih = static fn (string $key, mixed $value): string => (string) $filter[$key] === (string) $value ? ' selected' : '';

/** Tautan cetak/CSV memakai kriteria yang SAMA (tanpa page/per_page). */
$tautanEkspor = static fn (string $file): string => app_url('/portal/' . $file)
    . '?' . $laporan['query'] . '&mode=' . rawurlencode($mode);

portal_header('Laporan perizinan', $userCapabilities, $mode, $currentUser, [
    'active' => $mode === Capabilities::ORANG_TUA ? 'izin_anak' : ($mode === Capabilities::MUROBI ? 'antrean' : 'izin'),
    'description' => $laporan['cakupan_label'] . ' · ' . ah_rentang((string) $filter['date_from'], (string) $filter['date_to'], false),
    'actions' => '<a class="btn btn-outline-primary" href="' . portal_e($tautanEkspor('laporan_csv.php')) . '"><i class="fas fa-file-csv" aria-hidden="true"></i> Unduh CSV</a>'
        . '<a class="btn btn-primary" target="_blank" rel="noopener" href="' . portal_e($tautanEkspor('laporan_cetak.php')) . '"><i class="fas fa-print" aria-hidden="true"></i> Cetak / PDF</a>'
        // PRD V7 Fase 3: laporan lintas asal wali/pengurus dan tahap pembimbing.
        . '<a class="btn btn-outline-secondary" href="' . portal_e(app_url('/portal/izin_wali_laporan.php')) . '">Laporan asal &amp; tahap</a>',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Perizinan', 'url' => app_url('/portal/izin_ringkasan.php')],
        ['label' => 'Laporan'],
    ],
]);
portal_flash_render();
portal_mode_switcher($userCapabilities, $mode, app_url('/portal/laporan.php'));

$lanjutan = 0;
foreach (['santri_id', 'pengurus_id', 'murobi_guru_id', 'durasi_min_jam', 'durasi_maks_jam', 'kanal', 'sumber', 'tahun_ajaran_id', 'q'] as $kunci) {
    $lanjutan += (int) ($nilai($kunci) !== '');
}
$lanjutan += (int) ($filter['basis_tanggal'] !== 'izin') + (int) ($periode === 'rentang');
$utama = (int) ($filter['status'] !== null) + (int) ($filter['kelas_id'] !== null) + (int) ($filter['kamar_id'] !== null) + (int) ($periode !== 'bulan');
ah_filter_open($utama + $lanjutan, ['mode' => $mode]);
?>
    <div><label class="form-label" for="periode">Periode</label>
        <select class="form-select" id="periode" name="periode">
            <?php foreach (Periode::PILIHAN as $kode => $label): ?>
                <option value="<?= portal_e($kode) ?>"<?= $periode === $kode ? ' selected' : '' ?>><?= portal_e($label) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status"><option value="">Semua status</option>
            <?php foreach (IzinRepository::STATUSES as $status): ?>
                <option value="<?= portal_e($status) ?>"<?= $pilih('status', $status) ?>><?= portal_e($status === 'Perlu Penetapan Admin' ? 'Perlu penetapan' : $status) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="kelas_id">Kelas</label>
        <select class="form-select" id="kelas_id" name="kelas_id"><option value="">Semua kelas</option>
            <?php foreach ($pilihan['kelas'] as $item): ?>
                <option value="<?= (int) $item['id'] ?>"<?= $pilih('kelas_id', $item['id']) ?>><?= portal_e($item['nama'] . ' (' . $item['jenjang'] . ')') ?></option>
            <?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="kamar_id">Kamar</label>
        <select class="form-select" id="kamar_id" name="kamar_id"><option value="">Semua kamar</option>
            <?php foreach ($pilihan['kamar'] as $item): ?>
                <option value="<?= (int) $item['id'] ?>"<?= $pilih('kamar_id', $item['id']) ?>><?= portal_e($item['nama']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <details class="ah-filter__more"<?= $lanjutan > 0 ? ' open' : '' ?>>
        <summary>Filter lanjutan<?= $lanjutan > 0 ? ' (' . $lanjutan . ')' : '' ?></summary>
        <div class="ah-filter__more-body">
            <div><label class="form-label" for="date_from">Dari tanggal</label>
                <input class="form-control" type="date" id="date_from" name="date_from" value="<?= portal_e($nilai('date_from')) ?>" aria-describedby="rentang-bantu"></div>
            <div><label class="form-label" for="date_to">Sampai tanggal</label>
                <input class="form-control" type="date" id="date_to" name="date_to" value="<?= portal_e($nilai('date_to')) ?>" aria-describedby="rentang-bantu"></div>
            <p class="small text-muted mb-0 ah-filter__note" id="rentang-bantu">Tanggal dipakai bila Periode = Rentang sendiri.</p>
            <div><label class="form-label" for="basis_tanggal">Basis tanggal</label>
                <select class="form-select" id="basis_tanggal" name="basis_tanggal">
                    <?php foreach (IzinReportFilter::BASIS_TANGGAL as $basis): ?>
                        <option value="<?= portal_e($basis) ?>"<?= $pilih('basis_tanggal', $basis) ?>><?= portal_e(match ($basis) { 'pengajuan' => 'Tanggal pengajuan', 'keputusan' => 'Tanggal keputusan', default => 'Rentang izin' }) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div><label class="form-label" for="santri_id">Santri</label>
                <select class="form-select" id="santri_id" name="santri_id"><option value="">Semua santri</option>
                    <?php foreach ($pilihan['santri'] as $item): ?>
                        <option value="<?= (int) $item['id'] ?>"<?= $pilih('santri_id', $item['id']) ?>><?= portal_e($item['nama'] . ' (' . $item['nis'] . ')') ?></option>
                    <?php endforeach; ?>
                </select></div>
            <?php // Filter pengurus dan murobi hanya berguna bagi cakupan yang dapat melihat
                  // lebih dari satu. Server tetap menolak nilai di luar cakupan dengan 403. ?>
            <?php if ($bolehPelaku): ?>
                <div><label class="form-label" for="pengurus_id">Pengurus</label>
                    <select class="form-select" id="pengurus_id" name="pengurus_id"><option value="">Semua pengurus</option>
                        <?php foreach ($pilihan['pengurus'] as $item): ?>
                            <option value="<?= (int) $item['id'] ?>"<?= $pilih('pengurus_id', $item['id']) ?>><?= portal_e($item['nama']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div><label class="form-label" for="murobi_guru_id">Murobi</label>
                    <select class="form-select" id="murobi_guru_id" name="murobi_guru_id"><option value="">Semua murobi</option>
                        <?php foreach ($pilihan['murobi'] as $item): ?>
                            <option value="<?= (int) $item['id'] ?>"<?= $pilih('murobi_guru_id', $item['id']) ?>><?= portal_e($item['nama']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
            <?php endif; ?>
            <div><label class="form-label" for="durasi_min_jam">Durasi putus min. (jam)</label>
                <input class="form-control" type="number" min="0" id="durasi_min_jam" name="durasi_min_jam" value="<?= portal_e($nilai('durasi_min_jam')) ?>"></div>
            <div><label class="form-label" for="durasi_maks_jam">Durasi putus maks. (jam)</label>
                <input class="form-control" type="number" min="0" id="durasi_maks_jam" name="durasi_maks_jam" value="<?= portal_e($nilai('durasi_maks_jam')) ?>"></div>
            <div><label class="form-label" for="kanal">Kanal notifikasi</label>
                <select class="form-select" id="kanal" name="kanal"><option value="">Semua kanal</option>
                    <?php foreach (IzinReportFilter::KANAL as $kanal): ?>
                        <option value="<?= portal_e($kanal) ?>"<?= $pilih('kanal', $kanal) ?>><?= portal_e($kanal) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div><label class="form-label" for="sumber">Asal data</label>
                <select class="form-select" id="sumber" name="sumber"><option value="">Semua</option>
                    <option value="v2"<?= $pilih('sumber', 'v2') ?>>Sistem baru</option>
                    <option value="legacy"<?= $pilih('sumber', 'legacy') ?>>Arsip sistem lama</option>
                </select></div>
            <div><label class="form-label" for="tahun_ajaran_id">Tahun ajaran</label>
                <select class="form-select" id="tahun_ajaran_id" name="tahun_ajaran_id"><option value="">Semua tahun ajaran</option>
                    <?php foreach ($pilihan['tahun_ajaran'] as $item): ?>
                        <option value="<?= (int) $item['id'] ?>"<?= $pilih('tahun_ajaran_id', $item['id']) ?>><?= portal_e($item['tahun'] . ' ' . $item['semester']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="ah-filter__wide"><label class="form-label" for="q">Cari</label>
                <input class="form-control" type="search" id="q" name="q" maxlength="100" value="<?= portal_e($nilai('q')) ?>" placeholder="Nama santri, NIS, atau alasan"></div>
        </div>
    </details>
<?php ah_filter_close(['mode' => $mode]); ?>

<?= ah_stats([
    ['label' => 'Total', 'value' => (string) (int) $ringkasan['total'], 'hint' => Periode::label($periode)],
    ['label' => 'Disetujui', 'value' => (string) (int) $ringkasan['per_status']['Disetujui']],
    ['label' => 'Ditolak', 'value' => (string) (int) $ringkasan['per_status']['Ditolak']],
    ['label' => 'Median waktu putus', 'value' => (string) $durasi['median_label'], 'hint' => 'dari ' . (int) $durasi['jumlah'] . ' keputusan'],
    ['label' => 'Terlama', 'value' => (string) $durasi['maks_label']],
], 'Ringkasan laporan perizinan') ?>

<?php
$pekan = [];
foreach ($grafik['per_pekan'] as $senin => $jumlah) {
    $pekan['Pekan ' . ($senin !== '' ? ah_tanggal($senin, false, false) : '—')] = $jumlah;
}
?>
<div class="ah-split ah-split--even">
    <section class="ah-card"><div class="ah-card__body"><?= Chart::bar('Pengajuan per pekan', $pekan, 'Tidak ada pengajuan pada periode ini.') ?></div></section>
    <section class="ah-card"><div class="ah-card__body"><?= Chart::bar('Pengajuan per kelas', $grafik['per_kelas'], 'Tidak ada pengajuan pada periode ini.') ?></div></section>
</div>

<section class="ah-card" aria-labelledby="ah-laporan-rincian">
    <div class="ah-card__head"><span id="ah-laporan-rincian">Rincian pengajuan</span>
        <span class="text-muted small"><?= count($laporan['items']) ?> dari <?= (int) $ringkasan['total'] ?></span></div>
    <?php if ($laporan['items'] === []): ?>
        <div class="ah-card__body"><?= ah_empty('Tidak ada pengajuan sesuai saringan', 'Ubah periode atau saringan lain di atas.') ?></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards">
            <caption class="ah-visually-hidden">Rincian pengajuan izin sesuai saringan laporan</caption>
            <thead><tr>
                <th scope="col">#</th><th scope="col">Santri</th><th scope="col" class="ah-col-secondary">Kamar / kelas</th>
                <th scope="col">Rentang</th><th scope="col">Murobi</th><th scope="col">Status</th><th scope="col" class="ah-col-secondary">Durasi putus</th>
            </tr></thead>
            <tbody>
            <?php foreach ($laporan['items'] as $row): ?>
                <tr>
                    <td><a class="ah-row-link" href="<?= portal_e(portal_detail_url((int) $row['id'], $mode)) ?>">#<?= (int) $row['id'] ?></a>
                        <?php if (!empty($row['is_legacy'])): ?><span class="ah-cell-sub">Arsip sistem lama</span><?php endif; ?></td>
                    <td><?= portal_e($row['nama_santri']) ?><span class="ah-cell-sub"><?= portal_e($row['nis']) ?></span></td>
                    <td class="ah-col-secondary"><?= portal_e($row['kamar_kelas_label']) ?></td>
                    <td><?= portal_e(ah_rentang((string) $row['tgl_izin'], (string) $row['tgl_kembali'], false)) ?></td>
                    <td><?= portal_e(portal_label((string) $row['murobi_label'])) ?></td>
                    <td><?= portal_status((string) $row['status']) ?></td>
                    <td class="ah-col-secondary"><?= portal_e($row['durasi_label']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php portal_pagination(
    (int) $laporan['pagination']['total'],
    (int) $laporan['pagination']['current_page'],
    (int) $laporan['pagination']['per_page']
); ?>
<p class="small text-muted ah-no-print">Cetak dan CSV memakai saringan yang sama; ringkasan saringan dan kode laporan <span class="font-monospace"><?= portal_e(strtoupper(substr((string) $laporan['kriteria'], 0, 10))) ?></span> tercetak di dokumen.</p>
<?php portal_footer(); ?>
