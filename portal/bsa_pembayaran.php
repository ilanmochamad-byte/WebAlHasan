<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Database\PageQuery;
use App\Ui\Denial;

/**
 * Riwayat pembayaran tagihan (PRD V6 Fase 2). Petugas BSA melihat santri
 * aktif dalam cakupannya (termasuk transfer menunggu verifikasi), admin
 * seluruh pembayaran (hanya baca), orang tua pembayaran sah/dibatalkan
 * anaknya tanpa referensi dan catatan internal.
 */

require_once __DIR__ . '/_bsa.php';

bsa_wajib_fase2($tagihanBsa);
$wali = $aksesBsa['sumber'] === 'wali';
$q = PageQuery::term($_GET['q'] ?? '');
try {
    $d = $tagihanBsa->daftarPembayaran($currentUser, ['periode' => $_GET['periode'] ?? null, 'status' => $_GET['status'] ?? null, 'sumber' => $_GET['sumber'] ?? null,
        'q' => $q, 'page' => $_GET['page'] ?? 1]);
} catch (BsaException $exception) {
    Denial::page('Pembayaran', 'petugas BSA berpenugasan aktif, admin, dan orang tua atas anaknya', $exception->getMessage(), $exception->status);
}
$aktif = count(array_filter($d['saring'], static fn ($v): bool => $v !== null));

ah_page_open([
    'title' => 'Pembayaran tagihan',
    'user' => $currentUser,
    'active' => 'bsa',
    'description' => $wali ? 'Pembayaran tagihan anak Anda beserta kuitansinya.' : 'Pembayaran tunai/transfer atas tagihan bulanan' . ($aksesBsa['kelola'] ? ' pada ' . bsa_cakupan_teks($aksesBsa) : ' (hanya baca)') . '.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => bsa_judul($aksesBsa), 'url' => bsa_url('bsa.php')], ['label' => 'Pembayaran']],
]);
echo bsa_nav($aksesBsa, 'bsa_pembayaran.php', $tagihanBsa);
if (!$wali) {
    ah_list_search($q, 'Cari nama santri, NIS, atau nomor kuitansi', array_filter($d['saring'], static fn ($v): bool => $v !== null));
}
ah_filter_open($aktif, ['q' => $q], bsa_url('bsa_pembayaran.php')); ?>
    <div><label class="form-label" for="saring-periode">Periode tagihan</label>
        <input class="form-control" type="month" id="saring-periode" name="periode" value="<?= ah_e((string) $d['saring']['periode']) ?>"></div>
    <div><label class="form-label" for="saring-status">Status</label>
        <select class="form-select" id="saring-status" name="status"><option value="">Semua</option>
            <?php foreach (($wali ? ['Sah', 'Dibatalkan'] : ['Menunggu', 'Sah', 'Dibatalkan']) as $st): ?><option value="<?= ah_e($st) ?>"<?= $d['saring']['status'] === $st ? ' selected' : '' ?>><?= ah_e($st === 'Menunggu' ? 'Menunggu verifikasi' : $st) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label" for="saring-sumber">Cara</label>
        <select class="form-select" id="saring-sumber" name="sumber"><option value="">Semua</option>
            <?php foreach (['Tunai', 'Transfer'] as $sb): ?><option value="<?= ah_e($sb) ?>"<?= $d['saring']['sumber'] === $sb ? ' selected' : '' ?>><?= ah_e($sb) ?></option><?php endforeach; ?></select></div>
<?php ah_filter_close(['q' => $q]);
if ($d['rows'] === []) {
    echo $q !== '' || $aktif > 0 ? ah_empty('Tidak ada yang cocok', 'Coba saringan lain atau bersihkan saringan.') : ah_empty('Belum ada pembayaran', 'Pembayaran tagihan yang tercatat akan tampil di sini.');
} else { ?>
    <section class="ah-card" aria-labelledby="ah-daftar-bayar">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-daftar-bayar">Pembayaran</h2><span class="text-muted small"><?= (int) $d['total'] ?> pembayaran</span></div>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Pembayaran tagihan, terbaru di atas</caption>
            <thead><tr><th scope="col">Kuitansi</th><th scope="col">Santri</th><th scope="col">Cara</th><th scope="col" class="text-end">Nominal</th><th scope="col">Status</th></tr></thead><tbody>
            <?php foreach ($d['rows'] as $b): ?><tr>
                <td data-label="Kuitansi"><span class="ah-cell-wrap"><?= $b['kuitansi'] !== null ? '<a href="' . ah_e(bsa_url('bsa_kuitansi.php', ['id' => $b['id']])) . '">' . ah_e($b['nomor_kuitansi']) . '</a>' : ah_e($b['nomor_kuitansi']) ?>
                    <span class="ah-cell-sub"><?= ah_e(ah_tanggal($b['tanggal_transaksi'], false)) ?></span></span></td>
                <td data-label="Santri"><span class="ah-cell-wrap"><?= ah_e($b['santri']['nama']) ?><span class="ah-cell-sub"><a href="<?= ah_e(bsa_url('bsa_tagihan.php', ['id' => $b['tagihan_id']])) ?>">Tagihan <?= ah_e(bsa_periode($b['periode'])) ?></a></span></span></td>
                <td data-label="Cara"><?= ah_e($b['sumber']) ?></td>
                <td data-label="Nominal" class="text-end"><?= ah_e(bsa_rp($b['nominal'])) ?></td>
                <td data-label="Status"><?= bsa_status_bayar($b['status']) ?></td>
            </tr><?php endforeach; ?>
            </tbody></table></div>
    </section>
<?php }
ah_pagination((int) $d['total'], (int) $d['page'], (int) $d['per_page']);
ah_page_close();
