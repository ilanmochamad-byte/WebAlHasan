<?php
/*
 * Unduhan (U-8) — redesain website Tahap 8.
 *
 * Label jenis dan ukuran sesuai berkas sebenarnya, seluruh baris dapat
 * diklik untuk mengunduh. Nama berkas dibaca dari basis data dan dibatasi ke
 * folder `file_download/` (basename). Kontak bantuan memakai kontak resmi.
 */
require_once __DIR__ . '/koneksi.php';

use App\Situs\IsiSitus;
use App\Situs\Kerangka;

$e = static fn (mixed $v): string => Kerangka::e($v);
$cari = is_string($_GET['cari'] ?? null) ? trim(mb_substr($_GET['cari'], 0, 100)) : '';
if ($cari !== '') {
    $stmt = $koneksi->prepare('SELECT id, judul, nama_file, tanggal FROM download WHERE judul LIKE ? ORDER BY id DESC');
    $pola = '%' . addcslashes($cari, '%_\\') . '%';
    $stmt->bind_param('s', $pola);
} else {
    $stmt = $koneksi->prepare('SELECT id, judul, nama_file, tanggal FROM download ORDER BY id DESC');
}
$stmt->execute();
$berkas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$jenis = static function (string $nama): array {
    $ext = strtolower(pathinfo($nama, PATHINFO_EXTENSION));

    return match ($ext) {
        'pdf' => ['PDF', 'pdf'],
        'doc', 'docx', 'odt', 'rtf' => [strtoupper($ext), 'doc'],
        'xls', 'xlsx', 'ods', 'csv' => [strtoupper($ext), 'xls'],
        'jpg', 'jpeg', 'png', 'webp' => [strtoupper($ext === 'jpeg' ? 'jpg' : $ext), 'img'],
        'zip', 'rar' => [strtoupper($ext), 'zip'],
        default => [$ext !== '' ? strtoupper(substr($ext, 0, 4)) : 'BERKAS', 'lain'],
    };
};
$ukuran = static function (int $byte): string {
    if ($byte >= 1048576) {
        return number_format($byte / 1048576, 1, ',', '.') . ' MB';
    }

    return max(1, (int) round($byte / 1024)) . ' KB';
};
$wa = IsiSitus::whatsapp();

$situs = ['judul' => 'Unduhan', 'aktif' => 'unduhan', 'deskripsi' => 'Formulir, brosur, dan dokumen resmi Pesantren Al Hasan.'];
include 'header.php';
?>
<div class="s-wrap pb-4">
    <header class="s-head">
        <ol class="s-crumbs"><li><a href="<?= $e(app_url('/index.php')) ?>">Beranda</a></li><li aria-current="page">Unduhan</li></ol>
        <h1>Unduhan</h1>
        <p>Formulir, brosur, dan dokumen resmi.</p>
    </header>
    <form class="s-cari" method="get" action="<?= $e(app_url('/download.php')) ?>" role="search">
        <label class="s-vh" for="cari-unduhan">Cari dokumen</label>
        <input class="s-input" id="cari-unduhan" type="search" name="cari" value="<?= $e($cari) ?>" placeholder="Cari dokumen…" maxlength="100">
        <button class="s-btn s-btn--pri" type="submit" data-s-tanpa-kunci>Cari</button>
        <?php if ($cari !== ''): ?><a class="s-btn" href="<?= $e(app_url('/download.php')) ?>">Hapus pencarian</a><?php endif; ?>
    </form>
    <?php if ($berkas === []): ?>
        <div class="s-kosong"><b><?= $cari !== '' ? 'Dokumen tidak ditemukan' : 'Belum ada dokumen' ?></b><?= $cari !== '' ? 'Coba kata kunci lain.' : 'Dokumen resmi akan tampil di sini setelah diunggah pesantren.' ?></div>
    <?php else: ?>
        <ul class="s-list">
            <?php foreach ($berkas as $b):
                $nama = basename((string) $b['nama_file']);
                $jalur = __DIR__ . '/file_download/' . $nama;
                [$label, $kelas] = $jenis($nama);
                $meta = [$label];
                if ($nama !== '' && is_file($jalur)) {
                    $meta[] = $ukuran((int) filesize($jalur));
                }
                if (!empty($b['tanggal'])) {
                    $meta[] = Kerangka::tanggal($b['tanggal']);
                }
                ?>
                <li>
                    <a href="<?= $e(app_url('/file_download/' . rawurlencode($nama))) ?>" download>
                        <span class="s-jenis s-jenis--<?= $e($kelas) ?>" aria-hidden="true"><?= $e($label) ?></span>
                        <span class="s-list__main"><b><?= $e($b['judul']) ?></b><small><?= $e(implode(' · ', $meta)) ?></small></span>
                        <span class="s-list__aksi">Unduh<span class="s-vh"> <?= $e($b['judul']) ?></span></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($wa !== '' || IsiSitus::ada('email') || IsiSitus::ada('telepon')): ?>
        <p class="s-muted mt-3">Butuh dokumen lain? Hubungi admin pesantren
            <?php if ($wa !== ''): ?>lewat WhatsApp <a href="https://wa.me/<?= $e($wa) ?>" target="_blank" rel="noopener">+<?= $e($wa) ?></a><?php elseif (IsiSitus::ada('telepon')): ?>di <?= $e(IsiSitus::teks('telepon')) ?><?php else: ?>lewat surel <a href="mailto:<?= $e(IsiSitus::teks('email')) ?>"><?= $e(IsiSitus::teks('email')) ?></a><?php endif; ?>.
        </p>
    <?php endif; ?>
</div>
<?php include 'footer.php'; ?>
