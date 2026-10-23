<?php

declare(strict_types=1);

/**
 * Data sistem lama: pelanggaran (redesain website Tahap 3, N-5).
 *
 * Data warisan tetap baca-saja sesuai PRD V3 §5.2a: pencatatan dan
 * penghapusan lama sudah ditutup (405). Tahap 3 hanya menambah pencarian
 * nama santri, saringan tahun, dan tampilan kartu di HP.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || isset($_GET['hapus'])) {
    http_response_code(405);
    header('Allow: GET');
    master_header('Data sistem lama');
    ah_note('warning', 'Data warisan hanya dapat dibaca. Pencatatan dan penghapusan lama telah ditutup.');
    master_footer();
    exit;
}
$q = \App\Database\PageQuery::term($_GET['q'] ?? '');
$tahun = filter_var($_GET['tahun'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1900, 'max_range' => 2100]]) ?: 0;
$error = null;
try {
    $page = v3_katalog_service()->legacy($currentUser, (int) ($_GET['page'] ?? 1), $q, $tahun);
} catch (\App\V3\V3Exception $e) {
    http_response_code($e->status);
    $error = $e->getMessage();
    $page = ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 25, 'years' => []];
}
master_header('Data sistem lama', [
    'active' => 'arsip',
    'description' => 'Data warisan pelanggaran dari sistem lama. Hanya dapat dibaca; pelaku, katalog, dan tahun ajaran tidak diasumsikan.',
    'breadcrumbs' => [['label' => 'Pengaturan'], ['label' => 'Data sistem lama']],
]);
if ($error !== null) {
    ah_note('danger', $error);
}
ah_note('info', 'Pelanggaran baru dicatat di Pembinaan › Pelanggaran.', ' <a href="' . ah_e(app_url('/portal/v3_pelanggaran.php')) . '">Buka Pembinaan</a>');
ah_filter_open(($q !== '' ? 1 : 0) + ($tahun > 0 ? 1 : 0));
?>
    <div class="ah-filter__wide"><label class="form-label" for="arsip-q">Cari santri</label>
        <input class="form-control" type="search" id="arsip-q" name="q" maxlength="100" value="<?= ah_e($q) ?>" placeholder="Nama santri"></div>
    <div><label class="form-label" for="arsip-tahun">Tahun kejadian</label>
        <select class="form-select" id="arsip-tahun" name="tahun">
            <option value="">Semua tahun</option>
            <?php foreach ($page['years'] as $year): ?>
                <option value="<?= (int) $year ?>"<?= $year === $tahun ? ' selected' : '' ?>><?= (int) $year ?></option>
            <?php endforeach; ?>
        </select></div>
<?php ah_filter_close(); ?>
<div class="ah-card ah-table-wrap">
    <table class="ah-table ah-table--cards" id="arsip-pelanggaran">
        <caption class="ah-visually-hidden">Data warisan pelanggaran, <?= (int) $page['total'] ?> catatan</caption>
        <thead><tr><th scope="col">Santri</th><th scope="col">Tanggal</th><th scope="col">Jenis lama</th><th scope="col">Poin lama</th><th scope="col" class="ah-col-secondary">Catatan hukuman lama</th></tr></thead>
        <tbody>
        <?php foreach ($page['rows'] as $row): ?>
            <tr>
                <td><?= ah_e($row['nama_santri'] ?? 'Referensi santri tidak tersedia') ?></td>
                <td><?= ah_e($row['tgl_pelanggaran']) ?></td>
                <td><?= ah_e($row['jenis_pelanggaran']) ?></td>
                <td><?= ah_e($row['poin']) ?></td>
                <td class="ah-col-secondary"><?= ah_e($row['hukuman'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($page['rows'] === []): ?>
            <tr><td colspan="5" data-label=""><?= ah_empty($q !== '' || $tahun > 0 ? 'Tidak ada catatan yang cocok' : 'Belum ada data warisan', $q !== '' || $tahun > 0 ? 'Bersihkan saringan untuk melihat seluruh catatan lama.' : 'Sistem lama tidak meninggalkan catatan pelanggaran.') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php master_pagination((int) $page['total'], (int) $page['page'], (int) $page['per_page']); master_footer(); ?>
