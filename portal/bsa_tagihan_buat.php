<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Bsa\BsaService;
use App\Ui\Denial;

/**
 * Terbitkan satu tagihan bulanan untuk satu santri dengan komponen manual
 * (PRD V6 Fase 1). Penerbitan dari tarif unit untuk banyak santri ada di
 * `bsa_penerbitan.php` (Fase 2).
 *
 * Hanya petugas BSA berpenugasan aktif untuk santri aktif dalam cakupannya.
 * Satu santri + satu periode paling banyak satu tagihan (kunci unik basis
 * data); komponen disalin sebagai snapshot. Penerbitan tidak memotong tabungan.
 */

require_once __DIR__ . '/_bsa.php';

$judul = 'Terbitkan tagihan';
if (!$aksesBsa['kelola']) {
    Denial::page($judul, 'pengurus dengan penugasan BSA aktif',
        $aksesBsa['sumber'] === 'wali' ? 'Orang tua hanya melihat keuangan anaknya.' : 'Admin hanya membaca dan mengaudit keuangan santri.');
}
$santriId = max(0, (int) ($_GET['santri_id'] ?? 0));
$kembali = bsa_url('bsa_santri.php', ['id' => $santriId]);

$galat = null;
$isian = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $hasil = $bsa->terbitkanTagihan($currentUser, ['santri_id' => $santriId] + $_POST, BsaService::KANAL_WEB);
        ah_flash_set('success', $hasil['replayed'] ? 'Tagihan ini sudah terbit sebelumnya. Tidak ada tagihan ganda yang dibuat.' : 'Tagihan terbit.');
        ah_redirect(bsa_url('bsa_tagihan.php', ['id' => $hasil['id']]));
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
$komponen = is_array($isian['komponen'] ?? null) ? array_values($isian['komponen']) : [];
$kunci = bsa_kunci_formulir($isian);
$periodeBawaan = date('Y-m');

ah_page_open([
    'title' => $judul,
    'user' => $currentUser,
    'active' => 'bsa',
    'description' => $s['nama'] . ' · NIS ' . $s['nis'] . ($s['unit'] ? ' · unit ' . $s['unit'] : ''),
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => bsa_judul($aksesBsa), 'url' => bsa_url('bsa.php')],
        ['label' => $s['nama'], 'url' => $kembali], ['label' => 'Tagihan baru']],
]);
if ($galat !== null) {
    ah_note('danger', 'Tagihan belum terbit. ' . $galat);
}
?>
<section class="ah-card" aria-labelledby="ah-form-tagihan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-form-tagihan">Tagihan bulanan</h2></div>
    <div class="ah-card__body">
        <form method="post" action="<?= ah_e(bsa_url('bsa_tagihan_buat.php', ['santri_id' => $s['id']])) ?>" id="form-tagihan">
            <?= ah_csrf() ?>
            <input type="hidden" name="idempotency_key" value="<?= ah_e($kunci) ?>">
            <div class="row g-2 mb-3">
                <div class="col-sm-6"><label class="form-label" for="periode">Periode (bulan) <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control" type="month" id="periode" name="periode" required pattern="[0-9]{4}-[0-9]{2}" value="<?= ah_e($nilai('periode', $periodeBawaan)) ?>">
                    <p class="form-text mb-0">Satu santri hanya satu tagihan per bulan.</p></div>
                <div class="col-sm-6"><label class="form-label" for="jatuh_tempo">Jatuh tempo <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control" type="date" id="jatuh_tempo" name="jatuh_tempo" required value="<?= ah_e($nilai('jatuh_tempo', date('Y-m-10'))) ?>"></div>
            </div>
            <fieldset class="mb-3">
                <legend class="form-label fs-6">Komponen biaya <span class="text-danger" aria-hidden="true">*</span></legend>
                <p class="form-text mt-0">Isi paling sedikit satu baris. Baris yang dibiarkan kosong diabaikan.</p>
                <?php for ($i = 0; $i < 3; $i++):
                    $k = is_array($komponen[$i] ?? null) ? $komponen[$i] : [];
                    $kn = is_string($k['nama'] ?? null) ? $k['nama'] : '';
                    $kv = is_string($k['nominal'] ?? null) ? $k['nominal'] : ''; ?>
                    <div class="row g-2 mb-2">
                        <div class="col-7"><label class="form-label small" for="komponen-<?= $i ?>-nama">Nama komponen <?= $i + 1 ?></label>
                            <input class="form-control" id="komponen-<?= $i ?>-nama" name="komponen[<?= $i ?>][nama]" maxlength="100"<?= $i === 0 ? ' required' : '' ?> value="<?= ah_e($kn) ?>"></div>
                        <div class="col-5"><label class="form-label small" for="komponen-<?= $i ?>-nominal">Nominal (Rp)</label>
                            <input class="form-control" type="text" inputmode="numeric" id="komponen-<?= $i ?>-nominal" name="komponen[<?= $i ?>][nominal]" maxlength="13" pattern="[0-9]{1,3}(\.[0-9]{3})*|[0-9]+" autocomplete="off"<?= $i === 0 ? ' required' : '' ?> value="<?= ah_e($kv) ?>"></div>
                    </div>
                <?php endfor; ?>
            </fieldset>
            <div class="mb-3">
                <label class="form-label" for="catatan">Catatan untuk orang tua <span class="text-muted fw-normal">(opsional)</span></label>
                <input class="form-control" id="catatan" name="catatan" maxlength="500" value="<?= ah_e($nilai('catatan')) ?>">
            </div>
            <p class="form-text">Tagihan terbit tidak memotong tabungan. Total dihitung server dari komponen dan disalin sebagai snapshot.</p>
            <div class="ah-actbar mt-4 mb-0">
                <a class="btn btn-outline-secondary" href="<?= ah_e($kembali) ?>">Batal</a>
                <button class="btn btn-primary" type="submit" data-busy-label="Menerbitkan…">Terbitkan tagihan</button>
            </div>
        </form>
    </div>
</section>
<?php
ah_page_close();
