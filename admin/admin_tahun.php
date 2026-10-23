<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;

/**
 * Pengaturan › Tahun ajaran & semester (redesain website Tahap 6, M-3).
 *
 * Semester aktif ditonjolkan di atas. "Aktifkan" semester lain lewat ⋯ dengan
 * dialog yang menjelaskan dampaknya: hanya satu semester boleh aktif (dijaga
 * basis data), sehingga semester aktif sekarang otomatis menjadi Non-Aktif.
 * Status mengikuti basis data: Aktif, Non-Aktif, Arsip. Tidak ada tanggal
 * mulai semester di basis data, jadi tidak ditampilkan.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = master_data_service();
$halaman = 'admin_tahun.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    try {
        $label = static function (int $id) use ($service): string {
            $t = $service->year($id);
            return $t === null ? 'Semester' : $t['semester'] . ' ' . $t['tahun'];
        };
        if ($action === 'save') {
            $saved = $service->saveYear($_POST, $id ?: null);
            ah_old_clear('_tahun_old');
            master_flash('success', $label($saved) . ' tersimpan.');
        } elseif ($action === 'activate') {
            $service->activateYear($id);
            master_flash('success', $label($id) . ' kini semester aktif. Tepat satu semester aktif.');
        } elseif ($action === 'archive') {
            $service->archiveYear($id);
            master_flash('success', $label($id) . ' diarsipkan.');
        } elseif ($action === 'restore') {
            $service->restoreYear($id);
            master_flash('success', $label($id) . ' dipulihkan dari arsip.');
        }
    } catch (MasterDataException $exception) {
        master_flash('danger', $exception->getMessage());
        if ($action === 'save') {
            ah_validation_keep($_POST, ['tahun', 'semester'], $exception, '_tahun_old');
            master_redirect($halaman . '?action=' . ($id ? 'edit&id=' . $id : 'create'));
        }
    }
    master_redirect($halaman);
}

$q = App\Database\PageQuery::term($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = $service->yearsPage($q, $page);
$selected = isset($_GET['id']) ? $service->year((int) $_GET['id']) : null;
$mode = (string) ($_GET['action'] ?? '');
$aktif = null;
foreach ($service->years() as $t) {
    if ($t['status'] === 'Aktif' && !$t['archived_at']) { $aktif = $t; break; }
}
$tutup = $halaman . (($qs = ah_query(['action' => null, 'id' => null])) === '' ? '' : '?' . $qs);

master_header('Tahun ajaran', [
    'active' => 'tahun',
    'description' => 'Hanya satu semester yang boleh aktif. Jadwal, penempatan, penugasan, dan penilaian mengikuti semester aktif.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Pengaturan'], ['label' => 'Tahun ajaran']],
    'actions' => '<a class="btn btn-primary" href="?action=create"><i class="fas fa-plus" aria-hidden="true"></i> Tambah semester</a>',
]);

if ($mode === 'create' || ($mode === 'edit' && $selected)):
    $r = array_replace($selected ?? [], is_array($_SESSION['_tahun_old'] ?? null) ? $_SESSION['_tahun_old'] : []);
    ah_panel_open(['title' => $selected ? 'Ubah ' . $selected['semester'] . ' ' . $selected['tahun'] : 'Tambah semester', 'close_url' => $tutup]);
    echo ah_error_summary('_tahun_old'); ?>
    <form method="post">
        <?= master_csrf() ?><input type="hidden" name="action" value="save">
        <?php if ($selected): ?><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><?php endif; ?>
        <div class="mb-3"><label class="form-label" for="tahun">Tahun ajaran <span class="text-danger" aria-hidden="true">*</span></label>
            <input class="form-control" id="tahun" name="tahun" pattern="[0-9]{4}/[0-9]{4}" placeholder="2026/2027" required value="<?= master_e($r['tahun'] ?? '') ?>" aria-describedby="bantuan_tahun"><?= ah_field_error('tahun', '_tahun_old') ?>
            <div class="form-text" id="bantuan_tahun">Format YYYY/YYYY.</div></div>
        <div class="mb-3"><label class="form-label" for="semester">Semester</label>
            <select class="form-select" id="semester" name="semester">
                <option <?= ($r['semester'] ?? 'Ganjil') === 'Ganjil' ? 'selected' : '' ?>>Ganjil</option>
                <option <?= ($r['semester'] ?? '') === 'Genap' ? 'selected' : '' ?>>Genap</option>
            </select><?= ah_field_error('semester', '_tahun_old') ?></div>
        <p class="small text-muted">Semester baru disimpan berstatus Non-Aktif. Aktifkan lewat ⋯ pada daftar.</p>
        <div class="ah-actbar mb-0"><a class="btn btn-outline-secondary" href="<?= master_e($tutup) ?>">Batal</a><button class="btn btn-primary" type="submit">Simpan</button></div>
    </form>
    <?php ah_panel_close();
    ah_old_clear('_tahun_old');
endif;
?>
<section class="ah-card ah-semester" aria-labelledby="ah-semester-aktif">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-semester-aktif">Semester aktif</h2><?= $aktif ? ah_badge('Aktif', 'ok') : ah_badge('Belum ada', 'warn') ?></div>
    <div class="ah-card__body">
        <?php if ($aktif): ?>
            <dl class="ah-kv"><div><dt>Tahun ajaran</dt><dd><?= master_e($aktif['tahun']) ?></dd></div><div><dt>Semester</dt><dd><?= master_e($aktif['semester']) ?></dd></div></dl>
        <?php else: ?>
            <p class="mb-0">Belum ada semester aktif. Aktifkan satu semester dari daftar di bawah.</p>
        <?php endif; ?>
    </div>
</section>
<?php ah_filter_open($q !== '' ? 1 : 0); ?>
    <div class="ah-filter__wide"><label class="form-label" for="tahun-q">Cari tahun, semester, atau status</label>
        <input class="form-control" type="search" id="tahun-q" name="q" maxlength="100" value="<?= master_e($q) ?>"></div>
<?php ah_filter_close(); ?>
<div class="ah-card ah-table-wrap">
    <table class="ah-table ah-table--cards" id="daftar-tahun">
        <caption class="ah-visually-hidden">Daftar tahun ajaran dan semester</caption>
        <thead><tr><th scope="col">Tahun</th><th scope="col">Semester</th><th scope="col">Status</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $row): $tid = (int) $row['id']; $nama = $row['semester'] . ' ' . $row['tahun'];
            $status = $row['archived_at'] ? 'Arsip' : (string) $row['status']; ?>
            <tr<?= $row['archived_at'] ? ' class="ah-row-muted"' : '' ?>>
                <td><b><?= master_e($row['tahun']) ?></b></td>
                <td><?= master_e($row['semester']) ?></td>
                <td><?= ah_badge($status, $status === 'Aktif' ? 'ok' : 'muted') ?></td>
                <td data-label=""><?= ah_menu(array_values(array_filter([
                    ['label' => 'Ubah', 'url' => '?' . ah_query(['action' => 'edit', 'id' => $tid]), 'icon' => 'fa-pen'],
                    $row['status'] !== 'Aktif' && !$row['archived_at'] ? ['label' => 'Aktifkan', 'icon' => 'fa-power-off', 'action' => $halaman, 'fields' => ['id' => $tid, 'action' => 'activate'],
                        'confirm' => ['title' => 'Aktifkan ' . $nama . '?', 'ok' => 'Aktifkan',
                            'text' => 'Hanya satu semester boleh aktif' . ($aktif ? ', jadi ' . $aktif['semester'] . ' ' . $aktif['tahun'] . ' otomatis menjadi Non-Aktif' : '') . '. Jadwal, penempatan, penugasan, dan penilaian berpindah ke ' . $nama . '. Riwayat semester lain tidak dihapus.']] : null,
                    $row['archived_at'] ? ['label' => 'Pulihkan', 'icon' => 'fa-rotate-left', 'action' => $halaman, 'fields' => ['id' => $tid, 'action' => 'restore'],
                        'confirm' => ['title' => 'Pulihkan ' . $nama . '?', 'text' => 'Semester kembali ditawarkan pada daftar.', 'ok' => 'Pulihkan']] : null,
                    $row['status'] !== 'Aktif' && !$row['archived_at'] ? ['label' => 'Arsipkan', 'icon' => 'fa-box-archive', 'danger' => true, 'action' => $halaman, 'fields' => ['id' => $tid, 'action' => 'archive'],
                        'confirm' => ['title' => 'Arsipkan ' . $nama . '?', 'text' => 'Semester tidak lagi ditawarkan pada daftar aktif. Kelas, absensi, dan riwayat lama tetap tersimpan.', 'ok' => 'Arsipkan']] : null,
                ])), 'Tindakan untuk ' . $nama) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($result['rows'] === []): ?><tr><td colspan="4" data-label=""><?= ah_empty('Tidak ada hasil', 'Ubah kata pencarian atau tambahkan semester.') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php master_pagination((int) $result['total'], (int) $result['page'], 20); master_footer(); ?>
