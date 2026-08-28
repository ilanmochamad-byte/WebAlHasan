<?php

declare(strict_types=1);

use App\Api\ApiException;
use App\Auth\Capabilities;
use App\Izin\IzinException;
use App\Izin\IzinRepository;
use App\Report\IzinReportFilter;

require_once __DIR__ . '/_ui.php';

/**
 * Laporan perizinan V2 — halaman web berbasis cakupan.
 *
 * Halaman ini TIDAK membangun query-nya sendiri. Seluruh pembacaan melewati
 * `izin_report_service()`, yang menghitung ulang cakupan dari akun yang sedang
 * masuk. Menyembunyikan kolom atau tombol di bawah bukan kontrol akses;
 * kontrol akses ada di server (PRD 5.2).
 */

$requestedMode = isset($_GET['mode']) ? (string) $_GET['mode'] : null;

try {
    $laporan = izin_report_service()->report($currentUser, $_GET, $requestedMode);
    $pilihan = izin_report_service()->options($currentUser, $_GET, $requestedMode);
} catch (ApiException | IzinException $exception) {
    http_response_code($exception->status());
    portal_header('Laporan Perizinan', $userCapabilities, $requestedMode ?? '', $currentUser);
    echo '<div class="alert alert-danger"><strong>Laporan tidak dapat ditampilkan.</strong><br>'
        . portal_e($exception->getMessage()) . '</div>';
    echo '<a class="btn btn-outline-secondary" href="' . portal_e(app_url('/portal/laporan.php')) . '">Bersihkan filter</a>';
    portal_footer();
    exit;
}

$scope = $laporan['cakupan'];
$filter = $laporan['filter'];
$ringkasan = $laporan['ringkasan'];
$durasi = $laporan['durasi'];
$adminSaja = $scope['mode'] === Capabilities::ADMIN;

/** Nilai filter aktif untuk atribut form, sudah dinormalkan server. */
$nilai = static fn (string $key): string => $filter[$key] === null ? '' : (string) $filter[$key];

/** Tautan cetak/CSV memakai kriteria yang SAMA (tanpa page/per_page). */
$tautanEkspor = static fn (string $file): string => app_url('/portal/' . $file)
    . '?' . $laporan['query'] . '&mode=' . rawurlencode((string) $scope['mode']);

portal_header('Laporan Perizinan', $userCapabilities, (string) $scope['mode'], $currentUser);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center border-bottom pb-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Laporan Perizinan</h1>
        <p class="text-muted mb-0"><?= portal_e($laporan['cakupan_label']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" target="_blank" rel="noopener"
           href="<?= portal_e($tautanEkspor('laporan_cetak.php')) ?>">Cetak / PDF</a>
        <a class="btn btn-success" href="<?= portal_e($tautanEkspor('laporan_csv.php')) ?>">Unduh CSV</a>
    </div>
</div>

<?php portal_flash_render(); ?>
<?php portal_mode_switcher($userCapabilities, (string) $scope['mode'], app_url('/portal/laporan.php')); ?>

<form method="get" class="card border-0 shadow-sm mb-4"><div class="card-body row g-3">
    <input type="hidden" name="mode" value="<?= portal_e($scope['mode']) ?>">

    <div class="col-md-2">
        <label class="form-label" for="basis_tanggal">Basis tanggal</label>
        <select class="form-select" id="basis_tanggal" name="basis_tanggal">
            <?php foreach (IzinReportFilter::BASIS_TANGGAL as $basis): ?>
                <option value="<?= portal_e($basis) ?>" <?= $filter['basis_tanggal'] === $basis ? 'selected' : '' ?>>
                    <?= portal_e(match ($basis) {
                        'pengajuan' => 'Tanggal pengajuan',
                        'keputusan' => 'Tanggal keputusan',
                        default => 'Rentang izin',
                    }) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label" for="date_from">Dari tanggal</label>
        <input class="form-control" type="date" id="date_from" name="date_from" value="<?= portal_e($nilai('date_from')) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label" for="date_to">Sampai tanggal</label>
        <input class="form-control" type="date" id="date_to" name="date_to" value="<?= portal_e($nilai('date_to')) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="">Semua status</option>
            <?php foreach (IzinRepository::STATUSES as $status): ?>
                <option value="<?= portal_e($status) ?>" <?= $filter['status'] === $status ? 'selected' : '' ?>><?= portal_e($status) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label" for="santri_id">Santri</label>
        <select class="form-select" id="santri_id" name="santri_id">
            <option value="">Semua santri</option>
            <?php foreach ($pilihan['santri'] as $item): ?>
                <option value="<?= (int) $item['id'] ?>" <?= (int) $filter['santri_id'] === (int) $item['id'] ? 'selected' : '' ?>>
                    <?= portal_e($item['nama'] . ' (' . $item['nis'] . ')') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label" for="tahun_ajaran_id">Tahun ajaran</label>
        <select class="form-select" id="tahun_ajaran_id" name="tahun_ajaran_id">
            <option value="">Semua tahun ajaran</option>
            <?php foreach ($pilihan['tahun_ajaran'] as $item): ?>
                <option value="<?= (int) $item['id'] ?>" <?= (int) $filter['tahun_ajaran_id'] === (int) $item['id'] ? 'selected' : '' ?>>
                    <?= portal_e($item['tahun'] . ' - ' . $item['semester']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php // Filter pengurus dan murobi hanya berguna bagi cakupan yang benar-benar
          // dapat melihat lebih dari satu. Server tetap menolak nilai di luar
          // cakupan dengan 403 walau kolom ini dipalsukan. ?>
    <?php if ($adminSaja || $scope['mode'] === Capabilities::ORANG_TUA): ?>
        <div class="col-md-2">
            <label class="form-label" for="pengurus_id">Pengurus</label>
            <select class="form-select" id="pengurus_id" name="pengurus_id">
                <option value="">Semua pengurus</option>
                <?php foreach ($pilihan['pengurus'] as $item): ?>
                    <option value="<?= (int) $item['id'] ?>" <?= (int) $filter['pengurus_id'] === (int) $item['id'] ? 'selected' : '' ?>>
                        <?= portal_e($item['nama']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="murobi_guru_id">Murobi</label>
            <select class="form-select" id="murobi_guru_id" name="murobi_guru_id">
                <option value="">Semua murobi</option>
                <?php foreach ($pilihan['murobi'] as $item): ?>
                    <option value="<?= (int) $item['id'] ?>" <?= (int) $filter['murobi_guru_id'] === (int) $item['id'] ? 'selected' : '' ?>>
                        <?= portal_e($item['nama']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <div class="col-md-2">
        <label class="form-label" for="kamar_id">Kamar</label>
        <select class="form-select" id="kamar_id" name="kamar_id">
            <option value="">Semua kamar</option>
            <?php foreach ($pilihan['kamar'] as $item): ?>
                <option value="<?= (int) $item['id'] ?>" <?= (int) $filter['kamar_id'] === (int) $item['id'] ? 'selected' : '' ?>>
                    <?= portal_e($item['nama']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label" for="kelas_id">Kelas</label>
        <select class="form-select" id="kelas_id" name="kelas_id">
            <option value="">Semua kelas</option>
            <?php foreach ($pilihan['kelas'] as $item): ?>
                <option value="<?= (int) $item['id'] ?>" <?= (int) $filter['kelas_id'] === (int) $item['id'] ? 'selected' : '' ?>>
                    <?= portal_e($item['nama'] . ' (' . $item['jenjang'] . ')') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label" for="durasi_min_jam">Durasi min. (jam)</label>
        <input class="form-control" type="number" min="0" id="durasi_min_jam" name="durasi_min_jam" value="<?= portal_e($nilai('durasi_min_jam')) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label" for="durasi_maks_jam">Durasi maks. (jam)</label>
        <input class="form-control" type="number" min="0" id="durasi_maks_jam" name="durasi_maks_jam" value="<?= portal_e($nilai('durasi_maks_jam')) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label" for="kanal">Kanal notifikasi</label>
        <select class="form-select" id="kanal" name="kanal">
            <option value="">Semua kanal</option>
            <?php foreach (IzinReportFilter::KANAL as $kanal): ?>
                <option value="<?= portal_e($kanal) ?>" <?= $filter['kanal'] === $kanal ? 'selected' : '' ?>><?= portal_e($kanal) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label" for="sumber">Sumber data</label>
        <select class="form-select" id="sumber" name="sumber">
            <option value="">Semua sumber</option>
            <option value="legacy" <?= $filter['sumber'] === 'legacy' ? 'selected' : '' ?>>Data warisan</option>
            <option value="v2" <?= $filter['sumber'] === 'v2' ? 'selected' : '' ?>>V2</option>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label" for="q">Pencarian</label>
        <input class="form-control" id="q" name="q" value="<?= portal_e($nilai('q')) ?>" placeholder="Nama santri, NIS, atau alasan">
    </div>
    <div class="col-md-3 d-flex align-items-end gap-2">
        <button class="btn btn-success">Terapkan filter</button>
        <a class="btn btn-outline-secondary" href="<?= portal_e(app_url('/portal/laporan.php') . '?mode=' . rawurlencode((string) $scope['mode'])) ?>">Bersihkan</a>
    </div>
</div></form>

<?php // Ringkasan dan detail di bawah berasal dari SATU filter yang sama.
      // `kriteria` adalah sidik jari filter tersebut; ia juga tercetak pada
      // halaman cetak dan dipakai pengujian untuk membuktikan konsistensi. ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Total pengajuan</div>
            <div class="h4 mb-0"><?= (int) $ringkasan['total'] ?></div>
        </div></div>
    </div>
    <?php foreach ($ringkasan['per_status'] as $status => $jumlah): ?>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="text-muted small"><?= portal_e($status) ?></div>
                <div class="h4 mb-0"><?= (int) $jumlah ?></div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Median durasi keputusan</div>
            <div class="h5 mb-1"><?= portal_e($durasi['median_label']) ?></div>
            <div class="text-muted small">Dihitung dari <?= (int) $durasi['jumlah'] ?> keputusan yang memiliki waktu pengajuan dan waktu keputusan.</div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Durasi tercepat / terlama</div>
            <div class="h6 mb-1"><?= portal_e($durasi['min_label']) ?> &middot; <?= portal_e($durasi['maks_label']) ?></div>
            <div class="text-muted small">Rata-rata: <?= portal_e($durasi['rata_label']) ?></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Data warisan dalam hasil</div>
            <div class="h5 mb-1"><?= (int) $ringkasan['legacy'] ?></div>
            <div class="text-muted small font-monospace">Kriteria: <?= portal_e(substr((string) $laporan['kriteria'], 0, 16)) ?></div>
        </div></div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th>ID</th><th>Santri</th><th>Kamar / Kelas</th><th>Rentang izin</th>
                <th>Pengurus</th><th>Murobi</th><th>Status</th><th>Keputusan</th>
                <th>Durasi</th><th>Kanal</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($laporan['items'] as $row): ?>
                <tr>
                    <td>#<?= (int) $row['id'] ?><br><span class="badge text-bg-light border"><?= portal_e($row['sumber_label']) ?></span></td>
                    <td><?= portal_e($row['nama_santri']) ?><br><span class="text-muted small"><?= portal_e($row['nis']) ?></span></td>
                    <td class="small"><?= portal_e($row['kamar_kelas_label']) ?></td>
                    <td class="small"><?= portal_e($row['tgl_izin']) ?><br>&rarr; <?= portal_e($row['tgl_kembali']) ?></td>
                    <td class="small"><?= portal_e($row['pengurus_label']) ?></td>
                    <td class="small"><?= portal_e($row['murobi_label']) ?></td>
                    <td><?= portal_status_badge((string) $row['status']) ?></td>
                    <td class="small">
                        <?= portal_e($row['keputusan_label']) ?>
                        <?php if (($row['keputusan_kapasitas'] ?? null) !== null): ?>
                            <br><span class="text-muted"><?= portal_e($row['keputusan_kapasitas']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= portal_e($row['durasi_label']) ?></td>
                    <td class="small"><?= portal_e($row['kanal_notifikasi'] ?? '-') ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary"
                           href="<?= portal_e(app_url('/portal/izin_detail.php') . '?id=' . (int) $row['id'] . '&mode=' . rawurlencode((string) $scope['mode'])) ?>">Detail</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($laporan['items'] === []): ?>
                <tr><td colspan="11" class="text-center text-muted py-5">
                    Tidak ada pengajuan yang cocok dengan filter dalam cakupan Anda.
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php portal_pagination(
    (int) $laporan['pagination']['total'],
    (int) $laporan['pagination']['current_page'],
    (int) $laporan['pagination']['per_page']
); ?>
<?php portal_footer(); ?>
