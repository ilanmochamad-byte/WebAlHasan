<?php

declare(strict_types=1);

namespace App\Situs;

use App\Http\Csrf;
use App\Ui\Layout;

/**
 * Kerangka situs publik dan Portal Pendaftar (redesain website Tahap 8,
 * layar U-1 s.d. U-10 dan P-1 s.d. P-7).
 *
 * Satu navbar tanpa tautan "#", tautan "Masuk" di navbar (keputusan AM),
 * kaki empat kolom dengan kontak resmi dari `isi-situs.php`, dan kerangka
 * portal pendaftar (sidebar di tablet/desktop, bilah bawah di HP).
 *
 * Kelas ini murni presentasi. Hak akses tetap diputuskan halaman pemanggil
 * (`PendaftarSesi`, `PsbAkses`) sebelum kerangka dibuka.
 */
final class Kerangka
{
    private static bool $terbuka = false;

    /** @var array<string, mixed> */
    private static array $opsi = [];

    /** Bagian Profil (U-2): kunci => label. */
    public const PROFIL = [
        'sejarah' => 'Sejarah',
        'visi-misi' => 'Visi & misi',
        'struktur-yayasan' => 'Struktur yayasan',
        'pengurus-pesantren' => 'Pengurus pesantren',
        'pengurus-santri' => 'Pengurus santri',
        'dewan-pengajar' => 'Dewan pengajar',
    ];

    public static function e(mixed $nilai): string
    {
        return htmlspecialchars((string) $nilai, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array{
     *     judul?:?string,
     *     deskripsi?:string,
     *     aktif?:string,
     *     polos?:bool,
     *     tanpa_kaki?:bool,
     *     cta_tempel?:bool,
     *     bootstrap?:bool,
     *     robots?:string,
     *     og?:array{judul?:string, deskripsi?:string, gambar?:string},
     *     kelas?:string,
     *     portal?:bool,
     *     skrip?:array<int, string>
     * } $opsi
     */
    public static function buka(array $opsi = []): void
    {
        if (self::$terbuka) {
            return;
        }
        self::$terbuka = true;
        self::$opsi = $opsi;
        $e = static fn (mixed $v): string => self::e($v);
        $nama = IsiSitus::teks('nama') ?: 'Pesantren Al Hasan';
        $judul = trim((string) ($opsi['judul'] ?? ''));
        $judulPenuh = $judul === '' ? $nama : $judul . ' — ' . $nama;
        $deskripsi = (string) ($opsi['deskripsi'] ?? '');
        $og = $opsi['og'] ?? [];
        ?>
<!DOCTYPE html>
<html lang="id" class="s-nojs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($judulPenuh) ?></title>
    <?php if ($deskripsi !== ''): ?><meta name="description" content="<?= $e($deskripsi) ?>"><?php endif; ?>
    <?php if (!empty($opsi['robots'])): ?><meta name="robots" content="<?= $e($opsi['robots']) ?>"><?php endif; ?>
    <?php if ($og !== []): ?>
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?= $e($og['judul'] ?? $judulPenuh) ?>">
    <?php if (!empty($og['deskripsi'])): ?><meta property="og:description" content="<?= $e($og['deskripsi']) ?>"><?php endif; ?>
    <?php if (!empty($og['gambar'])): ?><meta property="og:image" content="<?= $e($og['gambar']) ?>"><?php endif; ?>
    <?php endif; ?>
    <script src="<?= $e(Layout::asset('/assets/ui/situs-awal.js')) ?>"></script>
    <?php if (($opsi['bootstrap'] ?? true) === true): ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= $e(Layout::asset('/assets/ui/alhasan.css')) ?>">
    <link rel="stylesheet" href="<?= $e(Layout::asset('/assets/ui/situs.css')) ?>">
</head>
<body class="ah ah-situs<?= isset($opsi['kelas']) ? ' ' . $e($opsi['kelas']) : '' ?>">
<a class="s-skip" href="#s-konten">Lompat ke konten utama</a>
<?php
        if (!empty($opsi['portal'])) {
            // Bilah atas portal pendaftar dicetak oleh bukaPortal().
        } elseif (!empty($opsi['polos'])) {
            self::navbarPolos();
        } else {
            self::navbar((string) ($opsi['aktif'] ?? ''));
        }
        echo '<main class="s-main" id="s-konten">';
    }

    public static function tutup(): void
    {
        if (!self::$terbuka) {
            return;
        }
        self::$terbuka = false;
        $opsi = self::$opsi;
        echo '</main>';
        if (!empty($opsi['cta_tempel']) && IsiSitus::psbDibuka()) {
            echo '<div class="s-sticky-cta"><a class="s-btn s-btn--gold s-btn--blk" href="' . self::e(app_url('/psb.php')) . '">Daftar PSB '
                . self::e(IsiSitus::tahunPsb()) . '</a></div>';
        }
        if (empty($opsi['tanpa_kaki']) && empty($opsi['polos'])) {
            self::kaki();
        }
        echo '<script src="' . self::e(Layout::asset('/assets/ui/situs.js')) . '"></script>';
        foreach ($opsi['skrip'] ?? [] as $skrip) {
            echo '<script src="' . self::e(Layout::asset((string) $skrip)) . '"></script>';
        }
        echo "\n</body>\n</html>\n";
    }

    /** Tanggal berbahasa Indonesia: "12 Okt 2026" atau "Kamis, 12 Oktober 2026". */
    public static function tanggal(?string $tanggal, bool $panjang = false): string
    {
        $waktu = $tanggal === null || $tanggal === '' ? false : strtotime($tanggal);
        if ($waktu === false) {
            return '';
        }
        if (!$panjang) {
            return Layout::date($waktu);
        }
        $bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        return Layout::dayName($waktu) . ', ' . (int) date('j', $waktu) . ' ' . $bulan[(int) date('n', $waktu) - 1] . ' ' . date('Y', $waktu);
    }

    /** Cuplikan teks polos dari isi HTML berita. */
    public static function cuplikan(string $html, int $panjang = 140): string
    {
        $teks = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br', '</p>'], [' <br', ' </p>'], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_strlen($teks) > $panjang ? rtrim(mb_substr($teks, 0, $panjang)) . '…' : $teks;
    }

    /** Alamat gambar unggahan konten (berita/galeri) dari nama di basis data, atau null. */
    public static function gambarKonten(string $folder, ?string $nama): ?string
    {
        $nama = basename((string) $nama);
        if ($nama === '' || $nama === '.' || preg_match('/\.(?:png|jpe?g|gif|webp)$/i', $nama) !== 1) {
            return null;
        }

        return app_url('/' . $folder . '/' . rawurlencode($nama));
    }

    /** Logo resmi bila ada; selain itu monogram teks (tanpa gambar contoh). */
    public static function merek(string $sub = 'Ciamis'): string
    {
        $logo = IsiSitus::gambar(IsiSitus::teks('logo'));
        $nama = IsiSitus::teks('nama') ?: 'Pesantren Al Hasan';

        return '<a class="s-brand" href="' . self::e(app_url('/index.php')) . '">'
            . ($logo !== null
                ? '<img class="s-brand__logo" src="' . self::e($logo) . '" alt="" width="40" height="40">'
                : '<span class="s-brand__mark" aria-hidden="true">AH</span>')
            . '<span class="s-brand__teks"><b>' . self::e(preg_replace('/\s+Ciamis$/', '', $nama)) . '</b><small>' . self::e($sub) . '</small></span></a>';
    }

    private static function navbar(string $aktif): void
    {
        $e = static fn (mixed $v): string => self::e($v);
        $u = static fn (string $p): string => self::e(app_url($p));
        $psb = IsiSitus::psbDibuka();
        $cur = static fn (string $k): string => $k === $aktif ? ' aria-current="page"' : '';
        $unit = IsiSitus::unit();
        ?>
<header class="s-top">
    <div class="s-wrap s-top__in">
        <?= self::merek() ?>
        <?php if ($psb): ?>
            <a class="s-btn s-btn--gold s-btn--sm s-top__tablet-cta" href="<?= $u('/psb.php') ?>">Daftar PSB</a>
        <?php endif; ?>
        <button class="s-burger" type="button" data-s-burger aria-controls="s-nav" aria-expanded="false">
            <span></span><span></span><span></span><b class="s-vh">Buka menu</b>
        </button>
        <nav class="s-nav" id="s-nav" aria-label="Menu utama">
            <ul class="s-nav__list">
                <li><a class="s-nav__link" href="<?= $u('/index.php') ?>"<?= $cur('beranda') ?>>Beranda</a></li>
                <li>
                    <details class="s-drop<?= $aktif === 'profil' ? ' is-aktif' : '' ?>">
                        <summary>Profil</summary>
                        <ul class="s-drop__menu">
                            <?php foreach (self::PROFIL as $kunci => $label): ?>
                                <li><a href="<?= $u('/profil.php?bagian=' . $kunci) ?>"><?= $e($label) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                </li>
                <li>
                    <details class="s-drop<?= $aktif === 'pendidikan' ? ' is-aktif' : '' ?>">
                        <summary>Pendidikan</summary>
                        <ul class="s-drop__menu">
                            <li><a href="<?= $u('/pendidikan.php') ?>">Semua unit pendidikan</a></li>
                            <?php foreach ($unit as $kunci => $data): ?>
                                <li><a href="<?= $u('/pendidikan.php') ?>#unit-<?= $e($kunci) ?>"><?= $e($data['nama']) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                </li>
                <li><a class="s-nav__link" href="<?= $u('/berita.php') ?>"<?= $cur('kabar') ?>>Kabar</a></li>
                <li><a class="s-nav__link" href="<?= $u('/galeri.php') ?>"<?= $cur('galeri') ?>>Galeri</a></li>
                <li><a class="s-nav__link" href="<?= $u('/jadwal_ngaji.php') ?>"<?= $cur('jadwal') ?>>Jadwal Ngaji</a></li>
                <li><a class="s-nav__link" href="<?= $u('/download.php') ?>"<?= $cur('unduhan') ?>>Unduhan</a></li>
                <li>
                    <details class="s-drop s-drop--kanan<?= $aktif === 'masuk' ? ' is-aktif' : '' ?>">
                        <summary>Masuk</summary>
                        <ul class="s-drop__menu">
                            <li><a href="<?= $u('/portal/') ?>">Masuk Sistem<small>Staf dan orang tua santri</small></a></li>
                            <li><a href="<?= $u('/login_pendaftar.php') ?>">Portal Pendaftar<small>Status PSB dan unggah berkas</small></a></li>
                        </ul>
                    </details>
                </li>
            </ul>
            <?php if ($psb): ?>
                <a class="s-btn s-btn--gold s-top__cta s-top__cta--lebar" href="<?= $u('/psb.php') ?>">Daftar PSB</a>
                <a class="s-btn s-btn--gold s-top__cta s-nav__mobile-only" href="<?= $u('/psb.php') ?>">Daftar PSB <?= $e(IsiSitus::tahunPsb()) ?></a>
            <?php endif; ?>
        </nav>
    </div>
</header>
        <?php
    }

    /** Kerangka polos: merek dan tautan kembali (P-3). */
    private static function navbarPolos(): void
    {
        echo '<header class="s-top"><div class="s-wrap s-top__in">' . self::merek()
            . '<a class="s-btn s-btn--teks" style="margin-left:auto" href="' . self::e(app_url('/index.php')) . '">Ke situs</a></div></header>';
    }

    private static function kaki(): void
    {
        $e = static fn (mixed $v): string => self::e($v);
        $u = static fn (string $p): string => self::e(app_url($p));
        $nama = IsiSitus::teks('nama') ?: 'Pesantren Al Hasan';
        $wa = IsiSitus::whatsapp();
        $jam = array_filter(IsiSitus::daftar('jam_layanan'), static fn ($v, $k): bool => is_string($k) && is_scalar($v) && trim((string) $v) !== '', ARRAY_FILTER_USE_BOTH);
        ?>
<footer class="s-foot">
    <div class="s-wrap s-foot__grid">
        <section aria-labelledby="kaki-tentang">
            <h2 id="kaki-tentang"><?= $e($nama) ?></h2>
            <?php if (IsiSitus::ada('semboyan_kaki')): ?><p><?= $e(IsiSitus::teks('semboyan_kaki')) ?></p><?php endif; ?>
            <p><a href="<?= $u('/profil.php') ?>">Profil pesantren</a></p>
        </section>
        <section aria-labelledby="kaki-tautan">
            <h2 id="kaki-tautan">Tautan cepat</h2>
            <ul>
                <li><a href="<?= $u('/psb.php') ?>">Pendaftaran santri baru</a></li>
                <li><a href="<?= $u('/login_pendaftar.php') ?>">Portal Pendaftar</a></li>
                <li><a href="<?= $u('/jadwal_ngaji.php') ?>">Jadwal ngaji</a></li>
                <li><a href="<?= $u('/verifikasi_rapor.php') ?>">Cek keaslian rapor</a></li>
                <li><a href="<?= $u('/portal/') ?>">Masuk Sistem</a></li>
            </ul>
        </section>
        <section aria-labelledby="kaki-kontak">
            <h2 id="kaki-kontak">Kontak resmi</h2>
            <?php if (IsiSitus::ada('alamat')): ?><p><?= $e(IsiSitus::teks('alamat')) ?></p><?php endif; ?>
            <ul>
                <?php if (IsiSitus::ada('email')): ?><li>Surel: <a href="mailto:<?= $e(IsiSitus::teks('email')) ?>"><?= $e(IsiSitus::teks('email')) ?></a></li><?php endif; ?>
                <?php if (IsiSitus::ada('telepon')): ?><li>Telepon: <?= $e(IsiSitus::teks('telepon')) ?></li><?php endif; ?>
                <?php if ($wa !== ''): ?><li>WhatsApp: <a href="https://wa.me/<?= $e($wa) ?>" rel="noopener" target="_blank">+<?= $e($wa) ?></a></li><?php endif; ?>
            </ul>
        </section>
        <?php if ($jam !== []): ?>
        <section aria-labelledby="kaki-jam">
            <h2 id="kaki-jam">Jam layanan</h2>
            <ul>
                <?php foreach ($jam as $hari => $waktu): ?><li><?= $e($hari) ?>: <?= $e($waktu) ?></li><?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </div>
    <div class="s-wrap s-foot__bawah">
        <span>© <?= date('Y') ?> <?= $e(IsiSitus::teks('nama_resmi') ?: $nama) ?></span>
        <span><a href="<?= $u('/privacy') ?>">Kebijakan privasi</a> · <a href="<?= $u('/verifikasi_rapor.php') ?>">Cek keaslian rapor</a></span>
    </div>
</footer>
        <?php
    }

    /**
     * Kerangka Portal Pendaftar (P-4 s.d. P-7): bilah atas dengan nama dan
     * nomor pendaftaran, tombol Keluar (POST ber-CSRF), menu samping di
     * tablet/desktop dan bilah bawah di HP.
     *
     * @param array<string, mixed> $pendaftar baris `psb_pendaftar` milik sesi
     * @param string $aktif status|data|berkas|biaya
     * @param bool $tab bagian portal ditampilkan sebagai tab oleh JavaScript
     */
    public static function bukaPortal(array $pendaftar, string $aktif, string $judul, bool $tab = false): void
    {
        self::buka(['judul' => $judul . ' · Portal Pendaftar', 'portal' => true, 'tanpa_kaki' => true, 'robots' => 'noindex, nofollow', 'bootstrap' => false]);
        $e = static fn (mixed $v): string => self::e($v);
        $noreg = (string) $pendaftar['no_pendaftaran'];
        $menu = [
            'status' => ['Status', app_url('/portal_pendaftar.php') . '#status'],
            'data' => ['Data diri', app_url('/portal_pendaftar.php') . '#data'],
            'berkas' => ['Berkas', app_url('/portal_pendaftar.php') . '#berkas'],
            'biaya' => ['Pembiayaan', app_url('/biaya_psb.php')],
        ];
        $cur = static fn (string $k): string => $k === $aktif ? ' aria-current="page"' : '';
        echo '<div class="s-ptop"><div class="s-wrap s-ptop__in"><span class="s-brand__teks"><b>Portal Pendaftar</b><small>PSB ' . $e(IsiSitus::tahunPsb()) . '</small></span>'
            . '<span class="s-ptop__akun"><b>' . $e($pendaftar['nama_lengkap']) . '</b><small class="s-mono">' . $e($noreg) . '</small></span>'
            . '<form action="' . $e(app_url('/logout_pendaftar.php')) . '" method="POST"'
            . ' data-s-konfirmasi-judul="Keluar dari Portal Pendaftar?" data-s-konfirmasi-teks="Masuk lagi dengan NISN dan No. HP wali untuk membuka portal." data-s-konfirmasi-ok="Ya, keluar">'
            . Csrf::input()
            . '<button class="s-btn s-btn--sm" type="submit" data-s-tanpa-kunci>Keluar Portal</button></form></div></div>';
        echo '<div class="s-wrap s-portal' . ($tab ? ' s-portal--tab' : '') . '"><nav class="s-portal__side" aria-label="Menu portal"><ul>';
        foreach ($menu as $kunci => [$label, $url]) {
            echo '<li><a href="' . $e($url) . '"' . $cur($kunci) . ($kunci !== 'biaya' ? ' data-s-bagian="' . $kunci . '"' : '') . '>' . $e($label) . '</a></li>';
        }
        echo '<li><a href="' . $e(app_url('/cetak_bukti.php?noreg=' . rawurlencode($noreg))) . '" target="_blank" rel="noopener">Bukti pendaftaran ↗</a></li>';
        echo '</ul></nav><div class="s-portal__main">';
        self::$opsi['portal_menu'] = $menu;
        self::$opsi['portal_aktif'] = $aktif;
    }

    public static function tutupPortal(): void
    {
        $menu = self::$opsi['portal_menu'] ?? [];
        $aktif = (string) (self::$opsi['portal_aktif'] ?? '');
        echo '</div></div><nav class="s-bottombar" aria-label="Menu portal">';
        foreach ($menu as $kunci => [$label, $url]) {
            echo '<a href="' . self::e($url) . '"' . ($kunci === $aktif ? ' aria-current="page"' : '') . ($kunci !== 'biaya' ? ' data-s-bagian="' . $kunci . '"' : '') . '>' . self::e($label) . '</a>';
        }
        echo '</nav>';
        self::tutup();
    }
}
