<?php
/*
 * Profil pesantren (U-2) — redesain website Tahap 8.
 *
 * Satu halaman dengan 6 bagian; setiap bagian punya alamat sendiri
 * (`/profil?bagian=…`) agar dapat dibagikan. Isi dibaca dari
 * `app/Situs/isi-situs.php`. Bagian yang belum diisi pesantren
 * menampilkan keadaan kosong yang jujur, tanpa teks contoh.
 */
require_once __DIR__ . '/koneksi.php';

use App\Situs\IsiSitus;
use App\Situs\Kerangka;

$e = static fn (mixed $v): string => Kerangka::e($v);
$bagian = is_string($_GET['bagian'] ?? null) && isset(Kerangka::PROFIL[$_GET['bagian']]) ? $_GET['bagian'] : 'sejarah';
$label = Kerangka::PROFIL[$bagian];
$profil = IsiSitus::daftar('profil');

/** Struktur bertingkat: ul > li > kotak jabatan/nama, bawahan rekursif. */
$struktur = static function (array $simpul) use (&$struktur, $e): string {
    $html = '';
    foreach ($simpul as $s) {
        if (!is_array($s) || (trim((string) ($s['jabatan'] ?? '')) === '' && trim((string) ($s['nama'] ?? '')) === '')) {
            continue;
        }
        $bawahan = is_array($s['bawahan'] ?? null) ? $struktur($s['bawahan']) : '';
        $html .= '<li><span class="s-org__kotak"><small>' . $e($s['jabatan'] ?? '') . '</small><b>' . $e($s['nama'] ?? '') . '</b></span>'
            . ($bawahan !== '' ? '<ul>' . $bawahan . '</ul>' : '') . '</li>';
    }

    return $html;
};
$kosong = static fn (): string => '<div class="s-kosong"><b>Belum diterbitkan</b>Informasi ' . Kerangka::e(mb_strtolower($label))
    . ' sedang disiapkan pesantren. Hubungi pesantren melalui kontak resmi di bagian bawah halaman.</div>';

$situs = ['judul' => $label . ' · Profil', 'aktif' => 'profil', 'deskripsi' => 'Profil Pesantren Al Hasan: ' . $label . '.'];
include 'header.php';
?>
<div class="s-wrap">
    <header class="s-head">
        <ol class="s-crumbs"><li><a href="<?= $e(app_url('/index.php')) ?>">Beranda</a></li><li><a href="<?= $e(app_url('/profil.php')) ?>">Profil</a></li><li aria-current="page"><?= $e($label) ?></li></ol>
        <h1>Profil pesantren</h1>
        <?php if (IsiSitus::ada('tahun_berdiri')): ?><p>Berdiri sejak <?= $e(IsiSitus::teks('tahun_berdiri')) ?>.</p><?php endif; ?>
    </header>
    <nav aria-label="Bagian profil">
        <ul class="s-tabs">
            <?php foreach (Kerangka::PROFIL as $kunci => $teks): ?>
                <li><a href="<?= $e(app_url('/profil.php?bagian=' . $kunci)) ?>"<?= $kunci === $bagian ? ' aria-current="page"' : '' ?>><?= $e($teks) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <section class="s-sec pt-0" aria-labelledby="judul-bagian">
        <h2 id="judul-bagian" class="h3 fw-bold mb-3"><?= $e($label) ?></h2>
        <?php
        switch ($bagian) {
            case 'sejarah':
                $teks = is_string($profil['sejarah'] ?? null) ? trim($profil['sejarah']) : '';
                echo $teks === '' ? $kosong() : '<div class="s-prose s-read">' . IsiSitus::paragraf($teks) . '</div>';
                break;
            case 'visi-misi':
                $visi = is_string($profil['visi'] ?? null) ? trim($profil['visi']) : '';
                $misi = array_values(array_filter(is_array($profil['misi'] ?? null) ? $profil['misi'] : [], static fn ($m): bool => is_scalar($m) && trim((string) $m) !== ''));
                if ($visi === '' && $misi === []) {
                    echo $kosong();
                    break;
                }
                echo '<div class="s-prose s-read">';
                if ($visi !== '') {
                    echo '<h3 class="h5 fw-bold">Visi</h3>' . IsiSitus::paragraf($visi);
                }
                if ($misi !== []) {
                    echo '<h3 class="h5 fw-bold">Misi</h3><ol>';
                    foreach ($misi as $m) {
                        echo '<li>' . $e($m) . '</li>';
                    }
                    echo '</ol>';
                }
                echo '</div>';
                break;
            case 'struktur-yayasan':
            case 'pengurus-pesantren':
            case 'pengurus-santri':
                $kunci = str_replace('-', '_', $bagian);
                $isi = $struktur(is_array($profil[$kunci] ?? null) ? $profil[$kunci] : []);
                echo $isi === '' ? $kosong() : '<ul class="s-org s-org--bagan">' . $isi . '</ul>';
                break;
            case 'dewan-pengajar':
                $pengajar = array_values(array_filter(is_array($profil['dewan_pengajar'] ?? null) ? $profil['dewan_pengajar'] : [],
                    static fn ($p): bool => is_array($p) && trim((string) ($p['nama'] ?? '')) !== ''));
                if ($pengajar === []) {
                    echo $kosong();
                    break;
                }
                echo '<ul class="s-people">';
                foreach ($pengajar as $p) {
                    $foto = IsiSitus::gambar((string) ($p['foto'] ?? ''));
                    $inisial = mb_strtoupper(mb_substr(trim((string) $p['nama']), 0, 1));
                    echo '<li>' . ($foto !== null ? '<img src="' . $e($foto) . '" alt="" loading="lazy">' : '<span class="s-inisial" aria-hidden="true">' . $e($inisial) . '</span>')
                        . '<b>' . $e($p['nama']) . '</b><span>' . $e($p['bidang'] ?? '') . '</span></li>';
                }
                echo '</ul>';
                break;
        }
        ?>
    </section>
</div>
<?php include 'footer.php'; ?>
