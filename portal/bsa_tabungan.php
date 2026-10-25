<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Database\PageQuery;
use App\Ui\Denial;

/**
 * Mutasi tabungan lintas santri dan setoran transfer menunggu verifikasi
 * (PRD V6 Fase 3). Petugas BSA melihat santri aktif dalam cakupannya, admin
 * seluruh santri (hanya baca). Orang tua melihat riwayat tabungan anaknya di
 * rincian anak, bukan di sini (403). Setiap baris ledger sudah sah; setoran
 * transfer yang menunggu belum dihitung sebagai saldo.
 */

require_once __DIR__ . '/_bsa.php';

bsa_wajib_fase3($tabunganBsa);
$q = PageQuery::term($_GET['q'] ?? '');
$tab = ($_GET['tab'] ?? '') === 'transfer' ? 'transfer' : 'mutasi';
try {
    $d = $tab === 'transfer'
        ? $tabunganBsa->daftarSetoranTransfer($currentUser, ['status' => $_GET['status'] ?? 'Menunggu', 'q' => $q, 'page' => $_GET['page'] ?? 1])
        : $tabunganBsa->daftarMutasi($currentUser, ['jenis' => $_GET['jenis'] ?? null, 'metode' => $_GET['metode'] ?? null, 'tanggal' => $_GET['tanggal'] ?? null, 'q' => $q, 'page' => $_GET['page'] ?? 1]);
} catch (BsaException $exception) {
    if ($exception->status === 422) {
        ah_flash_set('danger', $exception->getMessage());
        ah_redirect(bsa_url('bsa_tabungan.php'));
    }
    Denial::page('Tabungan', 'petugas BSA berpenugasan aktif dan admin', $exception->getMessage(), $exception->status);
}

ah_page_open([
    'title' => 'Tabungan santri',
    'user' => $currentUser,
    'active' => 'bsa',
    'description' => 'Mutasi tabungan sah dan setoran transfer yang menunggu verifikasi' . ($aksesBsa['kelola'] ? ' pada ' . bsa_cakupan_teks($aksesBsa) : ' di seluruh santri (hanya baca)') . '.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => bsa_judul($aksesBsa), 'url' => bsa_url('bsa.php')], ['label' => 'Tabungan']],
]);
echo bsa_nav($aksesBsa, 'bsa_tabungan.php', $tagihanBsa);
echo ah_seg([
    ['label' => 'Mutasi sah', 'url' => bsa_url('bsa_tabungan.php'), 'active' => $tab === 'mutasi'],
    ['label' => 'Setoran transfer', 'url' => bsa_url('bsa_tabungan.php', ['tab' => 'transfer']), 'active' => $tab === 'transfer'],
], 'Tampilan tabungan');

if ($tab === 'transfer') {
    ah_list_search($q, 'Cari nama santri, NIS, atau referensi', ['tab' => 'transfer', 'status' => $d['status']]);
    ah_filter_open($d['status'] === 'Menunggu' ? 0 : 1, ['q' => $q, 'tab' => 'transfer'], bsa_url('bsa_tabungan.php')); ?>
        <div><label class="form-label" for="saring-status">Status</label>
            <select class="form-select" id="saring-status" name="status">
                <?php foreach (['Menunggu' => 'Menunggu verifikasi', 'Sah' => 'Sah', 'Ditolak' => 'Ditolak', 'semua' => 'Semua'] as $v => $l): ?><option value="<?= ah_e($v) ?>"<?= $d['status'] === $v ? ' selected' : '' ?>><?= ah_e($l) ?></option><?php endforeach; ?></select></div>
    <?php ah_filter_close(['q' => $q, 'tab' => 'transfer']);
    if ($d['rows'] === []) {
        echo ah_empty($d['status'] === 'Menunggu' ? 'Tidak ada setoran transfer menunggu' : 'Tidak ada yang cocok', 'Setoran transfer yang dicatat tanpa pemeriksaan dana akan tampil di sini untuk diverifikasi.');
    } else { ?>
        <section class="ah-card" aria-labelledby="ah-setoran-transfer">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-setoran-transfer">Setoran transfer</h2><span class="text-muted small"><?= (int) $d['total'] ?> setoran</span></div>
            <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Setoran transfer, terbaru di atas</caption>
                <thead><tr><th scope="col">Santri</th><th scope="col">Referensi</th><th scope="col" class="text-end">Nominal</th><th scope="col">Status</th></tr></thead><tbody>
                <?php foreach ($d['rows'] as $st): ?><tr>
                    <td data-label="Santri"><span class="ah-cell-wrap"><a href="<?= ah_e(bsa_url('bsa_santri.php', ['id' => $st['santri']['id']])) ?>"><?= ah_e($st['santri']['nama']) ?></a><span class="ah-cell-sub"><?= ah_e(ah_waktu($st['dicatat_pada']) . ' · ' . $st['pencatat']['nama']) ?></span></span></td>
                    <td data-label="Referensi"><span class="ah-break"><?= ah_e($st['referensi']) ?></span></td>
                    <td data-label="Nominal" class="text-end"><?= ah_e(bsa_rp($st['nominal'])) ?></td>
                    <td data-label="Status"><span class="ah-cell-wrap"><?= bsa_status_bayar($st['status']) ?><?php if ($st['transaksi_id'] !== null): ?><span class="ah-cell-sub"><a href="<?= ah_e(bsa_url('bsa_tabungan_kuitansi.php', ['id' => $st['transaksi_id']])) ?>"><?= ah_e((string) $st['nomor_kuitansi']) ?></a></span><?php endif; ?></span></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
        </section>
    <?php }
} else {
    $saring = array_filter($d['saring'], static fn ($v): bool => $v !== null);
    ah_list_search($q, 'Cari nama santri atau NIS', $saring);
    ah_filter_open(count($saring), ['q' => $q], bsa_url('bsa_tabungan.php')); ?>
        <div><label class="form-label" for="saring-jenis">Jenis</label>
            <select class="form-select" id="saring-jenis" name="jenis"><option value="">Semua</option>
                <?php foreach (['Setoran', 'Penarikan', 'Belanja', 'PembayaranTagihan', 'Pembalik'] as $j): ?><option value="<?= ah_e($j) ?>"<?= $d['saring']['jenis'] === $j ? ' selected' : '' ?>><?= ah_e(bsa_jenis_tabungan($j)) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label" for="saring-metode">Cara</label>
            <select class="form-select" id="saring-metode" name="metode"><option value="">Semua</option>
                <?php foreach (['Tunai' => 'Tunai', 'Transfer' => 'Transfer', 'Internal' => 'Internal (tagihan)'] as $v => $l): ?><option value="<?= ah_e($v) ?>"<?= $d['saring']['metode'] === $v ? ' selected' : '' ?>><?= ah_e($l) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label" for="saring-tanggal">Tanggal</label>
            <input class="form-control" type="date" id="saring-tanggal" name="tanggal" value="<?= ah_e((string) $d['saring']['tanggal']) ?>"></div>
    <?php ah_filter_close(['q' => $q]);
    if ($d['rows'] === []) {
        echo $q !== '' || $saring !== [] ? ah_empty('Tidak ada yang cocok', 'Coba saringan lain atau bersihkan saringan.') : ah_empty('Belum ada transaksi tabungan', 'Setoran, penarikan, belanja, dan pembayaran tagihan dari tabungan akan tampil di sini.');
    } else { ?>
        <section class="ah-card" aria-labelledby="ah-mutasi">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-mutasi">Mutasi tabungan</h2><span class="text-muted small"><?= (int) $d['total'] ?> transaksi</span></div>
            <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Mutasi tabungan sah, terbaru di atas</caption>
                <thead><tr><th scope="col">Kuitansi</th><th scope="col">Santri</th><th scope="col">Transaksi</th><th scope="col" class="text-end">Nominal</th><th scope="col" class="text-end">Saldo sesudah</th></tr></thead><tbody>
                <?php foreach ($d['rows'] as $x): ?><tr>
                    <td data-label="Kuitansi"><span class="ah-cell-wrap"><a href="<?= ah_e(bsa_url('bsa_tabungan_kuitansi.php', ['id' => $x['id']])) ?>"><?= ah_e($x['nomor_kuitansi']) ?></a><span class="ah-cell-sub"><?= ah_e(ah_waktu($x['waktu'])) ?></span></span></td>
                    <td data-label="Santri"><a href="<?= ah_e(bsa_url('bsa_santri.php', ['id' => $x['santri']['id']])) ?>"><?= ah_e($x['santri']['nama']) ?></a></td>
                    <td data-label="Transaksi"><span class="ah-cell-wrap"><?= ah_e(bsa_jenis_tabungan($x['jenis']) . ' · ' . ($x['metode'] === 'Internal' ? 'internal' : $x['metode'])) ?>
                        <?php if ($x['tujuan'] !== null): ?><span class="ah-cell-sub ah-break"><?= ah_e($x['tujuan']) ?></span><?php endif; ?>
                        <?php if ($x['dikoreksi_oleh_id'] !== null): ?><span class="ah-cell-sub"><?= ah_status('Dibatalkan', 'Sudah dikoreksi') ?></span><?php endif; ?></span></td>
                    <td data-label="Nominal" class="text-end"><?= ah_e(($x['arah'] === 'Keluar' ? '−' : '+') . bsa_rp($x['nominal'])) ?></td>
                    <td data-label="Saldo sesudah" class="text-end"><?= ah_e(bsa_rp($x['saldo_sesudah'])) ?></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
        </section>
    <?php }
}
ah_pagination((int) $d['total'], (int) $d['page'], (int) $d['per_page']);
ah_page_close();
