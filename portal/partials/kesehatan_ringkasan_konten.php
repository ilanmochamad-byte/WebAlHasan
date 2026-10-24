<?php if (!isset($ringkasanWali)) { http_response_code(404); exit; } ?>
<?php
/*
 * Isi ringkasan kesehatan seperti dilihat wali (PRD V5 Fase 3). Dipakai
 * pratinjau petugas, tampilan internal versi berlaku, dan halaman wali,
 * sehingga ketiganya identik. Hanya kolom proyeksi `RingkasanService`:
 * tanpa nama petugas, tanpa catatan klinis, tanpa nomor ID.
 */
$rw = $ringkasanWali;
?>
<div class="ah-pub kesehatan-ringkasan-konten">
    <dl class="ah-info-list">
        <div><dt>Santri</dt><dd><?= ah_e($rw['santri']['nama']) ?></dd></div>
        <div><dt>Tanggal kunjungan klinik</dt><dd><?= ah_e(ah_tanggal($rw['tanggal_kunjungan'], false)) ?></dd></div>
        <div><dt>Dari</dt><dd>Klinik Pesantren Al Hasan</dd></div>
    </dl>
    <?php if ($rw['status'] !== 'Terbit'): ?>
        <p class="mb-0">Informasi ini telah ditarik oleh pesantren.</p>
    <?php else: ?>
        <h3>Ringkasan</h3><p class="ah-break"><?= nl2br(ah_e((string) $rw['ringkasan'])) ?></p>
        <?php if ($rw['tindakan_umum'] !== null): ?><h3>Penanganan di klinik</h3><p class="ah-break"><?= nl2br(ah_e($rw['tindakan_umum'])) ?></p><?php endif; ?>
        <?php if ($rw['tindak_lanjut_wali'] !== null): ?><h3>Yang perlu diperhatikan orang tua</h3><p class="ah-break"><?= nl2br(ah_e($rw['tindak_lanjut_wali'])) ?></p><?php endif; ?>
        <?php if ($rw['diperbarui']): ?><p class="small text-muted mb-0">Informasi ini diperbarui pesantren setelah pertama terbit.</p><?php endif; ?>
    <?php endif; ?>
</div>
