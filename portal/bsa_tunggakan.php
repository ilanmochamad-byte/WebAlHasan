<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Database\PageQuery;
use App\Ui\Denial;

/**
 * Tunggakan tagihan bulanan (PRD V6 Fase 2): tagihan terbit yang belum
 * lunas. Petugas BSA melihat santri aktif dalam cakupannya, admin seluruh
 * santri (termasuk santri nonaktif yang masih bertagihan), dan orang tua
 * hanya tagihan anaknya ("Belum lunas"). Saringan GET dapat dibagikan.
 */

require_once __DIR__ . '/_bsa.php';

bsa_wajib_fase2($tagihanBsa);
$wali = $aksesBsa['sumber'] === 'wali';
$q = PageQuery::term($_GET['q'] ?? '');
$input = ['periode' => $_GET['periode'] ?? null, 'unit' => $_GET['unit'] ?? null, 'lewat' => $_GET['lewat'] ?? null, 'q' => $q, 'page' => $_GET['page'] ?? 1];
try {
    $d = $tagihanBsa->tunggakan($currentUser, $input);
} catch (BsaException $exception) {
    if ($exception->status === 422) {
        ah_flash_set('danger', $exception->getMessage());
        ah_redirect(bsa_url('bsa_tunggakan.php'));
    }
    Denial::page('Tunggakan', 'petugas BSA berpenugasan aktif, admin, dan orang tua atas anaknya', $exception->getMessage(), $exception->status);
}
$judul = $wali ? 'Tagihan belum lunas' : 'Tunggakan';
$aktif = (int) ($d['saring']['periode'] !== null) + (int) ($d['saring']['unit'] !== null) + (int) $d['saring']['lewat'];

ah_page_open([
    'title' => $judul,
    'user' => $currentUser,
    'active' => 'bsa',
    'description' => $wali ? 'Tagihan anak Anda yang belum lunas.' : 'Tagihan terbit yang belum lunas' . ($aksesBsa['kelola'] ? ' pada ' . bsa_cakupan_teks($aksesBsa) : ' di seluruh santri (hanya baca)') . '.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => bsa_judul($aksesBsa), 'url' => bsa_url('bsa.php')], ['label' => $judul]],
]);
echo bsa_nav($aksesBsa, 'bsa_tunggakan.php', $tagihanBsa);
echo ah_stats([
    ['label' => 'Tagihan belum lunas', 'value' => (string) $d['ringkasan']['jumlah']],
    ['label' => 'Total sisa', 'value' => bsa_rp($d['ringkasan']['sisa']), 'warn' => $d['ringkasan']['sisa'] > 0],
    ['label' => 'Santri', 'value' => (string) $d['ringkasan']['santri']],
], 'Ringkasan tunggakan');
if (!$wali) {
    ah_list_search($q, 'Cari nama santri atau NIS', array_filter(['periode' => $d['saring']['periode'], 'unit' => $d['saring']['unit'], 'lewat' => $d['saring']['lewat'] ? '1' : null]));
}
ah_filter_open($aktif, ['q' => $q], bsa_url('bsa_tunggakan.php')); ?>
    <div><label class="form-label" for="saring-periode">Periode</label>
        <input class="form-control" type="month" id="saring-periode" name="periode" value="<?= ah_e((string) $d['saring']['periode']) ?>"></div>
    <?php if (!$wali): ?>
    <div><label class="form-label" for="saring-unit">Unit</label>
        <select class="form-select" id="saring-unit" name="unit"><option value="">Semua unit</option>
            <?php foreach ($d['unit'] as $u): ?><option value="<?= ah_e($u) ?>"<?= $d['saring']['unit'] === $u ? ' selected' : '' ?>><?= ah_e($u) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="form-check align-self-end mb-2"><input class="form-check-input" type="checkbox" id="saring-lewat" name="lewat" value="1"<?= $d['saring']['lewat'] ? ' checked' : '' ?>>
        <label class="form-check-label" for="saring-lewat">Lewat jatuh tempo saja</label></div>
<?php ah_filter_close(['q' => $q]);
if ($d['rows'] === []) {
    echo $q !== '' || $aktif > 0 ? ah_empty('Tidak ada yang cocok', 'Coba saringan lain atau bersihkan saringan.') : ah_empty('Tidak ada tunggakan', 'Seluruh tagihan terbit sudah lunas.');
} else { ?>
    <section class="ah-card" aria-labelledby="ah-tunggakan">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-tunggakan"><?= ah_e($judul) ?></h2><span class="text-muted small"><?= (int) $d['total'] ?> tagihan</span></div>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Tagihan belum lunas, jatuh tempo terdekat di atas</caption>
            <thead><tr><th scope="col">Santri</th><th scope="col">Periode</th><th scope="col">Jatuh tempo</th><th scope="col" class="text-end">Sisa</th></tr></thead><tbody>
            <?php foreach ($d['rows'] as $r): ?><tr>
                <td data-label="Santri"><span class="ah-cell-wrap"><?= ah_e($r['santri']['nama']) ?><span class="ah-cell-sub">NIS <?= ah_e($r['santri']['nis']) ?><?= $r['santri']['kelas'] ? ' · ' . ah_e($r['santri']['kelas']) : '' ?></span></span></td>
                <td data-label="Periode"><a class="ah-row-link" href="<?= ah_e(bsa_url('bsa_tagihan.php', ['id' => $r['tagihan_id']])) ?>"><?= ah_e(bsa_periode($r['periode'])) ?></a></td>
                <td data-label="Jatuh tempo"><span class="ah-cell-wrap"><?= ah_e(ah_tanggal($r['jatuh_tempo'], false)) ?><?= $r['lewat_jatuh_tempo'] ? ' ' . ah_status('Terlambat') : '' ?></span></td>
                <td data-label="Sisa" class="text-end"><span class="ah-cell-wrap"><?= ah_e(bsa_rp($r['sisa'])) ?><span class="ah-cell-sub">dari <?= ah_e(bsa_rp($r['total'])) ?><?= !$wali && $r['menunggu_verifikasi'] > 0 ? ' · ' . ah_e(bsa_rp($r['menunggu_verifikasi'])) . ' menunggu verifikasi' : '' ?></span></span></td>
            </tr><?php endforeach; ?>
            </tbody></table></div>
    </section>
<?php }
ah_pagination((int) $d['total'], (int) $d['page'], (int) $d['per_page']);
ah_page_close();
