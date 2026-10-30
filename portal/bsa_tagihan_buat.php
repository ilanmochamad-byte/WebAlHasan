<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Bsa\BsaService;
use App\Bsa\TagihanService;
use App\Ui\Denial;

/**
 * Terbitkan satu tagihan bulanan untuk satu santri dari rincian santri.
 *
 * Jalan utama memakai TARIF unit dan penyesuaian santri, lewat pratinjau dan
 * penerbitan yang sama dengan `bsa_penerbitan.php` (sasaran satu santri,
 * hash pratinjau, idempoten). Isian komponen manual (PRD V6 Fase 1) hanya
 * ditawarkan dan diterima bila tarif tidak dapat dipakai untuk santri itu
 * pada periode tersebut: belum ditempatkan di kelas, atau unitnya belum punya
 * tarif (`TagihanService::alasanTagihanManual`, diperiksa ulang saat POST).
 * Sebelum migrasi 028 hanya isian manual yang tersedia (perilaku Fase 1).
 *
 * Hanya petugas BSA berpenugasan aktif untuk santri aktif dalam cakupannya.
 * Satu santri + satu periode paling banyak satu tagihan. Penerbitan tidak
 * memotong tabungan.
 */

require_once __DIR__ . '/_bsa.php';

$judul = 'Terbitkan tagihan';
if (!$aksesBsa['kelola']) {
    Denial::page($judul, 'pengurus dengan penugasan BSA aktif',
        $aksesBsa['sumber'] === 'wali' ? 'Orang tua hanya melihat keuangan anaknya.' : 'Admin hanya membaca dan mengaudit keuangan santri.');
}
$santriId = max(0, (int) ($_GET['santri_id'] ?? 0));
$kembali = bsa_url('bsa_santri.php', ['id' => $santriId]);
$tarifTersedia = $tagihanBsa->tersedia();
$tolak = static function (BsaException $exception) use ($judul): never {
    Denial::page($judul, 'pengurus dengan penugasan BSA aktif untuk santri dalam cakupannya', $exception->getMessage(), $exception->status);
};

$galat = null;
$isian = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dariTarif = $tarifTersedia && (($_POST['mode'] ?? '') === 'tarif');
    try {
        if ($dariTarif) {
            $hasil = $tagihanBsa->terbitkanMassal($currentUser, ['santri_id' => $santriId] + $_POST, BsaService::KANAL_WEB);
            $tagihanId = null;
            foreach ($tagihanBsa->penerbitan($currentUser, $hasil['id'])['rows'] as $r) {
                $tagihanId = (int) $r['santri']['id'] === $santriId ? $r['tagihan_id'] : $tagihanId;
            }
            ah_flash_set('success', $hasil['replayed'] ? 'Tagihan ini sudah terbit sebelumnya. Tidak ada tagihan ganda yang dibuat.' : 'Tagihan terbit dari tarif.');
            ah_redirect($tagihanId === null ? $kembali : bsa_url('bsa_tagihan.php', ['id' => $tagihanId]));
        }
        // Isian manual hanya bila tarif tidak dapat dipakai untuk santri ini pada periode itu.
        if ($tarifTersedia && $tagihanBsa->alasanTagihanManual($currentUser, $santriId, $_POST['periode'] ?? null) === null) {
            throw new BsaException('Santri ini tercakup tarif unit pada periode tersebut. Terbitkan dari tarif agar nominal sesuai tarif dan penyesuaian santri.');
        }
        $hasil = $bsa->terbitkanTagihan($currentUser, ['santri_id' => $santriId] + $_POST, BsaService::KANAL_WEB);
        ah_flash_set('success', $hasil['replayed'] ? 'Tagihan ini sudah terbit sebelumnya. Tidak ada tagihan ganda yang dibuat.' : 'Tagihan terbit.');
        ah_redirect(bsa_url('bsa_tagihan.php', ['id' => $hasil['id']]));
    } catch (BsaException $exception) {
        if (in_array($exception->status, [403, 404], true)) {
            $tolak($exception);
        }
        if ($dariTarif) {
            ah_flash_set('danger', 'Tagihan belum terbit. ' . $exception->getMessage());
            ah_redirect(bsa_url('bsa_tagihan_buat.php', array_filter(['santri_id' => $santriId,
                'periode' => is_string($_POST['periode'] ?? null) ? $_POST['periode'] : null,
                'jatuh_tempo' => is_string($_POST['jatuh_tempo'] ?? null) ? $_POST['jatuh_tempo'] : null])));
        }
        http_response_code($exception->status);
        $galat = $exception->getMessage();
        $isian = $_POST;
    }
}

try {
    $d = $bsa->santri($currentUser, $santriId);
} catch (BsaException $exception) {
    $tolak($exception);
}
$s = $d['santri'];
$nilai = static fn (string $kolom, string $bawaan = ''): string => is_string($isian[$kolom] ?? null) ? (string) $isian[$kolom] : $bawaan;
$komponen = is_array($isian['komponen'] ?? null) ? array_values($isian['komponen']) : [];
$kunci = bsa_kunci_formulir($isian);
$periode = bsa_nilai_periode($_GET['periode'] ?? $isian['periode'] ?? null, date('Y-m'));
$jatuhTempoMasuk = $_GET['jatuh_tempo'] ?? $isian['jatuh_tempo'] ?? null;
$jatuhTempo = is_string($jatuhTempoMasuk) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $jatuhTempoMasuk) === 1 ? $jatuhTempoMasuk : $periode . '-10';

// Pratinjau dari tarif (hanya membaca). Baris tunggal santri ini.
$pratinjau = null;
$baris = null;
$galatPratinjau = null;
if ($tarifTersedia) {
    try {
        $pratinjau = $tagihanBsa->pratinjau($currentUser, ['santri_id' => $santriId, 'periode' => $periode, 'jatuh_tempo' => $jatuhTempo]);
        $baris = $pratinjau['rows'][0];
    } catch (BsaException $exception) {
        if (in_array($exception->status, [403, 404], true)) {
            $tolak($exception);
        }
        $galatPratinjau = $exception->getMessage();
    }
}
// Isian manual hanya bila tarif tidak dapat dipakai untuk santri ini pada periode itu
// (tanpa kelas / unit tanpa tarif), terlepas dari tagihan yang sudah ada.
$alasanManual = null;
if ($tarifTersedia) {
    try {
        $alasanManual = $tagihanBsa->alasanTagihanManual($currentUser, $santriId, $periode);
    } catch (BsaException $exception) {
        $tolak($exception);
    }
}
$bolehManual = !$tarifTersedia || $alasanManual !== null;

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
if ($tarifTersedia): ?>
<section class="ah-card" aria-labelledby="ah-form-periode">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-form-periode">1. Pilih periode</h2></div>
    <div class="ah-card__body">
        <form method="get" action="<?= ah_e(bsa_url('bsa_tagihan_buat.php')) ?>" id="form-periode">
            <input type="hidden" name="santri_id" value="<?= (int) $s['id'] ?>">
            <div class="row g-2">
                <div class="col-sm-6"><label class="form-label" for="pilih-periode">Periode (bulan)</label>
                    <input class="form-control" type="month" id="pilih-periode" name="periode" required pattern="[0-9]{4}-[0-9]{2}" value="<?= ah_e($periode) ?>">
                    <p class="form-text mb-0">Satu santri hanya satu tagihan per bulan.</p></div>
                <div class="col-sm-6"><label class="form-label" for="pilih-jatuh-tempo">Jatuh tempo</label>
                    <input class="form-control" type="date" id="pilih-jatuh-tempo" name="jatuh_tempo" required value="<?= ah_e($jatuhTempo) ?>">
                    <p class="form-text mb-0">Antara awal periode dan tiga bulan sesudahnya.</p></div>
            </div>
            <div class="ah-actbar mt-3 mb-0"><button class="btn btn-outline-primary" type="submit">Tampilkan tagihan</button></div>
        </form>
    </div>
</section>
<?php
    if ($galatPratinjau !== null) {
        ah_note('danger', $galatPratinjau);
    }
    if ($baris !== null): ?>
<section class="ah-card" aria-labelledby="ah-tagihan-tarif">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-tagihan-tarif">2. Tagihan <?= ah_e(bsa_periode($pratinjau['periode'])) ?> dari tarif</h2>
        <span class="text-muted small">Jatuh tempo <?= ah_e(ah_tanggal($pratinjau['jatuh_tempo'], false)) ?></span></div>
    <?php if ($baris['hasil'] === 'Terbit'): ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Rincian tagihan dari tarif unit dan penyesuaian santri</caption>
            <thead><tr><th scope="col">Uraian</th><th scope="col">Jenis</th><th scope="col" class="text-end">Nominal</th></tr></thead><tbody>
            <?php foreach ($baris['komponen'] as $k): ?><tr>
                <td data-label="Uraian"><?= ah_e($k['nama']) ?></td><td data-label="Jenis">Tarif unit <?= ah_e((string) $baris['unit']) ?></td>
                <td data-label="Nominal" class="text-end"><?= ah_e(bsa_rp($k['nominal'])) ?></td></tr><?php endforeach; ?>
            <?php foreach ($baris['penyesuaian'] as $j): ?><tr>
                <td data-label="Uraian"><?= ah_e($j['nama']) ?></td><td data-label="Jenis"><?= ah_e($j['jenis']) ?> santri</td>
                <td data-label="Nominal" class="text-end"><?= ah_e(($j['jenis'] === 'Potongan' ? '−' : '+') . bsa_rp($j['nominal'])) ?></td></tr><?php endforeach; ?>
            <tr class="fw-semibold"><td data-label="Uraian">Total tagihan</td><td data-label="Jenis">Tarif <?= $baris['penyesuaian'] === [] ? 'unit' : '± penyesuaian' ?></td><td data-label="Nominal" class="text-end"><?= ah_e(bsa_rp((int) $baris['total'])) ?></td></tr>
            </tbody></table></div>
        <div class="ah-card__body">
            <form method="post" action="<?= ah_e(bsa_url('bsa_tagihan_buat.php', ['santri_id' => $s['id']])) ?>" id="form-tarif">
                <?= ah_csrf() ?>
                <input type="hidden" name="mode" value="tarif">
                <input type="hidden" name="idempotency_key" value="<?= ah_e(bin2hex(random_bytes(16))) ?>">
                <input type="hidden" name="periode" value="<?= ah_e($pratinjau['periode']) ?>">
                <input type="hidden" name="jatuh_tempo" value="<?= ah_e($pratinjau['jatuh_tempo']) ?>">
                <input type="hidden" name="pratinjau_hash" value="<?= ah_e($pratinjau['hash']) ?>">
                <p class="form-text">Nominal diambil dari tarif unit dan penyesuaian santri. Tagihan menyalin rincian ini; perubahan tarif sesudahnya tidak mengubahnya. Tagihan tidak memotong tabungan.</p>
                <div class="ah-actbar mt-3 mb-0">
                    <a class="btn btn-outline-secondary" href="<?= ah_e($kembali) ?>">Batal</a>
                    <button class="btn btn-primary" type="submit" data-busy-label="Menerbitkan…">Terbitkan tagihan <?= ah_e(bsa_rp((int) $baris['total'])) ?></button>
                </div>
            </form>
        </div>
    <?php elseif ($baris['hasil'] === 'SudahAda'): ?>
        <div class="ah-card__body"><p class="mb-0"><?= ah_e((string) $baris['keterangan']) ?>.
            <a href="<?= ah_e(bsa_url('bsa_tagihan.php', ['id' => $baris['tagihan_id']])) ?>">Buka tagihan</a>. Perubahan nominal dilakukan lewat Koreksi tagihan.</p></div>
    <?php elseif ($bolehManual): ?>
        <div class="ah-card__body"><p class="mb-0"><b>Tarif tidak dapat dipakai:</b> <?= ah_e(lcfirst((string) $alasanManual)) ?>.
            <?= $alasanManual === TagihanService::DILEWATI_TANPA_TARIF[1] ? 'Tetapkan tarif unit lebih dahulu bila tagihan ini rutin, atau isi komponen manual di bawah.' : 'Tempatkan santri di kelas lebih dahulu, atau isi komponen manual di bawah.' ?></p></div>
    <?php else: ?>
        <div class="ah-card__body"><p class="mb-0">Tidak ada tagihan yang diterbitkan: <?= ah_e(lcfirst((string) $baris['keterangan'])) ?>.</p></div>
    <?php endif; ?>
</section>
<?php
    endif;
endif;

if ($bolehManual): ?>
<section class="ah-card" aria-labelledby="ah-form-tagihan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-form-tagihan"><?= $tarifTersedia ? '3. Isi komponen manual' : 'Tagihan bulanan' ?></h2></div>
    <div class="ah-card__body">
        <form method="post" action="<?= ah_e(bsa_url('bsa_tagihan_buat.php', ['santri_id' => $s['id']])) ?>" id="form-tagihan">
            <?= ah_csrf() ?>
            <input type="hidden" name="mode" value="manual">
            <input type="hidden" name="idempotency_key" value="<?= ah_e($kunci) ?>">
            <div class="row g-2 mb-3">
                <div class="col-sm-6"><label class="form-label" for="periode">Periode (bulan) <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control" type="month" id="periode" name="periode" required pattern="[0-9]{4}-[0-9]{2}" value="<?= ah_e($nilai('periode', $periode)) ?>">
                    <p class="form-text mb-0">Satu santri hanya satu tagihan per bulan.</p></div>
                <div class="col-sm-6"><label class="form-label" for="jatuh_tempo">Jatuh tempo <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control" type="date" id="jatuh_tempo" name="jatuh_tempo" required value="<?= ah_e($nilai('jatuh_tempo', $jatuhTempo)) ?>"></div>
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
<?php endif;
ah_page_close();
