<?php

declare(strict_types=1);

use App\Ui\Denial;
use App\V3\V3Exception;

require_once dirname(__DIR__).'/app/bootstrap.php';
$currentUser=authorization()->requireWebUser();$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);if(!$id)Denial::render('Kasus tidak ditemukan.','ID kasus tidak valid.');
try{$service=v3_konseling_service();$detail=$service->show($currentUser,(int)$id);$timeline=$service->timeline($currentUser,(int)$id,$detail);}catch(V3Exception $exception){Denial::render('Kasus tidak ditemukan atau tidak dapat dicetak.',$exception->getMessage());}
$case=$detail['kasus'];header('Cache-Control: private, no-store, max-age=0');
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cetak konseling #<?= (int)$case['id'] ?></title><style>body{font-family:system-ui,sans-serif;max-width:900px;margin:2rem auto;padding:0 1rem;color:#17202a}h1{font-size:1.5rem}.meta{color:#566573}article{border:1px solid #ccd1d1;border-radius:.5rem;padding:1rem;margin:1rem 0}dt{font-weight:700}dd{margin:0 0 .75rem;white-space:pre-wrap}@media print{button{display:none}body{margin:0;max-width:none}}</style></head><body><button onclick="window.print()">Cetak</button><h1>Catatan Konseling Internal #<?= (int)$case['id'] ?></h1><p class="meta"><?= ah_e($case['santri_nama'].' · '.$case['tahun'].' / '.$case['semester'].' · '.$case['status']) ?></p><dl><?php if($detail['akses_internal']): ?><dt>Tujuan</dt><dd><?= ah_e($case['tujuan']) ?></dd><dt>Ringkasan penutupan</dt><dd><?= ah_e($case['ringkasan_penutupan']??'—') ?></dd><?php else: ?><dt>Akses</dt><dd>Detail internal disembunyikan sesuai kewenangan murobi.</dd><?php endif ?></dl><h2>Timeline</h2><ol><?php foreach($timeline['rows'] as $event): ?><li><?= ah_e($event['waktu'].' · '.$event['jenis']) ?></li><?php endforeach ?></ol><h2>Sesi</h2><?php foreach($detail['sesi'] as $session): ?><article><strong>#<?= (int)$session['id'] ?> · <?= ah_e($session['jadwal'].' · '.$session['status']) ?></strong><?php if($detail['akses_internal']): ?><p>Ringkasan internal: <?= ah_e($session['ringkasan_internal']??'—') ?></p><p>Hasil: <?= ah_e($session['hasil']??'—') ?></p><p>Tindak lanjut: <?= ah_e($session['tindak_lanjut']??'—') ?></p><?php endif ?></article><?php endforeach ?></body></html>
