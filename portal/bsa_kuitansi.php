<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Report\PrintLayout;
use App\Ui\Denial;

/**
 * Kuitansi pembayaran tagihan bulanan (PRD V6 Fase 2), siap cetak/PDF.
 *
 * Angka tagihan diambil dari SNAPSHOT kuitansi yang dibuat sekali saat
 * pembayaran sah (versi dan total tagihan, terbayar sebelum, sisa sesudah),
 * sehingga perubahan tarif atau revisi sesudahnya tidak mengubah kuitansi.
 * Pembayaran yang kemudian dibatalkan tetap dapat dibuka dengan cap
 * DIBATALKAN. Hak sama dengan rincian tagihan: petugas dalam cakupan, admin,
 * dan orang tua anak itu (tanpa referensi/catatan internal). Transfer yang
 * belum diverifikasi belum memiliki kuitansi.
 */

require_once __DIR__ . '/_bsa.php';

bsa_wajib_fase2($tagihanBsa);
$id = max(0, (int) ($_GET['id'] ?? 0));
try {
    $b = $tagihanBsa->pembayaran($currentUser, $id);
} catch (BsaException $exception) {
    if ($exception->status === 404) {
        Denial::render('Kuitansi tidak ditemukan', 'Kuitansi yang Anda buka tidak ada atau bukan hak Anda.', 404,
            '<a class="btn btn-outline-primary" href="' . ah_e(bsa_url('bsa.php')) . '">Kembali</a>', false);
    }
    Denial::page('Kuitansi', 'petugas BSA berpenugasan aktif, admin, dan orang tua atas anaknya', $exception->getMessage(), $exception->status);
}
if ($b['kuitansi'] === null) {
    Denial::render('Kuitansi belum tersedia', 'Transfer ini belum diverifikasi petugas BSA sehingga belum memiliki kuitansi.', 404,
        '<a class="btn btn-outline-primary" href="' . ah_e(bsa_url('bsa_tagihan.php', ['id' => $b['tagihan_id']])) . '">Kembali ke tagihan</a>', false);
}
$e = static fn (mixed $v): string => PrintLayout::e($v);
$k = $b['kuitansi'];
$batal = $b['status'] === 'Dibatalkan';
$wali = $b['akses']['sumber'] === 'wali';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Kuitansi <?= $e($b['nomor_kuitansi']) ?></title>
    <style>
        <?= PrintLayout::cssDasar() ?>
        @page{size:A4 portrait;margin:14mm 14mm}
        body{font-size:10pt;max-width:190mm;margin:0 auto}
        h2{font-size:12pt}
        .report-nav{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;padding:8px 0;border-bottom:1px solid #ccd8cf}
        .report-nav a,.report-nav button{font:inherit;font-size:10pt;padding:8px 14px;border-radius:6px;border:1px solid #9dbfa8;background:#fff;color:#17231c;text-decoration:none;cursor:pointer;min-height:40px}
        .report-nav button{background:#146c43;border-color:#146c43;color:#fff}
        .lembar{position:relative}
        .info{display:grid;grid-template-columns:1fr 1fr;gap:1mm 8mm;margin:5mm 0}
        .info div{display:flex;gap:3mm}
        .info span{color:#4a544e;min-width:32mm;flex:0 0 auto}
        .info b{min-width:0;overflow-wrap:anywhere}
        @media screen and (max-width:600px){.info{grid-template-columns:minmax(0,1fr)}.report-nav{justify-content:space-between}}
        td.nominal{text-align:right;white-space:nowrap;font-weight:700}
        tr.total td{font-weight:700;background:#e8f2ea}
        .catatan{margin-top:5mm;border:1px solid #bac8bd;border-radius:4px;padding:3mm 4mm;font-size:9pt}
        .ttd-dua{display:grid;grid-template-columns:1fr 1fr;gap:10mm;margin-top:8mm;text-align:center;break-inside:avoid}
        .ttd-dua .ttd-ruang{height:18mm}
        .ttd-dua .ttd-garis{border-bottom:1px solid #17231c;margin:0 8mm}
        .stempel{position:absolute;top:30mm;right:6mm;transform:rotate(-12deg);border:3px solid #146c43;color:#146c43;font-weight:800;padding:2mm 4mm;text-align:center;font-size:14pt;letter-spacing:.08em}
        .stempel.batal{border-color:#b42318;color:#b42318}
        @media screen and (max-width:600px){.stempel{position:static;transform:none;display:inline-block;margin:3mm 0 0;font-size:11pt}}
    </style>
</head>
<body>
    <nav class="report-nav" aria-label="Tindakan dokumen">
        <a href="<?= $e(bsa_url('bsa_tagihan.php', ['id' => $b['tagihan_id']])) ?>">Kembali</a>
        <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
    </nav>
    <div class="lembar">
        <?= PrintLayout::kop('Kuitansi pembayaran tagihan bulanan', 'BSA (Bank Santri Al Hasan)') ?>
        <?php if ($batal): ?>
            <div class="stempel batal" aria-label="Status: dibatalkan">DIBATALKAN<br><small><?= $e(ah_tanggal(substr((string) $b['pembatalan']['pada'], 0, 10), false)) ?></small></div>
        <?php else: ?>
            <div class="stempel" aria-label="Status: diterima">DITERIMA</div>
        <?php endif; ?>
        <div class="info">
            <div><span>No. kuitansi</span><b><?= $e($b['nomor_kuitansi']) ?></b></div>
            <div><span>Tanggal bayar</span><b><?= $e(ah_tanggal($b['tanggal_transaksi'], false)) ?></b></div>
            <div><span>Nama santri</span><b><?= $e($b['santri']['nama']) ?></b></div>
            <div><span>NIS</span><b><?= $e($b['santri']['nis']) ?></b></div>
            <div><span>Tagihan</span><b><?= $e(bsa_periode($b['periode'])) ?> (versi <?= (int) $k['tagihan_versi'] ?>)</b></div>
            <div><span>Cara bayar</span><b><?= match ($b['sumber']) { 'Transfer' => 'Transfer bank', 'Tabungan' => 'Dari tabungan santri', default => 'Tunai' } ?></b></div>
            <?php if (($b['tabungan'] ?? null) !== null): ?><div><span>Debit tabungan</span><b><?= $e($b['tabungan']['nomor_kuitansi']) ?></b></div><?php endif; ?>
            <?php if (!$wali && ($b['referensi'] ?? null) !== null): ?><div><span>Referensi</span><b><?= $e($b['referensi']) ?></b></div><?php endif; ?>
            <div><span>Diterima oleh</span><b><?= $e($b['verifikasi']['oleh'] ?? $b['pencatat']['nama']) ?></b></div>
        </div>
        <table>
            <thead><tr><th>Uraian</th><th style="width:35%">Nominal (Rp)</th></tr></thead>
            <tbody>
                <tr><td>Total tagihan saat pembayaran</td><td class="nominal"><?= $e(number_format($k['total_tagihan'], 0, ',', '.')) ?></td></tr>
                <tr><td>Sudah dibayar sebelumnya</td><td class="nominal"><?= $e(number_format($k['terbayar_sebelum'], 0, ',', '.')) ?></td></tr>
                <tr class="total"><td>Dibayar dengan kuitansi ini</td><td class="nominal">Rp <?= $e(number_format($b['nominal'], 0, ',', '.')) ?></td></tr>
                <tr><td>Sisa tagihan sesudah pembayaran ini</td><td class="nominal"><?= $e(number_format($k['sisa_sesudah'], 0, ',', '.')) ?></td></tr>
            </tbody>
        </table>
        <div class="catatan">
            <?php if ($batal): ?>
                <b>Pembayaran ini telah dibatalkan</b> pada <?= $e(ah_waktu($b['pembatalan']['pada'])) ?><?= isset($b['pembatalan']['alasan']) ? ' oleh ' . $e($b['pembatalan']['oleh']) . '. Alasan: ' . $e($b['pembatalan']['alasan']) : '' ?>.
                Nominal di atas tidak lagi dihitung sebagai pembayaran tagihan.
            <?php else: ?>
                Angka tagihan pada kuitansi ini adalah keadaan saat pembayaran diterima dan tidak berubah oleh perubahan tarif atau revisi sesudahnya.
            <?php endif; ?>
        </div>
        <div class="ttd-dua">
            <div><div class="ttd-jabatan">Penyetor</div><div class="ttd-ruang"></div><div class="ttd-garis"></div><small>Nama jelas dan tanda tangan</small></div>
            <div><div class="ttd-jabatan">Petugas BSA</div><div class="ttd-ruang"></div><div class="ttd-garis"></div><small>Nama jelas dan tanda tangan</small></div>
        </div>
        <div class="lembar-footer"><span class="lembar-catatan">Dicetak <?= $e(date('d-m-Y H.i')) ?> WIB</span><span class="lembar-nomor">Halaman 1 dari 1</span></div>
    </div>
</body>
</html>
