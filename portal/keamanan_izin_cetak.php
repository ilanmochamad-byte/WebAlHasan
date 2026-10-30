<?php

declare(strict_types=1);

use App\Izin\IzinException;
use App\Keamanan\KeamananException;
use App\Report\PrintLayout;

require_once __DIR__ . '/_keamanan.php';

/**
 * Keamanan › Perizinan › Cetak (PK-T2: cetak PDF lewat dialog cetak peramban;
 * ekspor CSV tidak disediakan). Seluruh baris dengan saringan yang SAMA dengan
 * daftar (paling banyak 5.000), tanpa kerangka portal agar keluaran bersih.
 * Seperti daftar, laporan ini tanpa alasan izin; isi lengkap ada di rincian.
 * Setiap cetak dicatat di audit tanpa isi (saringan dan jumlah baris).
 */

try {
    $dokumen = keamanan_service()->izinCetak($currentUser, $_GET);
} catch (KeamananException | IzinException $exception) {
    http_response_code($exception->status());
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Laporan tidak dapat dicetak</title>'
        . '<p style="font-family:Arial,sans-serif">Laporan tidak dapat dicetak: ' . ah_e($exception->getMessage()) . '</p>';
    exit;
}
$e = [PrintLayout::class, 'e'];
$saring = [];
foreach (['asal' => 'Asal', 'tahap' => 'Tahap', 'hasil' => 'Hasil', 'dari' => 'Dari', 'sampai' => 'Sampai'] as $kunci => $label) {
    if (($dokumen['filter'][$kunci] ?? null) !== null) {
        $saring[] = $label . ': ' . $dokumen['filter'][$kunci];
    }
}
$pembuat = (string) ($currentUser['name'] ?? $currentUser['username'] ?? '');
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cetak perizinan santri · Keamanan</title>
<style><?= PrintLayout::cssDasar() ?></style></head>
<body>
<div class="report-nav"><button type="button" id="tombol-cetak">Cetak / Simpan PDF</button>
<script>document.getElementById('tombol-cetak').addEventListener('click',function(){window.print();});</script></div>
<?= PrintLayout::petunjukOrientasi() ?>
<?= PrintLayout::kop('Laporan Perizinan Santri — Keamanan', $dokumen['cakupan_label']) ?>
<div class="meta">
    <div>Saringan: <?= $e($saring === [] ? 'tanpa saringan' : implode(' · ', $saring)) ?></div>
    <div>Dibuat: <?= $e(date('Y-m-d H:i')) ?> oleh <?= $e($pembuat) ?></div>
    <div>Jumlah pengajuan: <?= count($dokumen['items']) ?></div>
</div>
<table>
    <colgroup><col style="width:5%"><col style="width:7%"><col style="width:20%"><col style="width:12%"><col style="width:16%"><col style="width:20%"><col style="width:20%"></colgroup>
    <thead><tr><th>No.</th><th>ID</th><th>Santri</th><th>Asal</th><th>Keluar – kembali</th><th>Status</th><th>Pembimbing / murobi</th></tr></thead>
    <tbody>
    <?php if ($dokumen['items'] === []): ?>
        <tr><td colspan="7" class="empty">Tidak ada pengajuan yang cocok dengan saringan.</td></tr>
    <?php endif; ?>
    <?php foreach ($dokumen['items'] as $nomor => $item): ?>
        <tr>
            <td><?= $nomor + 1 ?></td>
            <td>#<?= (int) $item['id'] ?></td>
            <td><?= $e($item['santri']['nama']) ?><br><span class="muted">NIS <?= $e($item['santri']['nis']) ?><?= $item['kamar'] !== null ? ' · ' . $e($item['kamar']) : '' ?></span></td>
            <td><?= $e($item['asal']) ?></td>
            <td><span class="utuh"><?= $e($item['tgl_izin']) ?></span> – <span class="utuh"><?= $e($item['tgl_kembali']) ?></span></td>
            <td><?= $e($item['status_label']) ?></td>
            <td><?= $e($item['pembimbing'] ?? $item['pengurus'] ?? '—') ?><br><span class="muted"><?= $e($item['murobi'] ?? '—') ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body></html>
