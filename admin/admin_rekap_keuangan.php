<?php

declare(strict_types=1);

use App\Psb\PsbPembayaran;

require_once __DIR__ . '/_guard.php';

/**
 * PSB › Rekap keuangan (redesain website Tahap 3, K-7).
 *
 * Matriks komponen biaya × unit × putra/putri dengan aturan pengelompokan
 * lama; kolom komponen lengket saat tabel digeser. Di HP matriks menjadi
 * lipatan per unit. Unduhan Excel dibuat di server dari angka yang sama.
 */

$status = in_array($_GET['status'] ?? 'Lunas', ['Lunas', 'Belum Lunas', 'Semua'], true) ? (string) ($_GET['status'] ?? 'Lunas') : 'Lunas';
$rekap = (new PsbPembayaran($koneksi))->rekap($status);
$self = app_url('/admin/admin_rekap_keuangan.php');
$angka = static fn (int $nilai): string => $nilai > 0 ? number_format($nilai, 0, ',', '.') : '–';
$judulStatus = ['Lunas' => 'Dana masuk (lunas)', 'Belum Lunas' => 'Tagihan belum lunas', 'Semua' => 'Semua tagihan (potensi kas)'][$status];

if (isset($_GET['unduh'])) {
    $baris = [['KOMPONEN BIAYA', 'SMP PUTRA', 'SMP PUTRI', 'SMK PUTRA', 'SMK PUTRI', 'LAINNYA PUTRA', 'LAINNYA PUTRI', 'TOTAL']];
    foreach ($rekap['komponen'] as $nama => $nilai) {
        $baris[] = [$nama, ...array_values($nilai), array_sum($nilai)];
    }
    $baris[] = ['TOTAL', ...array_values($rekap['kolom']), $rekap['total']];
    ah_xlsx_download('Rekap_Keuangan_PSB_AlHasan_' . str_replace(' ', '_', $status) . '_' . date('Y-m-d') . '.xlsx', $baris);
}

$tahun = beranda_service()->tahunAktif();
ah_page_open([
    'title' => 'Rekap keuangan PSB',
    'heading' => 'Rekap keuangan PSB' . ($tahun !== null ? ' ' . $tahun['tahun'] : ''),
    'user' => $currentUser,
    'active' => 'psb.rekap',
    'description' => 'Komponen biaya dibongkar dari setiap tagihan pendaftar. Komponen yang nol di semua unit tidak ditampilkan.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'PSB'], ['label' => 'Rekap keuangan']],
    'actions' => '<a class="btn btn-outline-secondary" href="' . ah_e($self . '?' . http_build_query(['status' => $status, 'unduh' => 1])) . '">Ekspor Excel</a>',
]);
echo ah_chips([
    ['label' => 'Lunas', 'url' => $self, 'active' => $status === 'Lunas'],
    ['label' => 'Belum lunas', 'url' => $self . '?status=' . rawurlencode('Belum Lunas'), 'active' => $status === 'Belum Lunas'],
    ['label' => 'Semua', 'url' => $self . '?status=Semua', 'active' => $status === 'Semua'],
], 'Status dana');

if ($rekap['komponen'] === []):
    echo '<div class="ah-card ah-card__body">' . ah_empty('Belum ada angka untuk ' . strtolower($judulStatus), 'Rekap terisi setelah ada tagihan dengan status ini.') . '</div>';
else:
    ?>
    <section class="ah-card ah-hide-sm" aria-labelledby="judul-rekap">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="judul-rekap"><?= ah_e($judulStatus) ?> · total Rp <?= number_format($rekap['total'], 0, ',', '.') ?></h2></div>
        <div class="ah-table-wrap">
            <table class="ah-matrix" id="tabelRekap">
                <caption class="ah-visually-hidden">Rekap keuangan PSB per komponen, unit, dan putra/putri dalam rupiah</caption>
                <thead>
                    <tr><th scope="col" rowspan="2">Komponen</th><?php foreach (PsbPembayaran::UNIT_REKAP as $label): ?><th scope="colgroup" colspan="2"><?= ah_e($label) ?></th><?php endforeach; ?><th scope="col" rowspan="2">Total</th></tr>
                    <tr><?php foreach (PsbPembayaran::UNIT_REKAP as $_): ?><th scope="col">Putra</th><th scope="col">Putri</th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                <?php foreach ($rekap['komponen'] as $nama => $nilai): ?>
                    <tr><th scope="row"><?= ah_e($nama) ?></th><?php foreach (PsbPembayaran::KOLOM as $k): ?><td><?= $angka($nilai[$k]) ?></td><?php endforeach; ?><td class="ah-matrix__total"><?= number_format(array_sum($nilai), 0, ',', '.') ?></td></tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><th scope="row">Total</th><?php foreach (PsbPembayaran::KOLOM as $k): ?><td><?= number_format($rekap['kolom'][$k], 0, ',', '.') ?></td><?php endforeach; ?><td><?= number_format($rekap['total'], 0, ',', '.') ?></td></tr>
                </tfoot>
            </table>
        </div>
    </section>
    <section class="ah-only-sm" aria-label="Rekap per unit">
        <p class="ah-meta"><?= ah_e($judulStatus) ?> · total Rp <?= number_format($rekap['total'], 0, ',', '.') ?></p>
        <?php foreach (PsbPembayaran::UNIT_REKAP as $kode => $label): ?>
            <details class="ah-fold">
                <summary><span><?= ah_e($label) ?></span><b>Rp <?= number_format($rekap['kolom'][$kode . '_L'] + $rekap['kolom'][$kode . '_P'], 0, ',', '.') ?></b></summary>
                <ul class="ah-list-plain">
                    <?php foreach ($rekap['komponen'] as $nama => $nilai): ?>
                        <?php if ($nilai[$kode . '_L'] + $nilai[$kode . '_P'] > 0): ?>
                            <li><span><?= ah_e($nama) ?><span class="ah-cell-sub">Putra <?= $angka($nilai[$kode . '_L']) ?> · Putri <?= $angka($nilai[$kode . '_P']) ?></span></span><b><?= number_format($nilai[$kode . '_L'] + $nilai[$kode . '_P'], 0, ',', '.') ?></b></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endforeach; ?>
    </section>
    <?php
endif;
ah_page_close();
