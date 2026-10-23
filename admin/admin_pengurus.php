<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;

/**
 * Data pesantren › Asatidz & pengurus › tab Pengurus (redesain website
 * Tahap 6, M-1).
 *
 * Kolom: nomor identitas, nama, HP, jabatan, kamar/kelas binaan (dari
 * penugasan pembimbing aktif), status. Tambah/ubah/detail di panel samping;
 * status di ⋯ dengan dialog yang seragam. Pengurus tidak dibuatkan akun di
 * sini; panel detail menautkan ke Akun & hak akses dengan pengurus terpilih.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = master_data_service();
$halaman = 'admin_pengurus.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($action === 'save') {
            $savedId = $service->savePengurus($_POST, $id ?: null);
            ah_old_clear('_pengurus_old');
            master_flash('success', 'Data ' . ($service->pengurus($savedId)['nama'] ?? 'pengurus') . ' tersimpan. Pengurus tidak dibuatkan akun login otomatis.');
            master_redirect($halaman . '?action=detail&id=' . $savedId);
        }
        $service->setPengurusState($id, $action);
        master_flash('success', 'Status ' . ($service->pengurus($id)['nama'] ?? 'pengurus') . ' diperbarui. Akun dan riwayat lama tidak dihapus.');
    } catch (MasterDataException $exception) {
        master_flash('danger', $exception->getMessage());
        if ($action === 'save') {
            ah_validation_keep($_POST, ['nama', 'nomor_identitas', 'no_hp', 'jabatan'], $exception, '_pengurus_old');
            master_redirect($halaman . '?action=' . ($id ? 'edit&id=' . $id : 'create'));
        }
    }
    master_redirect($halaman);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = [
    'q' => is_string($_GET['q'] ?? null) ? $_GET['q'] : '',
    'state' => in_array($_GET['state'] ?? 'active', ['active', 'inactive', 'archived', 'all'], true) ? (string) ($_GET['state'] ?? 'active') : 'active',
];
$result = $service->pengurusList($filters, $page);
$jumlah = $service->stateCounts('pengurus', $filters['q']);
$selected = isset($_GET['id']) ? $service->pengurus((int) $_GET['id']) : null;
$mode = (string) ($_GET['action'] ?? '');
$tutup = $halaman . (($q = ah_query(['action' => null, 'id' => null])) === '' ? '' : '?' . $q);

master_header('Asatidz & pengurus', [
    'active' => 'asatidz',
    'description' => 'Orang yang mengajar dan mengurus santri.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Data pesantren'], ['label' => 'Asatidz & pengurus']],
    'actions' => '<a class="btn btn-outline-secondary" href="export_master.php?entity=pengurus&amp;' . master_e(http_build_query($filters)) . '"><i class="fas fa-file-csv" aria-hidden="true"></i> Ekspor CSV</a>'
        . '<a class="btn btn-primary" href="?action=create"><i class="fas fa-plus" aria-hidden="true"></i> Tambah pengurus</a>',
]);

if ($mode === 'create' || ($mode === 'edit' && $selected)):
    $r = array_replace($selected ?? [], is_array($_SESSION['_pengurus_old'] ?? null) ? $_SESSION['_pengurus_old'] : []);
    ah_panel_open(['title' => $selected ? 'Ubah ' . $selected['nama'] : 'Tambah pengurus', 'close_url' => $selected ? $halaman . '?action=detail&id=' . (int) $selected['id'] : $tutup]);
    echo ah_error_summary('_pengurus_old'); ?>
    <form method="post">
        <?= master_csrf() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($selected): ?><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><?php endif; ?>
        <div class="mb-3"><label class="form-label" for="nama">Nama <span class="text-danger" aria-hidden="true">*</span></label>
            <input class="form-control" id="nama" name="nama" maxlength="100" required value="<?= master_e($r['nama'] ?? '') ?>"><?= ah_field_error('nama', '_pengurus_old') ?></div>
        <div class="mb-3"><label class="form-label" for="nomor_identitas">Nomor identitas</label>
            <input class="form-control" id="nomor_identitas" name="nomor_identitas" maxlength="50" value="<?= master_e($r['nomor_identitas'] ?? '') ?>"><?= ah_field_error('nomor_identitas', '_pengurus_old') ?></div>
        <div class="mb-3"><label class="form-label" for="no_hp">Nomor HP</label>
            <input class="form-control" id="no_hp" name="no_hp" inputmode="tel" maxlength="20" value="<?= master_e($r['no_hp'] ?? '') ?>"><?= ah_field_error('no_hp', '_pengurus_old') ?></div>
        <div class="mb-3"><label class="form-label" for="jabatan">Jabatan <span class="text-danger" aria-hidden="true">*</span></label>
            <input class="form-control" id="jabatan" name="jabatan" maxlength="100" required value="<?= master_e($r['jabatan'] ?? '') ?>"><?= ah_field_error('jabatan', '_pengurus_old') ?></div>
        <div class="ah-actbar mb-0"><a class="btn btn-outline-secondary" href="<?= master_e($selected ? $halaman . '?action=detail&id=' . (int) $selected['id'] : $tutup) ?>">Batal</a>
            <button class="btn btn-primary" type="submit">Simpan</button></div>
    </form>
    <?php ah_panel_close();
    ah_old_clear('_pengurus_old');
elseif ($mode === 'detail' && $selected):
    $pid = (int) $selected['id'];
    $akun = $service->linkedAccounts('pengurus_id', $pid);
    $binaan = '';
    foreach ($result['rows'] as $baris) {
        if ((int) $baris['id'] === $pid) { $binaan = (string) ($baris['binaan'] ?? ''); }
    }
    ah_panel_open(['title' => (string) $selected['nama'], 'close_url' => $tutup, 'description' => (string) $selected['jabatan']]); ?>
    <dl class="ah-kv">
        <div><dt>Nomor identitas</dt><dd><?= master_e($selected['nomor_identitas'] ?: '—') ?></dd></div>
        <div><dt>Nomor HP</dt><dd><?= master_e($selected['no_hp'] ?: '—') ?></dd></div>
        <div><dt>Status</dt><dd><?= ah_state_badge($selected) ?></dd></div>
        <div><dt>Akun login</dt><dd><?php if ($akun === []): ?>Belum ada · <a class="btn btn-sm btn-outline-primary" href="admin_akun.php?pengurus_id=<?= $pid ?>#ah-buat-pengurus">Buat akun</a>
            <?php else: foreach ($akun as $a): ?>@<?= master_e($a['username']) ?> · <?= master_e($a['peran'] ?: 'tanpa peran') ?><?= (int) $a['is_active'] === 1 ? '' : ' (nonaktif)' ?><br><?php endforeach; endif; ?></dd></div>
    </dl>
    <h3 class="h6 mt-3">Penugasan</h3>
    <ul class="ah-mini-list mb-3">
        <li><a href="admin_penugasan.php?jenis=pembimbing&amp;q=<?= master_e(rawurlencode((string) $selected['nama'])) ?>"><span>Pembimbing</span><b><?= $binaan !== '' ? master_e($binaan) : 'Lihat' ?></b></a></li>
        <li><a href="admin_penugasan.php?jenis=pendidikan&amp;q=<?= master_e(rawurlencode((string) $selected['nama'])) ?>"><span>Bagian Pendidikan</span><b>Lihat</b></a></li>
    </ul>
    <div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-primary" href="?action=edit&amp;id=<?= $pid ?>"><i class="fas fa-pen" aria-hidden="true"></i> Ubah</a></div>
    <?php ah_panel_close();
endif;

$chip = static fn (string $state): string => '?' . ah_query(['state' => $state, 'page' => null, 'action' => null, 'id' => null]);
echo ah_chips([
    ['label' => 'Aktif', 'url' => $chip('active'), 'active' => $filters['state'] === 'active', 'count' => $jumlah['active']],
    ['label' => 'Nonaktif', 'url' => $chip('inactive'), 'active' => $filters['state'] === 'inactive', 'count' => $jumlah['inactive']],
    ['label' => 'Arsip', 'url' => $chip('archived'), 'active' => $filters['state'] === 'archived', 'count' => $jumlah['archived']],
], 'Status pengurus');
ah_filter_open($filters['q'] !== '' ? 1 : 0, ['state' => $filters['state']]); ?>
    <div class="ah-filter__wide"><label class="form-label" for="pengurus-q">Cari nama, identitas, jabatan, atau HP</label>
        <input class="form-control" type="search" id="pengurus-q" name="q" maxlength="100" value="<?= master_e($filters['q']) ?>"></div>
<?php ah_filter_close(['state' => $filters['state']]); ?>
<div class="ah-card ah-table-wrap">
    <table class="ah-table ah-table--cards" id="daftar-pengurus">
        <caption class="ah-visually-hidden">Daftar pengurus, <?= (int) $result['total'] ?> data</caption>
        <thead><tr><th scope="col">Nomor identitas</th><th scope="col">Nama</th><th scope="col">HP</th><th scope="col">Jabatan</th><th scope="col">Kamar/kelas binaan</th><th scope="col">Status</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $row): $pid = (int) $row['id']; ?>
            <tr<?= $row['archived_at'] !== null ? ' class="ah-row-muted"' : '' ?>>
                <td><?= master_e($row['nomor_identitas'] ?: '—') ?></td>
                <td><a class="ah-row-link" href="?<?= master_e(ah_query(['action' => 'detail', 'id' => $pid])) ?>"><?= master_e($row['nama']) ?></a></td>
                <td><?= master_e($row['no_hp'] ?: '—') ?></td>
                <td><?= master_e($row['jabatan']) ?></td>
                <td><span class="ah-cell-wrap"><?= master_e($row['binaan'] ?: '—') ?></span></td>
                <td><?= ah_state_badge($row) ?></td>
                <td data-label=""><?= ah_menu(array_values(array_filter([
                    ['label' => 'Lihat', 'url' => '?' . ah_query(['action' => 'detail', 'id' => $pid]), 'icon' => 'fa-eye'],
                    ['label' => 'Ubah', 'url' => '?' . ah_query(['action' => 'edit', 'id' => $pid]), 'icon' => 'fa-pen'],
                    $row['archived_at'] === null ? ((int) $row['is_active'] === 1
                        ? ['label' => 'Nonaktifkan', 'icon' => 'fa-user-slash', 'action' => $halaman, 'fields' => ['id' => $pid, 'action' => 'deactivate'],
                            'confirm' => ['title' => 'Nonaktifkan ' . $row['nama'] . '?', 'text' => 'Status aktif memengaruhi kelayakan penugasan pembimbing. Akun dan riwayat lama tidak dihapus.', 'ok' => 'Nonaktifkan']]
                        : ['label' => 'Aktifkan', 'icon' => 'fa-user-check', 'action' => $halaman, 'fields' => ['id' => $pid, 'action' => 'activate'],
                            'confirm' => ['title' => 'Aktifkan ' . $row['nama'] . '?', 'text' => 'Kembali dapat dipilih untuk penugasan.', 'ok' => 'Aktifkan']]) : null,
                    $row['archived_at'] === null
                        ? ['label' => 'Arsipkan', 'icon' => 'fa-box-archive', 'danger' => true, 'action' => $halaman, 'fields' => ['id' => $pid, 'action' => 'archive'],
                            'confirm' => ['title' => 'Arsipkan ' . $row['nama'] . '?', 'text' => 'Berhenti muncul pada daftar aktif. Akun, penugasan, dan riwayat lama TIDAK dihapus.', 'ok' => 'Arsipkan']]
                        : ['label' => 'Pulihkan', 'icon' => 'fa-rotate-left', 'action' => $halaman, 'fields' => ['id' => $pid, 'action' => 'restore'],
                            'confirm' => ['title' => 'Pulihkan ' . $row['nama'] . '?', 'text' => 'Kembali ke daftar aktif.', 'ok' => 'Pulihkan']],
                ])), 'Tindakan untuk ' . $row['nama']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($result['rows'] === []): ?>
            <tr><td colspan="7" data-label=""><?= ah_empty('Tidak ada pengurus yang cocok', 'Ubah pencarian atau status, atau tambahkan pengurus.', '<a class="btn btn-sm btn-primary" href="?action=create">Tambah pengurus</a>') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php master_pagination((int) $result['total'], $page, 20); master_footer(); ?>
