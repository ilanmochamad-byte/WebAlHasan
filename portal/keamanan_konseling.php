<?php

declare(strict_types=1);

use App\V3\V3Exception;

require_once __DIR__ . '/_keamanan.php';

/**
 * Keamanan › Pembinaan santri › Konseling (penugasan lintas guru/pengurus Fase 3).
 *
 * Kasus konseling Internal seluruh santri aktif, hanya baca. Kasus Rahasia
 * TIDAK muncul di daftar, angka chip, maupun pencarian (PK-6): `KonselingService`
 * dalam mode Keamanan memakai penyaring kerahasiaan yang sama dengan murobi,
 * dan angka chip dihitung dengan klausa yang sama dengan daftar.
 */

$service = v3_konseling_service();
$teks = static fn (string $key): string => is_string($_GET[$key] ?? null) ? mb_substr(trim($_GET[$key]), 0, 100) : '';
$q = $teks('q');
$status = in_array($teks('status'), ['Dibuka', 'Dalam Pendampingan', 'Selesai', 'Dibatalkan'], true) ? $teks('status') : '';
$saring = array_filter(['q' => $q], static fn ($v): bool => $v !== '');
$error = '';
try {
    $counts = $service->statusCounts($pantauUser, $saring);
    $list = $service->page($pantauUser, $saring + array_filter(['status' => $status, 'page' => (string) max(1, (int) ($_GET['page'] ?? 1))]));
} catch (V3Exception $exception) {
    if ($exception->status !== 422) {
        keamanan_tolak($exception->status, $exception->getMessage());
    }
    $error = $exception->getMessage();
    $counts = ['Dibuka' => 0, 'Dalam Pendampingan' => 0, 'Selesai' => 0, 'Dibatalkan' => 0];
    $list = ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 25];
}

ah_page_open([
    'title' => 'Konseling', 'heading' => 'Pembinaan santri', 'user' => $currentUser, 'active' => 'keamanan_pembinaan',
    'description' => 'Kasus konseling Internal seluruh santri aktif · hanya baca. Kasus Rahasia tidak ditampilkan.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Keamanan'], ['label' => 'Konseling']],
]);
if ($error !== '') {
    ah_note('danger', $error);
}
$chips = [['label' => 'Semua', 'url' => '?' . ah_query(['status' => null, 'page' => null]), 'active' => $status === '', 'count' => array_sum($counts)]];
foreach ($counts as $nilai => $jumlah) {
    $chips[] = ['label' => $nilai === 'Dalam Pendampingan' ? 'Dalam pendampingan' : $nilai, 'url' => '?' . ah_query(['status' => $nilai, 'page' => null]), 'active' => $status === $nilai, 'count' => $jumlah];
}
echo ah_chips($chips, 'Saring menurut status');
ah_filter_open((int) ($q !== ''), array_filter(['status' => $status]));
?>
    <div class="ah-filter__wide"><label class="form-label" for="case-q">Cari santri</label><input class="form-control" type="search" id="case-q" name="q" maxlength="100" value="<?= ah_e($q) ?>" placeholder="Nama santri"></div>
<?php ah_filter_close(array_filter(['status' => $status])); ?>
<section class="ah-card" aria-labelledby="ah-keamanan-kasus">
    <div class="ah-card__head"><span id="ah-keamanan-kasus">Daftar kasus konseling</span><span class="text-muted small"><?= count($list['rows']) ?> dari <?= (int) $list['total'] ?></span></div>
    <?php if ($list['rows'] === []): ?><div class="ah-card__body"><?= ah_empty('Belum ada kasus', 'Belum ada kasus konseling Internal yang cocok dengan saringan ini.') ?></div><?php else: ?>
    <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Kasus konseling Internal santri aktif</caption>
        <thead><tr><th scope="col">Santri</th><th scope="col">Dibuka</th><th scope="col">Kerahasiaan</th><th scope="col">Sesi</th><th scope="col">Status</th></tr></thead><tbody>
        <?php foreach ($list['rows'] as $row): ?>
            <tr>
                <td data-label="Santri"><span class="ah-cell-wrap"><a class="ah-row-link" href="<?= ah_e(keamanan_url('keamanan_konseling_detail.php', ['id' => (int) $row['id']])) ?>"><?= ah_e($row['santri_nama']) ?></a><span class="ah-cell-sub"><?= ah_e($row['semester'] . ' ' . $row['tahun']) ?></span></span></td>
                <td data-label="Dibuka"><?= ah_e(ah_tanggal($row['dibuka_pada'], false)) ?></td>
                <td data-label="Kerahasiaan"><?= ah_badge($row['kerahasiaan'], 'muted') ?></td>
                <td data-label="Sesi"><?= (int) $row['jumlah_sesi'] ?></td>
                <td data-label="Status"><?= ah_status($row['status']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody></table></div>
    <?php endif ?>
</section>
<?php
ah_pagination((int) $list['total'], (int) $list['page'], (int) $list['per_page']);
ah_page_close();
