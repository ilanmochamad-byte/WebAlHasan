<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Ui\Denial;

/**
 * Rekonsiliasi harian BSA (PRD V6 Fase 3, persyaratan 4). Petugas BSA
 * melihat santri aktif dalam cakupannya kini, admin seluruh santri (hanya
 * baca); orang tua 403.
 *
 * Kas = uang nyata tunai/transfer yang sudah diverifikasi: setoran tabungan
 * dan pembayaran tagihan tunai/transfer (masuk), penarikan/belanja (keluar),
 * serta koreksi. Pembayaran tagihan dari tabungan dan pengembaliannya adalah
 * PERPINDAHAN INTERNAL: tampil terpisah dan tidak menambah penerimaan kas,
 * sebab uangnya sudah dihitung saat setoran. Transfer menunggu verifikasi
 * belum dihitung. Angka dibaca dalam satu snapshot basis data.
 */

require_once __DIR__ . '/_bsa.php';

bsa_wajib_fase3($tabunganBsa);
try {
    $r = $tabunganBsa->rekonsiliasi($currentUser, ['tanggal' => $_GET['tanggal'] ?? null]);
} catch (BsaException $exception) {
    if ($exception->status === 422) {
        ah_flash_set('danger', $exception->getMessage());
        ah_redirect(bsa_url('bsa_rekonsiliasi.php'));
    }
    Denial::page('Rekonsiliasi', 'petugas BSA berpenugasan aktif dan admin', $exception->getMessage(), $exception->status);
}
$selisihNol = $r['internal']['selisih'] === 0 && $r['internal']['selisih_pengembalian'] === 0 && $r['saldo']['selisih'] === 0 && ($r['saldo']['selisih_akun'] ?? 0) === 0;

ah_page_open([
    'title' => 'Rekonsiliasi harian',
    'user' => $currentUser,
    'active' => 'bsa',
    'description' => 'Kas tunai/transfer terverifikasi, debit tabungan, dan pembayaran tagihan tanggal ' . ah_tanggal($r['tanggal'], false)
        . ($aksesBsa['kelola'] ? ' pada ' . bsa_cakupan_teks($aksesBsa) : ' di seluruh santri (hanya baca)') . '.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => bsa_judul($aksesBsa), 'url' => bsa_url('bsa.php')], ['label' => 'Rekonsiliasi']],
]);
echo bsa_nav($aksesBsa, 'bsa_rekonsiliasi.php', $tagihanBsa);
?>
<form method="get" action="<?= ah_e(bsa_url('bsa_rekonsiliasi.php')) ?>" class="ah-card ah-card__body d-flex flex-wrap gap-2 align-items-end mb-3">
    <div><label class="form-label" for="tanggal">Tanggal</label>
        <input class="form-control" type="date" id="tanggal" name="tanggal" max="<?= ah_e(date('Y-m-d')) ?>" value="<?= ah_e($r['tanggal']) ?>"></div>
    <button class="btn btn-outline-primary" type="submit">Tampilkan</button>
</form>
<?php
echo ah_stats([
    ['label' => 'Penerimaan kas baru', 'value' => bsa_rp($r['penerimaan_kas_baru']), 'hint' => 'Setoran + pembayaran tagihan tunai/transfer terverifikasi'],
    ['label' => 'Pembayaran tagihan dari tabungan', 'value' => bsa_rp($r['internal']['pembayaran_dari_tabungan']), 'hint' => 'Perpindahan internal, bukan kas baru'],
    ['label' => 'Saldo tabungan akhir hari', 'value' => bsa_rp($r['saldo']['akhir']), 'hint' => 'Awal ' . bsa_rp($r['saldo']['awal']) . ' · mutasi ' . ($r['saldo']['mutasi_bersih'] < 0 ? '−' : '+') . bsa_rp(abs($r['saldo']['mutasi_bersih']))],
    ['label' => 'Selisih rekonsiliasi', 'value' => $selisihNol ? 'Rp0' : 'Ada selisih', 'hint' => $selisihNol ? 'Debit tabungan = pembayaran dari tabungan; saldo cocok' : 'Periksa baris di bawah', 'warn' => !$selisihNol],
], 'Ringkasan rekonsiliasi');
if ($r['menunggu']['setoran_transfer']['jumlah'] > 0 || $r['menunggu']['pembayaran_transfer']['jumlah'] > 0): ?>
    <div class="ah-ribbon" role="note"><i class="fas fa-hourglass-half mt-1" aria-hidden="true"></i>
        <span><b>Belum dihitung:</b> <?= (int) $r['menunggu']['setoran_transfer']['jumlah'] ?> setoran transfer (<?= ah_e(bsa_rp($r['menunggu']['setoran_transfer']['nominal'])) ?>) dan
            <?= (int) $r['menunggu']['pembayaran_transfer']['jumlah'] ?> pembayaran transfer (<?= ah_e(bsa_rp($r['menunggu']['pembayaran_transfer']['nominal'])) ?>) masih menunggu verifikasi.
            Angka di halaman ini hanya memuat dana yang sudah diverifikasi.</span></div>
<?php endif; ?>
<section class="ah-card" aria-labelledby="ah-kas">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-kas">Kas nyata per cara</h2><span class="text-muted small">Cocokkan dengan kas tunai dan mutasi rekening</span></div>
    <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Uang tunai dan transfer masuk serta keluar</caption>
        <thead><tr><th scope="col">Uraian</th><th scope="col" class="text-end">Tunai</th><th scope="col" class="text-end">Transfer</th></tr></thead><tbody>
        <?php foreach (['setoran' => 'Setoran tabungan', 'pembayaran_tagihan' => 'Pembayaran tagihan', 'koreksi_masuk' => 'Koreksi masuk (pembalik penarikan/belanja)', 'masuk' => 'Total masuk',
            'penarikan_belanja' => 'Penarikan dan belanja', 'koreksi_keluar' => 'Koreksi keluar (pembalik setoran, pembatalan pembayaran)', 'keluar' => 'Total keluar', 'bersih' => 'Bersih'] as $k => $l): ?>
            <tr<?= in_array($k, ['masuk', 'keluar', 'bersih'], true) ? ' class="fw-semibold"' : '' ?>><td data-label="Uraian"><?= ah_e($l) ?></td>
                <td data-label="Tunai" class="text-end"><?= ah_e(($r['kas']['Tunai'][$k] < 0 ? '−' : '') . bsa_rp(abs($r['kas']['Tunai'][$k]))) ?></td>
                <td data-label="Transfer" class="text-end"><?= ah_e(($r['kas']['Transfer'][$k] < 0 ? '−' : '') . bsa_rp(abs($r['kas']['Transfer'][$k]))) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
</section>
<section class="ah-card" aria-labelledby="ah-internal">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-internal">Perpindahan internal tabungan → tagihan</h2><span class="text-muted small">Tidak dihitung sebagai kas</span></div>
    <div class="ah-card__body"><dl class="ah-kv">
        <div><dt>Debit tabungan untuk tagihan</dt><dd><?= ah_e(bsa_rp($r['internal']['debit_tabungan'])) ?></dd></div>
        <div><dt>Pembayaran tagihan dari tabungan</dt><dd><?= ah_e(bsa_rp($r['internal']['pembayaran_dari_tabungan'])) ?></dd></div>
        <div><dt>Selisih</dt><dd><?= ah_e(bsa_rp($r['internal']['selisih'])) ?></dd></div>
        <div><dt>Saldo dikembalikan (pembatalan)</dt><dd><?= ah_e(bsa_rp($r['internal']['pengembalian_ke_tabungan'])) ?></dd></div>
        <div><dt>Pembayaran dari tabungan dibatalkan</dt><dd><?= ah_e(bsa_rp($r['internal']['pembatalan_pembayaran_tabungan'])) ?></dd></div>
        <div><dt>Selisih pengembalian</dt><dd><?= ah_e(bsa_rp($r['internal']['selisih_pengembalian'])) ?></dd></div>
        <?php if ($r['saldo']['akun'] !== null): ?><div><dt>Saldo akun (cerminan) − ledger</dt><dd><?= ah_e(bsa_rp((int) $r['saldo']['selisih_akun'])) ?></dd></div><?php endif; ?>
    </dl></div>
</section>
<section class="ah-card" aria-labelledby="ah-rincian">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-rincian">Rincian transaksi</h2><span class="text-muted small"><?= count($r['rows']) ?> baris<?= count($r['rows']) >= 500 ? ' (500 pertama)' : '' ?></span></div>
    <?php if ($r['rows'] === []): ?>
        <div class="ah-card__body"><p class="text-muted mb-0">Tidak ada transaksi sah pada tanggal ini.</p></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Transaksi tabungan dan pembayaran tagihan pada tanggal ini, urut waktu</caption>
            <thead><tr><th scope="col">Waktu</th><th scope="col">Santri</th><th scope="col">Transaksi</th><th scope="col" class="text-end">Nominal</th></tr></thead><tbody>
            <?php foreach ($r['rows'] as $x):
                $url = $x['asal'] === 'tabungan' ? bsa_url('bsa_tabungan_kuitansi.php', ['id' => $x['id']]) : bsa_url('bsa_kuitansi.php', ['id' => $x['id']]); ?><tr>
                <td data-label="Waktu"><span class="ah-cell-wrap"><?= ah_e(ah_waktu($x['waktu'])) ?><span class="ah-cell-sub"><a href="<?= ah_e($url) ?>"><?= ah_e($x['nomor_kuitansi']) ?></a></span></span></td>
                <td data-label="Santri"><?= ah_e($x['santri']['nama']) ?></td>
                <td data-label="Transaksi"><span class="ah-cell-wrap"><?= ah_e(($x['asal'] === 'tabungan' ? 'Tabungan: ' . bsa_jenis_tabungan($x['jenis']) : ($x['asal'] === 'pembayaran' ? 'Pembayaran tagihan' : 'Pembatalan pembayaran tagihan'))
                    . ' · ' . ($x['cara'] === 'Tabungan' ? 'dari tabungan' : ($x['cara'] === 'Internal' ? 'internal' : $x['cara']))) ?>
                    <?php if ($x['internal']): ?><span class="ah-cell-sub">Perpindahan internal, bukan kas</span><?php endif; ?></span></td>
                <td data-label="Nominal" class="text-end"><?= ah_e(($x['arah'] === 'Keluar' ? '−' : '+') . bsa_rp($x['nominal'])) ?></td>
            </tr><?php endforeach; ?>
            </tbody></table></div>
    <?php endif; ?>
</section>
<?php
ah_page_close();
