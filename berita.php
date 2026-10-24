<?php
/*
 * Kabar pesantren (U-4) — redesain website Tahap 8.
 *
 * Berita tampil langsung tanpa status/kategori (keputusan AH), 12 per
 * halaman, dengan pencarian judul/isi. Query terikat; keluaran di-escape.
 */
require_once __DIR__ . '/koneksi.php';

use App\Situs\Kerangka;

const BERITA_PER_HALAMAN = 12;

$e = static fn (mixed $v): string => Kerangka::e($v);
$cari = is_string($_GET['cari'] ?? null) ? trim(mb_substr($_GET['cari'], 0, 100)) : '';
$halaman = max(1, (int) filter_var($_GET['halaman'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['default' => 1]]));

$where = '';
$param = [];
if ($cari !== '') {
    $where = ' WHERE judul LIKE ? OR isi_berita LIKE ?';
    $pola = '%' . addcslashes($cari, '%_\\') . '%';
    $param = [$pola, $pola];
}
$stmt = $koneksi->prepare('SELECT COUNT(*) FROM berita' . $where);
if ($param !== []) {
    $stmt->bind_param('ss', ...$param);
}
$stmt->execute();
$total = (int) $stmt->get_result()->fetch_row()[0];
$stmt->close();
$jumlahHalaman = max(1, (int) ceil($total / BERITA_PER_HALAMAN));
$halaman = min($halaman, $jumlahHalaman);
$offset = ($halaman - 1) * BERITA_PER_HALAMAN;
$batas = BERITA_PER_HALAMAN;
$stmt = $koneksi->prepare('SELECT id, judul, isi_berita, gambar, tanggal FROM berita' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?');
if ($param !== []) {
    $stmt->bind_param('ssii', $param[0], $param[1], $batas, $offset);
} else {
    $stmt->bind_param('ii', $batas, $offset);
}
$stmt->execute();
$daftar = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$tautanHalaman = static fn (int $h): string => app_url('/berita.php?' . http_build_query(array_filter(['cari' => $cari, 'halaman' => $h > 1 ? $h : null])));

$situs = ['judul' => 'Kabar pesantren' . ($halaman > 1 ? ' · halaman ' . $halaman : ''), 'aktif' => 'kabar', 'deskripsi' => 'Berita dan kegiatan terbaru Pesantren Al Hasan Ciamis.'];
include 'header.php';
?>
<div class="s-wrap">
    <header class="s-head">
        <ol class="s-crumbs"><li><a href="<?= $e(app_url('/index.php')) ?>">Beranda</a></li><li aria-current="page">Kabar</li></ol>
        <h1>Kabar pesantren</h1>
        <p>Berita, kegiatan, dan informasi terkini.</p>
    </header>
    <form class="s-cari" method="get" action="<?= $e(app_url('/berita.php')) ?>" role="search">
        <label class="s-vh" for="cari-berita">Cari berita</label>
        <input class="s-input" id="cari-berita" type="search" name="cari" value="<?= $e($cari) ?>" placeholder="Cari berita…" maxlength="100">
        <button class="s-btn s-btn--pri" type="submit" data-s-tanpa-kunci>Cari</button>
        <?php if ($cari !== ''): ?><a class="s-btn" href="<?= $e(app_url('/berita.php')) ?>">Hapus pencarian</a><?php endif; ?>
    </form>

    <?php if ($daftar === []): ?>
        <div class="s-kosong">
            <b><?= $cari !== '' ? 'Berita tidak ditemukan' : 'Belum ada kabar' ?></b>
            <?= $cari !== '' ? 'Tidak ada berita yang cocok dengan “' . $e($cari) . '”. Coba kata kunci lain.' : 'Kabar kegiatan pesantren akan tampil di sini setelah diterbitkan.' ?>
        </div>
    <?php else: ?>
        <?php if ($cari !== ''): ?><p class="s-muted"><?= $total ?> berita cocok dengan “<?= $e($cari) ?>”.</p><?php endif; ?>
        <div class="s-grid">
            <?php foreach ($daftar as $b): $gambar = Kerangka::gambarKonten('gambar', $b['gambar']); $tautan = app_url('/detail.php?id=' . (int) $b['id']); ?>
                <article class="s-card">
                    <?php if ($gambar !== null): ?><div class="s-card__img"><img src="<?= $e($gambar) ?>" alt="" loading="lazy"></div><?php endif; ?>
                    <div class="s-card__body">
                        <span class="s-card__meta"><?= $e(Kerangka::tanggal($b['tanggal'])) ?></span>
                        <h2 class="s-card__title"><a href="<?= $e($tautan) ?>"><?= $e($b['judul']) ?></a></h2>
                        <p class="s-card__text"><?= $e(Kerangka::cuplikan((string) $b['isi_berita'])) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($jumlahHalaman > 1): ?>
            <nav class="s-pager" aria-label="Halaman berita">
                <span>Halaman <?= $halaman ?> dari <?= $jumlahHalaman ?></span>
                <span class="s-acts">
                    <?php if ($halaman > 1): ?><a class="s-btn" href="<?= $e($tautanHalaman($halaman - 1)) ?>" rel="prev">‹ Sebelumnya</a><?php endif; ?>
                    <?php if ($halaman < $jumlahHalaman): ?><a class="s-btn" href="<?= $e($tautanHalaman($halaman + 1)) ?>" rel="next">Berikutnya ›</a><?php endif; ?>
                </span>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php include 'footer.php'; ?>
