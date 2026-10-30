<?php

declare(strict_types=1);

use App\Report\PrintLayout;
use App\V3\V3Exception;

require_once __DIR__ . '/_keamanan.php';

/**
 * Keamanan › cetak catatan pelanggaran (PK-T2: cetak PDF lewat dialog cetak;
 * tanpa ekspor CSV). Isi sama dengan rincian dan dibaca lewat
 * `PelanggaranService::show()` dalam mode Keamanan, sehingga pembukaannya
 * tercatat di audit tanpa isi.
 */

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    keamanan_tolak(404, 'ID pelanggaran tidak valid.');
}
try {
    $detail = v3_pelanggaran_service()->show($pantauUser, (int) $id);
} catch (V3Exception $exception) {
    keamanan_tolak($exception->status === 403 ? 404 : $exception->status, $exception->status === 403 ? 'Pelanggaran tidak ditemukan atau santrinya tidak aktif.' : $exception->getMessage());
}
$row = $detail['pelanggaran'];
$e = [PrintLayout::class, 'e'];
$judul = (string) ($row['jenis'] ?? $row['kategori']);
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cetak pelanggaran · <?= $e($row['santri_nama']) ?></title>
<style><?= PrintLayout::cssDasar() ?>@page{size:A4 portrait}body{font-size:10pt}dl{display:grid;grid-template-columns:32mm 1fr;gap:3px 10px}dt{font-weight:700}dd{margin:0;white-space:pre-wrap}</style></head>
<body>
<div class="report-nav"><button type="button" id="tombol-cetak">Cetak / Simpan PDF</button>
<script>document.getElementById('tombol-cetak').addEventListener('click',function(){window.print();});</script></div>
<?= PrintLayout::kop('Catatan Pelanggaran Santri', $row['santri_nama'] . ' · ' . $row['semester'] . ' ' . $row['tahun'] . ' · ' . $row['status']) ?>
<dl>
    <dt>Jenis</dt><dd><?= $e($judul . (($row['jenis'] ?? null) !== null ? ' · ' . $row['kategori'] : '') . ' · ' . $row['tingkat']) ?></dd>
    <dt>Poin</dt><dd><?= (int) $row['poin'] ?> (total semester <?= (int) $detail['total_poin'] ?>)</dd>
    <dt>Waktu</dt><dd><?= $e($row['waktu_kejadian']) ?></dd>
    <dt>Tempat</dt><dd><?= $e($row['tempat'] ?? '—') ?></dd>
    <dt>Uraian</dt><dd><?= $e($row['uraian']) ?></dd>
    <dt>Saksi</dt><dd><?= $e($row['saksi'] ?? '—') ?></dd>
    <dt>Dicatat</dt><dd><?= $e(($row['pencatat'] ?? 'Admin pesantren') . ' · ' . $row['created_at']) ?></dd>
    <?php if ($row['alasan_revisi']): ?><dt>Alasan koreksi</dt><dd><?= $e($row['alasan_revisi']) ?></dd><?php endif ?>
    <?php if ($row['alasan_pembatalan']): ?><dt>Alasan pembatalan</dt><dd><?= $e($row['alasan_pembatalan']) ?></dd><?php endif ?>
    <dt>Lampiran</dt><dd><?= $e(count($detail['lampiran']) . ' berkas (tidak dicetak)') ?></dd>
</dl>
<p class="muted">Dicetak <?= $e(date('Y-m-d H:i')) ?> oleh <?= $e((string) ($currentUser['name'] ?? '')) ?> · Keamanan, hanya baca.</p>
</body></html>
