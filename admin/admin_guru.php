<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;

/**
 * Data Guru (koreksi ke-3, keputusan pengguna 30 Agustus 2026).
 *
 * Pilihan tugas lama "Guru / Pembimbing / Keduanya" DIHAPUS dari formulir dan
 * tampilan operasional, dan TIDAK diganti dropdown lain:
 *
 *   - penugasan mengajar ditentukan oleh Jadwal Pengajian;
 *   - penugasan murobi ditentukan oleh halaman Penugasan Murobi;
 *   - pembimbing tetap penugasan pengurus, bukan identitas guru.
 *
 * Kolom `guru.status` lama tidak dihapus dari basis data dan tidak ditimpa saat
 * formulir disimpan; nilainya hanya ditampilkan sebagai catatan warisan pada
 * halaman detail.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = master_data_service();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        $id = isset($_POST['id']) ? (int) $_POST['id'] : null;
        if ($action === 'save') {
            $savedId = $service->saveGuru($_POST, $id ?: null);
            ah_old_clear();
            master_flash('success', ($id ? 'Perubahan data guru' : 'Guru baru') . ' berhasil disimpan. ID guru: ' . $savedId . '.');
        } else {
            $service->setGuruState((int) $id, $action);
            master_flash('success', 'Status guru diperbarui. Jadwal, absensi, penugasan, dan riwayat lama tidak dihapus.');
        }
    } catch (MasterDataException $exception) {
        ah_validation_keep($_POST, ['nip', 'nama_guru', 'no_hp'], $exception, '_ah_old');
        master_flash('danger', $exception->getMessage());
        master_redirect('admin_guru.php?action=' . (!empty($_POST['id']) ? 'edit&id=' . (int) $_POST['id'] : 'create'));
    }
    master_redirect('admin_guru.php');
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = ['q' => $_GET['q'] ?? '', 'state' => $_GET['state'] ?? 'active'];
$result = $service->guruList($filters, $page);
$selected = isset($_GET['id']) ? $service->guru((int) $_GET['id']) : null;
$mode = (string) ($_GET['action'] ?? '');

$penugasan = [];
foreach ($result['rows'] as $row) {
    $penugasan[(int) $row['id']] = $service->guruAssignments((int) $row['id']);
}

master_header('Data Guru', [
    'heading' => 'Data Guru',
    'description' => 'Identitas guru. Penugasan mengajar berasal dari Jadwal Pengajian dan penugasan murobi berasal dari halaman Penugasan Murobi — bukan dari data identitas ini.',
    'active' => 'master.guru',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Master Data'],
        ['label' => 'Data Guru'],
    ],
    'actions' => '<a class="btn btn-outline-primary" href="export_master.php?entity=guru&amp;' . master_e(http_build_query($filters)) . '">Ekspor CSV</a>'
        . '<a class="btn btn-primary" href="admin_guru.php?action=create">Tambah Guru</a>',
]);
?>

<?php if ($mode === 'create' || ($mode === 'edit' && $selected)): $record = $selected ?? []; ?>
<section class="ah-card" aria-labelledby="ah-form-guru">
    <div class="ah-card__head"><span id="ah-form-guru"><?= $selected ? 'Ubah guru #' . (int) $selected['id'] : 'Tambah guru' ?></span></div>
    <div class="ah-card__body">
        <form method="post" class="row g-3">
            <?= master_csrf() ?>
            <input type="hidden" name="action" value="save">
            <?php if ($selected): ?><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><?php endif; ?>
            <div class="col-12">
                <fieldset class="ah-fieldset mb-0">
                    <legend>Identitas guru</legend>
                    <p class="ah-fieldset__hint">
                        Tidak ada lagi pilihan jenis tugas di sini. Guru menjadi pengampu melalui jadwal,
                        dan menjadi murobi melalui penugasan murobi yang aktif.
                    </p>
                    <div class="row g-3">
                        <div class="col-md-3"><label class="form-label" for="nip">NIP <span class="text-muted fw-normal">(boleh kosong)</span></label>
                            <input class="form-control" id="nip" name="nip" maxlength="30" value="<?= master_e(ah_old('nip', $record)) ?>"><?= ah_field_error('nip','_ah_old') ?></div>
                        <div class="col-md-5"><label class="form-label" for="nama_guru">Nama guru</label>
                            <input class="form-control" id="nama_guru" name="nama_guru" maxlength="100" required value="<?= master_e(ah_old('nama_guru', $record)) ?>"><?= ah_field_error('nama_guru','_ah_old') ?></div>
                        <div class="col-md-4"><label class="form-label" for="no_hp">Nomor HP</label>
                            <input class="form-control" id="no_hp" name="no_hp" maxlength="20" inputmode="tel"
                                   aria-describedby="bantuan_hp" value="<?= master_e(ah_old('no_hp', $record)) ?>"><?= ah_field_error('no_hp','_ah_old') ?>
                            <div class="form-text" id="bantuan_hp">Diawali 0, 9–16 digit. Boleh dikosongkan.</div></div>
                    </div>
                </fieldset>
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Simpan</button>
                <a class="btn btn-outline-secondary" href="admin_guru.php">Batal</a>
            </div>
        </form>
    </div>
</section>
<?php ah_old_clear(); ?>
<?php elseif ($mode === 'detail' && $selected): $ringkasan = $service->guruAssignments((int) $selected['id']); ?>
<section class="ah-card" aria-labelledby="ah-detail-guru">
    <div class="ah-card__head"><span id="ah-detail-guru">Detail guru #<?= (int) $selected['id'] ?></span>
        <a class="btn btn-sm btn-outline-secondary" href="admin_guru.php?action=edit&amp;id=<?= (int) $selected['id'] ?>">Ubah</a></div>
    <div class="ah-card__body">
        <div class="row g-3">
            <div class="col-md-3"><small class="text-muted d-block">NIP</small><?= master_e($selected['nip'] ?: '-') ?></div>
            <div class="col-md-4"><small class="text-muted d-block">Nama</small><?= master_e($selected['nama_guru']) ?></div>
            <div class="col-md-3"><small class="text-muted d-block">Nomor HP</small><?= master_e($selected['no_hp'] ?: '-') ?></div>
            <div class="col-md-2"><small class="text-muted d-block">Status data</small><?= ah_state_badge($selected) ?></div>
        </div>
        <h2 class="h6 mt-4">Penugasan aktif</h2>
        <p class="text-muted small">Dihitung dari data operasional, bukan dari label tugas.</p>
        <div class="ah-stats">
            <div class="ah-stat">
                <p class="ah-stat__label">Jadwal mengajar aktif</p>
                <p class="ah-stat__value"><?= (int) $ringkasan['jadwal_aktif'] ?></p>
                <p class="ah-stat__hint"><a href="<?= master_e(app_url('/admin/admin_pengajian.php?tab=jadwal&teacher_id=' . (int) $selected['id'])) ?>">Lihat jadwalnya</a></p>
            </div>
            <div class="ah-stat">
                <p class="ah-stat__label">Penugasan murobi aktif</p>
                <p class="ah-stat__value"><?= (int) $ringkasan['murobi_aktif'] ?></p>
                <p class="ah-stat__hint"><a href="<?= master_e(app_url('/admin/admin_murobi.php')) ?>">Kelola penugasan murobi</a></p>
            </div>
        </div>
        <?php if (!empty($selected['status'])): ?>
            <?php ah_note(
                'info',
                'Catatan warisan: data ini menyimpan nilai tugas lama "' . (string) $selected['status'] . '".',
                '<p class="small mb-0 mt-2">Nilai tersebut tidak lagi dipakai untuk menentukan apa pun dan tidak diubah saat formulir disimpan. '
                    . 'Ia dipertahankan agar laporan, ekspor, dan riwayat lama tetap dapat dibaca.</p>'
            ); ?>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<form method="get" class="ah-card ah-no-print">
    <div class="ah-card__body">
        <fieldset class="ah-fieldset mb-0">
            <legend>Pencarian dan filter</legend>
            <div class="row g-2 align-items-end">
                <div class="col-md-6"><label class="form-label" for="q">Pencarian</label>
                    <input class="form-control" id="q" name="q" value="<?= master_e($filters['q']) ?>" placeholder="Nama, NIP, atau nomor HP"></div>
                <div class="col-md-3"><label class="form-label" for="state">Status data</label>
                    <select class="form-select" id="state" name="state">
                        <?php foreach (['active' => 'Aktif', 'inactive' => 'Nonaktif', 'archived' => 'Arsip', 'all' => 'Semua'] as $value => $label): ?>
                            <option value="<?= master_e($value) ?>" <?= $filters['state'] === $value ? 'selected' : '' ?>><?= master_e($label) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-3 d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">Terapkan</button>
                    <a class="btn btn-outline-secondary" href="admin_guru.php">Bersihkan filter</a></div>
            </div>
        </fieldset>
    </div>
</form>

<section class="ah-card" aria-labelledby="ah-daftar-guru">
    <div class="ah-card__head"><span id="ah-daftar-guru">Daftar guru</span>
        <span class="text-muted small"><?= count($result['rows']) ?> dari <?= (int) $result['total'] ?> data</span></div>
    <?php if ($result['rows'] === []): ?>
        <div class="ah-card__body"><?= ah_empty(
            'Tidak ada guru yang sesuai filter',
            'Ubah kata kunci atau status data, atau tambahkan guru baru.',
            '<a class="btn btn-sm btn-primary" href="admin_guru.php?action=create">Tambah Guru</a>'
        ) ?></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table">
            <caption class="ah-visually-hidden">Daftar guru sesuai filter</caption>
            <thead><tr>
                <th scope="col">ID</th><th scope="col">NIP</th><th scope="col">Nama</th><th scope="col">HP</th>
                <th scope="col">Penugasan aktif</th><th scope="col">Status data</th><th scope="col">Aksi</th>
            </tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $row): $r = $penugasan[(int) $row['id']]; ?>
                <tr>
                    <td><?= (int) $row['id'] ?></td>
                    <td><?= master_e($row['nip'] ?: '-') ?></td>
                    <td class="fw-semibold"><?= master_e($row['nama_guru']) ?></td>
                    <td><?= master_e($row['no_hp'] ?: '-') ?></td>
                    <td>
                        <?= $r['jadwal_aktif'] > 0 ? ah_badge('Mengajar (' . $r['jadwal_aktif'] . ' jadwal)', 'ok') : ah_badge('Belum ada jadwal', 'muted') ?>
                        <?= $r['murobi_aktif'] > 0 ? ' ' . ah_badge('Murobi (' . $r['murobi_aktif'] . ')', 'info') : '' ?>
                    </td>
                    <td><?= ah_state_badge($row) ?></td>
                    <td><div class="ah-actions">
                        <a class="btn btn-sm btn-outline-primary" href="?action=detail&amp;id=<?= (int) $row['id'] ?>">Detail</a>
                        <a class="btn btn-sm btn-outline-secondary" href="?action=edit&amp;id=<?= (int) $row['id'] ?>">Ubah</a>
                        <form data-confirm="Ubah status data guru ini? Status aktif menentukan kelayakan jadwal dan penugasan; data serta riwayat lama tetap tersimpan." method="post"><?= master_csrf() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary" name="action" value="<?= (int) $row['is_active'] === 1 ? 'deactivate' : 'activate' ?>"><?= (int) $row['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
                        <form method="post" onsubmit="return confirm('Arsipkan data guru ini? Guru berhenti muncul pada daftar aktif dan pilihan penugasan baru, tetapi akun, jadwal, absensi, penugasan, dan riwayat lama TIDAK dihapus.')">
                            <?= master_csrf() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" name="action" value="<?= $row['archived_at'] ? 'restore' : 'archive' ?>"><?= $row['archived_at'] ? 'Pulihkan' : 'Arsipkan' ?></button></form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php master_pagination((int) $result['total'], $page, 20); master_footer(); ?>
