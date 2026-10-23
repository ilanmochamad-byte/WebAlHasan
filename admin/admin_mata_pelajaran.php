<?php

declare(strict_types=1);

use App\Penugasan\PenugasanException;
use App\Penugasan\PenugasanJenis;

/**
 * Penilaian & rapor › Mata pelajaran (redesain website Tahap 7, M-5).
 *
 * Pindah dari tab "Mata Pelajaran" Pusat Penugasan ke tempat mapel dipakai
 * (rencana nilai, nilai, rapor). Hanya admin (`admin/_guard.php`). Simpan
 * dan ubah status tetap `PenugasanService::simpanMataPelajaran()` /
 * `setMataPelajaranState()` (transaksi + audit, tanpa hapus permanen).
 * Kolom "Guru pengampu" menghitung penugasan guru mapel yang berlaku hari
 * ini dan menautkan ke Penugasan tersaring mapel itu.
 *
 * Alamat lama `admin_penugasan.php?jenis=mata_pelajaran` dialihkan ke sini
 * (GET); POST lamanya tetap diproses di sana.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = penugasan_service();
$halaman = 'admin_mata_pelajaran.php';
$aktorId = (int) $currentUser['id'];
$bucket = '_mapel_old';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $tujuan = $halaman;
    try {
        if ($action === 'mapel_simpan') {
            $mapelId = $service->simpanMataPelajaran($_POST, $id > 0 ? $id : null, $aktorId);
            ah_old_clear($bucket);
            master_flash('success', 'Mata pelajaran ' . ($service->mataPelajaran($mapelId)['nama'] ?? '') . ' tersimpan.');
        } elseif ($action === 'mapel_status') {
            $service->setMataPelajaranState($id, (string) ($_POST['status_action'] ?? ''), $aktorId);
            master_flash('success', 'Status mata pelajaran ' . ($service->mataPelajaran($id)['nama'] ?? '') . ' diperbarui tanpa menghapus riwayat.');
        } else {
            throw new PenugasanException('Aksi tidak dikenal.');
        }
    } catch (PenugasanException $exception) {
        if ($action === 'mapel_simpan') {
            ah_old_keep($_POST, ['kode', 'nama', 'kategori'], $bucket);
            $_SESSION[$bucket . '_errors'] = $exception->errors();
            $tujuan = $halaman . '?action=' . ($id > 0 ? 'edit&id=' . $id : 'create');
        }
        master_flash('danger', $exception->getMessage());
    } catch (RuntimeException $exception) {
        master_flash('danger', $exception->getMessage());
    }
    master_redirect($tujuan);
}

$q = App\Database\PageQuery::term($_GET['q'] ?? '');
$state = in_array($_GET['state'] ?? 'active', ['active', 'inactive', 'archived', 'all'], true) ? (string) ($_GET['state'] ?? 'active') : 'active';
$ringkas = $service->mataPelajaranRingkas();
$kategori = in_array($_GET['kategori'] ?? '', $ringkas['kategori'], true) ? (string) $_GET['kategori'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = $service->mataPelajaranSaring($q, $kategori, $state, $page);
$mode = (string) ($_GET['action'] ?? '');
$selected = isset($_GET['id']) ? $service->mataPelajaran((int) $_GET['id']) : null;
$tutup = $halaman . (($qs = ah_query(['action' => null, 'id' => null])) === '' ? '' : '?' . $qs);

master_header('Mata pelajaran', [
    'heading' => 'Penilaian & rapor',
    'active' => 'rapor',
    'description' => 'Daftar mata pelajaran yang dipakai rencana nilai, pengisian nilai, dan rapor.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Penilaian & rapor'], ['label' => 'Mata pelajaran']],
    'actions' => '<a class="btn btn-primary" href="?action=create"><i class="fas fa-plus" aria-hidden="true"></i> Tambah mapel</a>',
]);

if ($mode === 'create' || ($mode === 'edit' && $selected)):
    $r = array_replace($selected ?? [], is_array($_SESSION[$bucket] ?? null) ? $_SESSION[$bucket] : []);
    ah_panel_open(['title' => $selected ? 'Ubah ' . $selected['nama'] : 'Tambah mata pelajaran', 'close_url' => $tutup,
        'description' => $selected ? 'Perubahan nama tidak mengubah rapor yang sudah terbit.' : null]);
    echo ah_error_summary($bucket); ?>
    <form method="post">
        <?= master_csrf() ?><input type="hidden" name="action" value="mapel_simpan">
        <?php if ($selected): ?><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><?php endif; ?>
        <div class="mb-3"><label class="form-label" for="nama">Nama mata pelajaran <span class="text-danger" aria-hidden="true">*</span></label>
            <input class="form-control" id="nama" name="nama" maxlength="100" required value="<?= master_e($r['nama'] ?? '') ?>"><?= ah_field_error('nama', $bucket) ?></div>
        <div class="mb-3"><label class="form-label" for="kode">Kode <span class="text-muted fw-normal">(opsional)</span></label>
            <input class="form-control" id="kode" name="kode" maxlength="20" value="<?= master_e($r['kode'] ?? '') ?>" aria-describedby="kode-bantu">
            <p class="form-text" id="kode-bantu">Huruf, angka, titik, garis bawah, atau tanda hubung; maksimal 20.</p><?= ah_field_error('kode', $bucket) ?></div>
        <div class="mb-3"><label class="form-label" for="kategori">Kategori <span class="text-muted fw-normal">(opsional)</span></label>
            <input class="form-control" id="kategori" name="kategori" maxlength="30" list="daftar-kategori" value="<?= master_e($r['kategori'] ?? '') ?>"><?= ah_field_error('kategori', $bucket) ?>
            <datalist id="daftar-kategori"><?php foreach ($ringkas['kategori'] as $k): ?><option value="<?= master_e($k) ?>"></option><?php endforeach; ?></datalist></div>
        <div class="ah-actbar mb-0"><a class="btn btn-outline-secondary" href="<?= master_e($tutup) ?>">Batal</a><button class="btn btn-primary" type="submit">Simpan</button></div>
    </form>
    <?php ah_panel_close();
    ah_old_clear($bucket);
endif;

$chip = static fn (string $s): string => '?' . ah_query(['state' => $s, 'page' => null, 'action' => null, 'id' => null]);
echo ah_chips([
    ['label' => 'Aktif', 'url' => $chip('active'), 'active' => $state === 'active', 'count' => $ringkas['active']],
    ['label' => 'Nonaktif', 'url' => $chip('inactive'), 'active' => $state === 'inactive', 'count' => $ringkas['inactive']],
    ['label' => 'Arsip', 'url' => $chip('archived'), 'active' => $state === 'archived', 'count' => $ringkas['archived']],
], 'Status mata pelajaran');
ah_filter_open(($q !== '' ? 1 : 0) + ($kategori !== '' ? 1 : 0), ['state' => $state]); ?>
    <div class="ah-filter__wide"><label class="form-label" for="mapel-q">Cari kode atau nama</label>
        <input class="form-control" type="search" id="mapel-q" name="q" maxlength="100" value="<?= master_e($q) ?>"></div>
    <div><label class="form-label" for="mapel-kategori">Kategori</label>
        <select class="form-select" id="mapel-kategori" name="kategori"><option value="">Semua</option>
            <?php foreach ($ringkas['kategori'] as $k): ?><option value="<?= master_e($k) ?>" <?= $kategori === $k ? 'selected' : '' ?>><?= master_e($k) ?></option><?php endforeach; ?></select></div>
<?php ah_filter_close(['state' => $state]); ?>
<div class="ah-card ah-table-wrap">
    <table class="ah-table ah-table--cards" id="daftar-mapel">
        <caption class="ah-visually-hidden">Daftar mata pelajaran, <?= (int) $result['total'] ?> data</caption>
        <thead><tr><th scope="col">Kode</th><th scope="col">Nama</th><th scope="col">Kategori</th><th scope="col">Guru pengampu</th><th scope="col">Status</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $row): $mid = (int) $row['id']; $guru = (int) $row['guru_pengampu']; ?>
            <tr<?= $row['archived_at'] !== null ? ' class="ah-row-muted"' : '' ?>>
                <td data-label="Kode"><?= master_e($row['kode'] ?? '—') ?></td>
                <td data-label="Nama"><b><?= master_e($row['nama']) ?></b></td>
                <td data-label="Kategori"><?= master_e(($row['kategori'] ?? '') !== '' ? $row['kategori'] : '—') ?></td>
                <td data-label="Guru pengampu"><a href="admin_penugasan.php?jenis=<?= PenugasanJenis::GURU_MAPEL ?>&amp;mata_pelajaran_id=<?= $mid ?>"><?= $guru ?> guru</a></td>
                <td data-label="Status"><?= ah_state_badge($row) ?></td>
                <td data-label=""><?= ah_menu(array_values(array_filter([
                    ['label' => 'Ubah', 'url' => '?' . ah_query(['action' => 'edit', 'id' => $mid]), 'icon' => 'fa-pen'],
                    $row['archived_at'] === null ? ((int) $row['is_active'] === 1
                        ? ['label' => 'Nonaktifkan', 'icon' => 'fa-ban', 'action' => $halaman, 'fields' => ['id' => $mid, 'action' => 'mapel_status', 'status_action' => 'deactivate'],
                            'confirm' => ['title' => 'Nonaktifkan ' . $row['nama'] . '?', 'text' => 'Penugasan guru yang memakainya tidak dihapus, tetapi pengisian nilai mapel ini tidak tersedia selama nonaktif. Rapor terbit tidak berubah.', 'ok' => 'Nonaktifkan']]
                        : ['label' => 'Aktifkan', 'icon' => 'fa-check', 'action' => $halaman, 'fields' => ['id' => $mid, 'action' => 'mapel_status', 'status_action' => 'activate'],
                            'confirm' => ['title' => 'Aktifkan ' . $row['nama'] . '?', 'text' => 'Mapel kembali dapat dipilih di rencana dan penugasan.', 'ok' => 'Aktifkan']]) : null,
                    $row['archived_at'] === null
                        ? ['label' => 'Arsipkan', 'icon' => 'fa-box-archive', 'danger' => true, 'action' => $halaman, 'fields' => ['id' => $mid, 'action' => 'mapel_status', 'status_action' => 'archive'],
                            'confirm' => ['title' => 'Arsipkan ' . $row['nama'] . '?', 'text' => 'Mapel disembunyikan dari pilihan. Riwayat penugasan, nilai, dan rapor tetap tersimpan.', 'ok' => 'Arsipkan']]
                        : ['label' => 'Pulihkan', 'icon' => 'fa-rotate-left', 'action' => $halaman, 'fields' => ['id' => $mid, 'action' => 'mapel_status', 'status_action' => 'restore'],
                            'confirm' => ['title' => 'Pulihkan ' . $row['nama'] . '?', 'text' => 'Mapel kembali aktif.', 'ok' => 'Pulihkan']],
                ])), 'Tindakan untuk ' . $row['nama']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($result['rows'] === []): ?><tr><td colspan="6" data-label=""><?= $q !== '' || $kategori !== '' ? ah_empty('Tidak ada hasil', 'Ubah pencarian atau kategori.') : ah_empty('Belum ada mata pelajaran', 'Tambahkan mata pelajaran sebelum menyusun rencana nilai dan menugaskan guru.') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php master_pagination((int) $result['total'], (int) $result['page'], 20); master_footer(); ?>
