<?php
/*
 * Unit pendidikan (U-3) — redesain website Tahap 8.
 *
 * Empat unit dalam lingkungan pesantren. Tautan daftar membuka formulir PSB
 * dengan jenjang terpilih; RA dan MI belum dibuka (biaya belum ditetapkan,
 * keputusan Human Developer 24 Oktober 2026). Situs unit membuka tab baru.
 */
require_once __DIR__ . '/koneksi.php';

use App\Psb\Jenjang;
use App\Situs\IsiSitus;
use App\Situs\Kerangka;

$e = static fn (mixed $v): string => Kerangka::e($v);
$situs = ['judul' => 'Unit pendidikan', 'aktif' => 'pendidikan', 'deskripsi' => 'Unit pendidikan di lingkungan Pesantren Al Hasan Ciamis.'];
include 'header.php';
?>
<div class="s-wrap">
    <header class="s-head">
        <ol class="s-crumbs"><li><a href="<?= $e(app_url('/index.php')) ?>">Beranda</a></li><li aria-current="page">Unit pendidikan</li></ol>
        <h1>Unit pendidikan</h1>
        <p>Empat jenjang dalam lingkungan pesantren.</p>
    </header>
    <div class="s-grid s-grid--2 pb-4">
        <?php foreach (IsiSitus::unit() as $kunci => $unit): $dibuka = IsiSitus::psbDibuka() && Jenjang::dibuka($unit['jenjang_psb']); ?>
            <article class="s-card s-card--unit" id="unit-<?= $e($kunci) ?>" aria-labelledby="unit-judul-<?= $e($kunci) ?>">
                <div class="s-card__body">
                    <span class="s-unit-kode" aria-hidden="true"><?= $e(strtoupper($kunci)) ?></span>
                    <h2 class="s-card__title" id="unit-judul-<?= $e($kunci) ?>"><?= $e($unit['nama']) ?></h2>
                    <?php if ($unit['keterangan'] !== ''): ?><p class="mb-0"><?= $e($unit['keterangan']) ?></p><?php endif; ?>
                    <?php if ($unit['situs'] !== ''): ?><p class="s-card__meta mb-0">Situs: <?= $e(preg_replace('#^https://#', '', $unit['situs'])) ?></p><?php endif; ?>
                    <?php if (!$dibuka && IsiSitus::psbDibuka() && in_array($unit['jenjang_psb'], Jenjang::BELUM_DIBUKA, true)): ?>
                        <p class="s-card__meta mb-0"><span class="ah-badge ah-badge--muted">PSB belum dibuka</span> Pendaftaran unit ini dibuka setelah biaya ditetapkan panitia.</p>
                    <?php endif; ?>
                    <div class="s-card__foot">
                        <?php if ($dibuka): ?>
                            <a class="s-btn s-btn--pri s-btn--sm" href="<?= $e(app_url('/psb.php?jenjang=' . rawurlencode($unit['jenjang_psb']))) ?>">Daftar ke <?= $e(preg_replace('/ Terpadu Al Hasan$/', '', $unit['nama'])) ?></a>
                        <?php endif; ?>
                        <?php if ($unit['situs'] !== ''): ?>
                            <a class="s-btn s-btn--sm" href="<?= $e($unit['situs']) ?>" target="_blank" rel="noopener">Buka situs <?= $e(preg_replace('/ Terpadu Al Hasan$/', '', $unit['nama'])) ?> ↗<span class="s-vh"> (tab baru)</span></a>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>
<?php include 'footer.php'; ?>
