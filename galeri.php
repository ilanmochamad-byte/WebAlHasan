<?php
/*
 * Galeri kegiatan (U-6) — redesain website Tahap 8.
 *
 * Tanpa album (keputusan AI). Urut dari foto terbaru, 24 foto per muatan
 * dengan "Muat lagi" (tautan biasa, berjalan tanpa JavaScript). Keterangan
 * selalu terlihat di bawah foto; klik foto membuka penampil layar penuh.
 */
require_once __DIR__ . '/koneksi.php';

use App\Situs\Kerangka;

const GALERI_PER_MUAT = 24;

$e = static fn (mixed $v): string => Kerangka::e($v);
$jumlah = (int) filter_var($_GET['jumlah'] ?? GALERI_PER_MUAT, FILTER_VALIDATE_INT, ['options' => ['default' => GALERI_PER_MUAT, 'min_range' => GALERI_PER_MUAT, 'max_range' => 960]]);
$jumlah = (int) (ceil($jumlah / GALERI_PER_MUAT) * GALERI_PER_MUAT);

$total = (int) $koneksi->query('SELECT COUNT(*) FROM galeri')->fetch_row()[0];
$stmt = $koneksi->prepare('SELECT id, judul, keterangan, gambar, tanggal FROM galeri ORDER BY tanggal DESC, id DESC LIMIT ?');
$stmt->bind_param('i', $jumlah);
$stmt->execute();
$foto = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$situs = ['judul' => 'Galeri kegiatan', 'aktif' => 'galeri', 'deskripsi' => 'Foto kegiatan Pesantren Al Hasan Ciamis.'];
include 'header.php';
?>
<div class="s-wrap">
    <header class="s-head">
        <ol class="s-crumbs"><li><a href="<?= $e(app_url('/index.php')) ?>">Beranda</a></li><li aria-current="page">Galeri</li></ol>
        <h1>Galeri kegiatan</h1>
        <p>Aktivitas harian, fasilitas, dan momen di Pesantren Al Hasan.</p>
    </header>
    <?php if ($foto === []): ?>
        <div class="s-kosong"><b>Belum ada foto</b>Foto kegiatan akan tampil di sini setelah diunggah pesantren.</div>
    <?php else: ?>
        <ul class="s-media">
            <?php foreach ($foto as $i => $f): $src = Kerangka::gambarKonten('gambar_galeri', $f['gambar']); if ($src === null) { continue; } ?>
                <li id="foto-<?= $i + 1 ?>">
                    <figure>
                        <a href="<?= $e($src) ?>" data-s-galeri data-s-judul="<?= $e($f['judul']) ?>" data-s-ket="<?= $e($f['keterangan'] ?? '') ?>">
                            <img src="<?= $e($src) ?>" alt="<?= $e($f['judul']) ?>" loading="lazy">
                        </a>
                        <figcaption>
                            <b><?= $e($f['judul']) ?></b>
                            <?php if (trim((string) ($f['keterangan'] ?? '')) !== ''): ?><span><?= $e($f['keterangan']) ?></span><?php endif; ?>
                            <?php if (!empty($f['tanggal'])): ?><small><?= $e(Kerangka::tanggal($f['tanggal'])) ?></small><?php endif; ?>
                        </figcaption>
                    </figure>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="s-pager">
            <span>Menampilkan <?= min($jumlah, $total) ?> dari <?= $total ?> foto</span>
            <?php if ($total > $jumlah): ?>
                <a class="s-btn" href="<?= $e(app_url('/galeri.php?jumlah=' . ($jumlah + GALERI_PER_MUAT))) ?>#foto-<?= $jumlah + 1 ?>">Muat lagi</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php include 'footer.php'; ?>
