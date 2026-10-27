<?php

declare(strict_types=1);

use App\Izin\IzinException;
use App\Izin\IzinWaliService;

require_once __DIR__ . '/_izin_wali.php';

/**
 * Pengajuan izin wali (PRD V7 Fase 1–3) — satu alamat, tampilan per peran:
 *   - orang tua : pengajuan wali untuk anak berelasi aktif (pengaju: isi
 *                 lengkap; wali lain: status ringkas) + tombol Ajukan izin;
 *   - pembimbing: antrean yang dialamatkan kepadanya (tinjauan di rincian)
 *                 dan riwayat yang sudah ditinjau dengan status terkini;
 *   - admin     : pemantauan seluruh pengajuan wali dan antrean penetapan
 *                 pembimbing/murobi.
 * Murobi memutus pengajuan yang sudah diteruskan dari antrean perizinan V2.
 */

$mode = (string) portal_requested_mode($currentUser, $userCapabilities);
$peran = izin_wali_peran($mode);
$svc = izin_wali_service();
if ($peran === null) {
    izin_wali_tolak(IzinException::forbidden('Pengajuan izin wali hanya untuk orang tua, pembimbing, dan admin.'), $currentUser, $userCapabilities, $mode);
}

try {
    $akses = $svc->akses($currentUser);
    $data = match ($peran) {
        IzinWaliService::PERAN_WALI => $svc->daftarWali($currentUser, $_GET['santri_id'] ?? null, $_GET['page'] ?? 1),
        IzinWaliService::PERAN_PEMBIMBING => ($_GET['riwayat'] ?? '') === '1'
            ? $svc->riwayatPembimbing($currentUser, $_GET['page'] ?? 1)
            : $svc->antreanPembimbing($currentUser, $_GET['page'] ?? 1),
        default => $svc->daftarAdmin($currentUser, [
            'antrean' => ($_GET['antrean'] ?? '1') === '1' ? '1' : '0',
            'status' => $_GET['status'] ?? null,
            'page' => $_GET['page'] ?? 1,
        ]),
    };
} catch (IzinException $exception) {
    izin_wali_tolak($exception, $currentUser, $userCapabilities, $mode);
}

$judul = match ($peran) {
    IzinWaliService::PERAN_WALI => 'Pengajuan izin oleh wali',
    IzinWaliService::PERAN_PEMBIMBING => 'Pengajuan wali — antrean pembimbing',
    default => 'Pengajuan wali — pemantauan admin',
};
$tombol = $peran === IzinWaliService::PERAN_WALI && $akses['boleh_mengajukan']
    ? '<a class="btn btn-primary" href="' . portal_e(app_url('/portal/izin_wali_buat.php')) . '"><i class="fas fa-plus" aria-hidden="true"></i> Ajukan izin</a>'
    : '';

portal_header($judul, $userCapabilities, $mode, $currentUser, [
    'active' => $peran === IzinWaliService::PERAN_WALI ? 'izin_anak' : 'izin',
    'description' => match ($peran) {
        IzinWaliService::PERAN_WALI => 'Izin yang diajukan wali melalui pembimbing. Wali lain pada anak yang sama hanya melihat status ringkas.',
        IzinWaliService::PERAN_PEMBIMBING => 'Pengajuan wali untuk santri dalam cakupan penugasan pembimbing aktif Anda.',
        default => 'Seluruh pengajuan wali. Antrean admin berisi pengajuan tanpa tepat satu pembimbing sah atau murobi tunggal.',
    },
    'actions' => $tombol . '<a class="btn btn-outline-secondary" href="' . portal_e(app_url('/portal/izin_wali_laporan.php')) . '">'
        . '<i class="fas fa-chart-column" aria-hidden="true"></i> Laporan</a>',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        $peran === IzinWaliService::PERAN_WALI
            ? ['label' => 'Izin anak', 'url' => app_url('/portal/izin.php')]
            : ['label' => 'Perizinan', 'url' => app_url('/portal/izin_ringkasan.php')],
        ['label' => 'Pengajuan wali'],
    ],
]);
portal_flash_render();

$riwayatPembimbing = $peran === IzinWaliService::PERAN_PEMBIMBING && ($_GET['riwayat'] ?? '') === '1';
if ($peran === IzinWaliService::PERAN_PEMBIMBING) {
    echo ah_chips([
        ['label' => 'Menunggu tinjauan', 'url' => '?riwayat=0', 'active' => !$riwayatPembimbing, 'count' => (int) $akses['antrean_pembimbing']],
        ['label' => 'Sudah ditinjau', 'url' => '?riwayat=1', 'active' => $riwayatPembimbing],
    ], 'Saring pengajuan wali');
}
if ($peran === IzinWaliService::PERAN_ADMIN) {
    $antrean = (bool) $data['filter']['antrean'];
    echo ah_chips([
        ['label' => 'Perlu tindakan admin', 'url' => '?antrean=1', 'active' => $antrean, 'count' => (int) $data['antrean_admin']],
        ['label' => 'Semua pengajuan wali', 'url' => '?antrean=0', 'active' => !$antrean, 'count' => array_sum($data['ringkasan'])],
    ], 'Saring pengajuan wali');
}

if ($data['items'] === []) {
    echo ah_empty(
        match ($peran) {
            IzinWaliService::PERAN_WALI => 'Belum ada pengajuan wali',
            IzinWaliService::PERAN_PEMBIMBING => $riwayatPembimbing ? 'Belum ada yang ditinjau' : 'Antrean kosong',
            default => 'Tidak ada pengajuan yang cocok',
        },
        match ($peran) {
            IzinWaliService::PERAN_WALI => $akses['boleh_mengajukan']
                ? 'Ajukan izin untuk anak Anda; pengajuan ditinjau pembimbing sebelum diputus murobi.'
                : 'Akun ini belum terhubung dengan santri aktif. Hubungi admin pesantren.',
            IzinWaliService::PERAN_PEMBIMBING => 'Pengajuan wali yang dialamatkan kepada Anda akan tampil di sini.',
            default => 'Ubah saringan untuk melihat pengajuan lain.',
        },
        $tombol !== '' ? $tombol : null
    );
} elseif ($peran === IzinWaliService::PERAN_WALI) {
    echo '<div class="ah-cards">';
    foreach ($data['items'] as $item) {
        echo '<a class="ah-icard" href="' . portal_e(izin_wali_detail_url((int) $item['id'])) . '">'
            . '<span class="ah-icard__head"><span>' . portal_e(ah_rentang($item['tgl_izin'], $item['tgl_kembali'])) . '</span>'
            . izin_wali_status((string) $item['status']) . '</span>'
            . '<span class="ah-icard__text">' . portal_e($item['santri']['nama'])
            . ($item['ringkas'] ? '' : ' · ' . portal_e(mb_strimwidth((string) $item['alasan'], 0, 140, '…'))) . '</span>'
            . '<span class="ah-icard__meta">#' . (int) $item['id'] . ' · Diajukan oleh ' . portal_e($item['pengaju'])
            . ' · ' . portal_e(ah_waktu((string) $item['diajukan_pada'])) . '</span>'
            . '</a>';
    }
    echo '</div>';
} else {
    ?>
    <section class="ah-card" aria-labelledby="ah-daftar-wali">
        <div class="ah-card__head"><span id="ah-daftar-wali"><?= $peran === IzinWaliService::PERAN_PEMBIMBING ? ($riwayatPembimbing ? 'Sudah Anda tinjau' : 'Menunggu tinjauan Anda') : 'Daftar pengajuan wali' ?></span>
            <span class="text-muted small"><?= count($data['items']) ?> dari <?= (int) $data['total'] ?></span></div>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards">
            <caption class="ah-visually-hidden">Pengajuan izin wali</caption>
            <thead><tr><th scope="col">#</th><th scope="col">Santri</th><th scope="col">Rentang</th>
                <th scope="col" class="ah-col-secondary">Alasan</th><th scope="col">Pembimbing</th><th scope="col">Status</th></tr></thead>
            <tbody>
            <?php foreach ($data['items'] as $item): ?>
                <tr>
                    <td><a class="ah-row-link" href="<?= portal_e(izin_wali_detail_url((int) $item['id'])) ?>">#<?= (int) $item['id'] ?></a></td>
                    <td><a class="ah-row-link" href="<?= portal_e(izin_wali_detail_url((int) $item['id'])) ?>"><?= portal_e($item['santri']['nama']) ?></a>
                        <span class="ah-cell-sub"><?= portal_e($item['santri']['nis']) ?> · Wali: <?= portal_e((string) $item['wali']['nama']) ?></span></td>
                    <td><?= portal_e(ah_rentang($item['tgl_izin'], $item['tgl_kembali'])) ?></td>
                    <td class="ah-col-secondary"><?= portal_e(mb_strimwidth((string) $item['alasan'], 0, 70, '…')) ?></td>
                    <td><?= portal_e($item['pembimbing']['nama'] ?? 'Belum ditetapkan') ?>
                        <?php if ($item['perlu_admin']): ?><span class="ah-cell-sub"><?= ah_badge($item['status'] === 'Perlu Penetapan Admin' ? 'Perlu penetapan murobi' : 'Perlu penetapan admin', 'warn') ?></span><?php endif; ?></td>
                    <td><?= izin_wali_status((string) $item['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php
}
portal_pagination((int) $data['total'], (int) $data['page'], (int) $data['per_page']);
portal_footer();
