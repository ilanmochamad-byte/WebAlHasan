<?php

declare(strict_types=1);

use App\Beranda\RingkasanAdministrasi;
use App\Ui\Chart;

require_once __DIR__ . '/_guard.php';

/**
 * Beranda › Ringkasan administrasi (redesain website Tahap 3, N-4).
 *
 * Grafik dikelompokkan per tema — PSB, Santri & asrama, Kegiatan — di dalam
 * kerangka bersama. Grafik dirender server tanpa pustaka (App\Ui\Chart),
 * warnanya dari token, dan setiap grafik punya keadaan kosong. Semua data
 * hanya dibaca (`RingkasanAdministrasi`, `report_service`).
 */

$tema = in_array($_GET['tema'] ?? '', ['santri', 'kegiatan'], true) ? (string) $_GET['tema'] : 'psb';
$ringkasan = new RingkasanAdministrasi($koneksi);
$tahun = beranda_service()->tahunAktif();
$alamat = app_url('/admin/admin_dashboard.php');
$angka = static fn (int $nilai): string => number_format($nilai, 0, ',', '.');

ah_page_open([
    'title' => 'Ringkasan administrasi',
    'heading' => "Assalamu'alaikum, " . (string) ($currentUser['name'] ?? ''),
    'user' => $currentUser,
    'active' => 'beranda',
    'description' => 'Ringkasan per tema. Angka dihitung saat halaman dibuka.',
    'actions' => '<a class="btn btn-outline-secondary" href="' . ah_e(app_url('/index.php')) . '" target="_blank" rel="noopener">Lihat situs ↗</a>',
]);
echo ah_chips([
    ['label' => 'PSB', 'url' => $alamat, 'active' => $tema === 'psb'],
    ['label' => 'Santri & asrama', 'url' => $alamat . '?tema=santri', 'active' => $tema === 'santri'],
    ['label' => 'Kegiatan', 'url' => $alamat . '?tema=kegiatan', 'active' => $tema === 'kegiatan'],
], 'Tema ringkasan');

if ($tema === 'psb'):
    $psb = $ringkasan->psb();
    if ($psb === null):
        ah_note('warning', 'Data PSB belum dapat dibaca. Coba muat ulang halaman.');
    else:
        echo ah_stats([
            ['label' => 'Total pendaftar', 'value' => $angka($psb['total']), 'url' => app_url('/admin/admin_data.php')],
            ['label' => 'Baru', 'value' => $angka($psb['status']['Baru']), 'url' => app_url('/admin/admin_data.php?status=Baru'), 'warn' => $psb['status']['Baru'] > 0],
            ['label' => 'Pembayaran belum lunas', 'value' => $angka($psb['belum_lunas']), 'url' => app_url('/admin/admin_pembayaran_psb.php?status=' . rawurlencode('Belum Lunas'))],
            ['label' => 'Kas masuk (lunas)', 'value' => 'Rp ' . $angka($psb['kas']), 'url' => app_url('/admin/admin_rekap_keuangan.php')],
        ], 'Ringkasan PSB');
        ?>
        <div class="ah-chart-grid">
            <section class="ah-card ah-card__body"><?= Chart::bar('Pendaftar per unit', $psb['unit'], 'Belum ada pendaftar.') ?></section>
            <section class="ah-card ah-card__body"><?= Chart::donut('Status pendaftaran', $psb['status'], 'Belum ada pendaftar.') ?></section>
        </div>
        <?php
    endif;

elseif ($tema === 'santri'):
    $santri = $ringkasan->santri((int) ($tahun['id'] ?? 0));
    if ($tahun === null) {
        ah_note('warning', 'Tahun ajaran aktif belum diatur; jenjang dan hunian dihitung setelah tahun ajaran aktif ditetapkan.');
    }
    if ($santri === null):
        ah_note('warning', 'Data santri belum dapat dibaca. Coba muat ulang halaman.');
    else:
        $persen = $santri['kapasitas'] > 0 ? (int) round(100 * $santri['terisi'] / $santri['kapasitas']) : 0;
        echo ah_stats([
            ['label' => 'Santri aktif', 'value' => $angka($santri['aktif']), 'url' => app_url('/admin/admin_master_santri.php')],
            ['label' => 'Laki-laki', 'value' => $angka($santri['l'])],
            ['label' => 'Perempuan', 'value' => $angka($santri['p'])],
            ['label' => 'Hunian asrama', 'value' => $santri['kapasitas'] > 0 ? $persen . '%' : '—', 'url' => app_url('/admin/admin_kamar.php'), 'warn' => $persen > 90],
        ], 'Ringkasan santri dan asrama');
        ?>
        <div class="ah-chart-grid">
            <section class="ah-card ah-card__body"><?= Chart::bar('Santri aktif per jenjang', $santri['jenjang'], 'Belum ada santri aktif.') ?></section>
            <section class="ah-card ah-card__body"><?= Chart::donut('Rasio laki-laki dan perempuan', ['Laki-laki' => $santri['l'], 'Perempuan' => $santri['p']], 'Belum ada santri aktif.') ?></section>
            <section class="ah-card ah-card__body" aria-labelledby="judul-hunian">
                <h2 class="ah-chart__title" id="judul-hunian">Hunian asrama</h2>
                <?php if ($santri['kapasitas'] <= 0): ?>
                    <p class="ah-chart__empty">Kapasitas kamar belum diisi.</p>
                <?php else: ?>
                    <?= Chart::progress([['label' => 'Seluruh kamar', 'value' => $santri['terisi'], 'max' => $santri['kapasitas'], 'hint' => $santri['terisi'] . '/' . $santri['kapasitas'] . ' tempat']]) ?>
                    <p class="ah-meta mt-2 mb-2">Penghuni putra <?= $angka($santri['terisi_l']) ?> · putri <?= $angka($santri['terisi_p']) ?>. Asrama putra/putri per kamar dicatat setelah kolom asrama tersedia.</p>
                    <?php if ($santri['kamar_padat'] > 0) { ah_note('warning', $santri['kamar_padat'] . ' kamar terisi di atas 90%.'); } ?>
                <?php endif; ?>
                <div class="ah-actions mt-2">
                    <a class="btn btn-sm btn-outline-primary" href="<?= ah_e(app_url('/admin/admin_penempatan_santri.php')) ?>">Penempatan kelas &amp; kamar</a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= ah_e(app_url('/admin/admin_rekap_santri.php')) ?>">Rekap jumlah santri</a>
                </div>
            </section>
        </div>
        <?php
    endif;

else:
    $reportFrom = (string) ($_GET['report_from'] ?? date('Y-m-01'));
    $reportTo = (string) ($_GET['report_to'] ?? date('Y-m-d'));
    $ringkasAbsensi = null;
    $galatAbsensi = null;
    try {
        $ringkasAbsensi = report_service()->dashboardSummary(['date_from' => $reportFrom, 'date_to' => $reportTo], $currentUser)['summary'];
    } catch (\App\Api\ApiException $exception) {
        $galatAbsensi = $exception->getMessage();
    }
    $pekanan = RingkasanAdministrasi::kehadiranPekanan(static function (string $dari, string $sampai) use ($currentUser): ?array {
        try {
            return report_service()->dashboardSummary(['date_from' => $dari, 'date_to' => $sampai], $currentUser)['summary']['statuses'];
        } catch (\Throwable $exception) {
            return null;
        }
    });
    $izin = $ringkasan->izinBulanIni();
    $pelanggaran = $ringkasan->pelanggaranBulanIni();
    ?>
    <section class="ah-card" aria-labelledby="ringkasan-absensi">
        <div class="ah-card__head flex-wrap gap-2">
            <h2 class="h6 mb-0" id="ringkasan-absensi">Pertemuan &amp; kehadiran pengajian</h2>
            <a class="btn btn-sm btn-outline-primary" href="<?= ah_e(app_url('/admin/admin_laporan_absensi.php?' . http_build_query(['date_from' => $reportFrom, 'date_to' => $reportTo]))) ?>">Buka laporan</a>
        </div>
        <div class="ah-card__body">
            <?php ah_filter_open(0, ['tema' => 'kegiatan']); ?>
                <div><label class="form-label" for="report_from">Dari</label><input class="form-control" id="report_from" name="report_from" type="date" value="<?= ah_e($reportFrom) ?>"></div>
                <div><label class="form-label" for="report_to">Sampai</label><input class="form-control" id="report_to" name="report_to" type="date" value="<?= ah_e($reportTo) ?>"></div>
            <?php ah_filter_close(['tema' => 'kegiatan']); ?>
            <?php if ($galatAbsensi !== null): ?>
                <?php ah_note('danger', $galatAbsensi); ?>
            <?php elseif ($ringkasAbsensi !== null): ?>
                <?= ah_stats(array_merge(
                    [['label' => 'Pertemuan', 'value' => $angka((int) $ringkasAbsensi['meeting_count'])]],
                    array_map(static fn (string $label, int $nilai): array => ['label' => $label, 'value' => number_format($nilai, 0, ',', '.')], array_keys($ringkasAbsensi['statuses']), $ringkasAbsensi['statuses'])
                ), 'Ringkasan kehadiran pada rentang terpilih') ?>
            <?php endif; ?>
        </div>
    </section>
    <div class="ah-chart-grid">
        <section class="ah-card ah-card__body"><?= Chart::line('Kehadiran pengajian 10 pekan (hadir + terlambat)', $pekanan, 'Belum ada catatan kehadiran dalam 10 pekan terakhir.') ?></section>
        <section class="ah-card ah-card__body">
            <?= $izin === null ? '<p class="ah-chart__empty">Data perizinan belum dapat dibaca.</p>' : Chart::bar('Izin bulan ini per status', $izin, 'Belum ada pengajuan izin bulan ini.') ?>
            <div class="ah-actions mt-2"><a class="btn btn-sm btn-outline-primary" href="<?= ah_e(app_url('/portal/izin_ringkasan.php')) ?>">Buka perizinan</a></div>
        </section>
        <?php if ($pelanggaran !== null): ?>
        <section class="ah-card ah-card__body">
            <h2 class="ah-chart__title">Pembinaan</h2>
            <?= ah_stats([['label' => 'Pelanggaran dicatat bulan ini', 'value' => $angka($pelanggaran), 'url' => app_url('/portal/v3_pelanggaran.php')]], 'Ringkasan pembinaan') ?>
        </section>
        <?php endif; ?>
    </div>
    <?php
endif;

ah_page_close();
