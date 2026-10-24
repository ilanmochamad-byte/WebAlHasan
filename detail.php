<?php
/*
 * Detail berita (U-5) — redesain website Tahap 8.
 *
 * Kolom baca ±65 karakter, jalur kembali ke Kabar, judul dan meta deskripsi
 * dari berita agar tampil baik saat dibagikan, tombol bagikan WhatsApp dan
 * salin tautan. Nomor berita dibaca sebagai angka (query terikat). Isi berita
 * adalah HTML dari editor admin dan tetap ditampilkan seperti sebelumnya.
 */
require_once __DIR__ . '/koneksi.php';

use App\Situs\Kerangka;

$e = static fn (mixed $v): string => Kerangka::e($v);
$id = (int) filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 1]]);
$data = null;
if ($id > 0) {
    $stmt = $koneksi->prepare('SELECT id, judul, isi_berita, gambar, tanggal FROM berita WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$lain = [];
if ($data !== null) {
    $stmt = $koneksi->prepare('SELECT id, judul, tanggal FROM berita WHERE id <> ? ORDER BY id DESC LIMIT 3');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $lain = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    http_response_code(404);
}

$gambar = $data === null ? null : Kerangka::gambarKonten('gambar', $data['gambar']);
$cuplikan = $data === null ? '' : Kerangka::cuplikan((string) $data['isi_berita'], 160);
$skema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
$alamat = $data === null || $host === '' ? '' : $skema . '://' . $host . app_url('/detail.php?id=' . (int) $data['id']);

$situs = $data === null
    ? ['judul' => 'Berita tidak ditemukan', 'aktif' => 'kabar', 'robots' => 'noindex']
    : ['judul' => (string) $data['judul'], 'aktif' => 'kabar', 'deskripsi' => $cuplikan,
        'og' => ['judul' => (string) $data['judul'], 'deskripsi' => $cuplikan, 'gambar' => $gambar !== null && $host !== '' ? $skema . '://' . $host . $gambar : '']];
include 'header.php';
?>
<div class="s-wrap">
    <div class="s-read mx-auto py-4">
        <ol class="s-crumbs"><li><a href="<?= $e(app_url('/index.php')) ?>">Beranda</a></li><li><a href="<?= $e(app_url('/berita.php')) ?>">Kabar</a></li><li aria-current="page"><?= $e($data['judul'] ?? 'Tidak ditemukan') ?></li></ol>
        <?php if ($data === null): ?>
            <div class="s-kosong"><b>Berita tidak ditemukan</b>Berita mungkin sudah dihapus atau alamatnya salah.<div class="s-acts"><a class="s-btn s-btn--pri" href="<?= $e(app_url('/berita.php')) ?>">Lihat semua kabar</a></div></div>
        <?php else: ?>
            <article>
                <h1 class="fw-bold mb-2"><?= $e($data['judul']) ?></h1>
                <p class="s-muted"><?= $e(Kerangka::tanggal($data['tanggal'], true)) ?></p>
                <?php if ($gambar !== null): ?><div class="s-foto mb-4"><img src="<?= $e($gambar) ?>" alt=""></div><?php endif; ?>
                <div class="s-prose"><?= $data['isi_berita'] ?></div>
            </article>
            <div class="s-acts my-4">
                <a class="s-btn" href="https://wa.me/?text=<?= $e(rawurlencode($data['judul'] . ($alamat !== '' ? ' — ' . $alamat : ''))) ?>" target="_blank" rel="noopener">Bagikan ke WhatsApp<span class="s-vh"> (tab baru)</span></a>
                <button class="s-btn s-js-only" type="button" data-s-salin="<?= $e($alamat) ?>" data-s-salin-pesan="Tautan berita disalin.">Salin tautan</button>
            </div>
            <?php if ($lain !== []): ?>
                <h2 class="h5 fw-bold mt-5">Kabar lainnya</h2>
                <ul class="s-list">
                    <?php foreach ($lain as $l): ?>
                        <li><a href="<?= $e(app_url('/detail.php?id=' . (int) $l['id'])) ?>"><span class="s-list__main"><b><?= $e($l['judul']) ?></b><small><?= $e(Kerangka::tanggal($l['tanggal'])) ?></small></span></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php include 'footer.php'; ?>
