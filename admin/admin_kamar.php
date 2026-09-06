<?php

declare(strict_types=1);

use App\Database\PageQuery;
use App\MasterData\MasterDataException;

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

// A-11: data/riwayat kamar tidak dihapus, termasuk melalui URL lama.
if (isset($_GET['hapus'])) {
    http_response_code(405);
    exit('Penghapusan kamar tidak tersedia. Data dan riwayat kamar tetap dipertahankan.');
}
$service = master_data_service();
$mode = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$selected = $id === null ? null : $service->room($id);
$error = null;
$form = $selected ?? ['nama_kamar' => '', 'kapasitas' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') !== 'save' && !isset($_POST['tambah']) && !isset($_POST['edit'])) {
            throw new MasterDataException('Aksi kamar tidak dikenal.');
        }
        $id = (isset($_POST['id']) || isset($_POST['edit']))
            ? filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
        if ($id === false) { throw new MasterDataException('ID kamar tidak valid.'); }
        $saved = $service->saveRoom($_POST, $id);
        master_flash('success', 'Kamar berhasil disimpan. Penempatan santri dan riwayatnya tidak berubah.');
        master_redirect('admin_kamar.php?action=detail&id=' . $saved);
    } catch (MasterDataException $exception) {
        http_response_code(422);
        $error = $exception->getMessage();
        ah_validation_keep($_POST, ['nama_kamar','kapasitas'], $exception, '_room_error');
        $form = [
            'nama_kamar' => is_scalar($_POST['nama_kamar'] ?? null) ? (string) $_POST['nama_kamar'] : '',
            'kapasitas' => is_scalar($_POST['kapasitas'] ?? null) ? (string) $_POST['kapasitas'] : '',
        ];
        $mode = $id ? 'edit' : 'create';
    }
}
$year = $koneksi->query("SELECT id, tahun, semester FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1")->fetch_assoc();
$yearId = $year ? (int) $year['id'] : 0;
$q = PageQuery::term($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$isDetail = $mode === 'detail' && $selected !== null;
if (in_array($mode, ['edit', 'detail'], true) && $selected === null && $error === null) {
    http_response_code(404);
    $error = 'Kamar tidak ditemukan. Kembali ke daftar untuk memilih kamar yang tersedia.';
}
$result = $isDetail ? $service->roomOccupantsPage((int) $selected['id'], $yearId, $q, $page) : $service->roomsPage($q, $page, $yearId);
$page = (int) $result['page'];
master_header('Data Kamar Santri', [
    'description' => 'Kelola nama dan kapasitas kamar. Data penghuni mengikuti penempatan pada semester aktif.',
    'active' => 'master.kamar',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Master Data'], ['label' => 'Kamar'],
    ],
    'actions' => '<a class="btn btn-outline-primary" href="admin_penempatan_santri.php">Penempatan kelas &amp; kamar</a>'
        . '<a class="btn btn-success" href="admin_kamar.php?action=create">Tambah Kamar</a>',
]);
if ($error !== null) { ah_note('danger', $error); }
ah_note('info', 'Semester aktif: ' . ($year ? $year['tahun'] . ' ' . $year['semester'] : 'belum diatur') . '. Penghapusan kamar tidak tersedia agar data dan riwayat tetap utuh.');
?>
<?php if ($mode === 'create' || ($mode === 'edit' && $selected !== null)): ?>
<section class="ah-card" aria-labelledby="room-form-title">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="room-form-title"><?= $id ? 'Ubah Kamar' : 'Tambah Kamar' ?></h2></div>
    <div class="ah-card__body">
        <form method="post" class="row g-3" action="admin_kamar.php<?= $id ? '?action=edit&amp;id=' . (int) $id : '?action=create' ?>">
            <?= master_csrf() ?><input type="hidden" name="action" value="save">
            <?php if ($id): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>
            <div class="col-md-8"><label class="form-label" for="nama_kamar">Nama Kamar / Kobong</label>
                <input class="form-control" id="nama_kamar" name="nama_kamar" maxlength="50" required value="<?= master_e($form['nama_kamar']) ?>"><?= ah_field_error('nama_kamar','_room_error') ?></div>
            <div class="col-md-4"><label class="form-label" for="kapasitas">Kapasitas (orang)</label>
                <input class="form-control" id="kapasitas" name="kapasitas" type="number" min="1" max="2147483647" required value="<?= master_e($form['kapasitas']) ?>"><?= ah_field_error('kapasitas','_room_error') ?></div>
            <p class="small text-muted mb-0">Mengubah kapasitas tidak memindahkan atau mengeluarkan penghuni. Perubahan dicatat dalam audit.</p>
            <div class="ah-actions"><button class="btn btn-success" type="submit">Simpan Kamar</button><a class="btn btn-outline-secondary" href="admin_kamar.php">Batal</a></div>
        </form>
    </div>
</section>
<?php endif; ?>
<?php if ($isDetail): ?>
<section class="ah-card" aria-labelledby="room-detail-title">
    <div class="ah-card__head flex-wrap gap-2"><h2 class="h6 mb-0" id="room-detail-title">Penghuni: <?= master_e($selected['nama_kamar']) ?></h2>
        <div class="ah-actions"><a class="btn btn-sm btn-primary" href="admin_penempatan_santri.php?kamar_id=<?= (int) $selected['id'] ?>">Kelola penempatan kamar ini</a><a class="btn btn-sm btn-outline-primary" href="admin_penempatan_santri.php?status=tanpa_kamar">Santri belum berkamar</a><a class="btn btn-sm btn-outline-secondary" href="?action=edit&amp;id=<?= (int) $selected['id'] ?>">Ubah kamar</a><a class="btn btn-sm btn-outline-secondary" href="admin_kamar.php">Daftar kamar</a></div></div>
    <div class="ah-card__body"><p>Kapasitas maksimal <?= (int) $selected['kapasitas'] ?> orang. Daftar berikut mengikuti semester aktif dan pencarian.</p></div>
</section>
<?php ah_list_search($q, 'Cari NIS, nama santri, atau sekolah', ['action' => 'detail', 'id' => (int) $selected['id']]); ?>
<div class="ah-card ah-table-wrap"><table class="ah-table" id="room-occupants">
    <caption class="ah-visually-hidden">Penghuni kamar pada semester aktif sesuai pencarian</caption>
    <thead><tr><th scope="col">No</th><th scope="col">NIS</th><th scope="col">Nama santri</th><th scope="col">L/P</th><th scope="col">Sekolah</th></tr></thead>
    <tbody><?php foreach ($result['rows'] as $index => $row): ?><tr>
        <td><?= ($page - 1) * 20 + $index + 1 ?></td><td><?= master_e($row['nis']) ?></td><td><?= master_e($row['nama_santri']) ?></td><td><?= master_e($row['jenis_kelamin']) ?></td><td><?= master_e($row['sekolah_saat_ini']) ?></td>
    </tr><?php endforeach; ?>
    <?php if ($result['rows'] === []): ?><tr><td colspan="5"><?= ah_empty('Tidak ada penghuni sesuai pencarian', $year ? 'Kamar kosong atau tidak ada penghuni yang cocok. Coba bersihkan pencarian.' : 'Atur semester aktif untuk melihat penempatan santri.') ?></td></tr><?php endif; ?>
    </tbody>
</table></div>
<?php else: ?>
<?php ah_list_search($q, 'Cari nama kamar'); ?>
<div class="ah-card ah-table-wrap"><table class="ah-table" id="room-list">
    <caption class="ah-visually-hidden">Daftar kamar dan kapasitas pada semester aktif</caption>
    <thead><tr><th scope="col">No</th><th scope="col">Nama Kamar / Kobong</th><th scope="col">Terisi / kapasitas</th><th scope="col">Status kapasitas</th><th scope="col">Aksi</th></tr></thead>
    <tbody><?php foreach ($result['rows'] as $index => $row): ?><tr>
        <td><?= ($page - 1) * 20 + $index + 1 ?></td><td><?= master_e($row['nama_kamar']) ?></td><td><?= (int) $row['terisi'] ?> / <?= (int) $row['kapasitas'] ?></td>
        <td><?= ah_badge((int) $row['terisi'] >= (int) $row['kapasitas'] ? 'Penuh' : 'Tersedia', (int) $row['terisi'] >= (int) $row['kapasitas'] ? 'warn' : 'ok') ?></td>
        <td><div class="ah-actions"><a class="btn btn-sm btn-outline-primary" href="?action=detail&amp;id=<?= (int) $row['id'] ?>">Penghuni</a><a class="btn btn-sm btn-outline-primary" href="admin_penempatan_santri.php?kamar_id=<?= (int) $row['id'] ?>">Penempatan</a><a class="btn btn-sm btn-outline-secondary" href="?action=edit&amp;id=<?= (int) $row['id'] ?>">Ubah</a></div></td>
    </tr><?php endforeach; ?>
    <?php if ($result['rows'] === []): ?><tr><td colspan="5"><?= ah_empty('Tidak ada kamar sesuai pencarian', 'Bersihkan pencarian atau tambahkan kamar melalui tombol Tambah Kamar.') ?></td></tr><?php endif; ?>
    </tbody>
</table></div>
<?php endif; ?>
<?php master_pagination((int) $result['total'], $page, 20); ah_old_clear('_room_error'); master_footer(); ?>
