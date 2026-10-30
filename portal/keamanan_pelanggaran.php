<?php

declare(strict_types=1);

use App\Ui\Periode;
use App\V3\V3Exception;

require_once __DIR__ . '/_keamanan.php';

/**
 * Keamanan › Pembinaan santri › Pelanggaran (penugasan lintas guru/pengurus Fase 3).
 *
 * Pelanggaran seluruh santri aktif, hanya baca (PK-5/PK-6). Daftar, chip
 * status, pencarian, dan periode memakai `PelanggaranService` yang sama dengan
 * halaman pembinaan, dalam mode baca `keamanan` (penanda `KeamananAkses`).
 * Tidak ada tombol catat, koreksi, batal, publikasi, atau tandai mengetahui.
 */

$service = v3_pelanggaran_service();
$teks = static fn (string $key): string => is_string($_GET[$key] ?? null) ? mb_substr(trim($_GET[$key]), 0, 100) : '';
$status = in_array($teks('status'), ['Dicatat', 'Ditindaklanjuti', 'Selesai', 'Dibatalkan'], true) ? $teks('status') : '';
$q = $teks('q');
$periodeKode = array_key_exists($teks('periode'), Periode::PILIHAN) ? $teks('periode') : 'semua';
$mulai = '';
$sampai = '';
if ($periodeKode === 'rentang') {
    $tanggal = static fn (string $v): string => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) ? $v : '';
    $mulai = $tanggal($teks('mulai'));
    $sampai = $tanggal($teks('sampai'));
} elseif ($periodeKode !== 'semua') {
    $aktif = app_db()->query("SELECT tahun,semester FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1")?->fetch_assoc();
    [$mulai, $sampai] = Periode::rentang($periodeKode, $aktif ?: null);
}
$filters = array_filter(['status' => $status, 'q' => $q, 'mulai' => $mulai, 'sampai' => $sampai, 'page' => (string) max(1, (int) ($_GET['page'] ?? 1))], static fn ($v): bool => $v !== '');
$error = '';
try {
    $list = $service->page($pantauUser, $filters);
    $counts = $service->statusCounts($pantauUser, $filters);
} catch (V3Exception $exception) {
    if ($exception->status !== 422) {
        keamanan_tolak($exception->status, $exception->getMessage());
    }
    $error = $exception->getMessage();
    $list = ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 25, 'peringatan_konfigurasi' => []];
    $counts = ['Dicatat' => 0, 'Ditindaklanjuti' => 0, 'Selesai' => 0, 'Dibatalkan' => 0];
}

ah_page_open([
    'title' => 'Pelanggaran', 'heading' => 'Pembinaan santri', 'user' => $currentUser, 'active' => 'keamanan_pembinaan',
    'description' => 'Pelanggaran seluruh santri aktif · hanya baca.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Keamanan'], ['label' => 'Pelanggaran']],
]);
if ($error !== '') {
    ah_note('danger', $error);
}
$chips = [['label' => 'Semua', 'url' => '?' . ah_query(['status' => null, 'page' => null]), 'active' => $status === '', 'count' => array_sum($counts)]];
foreach ($counts as $nilai => $jumlah) {
    $chips[] = ['label' => $nilai, 'url' => '?' . ah_query(['status' => $nilai, 'page' => null]), 'active' => $status === $nilai, 'count' => $jumlah];
}
echo ah_chips($chips, 'Saring menurut status');
ah_filter_open((int) ($q !== '') + (int) ($periodeKode !== 'semua'), ['status' => $status]);
?>
    <div class="ah-filter__wide"><label class="form-label" for="q">Cari santri</label>
        <input class="form-control" type="search" id="q" name="q" maxlength="100" value="<?= ah_e($q) ?>" placeholder="Nama santri"></div>
    <div><label class="form-label" for="periode">Periode kejadian</label>
        <select class="form-select" id="periode" name="periode">
            <option value="semua">Semua waktu</option>
            <?php foreach (['pekan', 'bulan', 'semester', 'rentang'] as $kode): ?><option value="<?= $kode ?>"<?= $periodeKode === $kode ? ' selected' : '' ?>><?= ah_e(Periode::label($kode)) ?></option><?php endforeach ?>
        </select></div>
    <div><label class="form-label" for="mulai">Dari (rentang sendiri)</label><input class="form-control" type="date" id="mulai" name="mulai" value="<?= ah_e($periodeKode === 'rentang' ? $mulai : '') ?>"></div>
    <div><label class="form-label" for="sampai">Sampai</label><input class="form-control" type="date" id="sampai" name="sampai" value="<?= ah_e($periodeKode === 'rentang' ? $sampai : '') ?>"></div>
<?php ah_filter_close(['status' => $status]); ?>

<section class="ah-card" aria-labelledby="ah-keamanan-pelanggaran">
    <div class="ah-card__head"><span id="ah-keamanan-pelanggaran">Daftar pelanggaran</span>
        <span class="text-muted small"><?= count($list['rows']) ?> dari <?= (int) $list['total'] ?></span></div>
    <?php if ($list['rows'] === []): ?>
        <div class="ah-card__body"><?= ah_empty('Belum ada catatan yang cocok', 'Ubah chip status atau saringan untuk melihat catatan lain.') ?></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards">
            <caption class="ah-visually-hidden">Pelanggaran santri aktif sesuai saringan</caption>
            <thead><tr><th scope="col">Santri</th><th scope="col">Waktu</th><th scope="col">Jenis</th><th scope="col">Tingkat</th><th scope="col">Poin</th><th scope="col">Status</th></tr></thead>
            <tbody>
            <?php foreach ($list['rows'] as $row): $url = keamanan_url('keamanan_pelanggaran_detail.php', ['id' => (int) $row['id']]); ?>
                <tr>
                    <td data-label="Santri"><span class="ah-cell-wrap"><a class="ah-row-link" href="<?= ah_e($url) ?>"><?= ah_e($row['santri_nama']) ?></a><span class="ah-cell-sub"><?= ah_e($row['semester'] . ' ' . $row['tahun']) ?></span></span></td>
                    <td data-label="Waktu"><?= ah_e(ah_waktu($row['waktu_kejadian'])) ?></td>
                    <td data-label="Jenis"><span class="ah-cell-wrap"><?= ah_e($row['jenis'] ?? $row['kategori']) ?><?php if (($row['jenis'] ?? null) !== null): ?><span class="ah-cell-sub"><?= ah_e($row['kategori']) ?></span><?php endif ?></span></td>
                    <td data-label="Tingkat"><?= ah_tingkat($row['tingkat']) ?></td>
                    <td data-label="Poin"><?= (int) $row['poin'] ?></td>
                    <td data-label="Status"><?= ah_status($row['status']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
</section>
<?php
ah_pagination((int) $list['total'], (int) $list['page'], (int) $list['per_page']);
ah_page_close();
