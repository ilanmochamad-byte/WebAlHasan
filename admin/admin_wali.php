<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;

/**
 * Orang Tua / Wali (koreksi ke-2, keputusan pengguna 30 Agustus 2026).
 *
 * Satu identitas wali dapat dipakai bersama beberapa santri (saudara kandung).
 * Karena itu, mengubah identitas wali yang dipakai bersama menampilkan daftar
 * santri terdampak lebih dulu dan menuntut konfirmasi sebelum disimpan.
 *
 * Halaman ini tidak pernah membuat akun login: akun orang tua dibuat terpisah
 * pada halaman Akun & Hak Akses.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = master_data_service();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            if (!ah_form_token_consume('wali', $_POST['form_token'] ?? null)) {
                master_flash('warning', 'Formulir ini sudah pernah dikirim. Tidak ada data ganda yang dibuat.');
                master_redirect('admin_wali.php');
            }
            $savedId = $service->saveWali($_POST, $id ?: null);
            ah_old_clear('_wali_old');
            master_flash('success', 'Data orang tua/wali berhasil disimpan.');
            master_redirect('admin_wali.php?action=detail&id=' . $savedId);
        } elseif ($action === 'attach') {
            $service->attachWali($id, $_POST, (int) $currentUser['id']);
            master_flash('success', 'Relasi santri berhasil ditambahkan.');
        } elseif ($action === 'detach') {
            $service->detachWali($id, (int) ($_POST['relation_id'] ?? 0));
            master_flash('success', 'Relasi diarsipkan; data wali dan santri tetap tersimpan.');
        } else {
            $service->setWaliState($id, $action);
            master_flash('success', 'Status wali berhasil diperbarui tanpa menghapus relasi atau riwayat.');
        }
    } catch (MasterDataException $exception) {
        ah_old_keep($_POST, ['nama', 'no_hp', 'alamat'], '_wali_old');
        master_flash('danger', $exception->getMessage());
        if ((string) ($_POST['action'] ?? '') === 'save' && $id > 0) {
            master_redirect('admin_wali.php?action=edit&id=' . $id);
        }
    }
    master_redirect('admin_wali.php' . ($id > 0 ? '?action=detail&id=' . $id : ''));
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = ['q' => $_GET['q'] ?? '', 'state' => $_GET['state'] ?? 'active'];
$result = $service->waliList($filters, $page);
$selected = isset($_GET['id']) ? $service->wali((int) $_GET['id']) : null;
$mode = (string) ($_GET['action'] ?? '');
$terdampak = $selected ? array_values(array_filter($selected['relations'], static fn (array $r): bool => $r['archived_at'] === null)) : [];

master_header('Orang Tua / Wali', [
    'description' => 'Satu identitas wali dapat terhubung ke satu atau lebih santri. Identitas inilah sumber utama data orang tua.',
    'active' => 'master.wali',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Master Data'],
        ['label' => 'Orang Tua / Wali'],
    ],
    'actions' => '<a class="btn btn-outline-primary" href="admin_wali_rekonsiliasi.php">Rekonsiliasi wali</a>'
        . '<a class="btn btn-primary" href="?action=create">Tambah Wali</a>',
]);
?>

<?php if ($mode === 'create' || ($mode === 'edit' && $selected)): $record = $selected ?? []; ?>
<section class="ah-card" aria-labelledby="ah-form-wali">
    <div class="ah-card__head"><span id="ah-form-wali"><?= $selected ? 'Ubah wali #' . (int) $selected['id'] : 'Tambah wali' ?></span></div>
    <div class="ah-card__body">
        <?php if ($selected && count($terdampak) > 1): ?>
            <?php ah_note(
                'warning',
                'Identitas ini dipakai bersama oleh ' . count($terdampak) . ' santri. Perubahan nama atau nomor HP berlaku untuk semuanya.',
                '<ul class="small mb-0 mt-2"><li>' . implode('</li><li>', array_map(
                    static fn (array $r): string => ah_e($r['nis'] . ' — ' . $r['nama_santri'] . ' (' . $r['hubungan'] . ')'),
                    $terdampak
                )) . '</li></ul>'
            ); ?>
        <?php endif; ?>
        <form method="post" class="row g-3">
            <?= master_csrf() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="form_token" value="<?= master_e(ah_form_token('wali')) ?>">
            <?php if ($selected): ?><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><?php endif; ?>
            <div class="col-md-4"><label class="form-label" for="nama">Nama</label>
                <input class="form-control" id="nama" name="nama" maxlength="100" required value="<?= master_e(ah_old('nama', $record, '_wali_old')) ?>"></div>
            <div class="col-md-3"><label class="form-label" for="no_hp">Nomor HP</label>
                <input class="form-control" id="no_hp" name="no_hp" inputmode="tel" maxlength="20"
                       aria-describedby="bantuan_hp_wali" value="<?= master_e(ah_old('no_hp', $record, '_wali_old')) ?>">
                <div class="form-text" id="bantuan_hp_wali">Boleh sama dengan wali lain; nomor HP bukan identitas unik.</div></div>
            <div class="col-md-5"><label class="form-label" for="alamat">Alamat</label>
                <input class="form-control" id="alamat" name="alamat" maxlength="200" value="<?= master_e(ah_old('alamat', $record, '_wali_old')) ?>"></div>
            <?php if ($selected && count($terdampak) > 1): ?>
                <div class="col-12">
                    <div class="ah-danger-zone">
                        <label class="d-inline-flex align-items-start gap-2 mb-0">
                            <input type="checkbox" name="konfirmasi_dampak" value="1" class="mt-1" required>
                            <span>Saya sudah memeriksa daftar santri terdampak di atas dan yakin perubahan identitas ini benar.</span>
                        </label>
                    </div>
                </div>
            <?php endif; ?>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Simpan</button>
                <a class="btn btn-outline-secondary" href="admin_wali.php">Batal</a>
            </div>
        </form>
    </div>
</section>
<?php ah_old_clear('_wali_old'); ?>
<?php endif; ?>

<?php if ($mode === 'detail' && $selected): ?>
<section class="ah-card" aria-labelledby="ah-detail-wali">
    <div class="ah-card__head"><span id="ah-detail-wali">Detail <?= master_e($selected['nama']) ?> (ID <?= (int) $selected['id'] ?>)</span>
        <a class="btn btn-sm btn-outline-secondary" href="?action=edit&amp;id=<?= (int) $selected['id'] ?>">Ubah</a></div>
    <div class="ah-card__body">
        <?php if (!empty($selected['merged_into_wali_id'])): ?>
            <?php ah_note('info', 'Identitas ini sudah digabungkan ke wali #' . (int) $selected['merged_into_wali_id'] . '.',
                '<p class="small mb-0 mt-2">Barisnya sengaja tidak dihapus agar ID lama tetap dapat ditelusuri dari laporan dan ekspor lama.</p>'); ?>
        <?php endif; ?>
        <div class="row g-3">
            <div class="col-md-4"><small class="text-muted d-block">Nomor HP</small><?= master_e($selected['no_hp'] ?: '-') ?></div>
            <div class="col-md-5"><small class="text-muted d-block">Alamat</small><?= master_e($selected['alamat'] ?: '-') ?></div>
            <div class="col-md-3"><small class="text-muted d-block">Status data</small><?= ah_state_badge($selected) ?></div>
        </div>

        <h2 class="h6 mt-4">Santri yang terhubung</h2>
        <?php if ($selected['relations'] === []): ?>
            <p class="text-muted">Belum ada relasi santri.</p>
        <?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table">
                <caption class="ah-visually-hidden">Santri yang terhubung dengan wali ini</caption>
                <thead><tr><th scope="col">NIS</th><th scope="col">Santri</th><th scope="col">Hubungan</th><th scope="col">Kontak utama</th><th scope="col">Status relasi</th><th scope="col">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($selected['relations'] as $relation): ?>
                    <tr>
                        <td><?= master_e($relation['nis']) ?></td>
                        <td><a href="admin_master_santri.php?action=detail&amp;id=<?= (int) $relation['santri_id'] ?>"><?= master_e($relation['nama_santri']) ?></a></td>
                        <td><?= master_e($relation['hubungan']) ?></td>
                        <td><?= (int) $relation['is_primary'] === 1 ? 'Ya' : 'Tidak' ?></td>
                        <td><?= $relation['archived_at'] ? ah_badge('Arsip', 'muted') : ah_badge('Aktif', 'ok') ?></td>
                        <td><?php if (!$relation['archived_at']): ?>
                            <form method="post" onsubmit="return confirm('Arsipkan relasi ini? Data wali dan santri tetap tersimpan, hanya hubungannya yang dinonaktifkan.')">
                                <?= master_csrf() ?>
                                <input type="hidden" name="id" value="<?= (int) $selected['id'] ?>">
                                <input type="hidden" name="relation_id" value="<?= (int) $relation['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" name="action" value="detach">Arsipkan relasi</button>
                            </form>
                        <?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>

        <h2 class="h6 mt-4">Tambahkan relasi santri</h2>
        <form method="post" class="row g-2">
            <?= master_csrf() ?>
            <input type="hidden" name="id" value="<?= (int) $selected['id'] ?>">
            <div class="col-md-5"><label class="form-label" for="santri_id">Santri</label>
                <select class="form-select" id="santri_id" name="santri_id" required>
                    <option value="">Pilih santri</option>
                    <?php foreach ($service->santriOptions() as $santri): ?>
                        <option value="<?= (int) $santri['id'] ?>"><?= master_e($santri['nis'] . ' — ' . $santri['nama_santri']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-3"><label class="form-label" for="hubungan">Hubungan</label>
                <input class="form-control" id="hubungan" name="hubungan" maxlength="30" placeholder="Ayah / Ibu / Wali" required></div>
            <div class="col-md-2 d-flex align-items-end">
                <label class="d-inline-flex align-items-center gap-2"><input type="checkbox" name="is_primary" value="1"> Kontak utama</label></div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary" name="action" value="attach">Hubungkan</button></div>
        </form>
    </div>
</section>
<?php endif; ?>

<form method="get" class="ah-card ah-no-print">
    <div class="ah-card__body">
        <fieldset class="ah-fieldset mb-0">
            <legend>Pencarian dan filter</legend>
            <div class="row g-2 align-items-end">
                <div class="col-md-6"><label class="form-label" for="q">Pencarian</label>
                    <input class="form-control" id="q" name="q" value="<?= master_e($filters['q']) ?>" placeholder="Cari nama atau nomor HP"></div>
                <div class="col-md-3"><label class="form-label" for="state">Status data</label>
                    <select class="form-select" id="state" name="state">
                        <?php foreach (['active' => 'Aktif', 'inactive' => 'Nonaktif', 'archived' => 'Arsip', 'all' => 'Semua'] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= $filters['state'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-3 d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">Terapkan</button>
                    <a class="btn btn-outline-secondary" href="admin_wali.php">Bersihkan</a></div>
            </div>
        </fieldset>
    </div>
</form>

<section class="ah-card" aria-labelledby="ah-daftar-wali">
    <div class="ah-card__head"><span id="ah-daftar-wali">Daftar orang tua/wali</span>
        <span class="text-muted small"><?= count($result['rows']) ?> dari <?= (int) $result['total'] ?> data</span></div>
    <?php if ($result['rows'] === []): ?>
        <div class="ah-card__body"><?= ah_empty('Tidak ada wali yang sesuai filter', 'Ubah kata kunci atau status data di atas.') ?></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table">
            <caption class="ah-visually-hidden">Daftar orang tua/wali sesuai filter</caption>
            <thead><tr><th scope="col">ID</th><th scope="col">Nama</th><th scope="col">HP</th><th scope="col">Santri terhubung</th><th scope="col">Status</th><th scope="col">Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $row): ?>
                <tr>
                    <td><?= (int) $row['id'] ?></td>
                    <td class="fw-semibold"><?= master_e($row['nama']) ?>
                        <?= !empty($row['merged_into_wali_id']) ? '<span class="ah-cell-sub">Digabungkan ke #' . (int) $row['merged_into_wali_id'] . '</span>' : '' ?></td>
                    <td><?= master_e($row['no_hp'] ?: '-') ?></td>
                    <td><?= master_e($row['santri'] ?: '—') ?></td>
                    <td><?= ah_state_badge($row) ?></td>
                    <td><div class="ah-actions">
                        <a class="btn btn-sm btn-outline-primary" href="?action=detail&amp;id=<?= (int) $row['id'] ?>">Detail</a>
                        <a class="btn btn-sm btn-outline-secondary" href="?action=edit&amp;id=<?= (int) $row['id'] ?>">Ubah</a>
                        <form method="post"><?= master_csrf() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary" name="action" value="<?= (int) $row['is_active'] === 1 ? 'deactivate' : 'activate' ?>"><?= (int) $row['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
                        <form method="post" onsubmit="return confirm('Arsipkan identitas wali ini? Relasi santri dan riwayat perizinan lama TIDAK dihapus.')">
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
