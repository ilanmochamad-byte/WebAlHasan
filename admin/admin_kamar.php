<?php

declare(strict_types=1);

use App\Database\PageQuery;
use App\MasterData\MasterDataException;

/**
 * Data pesantren › Kelas & kobong › tab Kamar/kobong (redesain website
 * Tahap 6, M-2).
 *
 * Kartu kamar dengan hunian, bilah keterisian, dan label Asrama putra/putri
 * (keputusan AE, migrasi 023); tampilan tabel tersedia. Kamar lama yang belum
 * diisi asramanya masuk chip "Belum ditentukan" sampai admin melengkapinya.
 * Klik kamar membuka panel penghuni dengan tombol "Tempatkan santri" menuju
 * Data santri › Penempatan.
 *
 * Kamar tidak punya status aktif/arsip di basis data, jadi tidak ada tombol
 * Nonaktifkan/Arsipkan, dan penghapusan tetap tidak tersedia (A-11).
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

// A-11: data/riwayat kamar tidak dihapus, termasuk melalui URL lama.
if (isset($_GET['hapus'])) {
    http_response_code(405);
    exit('Penghapusan kamar tidak tersedia. Data dan riwayat kamar tetap dipertahankan.');
}
$service = master_data_service();
$halaman = 'admin_kamar.php';
$asramaAda = $service->asramaTersedia();
$mode = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$selected = $id === null ? null : $service->room($id);
$error = null;
$form = $selected ?? ['nama_kamar' => '', 'kapasitas' => '', 'asrama' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') !== 'save' && !isset($_POST['tambah']) && !isset($_POST['edit'])) {
            throw new MasterDataException('Aksi kamar tidak dikenal.');
        }
        $id = (isset($_POST['id']) || isset($_POST['edit']))
            ? filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
        if ($id === false) { throw new MasterDataException('ID kamar tidak valid.'); }
        $saved = $service->saveRoom($_POST, $id);
        master_flash('success', 'Kamar ' . ($service->room($saved)['nama_kamar'] ?? '') . ' tersimpan. Penempatan santri dan riwayatnya tidak berubah.');
        master_redirect($halaman . '?action=detail&id=' . $saved);
    } catch (MasterDataException $exception) {
        http_response_code(422);
        $error = $exception->getMessage();
        ah_validation_keep($_POST, ['nama_kamar', 'kapasitas', 'asrama'], $exception, '_room_error');
        $form = [
            'nama_kamar' => is_scalar($_POST['nama_kamar'] ?? null) ? (string) $_POST['nama_kamar'] : '',
            'kapasitas' => is_scalar($_POST['kapasitas'] ?? null) ? (string) $_POST['kapasitas'] : '',
            'asrama' => is_scalar($_POST['asrama'] ?? null) ? (string) $_POST['asrama'] : '',
        ];
        $mode = $id ? 'edit' : 'create';
        $selected = $id ? $service->room((int) $id) : null;
    }
}
$year = $koneksi->query("SELECT id, tahun, semester FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1")->fetch_assoc();
$yearId = $year ? (int) $year['id'] : 0;
$q = PageQuery::term($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$asrama = in_array($_GET['asrama'] ?? '', ['Putra', 'Putri', 'belum'], true) ? (string) $_GET['asrama'] : '';
$tampilan = ($_GET['tampilan'] ?? '') === 'tabel' ? 'tabel' : 'kartu';
if (in_array($mode, ['edit', 'detail'], true) && $selected === null && $error === null) {
    http_response_code(404);
    $error = 'Kamar tidak ditemukan. Kembali ke daftar untuk memilih kamar yang tersedia.';
    $mode = '';
}
$result = $service->roomsPage($q, $page, $yearId, $asrama, 24);
$page = (int) $result['page'];
$ringkasan = $service->roomSummary($yearId);
$tutup = $halaman . (($qs = ah_query(['action' => null, 'id' => null, 'page' => null])) === '' ? '' : '?' . $qs);

master_header('Kelas & kobong', [
    'active' => 'kelas',
    'description' => 'Tempat santri tinggal pada semester aktif' . ($year ? ' (' . $year['semester'] . ' ' . $year['tahun'] . ')' : '') . '.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Data pesantren'], ['label' => 'Kelas & kobong']],
    'actions' => '<a class="btn btn-primary" href="?action=create"><i class="fas fa-plus" aria-hidden="true"></i> Tambah kamar</a>',
]);
if ($error !== null && $mode === '') { ah_note('danger', $error); }
if (!$asramaAda) {
    ah_note('info', 'Label asrama putra/putri belum tersedia karena migrasi basis data Tahap 6 belum dijalankan.');
}

/** @param array<string, mixed> $kamar */
$labelAsrama = static function (array $kamar) use ($asramaAda): string {
    if (!$asramaAda) {
        return '';
    }
    return in_array($kamar['asrama'] ?? null, ['Putra', 'Putri'], true) ? ah_badge('Asrama ' . mb_strtolower((string) $kamar['asrama']), 'info') : ah_badge('Asrama belum ditentukan', 'warn');
};
/** @param array<string, mixed> $kamar */
$tidakSesuai = static function (array $kamar): int {
    return match ($kamar['asrama'] ?? null) {
        'Putra' => (int) ($kamar['penghuni_p'] ?? 0),
        'Putri' => (int) ($kamar['penghuni_l'] ?? 0),
        default => 0,
    };
};

// --- Panel formulir ---------------------------------------------------------
if ($mode === 'create' || ($mode === 'edit' && $selected !== null)):
    ah_panel_open(['title' => $id ? 'Ubah ' . ($selected['nama_kamar'] ?? 'kamar') : 'Tambah kamar', 'close_url' => $id ? $halaman . '?action=detail&id=' . (int) $id : $tutup]);
    if ($error !== null) { ah_note('danger', $error); } ?>
    <form method="post" action="admin_kamar.php<?= $id ? '?action=edit&amp;id=' . (int) $id : '?action=create' ?>">
        <?= master_csrf() ?><input type="hidden" name="action" value="save">
        <?php if ($id): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="mb-3"><label class="form-label" for="nama_kamar">Nama kamar/kobong <span class="text-danger" aria-hidden="true">*</span></label>
            <input class="form-control" id="nama_kamar" name="nama_kamar" maxlength="50" required value="<?= master_e($form['nama_kamar']) ?>"><?= ah_field_error('nama_kamar', '_room_error') ?></div>
        <div class="mb-3"><label class="form-label" for="kapasitas">Kapasitas (orang) <span class="text-danger" aria-hidden="true">*</span></label>
            <input class="form-control" id="kapasitas" name="kapasitas" type="number" min="1" max="2147483647" required value="<?= master_e($form['kapasitas']) ?>"><?= ah_field_error('kapasitas', '_room_error') ?>
            <div class="form-text">Mengubah kapasitas tidak memindahkan atau mengeluarkan penghuni.</div></div>
        <?php if ($asramaAda): ?>
            <fieldset class="mb-3"><legend class="form-label">Asrama <span class="text-danger" aria-hidden="true">*</span></legend>
                <?php foreach (['Putra', 'Putri'] as $pilihan): ?>
                    <label class="d-inline-flex align-items-center gap-2 me-3"><input type="radio" name="asrama" value="<?= $pilihan ?>" required <?= ($form['asrama'] ?? '') === $pilihan ? 'checked' : '' ?>> <?= $pilihan ?></label>
                <?php endforeach; ?>
                <?= ah_field_error('asrama', '_room_error') ?>
                <div class="form-text">Penempatan santri yang tidak sesuai asrama memunculkan peringatan dan harus dikonfirmasi beralasan.</div>
            </fieldset>
        <?php endif; ?>
        <div class="ah-actbar mb-0"><a class="btn btn-outline-secondary" href="<?= master_e($id ? $halaman . '?action=detail&id=' . (int) $id : $tutup) ?>">Batal</a><button class="btn btn-primary" type="submit">Simpan kamar</button></div>
    </form>
    <?php ah_panel_close();
endif;

// --- Panel penghuni ---------------------------------------------------------
if ($mode === 'detail' && $selected !== null):
    $pq = PageQuery::term($_GET['cari'] ?? '');
    $penghuni = $service->roomOccupantsPage((int) $selected['id'], $yearId, $pq, max(1, (int) ($_GET['hal'] ?? 1)));
    $kamarTerhitung = null;
    foreach ($service->roomsPage((string) $selected['nama_kamar'], 1, $yearId, '', 100)['rows'] as $k) {
        if ((int) $k['id'] === (int) $selected['id']) { $kamarTerhitung = $k; break; }
    }
    $terisi = (int) ($kamarTerhitung['terisi'] ?? $penghuni['total']);
    ah_panel_open(['title' => (string) $selected['nama_kamar'], 'close_url' => $tutup, 'description' => $terisi . ' dari ' . (int) $selected['kapasitas'] . ' tempat terisi pada semester aktif']); ?>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <?= $labelAsrama($selected) ?>
        <?php if ($kamarTerhitung !== null && $tidakSesuai($kamarTerhitung) > 0): ?><?= ah_badge($tidakSesuai($kamarTerhitung) . ' penghuni tidak sesuai asrama', 'warn') ?><?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a class="btn btn-primary" href="admin_penempatan_santri.php?status=tanpa_kamar">Tempatkan santri</a>
        <a class="btn btn-outline-primary" href="admin_penempatan_santri.php?kamar_id=<?= (int) $selected['id'] ?>">Kelola penghuni</a>
        <a class="btn btn-outline-secondary" href="?<?= master_e(ah_query(['action' => 'edit', 'id' => (int) $selected['id']])) ?>"><i class="fas fa-pen" aria-hidden="true"></i> Ubah kamar</a>
    </div>
    <form method="get" class="mb-2"><input type="hidden" name="action" value="detail"><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>">
        <label class="form-label" for="cari-penghuni">Cari penghuni</label>
        <div class="d-flex gap-2"><input class="form-control" type="search" id="cari-penghuni" name="cari" maxlength="100" value="<?= master_e($pq) ?>"><button class="btn btn-outline-secondary" type="submit">Cari</button></div></form>
    <?php if ($penghuni['rows'] === []): ?>
        <p class="text-muted"><?= $year ? 'Belum ada penghuni yang cocok.' : 'Atur semester aktif untuk melihat penghuni.' ?></p>
    <?php else: ?>
        <ul class="ah-mini-list" id="room-occupants">
            <?php foreach ($penghuni['rows'] as $row): $beda = \App\MasterData\PenempatanService::asramaTidakSesuai((string) $row['jenis_kelamin'], $selected['asrama'] ?? null); ?>
                <li><span class="ah-mini-list__row"><span class="ah-mini-list__main"><b><?= master_e($row['nama_santri']) ?></b><small>NIS <?= master_e($row['nis']) ?> · <?= master_e($row['jenis_kelamin']) ?><?= $row['sekolah_saat_ini'] ? ' · ' . master_e($row['sekolah_saat_ini']) : '' ?></small></span>
                    <?= $beda ? ah_badge('Tidak sesuai asrama', 'warn') : '' ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php if ((int) $penghuni['total'] > (int) $penghuni['perPage']): ?><p class="small text-muted"><?= (int) $penghuni['total'] ?> penghuni; persempit dengan pencarian.</p><?php endif; ?>
    <?php endif; ?>
    <?php ah_panel_close();
endif;

// --- Ringkasan, chip, dan daftar ---------------------------------------------
echo ah_stats([
    ['label' => 'Kamar', 'value' => number_format($ringkasan['kamar'], 0, ',', '.')],
    ['label' => 'Kapasitas', 'value' => number_format($ringkasan['kapasitas'], 0, ',', '.')],
    ['label' => 'Terisi', 'value' => number_format($ringkasan['terisi'], 0, ',', '.'), 'hint' => $ringkasan['kapasitas'] > 0 ? round(100 * $ringkasan['terisi'] / $ringkasan['kapasitas']) . '% hunian' : null],
    ['label' => 'Penuh/hampir penuh', 'value' => (string) $ringkasan['hampir_penuh'], 'warn' => $ringkasan['hampir_penuh'] > 0, 'hint' => 'Terisi ≥ 90% kapasitas'],
], 'Ringkasan kamar semester aktif');
if ($asramaAda) {
    $chip = static fn (string $a): string => '?' . ah_query(['asrama' => $a === '' ? null : $a, 'page' => null, 'action' => null, 'id' => null]);
    echo ah_chips([
        ['label' => 'Semua', 'url' => $chip(''), 'active' => $asrama === '', 'count' => $ringkasan['kamar']],
        ['label' => 'Putra', 'url' => $chip('Putra'), 'active' => $asrama === 'Putra', 'count' => $ringkasan['putra']],
        ['label' => 'Putri', 'url' => $chip('Putri'), 'active' => $asrama === 'Putri', 'count' => $ringkasan['putri']],
        ['label' => 'Belum ditentukan', 'url' => $chip('belum'), 'active' => $asrama === 'belum', 'count' => $ringkasan['belum']],
    ], 'Asrama kamar');
}
ah_filter_open($q !== '' ? 1 : 0, ['asrama' => $asrama, 'tampilan' => $tampilan === 'tabel' ? 'tabel' : '']); ?>
    <div class="ah-filter__wide"><label class="form-label" for="kamar-q">Cari nama kamar</label>
        <input class="form-control" type="search" id="kamar-q" name="q" maxlength="100" value="<?= master_e($q) ?>"></div>
<?php ah_filter_close(['asrama' => $asrama]);
echo ah_seg([
    ['label' => 'Kartu', 'url' => '?' . ah_query(['tampilan' => null, 'action' => null, 'id' => null]), 'active' => $tampilan === 'kartu'],
    ['label' => 'Tabel', 'url' => '?' . ah_query(['tampilan' => 'tabel', 'action' => null, 'id' => null]), 'active' => $tampilan === 'tabel'],
], 'Tampilan kamar');

$keadaan = static function (array $row): array {
    $kap = max(0, (int) $row['kapasitas']);
    $isi = (int) $row['terisi'];
    $persen = $kap > 0 ? (int) min(100, round(100 * $isi / $kap)) : 100;
    return [$persen, $kap > 0 && $isi >= $kap ? 'Penuh' : ($kap > 0 && $isi * 10 >= $kap * 9 ? 'Hampir penuh' : 'Tersedia')];
};
if ($result['rows'] === []):
    echo ah_empty('Tidak ada kamar yang cocok', 'Bersihkan pencarian atau saringan, atau tambahkan kamar.', '<a class="btn btn-sm btn-primary" href="?action=create">Tambah kamar</a>');
elseif ($tampilan === 'kartu'): ?>
    <div class="ah-cards" id="room-list">
        <?php foreach ($result['rows'] as $row): [$persen, $label] = $keadaan($row); $beda = $tidakSesuai($row); ?>
            <a class="ah-icard ah-room" href="?<?= master_e(ah_query(['action' => 'detail', 'id' => (int) $row['id']])) ?>">
                <span class="ah-icard__head"><span><?= master_e($row['nama_kamar']) ?></span><?= ah_badge($label, $label === 'Penuh' ? 'danger' : ($label === 'Hampir penuh' ? 'warn' : 'ok')) ?></span>
                <span class="ah-icard__meta"><?= (int) $row['terisi'] ?>/<?= (int) $row['kapasitas'] ?> terisi</span>
                <span class="ah-room__bar<?= $label === 'Penuh' ? ' ah-room__bar--full' : ($label === 'Hampir penuh' ? ' ah-room__bar--warn' : '') ?>" role="img" aria-label="Keterisian <?= $persen ?> persen"><span style="width:<?= $persen ?>%"></span></span>
                <span class="d-flex flex-wrap gap-1"><?= $labelAsrama($row) ?><?= $beda > 0 ? ah_badge($beda . ' tidak sesuai', 'warn') : '' ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="ah-card ah-table-wrap"><table class="ah-table ah-table--cards" id="room-list">
        <caption class="ah-visually-hidden">Daftar kamar dan keterisian semester aktif</caption>
        <thead><tr><th scope="col">Nama kamar/kobong</th><th scope="col">Terisi / kapasitas</th><th scope="col">Keadaan</th><?php if ($asramaAda): ?><th scope="col">Asrama</th><?php endif; ?><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
        <tbody><?php foreach ($result['rows'] as $row): [, $label] = $keadaan($row); ?><tr>
            <td><a class="ah-row-link" href="?<?= master_e(ah_query(['action' => 'detail', 'id' => (int) $row['id']])) ?>"><?= master_e($row['nama_kamar']) ?></a></td>
            <td><?= (int) $row['terisi'] ?> / <?= (int) $row['kapasitas'] ?></td>
            <td><?= ah_badge($label, $label === 'Penuh' ? 'danger' : ($label === 'Hampir penuh' ? 'warn' : 'ok')) ?></td>
            <?php if ($asramaAda): ?><td><?= $labelAsrama($row) ?></td><?php endif; ?>
            <td data-label=""><?= ah_menu([
                ['label' => 'Lihat penghuni', 'url' => '?' . ah_query(['action' => 'detail', 'id' => (int) $row['id']]), 'icon' => 'fa-users'],
                ['label' => 'Ubah', 'url' => '?' . ah_query(['action' => 'edit', 'id' => (int) $row['id']]), 'icon' => 'fa-pen'],
                ['label' => 'Kelola penghuni', 'url' => 'admin_penempatan_santri.php?kamar_id=' . (int) $row['id'], 'icon' => 'fa-bed'],
            ], 'Tindakan untuk ' . $row['nama_kamar']) ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
<?php endif;
master_pagination((int) $result['total'], $page, 24);
ah_old_clear('_room_error');
master_footer();
