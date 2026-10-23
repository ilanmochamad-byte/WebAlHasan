<?php if(!isset($konten)){http_response_code(404);exit;} ?>
<?php /* Isi publikasi yang dilihat wali (V-5 pratinjau, V-7). Nama santri dan pengirim diberikan pemanggil; tanpa nomor ID. */ ?>
<div class="ah-pub v3-publikasi-konten">
<?php if(($kontenMeta??[])!==[]): ?><dl class="ah-info-list"><?php foreach($kontenMeta as $kontenLabel=>$kontenNilai): ?><div><dt><?= ah_e($kontenLabel) ?></dt><dd><?= ah_e($kontenNilai) ?></dd></div><?php endforeach ?></dl><?php endif ?>
<h3>Ringkasan</h3><p><?= ah_e($konten['ringkasan']) ?></p>
<h3>Tindak lanjut di rumah</h3><p><?= ah_e($konten['tindak_lanjut']??'—') ?></p>
</div>
