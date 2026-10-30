<?php

declare(strict_types=1);

use App\Report\PrintLayout;
use App\V3\V3Exception;

require_once __DIR__ . '/_keamanan.php';

/**
 * Keamanan › cetak kasus konseling Internal (PK-T2: cetak PDF lewat dialog
 * cetak; tanpa ekspor CSV). Dibaca lewat `KonselingService::show()` dalam mode
 * Keamanan: kasus Rahasia → 404, pembukaan tercatat di audit tanpa isi.
 */

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    keamanan_tolak(404, 'ID kasus tidak valid.');
}
try {
    $service = v3_konseling_service();
    $detail = $service->show($pantauUser, (int) $id);
    $timeline = $service->timeline($pantauUser, (int) $id, $detail);
} catch (V3Exception $exception) {
    keamanan_tolak($exception->status === 403 ? 404 : $exception->status, $exception->status === 403 ? 'Kasus tidak ditemukan atau tidak dapat dibaca Keamanan.' : $exception->getMessage());
}
$case = $detail['kasus'];
$e = [PrintLayout::class, 'e'];
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cetak konseling · <?= $e($case['santri_nama']) ?></title>
<style><?= PrintLayout::cssDasar() ?>@page{size:A4 portrait}body{font-size:10pt}dt{font-weight:700}dd{margin:0 0 6px;white-space:pre-wrap}article{border:1px solid #ccd1d1;border-radius:6px;padding:8px;margin:8px 0;break-inside:avoid}</style></head>
<body>
<div class="report-nav"><button type="button" id="tombol-cetak">Cetak / Simpan PDF</button>
<script>document.getElementById('tombol-cetak').addEventListener('click',function(){window.print();});</script></div>
<?= PrintLayout::kop('Catatan Konseling Internal', $case['santri_nama'] . ' · ' . $case['semester'] . ' ' . $case['tahun'] . ' · ' . $case['kerahasiaan'] . ' · ' . $case['status']) ?>
<dl>
    <dt>Tujuan</dt><dd><?= $e($case['tujuan'] ?? '—') ?></dd>
    <dt>Dibuka</dt><dd><?= $e($case['dibuka_pada']) ?></dd>
    <dt>Ringkasan penutupan</dt><dd><?= $e($case['ringkasan_penutupan'] ?? '—') ?></dd>
</dl>
<h2>Linimasa</h2>
<ol><?php foreach ($timeline['rows'] as $ev): ?><li><?= $e((string) $ev['waktu'] . ' · ' . $ev['jenis']) ?></li><?php endforeach ?></ol>
<h2>Sesi</h2>
<?php foreach ($detail['sesi_aktif'] as $nomor => $s): ?>
<article><strong>Sesi <?= $nomor + 1 ?> · <?= $e((string) ($s['realisasi'] ?? $s['jadwal']) . ' · ' . $s['status']) ?></strong>
    <p>Ringkasan internal: <?= $e($s['ringkasan_internal'] ?? '—') ?></p>
    <p>Hasil: <?= $e($s['hasil'] ?? '—') ?></p>
    <p>Tindak lanjut: <?= $e($s['tindak_lanjut'] ?? '—') ?></p></article>
<?php endforeach ?>
<?php if ($detail['sesi_aktif'] === []): ?><p>Belum ada sesi.</p><?php endif ?>
<p class="muted">Dicetak <?= $e(date('Y-m-d H:i')) ?> oleh <?= $e((string) ($currentUser['name'] ?? '')) ?> · Keamanan, hanya baca.</p>
</body></html>
