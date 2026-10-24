<?php

declare(strict_types=1);

use App\Kesehatan\KesehatanException;
use App\Kesehatan\KesehatanService;
use App\Ui\Denial;

/**
 * Riwayat versi satu kunjungan klinik (PRD V5 Fase 2).
 *
 * Hanya petugas kesehatan dan admin (`riwayat_versi`); pembimbing dan murobi
 * membaca versi terbaru saja dan ditolak 403 di sini. Setiap pembukaan
 * tercatat di riwayat akses (aksi `baca_versi`). Tiap versi menampilkan
 * pelaku, waktu, alasan, dan perubahan sebelum–sesudah; tidak ada tombol
 * ubah atau hapus versi.
 */

require_once __DIR__ . '/_kesehatan.php';

$id = max(0, (int) ($_GET['id'] ?? 0));
try {
    $riwayat = $kesehatan->riwayatVersi($currentUser, $id, KesehatanService::KANAL_WEB);
} catch (KesehatanException $exception) {
    if ($exception->status === 404) {
        Denial::render('Kunjungan tidak ditemukan', 'Kunjungan yang Anda buka tidak ada.', 404, '<a class="btn btn-outline-primary" href="' . ah_e(kesehatan_url('kesehatan.php')) . '">Kembali ke daftar</a>', false);
    }
    Denial::page('Riwayat versi kunjungan', 'petugas kesehatan berpenugasan aktif dan admin', $exception->getMessage(), $exception->status);
}
$k = $riwayat['kunjungan'];

$labelKolom = [
    'status' => 'Status', 'waktu_kunjungan' => 'Waktu kunjungan', 'keluhan' => 'Keluhan', 'catatan_klinis' => 'Catatan klinis', 'tindakan' => 'Tindakan',
    'nama_obat' => 'Nama obat', 'jumlah' => 'Jumlah', 'satuan' => 'Satuan', 'petunjuk' => 'Petunjuk', 'waktu_pemberian' => 'Waktu pemberian',
    'tujuan' => 'Tujuan', 'tanggal_rujukan' => 'Tanggal rujukan', 'alasan' => 'Alasan/catatan', 'hasil' => 'Hasil', 'tanggal_hasil' => 'Tanggal hasil',
    'uraian' => 'Uraian', 'tanggal_target' => 'Target', 'tanggal_selesai' => 'Tanggal selesai',
];
$labelBagian = ['kunjungan' => 'Kunjungan', 'obat' => 'Obat', 'rujukan' => 'Rujukan', 'tindak_lanjut' => 'Tindak lanjut'];
$nilai = static fn (mixed $v): string => $v === null || $v === '' ? '—' : (string) $v;
$ringkasBaris = static function (?array $r) use ($labelKolom, $nilai): string {
    if ($r === null) {
        return '—';
    }
    $bagian = [];
    foreach ($r as $kolom => $v) {
        if ($kolom !== 'id' && $v !== null && $v !== '') {
            $bagian[] = ($labelKolom[$kolom] ?? $kolom) . ': ' . $nilai($v);
        }
    }

    return implode(' · ', $bagian);
};

ah_page_open([
    'title' => 'Riwayat versi ' . $k['santri']['nama'],
    'heading' => 'Riwayat versi kunjungan',
    'user' => $currentUser,
    'active' => 'kesehatan',
    'description' => $k['santri']['nama'] . ' · ' . ah_waktu($k['waktu_kunjungan']) . ' · versi terbaru ' . (int) $k['versi'],
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Kesehatan', 'url' => kesehatan_url('kesehatan.php')],
        ['label' => 'Detail kunjungan', 'url' => kesehatan_url('kesehatan_detail.php', ['id' => $k['id']])], ['label' => 'Riwayat versi']],
]);
?>
<div class="ah-ribbon" role="note"><i class="fas fa-lock mt-1" aria-hidden="true"></i>
    <span>Riwayat internal. Hanya petugas kesehatan dan admin; pembukaan halaman ini tercatat di riwayat akses.</span></div>
<?php foreach ($riwayat['versi'] as $v): ?>
<section class="ah-card" aria-labelledby="ah-versi-<?= (int) $v['versi'] ?>">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-versi-<?= (int) $v['versi'] ?>">Versi <?= (int) $v['versi'] ?><?= $v['versi'] === $k['versi'] ? ' (terbaru)' : '' ?></h2><?= ah_status($v['status']) ?></div>
    <div class="ah-card__body">
        <p class="small text-muted mb-2"><?= ah_e(ah_waktu($v['waktu'])) ?> · <?= ah_e($v['oleh']['nama']) ?></p>
        <?php if ($v['versi'] === 1): ?>
            <p class="mb-0">Pencatatan awal.</p>
        <?php else: ?>
            <p class="mb-2"><b>Alasan:</b> <span class="ah-break"><?= $v['alasan'] === null ? '<span class="text-muted">— (draf)</span>' : nl2br(ah_e($v['alasan'])) ?></span></p>
            <?php if ($v['perubahan'] === []): ?><p class="text-muted mb-0">Tidak ada perubahan isi yang terbaca.</p>
            <?php else: ?><div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Perubahan pada versi <?= (int) $v['versi'] ?></caption>
                <thead><tr><th scope="col">Bagian</th><th scope="col">Perubahan</th><th scope="col">Sebelum</th><th scope="col">Sesudah</th></tr></thead><tbody>
                <?php foreach ($v['perubahan'] as $p): ?><tr>
                    <td data-label="Bagian"><?= ah_e($labelBagian[$p['bagian']] ?? $p['bagian']) ?></td>
                    <td data-label="Perubahan"><?= ah_e(match ($p['jenis']) { 'tambah' => 'Ditambahkan', 'hapus' => 'Dihapus', default => $labelKolom[$p['kolom']] ?? (string) $p['kolom'] }) ?></td>
                    <td data-label="Sebelum"><span class="ah-break"><?= nl2br(ah_e(is_array($p['sebelum']) || $p['sebelum'] === null ? $ringkasBaris($p['sebelum']) : $nilai($p['sebelum']))) ?></span></td>
                    <td data-label="Sesudah"><span class="ah-break"><?= nl2br(ah_e(is_array($p['sesudah']) || $p['sesudah'] === null ? $ringkasBaris($p['sesudah']) : $nilai($p['sesudah']))) ?></span></td>
                </tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<?php endforeach; ?>
<p class="mt-3"><a class="btn btn-outline-secondary" href="<?= ah_e(kesehatan_url('kesehatan_detail.php', ['id' => $k['id']])) ?>">Kembali ke detail</a></p>
<?php
ah_page_close();
