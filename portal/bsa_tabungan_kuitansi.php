<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Report\PrintLayout;
use App\Ui\Denial;

/**
 * Kuitansi transaksi tabungan (PRD V6 Fase 3), siap cetak/PDF: setoran,
 * penarikan, belanja, pembayaran tagihan dari tabungan, dan koreksi
 * pembalik. Angka berasal dari baris ledger yang sudah sah dan tidak pernah
 * diubah (saldo sebelum = saldo sesudah ∓ nominal), sehingga kuitansi tetap
 * sama kapan pun dicetak. Transaksi yang sudah dikoreksi tetap dapat dibuka
 * dengan cap DIKOREKSI.
 *
 * Hak sama dengan rincian santri: petugas dalam cakupan, admin, dan orang tua
 * anak itu (tanpa referensi, catatan internal, dan alasan koreksi).
 */

require_once __DIR__ . '/_bsa.php';

bsa_wajib_fase3($tabunganBsa);
$id = max(0, (int) ($_GET['id'] ?? 0));
try {
    $x = $tabunganBsa->transaksi($currentUser, $id);
} catch (BsaException $exception) {
    if ($exception->status === 404) {
        Denial::render('Kuitansi tidak ditemukan', 'Kuitansi tabungan yang Anda buka tidak ada atau bukan hak Anda.', 404,
            '<a class="btn btn-outline-primary" href="' . ah_e(bsa_url('bsa.php')) . '">Kembali</a>', false);
    }
    Denial::page('Kuitansi tabungan', 'petugas BSA berpenugasan aktif, admin, dan orang tua atas anaknya', $exception->getMessage(), $exception->status);
}
$e = static fn (mixed $v): string => PrintLayout::e($v);
$rp = static fn (int $n): string => number_format($n, 0, ',', '.');
$wali = $x['akses']['sumber'] === 'wali';
$dikoreksi = $x['dikoreksi_oleh_id'] !== null;
$masuk = $x['arah'] === 'Masuk';
$judul = match ($x['jenis']) {
    'Setoran' => 'Kuitansi setoran tabungan',
    'Penarikan' => 'Bukti penarikan tabungan',
    'Belanja' => 'Bukti belanja dari tabungan',
    'PembayaranTagihan' => 'Bukti pembayaran tagihan dari tabungan',
    default => 'Bukti koreksi tabungan',
};
$cara = match ($x['metode']) {
    'Transfer' => 'Transfer bank',
    'Internal' => 'Pemindahan internal (tanpa uang tunai/transfer)',
    default => 'Tunai',
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($judul . ' ' . $x['nomor_kuitansi']) ?></title>
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
        <a href="<?= $e(bsa_url('bsa_santri.php', ['id' => $x['santri']['id']])) ?>">Kembali</a>
        <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
    </nav>
    <div class="lembar">
        <?= PrintLayout::kop($judul, 'BSA (Bank Santri Al Hasan)') ?>
        <?php if ($dikoreksi): ?>
            <div class="stempel batal" aria-label="Status: dikoreksi">DIKOREKSI</div>
        <?php else: ?>
            <div class="stempel" aria-label="Status: sah"><?= $masuk ? 'DITERIMA' : 'DIBAYARKAN' ?></div>
        <?php endif; ?>
        <div class="info">
            <div><span>No. kuitansi</span><b><?= $e($x['nomor_kuitansi']) ?></b></div>
            <div><span>Waktu</span><b><?= $e(ah_waktu($x['waktu'])) ?></b></div>
            <div><span>Nama santri</span><b><?= $e($x['santri']['nama']) ?></b></div>
            <div><span>NIS</span><b><?= $e($x['santri']['nis']) ?></b></div>
            <div><span>Jenis</span><b><?= $e(bsa_jenis_tabungan($x['jenis'])) ?></b></div>
            <div><span>Cara</span><b><?= $e($cara) ?></b></div>
            <?php if ($x['tujuan'] !== null): ?><div><span>Tujuan</span><b><?= $e($x['tujuan']) ?></b></div><?php endif; ?>
            <?php if ($x['pembayaran'] !== null): ?><div><span>Kuitansi tagihan</span><b><?= $e($x['pembayaran']['nomor_kuitansi'] . ' · ' . bsa_periode($x['pembayaran']['periode'])) ?></b></div><?php endif; ?>
            <?php if ($x['pembalik_dari'] !== null): ?><div><span>Membalik</span><b><?= $e($x['pembalik_dari']['nomor_kuitansi']) ?></b></div><?php endif; ?>
            <?php if (!$wali && ($x['referensi'] ?? null) !== null): ?><div><span>Referensi</span><b><?= $e($x['referensi']) ?></b></div><?php endif; ?>
            <div><span>Dicatat oleh</span><b><?= $e($x['petugas']['nama']) ?></b></div>
            <?php if ($x['penyetuju'] !== null && $x['penyetuju']['nama'] !== $x['petugas']['nama']): ?><div><span>Diverifikasi oleh</span><b><?= $e($x['penyetuju']['nama']) ?></b></div><?php endif; ?>
        </div>
        <table>
            <thead><tr><th>Uraian</th><th style="width:35%">Nominal (Rp)</th></tr></thead>
            <tbody>
                <tr><td>Saldo tabungan sebelum transaksi</td><td class="nominal"><?= $e($rp($x['saldo_sebelum'])) ?></td></tr>
                <tr class="total"><td><?= $masuk ? 'Masuk ke tabungan' : 'Keluar dari tabungan' ?></td><td class="nominal"><?= $masuk ? '+' : '−' ?> Rp <?= $e($rp($x['nominal'])) ?></td></tr>
                <tr><td>Saldo tabungan sesudah transaksi</td><td class="nominal"><?= $e($rp((int) $x['saldo_sesudah'])) ?></td></tr>
            </tbody>
        </table>
        <div class="catatan">
            <?php if ($dikoreksi): ?>
                <b>Transaksi ini telah dikoreksi</b> dengan transaksi pembalik. Nominal di atas tidak lagi berpengaruh pada saldo sesudah koreksi.
            <?php elseif ($x['jenis'] === 'PembayaranTagihan'): ?>
                Pemindahan internal dari tabungan ke tagihan atas persetujuan petugas BSA. Ini bukan penerimaan uang baru; uangnya sudah diterima saat setoran.
            <?php else: ?>
                Angka pada kuitansi ini adalah keadaan saat transaksi sah dan tidak berubah oleh transaksi sesudahnya.
            <?php endif; ?>
            <?php if (!$wali && ($x['alasan'] ?? null) !== null): ?><br>Alasan koreksi: <?= $e($x['alasan']) ?><?php endif; ?>
        </div>
        <div class="ttd-dua">
            <div><div class="ttd-jabatan"><?= $masuk ? 'Penyetor' : 'Penerima' ?></div><div class="ttd-ruang"></div><div class="ttd-garis"></div><small>Nama jelas dan tanda tangan</small></div>
            <div><div class="ttd-jabatan">Petugas BSA</div><div class="ttd-ruang"></div><div class="ttd-garis"></div><small>Nama jelas dan tanda tangan</small></div>
        </div>
        <div class="lembar-footer"><span class="lembar-catatan">Dicetak <?= $e(date('d-m-Y H.i')) ?> WIB</span><span class="lembar-nomor">Halaman 1 dari 1</span></div>
    </div>
</body>
</html>
