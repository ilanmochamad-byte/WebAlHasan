<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Ui\Denial;

/**
 * Rincian satu tagihan bulanan (PRD V6 Fase 1): snapshot komponen, total,
 * terbayar, sisa, dan status turunan. Tagihan santri di luar hak pembaca
 * dijawab 404 oleh `BsaService::tagihan()`.
 */

require_once __DIR__ . '/_bsa.php';

$id = max(0, (int) ($_GET['id'] ?? 0));
try {
    $t = $bsa->tagihan($currentUser, $id);
} catch (BsaException $exception) {
    if ($exception->status === 404) {
        Denial::render('Tagihan tidak ditemukan', 'Tagihan yang Anda buka tidak ada atau bukan hak Anda.', 404,
            '<a class="btn btn-outline-primary" href="' . ah_e(bsa_url('bsa.php')) . '">Kembali ke daftar</a>', false);
    }
    Denial::page('Rincian tagihan', 'petugas BSA berpenugasan aktif, admin, dan orang tua atas anaknya', $exception->getMessage(), $exception->status);
}
$s = $t['santri'];
$kembali = bsa_url('bsa_santri.php', ['id' => $s['id']]);

ah_page_open([
    'title' => 'Tagihan ' . bsa_periode($t['periode']) . ' · ' . $s['nama'],
    'heading' => 'Tagihan ' . bsa_periode($t['periode']),
    'user' => $currentUser,
    'active' => 'bsa',
    'description' => $s['nama'] . ' · NIS ' . $s['nis'],
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => bsa_judul($aksesBsa), 'url' => bsa_url('bsa.php')],
        ['label' => $s['nama'], 'url' => $kembali], ['label' => 'Tagihan ' . bsa_periode($t['periode'])]],
]);
?>
<section class="ah-card" aria-labelledby="ah-tagihan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-tagihan"><?= ah_e(bsa_periode($t['periode'])) ?></h2><?= ah_status($t['status_bayar']) ?></div>
    <div class="ah-card__body"><dl class="ah-kv">
        <div><dt>Total</dt><dd><?= ah_e(bsa_rp($t['total'])) ?></dd></div>
        <div><dt>Terbayar</dt><dd><?= ah_e(bsa_rp($t['terbayar'])) ?></dd></div>
        <div><dt>Sisa</dt><dd><?= ah_e(bsa_rp($t['sisa'])) ?></dd></div>
        <div><dt>Jatuh tempo</dt><dd><?= ah_e(ah_tanggal($t['jatuh_tempo'], false)) ?></dd></div>
        <div><dt>Diterbitkan</dt><dd><?= ah_e(ah_waktu($t['diterbitkan_pada'])) ?></dd></div>
        <div><dt>Penerbit</dt><dd><?= ah_e($t['penerbit']['nama']) ?></dd></div>
    </dl></div>
</section>
<section class="ah-card" aria-labelledby="ah-komponen">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-komponen">Komponen biaya</h2><span class="text-muted small">Snapshot saat terbit</span></div>
    <div class="ah-table-wrap"><table class="ah-table"><caption class="ah-visually-hidden">Komponen biaya tagihan</caption>
        <thead><tr><th scope="col">Komponen</th><th scope="col" class="text-end">Nominal</th></tr></thead><tbody>
        <?php foreach ($t['komponen'] as $k): ?><tr><td><?= ah_e($k['nama']) ?></td><td class="text-end"><?= ah_e(bsa_rp($k['nominal'])) ?></td></tr><?php endforeach; ?>
        <tr><th scope="row">Total</th><td class="text-end fw-semibold"><?= ah_e(bsa_rp($t['total'])) ?></td></tr>
        </tbody></table></div>
    <?php if ($t['catatan'] !== null): ?><div class="ah-card__body"><p class="mb-0 ah-break"><b>Catatan:</b> <?= ah_e($t['catatan']) ?></p></div><?php endif; ?>
</section>
<p class="text-muted small">Pembayaran tunai/transfer dan kuitansi tersedia setelah modul tagihan lengkap (PRD V6 Fase 2). Tagihan tidak memotong tabungan secara otomatis.</p>
<p class="mt-3"><a class="btn btn-outline-secondary" href="<?= ah_e($kembali) ?>">Kembali ke rincian santri</a></p>
<?php
ah_page_close();
