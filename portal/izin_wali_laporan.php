<?php

declare(strict_types=1);

use App\Izin\IzinException;
use App\Izin\IzinLaporanRepository;

require_once __DIR__ . '/_izin_wali.php';

/**
 * Laporan perizinan lintas asal wali/pengurus (PRD V7 Fase 3).
 *
 * Cakupan mengikuti peran aktif sesi, tetapi dihitung ulang dari basis data
 * oleh `IzinLaporanService` pada setiap permintaan: admin seluruh pengajuan;
 * pengurus = pengajuan miliknya + pengajuan wali yang ditujukan kepadanya
 * sebagai pembimbing sah; murobi = pengajuan yang diarahkan kepadanya; orang
 * tua = anak berelasi aktif (pengajuan wali lain hanya status). Kartu, tabel,
 * dan ekspor memakai saringan yang sama; alasan izin tidak ditampilkan.
 */

$mode = (string) portal_requested_mode($currentUser, $userCapabilities);
$svc = izin_laporan_service();
$query = $_GET + ['peran' => $mode];
$galat = null;
try {
    $lap = $svc->laporan($currentUser, $query);
} catch (IzinException $exception) {
    if ($exception->status() !== 422) {
        izin_wali_tolak($exception, $currentUser, $userCapabilities, $mode);
    }
    // Saringan tidak valid: pesan + laporan tanpa saringan, bukan halaman kosong.
    $galat = $exception->getMessage();
    $lap = $svc->laporan($currentUser, ['peran' => $mode]);
}
$f = $lap['filter'];
$k = $lap['ringkasan'];
$wali = $lap['peran'] === 'orang_tua';
$alamat = app_url('/portal/izin_wali_laporan.php');
$saring = array_filter($f, static fn ($v): bool => $v !== null && $v !== '' && $v !== 'izin');
$keSaring = static fn (array $tambah): string => $alamat . '?' . http_build_query(array_filter($tambah + $saring, static fn ($v): bool => $v !== null));
$angka = static fn (int $n): string => number_format($n, 0, ',', '.');
$jam = static fn (?float $j): string => $j === null ? '—' : number_format($j, 1, ',', '.') . ' jam';
$labelTahap = [
    'pembimbing' => 'Tinjauan pembimbing', 'penetapan_pembimbing' => 'Penetapan pembimbing', 'perbaikan_wali' => 'Perbaikan wali',
    'murobi' => 'Keputusan murobi', 'penetapan_murobi' => 'Penetapan murobi', 'selesai' => 'Selesai',
];
$labelHasil = [
    'berjalan' => 'Masih berjalan', 'disetujui' => 'Disetujui', 'ditolak_murobi' => 'Ditolak murobi/admin',
    'ditolak_pembimbing' => 'Ditolak pembimbing', 'dibatalkan' => 'Dibatalkan',
];

portal_header('Laporan pengajuan izin wali dan pengurus', $userCapabilities, $mode, $currentUser, [
    'active' => $wali ? 'izin_anak' : 'izin',
    'description' => $lap['cakupan_label'] . '. Tanpa alasan izin; buka rincian untuk isi sesuai hak Anda.',
    'actions' => '<a class="btn btn-outline-secondary" href="' . portal_e(app_url('/portal/izin_wali_laporan_ekspor.php') . '?'
        . http_build_query($saring + ['peran' => $lap['peran']])) . '"><i class="fas fa-file-csv" aria-hidden="true"></i> Ekspor CSV</a>',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Pengajuan wali', 'url' => app_url('/portal/izin_wali.php')],
        ['label' => 'Laporan'],
    ],
]);
portal_flash_render();
if ($galat !== null): ?>
    <div class="alert alert-warning" role="alert"><?= portal_e($galat) ?> Laporan di bawah tanpa saringan.</div>
<?php endif;

ah_filter_open(count($saring));
$pilihan = static function (string $nama, string $label, array $opsi, ?string $nilai): void {
    echo '<div><label class="form-label" for="f-' . portal_e($nama) . '">' . portal_e($label) . '</label><select class="form-select" id="f-'
        . portal_e($nama) . '" name="' . portal_e($nama) . '"><option value="">Semua</option>';
    foreach ($opsi as $kode => $teks) {
        echo '<option value="' . portal_e((string) $kode) . '"' . ($nilai === (string) $kode ? ' selected' : '') . '>' . portal_e($teks) . '</option>';
    }
    echo '</select></div>';
};
$pilihan('asal', 'Asal pengajuan', ['wali' => 'Wali', 'pengurus' => 'Pengurus'], $f['asal']);
$pilihan('tahap', 'Tahap', $labelTahap, $f['tahap']);
$pilihan('hasil', 'Hasil', $labelHasil, $f['hasil']);
?>
    <div><label class="form-label" for="f-basis">Basis tanggal</label>
        <select class="form-select" id="f-basis" name="basis">
            <option value="izin"<?= $f['basis'] === 'izin' ? ' selected' : '' ?>>Rentang izin</option>
            <option value="diajukan"<?= $f['basis'] === 'diajukan' ? ' selected' : '' ?>>Tanggal diajukan</option>
        </select></div>
    <div><label class="form-label" for="f-dari">Dari tanggal</label>
        <input class="form-control" type="date" id="f-dari" name="dari" value="<?= portal_e((string) $f['dari']) ?>"></div>
    <div><label class="form-label" for="f-sampai">Sampai</label>
        <input class="form-control" type="date" id="f-sampai" name="sampai" value="<?= portal_e((string) $f['sampai']) ?>"></div>
<?php if (!$wali): ?>
    <div><label class="form-label" for="f-min">Lama total minimal (jam)</label>
        <input class="form-control" type="number" min="1" id="f-min" name="durasi_min_jam" value="<?= portal_e((string) $f['durasi_min_jam']) ?>"></div>
    <div><label class="form-label" for="f-maks">Lama total maksimal (jam)</label>
        <input class="form-control" type="number" min="1" id="f-maks" name="durasi_maks_jam" value="<?= portal_e((string) $f['durasi_maks_jam']) ?>"></div>
<?php endif;
foreach (['santri_id', 'pembimbing_id', 'murobi_id', 'kamar_id', 'kelas_id'] as $tersembunyi) {
    if ($f[$tersembunyi] !== null) {
        echo '<input type="hidden" name="' . $tersembunyi . '" value="' . (int) $f[$tersembunyi] . '">';
    }
}
ah_filter_close();

echo ah_stats([
    ['label' => 'Total pengajuan', 'value' => $angka($k['total'])],
    ['label' => 'Asal wali', 'value' => $angka($k['per_asal']['Wali']), 'url' => $keSaring(['asal' => 'wali'])],
    ['label' => 'Asal pengurus', 'value' => $angka($k['per_asal']['Pengurus']), 'url' => $keSaring(['asal' => 'pengurus'])],
], 'Jumlah menurut asal');
echo '<h2 class="h6 mt-3">Menurut tahap</h2>';
echo ah_stats(array_map(static fn (string $kode): array => [
    'label' => $labelTahap[$kode], 'value' => $angka((int) $k['per_tahap'][$kode]), 'url' => $keSaring(['tahap' => $kode]),
], array_keys(IzinLaporanRepository::TAHAP)), 'Jumlah menurut tahap');
echo '<h2 class="h6 mt-3">Menurut hasil</h2>';
echo ah_stats(array_map(static fn (string $kode): array => [
    'label' => $labelHasil[$kode], 'value' => $angka((int) $k['per_hasil'][$kode]), 'url' => $keSaring(['hasil' => $kode]),
], array_keys(IzinLaporanRepository::HASIL)), 'Jumlah menurut hasil');
if (!$wali) {
    $r = $k['rerata_lama_jam'];
    echo '<h2 class="h6 mt-3">Rerata lama tahap</h2>';
    echo ah_stats([
        ['label' => 'Tahap pembimbing', 'value' => $jam($r['pembimbing']), 'hint' => $angka($r['n_pembimbing']) . ' pengajuan wali'],
        ['label' => 'Tahap murobi', 'value' => $jam($r['murobi']), 'hint' => $angka($r['n_murobi']) . ' sudah diputus'],
        ['label' => 'Total sampai selesai', 'value' => $jam($r['total']), 'hint' => $angka($r['n_total']) . ' selesai'],
    ], 'Rerata lama tahap');
}
?>
<section class="ah-card mt-3" aria-labelledby="ah-laporan-daftar">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-laporan-daftar">Daftar pengajuan</h2>
        <span class="text-muted small" data-total="<?= (int) $lap['total'] ?>"><?= count($lap['items']) ?> dari <?= (int) $lap['total'] ?></span></div>
<?php if ($lap['items'] === []): ?>
    <p class="text-muted mb-0 p-3">Tidak ada pengajuan yang cocok dengan saringan.</p>
<?php else: ?>
    <div class="ah-table-wrap"><table class="ah-table ah-table--cards">
        <caption class="ah-visually-hidden">Pengajuan izin, satu baris per pengajuan</caption>
        <thead><tr><th scope="col">#</th><th scope="col">Asal</th><th scope="col">Santri</th><th scope="col">Rentang</th><th scope="col">Status</th>
            <?php if ($wali): ?><th scope="col">Pengaju</th><?php else: ?><th scope="col" class="ah-col-secondary">Pembimbing / murobi</th><th scope="col">Lama total</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($lap['items'] as $item): $url = app_url($item['detail']['web']); ?>
            <tr data-id="<?= (int) $item['id'] ?>">
                <td data-label="#"><a class="ah-row-link" href="<?= portal_e($url) ?>">#<?= (int) $item['id'] ?></a></td>
                <td data-label="Asal"><?= ah_badge($item['asal'], $item['asal'] === 'Wali' ? 'info' : 'muted') ?></td>
                <td data-label="Santri"><span class="ah-cell-wrap"><?= portal_e($item['santri']['nama']) ?><span class="ah-cell-sub">NIS <?= portal_e($item['santri']['nis']) ?><?= !$wali && $item['kamar'] !== null ? ' · ' . portal_e($item['kamar']) : '' ?></span></span></td>
                <td data-label="Rentang"><?= portal_e(ah_rentang($item['tgl_izin'], $item['tgl_kembali'])) ?></td>
                <td data-label="Status"><?= izin_wali_status((string) $item['status']) ?></td>
                <?php if ($wali): ?>
                    <td data-label="Pengaju"><?= portal_e($item['pengaju']) ?></td>
                <?php else: ?>
                    <td data-label="Pembimbing / murobi" class="ah-col-secondary"><?= portal_e($item['pembimbing'] ?? '—') ?><span class="ah-cell-sub"><?= portal_e($item['murobi'] ?? '—') ?></span></td>
                    <td data-label="Lama total"><?= portal_e($jam($item['lama_jam']['total'])) ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
</section>
<?php
portal_pagination((int) $lap['total'], (int) $lap['page'], (int) $lap['per_page']);
portal_footer();
