<?php if(!isset($konten)){http_response_code(404);exit;} ?>
<div class="v3-publikasi-konten" style="overflow-wrap:anywhere">
<p class="text-muted">Santri #<?= (int)$konten['santri_id'] ?></p>
<h3 class="h6">Ringkasan untuk orang tua</h3><p style="white-space:pre-wrap"><?= ah_e($konten['ringkasan']) ?></p>
<h3 class="h6">Tindak lanjut</h3><p style="white-space:pre-wrap"><?= ah_e($konten['tindak_lanjut']??'—') ?></p>
</div>
