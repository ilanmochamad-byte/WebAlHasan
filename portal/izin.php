<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Izin\IzinException;
use App\Izin\IzinRepository;

/**
 * Perizinan › Pengajuan (redesain website Tahap 4, I-2) dan
 * Izin anak untuk orang tua (I-7, keputusan O).
 *
 * Staf: chip status sebagai penyaring utama, pencarian dan tanggal di bilah
 * saring, asal data dan "tertunda > 1 hari" di Filter lanjutan, tabel tujuh
 * kolom yang menjadi kartu di HP. Orang tua: satu kartu per izin dengan tab
 * Aktif dan Riwayat, hanya-baca.
 *
 * Cakupan tetap dihitung server (`IzinService::list` → `IzinRepository`
 * menambahkan predikat cakupan pada setiap query). Parameter alamat hanya
 * mempersempit hasil di dalam cakupan itu.
 */

require_once __DIR__ . '/_ui.php';

$requestedMode = portal_requested_mode($currentUser, $userCapabilities);
$page = max(1, (int) ($_GET['page'] ?? 1));
$teks = static fn (string $key, int $max = 100): string => is_string($_GET[$key] ?? null) ? mb_substr(trim((string) $_GET[$key]), 0, $max) : '';
$tanggal = static fn (string $key): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $teks($key)) === 1 ? $teks($key) : '';

try {
    $scope = izin_service()->scopeFor($currentUser, $requestedMode);
} catch (IzinException $exception) {
    http_response_code($exception->status());
    exit(portal_e($exception->getMessage()));
}
$mode = (string) $scope['mode'];

// ===========================================================================
// I-7 Izin anak (orang tua)
// ===========================================================================
if ($mode === Capabilities::ORANG_TUA) {
    $anak = izin_service()->santriInScope($currentUser, $mode);
    $idAnak = array_map('intval', array_column($anak, 'id'));
    $kunciAnak = '_ah_izin_anak_' . (int) $currentUser['id'];
    $pilih = (int) ($_GET['santri_id'] ?? ($_SESSION[$kunciAnak] ?? 0));
    if (!in_array($pilih, $idAnak, true)) {
        $pilih = $idAnak[0] ?? 0;
    }
    if ($pilih > 0) {
        $_SESSION[$kunciAnak] = $pilih;
    }
    $namaAnak = '';
    foreach ($anak as $item) {
        if ((int) $item['id'] === $pilih) {
            $namaAnak = (string) $item['nama_santri'];
        }
    }
    $tab = ($_GET['tab'] ?? '') === 'riwayat' ? 'riwayat' : 'aktif';
    $result = $pilih > 0
        ? izin_service()->list($currentUser, ['aktif' => $tab === 'aktif' ? '1' : '0', 'santri_id' => $pilih], $page, 20, $mode)
        : ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 20];

    portal_header($namaAnak !== '' ? 'Izin ' . $namaAnak : 'Izin anak', $userCapabilities, $mode, $currentUser, [
        'active' => 'izin_anak',
        'description' => $tab === 'aktif'
            ? 'Izin yang belum diputus atau belum lewat tanggal kembali.'
            : 'Izin yang sudah selesai, ditolak, atau dibatalkan. Terbaru di atas.',
        'breadcrumbs' => [
            ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
            ['label' => 'Izin anak'],
        ],
    ]);
    portal_flash_render();
    if (count($anak) > 1) {
        $chips = [];
        foreach ($anak as $item) {
            $chips[] = ['label' => (string) $item['nama_santri'], 'url' => '?' . http_build_query(array_filter(['santri_id' => (int) $item['id'], 'tab' => $tab === 'riwayat' ? 'riwayat' : null])), 'active' => (int) $item['id'] === $pilih];
        }
        echo ah_chips($chips, 'Pilih anak');
    }
    if ($anak === []) {
        echo ah_empty('Belum ada anak yang terhubung', 'Akun ini belum terhubung dengan data santri. Hubungi admin pesantren untuk menghubungkan akun wali.');
    } elseif ($result['rows'] === []) {
        echo ah_empty(
            $tab === 'aktif' ? 'Tidak ada izin aktif' : 'Belum ada riwayat izin',
            $tab === 'aktif' ? 'Izin yang sedang diproses atau sedang berjalan akan tampil di sini.' : 'Izin yang sudah selesai akan tampil di sini.'
        );
    } else {
        echo '<div class="ah-cards">';
        foreach ($result['rows'] as $row) {
            $keputusan = match (true) {
                $row['keputusan_hasil'] !== null => portal_label((string) ($row['keputusan_oleh'] ?? $row['murobi_label']))
                    . ' · ' . ah_waktu((string) $row['diputus_pada'])
                    . ((string) ($row['keputusan_alasan'] ?? '') !== '' ? ' · “' . mb_strimwidth((string) $row['keputusan_alasan'], 0, 160, '…') . '”' : ''),
                $row['status'] === 'Dibatalkan' => 'Pengajuan dibatalkan',
                !empty($row['is_legacy']) => 'Arsip sistem lama',
                default => 'Menunggu keputusan murobi',
            };
            echo '<a class="ah-icard" href="' . portal_e(portal_detail_url((int) $row['id'], $mode)) . '">'
                . '<span class="ah-icard__head"><span>' . portal_e(ah_rentang((string) $row['tgl_izin'], (string) $row['tgl_kembali'])) . '</span>'
                . portal_status((string) $row['status']) . '</span>'
                . '<span class="ah-icard__text">' . portal_e(mb_strimwidth((string) $row['alasan'], 0, 180, '…')) . '</span>'
                . '<span class="ah-icard__meta">' . portal_e($keputusan) . '</span>'
                . '</a>';
        }
        echo '</div>';
        portal_pagination((int) $result['total'], (int) $result['page'], (int) $result['per_page']);
    }
    echo '<p class="small text-muted ah-no-print">Halaman ini hanya-baca. Ketuk kartu untuk melihat rincian dan riwayatnya. '
        . '<a href="' . portal_e(app_url('/portal/laporan.php')) . '">Laporan izin (cetak/CSV)</a></p>';
    portal_footer();
    exit;
}

// ===========================================================================
// I-2 Daftar pengajuan (staf)
// ===========================================================================
$status = in_array($teks('status', 40), IzinRepository::STATUSES, true) ? $teks('status', 40) : '';
$filters = [
    'q' => $teks('q'),
    'status' => $status,
    'source' => in_array($teks('source', 10), ['legacy', 'v2'], true) ? $teks('source', 10) : '',
    'date_from' => $tanggal('date_from'),
    'date_to' => $tanggal('date_to'),
    'tertunda' => ($_GET['tertunda'] ?? '') === '1' ? '1' : '',
];

try {
    $result = izin_service()->list($currentUser, $filters, $page, 20, $mode);
} catch (IzinException $exception) {
    http_response_code($exception->status());
    exit(portal_e($exception->getMessage()));
}

portal_header('Perizinan santri', $userCapabilities, $mode, $currentUser, [
    'description' => $scope['label'] . ' — ' . (int) $result['total'] . ' pengajuan sesuai saringan.',
    'actions' => portal_ajukan_button($mode),
    'active' => 'izin',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Perizinan', 'url' => app_url('/portal/izin_ringkasan.php')],
        ['label' => 'Pengajuan'],
    ],
]);
portal_flash_render();
portal_mode_switcher($userCapabilities, $mode, app_url('/portal/izin.php'));

$perStatus = $result['summary']['per_status'];
$chips = [['label' => 'Semua', 'url' => '?' . ah_query(['status' => null, 'page' => null]), 'active' => $status === '', 'count' => (int) $result['summary']['total']]];
foreach (IzinRepository::STATUSES as $nilai) {
    $chips[] = [
        'label' => $nilai === 'Perlu Penetapan Admin' ? 'Perlu penetapan' : $nilai,
        'url' => '?' . ah_query(['status' => $nilai, 'page' => null]),
        'active' => $status === $nilai,
        'count' => (int) ($perStatus[$nilai] ?? 0),
    ];
}
echo ah_chips($chips, 'Saring menurut status');

$lanjutan = (int) ($filters['source'] !== '') + (int) ($filters['tertunda'] !== '');
$aktif = $lanjutan + (int) ($filters['q'] !== '') + (int) ($filters['date_from'] !== '') + (int) ($filters['date_to'] !== '');
ah_filter_open($aktif, ['mode' => $mode, 'status' => $status]);
?>
    <div class="ah-filter__wide"><label class="form-label" for="q">Cari</label>
        <input class="form-control" type="search" id="q" name="q" maxlength="100" value="<?= portal_e($filters['q']) ?>" placeholder="Nama santri, NIS, atau alasan"></div>
    <div><label class="form-label" for="date_from">Dari tanggal</label>
        <input class="form-control" type="date" id="date_from" name="date_from" value="<?= portal_e($filters['date_from']) ?>"></div>
    <div><label class="form-label" for="date_to">Sampai</label>
        <input class="form-control" type="date" id="date_to" name="date_to" value="<?= portal_e($filters['date_to']) ?>"></div>
    <details class="ah-filter__more"<?= $lanjutan > 0 ? ' open' : '' ?>>
        <summary>Filter lanjutan<?= $lanjutan > 0 ? ' (' . $lanjutan . ')' : '' ?></summary>
        <div class="ah-filter__more-body">
            <div><label class="form-label" for="source">Asal data</label>
                <select class="form-select" id="source" name="source">
                    <option value="">Semua</option>
                    <option value="v2"<?= $filters['source'] === 'v2' ? ' selected' : '' ?>>Sistem baru</option>
                    <option value="legacy"<?= $filters['source'] === 'legacy' ? ' selected' : '' ?>>Arsip sistem lama</option>
                </select></div>
            <div class="form-check align-self-end mb-2">
                <input class="form-check-input" type="checkbox" id="tertunda" name="tertunda" value="1"<?= $filters['tertunda'] === '1' ? ' checked' : '' ?>>
                <label class="form-check-label" for="tertunda">Menunggu lebih dari 1 hari</label>
            </div>
            <p class="small text-muted mb-0">Arsip sistem lama berasal dari sistem sebelumnya yang tidak mencatat pengurus, murobi, dan pemberi keputusan.</p>
        </div>
    </details>
<?php ah_filter_close(['mode' => $mode, 'status' => $status]); ?>

<section class="ah-card" aria-labelledby="ah-daftar-izin">
    <div class="ah-card__head"><span id="ah-daftar-izin">Daftar pengajuan</span>
        <span class="text-muted small"><?= count($result['rows']) ?> dari <?= (int) $result['total'] ?></span></div>
    <?php if ($result['rows'] === []): ?>
        <div class="ah-card__body"><?= ah_empty(
            'Tidak ada pengajuan yang cocok',
            'Ubah chip status atau saringan, atau bersihkan saringan untuk melihat semua pengajuan dalam cakupan Anda.',
            portal_ajukan_button($mode, 'btn btn-sm btn-primary') ?: null
        ) ?></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards">
            <caption class="ah-visually-hidden">Pengajuan izin sesuai saringan dalam cakupan Anda</caption>
            <thead><tr>
                <th scope="col">#</th><th scope="col">Santri</th><th scope="col">Rentang</th>
                <th scope="col" class="ah-col-secondary">Alasan</th><th scope="col" class="ah-col-secondary">Pengurus</th>
                <th scope="col">Murobi</th><th scope="col">Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $row): ?>
                <tr>
                    <td><a class="ah-row-link" href="<?= portal_e(portal_detail_url((int) $row['id'], $mode)) ?>">#<?= (int) $row['id'] ?></a>
                        <?php if (!empty($row['is_legacy'])): ?><span class="ah-cell-sub"><?= portal_e(portal_sumber_label($row)) ?></span><?php endif; ?></td>
                    <td><a class="ah-row-link" href="<?= portal_e(portal_detail_url((int) $row['id'], $mode)) ?>"><?= portal_e($row['nama_santri']) ?></a><span class="ah-cell-sub"><?= portal_e($row['nis']) ?></span></td>
                    <td><?= portal_e(ah_rentang((string) $row['tgl_izin'], (string) $row['tgl_kembali'])) ?></td>
                    <td class="ah-col-secondary"><?= portal_e(mb_strimwidth((string) $row['alasan'], 0, 70, '…')) ?></td>
                    <td class="ah-col-secondary"><?= portal_e(portal_label((string) $row['pengurus_label'])) ?></td>
                    <td><?= portal_e(portal_label((string) $row['murobi_label'])) ?></td>
                    <td><?= portal_status((string) $row['status']) ?>
                        <?php if (portal_izin_tertunda($row)): ?><span class="ah-cell-sub"><?= ah_badge('Menunggu ' . portal_lama((string) ($row['diajukan_pada'] ?? $row['created_at'])), 'warn') ?></span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php portal_pagination((int) $result['total'], (int) $result['page'], (int) $result['per_page']); ?>
<?php portal_footer(); ?>
