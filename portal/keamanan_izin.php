<?php

declare(strict_types=1);

use App\Izin\IzinException;
use App\Izin\IzinLaporanRepository;

require_once __DIR__ . '/_keamanan.php';

/**
 * Keamanan › Perizinan santri (penugasan lintas guru/pengurus Fase 3).
 *
 * Seluruh pengajuan izin santri aktif, asal Pengurus (V2) maupun Wali (V7),
 * pada semua tahap. Cakupan, saringan, kartu, dan daftar berasal dari
 * `IzinLaporanService` dengan cakupan `keamanan` (satu pembangun WHERE), jadi
 * angka dan baris tidak dapat menyimpang. Hanya baca: tidak ada tombol ajukan,
 * tinjau, putus, koreksi, atau batal. Cetak/PDF tersedia; ekspor CSV tidak
 * (PK-T2). Isi lengkap (alasan, catatan, riwayat) ada di rincian, yang
 * pembukaannya diaudit.
 */

$svc = izin_laporan_service();
$galat = null;
try {
    $lap = $svc->laporan($pantauUser, $_GET);
} catch (IzinException $exception) {
    if ($exception->status() !== 422) {
        keamanan_tolak($exception->status(), $exception->getMessage());
    }
    // Saringan tidak valid: pesan + daftar tanpa saringan, bukan halaman kosong.
    $galat = $exception->getMessage();
    try {
        $lap = $svc->laporan($pantauUser, []);
    } catch (IzinException $ulang) {
        keamanan_tolak($ulang->status(), $ulang->getMessage());
    }
}
$f = $lap['filter'];
$k = $lap['ringkasan'];
$saring = array_filter($f, static fn ($v): bool => $v !== null && $v !== '' && $v !== 'izin');
$keSaring = static fn (array $tambah): string => keamanan_url('keamanan_izin.php', array_filter($tambah + $saring, static fn ($v): bool => $v !== null));
$angka = static fn (int $n): string => number_format($n, 0, ',', '.');
$labelTahap = [
    'pembimbing' => 'Tinjauan pembimbing', 'penetapan_pembimbing' => 'Penetapan pembimbing', 'perbaikan_wali' => 'Perbaikan wali',
    'murobi' => 'Keputusan murobi', 'penetapan_murobi' => 'Penetapan murobi', 'selesai' => 'Selesai',
];
$labelHasil = [
    'berjalan' => 'Masih berjalan', 'disetujui' => 'Disetujui', 'ditolak_murobi' => 'Ditolak murobi/admin',
    'ditolak_pembimbing' => 'Ditolak pembimbing', 'dibatalkan' => 'Dibatalkan',
];

ah_page_open([
    'title' => 'Perizinan santri',
    'user' => $currentUser,
    'active' => 'keamanan_izin',
    'description' => $lap['cakupan_label'] . '. Buka rincian untuk alasan dan riwayat.',
    'actions' => '<a class="btn btn-outline-primary" target="_blank" rel="noopener" href="' . ah_e(keamanan_url('keamanan_izin_cetak.php', $saring)) . '"><i class="fas fa-print" aria-hidden="true"></i> Cetak / PDF</a>',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Keamanan'],
        ['label' => 'Perizinan santri'],
    ],
]);
if ($galat !== null) {
    ah_note('warning', $galat . ' Daftar di bawah tanpa saringan.');
}

ah_filter_open(count($saring));
$pilihan = static function (string $nama, string $label, array $opsi, ?string $nilai): void {
    echo '<div><label class="form-label" for="f-' . ah_e($nama) . '">' . ah_e($label) . '</label><select class="form-select" id="f-'
        . ah_e($nama) . '" name="' . ah_e($nama) . '"><option value="">Semua</option>';
    foreach ($opsi as $kode => $teks) {
        echo '<option value="' . ah_e((string) $kode) . '"' . ($nilai === (string) $kode ? ' selected' : '') . '>' . ah_e($teks) . '</option>';
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
        <input class="form-control" type="date" id="f-dari" name="dari" value="<?= ah_e((string) $f['dari']) ?>"></div>
    <div><label class="form-label" for="f-sampai">Sampai</label>
        <input class="form-control" type="date" id="f-sampai" name="sampai" value="<?= ah_e((string) $f['sampai']) ?>"></div>
<?php
foreach (['santri_id', 'pembimbing_id', 'murobi_id', 'kamar_id', 'kelas_id'] as $tersembunyi) {
    if ($f[$tersembunyi] !== null) {
        echo '<input type="hidden" name="' . $tersembunyi . '" value="' . (int) $f[$tersembunyi] . '">';
    }
}
ah_filter_close();

echo ah_stats([
    ['label' => 'Total pengajuan', 'value' => $angka($k['total'])],
    ['label' => 'Masih berjalan', 'value' => $angka((int) $k['per_hasil']['berjalan']), 'url' => $keSaring(['hasil' => 'berjalan'])],
    ['label' => 'Disetujui', 'value' => $angka((int) $k['per_hasil']['disetujui']), 'url' => $keSaring(['hasil' => 'disetujui'])],
    ['label' => 'Asal wali', 'value' => $angka($k['per_asal']['Wali']), 'url' => $keSaring(['asal' => 'wali'])],
    ['label' => 'Asal pengurus', 'value' => $angka($k['per_asal']['Pengurus']), 'url' => $keSaring(['asal' => 'pengurus'])],
], 'Ringkasan perizinan');
?>
<section class="ah-card mt-3" aria-labelledby="ah-keamanan-izin">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-keamanan-izin">Daftar pengajuan</h2>
        <span class="text-muted small" data-total="<?= (int) $lap['total'] ?>"><?= count($lap['items']) ?> dari <?= (int) $lap['total'] ?></span></div>
<?php if ($lap['items'] === []): ?>
    <p class="text-muted mb-0 p-3">Tidak ada pengajuan yang cocok dengan saringan.</p>
<?php else: ?>
    <div class="ah-table-wrap"><table class="ah-table ah-table--cards">
        <caption class="ah-visually-hidden">Pengajuan izin santri aktif, satu baris per pengajuan</caption>
        <thead><tr><th scope="col">#</th><th scope="col">Asal</th><th scope="col">Santri</th><th scope="col">Keluar – kembali</th><th scope="col">Status</th>
            <th scope="col" class="ah-col-secondary">Pembimbing / murobi</th></tr></thead>
        <tbody>
        <?php foreach ($lap['items'] as $item): $url = app_url($item['detail']['web']); ?>
            <tr data-id="<?= (int) $item['id'] ?>">
                <td data-label="#"><a class="ah-row-link" href="<?= ah_e($url) ?>">#<?= (int) $item['id'] ?></a></td>
                <td data-label="Asal"><?= ah_badge($item['asal'], $item['asal'] === 'Wali' ? 'info' : 'muted') ?></td>
                <td data-label="Santri"><span class="ah-cell-wrap"><?= ah_e($item['santri']['nama']) ?><span class="ah-cell-sub">NIS <?= ah_e($item['santri']['nis']) ?><?= $item['kamar'] !== null ? ' · ' . ah_e($item['kamar']) : '' ?></span></span></td>
                <td data-label="Keluar – kembali"><?= ah_e(ah_rentang($item['tgl_izin'], $item['tgl_kembali'])) ?></td>
                <td data-label="Status"><?= keamanan_izin_status((string) $item['status']) ?></td>
                <td data-label="Pembimbing / murobi" class="ah-col-secondary"><?= ah_e($item['pembimbing'] ?? $item['pengurus'] ?? '—') ?><span class="ah-cell-sub"><?= ah_e($item['murobi'] ?? '—') ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
</section>
<?php
ah_pagination((int) $lap['total'], (int) $lap['page'], (int) $lap['per_page']);
ah_page_close();
