<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Bsa\BsaService;
use App\Ui\Denial;

/**
 * Bayar tagihan dari tabungan santri (PRD V6 Fase 3) — tindakan EKSPLISIT.
 *
 * Hanya petugas BSA berpenugasan aktif atas santri aktif dalam cakupannya.
 * Petugas memilih nominal (sebagian boleh) dan mencentang persetujuan
 * pemotongan tabungan; server (`TagihanService::bayarDariTabungan`)
 * memeriksa ulang sisa tagihan dan saldo, lalu mencatat debit tabungan dan
 * pembayaran tagihan dalam satu transaksi basis data dengan referensi
 * silang. Tagihan yang terbit tidak pernah memotong tabungan otomatis. Kunci
 * idempotensi tersembunyi mencegah debit ganda karena klik ganda/muat ulang.
 */

require_once __DIR__ . '/_bsa.php';

bsa_wajib_fase2($tagihanBsa);
bsa_wajib_fase3($tabunganBsa);
$judul = 'Bayar dari tabungan';
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
        $h = $tagihanBsa->bayarDariTabungan($currentUser, $tagihanId, $_POST, BsaService::KANAL_WEB);
        $tautan = '<a href="' . ah_e(bsa_url('bsa_kuitansi.php', ['id' => $h['id']])) . '">Buka kuitansi ' . ah_e($h['nomor_kuitansi']) . '</a>';
        ah_flash_set('success', $h['replayed'] ? 'Pembayaran dari tabungan ini sudah tersimpan sebelumnya. Tidak ada debit ganda.'
            : 'Tagihan dibayar dari tabungan. Sisa tagihan ' . bsa_rp($h['sisa']) . '; saldo tabungan ' . bsa_rp($h['saldo']) . '.', $tautan);
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
    $saldo = $bsa->saldo($currentUser, (int) $t['santri']['id'])['saldo'];
} catch (BsaException $exception) {
    Denial::page($judul, 'pengurus dengan penugasan BSA aktif untuk santri dalam cakupannya', $exception->getMessage(), $exception->status);
}
$s = $t['santri'];
$batasTagihan = max(0, $t['sisa'] - (int) $t['menunggu']);
$batas = min($batasTagihan, $saldo);
$nilai = static fn (string $kolom, string $bawaan = ''): string => is_string($isian[$kolom] ?? null) ? (string) $isian[$kolom] : $bawaan;

ah_page_open([
    'title' => $judul,
    'user' => $currentUser,
    'active' => 'bsa',
    'description' => $s['nama'] . ' · NIS ' . $s['nis'] . ' · tagihan ' . bsa_periode($t['periode']),
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => bsa_judul($aksesBsa), 'url' => bsa_url('bsa.php')],
        ['label' => $s['nama'], 'url' => bsa_url('bsa_santri.php', ['id' => $s['id']])], ['label' => 'Tagihan ' . bsa_periode($t['periode']), 'url' => $kembali], ['label' => 'Bayar dari tabungan']],
]);
if ($galat !== null) {
    ah_note('danger', 'Pembayaran belum tersimpan; tabungan tidak dipotong. ' . $galat);
}
echo ah_stats([
    ['label' => 'Saldo tabungan', 'value' => bsa_rp($saldo), 'hint' => 'Saldo efektif saat halaman dibuka'],
    ['label' => 'Sisa tagihan', 'value' => bsa_rp($t['sisa']), 'hint' => (int) $t['menunggu'] > 0 ? bsa_rp((int) $t['menunggu']) . ' transfer menunggu verifikasi' : 'Tagihan ' . bsa_periode($t['periode']), 'warn' => $t['sisa'] > 0],
], 'Saldo dan sisa tagihan');
if ($t['status'] !== 'Terbit' || $batas < 1): ?>
    <?= ah_empty($t['status'] !== 'Terbit' ? 'Tagihan dibatalkan' : ($batasTagihan < 1 ? 'Tidak ada sisa yang dapat dibayar' : 'Saldo tabungan kosong'),
        $t['status'] !== 'Terbit' ? 'Pembayaran tidak dapat dicatat untuk tagihan yang dibatalkan.'
            : ($batasTagihan < 1 ? 'Tagihan sudah lunas atau sisanya tertutup transfer yang menunggu verifikasi.' : 'Santri belum memiliki saldo tabungan untuk membayar tagihan ini.'),
        '<a class="btn btn-outline-primary" href="' . ah_e($kembali) . '">Kembali ke tagihan</a>') ?>
<?php else: ?>
<section class="ah-card" aria-labelledby="ah-form-bayar-tabungan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-form-bayar-tabungan">Potong tabungan untuk tagihan <?= ah_e(bsa_periode($t['periode'])) ?></h2></div>
    <div class="ah-card__body">
        <form method="post" action="<?= ah_e(bsa_url('bsa_bayar_tabungan.php', ['tagihan_id' => $t['id']])) ?>" id="form-bayar-tabungan">
            <?= ah_csrf() ?>
            <input type="hidden" name="idempotency_key" value="<?= ah_e(bsa_kunci_formulir($isian)) ?>">
            <div class="mb-3">
                <label class="form-label" for="nominal">Nominal (Rp) <span class="text-danger" aria-hidden="true">*</span></label>
                <input class="form-control" type="text" inputmode="numeric" id="nominal" name="nominal" required maxlength="13" pattern="[0-9]{1,3}(\.[0-9]{3})*|[0-9]+" autocomplete="off" value="<?= ah_e($nilai('nominal', number_format($batas, 0, ',', '.'))) ?>" aria-describedby="nominal-bantu">
                <p class="form-text mb-0" id="nominal-bantu">Pembayaran sebagian boleh. Paling banyak <?= ah_e(bsa_rp($batas)) ?> (yang lebih kecil antara saldo dan sisa tagihan).</p>
            </div>
            <div class="mb-3">
                <label class="form-label" for="catatan">Catatan internal <span class="text-muted fw-normal">(opsional)</span></label>
                <input class="form-control" id="catatan" name="catatan" maxlength="500" value="<?= ah_e($nilai('catatan')) ?>">
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="setuju" name="setuju" value="1" required<?= $nilai('setuju') === '1' ? ' checked' : '' ?>>
                <label class="form-check-label" for="setuju">Saya menyetujui pemotongan tabungan <?= ah_e($s['nama']) ?> untuk membayar tagihan ini <span class="text-danger" aria-hidden="true">*</span></label>
                <p class="form-text mb-0">Debit tabungan dan pembayaran tagihan dicatat bersama dengan nama Anda dan saling merujuk. Bukan penerimaan kas baru.</p>
            </div>
            <div class="ah-actbar mt-4 mb-0">
                <a class="btn btn-outline-secondary" href="<?= ah_e($kembali) ?>">Batal</a>
                <button class="btn btn-primary" type="submit" data-busy-label="Menyimpan…">Bayar dari tabungan</button>
            </div>
        </form>
    </div>
</section>
<?php endif;
ah_page_close();
