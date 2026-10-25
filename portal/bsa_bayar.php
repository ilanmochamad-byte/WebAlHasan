<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Bsa\BsaService;
use App\Ui\Denial;

/**
 * Catat pembayaran tagihan tunai/transfer (PRD V6 Fase 2).
 *
 * Hanya petugas BSA berpenugasan aktif atas santri aktif dalam cakupannya.
 * Tunai sah seketika (pencatat = verifikator). Transfer sah seketika hanya
 * bila petugas menyatakan sudah memeriksa dana masuk; selain itu menunggu
 * verifikasi dan belum mengurangi sisa. Pembayaran sebagian boleh; nominal
 * di atas sisa ditolak server. Kunci idempotensi tersembunyi mencegah
 * pembayaran ganda karena klik ganda atau muat ulang.
 */

require_once __DIR__ . '/_bsa.php';

bsa_wajib_fase2($tagihanBsa);
$judul = 'Catat pembayaran';
if (!$aksesBsa['kelola']) {
    Denial::page($judul, 'pengurus dengan penugasan BSA aktif',
        $aksesBsa['sumber'] === 'wali' ? 'Orang tua hanya melihat keuangan anaknya.' : 'Admin hanya membaca dan mengaudit keuangan santri.');
}
$tagihanId = max(0, (int) ($_GET['tagihan_id'] ?? 0));
$kembali = bsa_url('bsa_tagihan.php', ['id' => $tagihanId]);
$galat = null;
$isian = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $h = $tagihanBsa->catatPembayaran($currentUser, $tagihanId, $_POST, BsaService::KANAL_WEB);
        $kuitansi = $h['status_pembayaran'] === 'Sah' ? ' <a href="' . ah_e(bsa_url('bsa_kuitansi.php', ['id' => $h['id']])) . '">Buka kuitansi ' . ah_e($h['nomor_kuitansi']) . '</a>' : '';
        ah_flash_set('success', $h['replayed'] ? 'Pembayaran ini sudah tersimpan sebelumnya. Tidak ada pembayaran ganda.'
            : ($h['status_pembayaran'] === 'Sah' ? 'Pembayaran tercatat dan terverifikasi. Sisa tagihan ' . bsa_rp($h['sisa']) . '.'
                : 'Transfer tercatat dan menunggu verifikasi. Sisa tagihan belum berubah.'), $kuitansi === '' ? null : $kuitansi);
        ah_redirect($kembali);
    } catch (BsaException $exception) {
        if (in_array($exception->status, [403, 404], true)) {
            Denial::page($judul, 'pengurus dengan penugasan BSA aktif untuk santri dalam cakupannya', $exception->getMessage(), $exception->status);
        }
        http_response_code($exception->status);
        $galat = $exception->getMessage();
        $isian = $_POST;
    }
}
try {
    $t = $tagihanBsa->rincianTagihan($currentUser, $tagihanId);
    if (!$t['akses']['kelola']) {
        throw new BsaException('Admin hanya membaca dan mengaudit keuangan santri.', 403);
    }
} catch (BsaException $exception) {
    Denial::page($judul, 'pengurus dengan penugasan BSA aktif untuk santri dalam cakupannya', $exception->getMessage(), $exception->status);
}
$s = $t['santri'];
$batas = max(0, $t['sisa'] - (int) $t['menunggu']);
$nilai = static fn (string $kolom, string $bawaan = ''): string => is_string($isian[$kolom] ?? null) ? (string) $isian[$kolom] : $bawaan;

ah_page_open([
    'title' => $judul,
    'user' => $currentUser,
    'active' => 'bsa',
    'description' => $s['nama'] . ' · NIS ' . $s['nis'] . ' · tagihan ' . bsa_periode($t['periode']) . ' · sisa ' . bsa_rp($t['sisa']),
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => bsa_judul($aksesBsa), 'url' => bsa_url('bsa.php')],
        ['label' => $s['nama'], 'url' => bsa_url('bsa_santri.php', ['id' => $s['id']])], ['label' => 'Tagihan ' . bsa_periode($t['periode']), 'url' => $kembali], ['label' => 'Pembayaran']],
]);
if ($galat !== null) {
    ah_note('danger', 'Pembayaran belum tersimpan. ' . $galat);
}
if ($t['status'] !== 'Terbit' || $batas < 1): ?>
    <?= ah_empty($t['status'] !== 'Terbit' ? 'Tagihan dibatalkan' : 'Tidak ada sisa yang dapat dibayar', $t['status'] !== 'Terbit' ? 'Pembayaran tidak dapat dicatat untuk tagihan yang dibatalkan.'
        : ($t['menunggu'] > 0 ? 'Sisa tagihan sudah tertutup oleh transfer yang menunggu verifikasi.' : 'Tagihan ini sudah lunas.'), '<a class="btn btn-outline-primary" href="' . ah_e($kembali) . '">Kembali ke tagihan</a>') ?>
<?php else: ?>
<section class="ah-card" aria-labelledby="ah-form-bayar">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-form-bayar">Pembayaran tagihan <?= ah_e(bsa_periode($t['periode'])) ?></h2></div>
    <div class="ah-card__body">
        <form method="post" action="<?= ah_e(bsa_url('bsa_bayar.php', ['tagihan_id' => $t['id']])) ?>" id="form-bayar">
            <?= ah_csrf() ?>
            <input type="hidden" name="idempotency_key" value="<?= ah_e(bsa_kunci_formulir($isian)) ?>">
            <div class="mb-3">
                <label class="form-label" for="nominal">Nominal (Rp) <span class="text-danger" aria-hidden="true">*</span></label>
                <input class="form-control" type="text" inputmode="numeric" id="nominal" name="nominal" required maxlength="13" pattern="[0-9]{1,3}(\.[0-9]{3})*|[0-9]+" autocomplete="off" value="<?= ah_e($nilai('nominal', number_format($batas, 0, ',', '.'))) ?>" aria-describedby="nominal-bantu">
                <p class="form-text mb-0" id="nominal-bantu">Pembayaran sebagian boleh. Paling banyak <?= ah_e(bsa_rp($batas)) ?><?= $t['menunggu'] > 0 ? ' (sisa dikurangi transfer yang menunggu verifikasi)' : '' ?>.</p>
            </div>
            <fieldset class="mb-3">
                <legend class="form-label fs-6">Cara pembayaran <span class="text-danger" aria-hidden="true">*</span></legend>
                <?php foreach (['Tunai', 'Transfer'] as $v): ?>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="sumber" id="sumber-<?= ah_e(strtolower($v)) ?>" value="<?= ah_e($v) ?>" required<?= $nilai('sumber', 'Tunai') === $v ? ' checked' : '' ?>>
                        <label class="form-check-label" for="sumber-<?= ah_e(strtolower($v)) ?>"><?= ah_e($v) ?></label></div>
                <?php endforeach; ?>
            </fieldset>
            <div class="mb-3">
                <label class="form-label" for="tanggal_transaksi">Tanggal pembayaran <span class="text-danger" aria-hidden="true">*</span></label>
                <input class="form-control" type="date" id="tanggal_transaksi" name="tanggal_transaksi" required max="<?= ah_e(date('Y-m-d')) ?>" value="<?= ah_e($nilai('tanggal_transaksi', date('Y-m-d'))) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="referensi">Referensi/bukti transfer <span class="text-muted fw-normal">(wajib untuk transfer)</span></label>
                <input class="form-control" id="referensi" name="referensi" maxlength="100" autocomplete="off" value="<?= ah_e($nilai('referensi')) ?>">
                <p class="form-text mb-0">Tidak tampil kepada orang tua dan tidak masuk log umum.</p>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="terverifikasi" name="terverifikasi" value="1"<?= $nilai('terverifikasi') === '1' ? ' checked' : '' ?>>
                <label class="form-check-label" for="terverifikasi">Transfer: saya sudah memeriksa dana ini masuk ke rekening pesantren</label>
                <p class="form-text mb-0">Tanpa centang, transfer tercatat <b>menunggu verifikasi</b> dan belum mengurangi sisa. Tunai selalu langsung sah.</p>
            </div>
            <div class="mb-3">
                <label class="form-label" for="catatan">Catatan internal <span class="text-muted fw-normal">(opsional)</span></label>
                <input class="form-control" id="catatan" name="catatan" maxlength="500" value="<?= ah_e($nilai('catatan')) ?>">
            </div>
            <p class="form-text">Pembayaran sah tidak dapat dihapus; koreksi dibuat sebagai pembatalan beralasan. Tagihan tidak memotong tabungan.</p>
            <div class="ah-actbar mt-4 mb-0">
                <a class="btn btn-outline-secondary" href="<?= ah_e($kembali) ?>">Batal</a>
                <button class="btn btn-primary" type="submit" data-busy-label="Menyimpan…">Simpan pembayaran</button>
            </div>
        </form>
    </div>
</section>
<?php endif;
ah_page_close();
