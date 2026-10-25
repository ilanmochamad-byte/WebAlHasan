<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Ui\Denial;

/**
 * Rincian keuangan satu santri (PRD V6 Fase 1–3): saldo tabungan, tagihan,
 * dan riwayat tabungan. Tagihan dan saldo tabungan ditampilkan terpisah;
 * pembayaran tagihan dari tabungan tampil di kedua riwayat dengan nomor
 * kuitansi yang saling merujuk.
 *
 * Fase 3: setoran transfer yang menunggu verifikasi (bukan saldo efektif,
 * tidak untuk orang tua) dengan tindakan verifikasi/tolak, nomor kuitansi
 * tabungan, dan koreksi pembalik beralasan per transaksi.
 *
 * Santri di luar hak pembaca dijawab 404 oleh `BsaService::santri()`. Tombol
 * pencatatan hanya untuk petugas BSA; server tetap menolak mutasi admin/wali.
 */

require_once __DIR__ . '/_bsa.php';

$id = max(0, (int) ($_GET['id'] ?? 0));
try {
    $d = $tabunganBsa->santri($currentUser, $id, $_GET['page'] ?? 1);
} catch (BsaException $exception) {
    if ($exception->status === 404) {
        Denial::render('Santri tidak ditemukan', 'Data keuangan santri yang Anda buka tidak ada atau bukan hak Anda.', 404,
            '<a class="btn btn-outline-primary" href="' . ah_e(bsa_url('bsa.php')) . '">Kembali ke daftar</a>', false);
    }
    Denial::page(bsa_judul($aksesBsa), 'petugas BSA berpenugasan aktif, admin, dan orang tua atas anaknya', $exception->getMessage(), $exception->status);
}
$s = $d['santri'];
$kelola = $d['akses']['kelola'];
$wali = $d['akses']['sumber'] === 'wali';
$fase3 = $tabunganBsa->tersedia();
$aksiTabungan = bsa_url('bsa_tabungan_aksi.php');
$kunciBaru = static fn (): string => bin2hex(random_bytes(16));
$terbuka = array_filter($d['tagihan'], static fn (array $t): bool => $t['status'] === 'Terbit' && $t['sisa'] > 0);
$sisa = array_sum(array_map(static fn (array $t): int => $t['sisa'], $terbuka));
$aksi = $kelola ? '<a class="btn btn-primary" href="' . ah_e(bsa_url('bsa_transaksi.php', ['santri_id' => $s['id'], 'jenis' => 'setoran'])) . '"><i class="fas fa-arrow-down" aria-hidden="true"></i> Catat setoran</a>'
    . ' <a class="btn btn-outline-primary" href="' . ah_e(bsa_url('bsa_transaksi.php', ['santri_id' => $s['id'], 'jenis' => 'penarikan'])) . '"><i class="fas fa-arrow-up" aria-hidden="true"></i> Catat penarikan</a>'
    . ' <a class="btn btn-outline-primary" href="' . ah_e(bsa_url('bsa_tagihan_buat.php', ['santri_id' => $s['id']])) . '"><i class="fas fa-file-invoice" aria-hidden="true"></i> Terbitkan tagihan</a>' : '';
// Fase 2: penyesuaian tarif per santri (petugas mengelola, admin membaca; orang tua tidak).
if (!$wali && $tagihanBsa->tersedia()) {
    $aksi .= ' <a class="btn btn-outline-primary" href="' . ah_e(bsa_url('bsa_penyesuaian.php', ['santri_id' => $s['id']])) . '"><i class="fas fa-percent" aria-hidden="true"></i> Penyesuaian tarif</a>';
}

ah_page_open([
    'title' => 'Keuangan ' . $s['nama'],
    'heading' => $s['nama'],
    'user' => $currentUser,
    'active' => 'bsa',
    'description' => 'NIS ' . $s['nis'] . ($s['kelas'] ? ' · ' . $s['kelas'] . ($s['unit'] ? ' (' . $s['unit'] . ')' : '') : ' · belum ditempatkan di kelas') . ($s['aktif'] ? '' : ' · santri nonaktif'),
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => bsa_judul($aksesBsa), 'url' => bsa_url('bsa.php')], ['label' => $s['nama']]],
    'actions' => $aksi,
]);
if (!$kelola && !$wali): ?>
    <div class="ah-ribbon" role="note"><i class="fas fa-eye mt-1" aria-hidden="true"></i>
        <span><b>Hanya baca.</b> Admin tidak mencatat atau mengubah transaksi BSA.</span></div>
<?php endif;
echo ah_stats(array_merge([
    ['label' => 'Saldo tabungan', 'value' => bsa_rp($d['saldo']), 'hint' => 'Saldo efektif dari transaksi sah'],
    ['label' => 'Sisa tagihan', 'value' => bsa_rp($sisa), 'hint' => count($terbuka) . ' tagihan belum lunas', 'warn' => $sisa > 0],
], $d['saldo_menunggu'] > 0 ? [['label' => 'Setoran menunggu', 'value' => bsa_rp($d['saldo_menunggu']), 'hint' => 'Transfer belum diverifikasi; belum masuk saldo', 'warn' => true]] : []), 'Ringkasan keuangan');
?>
<section class="ah-card" aria-labelledby="ah-tagihan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-tagihan">Tagihan bulanan</h2><span class="text-muted small"><?= count($d['tagihan']) ?> tagihan</span></div>
    <?php if ($d['tagihan'] === []): ?>
        <div class="ah-card__body"><p class="text-muted mb-0">Belum ada tagihan terbit. Tagihan tidak memotong tabungan secara otomatis.</p></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Tagihan bulanan santri</caption>
            <thead><tr><th scope="col">Periode</th><th scope="col">Jatuh tempo</th><th scope="col" class="text-end">Total</th><th scope="col" class="text-end">Terbayar</th><th scope="col" class="text-end">Sisa</th><th scope="col">Status</th></tr></thead><tbody>
            <?php foreach ($d['tagihan'] as $t): ?><tr>
                <td data-label="Periode"><a class="ah-row-link" href="<?= ah_e(bsa_url('bsa_tagihan.php', ['id' => $t['id']])) ?>"><?= ah_e(bsa_periode($t['periode'])) ?></a></td>
                <td data-label="Jatuh tempo"><?= ah_e(ah_tanggal($t['jatuh_tempo'], false)) ?></td>
                <td data-label="Total" class="text-end"><?= ah_e(bsa_rp($t['total'])) ?></td>
                <td data-label="Terbayar" class="text-end"><?= ah_e(bsa_rp($t['terbayar'])) ?></td>
                <td data-label="Sisa" class="text-end"><?= ah_e(bsa_rp($t['status'] === 'Terbit' ? $t['sisa'] : 0)) ?></td>
                <td data-label="Status"><?= ah_status($t['status_bayar']) ?></td>
            </tr><?php endforeach; ?>
            </tbody></table></div>
    <?php endif; ?>
</section>
<?php if ($d['setoran_transfer'] !== []): ?>
<section class="ah-card" aria-labelledby="ah-setoran-transfer">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-setoran-transfer">Setoran transfer</h2><span class="text-muted small">Menunggu verifikasi tidak dihitung sebagai saldo</span></div>
    <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Setoran transfer menunggu, diverifikasi, atau ditolak</caption>
        <thead><tr><th scope="col">Dicatat</th><th scope="col">Referensi</th><th scope="col" class="text-end">Nominal</th><th scope="col">Status</th><?= $kelola ? '<th scope="col"><span class="ah-visually-hidden">Tindakan</span></th>' : '' ?></tr></thead><tbody>
        <?php foreach ($d['setoran_transfer'] as $st): ?><tr>
            <td data-label="Dicatat"><span class="ah-cell-wrap"><?= ah_e(ah_waktu($st['dicatat_pada'])) ?><span class="ah-cell-sub"><?= ah_e($st['pencatat']['nama']) ?></span></span></td>
            <td data-label="Referensi"><span class="ah-break"><?= ah_e($st['referensi']) ?></span></td>
            <td data-label="Nominal" class="text-end"><?= ah_e(bsa_rp($st['nominal'])) ?></td>
            <td data-label="Status"><span class="ah-cell-wrap"><?= bsa_status_bayar($st['status']) ?>
                <?php if ($st['diputus'] !== null): ?><span class="ah-cell-sub ah-break"><?= ah_e($st['diputus']['oleh'] . ' · ' . ah_waktu($st['diputus']['pada'])) ?><?= $st['alasan_tolak'] !== null ? ' · ' . ah_e($st['alasan_tolak']) : '' ?></span><?php endif; ?>
                <?php if ($st['transaksi_id'] !== null): ?><span class="ah-cell-sub"><a href="<?= ah_e(bsa_url('bsa_tabungan_kuitansi.php', ['id' => $st['transaksi_id']])) ?>"><?= ah_e((string) $st['nomor_kuitansi']) ?></a></span><?php endif; ?></span></td>
            <?php if ($kelola): ?><td data-label="Tindakan">
                <?php if ($st['status'] === 'Menunggu'): ?>
                    <form method="post" action="<?= ah_e($aksiTabungan) ?>" class="d-inline">
                        <?= ah_csrf() ?><input type="hidden" name="aksi" value="verifikasi"><input type="hidden" name="setoran_transfer_id" value="<?= (int) $st['id'] ?>"><input type="hidden" name="santri_id" value="<?= (int) $s['id'] ?>">
                        <input type="hidden" name="idempotency_key" value="<?= ah_e($kunciBaru()) ?>">
                        <button class="btn btn-sm btn-primary" type="submit" data-busy-label="Memverifikasi…">Verifikasi dana masuk</button>
                    </form>
                    <?php ah_action_dialog_open('tolak-setoran-' . (int) $st['id'], 'Tolak', 'Tolak setoran transfer ' . $st['referensi'], 'sm btn-outline-danger'); ?>
                        <form method="post" action="<?= ah_e($aksiTabungan) ?>">
                            <?= ah_csrf() ?><input type="hidden" name="aksi" value="tolak"><input type="hidden" name="setoran_transfer_id" value="<?= (int) $st['id'] ?>"><input type="hidden" name="santri_id" value="<?= (int) $s['id'] ?>">
                            <input type="hidden" name="idempotency_key" value="<?= ah_e($kunciBaru()) ?>">
                            <p class="small">Saldo tidak berubah. Penolakan tercatat dengan nama Anda, waktu, dan alasan.</p>
                            <label class="form-label" for="alasan-tolak-<?= (int) $st['id'] ?>">Alasan <span class="text-danger" aria-hidden="true">*</span></label>
                            <input class="form-control mb-2" id="alasan-tolak-<?= (int) $st['id'] ?>" name="alasan" required minlength="5" maxlength="500">
                            <button class="btn btn-danger" type="submit" data-busy-label="Menyimpan…">Tolak setoran</button>
                        </form>
                    <?php ah_action_dialog_close(); ?>
                <?php endif; ?>
            </td><?php endif; ?>
        </tr><?php endforeach; ?>
        </tbody></table></div>
</section>
<?php endif; ?>
<section class="ah-card" aria-labelledby="ah-tabungan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-tabungan">Riwayat tabungan</h2><span class="text-muted small"><?= (int) $d['tabungan']['total'] ?> transaksi</span></div>
    <?php if ($d['tabungan']['rows'] === []): ?>
        <div class="ah-card__body"><p class="text-muted mb-0">Belum ada transaksi tabungan. Saldo awal Rp0.</p></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Riwayat setoran dan penarikan tabungan, terbaru di atas</caption>
            <thead><tr><th scope="col">Waktu</th><th scope="col">Transaksi</th><th scope="col" class="text-end">Nominal</th><th scope="col" class="text-end">Saldo sesudah</th><th scope="col">Petugas</th><?= $kelola && $fase3 ? '<th scope="col"><span class="ah-visually-hidden">Tindakan</span></th>' : '' ?></tr></thead><tbody>
            <?php foreach ($d['tabungan']['rows'] as $x): ?><tr>
                <td data-label="Waktu"><span class="ah-cell-wrap"><?= ah_e(ah_waktu($x['waktu'])) ?>
                    <?php if ($fase3): ?><span class="ah-cell-sub"><a href="<?= ah_e(bsa_url('bsa_tabungan_kuitansi.php', ['id' => $x['id']])) ?>"><?= ah_e($x['nomor_kuitansi']) ?></a></span><?php endif; ?></span></td>
                <td data-label="Transaksi"><span class="ah-cell-wrap"><?= ah_e(bsa_jenis_tabungan($x['jenis']) . ' · ' . ($x['metode'] === 'Internal' ? 'internal' : $x['metode'])) ?>
                    <?php if ($x['tujuan'] !== null): ?><span class="ah-cell-sub ah-break"><?= ah_e($x['tujuan']) ?></span><?php endif; ?>
                    <?php if ($x['pembayaran'] !== null): ?><span class="ah-cell-sub ah-break"><?= $x['jenis'] === 'Pembalik' ? 'Pengembalian ' : '' ?>Kuitansi tagihan <a href="<?= ah_e(bsa_url('bsa_kuitansi.php', ['id' => $x['pembayaran']['id']])) ?>"><?= ah_e($x['pembayaran']['nomor_kuitansi']) ?></a> · <?= ah_e(bsa_periode($x['pembayaran']['periode'])) ?></span><?php endif; ?>
                    <?php if ($x['pembalik_dari'] !== null && $x['pembayaran'] === null): ?><span class="ah-cell-sub ah-break">Membalik <?= ah_e($x['pembalik_dari']['nomor_kuitansi']) ?></span><?php endif; ?>
                    <?php if ($x['dikoreksi_oleh_id'] !== null): ?><span class="ah-cell-sub"><?= ah_status('Dibatalkan', 'Sudah dikoreksi') ?></span><?php endif; ?>
                    <?php if (($x['alasan'] ?? null) !== null): ?><span class="ah-cell-sub ah-break">Alasan: <?= ah_e($x['alasan']) ?></span><?php endif; ?>
                    <?php if (($x['referensi'] ?? null) !== null): ?><span class="ah-cell-sub ah-break">Ref. <?= ah_e($x['referensi']) ?></span><?php endif; ?></span></td>
                <td data-label="Nominal" class="text-end"><?= ah_e(($x['arah'] === 'Keluar' ? '−' : '+') . bsa_rp($x['nominal'])) ?></td>
                <td data-label="Saldo sesudah" class="text-end"><?= $x['saldo_sesudah'] === null ? '—' : ah_e(bsa_rp($x['saldo_sesudah'])) ?></td>
                <td data-label="Petugas"><?= ah_e($x['petugas']['nama']) ?></td>
                <?php if ($kelola && $fase3): ?><td data-label="Tindakan">
                    <?php if (in_array($x['jenis'], ['Setoran', 'Penarikan', 'Belanja'], true) && $x['dikoreksi_oleh_id'] === null):
                        ah_action_dialog_open('koreksi-' . (int) $x['id'], 'Koreksi', 'Koreksi ' . bsa_jenis_tabungan($x['jenis']) . ' ' . $x['nomor_kuitansi'], 'sm btn-outline-danger'); ?>
                        <form method="post" action="<?= ah_e($aksiTabungan) ?>">
                            <?= ah_csrf() ?><input type="hidden" name="aksi" value="koreksi"><input type="hidden" name="transaksi_id" value="<?= (int) $x['id'] ?>"><input type="hidden" name="santri_id" value="<?= (int) $s['id'] ?>">
                            <input type="hidden" name="idempotency_key" value="<?= ah_e($kunciBaru()) ?>">
                            <p class="small">Transaksi asal tidak dihapus. Koreksi dicatat sebagai transaksi pembalik <?= $x['arah'] === 'Masuk' ? 'keluar' : 'masuk' ?> <?= ah_e(bsa_rp($x['nominal'])) ?> beralasan<?= $x['arah'] === 'Masuk' ? '; ditolak bila saldo tidak mencukupi' : '' ?>.</p>
                            <label class="form-label" for="alasan-koreksi-<?= (int) $x['id'] ?>">Alasan <span class="text-danger" aria-hidden="true">*</span></label>
                            <input class="form-control mb-2" id="alasan-koreksi-<?= (int) $x['id'] ?>" name="alasan" required minlength="5" maxlength="500">
                            <button class="btn btn-danger" type="submit" data-busy-label="Menyimpan…">Simpan koreksi</button>
                        </form>
                    <?php ah_action_dialog_close(); endif; ?>
                </td><?php endif; ?>
            </tr><?php endforeach; ?>
            </tbody></table></div>
    <?php endif; ?>
</section>
<?php
ah_pagination((int) $d['tabungan']['total'], (int) $d['tabungan']['page'], (int) $d['tabungan']['per_page']);
?>
<p class="mt-3"><a class="btn btn-outline-secondary" href="<?= ah_e(bsa_url('bsa.php')) ?>">Kembali ke daftar</a></p>
<?php
ah_page_close();
