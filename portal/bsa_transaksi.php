<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Bsa\BsaService;
use App\Ui\Denial;

/**
 * Catat setoran atau penarikan/belanja tabungan satu santri (PRD V6 Fase 1,
 * dilengkapi Fase 3).
 *
 * Hanya petugas BSA berpenugasan aktif untuk santri aktif dalam cakupannya.
 * Admin dan orang tua ditolak di server (`BsaService`), bukan hanya
 * disembunyikan tombolnya. Petugas pencatat sekaligus menyetujui; transaksi
 * langsung sah dan saldo berubah satu kali. Fase 3 (bila migrasi 029
 * terpasang, lewat `TabunganService`): setoran transfer tanpa pernyataan
 * "sudah memeriksa dana masuk" tercatat menunggu verifikasi dan belum
 * menambah saldo; pengeluaran dapat berjenis Penarikan atau Belanja.
 *
 * Kunci idempotensi dibuat saat formulir dirender dan dikirim tersembunyi:
 * klik ganda, muat ulang POST, atau retry jaringan memakai kunci yang sama dan
 * menghasilkan tepat satu transaksi. Bila validasi gagal, formulir dirender
 * ulang dari POST dengan kunci yang sama.
 */

require_once __DIR__ . '/_bsa.php';

$keluar = ($_GET['jenis'] ?? '') === 'penarikan';
$judul = $keluar ? 'Catat penarikan/belanja' : 'Catat setoran';
if (!$aksesBsa['kelola']) {
    Denial::page($judul, 'pengurus dengan penugasan BSA aktif',
        $aksesBsa['sumber'] === 'wali' ? 'Orang tua hanya melihat keuangan anaknya.' : 'Admin hanya membaca dan mengaudit keuangan santri.');
}
$santriId = max(0, (int) ($_GET['santri_id'] ?? 0));
$kembali = bsa_url('bsa_santri.php', ['id' => $santriId]);
$fase3 = $tabunganBsa->tersedia();

$galat = null;
$isian = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $hasil = $keluar
            ? $tabunganBsa->penarikan($currentUser, $santriId, $_POST, BsaService::KANAL_WEB)
            : $tabunganBsa->setoran($currentUser, $santriId, $_POST, BsaService::KANAL_WEB);
        $menunggu = ($hasil['status_setoran'] ?? 'Sah') === 'Menunggu';
        $kuitansi = ($hasil['transaksi_id'] ?? null) !== null && $fase3
            ? '<a href="' . ah_e(bsa_url('bsa_tabungan_kuitansi.php', ['id' => $hasil['transaksi_id']])) . '">Buka kuitansi ' . ah_e((string) $hasil['nomor_kuitansi']) . '</a>' : null;
        ah_flash_set('success', $hasil['replayed']
            ? 'Transaksi ini sudah tersimpan sebelumnya. Tidak ada transaksi ganda yang dibuat.'
            : ($menunggu ? 'Setoran transfer tercatat dan menunggu verifikasi. Saldo belum berubah (' . bsa_rp($hasil['saldo_sesudah']) . ').'
                : ($keluar ? (($hasil['jenis'] ?? 'Penarikan') === 'Belanja' ? 'Belanja tercatat.' : 'Penarikan tercatat.') : 'Setoran tercatat.') . ' Saldo sekarang ' . bsa_rp($hasil['saldo_sesudah']) . '.'), $kuitansi);
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
    $d = $bsa->santri($currentUser, $santriId);
} catch (BsaException $exception) {
    Denial::page($judul, 'pengurus dengan penugasan BSA aktif untuk santri dalam cakupannya', $exception->getMessage(), $exception->status);
}
$s = $d['santri'];
$nilai = static fn (string $kolom, string $bawaan = ''): string => is_string($isian[$kolom] ?? null) ? (string) $isian[$kolom] : $bawaan;
$kunci = bsa_kunci_formulir($isian);

ah_page_open([
    'title' => $judul,
    'user' => $currentUser,
    'active' => 'bsa',
    'description' => $s['nama'] . ' · NIS ' . $s['nis'] . ' · saldo ' . bsa_rp($d['saldo']),
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => bsa_judul($aksesBsa), 'url' => bsa_url('bsa.php')],
        ['label' => $s['nama'], 'url' => $kembali], ['label' => $keluar ? 'Penarikan' : 'Setoran']],
]);
if ($galat !== null) {
    ah_note('danger', ($keluar ? 'Penarikan' : 'Setoran') . ' belum tersimpan. ' . $galat);
}
?>
<section class="ah-card" aria-labelledby="ah-form-transaksi">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-form-transaksi"><?= $keluar ? 'Penarikan atau belanja dari tabungan' : 'Setoran tabungan' ?></h2></div>
    <div class="ah-card__body">
        <form method="post" action="<?= ah_e(bsa_url('bsa_transaksi.php', ['santri_id' => $s['id'], 'jenis' => $keluar ? 'penarikan' : 'setoran'])) ?>" id="form-transaksi">
            <?= ah_csrf() ?>
            <input type="hidden" name="idempotency_key" value="<?= ah_e($kunci) ?>">
            <div class="mb-3">
                <label class="form-label" for="nominal">Nominal (Rp) <span class="text-danger" aria-hidden="true">*</span></label>
                <input class="form-control" type="text" inputmode="numeric" id="nominal" name="nominal" required maxlength="13" pattern="[0-9]{1,3}(\.[0-9]{3})*|[0-9]+" autocomplete="off" value="<?= ah_e($nilai('nominal')) ?>" aria-describedby="nominal-bantu">
                <p class="form-text mb-0" id="nominal-bantu">Rupiah bulat, mis. 100000 atau 100.000.<?= $keluar ? ' Tidak boleh melebihi saldo ' . ah_e(bsa_rp($d['saldo'])) . '.' : '' ?></p>
            </div>
            <?php if ($keluar && $fase3): ?>
            <fieldset class="mb-3">
                <legend class="form-label fs-6">Jenis pengeluaran <span class="text-danger" aria-hidden="true">*</span></legend>
                <?php foreach (['Penarikan' => 'Penarikan uang', 'Belanja' => 'Belanja untuk santri'] as $v => $l): ?>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="jenis" id="jenis-<?= ah_e(strtolower($v)) ?>" value="<?= ah_e($v) ?>" required<?= $nilai('jenis', 'Penarikan') === $v ? ' checked' : '' ?>>
                        <label class="form-check-label" for="jenis-<?= ah_e(strtolower($v)) ?>"><?= ah_e($l) ?></label></div>
                <?php endforeach; ?>
            </fieldset>
            <?php endif; ?>
            <fieldset class="mb-3">
                <legend class="form-label fs-6"><?= $keluar ? 'Cara pengeluaran' : 'Cara penerimaan' ?> <span class="text-danger" aria-hidden="true">*</span></legend>
                <?php foreach (['Tunai' => 'Tunai', 'Transfer' => 'Transfer'] as $v => $l): ?>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="metode" id="metode-<?= ah_e(strtolower($v)) ?>" value="<?= ah_e($v) ?>" required<?= $nilai('metode', 'Tunai') === $v ? ' checked' : '' ?>>
                        <label class="form-check-label" for="metode-<?= ah_e(strtolower($v)) ?>"><?= ah_e($l) ?></label></div>
                <?php endforeach; ?>
            </fieldset>
            <div class="mb-3">
                <label class="form-label" for="referensi">Referensi/bukti <span class="text-muted fw-normal">(wajib untuk transfer)</span></label>
                <input class="form-control" id="referensi" name="referensi" maxlength="100" autocomplete="off" value="<?= ah_e($nilai('referensi')) ?>">
                <p class="form-text mb-0">Mis. nomor bukti transfer. Tidak tampil kepada orang tua dan tidak masuk log umum.</p>
            </div>
            <?php if (!$keluar && $fase3): ?>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="terverifikasi" name="terverifikasi" value="1"<?= $nilai('terverifikasi') === '1' ? ' checked' : '' ?>>
                <label class="form-check-label" for="terverifikasi">Transfer: saya sudah memeriksa dana ini masuk ke rekening pesantren</label>
                <p class="form-text mb-0">Tanpa centang, setoran transfer tercatat <b>menunggu verifikasi</b> dan belum menambah saldo. Tunai selalu langsung sah.</p>
            </div>
            <?php endif; ?>
            <?php if ($keluar): ?>
            <div class="mb-3">
                <label class="form-label" for="tujuan">Tujuan penarikan/belanja <span class="text-danger" aria-hidden="true">*</span></label>
                <input class="form-control" id="tujuan" name="tujuan" required minlength="3" maxlength="255" value="<?= ah_e($nilai('tujuan')) ?>">
                <p class="form-text mb-0">Tampil di riwayat tabungan, termasuk bagi orang tua.</p>
            </div>
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label" for="catatan">Catatan internal <span class="text-muted fw-normal">(opsional)</span></label>
                <input class="form-control" id="catatan" name="catatan" maxlength="500" value="<?= ah_e($nilai('catatan')) ?>">
            </div>
            <p class="form-text">Dengan menyimpan, Anda mencatat sekaligus menyetujui transaksi ini atas nama Anda. Transaksi sah tidak dapat dihapus; koreksi dibuat sebagai transaksi pembalik.</p>
            <div class="ah-actbar mt-4 mb-0">
                <a class="btn btn-outline-secondary" href="<?= ah_e($kembali) ?>">Batal</a>
                <button class="btn btn-primary" type="submit" data-busy-label="Menyimpan…"><?= $keluar ? 'Simpan penarikan' : 'Simpan setoran' ?></button>
            </div>
        </form>
    </div>
</section>
<?php
ah_page_close();
