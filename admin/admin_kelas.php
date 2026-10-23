<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;

/**
 * Data pesantren › Kelas & kobong › tab Kelas (redesain website Tahap 6, M-2).
 *
 * Nama kelas, jenjang, jumlah anggota semester aktif, dan status
 * Aktif/Nonaktif/Arsip. "Atur anggota" membuka Data santri › Penempatan
 * tersaring kelas itu; kartu "Tempatkan santri" ganda di halaman ini dihapus.
 *
 * Kompatibilitas: POST `action=assign` lama tetap diterima (jalur
 * `MasterDataService::assignActiveClass()`), tetapi tidak lagi ditampilkan.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = master_data_service();
$halaman = 'admin_kelas.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($action === 'save') {
            $savedId = $service->saveClass($_POST, $id ?: null);
            ah_old_clear('_kelas_old');
            master_flash('success', 'Kelas ' . ($service->class($savedId)['nama_kelas'] ?? '') . ' tersimpan.');
        } elseif ($action === 'assign') {
            $service->assignActiveClass($_POST, (int) $currentUser['id']);
            master_flash('success', 'Santri ditempatkan ke kelas pada semester aktif; riwayat lama tetap tersimpan.');
        } else {
            $service->setClassState($id, $action);
            master_flash('success', 'Status kelas ' . ($service->class($id)['nama_kelas'] ?? '') . ' diperbarui. Riwayat keanggotaan dan absensi lama tetap tersimpan.');
        }
    } catch (MasterDataException $exception) {
        master_flash('danger', $exception->getMessage());
        if ($action === 'save') {
            ah_validation_keep($_POST, ['nama_kelas', 'jenjang'], $exception, '_kelas_old');
            master_redirect($halaman . '?action=' . ($id ? 'edit&id=' . $id : 'create'));
        }
    }
    master_redirect($halaman);
}

$q = App\Database\PageQuery::term($_GET['q'] ?? '');
$state = in_array($_GET['state'] ?? 'active', ['active', 'inactive', 'archived', 'all'], true) ? (string) ($_GET['state'] ?? 'active') : 'active';
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = $service->classesPage($q, $page, $state);
$jumlah = $service->classStateCounts();
$selected = isset($_GET['id']) ? $service->class((int) $_GET['id']) : null;
$mode = (string) ($_GET['action'] ?? '');
$year = $service->years();
$aktif = null;
foreach ($year as $t) {
    if ($t['status'] === 'Aktif' && !$t['archived_at']) { $aktif = $t; break; }
}
$tutup = $halaman . (($qs = ah_query(['action' => null, 'id' => null])) === '' ? '' : '?' . $qs);

master_header('Kelas & kobong', [
    'active' => 'kelas',
    'description' => 'Tempat santri belajar dan tinggal pada semester aktif' . ($aktif ? ' (' . $aktif['semester'] . ' ' . $aktif['tahun'] . ')' : '') . '.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Data pesantren'], ['label' => 'Kelas & kobong']],
    'actions' => '<a class="btn btn-primary" href="?action=create"><i class="fas fa-plus" aria-hidden="true"></i> Tambah kelas</a>',
]);

if ($mode === 'create' || ($mode === 'edit' && $selected)):
    $r = array_replace($selected ?? [], is_array($_SESSION['_kelas_old'] ?? null) ? $_SESSION['_kelas_old'] : []);
    ah_panel_open(['title' => $selected ? 'Ubah ' . $selected['nama_kelas'] : 'Tambah kelas', 'close_url' => $tutup]);
    echo ah_error_summary('_kelas_old'); ?>
    <form method="post">
        <?= master_csrf() ?><input type="hidden" name="action" value="save">
        <?php if ($selected): ?><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><?php endif; ?>
        <div class="mb-3"><label class="form-label" for="nama_kelas">Nama kelas <span class="text-danger" aria-hidden="true">*</span></label>
            <input class="form-control" id="nama_kelas" name="nama_kelas" maxlength="50" required value="<?= master_e($r['nama_kelas'] ?? '') ?>"><?= ah_field_error('nama_kelas', '_kelas_old') ?></div>
        <div class="mb-3"><label class="form-label" for="jenjang">Jenjang <span class="text-danger" aria-hidden="true">*</span></label>
            <input class="form-control" id="jenjang" name="jenjang" maxlength="50" required value="<?= master_e($r['jenjang'] ?? '') ?>"><?= ah_field_error('jenjang', '_kelas_old') ?></div>
        <div class="ah-actbar mb-0"><a class="btn btn-outline-secondary" href="<?= master_e($tutup) ?>">Batal</a><button class="btn btn-primary" type="submit">Simpan</button></div>
    </form>
    <?php ah_panel_close();
    ah_old_clear('_kelas_old');
endif;

$chip = static fn (string $s): string => '?' . ah_query(['state' => $s, 'page' => null, 'action' => null, 'id' => null]);
echo ah_chips([
    ['label' => 'Aktif', 'url' => $chip('active'), 'active' => $state === 'active', 'count' => $jumlah['active']],
    ['label' => 'Nonaktif', 'url' => $chip('inactive'), 'active' => $state === 'inactive', 'count' => $jumlah['inactive']],
    ['label' => 'Arsip', 'url' => $chip('archived'), 'active' => $state === 'archived', 'count' => $jumlah['archived']],
], 'Status kelas');
ah_filter_open($q !== '' ? 1 : 0, ['state' => $state]); ?>
    <div class="ah-filter__wide"><label class="form-label" for="kelas-q">Cari nama kelas atau jenjang</label>
        <input class="form-control" type="search" id="kelas-q" name="q" maxlength="100" value="<?= master_e($q) ?>"></div>
<?php ah_filter_close(['state' => $state]); ?>
<div class="ah-card ah-table-wrap">
    <table class="ah-table ah-table--cards" id="daftar-kelas">
        <caption class="ah-visually-hidden">Daftar kelas, <?= (int) $result['total'] ?> data</caption>
        <thead><tr><th scope="col">Nama kelas</th><th scope="col">Jenjang</th><th scope="col">Anggota semester aktif</th><th scope="col">Status</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $row): $kid = (int) $row['id']; $hidup = (int) $row['is_active'] === 1 && $row['archived_at'] === null; ?>
            <tr<?= $row['archived_at'] !== null ? ' class="ah-row-muted"' : '' ?>>
                <td><b><?= master_e($row['nama_kelas']) ?></b></td>
                <td><?= master_e($row['jenjang']) ?></td>
                <td><?= (int) $row['anggota'] ?> santri</td>
                <td><?= ah_state_badge($row) ?></td>
                <td data-label=""><div class="ah-actions">
                    <?php if ($hidup): ?><a class="btn btn-sm btn-outline-primary" href="admin_penempatan_santri.php?kelas_id=<?= $kid ?>">Atur anggota</a><?php endif; ?>
                    <?= ah_menu(array_values(array_filter([
                        ['label' => 'Ubah', 'url' => '?' . ah_query(['action' => 'edit', 'id' => $kid]), 'icon' => 'fa-pen'],
                        $row['archived_at'] === null ? ((int) $row['is_active'] === 1
                            ? ['label' => 'Nonaktifkan', 'icon' => 'fa-ban', 'action' => $halaman, 'fields' => ['id' => $kid, 'action' => 'deactivate'],
                                'confirm' => ['title' => 'Nonaktifkan kelas ' . $row['nama_kelas'] . '?', 'text' => 'Kelas nonaktif tidak dapat dipilih untuk penempatan dan penugasan baru. Riwayat keanggotaan dan absensi lama tetap tersimpan.', 'ok' => 'Nonaktifkan']]
                            : ['label' => 'Aktifkan', 'icon' => 'fa-check', 'action' => $halaman, 'fields' => ['id' => $kid, 'action' => 'activate'],
                                'confirm' => ['title' => 'Aktifkan kelas ' . $row['nama_kelas'] . '?', 'text' => 'Kelas kembali dapat dipilih.', 'ok' => 'Aktifkan']]) : null,
                        $row['archived_at'] === null
                            ? ['label' => 'Arsipkan', 'icon' => 'fa-box-archive', 'danger' => true, 'action' => $halaman, 'fields' => ['id' => $kid, 'action' => 'archive'],
                                'confirm' => ['title' => 'Arsipkan kelas ' . $row['nama_kelas'] . '?', 'text' => 'Kelas disembunyikan dari pilihan. Riwayat keanggotaan dan absensi lama tetap tersimpan.', 'ok' => 'Arsipkan']]
                            : ['label' => 'Pulihkan', 'icon' => 'fa-rotate-left', 'action' => $halaman, 'fields' => ['id' => $kid, 'action' => 'restore'],
                                'confirm' => ['title' => 'Pulihkan kelas ' . $row['nama_kelas'] . '?', 'text' => 'Kelas kembali aktif.', 'ok' => 'Pulihkan']],
                    ])), 'Tindakan untuk kelas ' . $row['nama_kelas']) ?></div></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($result['rows'] === []): ?><tr><td colspan="5" data-label=""><?= ah_empty('Tidak ada kelas yang cocok', 'Ubah pencarian atau status, atau tambahkan kelas.') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php master_pagination((int) $result['total'], (int) $result['page'], 20); master_footer(); ?>
