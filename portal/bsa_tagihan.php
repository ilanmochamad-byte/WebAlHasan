<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Ui\Denial;

/**
 * Rincian satu tagihan bulanan (PRD V6 Fase 1–2): snapshot komponen, baris
 * penyesuaian, total, terbayar, sisa, pembayaran beserta kuitansi, dan
 * riwayat versi. Tagihan santri di luar hak pembaca dijawab 404.
 *
 * Petugas BSA dalam cakupan: catat pembayaran, verifikasi/tolak transfer,
 * batalkan pembayaran, revisi, dan batalkan tagihan — semuanya POST ke
 * `bsa_tagihan_aksi.php` atau `bsa_bayar.php` yang diperiksa server. Fase 3:
 * "Bayar dari tabungan" (`bsa_bayar_tabungan.php`, tindakan eksplisit);
 * pembayaran bersumber tabungan merujuk debit tabungannya. Admin
 * hanya membaca; orang tua melihat tagihan dan pembayaran sah anaknya tanpa
 * referensi, catatan internal, atau alasan.
 */

require_once __DIR__ . '/_bsa.php';

$id = max(0, (int) ($_GET['id'] ?? 0));
try {
    $t = $tagihanBsa->rincianTagihan($currentUser, $id);
} catch (BsaException $exception) {
    if ($exception->status === 404) {
        Denial::render('Tagihan tidak ditemukan', 'Tagihan yang Anda buka tidak ada atau bukan hak Anda.', 404,
            '<a class="btn btn-outline-primary" href="' . ah_e(bsa_url('bsa.php')) . '">Kembali ke daftar</a>', false);
    }
    Denial::page('Rincian tagihan', 'petugas BSA berpenugasan aktif, admin, dan orang tua atas anaknya', $exception->getMessage(), $exception->status);
}
$s = $t['santri'];
$kembali = bsa_url('bsa_santri.php', ['id' => $s['id']]);
$fase2 = $tagihanBsa->tersedia();
$kelola = $t['akses']['kelola'] && $fase2;
$wali = $t['akses']['sumber'] === 'wali';
$terbit = $t['status'] === 'Terbit';
$sisaBayar = $t['sisa'] - (int) ($t['menunggu'] ?? 0);
$fase3 = $tabunganBsa->tersedia();
$aksi = $kelola && $terbit && $sisaBayar > 0
    ? '<a class="btn btn-primary" href="' . ah_e(bsa_url('bsa_bayar.php', ['tagihan_id' => $t['id']])) . '"><i class="fas fa-money-bill-wave" aria-hidden="true"></i> Catat pembayaran</a>'
        . ($fase3 ? ' <a class="btn btn-outline-primary" href="' . ah_e(bsa_url('bsa_bayar_tabungan.php', ['tagihan_id' => $t['id']])) . '"><i class="fas fa-piggy-bank" aria-hidden="true"></i> Bayar dari tabungan</a>' : '')
    : '';
$kunci = static fn (): string => bin2hex(random_bytes(16));
$aksiUrl = bsa_url('bsa_tagihan_aksi.php');

ah_page_open([
    'title' => 'Tagihan ' . bsa_periode($t['periode']) . ' · ' . $s['nama'],
    'heading' => 'Tagihan ' . bsa_periode($t['periode']),
    'user' => $currentUser,
    'active' => 'bsa',
    'description' => $s['nama'] . ' · NIS ' . $s['nis'] . ($t['unit_saat_terbit'] ? ' · unit saat terbit ' . $t['unit_saat_terbit'] : ''),
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => bsa_judul($aksesBsa), 'url' => bsa_url('bsa.php')],
        ['label' => $s['nama'], 'url' => $kembali], ['label' => 'Tagihan ' . bsa_periode($t['periode'])]],
    'actions' => $aksi,
]);
if (!$t['akses']['kelola'] && !$wali): ?>
    <div class="ah-ribbon" role="note"><i class="fas fa-eye mt-1" aria-hidden="true"></i>
        <span><b>Hanya baca.</b> Admin tidak mencatat atau mengubah tagihan dan pembayaran.</span></div>
<?php endif; ?>
<section class="ah-card" aria-labelledby="ah-tagihan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-tagihan"><?= ah_e(bsa_periode($t['periode'])) ?></h2><?= ah_status($t['status_bayar']) ?></div>
    <div class="ah-card__body"><dl class="ah-kv">
        <div><dt>Total</dt><dd><?= ah_e(bsa_rp($t['total'])) ?></dd></div>
        <div><dt>Terbayar</dt><dd><?= ah_e(bsa_rp($t['terbayar'])) ?></dd></div>
        <div><dt>Sisa</dt><dd><?= ah_e(bsa_rp($t['sisa'])) ?></dd></div>
        <div><dt>Jatuh tempo</dt><dd><?= ah_e(ah_tanggal($t['jatuh_tempo'], false)) ?></dd></div>
        <div><dt>Diterbitkan</dt><dd><?= ah_e(ah_waktu($t['diterbitkan_pada'])) ?></dd></div>
        <div><dt>Penerbit</dt><dd><?= ah_e($t['penerbit']['nama']) ?></dd></div>
    </dl>
    <?php if (!$wali && ($t['menunggu'] ?? 0) > 0): ?><p class="text-muted small mb-0 mt-2"><?= ah_e(bsa_rp((int) $t['menunggu'])) ?> transfer menunggu verifikasi dan belum mengurangi sisa.</p><?php endif; ?>
    </div>
</section>
<section class="ah-card" aria-labelledby="ah-komponen">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-komponen">Rincian biaya</h2><span class="text-muted small">Snapshot saat terbit<?= $t['versi'] > 1 ? ' · versi ' . (int) $t['versi'] : '' ?></span></div>
    <div class="ah-table-wrap"><table class="ah-table"><caption class="ah-visually-hidden">Komponen biaya dan penyesuaian tagihan</caption>
        <thead><tr><th scope="col">Uraian</th><th scope="col" class="text-end">Nominal</th></tr></thead><tbody>
        <?php foreach ($t['komponen'] as $k): ?><tr><td><?= ah_e($k['nama']) ?></td><td class="text-end"><?= ah_e(bsa_rp($k['nominal'])) ?></td></tr><?php endforeach; ?>
        <?php foreach ($t['penyesuaian'] as $j): ?><tr><td><span class="ah-cell-wrap"><?= ah_e($j['nama']) ?>
            <span class="ah-cell-sub"><?= ah_e($j['jenis'] . ($j['asal'] === 'Revisi' ? ' · revisi versi ' . $j['versi'] : ' · penyesuaian santri')) ?><?= isset($j['alasan']) ? ' · ' . ah_e($j['alasan']) : '' ?></span></span></td>
            <td class="text-end"><?= ah_e(($j['jenis'] === 'Potongan' ? '−' : '+') . bsa_rp($j['nominal'])) ?></td></tr><?php endforeach; ?>
        <tr><th scope="row">Total</th><td class="text-end fw-semibold"><?= ah_e(bsa_rp($t['total'])) ?></td></tr>
        </tbody></table></div>
    <?php if ($t['catatan'] !== null): ?><div class="ah-card__body"><p class="mb-0 ah-break"><b>Catatan:</b> <?= ah_e($t['catatan']) ?></p></div><?php endif; ?>
</section>
<?php if ($fase2): ?>
<section class="ah-card" aria-labelledby="ah-pembayaran">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-pembayaran">Pembayaran</h2><span class="text-muted small"><?= count($t['pembayaran']) ?> pembayaran</span></div>
    <?php if ($t['pembayaran'] === []): ?>
        <div class="ah-card__body"><p class="text-muted mb-0">Belum ada pembayaran. Tagihan tidak memotong tabungan secara otomatis.</p></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Pembayaran tagihan, terlama di atas</caption>
            <thead><tr><th scope="col">Kuitansi</th><th scope="col">Cara</th><th scope="col" class="text-end">Nominal</th><th scope="col">Status</th><?= $kelola ? '<th scope="col"><span class="ah-visually-hidden">Tindakan</span></th>' : '' ?></tr></thead><tbody>
            <?php foreach ($t['pembayaran'] as $b): ?><tr>
                <td data-label="Kuitansi"><span class="ah-cell-wrap"><?= $b['kuitansi'] !== null ? '<a href="' . ah_e(bsa_url('bsa_kuitansi.php', ['id' => $b['id']])) . '">' . ah_e($b['nomor_kuitansi']) . '</a>' : ah_e($b['nomor_kuitansi']) ?>
                    <span class="ah-cell-sub"><?= ah_e(ah_tanggal($b['tanggal_transaksi'], false)) ?> · <?= ah_e($b['pencatat']['nama']) ?></span></span></td>
                <td data-label="Cara"><span class="ah-cell-wrap"><?= ah_e($b['sumber'] === 'Tabungan' ? 'Dari tabungan' : $b['sumber']) ?><?php if (($b['referensi'] ?? null) !== null): ?><span class="ah-cell-sub ah-break">Ref. <?= ah_e($b['referensi']) ?></span><?php endif; ?>
                    <?php if (($b['tabungan'] ?? null) !== null): ?><span class="ah-cell-sub ah-break">Debit <?= $fase3 ? '<a href="' . ah_e(bsa_url('bsa_tabungan_kuitansi.php', ['id' => $b['tabungan']['transaksi_id']])) . '">' . ah_e($b['tabungan']['nomor_kuitansi']) . '</a>' : ah_e($b['tabungan']['nomor_kuitansi']) ?><?= $b['tabungan']['pengembalian_id'] !== null ? ' · saldo dikembalikan' : '' ?></span><?php endif; ?></span></td>
                <td data-label="Nominal" class="text-end"><?= ah_e(bsa_rp($b['nominal'])) ?></td>
                <td data-label="Status"><span class="ah-cell-wrap"><?= bsa_status_bayar($b['status']) ?>
                    <?php if ($b['pembatalan'] !== null): ?><span class="ah-cell-sub ah-break"><?= ah_e($b['pembatalan']['jenis'] . ' ' . ah_waktu($b['pembatalan']['pada'])) ?><?= isset($b['pembatalan']['alasan']) ? ' · ' . ah_e($b['pembatalan']['alasan']) . ' (' . ah_e($b['pembatalan']['oleh']) . ')' : '' ?></span><?php endif; ?></span></td>
                <?php if ($kelola): ?><td data-label="Tindakan">
                    <?php if ($b['status'] === 'Menunggu' && $terbit): ?>
                        <form method="post" action="<?= ah_e($aksiUrl) ?>" class="d-inline">
                            <?= ah_csrf() ?><input type="hidden" name="aksi" value="verifikasi"><input type="hidden" name="pembayaran_id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="tagihan_id" value="<?= (int) $t['id'] ?>">
                            <input type="hidden" name="idempotency_key" value="<?= ah_e($kunci()) ?>">
                            <button class="btn btn-sm btn-primary" type="submit" data-busy-label="Memverifikasi…">Verifikasi dana masuk</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($b['status'] !== 'Dibatalkan' && $terbit):
                        ah_action_dialog_open('batal-bayar-' . (int) $b['id'], $b['status'] === 'Menunggu' ? 'Tolak' : 'Batalkan', ($b['status'] === 'Menunggu' ? 'Tolak transfer ' : 'Batalkan pembayaran ') . $b['nomor_kuitansi'], 'sm btn-outline-danger'); ?>
                        <form method="post" action="<?= ah_e($aksiUrl) ?>">
                            <?= ah_csrf() ?><input type="hidden" name="aksi" value="batal_bayar"><input type="hidden" name="pembayaran_id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="tagihan_id" value="<?= (int) $t['id'] ?>">
                            <input type="hidden" name="idempotency_key" value="<?= ah_e($kunci()) ?>">
                            <p class="small">Nominal dan nomor kuitansi tidak diubah. Pembatalan tercatat sebagai entri penyeimbang<?= $b['status'] === 'Sah' ? ' dan sisa tagihan naik kembali ' . ah_e(bsa_rp($b['nominal'])) : '' ?><?= $b['sumber'] === 'Tabungan' ? '; saldo tabungan dikembalikan ' . ah_e(bsa_rp($b['nominal'])) . ' sebagai transaksi pembalik pada saat yang sama' : '' ?>.</p>
                            <label class="form-label" for="alasan-bayar-<?= (int) $b['id'] ?>">Alasan <span class="text-danger" aria-hidden="true">*</span></label>
                            <input class="form-control mb-2" id="alasan-bayar-<?= (int) $b['id'] ?>" name="alasan" required minlength="5" maxlength="500">
                            <button class="btn btn-danger" type="submit" data-busy-label="Menyimpan…"><?= $b['status'] === 'Menunggu' ? 'Tolak transfer' : 'Batalkan pembayaran' ?></button>
                        </form>
                    <?php ah_action_dialog_close(); endif; ?>
                </td><?php endif; ?>
            </tr><?php endforeach; ?>
            </tbody></table></div>
    <?php endif; ?>
</section>
<?php if ($kelola && $terbit): ?>
<section class="ah-card" aria-labelledby="ah-koreksi">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-koreksi">Koreksi tagihan</h2></div>
    <div class="ah-card__body">
        <p class="small text-muted">Koreksi menambah baris potongan/tambahan beralasan dan menaikkan versi; komponen semula dan kuitansi lama tidak diubah.</p>
        <div class="d-flex flex-wrap gap-2">
        <?php ah_action_dialog_open('revisi-tagihan', 'Revisi tagihan', 'Revisi tagihan ' . bsa_periode($t['periode']), 'outline-primary', false, 'fa-pen'); ?>
            <form method="post" action="<?= ah_e($aksiUrl) ?>">
                <?= ah_csrf() ?><input type="hidden" name="aksi" value="revisi"><input type="hidden" name="tagihan_id" value="<?= (int) $t['id'] ?>">
                <input type="hidden" name="versi" value="<?= (int) $t['versi'] ?>"><input type="hidden" name="idempotency_key" value="<?= ah_e($kunci()) ?>">
                <fieldset class="mb-2"><legend class="form-label fs-6">Jenis</legend>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="jenis" id="revisi-potongan" value="Potongan"><label class="form-check-label" for="revisi-potongan">Potongan</label></div>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="jenis" id="revisi-tambahan" value="Tambahan"><label class="form-check-label" for="revisi-tambahan">Tambahan</label></div>
                </fieldset>
                <label class="form-label" for="revisi-nama">Keterangan</label>
                <input class="form-control mb-2" id="revisi-nama" name="nama" maxlength="100" placeholder="Mis. Koreksi salah ketik syahriah">
                <label class="form-label" for="revisi-nominal">Nominal (Rp)</label>
                <input class="form-control mb-2" id="revisi-nominal" name="nominal" type="text" inputmode="numeric" maxlength="13" pattern="[0-9]{1,3}(\.[0-9]{3})*|[0-9]+" autocomplete="off">
                <label class="form-label" for="revisi-jatuh-tempo">Jatuh tempo baru <span class="text-muted fw-normal">(opsional)</span></label>
                <input class="form-control mb-2" id="revisi-jatuh-tempo" name="jatuh_tempo" type="date">
                <label class="form-label" for="revisi-alasan">Alasan <span class="text-danger" aria-hidden="true">*</span></label>
                <input class="form-control mb-2" id="revisi-alasan" name="alasan" required minlength="5" maxlength="500">
                <p class="small text-muted">Total sesudah revisi tidak boleh di bawah pembayaran yang sudah tercatat (<?= ah_e(bsa_rp($t['terbayar'] + (int) $t['menunggu'])) ?>).</p>
                <button class="btn btn-primary" type="submit" data-busy-label="Menyimpan…">Simpan revisi</button>
            </form>
        <?php ah_action_dialog_close();
        if ($t['terbayar'] === 0 && (int) $t['menunggu'] === 0):
            ah_action_dialog_open('batal-tagihan', 'Batalkan tagihan', 'Batalkan tagihan ' . bsa_periode($t['periode']), 'outline-danger', false, 'fa-ban'); ?>
            <form method="post" action="<?= ah_e($aksiUrl) ?>">
                <?= ah_csrf() ?><input type="hidden" name="aksi" value="batal"><input type="hidden" name="tagihan_id" value="<?= (int) $t['id'] ?>">
                <input type="hidden" name="versi" value="<?= (int) $t['versi'] ?>"><input type="hidden" name="idempotency_key" value="<?= ah_e($kunci()) ?>">
                <p class="small">Tagihan tetap tersimpan berstatus Dibatalkan dan santri ini tidak mendapat tagihan kedua untuk periode yang sama.</p>
                <label class="form-label" for="batal-alasan">Alasan <span class="text-danger" aria-hidden="true">*</span></label>
                <input class="form-control mb-2" id="batal-alasan" name="alasan" required minlength="5" maxlength="500">
                <button class="btn btn-danger" type="submit" data-busy-label="Menyimpan…">Batalkan tagihan</button>
            </form>
        <?php ah_action_dialog_close(); endif; ?>
        </div>
    </div>
</section>
<?php endif;
if (!$wali && $t['riwayat'] !== []): ?>
<section class="ah-card" aria-labelledby="ah-riwayat">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-riwayat">Riwayat versi</h2></div>
    <div class="ah-card__body"><?= ah_timeline(array_map(static fn (array $r): array => [
        'waktu' => ah_waktu($r['waktu']), 'pelaku' => $r['aktor']['nama'],
        'judul' => 'Versi ' . $r['versi'] . ' · ' . $r['tindakan'] . ' · total ' . bsa_rp($r['total']) . ' · jatuh tempo ' . ah_tanggal($r['jatuh_tempo'], false),
        'isi' => (string) ($r['alasan'] ?? ''), 'tone' => $r['tindakan'] === 'Dibatalkan' ? 'danger' : null,
    ], $t['riwayat']), 'Riwayat versi tagihan') ?></div>
</section>
<?php endif;
endif; ?>
<p class="mt-3"><a class="btn btn-outline-secondary" href="<?= ah_e($kembali) ?>">Kembali ke rincian santri</a></p>
<?php
ah_page_close();
