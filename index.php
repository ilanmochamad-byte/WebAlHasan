<?php
/*
 * Beranda situs (U-1) — redesain website Tahap 8.
 *
 * Isi resmi (ringkasan, tentang, foto, statistik, keterangan unit) dibaca dari
 * `app/Situs/isi-situs.php`; bagian yang isinya belum diisi pesantren tidak
 * ditampilkan. Tidak ada teks contoh, nomor contoh, foto stok, atau tautan "#".
 */
require_once __DIR__ . '/koneksi.php';

use App\Situs\IsiSitus;
use App\Situs\Kerangka;

$e = static fn (mixed $v): string => Kerangka::e($v);
$u = static fn (string $p): string => Kerangka::e(app_url($p));

$stmt = $koneksi->prepare('SELECT id, judul, isi_berita, gambar, tanggal FROM berita ORDER BY id DESC LIMIT 3');
$stmt->execute();
$beritaTerbaru = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$psbDibuka = IsiSitus::psbDibuka();
$tahunPsb = IsiSitus::tahunPsb();
$fotoTentang = IsiSitus::gambar(IsiSitus::teks('foto_tentang'));
$pengasuh = IsiSitus::daftar('pengasuh');
$fotoPengasuh = IsiSitus::gambar(is_string($pengasuh['foto'] ?? null) ? $pengasuh['foto'] : '');
$statistik = array_values(array_filter(IsiSitus::daftar('statistik'), static fn ($s): bool => is_array($s)
    && trim((string) ($s['label'] ?? '')) !== '' && trim((string) ($s['nilai'] ?? '')) !== ''));
$wa = IsiSitus::whatsapp();

$situs = ['aktif' => 'beranda', 'cta_tempel' => true, 'deskripsi' => IsiSitus::teks('ringkasan') ?: IsiSitus::teks('slogan')];
include 'header.php';
?>

<section class="s-hero">
    <div class="s-wrap">
        <?php if ($psbDibuka): ?><span class="s-pill">PSB <?= $e($tahunPsb) ?> dibuka</span><?php endif; ?>
        <h1><?= $e(IsiSitus::teks('slogan') ?: IsiSitus::teks('nama')) ?></h1>
        <?php if (IsiSitus::ada('ringkasan')): ?><p><?= $e(IsiSitus::teks('ringkasan')) ?></p><?php endif; ?>
        <div class="s-acts">
            <?php if ($psbDibuka): ?><a class="s-btn s-btn--gold" href="<?= $u('/psb.php') ?>">Daftar PSB</a><?php endif; ?>
            <a class="s-btn s-btn--terang" href="<?= $u('/profil.php') ?>">Kenali pesantren</a>
        </div>
    </div>
</section>

<div class="s-wrap">
    <nav class="s-quick" aria-label="Pintasan">
        <a href="<?= $u('/psb.php') ?>"><b>Daftar PSB</b><span>Isi formulir 4 langkah</span></a>
        <a href="<?= $u('/login_pendaftar.php') ?>"><b>Portal Pendaftar</b><span>Cek status &amp; unggah berkas</span></a>
        <a href="<?= $u('/verifikasi_rapor.php') ?>"><b>Cek keaslian rapor</b><span>Masukkan kode verifikasi</span></a>
        <a href="<?= $u('/portal/') ?>"><b>Masuk Sistem</b><span>Staf dan orang tua santri</span></a>
    </nav>
</div>

<?php if (IsiSitus::ada('tentang') || $statistik !== []): ?>
<section class="s-sec" aria-labelledby="judul-tentang">
    <div class="s-wrap <?= $fotoTentang !== null ? 's-split' : '' ?>">
        <?php if ($fotoTentang !== null): ?>
            <div class="s-foto"><img src="<?= $e($fotoTentang) ?>" alt="<?= $e(IsiSitus::teks('nama')) ?>" loading="lazy"></div>
        <?php endif; ?>
        <div>
            <span class="s-eyebrow">Tentang pesantren</span>
            <h2 id="judul-tentang" class="s-sec__title"><?= $e(IsiSitus::teks('nama')) ?></h2>
            <?php if (IsiSitus::ada('tentang')): ?>
                <div class="s-read"><?= IsiSitus::paragraf(IsiSitus::teks('tentang')) ?></div>
                <?php if (trim((string) ($pengasuh['nama'] ?? '')) !== ''): ?>
                    <div class="s-pengasuh">
                        <?php if ($fotoPengasuh !== null): ?><img src="<?= $e($fotoPengasuh) ?>" alt="" loading="lazy"><?php endif; ?>
                        <div><b><?= $e($pengasuh['nama']) ?></b><small><?= $e($pengasuh['jabatan'] ?? '') ?></small></div>
                    </div>
                <?php endif; ?>
                <p class="mt-3"><a href="<?= $u('/profil.php') ?>">Baca profil pesantren</a></p>
            <?php endif; ?>
            <?php if ($statistik !== []): ?>
                <div class="s-stats">
                    <?php foreach ($statistik as $s): ?><div><b><?= $e($s['nilai']) ?></b><span><?= $e($s['label']) ?></span></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="s-sec" aria-labelledby="judul-unit">
    <div class="s-wrap">
        <div class="s-sec__head">
            <div><span class="s-eyebrow">Program pendidikan</span><h2 id="judul-unit">Unit pendidikan</h2></div>
            <a class="s-btn" href="<?= $u('/pendidikan.php') ?>">Semua unit</a>
        </div>
        <div class="s-grid s-grid--4">
            <?php foreach (IsiSitus::unit() as $kunci => $unit): ?>
                <article class="s-card s-card--unit">
                    <div class="s-card__body">
                        <span class="s-unit-kode" aria-hidden="true"><?= $e(strtoupper($kunci)) ?></span>
                        <h3 class="s-card__title"><?= $e($unit['nama']) ?></h3>
                        <?php if ($unit['keterangan'] !== ''): ?><p class="s-card__text"><?= $e($unit['keterangan']) ?></p><?php endif; ?>
                        <div class="s-card__foot">
                            <a class="s-btn s-btn--sm" href="<?= $u('/pendidikan.php') ?>#unit-<?= $e($kunci) ?>">Lihat unit<span class="s-vh"> <?= $e($unit['nama']) ?></span></a>
                            <?php if ($unit['situs'] !== ''): ?>
                                <a class="s-btn s-btn--sm s-btn--teks" href="<?= $e($unit['situs']) ?>" target="_blank" rel="noopener">Situs unit ↗<span class="s-vh"> (tab baru)</span></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="s-sec s-sec--putih" aria-labelledby="judul-kabar">
    <div class="s-wrap">
        <div class="s-sec__head">
            <div><span class="s-eyebrow">Kabar pesantren</span><h2 id="judul-kabar">Kegiatan terbaru</h2></div>
            <a class="s-btn" href="<?= $u('/berita.php') ?>">Lihat semua kabar</a>
        </div>
        <?php if ($beritaTerbaru === []): ?>
            <div class="s-kosong"><b>Belum ada kabar</b>Kabar kegiatan pesantren akan tampil di sini setelah diterbitkan.</div>
        <?php else: ?>
            <div class="s-grid">
                <?php foreach ($beritaTerbaru as $b): $gambar = Kerangka::gambarKonten('gambar', $b['gambar']); $tautan = app_url('/detail.php?id=' . (int) $b['id']); ?>
                    <article class="s-card">
                        <?php if ($gambar !== null): ?><div class="s-card__img"><img src="<?= $e($gambar) ?>" alt="" loading="lazy"></div><?php endif; ?>
                        <div class="s-card__body">
                            <span class="s-card__meta"><?= $e(Kerangka::tanggal($b['tanggal'])) ?></span>
                            <h3 class="s-card__title"><a href="<?= $e($tautan) ?>"><?= $e($b['judul']) ?></a></h3>
                            <p class="s-card__text"><?= $e(Kerangka::cuplikan((string) $b['isi_berita'])) ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($psbDibuka): ?>
<section class="s-sec" aria-labelledby="judul-ajak">
    <div class="s-wrap">
        <div class="s-panel">
            <h2 id="judul-ajak" class="h4 fw-bold">Pendaftaran santri baru <?= $e($tahunPsb) ?></h2>
            <p class="s-muted">Isi formulir dari HP atau komputer. Setelah terkirim, unggah berkas dan pantau statusnya di Portal Pendaftar.</p>
            <div class="s-acts">
                <a class="s-btn s-btn--pri" href="<?= $u('/psb.php') ?>">Daftar sekarang</a>
                <a class="s-btn" href="<?= $u('/login_pendaftar.php') ?>">Masuk Portal Pendaftar</a>
                <?php if ($wa !== ''): ?><a class="s-btn" href="https://wa.me/<?= $e($wa) ?>" target="_blank" rel="noopener">Tanya lewat WhatsApp<span class="s-vh"> (tab baru)</span></a><?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php include 'footer.php'; ?>
