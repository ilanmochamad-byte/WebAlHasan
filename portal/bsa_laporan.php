<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Database\PageQuery;
use App\Ui\Denial;

/**
 * Laporan keuangan santri/BSA (PRD V6 Fase 5). Petugas BSA melihat santri
 * aktif dalam cakupannya, admin seluruh santri (hanya baca, termasuk santri
 * nonaktif yang masih bersaldo/bertunggakan), orang tua hanya anaknya.
 *
 * Tagihan dihitung menurut periode tagihan; tabungan dan kas menurut waktu
 * transaksi sah. Pembayaran tagihan dari tabungan adalah perpindahan
 * internal: tampil terpisah dan tidak menambah penerimaan kas baru. Seluruh
 * angka dibaca dalam satu snapshot oleh `LaporanService`; ekspor CSV memakai
 * hak yang sama. Formulir GET, berjalan tanpa JavaScript.
 */

require_once __DIR__ . '/_bsa.php';

$laporanBsa = laporan_bsa_service();
if (!$laporanBsa->tersedia()) {
    Denial::render('Laporan keuangan belum tersedia', 'Laporan memerlukan modul tagihan dan tabungan lengkap (PRD V6 Fase 2–3) yang belum dipasang pada basis data ini.', 503, null, false);
}
$wali = $aksesBsa['sumber'] === 'wali';
$masukan = ['dari' => $_GET['dari'] ?? null, 'sampai' => $_GET['sampai'] ?? null, 'santri' => $_GET['santri'] ?? null, 'unit' => $_GET['unit'] ?? null,
    'q' => PageQuery::term($_GET['q'] ?? ''), 'page' => $_GET['page'] ?? 1];
try {
    $l = $laporanBsa->laporan($currentUser, $masukan);
} catch (BsaException $exception) {
    if ($exception->status === 422) {
        ah_flash_set('danger', $exception->getMessage());
        ah_redirect(bsa_url('bsa_laporan.php'));
    }
    Denial::page('Laporan keuangan', 'petugas BSA berpenugasan aktif, admin, dan orang tua atas anaknya', $exception->getMessage(), $exception->status);
}
$rentang = $l['rentang'];
$saring = array_filter(['dari' => $rentang['dari'], 'sampai' => $rentang['sampai'], 'santri' => $l['saring']['santri_id'], 'unit' => $l['saring']['unit']],
    static fn (mixed $v): bool => $v !== null && $v !== '');
$rp = static fn (int $n): string => ($n < 0 ? '−' : '') . bsa_rp(abs($n));
$angka = static fn (int $n): string => number_format($n, 0, ',', '.');
$labelRentang = $rentang['dari'] === $rentang['sampai'] ? bsa_periode($rentang['dari']) : bsa_periode($rentang['dari']) . ' – ' . bsa_periode($rentang['sampai']);
$ekspor = '';
foreach (['santri' => 'CSV per santri', 'transaksi' => 'CSV rincian transaksi'] + ($wali ? [] : ['koreksi' => 'CSV koreksi']) as $jenis => $label) {
    $ekspor .= '<a class="btn btn-outline-primary" href="' . ah_e(bsa_url('bsa_laporan_ekspor.php', ['jenis' => $jenis] + $saring)) . '"><i class="fas fa-file-csv" aria-hidden="true"></i> ' . ah_e($label) . '</a> ';
}

ah_page_open([
    'title' => $wali ? 'Laporan keuangan anak' : 'Laporan keuangan',
    'user' => $currentUser,
    'active' => 'bsa',
    'description' => $labelRentang . ' · ' . ($wali ? 'anak Anda saja.' : ($aksesBsa['kelola'] ? bsa_cakupan_teks($aksesBsa) . '.' : 'seluruh santri (hanya baca).')),
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => bsa_judul($aksesBsa), 'url' => bsa_url('bsa.php')], ['label' => 'Laporan']],
    'actions' => $ekspor,
]);
echo bsa_nav($aksesBsa, 'bsa_laporan.php', $tagihanBsa);
ah_filter_open((int) isset($_GET['dari']) + (int) isset($_GET['sampai']) + (int) ($l['saring']['unit'] !== null), array_filter(['santri' => $l['saring']['santri_id']]), bsa_url('bsa_laporan.php')); ?>
    <div><label class="form-label" for="lap-dari">Dari periode</label>
        <input class="form-control" type="month" id="lap-dari" name="dari" max="<?= ah_e(date('Y-m')) ?>" value="<?= ah_e($rentang['dari']) ?>"></div>
    <div><label class="form-label" for="lap-sampai">Sampai periode</label>
        <input class="form-control" type="month" id="lap-sampai" name="sampai" max="<?= ah_e(date('Y-m')) ?>" value="<?= ah_e($rentang['sampai']) ?>"></div>
    <?php if (!$wali): ?>
    <div><label class="form-label" for="lap-unit">Unit</label>
        <select class="form-select" id="lap-unit" name="unit"><option value="">Semua unit<?= $aksesBsa['kelola'] ? ' dalam cakupan' : '' ?></option>
            <?php foreach ($tagihanBsa->unitAkses($aksesBsa) as $u): ?><option value="<?= ah_e($u) ?>"<?= $l['saring']['unit'] === $u ? ' selected' : '' ?>><?= ah_e($u) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
<?php ah_filter_close();
if ($l['santri'] !== null): ?>
    <div class="ah-ribbon" role="note"><i class="fas fa-user mt-1" aria-hidden="true"></i>
        <span>Laporan untuk <b><?= ah_e($l['santri']['nama']) ?></b> (NIS <?= ah_e($l['santri']['nis']) ?>).
            <a href="<?= ah_e(bsa_url('bsa_laporan.php', array_diff_key($saring, ['santri' => 1]))) ?>">Tampilkan <?= $wali ? 'semua anak' : 'semua santri' ?></a></span></div>
<?php endif;
if ($l['selisih'] !== null):
    $s = $l['selisih']; ?>
    <div class="<?= $s['nol'] ? 'ah-ribbon' : 'alert alert-danger d-flex gap-2' ?>" role="<?= $s['nol'] ? 'note' : 'alert' ?>"><i class="fas <?= $s['nol'] ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> mt-1" aria-hidden="true"></i>
        <span><b>Rekonsiliasi periode: <?= $s['nol'] ? 'selisih Rp0.' : 'ADA SELISIH.' ?></b>
            Terbayar − pembayaran sah <?= ah_e($rp($s['tagihan_terbayar'])) ?> · saldo awal + mutasi − saldo akhir <?= ah_e($rp($s['saldo'])) ?> ·
            debit tabungan − pembayaran dari tabungan <?= ah_e($rp($s['internal'])) ?> · pengembalian − pembatalan <?= ah_e($rp($s['pengembalian'])) ?> ·
            santri dengan saldo akun ≠ ledger: <?= (int) $s['santri_akun'] ?>.</span></div>
<?php endif;

$t = $l['tagihan'];
echo '<h2 class="h6 mt-3">Tagihan periode ' . ah_e($labelRentang) . '</h2>';
echo ah_stats([
    ['label' => 'Tagihan terbit', 'value' => bsa_rp($t['terbit']['nominal']), 'hint' => $angka($t['terbit']['jumlah']) . ' tagihan · ' . $angka($t['santri']) . ' santri'],
    ['label' => 'Terbayar', 'value' => bsa_rp($t['terbayar']), 'hint' => $angka($t['lunas']) . ' lunas'],
    ['label' => 'Sisa (tunggakan periode)', 'value' => bsa_rp($t['sisa']), 'warn' => $t['sisa'] > 0, 'hint' => $angka($t['belum_lunas']) . ' belum lunas',
        'url' => bsa_url('bsa_tunggakan.php', $rentang['dari'] === $rentang['sampai'] ? ['periode' => $rentang['dari']] : [])],
    ['label' => 'Lewat jatuh tempo', 'value' => bsa_rp($t['lewat_jatuh_tempo']['sisa']), 'warn' => $t['lewat_jatuh_tempo']['jumlah'] > 0, 'hint' => $angka($t['lewat_jatuh_tempo']['jumlah']) . ' tagihan'],
], 'Ringkasan tagihan'); ?>
<section class="ah-card" aria-labelledby="ah-bayar-sumber">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-bayar-sumber">Pembayaran atas tagihan periode ini</h2><span class="text-muted small">Menurut sumber</span></div>
    <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Pembayaran sah, dibatalkan, dan menunggu menurut sumber</caption>
        <thead><tr><th scope="col">Sumber</th><th scope="col" class="text-end">Sah</th><th scope="col" class="text-end">Dibatalkan</th></tr></thead><tbody>
        <?php foreach (['Tunai' => 'Tunai', 'Transfer' => 'Transfer', 'Tabungan' => 'Dari tabungan (internal)'] as $k => $label): ?>
            <tr><td data-label="Sumber"><?= ah_e($label) ?></td><td data-label="Sah" class="text-end"><?= ah_e(bsa_rp($t['pembayaran_sah'][$k])) ?></td>
                <td data-label="Dibatalkan" class="text-end"><?= ah_e(bsa_rp($t['pembayaran_dibatalkan'][$k])) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php if ($t['menunggu_verifikasi'] !== null && $t['menunggu_verifikasi']['jumlah'] > 0): ?>
        <p class="text-muted small mb-0 p-3"><?= (int) $t['menunggu_verifikasi']['jumlah'] ?> transfer (<?= ah_e(bsa_rp($t['menunggu_verifikasi']['nominal'])) ?>) menunggu verifikasi dan belum dihitung terbayar.</p>
    <?php endif; ?>
</section>
<?php
$tb = $l['tabungan'];
echo '<h2 class="h6 mt-3">Tabungan ' . ah_e(ah_rentang($rentang['tanggal_awal'], $rentang['tanggal_akhir'], false)) . '</h2>';
echo ah_stats([
    ['label' => 'Saldo awal', 'value' => bsa_rp($tb['saldo_awal'])],
    ['label' => 'Setoran', 'value' => bsa_rp(array_sum($tb['setoran'])), 'hint' => 'Tunai ' . bsa_rp($tb['setoran']['Tunai']) . ' · transfer ' . bsa_rp($tb['setoran']['Transfer'])],
    ['label' => 'Penarikan dan belanja', 'value' => bsa_rp(array_sum($tb['penarikan']) + array_sum($tb['belanja']))],
    ['label' => 'Bayar tagihan dari tabungan', 'value' => bsa_rp($tb['pembayaran_tagihan']), 'hint' => 'Perpindahan internal'],
    ['label' => 'Saldo akhir', 'value' => bsa_rp($tb['saldo_akhir']), 'hint' => 'Mutasi ' . $rp($tb['mutasi_bersih'])],
], 'Ringkasan tabungan');
if ($tb['setoran_menunggu'] !== null && $tb['setoran_menunggu']['jumlah'] > 0): ?>
    <p class="text-muted small"><?= (int) $tb['setoran_menunggu']['jumlah'] ?> setoran transfer (<?= ah_e(bsa_rp($tb['setoran_menunggu']['nominal'])) ?>) masih menunggu verifikasi dan belum dihitung saldo.</p>
<?php endif;

if ($l['kas'] !== null): ?>
<section class="ah-card" aria-labelledby="ah-kas">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-kas">Kas nyata per cara</h2>
        <span class="text-muted small">Penerimaan kas baru <?= ah_e(bsa_rp((int) $l['penerimaan_kas_baru'])) ?> · pembayaran dari tabungan tidak dihitung</span></div>
    <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Uang tunai dan transfer masuk serta keluar dalam periode</caption>
        <thead><tr><th scope="col">Uraian</th><th scope="col" class="text-end">Tunai</th><th scope="col" class="text-end">Transfer</th></tr></thead><tbody>
        <?php foreach (['setoran' => 'Setoran tabungan', 'pembayaran_tagihan' => 'Pembayaran tagihan', 'koreksi_masuk' => 'Koreksi masuk', 'masuk' => 'Total masuk',
            'penarikan_belanja' => 'Penarikan dan belanja', 'koreksi_keluar' => 'Koreksi keluar dan pembatalan pembayaran', 'keluar' => 'Total keluar', 'bersih' => 'Bersih'] as $k => $label): ?>
            <tr<?= in_array($k, ['masuk', 'keluar', 'bersih'], true) ? ' class="fw-semibold"' : '' ?>><td data-label="Uraian"><?= ah_e($label) ?></td>
                <td data-label="Tunai" class="text-end"><?= ah_e($rp($l['kas']['Tunai'][$k])) ?></td><td data-label="Transfer" class="text-end"><?= ah_e($rp($l['kas']['Transfer'][$k])) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <div class="ah-card__body"><dl class="ah-kv">
        <div><dt>Debit tabungan untuk tagihan</dt><dd><?= ah_e(bsa_rp($l['internal']['debit_tabungan'])) ?></dd></div>
        <div><dt>Pembayaran tagihan dari tabungan</dt><dd><?= ah_e(bsa_rp($l['internal']['pembayaran_dari_tabungan'])) ?></dd></div>
        <div><dt>Saldo dikembalikan (pembatalan)</dt><dd><?= ah_e(bsa_rp($l['internal']['pengembalian_ke_tabungan'])) ?></dd></div>
        <div><dt>Pembayaran dari tabungan dibatalkan</dt><dd><?= ah_e(bsa_rp($l['internal']['pembatalan_pembayaran_tabungan'])) ?></dd></div>
    </dl></div>
</section>
<?php endif;

$pb = $l['pembatalan'];
echo '<h2 class="h6 mt-3">Pembatalan dan koreksi</h2>';
echo ah_stats(array_values(array_filter([
    ['label' => 'Pembayaran dibatalkan', 'value' => bsa_rp(array_sum($pb['pembayaran'])), 'hint' => 'Tunai/transfer ' . bsa_rp($pb['pembayaran']['Tunai'] + $pb['pembayaran']['Transfer']) . ' · tabungan ' . bsa_rp($pb['pembayaran']['Tabungan'])],
    ['label' => 'Koreksi tabungan', 'value' => $angka($pb['koreksi_tabungan']['jumlah']), 'hint' => 'Masuk ' . bsa_rp($pb['koreksi_tabungan']['masuk']) . ' · keluar ' . bsa_rp($pb['koreksi_tabungan']['keluar'])],
    ['label' => 'Tagihan dibatalkan', 'value' => $angka($pb['tagihan_dibatalkan']['jumlah']), 'hint' => $angka($pb['tagihan_direvisi']) . ' revisi tagihan'],
    $pb['penolakan_transfer_pembayaran'] === null ? null : ['label' => 'Transfer ditolak', 'value' => $angka($pb['penolakan_transfer_pembayaran']['jumlah'] + $pb['setoran_transfer_ditolak']['jumlah']),
        'hint' => 'Pembayaran ' . bsa_rp($pb['penolakan_transfer_pembayaran']['nominal']) . ' · setoran ' . bsa_rp($pb['setoran_transfer_ditolak']['nominal'])],
])), 'Ringkasan pembatalan');
if ($l['koreksi'] !== null): ?>
<section class="ah-card" aria-labelledby="ah-koreksi">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-koreksi">Jejak koreksi dan pembatalan</h2>
        <span class="text-muted small"><?= count($l['koreksi']['rows']) ?> terbaru<?= $l['koreksi']['terpotong'] ? ' (selengkapnya lewat CSV koreksi)' : '' ?></span></div>
    <?php if ($l['koreksi']['rows'] === []): ?>
        <div class="ah-card__body"><p class="text-muted mb-0">Tidak ada koreksi atau pembatalan pada periode ini.</p></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Koreksi dan pembatalan dengan aktor, waktu, alasan, dan asal</caption>
            <thead><tr><th scope="col">Waktu dan aktor</th><th scope="col">Santri</th><th scope="col">Tindakan dan alasan</th><th scope="col" class="text-end">Nominal</th></tr></thead><tbody>
            <?php foreach ($l['koreksi']['rows'] as $k): ?><tr>
                <td data-label="Waktu"><span class="ah-cell-wrap"><?= ah_e(ah_waktu($k['waktu'])) ?><span class="ah-cell-sub"><?= ah_e((string) ($k['aktor'] ?? '—')) ?></span></span></td>
                <td data-label="Santri"><?= ah_e($k['santri']['nama']) ?></td>
                <td data-label="Tindakan"><span class="ah-cell-wrap"><?= ah_e($k['jenis']) ?> · asal <?= ah_e($k['rujukan']['label']) ?><span class="ah-cell-sub"><?= ah_e((string) $k['alasan']) ?></span></span></td>
                <td data-label="Nominal" class="text-end"><?= ah_e(bsa_rp($k['nominal'])) ?></td>
            </tr><?php endforeach; ?>
            </tbody></table></div>
    <?php endif; ?>
</section>
<?php endif; ?>
<section class="ah-card" aria-labelledby="ah-per-bulan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-per-bulan">Per bulan</h2><span class="text-muted small">Tagihan menurut periode; uang menurut waktu transaksi</span></div>
    <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Ringkasan per bulan</caption>
        <thead><tr><th scope="col">Bulan</th><th scope="col" class="text-end">Tagihan terbit</th><th scope="col" class="text-end">Sisa</th><th scope="col" class="text-end">Setoran</th>
            <th scope="col" class="text-end">Penarikan/belanja</th><th scope="col" class="text-end">Bayar tunai/transfer</th><th scope="col" class="text-end">Bayar dari tabungan</th>
            <?php if (!$wali): ?><th scope="col" class="text-end">Kas baru</th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($l['per_bulan'] as $b): ?><tr>
            <td data-label="Bulan"><?= ah_e(bsa_periode($b['periode'])) ?></td>
            <td data-label="Tagihan terbit" class="text-end"><?= ah_e(bsa_rp($b['tagihan_terbit'])) ?></td>
            <td data-label="Sisa" class="text-end"><?= ah_e(bsa_rp($b['sisa'])) ?></td>
            <td data-label="Setoran" class="text-end"><?= ah_e(bsa_rp($b['setoran'])) ?></td>
            <td data-label="Penarikan/belanja" class="text-end"><?= ah_e(bsa_rp($b['penarikan_belanja'])) ?></td>
            <td data-label="Bayar tunai/transfer" class="text-end"><?= ah_e(bsa_rp($b['bayar_kas'])) ?></td>
            <td data-label="Bayar dari tabungan" class="text-end"><?= ah_e(bsa_rp($b['bayar_tabungan'])) ?></td>
            <?php if (!$wali): ?><td data-label="Kas baru" class="text-end"><?= ah_e(bsa_rp($b['kas_baru'])) ?></td><?php endif; ?>
        </tr><?php endforeach; ?>
        </tbody></table></div>
</section>
<?php
$ps = $l['per_santri'];
echo '<h2 class="h6 mt-3">' . ($wali ? 'Per anak' : 'Per santri') . '</h2>';
if (!$wali && $l['santri'] === null) {
    ah_list_search($l['saring']['q'], 'Cari nama santri atau NIS', $saring);
}
if ($ps['rows'] === []) {
    echo ah_empty('Belum ada data', $l['saring']['q'] !== '' ? 'Tidak ada santri yang cocok.' : 'Belum ada tagihan atau transaksi tabungan untuk ' . ($wali ? 'anak Anda.' : 'santri dalam hak Anda.'));
} else { ?>
<section class="ah-card" aria-labelledby="ah-per-santri">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-per-santri"><?= $wali ? 'Anak' : 'Santri' ?></h2><span class="text-muted small"><?= (int) $ps['total'] ?> santri</span></div>
    <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Tagihan dan tabungan per santri dalam periode</caption>
        <thead><tr><th scope="col">Santri</th><th scope="col" class="text-end">Tagihan / terbayar</th><th scope="col" class="text-end">Sisa</th>
            <th scope="col" class="text-end">Saldo awal → akhir</th><th scope="col" class="text-end">Setoran / keluar</th></tr></thead><tbody>
        <?php foreach ($ps['rows'] as $r): $s = $r['santri']; ?><tr>
            <td data-label="Santri"><span class="ah-cell-wrap"><a class="ah-row-link" href="<?= ah_e(bsa_url('bsa_laporan.php', ['santri' => $s['id']] + array_diff_key($saring, ['santri' => 1]))) ?>"><?= ah_e($s['nama']) ?></a>
                <span class="ah-cell-sub">NIS <?= ah_e($s['nis']) ?><?= $s['kelas'] ? ' · ' . ah_e($s['kelas']) : '' ?><?= $s['aktif'] ? '' : ' · nonaktif' ?></span></span></td>
            <td data-label="Tagihan / terbayar" class="text-end"><span class="ah-cell-wrap"><?= ah_e(bsa_rp($r['tagihan'])) ?><span class="ah-cell-sub">terbayar <?= ah_e(bsa_rp($r['terbayar'])) ?></span></span></td>
            <td data-label="Sisa" class="text-end"><span class="ah-cell-wrap"><?= ah_e(bsa_rp($r['sisa'])) ?><span class="ah-cell-sub">seluruh tunggakan <?= ah_e(bsa_rp($r['sisa_semua'])) ?></span></span></td>
            <td data-label="Saldo" class="text-end"><span class="ah-cell-wrap"><?= ah_e(bsa_rp($r['saldo_awal'])) ?> → <?= ah_e(bsa_rp($r['saldo_akhir'])) ?>
                <?php if (!$wali && ($r['selisih_saldo'] !== 0 || $r['selisih_akun'] !== 0)): ?><span class="ah-cell-sub"><?= ah_badge('selisih', 'danger') ?></span><?php endif; ?></span></td>
            <td data-label="Setoran / keluar" class="text-end"><span class="ah-cell-wrap"><?= ah_e(bsa_rp($r['setoran'])) ?>
                <span class="ah-cell-sub">keluar <?= ah_e(bsa_rp($r['penarikan'] + $r['belanja'] + $r['bayar_tabungan'])) ?></span></span></td>
        </tr><?php endforeach; ?>
        </tbody>
        <?php if ($ps['total'] > 1): $j = $ps['jumlah']; ?>
        <tfoot><tr class="fw-semibold"><td data-label="Santri">Jumlah <?= (int) $j['santri'] ?> santri</td>
            <td data-label="Tagihan / terbayar" class="text-end"><?= ah_e(bsa_rp($j['tagihan'])) ?> / <?= ah_e(bsa_rp($j['terbayar'])) ?></td>
            <td data-label="Sisa" class="text-end"><?= ah_e(bsa_rp($j['sisa'])) ?></td>
            <td data-label="Saldo" class="text-end"><?= ah_e(bsa_rp($j['saldo_awal'])) ?> → <?= ah_e(bsa_rp($j['saldo_akhir'])) ?></td>
            <td data-label="Setoran / keluar" class="text-end"><?= ah_e(bsa_rp($j['setoran'])) ?> / <?= ah_e(bsa_rp($j['penarikan'] + $j['belanja'] + $j['bayar_tabungan'])) ?></td></tr></tfoot>
        <?php endif; ?>
    </table></div>
</section>
<?php }
ah_pagination($ps['total'], $ps['page'], $ps['per_page']);

if ($l['nonaktif'] !== null): ?>
<section class="ah-card mt-3" aria-labelledby="ah-nonaktif">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-nonaktif">Santri nonaktif yang masih bersaldo atau bertunggakan</h2>
        <span class="text-muted small"><?= (int) $l['nonaktif']['jumlah'] ?> santri · saldo <?= ah_e(bsa_rp($l['nonaktif']['saldo'])) ?> · sisa tagihan <?= ah_e(bsa_rp($l['nonaktif']['sisa'])) ?></span></div>
    <div class="ah-card__body"><p class="text-muted small mb-0">Belum ada jalur pengembalian saldo santri keluar; keputusan Human Developer diperlukan sebelum rilis (R-10). Data hanya dibaca.</p>
        <?php if ($l['nonaktif']['rows'] === []): ?><p class="text-muted mb-0 mt-2">Tidak ada.</p><?php endif; ?></div>
    <?php if ($l['nonaktif']['rows'] !== []): ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Santri nonaktif bersaldo atau bertunggakan</caption>
            <thead><tr><th scope="col">Santri</th><th scope="col" class="text-end">Saldo tabungan</th><th scope="col" class="text-end">Sisa tagihan</th></tr></thead><tbody>
            <?php foreach ($l['nonaktif']['rows'] as $n): ?><tr>
                <td data-label="Santri"><a class="ah-row-link" href="<?= ah_e(bsa_url('bsa_santri.php', ['id' => $n['santri']['id']])) ?>"><?= ah_e($n['santri']['nama']) ?></a> <span class="text-muted small">NIS <?= ah_e($n['santri']['nis']) ?></span></td>
                <td data-label="Saldo tabungan" class="text-end"><?= ah_e(bsa_rp($n['saldo'])) ?></td><td data-label="Sisa tagihan" class="text-end"><?= ah_e(bsa_rp($n['sisa'])) ?></td>
            </tr><?php endforeach; ?>
            </tbody></table></div>
    <?php endif; ?>
</section>
<?php endif;
ah_page_close();
