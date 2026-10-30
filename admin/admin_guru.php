<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;

/**
 * Data pesantren › Asatidz & pengurus › tab Asatidz (redesain website
 * Tahap 6, M-1).
 *
 * Tambah/ubah/detail di panel samping; Nonaktifkan/Arsipkan/Pulihkan di ⋯
 * dengan dialog. Detail menampilkan akun login yang terhubung (atau tombol
 * "Buat akun" menuju Akun & hak akses dengan guru ini terpilih) dan tautan ke
 * Penugasan.
 *
 * Pilihan tugas lama "Guru / Pembimbing / Keduanya" tetap tidak menjadi
 * isian (koreksi ke-3, 30 Agustus 2026); kolom `guru.status` hanya tampil
 * sebagai catatan kecil di panel detail dan tidak ditimpa saat disimpan.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = master_data_service();
$halaman = 'admin_guru.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = isset($_POST['id']) ? (int) $_POST['id'] : null;
    try {
        if ($action === 'save') {
            $savedId = $service->saveGuru($_POST, $id ?: null);
            ah_old_clear();
            master_flash('success', 'Data ' . ($service->guru($savedId)['nama_guru'] ?? 'ustadz/ustadzah') . ' tersimpan.');
            master_redirect($halaman . '?action=detail&id=' . $savedId);
        }
        $service->setGuruState((int) $id, $action);
        master_flash('success', 'Status ' . ($service->guru((int) $id)['nama_guru'] ?? 'guru') . ' diperbarui. Jadwal, absensi, penugasan, dan riwayat lama tidak dihapus.');
    } catch (MasterDataException $exception) {
        ah_validation_keep($_POST, ['nip', 'nama_guru', 'no_hp'], $exception, '_ah_old');
        master_flash('danger', $exception->getMessage());
        if ($action === 'save') {
            master_redirect($halaman . '?action=' . (!empty($_POST['id']) ? 'edit&id=' . (int) $_POST['id'] : 'create'));
        }
    }
    master_redirect($halaman);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = [
    'q' => is_string($_GET['q'] ?? null) ? $_GET['q'] : '',
    'state' => in_array($_GET['state'] ?? 'active', ['active', 'inactive', 'archived', 'all'], true) ? (string) ($_GET['state'] ?? 'active') : 'active',
];
$result = $service->guruList($filters, $page);
$jumlah = $service->stateCounts('guru', $filters['q']);
$selected = isset($_GET['id']) ? $service->guru((int) $_GET['id']) : null;
$mode = (string) ($_GET['action'] ?? '');
$tutup = $halaman . (($q = ah_query(['action' => null, 'id' => null])) === '' ? '' : '?' . $q);

master_header('Asatidz & pengurus', [
    'active' => 'asatidz',
    'description' => 'Orang yang mengajar dan mengurus santri.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Data pesantren'], ['label' => 'Asatidz & pengurus']],
    'actions' => '<a class="btn btn-outline-secondary" href="export_master.php?entity=guru&amp;' . master_e(http_build_query($filters)) . '"><i class="fas fa-file-csv" aria-hidden="true"></i> Ekspor CSV</a>'
        . '<a class="btn btn-primary" href="?action=create"><i class="fas fa-plus" aria-hidden="true"></i> Tambah ustadz/ustadzah</a>',
]);

if ($mode === 'create' || ($mode === 'edit' && $selected)):
    $record = $selected ?? [];
    ah_panel_open(['title' => $selected ? 'Ubah ' . $selected['nama_guru'] : 'Tambah ustadz/ustadzah', 'close_url' => $selected ? $halaman . '?action=detail&id=' . (int) $selected['id'] : $tutup,
        'description' => 'Tugas mengajar mengikuti jadwal; tugas murobi dan lainnya mengikuti Penugasan.']);
    echo ah_error_summary('_ah_old'); ?>
    <form method="post">
        <?= master_csrf() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($selected): ?><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><?php endif; ?>
        <div class="mb-3"><label class="form-label" for="nama_guru">Nama <span class="text-danger" aria-hidden="true">*</span></label>
            <input class="form-control" id="nama_guru" name="nama_guru" maxlength="100" required value="<?= master_e(ah_old('nama_guru', $record)) ?>"><?= ah_field_error('nama_guru', '_ah_old') ?></div>
        <div class="mb-3"><label class="form-label" for="nip">NIP <span class="text-muted fw-normal">(boleh kosong)</span></label>
            <input class="form-control" id="nip" name="nip" maxlength="30" value="<?= master_e(ah_old('nip', $record)) ?>"><?= ah_field_error('nip', '_ah_old') ?></div>
        <div class="mb-3"><label class="form-label" for="no_hp">Nomor HP</label>
            <input class="form-control" id="no_hp" name="no_hp" maxlength="20" inputmode="tel" aria-describedby="bantuan_hp" value="<?= master_e(ah_old('no_hp', $record)) ?>"><?= ah_field_error('no_hp', '_ah_old') ?>
            <div class="form-text" id="bantuan_hp">Diawali 0, 9–16 digit. Boleh dikosongkan.</div></div>
        <div class="ah-actbar mb-0"><a class="btn btn-outline-secondary" href="<?= master_e($selected ? $halaman . '?action=detail&id=' . (int) $selected['id'] : $tutup) ?>">Batal</a>
            <button class="btn btn-primary" type="submit">Simpan</button></div>
    </form>
    <?php ah_panel_close();
    ah_old_clear();
elseif ($mode === 'detail' && $selected):
    $gid = (int) $selected['id'];
    $tugas = $service->guruAssignments($gid);
    $akun = $service->linkedAccounts('guru_id', $gid);
    ah_panel_open(['title' => (string) $selected['nama_guru'], 'close_url' => $tutup]); ?>
    <dl class="ah-kv">
        <div><dt>NIP</dt><dd><?= master_e($selected['nip'] ?: '—') ?></dd></div>
        <div><dt>Nomor HP</dt><dd><?= master_e($selected['no_hp'] ?: '—') ?></dd></div>
        <div><dt>Status</dt><dd><?= ah_state_badge($selected) ?></dd></div>
        <div><dt>Akun login</dt><dd><?php if ($akun === []): ?>Belum ada · <a class="btn btn-sm btn-outline-primary" href="admin_akun.php?guru_id=<?= $gid ?>#ah-buat-guru">Buat akun</a>
            <?php else: foreach ($akun as $a): ?>@<?= master_e($a['username']) ?> · <?= master_e($a['peran'] ?: 'tanpa peran') ?><?= (int) $a['is_active'] === 1 ? '' : ' (nonaktif)' ?><br><?php endforeach; endif; ?></dd></div>
    </dl>
    <h3 class="h6 mt-3">Penugasan aktif</h3>
    <ul class="ah-mini-list mb-3">
        <li><a href="<?= master_e(app_url('/admin/admin_pengajian.php?tab=jadwal&teacher_id=' . $gid)) ?>"><span>Jadwal mengajar semester aktif</span><b><?= (int) $tugas['jadwal_aktif'] ?></b></a></li>
        <li><a href="admin_penugasan.php?jenis=murobi&amp;q=<?= master_e(rawurlencode((string) $selected['nama_guru'])) ?>"><span>Murobi</span><b><?= (int) $tugas['murobi_aktif'] ?></b></a></li>
        <li><a href="admin_penugasan.php?jenis=guru_mapel&amp;q=<?= master_e(rawurlencode((string) $selected['nama_guru'])) ?>"><span>Guru mata pelajaran</span><b>Lihat</b></a></li>
    </ul>
    <?php // PK-1: penugasan berbasis pengurus milik guru ini (pembimbing, Pendidikan, kesehatan, BSA, Keamanan, PSB).
    $identitas = $service->identitasGuru($gid);
    if ($identitas !== null): ?>
        <h3 class="h6 mt-3">Penugasan sebagai pengurus</h3>
        <p class="small text-muted mb-2">Guru ini memegang identitas pengurus untuk penugasan selain murobi dan guru mata pelajaran. Nama, nomor HP, dan statusnya mengikuti data guru ini.</p>
        <ul class="ah-mini-list mb-3">
            <?php foreach (['pembimbing' => 'Pembimbing', 'pendidikan' => 'Bagian Pendidikan', 'kesehatan' => 'Petugas Kesehatan', 'bendahara_bulanan' => 'Petugas BSA', 'keamanan' => 'Keamanan'] as $jenisPengurus => $labelPengurus): ?>
                <li><a href="admin_penugasan.php?jenis=<?= $jenisPengurus ?>&amp;q=<?= master_e(rawurlencode((string) $identitas['nama'])) ?>"><span><?= $labelPengurus ?></span><b>Lihat</b></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if (!empty($selected['status'])): ?>
        <p class="small text-muted">Catatan data lama: tugas tercatat “<?= master_e($selected['status']) ?>”. Nilai ini tidak dipakai lagi dan tidak diubah saat disimpan.</p>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-primary" href="?action=edit&amp;id=<?= $gid ?>"><i class="fas fa-pen" aria-hidden="true"></i> Ubah</a></div>
    <?php ah_panel_close();
endif;

$chip = static fn (string $state): string => '?' . ah_query(['state' => $state, 'page' => null, 'action' => null, 'id' => null]);
echo ah_chips([
    ['label' => 'Aktif', 'url' => $chip('active'), 'active' => $filters['state'] === 'active', 'count' => $jumlah['active']],
    ['label' => 'Nonaktif', 'url' => $chip('inactive'), 'active' => $filters['state'] === 'inactive', 'count' => $jumlah['inactive']],
    ['label' => 'Arsip', 'url' => $chip('archived'), 'active' => $filters['state'] === 'archived', 'count' => $jumlah['archived']],
], 'Status asatidz');
ah_filter_open($filters['q'] !== '' ? 1 : 0, ['state' => $filters['state']]); ?>
    <div class="ah-filter__wide"><label class="form-label" for="guru-q">Cari nama, NIP, atau HP</label>
        <input class="form-control" type="search" id="guru-q" name="q" maxlength="100" value="<?= master_e($filters['q']) ?>"></div>
<?php ah_filter_close(['state' => $filters['state']]); ?>
<div class="ah-card ah-table-wrap">
    <table class="ah-table ah-table--cards" id="daftar-guru">
        <caption class="ah-visually-hidden">Daftar asatidz, <?= (int) $result['total'] ?> data</caption>
        <thead><tr><th scope="col">NIP</th><th scope="col">Nama</th><th scope="col">HP</th><th scope="col">Penugasan aktif</th><th scope="col">Status</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $row): $gid = (int) $row['id']; $r = $service->guruAssignments($gid); ?>
            <tr<?= $row['archived_at'] !== null ? ' class="ah-row-muted"' : '' ?>>
                <td><?= master_e($row['nip'] ?: '—') ?></td>
                <td><a class="ah-row-link" href="?<?= master_e(ah_query(['action' => 'detail', 'id' => $gid])) ?>"><?= master_e($row['nama_guru']) ?></a></td>
                <td><?= master_e($row['no_hp'] ?: '—') ?></td>
                <td><span class="ah-hak"><?= $r['jadwal_aktif'] > 0 ? ah_badge('Mengajar · ' . $r['jadwal_aktif'] . ' jadwal', 'ok') : '' ?><?= $r['murobi_aktif'] > 0 ? ah_badge('Murobi', 'info') : '' ?><?= $r['jadwal_aktif'] === 0 && $r['murobi_aktif'] === 0 ? '<span class="text-muted">—</span>' : '' ?></span></td>
                <td><?= ah_state_badge($row) ?></td>
                <td data-label=""><?= ah_menu(array_values(array_filter([
                    ['label' => 'Lihat', 'url' => '?' . ah_query(['action' => 'detail', 'id' => $gid]), 'icon' => 'fa-eye'],
                    ['label' => 'Ubah', 'url' => '?' . ah_query(['action' => 'edit', 'id' => $gid]), 'icon' => 'fa-pen'],
                    $row['archived_at'] === null ? ((int) $row['is_active'] === 1
                        ? ['label' => 'Nonaktifkan', 'icon' => 'fa-user-slash', 'action' => $halaman, 'fields' => ['id' => $gid, 'action' => 'deactivate'],
                            'confirm' => ['title' => 'Nonaktifkan ' . $row['nama_guru'] . '?', 'text' => 'Status aktif menentukan kelayakan jadwal dan penugasan. Data serta riwayat lama tetap tersimpan.', 'ok' => 'Nonaktifkan']]
                        : ['label' => 'Aktifkan', 'icon' => 'fa-user-check', 'action' => $halaman, 'fields' => ['id' => $gid, 'action' => 'activate'],
                            'confirm' => ['title' => 'Aktifkan ' . $row['nama_guru'] . '?', 'text' => 'Kembali dapat dipilih untuk jadwal dan penugasan.', 'ok' => 'Aktifkan']]) : null,
                    $row['archived_at'] === null
                        ? ['label' => 'Arsipkan', 'icon' => 'fa-box-archive', 'danger' => true, 'action' => $halaman, 'fields' => ['id' => $gid, 'action' => 'archive'],
                            'confirm' => ['title' => 'Arsipkan ' . $row['nama_guru'] . '?', 'text' => 'Berhenti muncul pada daftar aktif dan pilihan penugasan baru. Akun, jadwal, absensi, penugasan, dan riwayat lama TIDAK dihapus.', 'ok' => 'Arsipkan']]
                        : ['label' => 'Pulihkan', 'icon' => 'fa-rotate-left', 'action' => $halaman, 'fields' => ['id' => $gid, 'action' => 'restore'],
                            'confirm' => ['title' => 'Pulihkan ' . $row['nama_guru'] . '?', 'text' => 'Kembali ke daftar aktif.', 'ok' => 'Pulihkan']],
                ])), 'Tindakan untuk ' . $row['nama_guru']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($result['rows'] === []): ?>
            <tr><td colspan="6" data-label=""><?= ah_empty('Tidak ada data yang cocok', 'Ubah kata kunci atau status, atau tambahkan ustadz/ustadzah baru.', '<a class="btn btn-sm btn-primary" href="?action=create">Tambah ustadz/ustadzah</a>') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php master_pagination((int) $result['total'], $page, 20); master_footer(); ?>
