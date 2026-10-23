<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Izin\IzinException;

/**
 * Perizinan › Ringkasan (redesain website Tahap 4, I-1).
 *
 * Kartu angka per status menjadi pintu ke tab Pengajuan dengan status itu;
 * catatan panjang di atas halaman diganti kartu "Alur izin". Cakupan, mode,
 * dan aturan otorisasi tidak berubah: halaman tetap dijaga
 * `portal/_guard.php` lewat `_ui.php`, dan seluruh angka dihitung server
 * dari cakupan akun (`izin_service()`).
 */

require_once __DIR__ . '/_ui.php';

$requestedMode = portal_requested_mode($currentUser, $userCapabilities);

try {
    $overview = izin_service()->list($currentUser, [], 1, 5, $requestedMode);
} catch (IzinException $exception) {
    http_response_code($exception->status());
    exit(portal_e($exception->getMessage()));
}

$scope = $overview['scope'];
$summary = $overview['summary'];
$mode = (string) $scope['mode'];
$ortu = $mode === Capabilities::ORANG_TUA;
$daftar = static fn (array $query = []): string => app_url('/portal/izin.php') . '?' . http_build_query(array_merge(['mode' => $mode], $query));

portal_header('Perizinan santri', $userCapabilities, $mode, $currentUser, [
    'description' => $scope['label'],
    'actions' => portal_ajukan_button($mode),
    'active' => 'izin',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Perizinan'],
    ],
]);
portal_flash_render();
portal_mode_switcher($userCapabilities, $mode, app_url('/portal/izin_ringkasan.php'));

$kartu = [['label' => 'Total pengajuan', 'value' => (string) (int) $summary['total'], 'hint' => 'dalam cakupan Anda', 'url' => $daftar()]];
foreach (['Diajukan', 'Perlu Penetapan Admin', 'Disetujui', 'Ditolak'] as $status) {
    $kartu[] = [
        'label' => $status === 'Perlu Penetapan Admin' ? 'Perlu penetapan' : $status,
        'value' => (string) (int) ($summary['per_status'][$status] ?? 0),
        'url' => $daftar(['status' => $status]),
        'warn' => $status === 'Perlu Penetapan Admin' && (int) ($summary['per_status'][$status] ?? 0) > 0,
    ];
}
echo ah_stats($kartu, 'Jumlah pengajuan per status');
?>
<div class="ah-split">
    <section class="ah-card" aria-labelledby="ah-terbaru">
        <div class="ah-card__head"><span id="ah-terbaru">Pengajuan terbaru</span>
            <a class="btn btn-sm btn-outline-primary" href="<?= portal_e($daftar()) ?>">Semua</a>
        </div>
        <?php if ($overview['rows'] === []): ?>
            <div class="ah-card__body"><?= ah_empty(
                'Belum ada pengajuan',
                $ortu ? 'Belum ada pengajuan izin untuk anak yang terhubung dengan akun ini.' : 'Belum ada pengajuan izin dalam cakupan Anda.',
                portal_ajukan_button($mode, 'btn btn-sm btn-primary') ?: null
            ) ?></div>
        <?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table ah-table--cards">
                <caption class="ah-visually-hidden">Lima pengajuan izin terbaru dalam cakupan Anda</caption>
                <thead><tr><th scope="col">#</th><th scope="col">Santri</th><th scope="col">Rentang</th><th scope="col">Status</th></tr></thead>
                <tbody>
                <?php foreach ($overview['rows'] as $row): ?>
                    <tr>
                        <td><a class="ah-row-link" href="<?= portal_e(portal_detail_url((int) $row['id'], $mode)) ?>">#<?= (int) $row['id'] ?></a></td>
                        <td><?= portal_e($row['nama_santri']) ?><span class="ah-cell-sub"><?= portal_e($row['nis']) ?></span></td>
                        <td><?= portal_e(ah_rentang((string) $row['tgl_izin'], (string) $row['tgl_kembali'])) ?></td>
                        <td><?= portal_status((string) $row['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <section class="ah-card" aria-labelledby="ah-alur">
        <div class="ah-card__head"><span id="ah-alur">Alur izin</span></div>
        <div class="ah-card__body">
            <ol class="ah-vsteps">
                <li>Pengurus mengajukan izin santri binaannya.</li>
                <li>Sistem meneruskan ke murobi kamar atau kelas santri. Bila murobinya tidak tepat satu, admin menetapkan murobi.</li>
                <li>Murobi menyetujui atau menolak dengan alasan.</li>
                <li>Pengurus dan wali santri menerima notifikasi keputusan.</li>
            </ol>
            <?php if ($ortu): ?>
                <p class="small text-muted mb-0 mt-3">Akun orang tua hanya membaca status dan riwayat izin anak; tidak ada tombol yang mengubah data.</p>
            <?php endif; ?>
        </div>
    </section>
</div>
<?php portal_footer(); ?>
