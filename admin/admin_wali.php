<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;

/**
 * Data santri › Orang tua/wali (redesain website Tahap 6, D-6).
 *
 * Satu identitas wali dapat terhubung ke beberapa santri (saudara kandung).
 * Klik wali membuka panel: data, akun, santri terhubung, dan tambah relasi.
 * Tombol "Rekonsiliasi wali" di kepala halaman menyebut jumlah hal yang perlu
 * ditinjau dan membuka D-7 (keputusan D: bukan menu atau tab tersendiri).
 *
 * Mengubah identitas yang dipakai bersama tetap menampilkan daftar santri
 * terdampak dan meminta konfirmasi. Halaman ini tidak membuat akun login.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = master_data_service();
$halaman = 'admin_wali.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            if (!ah_form_token_consume('wali', $_POST['form_token'] ?? null)) {
                master_flash('warning', 'Formulir ini sudah pernah dikirim. Tidak ada data ganda yang dibuat.');
                master_redirect($halaman);
            }
            $savedId = $service->saveWali($_POST, $id ?: null);
            ah_old_clear('_wali_old');
            master_flash('success', 'Data ' . ($service->wali($savedId)['nama'] ?? 'orang tua/wali') . ' tersimpan.');
            master_redirect($halaman . '?action=detail&id=' . $savedId);
        } elseif ($action === 'attach') {
            $service->attachWali($id, $_POST, (int) $currentUser['id']);
            master_flash('success', 'Santri berhasil dihubungkan.');
        } elseif ($action === 'detach') {
            $service->detachWali($id, (int) ($_POST['relation_id'] ?? 0));
            master_flash('success', 'Relasi diarsipkan; data wali dan santri tetap tersimpan.');
        } else {
            $service->setWaliState($id, $action);
            master_flash('success', 'Status wali diperbarui tanpa menghapus relasi atau riwayat.');
        }
    } catch (MasterDataException $exception) {
        if ($action === 'save') { ah_validation_keep($_POST, ['nama', 'no_hp', 'alamat'], $exception, '_wali_old'); }
        if ($action === 'attach') { ah_validation_keep($_POST, ['santri_id', 'hubungan', 'is_primary'], $exception, '_wali_attach'); }
        master_flash('danger', $exception->getMessage());
        if ($action === 'save') {
            master_redirect($halaman . '?action=' . ($id > 0 ? 'edit&id=' . $id : 'create'));
        }
    }
    master_redirect($halaman . ($id > 0 && in_array($action, ['attach', 'detach'], true) ? '?action=detail&id=' . $id : ''));
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = [
    'q' => is_string($_GET['q'] ?? null) ? $_GET['q'] : '',
    'state' => in_array($_GET['state'] ?? 'active', ['active', 'inactive', 'archived', 'all'], true) ? (string) ($_GET['state'] ?? 'active') : 'active',
];
$result = $service->waliList($filters, $page);
$jumlah = $service->stateCounts('wali', $filters['q']);
$selected = isset($_GET['id']) ? $service->wali((int) $_GET['id']) : null;
$mode = (string) ($_GET['action'] ?? '');
$rekon = $service->reconciliationSummary();
$tutup = $halaman . (($q = ah_query(['action' => null, 'id' => null])) === '' ? '' : '?' . $q);

master_header('Orang tua/wali', [
    'active' => 'santri',
    'heading' => 'Data santri',
    'description' => 'Satu identitas wali dapat terhubung ke beberapa santri. Identitas inilah sumber utama data orang tua.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Data santri', 'url' => app_url('/admin/admin_master_santri.php')], ['label' => 'Orang tua/wali']],
    'actions' => '<a class="btn btn-outline-primary" href="admin_wali_rekonsiliasi.php">Rekonsiliasi wali'
        . ($rekon['total'] > 0 ? ' <span class="ah-chips__count">' . number_format($rekon['total'], 0, ',', '.') . '</span>' : '') . '</a>'
        . '<a class="btn btn-primary" href="?action=create"><i class="fas fa-plus" aria-hidden="true"></i> Tambah wali</a>',
]);

// --- Panel tambah/ubah ------------------------------------------------------
if ($mode === 'create' || ($mode === 'edit' && $selected)):
    $record = $selected ?? [];
    $terdampak = $selected ? array_values(array_filter($selected['relations'], static fn (array $r): bool => $r['archived_at'] === null)) : [];
    ah_panel_open(['title' => $selected ? 'Ubah ' . $selected['nama'] : 'Tambah wali', 'close_url' => $selected ? $halaman . '?action=detail&id=' . (int) $selected['id'] : $tutup]);
    echo ah_error_summary('_wali_old');
    if ($selected && count($terdampak) > 1) {
        ah_note('warning', 'Identitas ini dipakai bersama oleh ' . count($terdampak) . ' santri. Perubahan nama atau nomor HP berlaku untuk semuanya.',
            '<ul class="small mb-0 mt-2"><li>' . implode('</li><li>', array_map(static fn (array $r): string => ah_e($r['nama_santri'] . ' (' . $r['hubungan'] . ')'), $terdampak)) . '</li></ul>');
    } ?>
    <form method="post">
        <?= master_csrf() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="form_token" value="<?= master_e(ah_form_token('wali')) ?>">
        <?php if ($selected): ?><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><?php endif; ?>
        <div class="mb-3"><label class="form-label" for="nama">Nama <span class="text-danger" aria-hidden="true">*</span></label>
            <input class="form-control" id="nama" name="nama" maxlength="100" required value="<?= master_e(ah_old('nama', $record, '_wali_old')) ?>"><?= ah_field_error('nama', '_wali_old') ?></div>
        <div class="mb-3"><label class="form-label" for="no_hp">Nomor HP</label>
            <input class="form-control" id="no_hp" name="no_hp" inputmode="tel" maxlength="20" aria-describedby="bantuan_hp_wali" value="<?= master_e(ah_old('no_hp', $record, '_wali_old')) ?>"><?= ah_field_error('no_hp', '_wali_old') ?>
            <div class="form-text" id="bantuan_hp_wali">Boleh sama dengan wali lain; nomor HP bukan identitas unik.</div></div>
        <div class="mb-3"><label class="form-label" for="alamat">Alamat</label>
            <textarea class="form-control" id="alamat" name="alamat" rows="2" maxlength="2000"><?= master_e(ah_old('alamat', $record, '_wali_old')) ?></textarea><?= ah_field_error('alamat', '_wali_old') ?></div>
        <?php if ($selected && count($terdampak) > 1): ?>
            <div class="ah-danger-zone mb-3"><label class="d-inline-flex align-items-start gap-2 mb-0">
                <input type="checkbox" name="konfirmasi_dampak" value="1" class="mt-1" required>
                <span>Saya sudah memeriksa santri terdampak dan yakin perubahan identitas ini benar.</span></label></div>
        <?php endif; ?>
        <div class="ah-actbar mb-0"><a class="btn btn-outline-secondary" href="<?= master_e($selected ? $halaman . '?action=detail&id=' . (int) $selected['id'] : $tutup) ?>">Batal</a>
            <button class="btn btn-primary" type="submit">Simpan</button></div>
    </form>
    <?php ah_panel_close();
    ah_old_clear('_wali_old');
endif;

// --- Panel detail -----------------------------------------------------------
if ($mode === 'detail' && $selected):
    $akun = $service->linkedAccounts('wali_id', (int) $selected['id']);
    ah_panel_open(['title' => (string) $selected['nama'], 'close_url' => $tutup]);
    if (!empty($selected['merged_into_wali_id'])) {
        $tujuan = $service->wali((int) $selected['merged_into_wali_id']);
        ah_note('info', 'Identitas ini sudah digabungkan ke ' . ($tujuan['nama'] ?? 'wali lain') . '.',
            '<p class="small mb-0 mt-2">Barisnya sengaja tidak dihapus agar laporan dan ekspor lama tetap dapat ditelusuri. '
            . ($tujuan ? '<a href="?action=detail&amp;id=' . (int) $tujuan['id'] . '">Buka identitas tujuan</a>' : '') . '</p>');
    } ?>
    <dl class="ah-kv">
        <div><dt>Nomor HP</dt><dd><?= master_e($selected['no_hp'] ?: '—') ?></dd></div>
        <div><dt>Alamat</dt><dd><?= master_e($selected['alamat'] ?: '—') ?></dd></div>
        <div><dt>Status</dt><dd><?= ah_state_badge($selected) ?></dd></div>
        <div><dt>Akun</dt><dd><?php if ($akun === []): ?>Belum ada · <a href="admin_akun.php?wali_id=<?= (int) $selected['id'] ?>#ah-buat-ortu">Buat akun</a>
            <?php else: foreach ($akun as $a): ?>@<?= master_e($a['username']) ?><?= (int) $a['is_active'] === 1 ? '' : ' (nonaktif)' ?><?= $a['last_login_at'] ? ' · masuk ' . master_e(ah_tanggal((string) $a['last_login_at'], false)) : ' · belum pernah masuk' ?><br><?php endforeach; endif; ?></dd></div>
    </dl>
    <div class="d-flex flex-wrap gap-2 mb-3"><a class="btn btn-sm btn-outline-primary" href="?action=edit&amp;id=<?= (int) $selected['id'] ?>"><i class="fas fa-pen" aria-hidden="true"></i> Ubah</a></div>

    <h3 class="h6">Santri terhubung</h3>
    <?php if ($selected['relations'] === []): ?>
        <p class="text-muted">Belum ada santri terhubung.</p>
    <?php else: ?>
        <ul class="ah-mini-list mb-3">
        <?php foreach ($selected['relations'] as $relation): ?>
            <li><span class="ah-mini-list__row">
                <span class="ah-mini-list__main"><b><a href="admin_master_santri.php?action=detail&amp;id=<?= (int) $relation['santri_id'] ?>"><?= master_e($relation['nama_santri']) ?></a></b>
                    <small><?= master_e($relation['hubungan']) ?><?= (int) $relation['is_primary'] === 1 ? ' · kontak utama' : '' ?></small></span>
                <?= $relation['archived_at'] ? ah_badge('Arsip', 'muted') : ah_badge('Aktif', 'ok') ?>
                <?php if (!$relation['archived_at']): ?><?= ah_menu([
                    ['label' => 'Arsipkan relasi', 'icon' => 'fa-link-slash', 'danger' => true, 'action' => $halaman, 'fields' => ['id' => (int) $selected['id'], 'relation_id' => (int) $relation['id'], 'action' => 'detach'],
                        'confirm' => ['title' => 'Arsipkan relasi dengan ' . $relation['nama_santri'] . '?', 'text' => 'Data wali dan santri tetap tersimpan, hanya hubungannya yang dinonaktifkan.', 'ok' => 'Arsipkan relasi']],
                ], 'Tindakan relasi ' . $relation['nama_santri']) ?><?php endif; ?>
            </span></li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <details class="ah-fold"<?= isset($_SESSION['_wali_attach_errors']) ? ' open' : '' ?>><summary class="btn btn-sm btn-outline-primary">+ Hubungkan santri</summary>
        <form method="post" class="mt-2">
            <?= master_csrf() ?>
            <input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="action" value="attach">
            <div class="mb-2"><label class="form-label" for="santri_id">Santri</label>
                <input class="form-control mb-2" type="search" hidden data-ah-option-filter="santri_id" placeholder="Ketik nama atau NIS untuk menyaring" aria-label="Saring daftar santri" autocomplete="off">
                <select class="form-select" id="santri_id" name="santri_id" required>
                    <option value="">Pilih santri</option>
                    <?php foreach ($service->santriOptions() as $santri): ?>
                        <option value="<?= (int) $santri['id'] ?>" <?= ah_old('santri_id', null, '_wali_attach') === (string) $santri['id'] ? 'selected' : '' ?>><?= master_e($santri['nama_santri'] . ' — NIS ' . $santri['nis']) ?></option>
                    <?php endforeach; ?>
                </select><?= ah_field_error('santri_id', '_wali_attach') ?></div>
            <div class="mb-2"><label class="form-label" for="hubungan">Hubungan</label>
                <input class="form-control" id="hubungan" name="hubungan" list="hubungan-pilihan" value="<?= ah_e(ah_old('hubungan', null, '_wali_attach')) ?>" maxlength="30" required><?= ah_field_error('hubungan', '_wali_attach') ?>
                <datalist id="hubungan-pilihan"><option value="Ayah"><option value="Ibu"><option value="Wali"></datalist></div>
            <label class="d-inline-flex align-items-center gap-2 mb-2"><input type="checkbox" name="is_primary" value="1" <?= ah_old('is_primary', null, '_wali_attach') === '1' ? 'checked' : '' ?>> Kontak utama</label>
            <div><button class="btn btn-primary" type="submit">Hubungkan</button></div>
        </form>
    </details>
    <?php ah_panel_close();
endif;

// --- Daftar -----------------------------------------------------------------
$chip = static fn (string $state): string => '?' . ah_query(['state' => $state, 'page' => null, 'action' => null, 'id' => null]);
echo ah_chips([
    ['label' => 'Aktif', 'url' => $chip('active'), 'active' => $filters['state'] === 'active', 'count' => $jumlah['active']],
    ['label' => 'Nonaktif', 'url' => $chip('inactive'), 'active' => $filters['state'] === 'inactive', 'count' => $jumlah['inactive']],
    ['label' => 'Arsip', 'url' => $chip('archived'), 'active' => $filters['state'] === 'archived', 'count' => $jumlah['archived']],
], 'Status wali');
ah_filter_open($filters['q'] !== '' ? 1 : 0, ['state' => $filters['state']]); ?>
    <div class="ah-filter__wide"><label class="form-label" for="wali-q">Cari nama atau HP</label>
        <input class="form-control" type="search" id="wali-q" name="q" maxlength="100" value="<?= master_e($filters['q']) ?>"></div>
<?php ah_filter_close(['state' => $filters['state']]); ?>
<div class="ah-card ah-table-wrap">
    <table class="ah-table ah-table--cards" id="daftar-wali">
        <caption class="ah-visually-hidden">Daftar orang tua/wali, <?= (int) $result['total'] ?> data</caption>
        <thead><tr><th scope="col">Nama</th><th scope="col">HP</th><th scope="col">Santri terhubung</th><th scope="col">Status</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $row): $wid = (int) $row['id']; ?>
            <tr<?= $row['archived_at'] !== null ? ' class="ah-row-muted"' : '' ?>>
                <td><a class="ah-row-link" href="?<?= master_e(ah_query(['action' => 'detail', 'id' => $wid])) ?>"><?= master_e($row['nama']) ?></a>
                    <?= !empty($row['merged_into_wali_id']) ? '<span class="ah-cell-sub">Sudah digabungkan</span>' : '' ?></td>
                <td><?= master_e($row['no_hp'] ?: '—') ?></td>
                <td><span class="ah-cell-wrap"><?= master_e($row['santri'] ?: '—') ?></span></td>
                <td><?= ah_state_badge($row) ?></td>
                <td data-label=""><?= ah_menu(array_values(array_filter([
                    ['label' => 'Lihat', 'url' => '?' . ah_query(['action' => 'detail', 'id' => $wid]), 'icon' => 'fa-eye'],
                    ['label' => 'Ubah', 'url' => '?' . ah_query(['action' => 'edit', 'id' => $wid]), 'icon' => 'fa-pen'],
                    $row['archived_at'] === null ? ((int) $row['is_active'] === 1
                        ? ['label' => 'Nonaktifkan', 'icon' => 'fa-user-slash', 'action' => $halaman, 'fields' => ['id' => $wid, 'action' => 'deactivate'],
                            'confirm' => ['title' => 'Nonaktifkan ' . $row['nama'] . '?', 'text' => 'Status aktif memengaruhi kelayakan hubungan akun orang tua. Data santri, relasi, dan riwayat lama tetap tersimpan.', 'ok' => 'Nonaktifkan']]
                        : ['label' => 'Aktifkan', 'icon' => 'fa-user-check', 'action' => $halaman, 'fields' => ['id' => $wid, 'action' => 'activate'],
                            'confirm' => ['title' => 'Aktifkan ' . $row['nama'] . '?', 'text' => 'Wali kembali aktif.', 'ok' => 'Aktifkan']]) : null,
                    $row['archived_at'] === null
                        ? ['label' => 'Arsipkan', 'icon' => 'fa-box-archive', 'danger' => true, 'action' => $halaman, 'fields' => ['id' => $wid, 'action' => 'archive'],
                            'confirm' => ['title' => 'Arsipkan ' . $row['nama'] . '?', 'text' => 'Wali disembunyikan dari daftar aktif. Relasi santri dan riwayat perizinan lama TIDAK dihapus.', 'ok' => 'Arsipkan']]
                        : ['label' => 'Pulihkan', 'icon' => 'fa-rotate-left', 'action' => $halaman, 'fields' => ['id' => $wid, 'action' => 'restore'],
                            'confirm' => ['title' => 'Pulihkan ' . $row['nama'] . '?', 'text' => 'Wali kembali ke daftar.', 'ok' => 'Pulihkan']],
                ])), 'Tindakan untuk ' . $row['nama']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($result['rows'] === []): ?>
            <tr><td colspan="5" data-label=""><?= ah_empty('Tidak ada wali yang cocok', 'Ubah kata kunci atau status di atas.') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php master_pagination((int) $result['total'], $page, 20); ah_old_clear('_wali_attach'); master_footer(); ?>
