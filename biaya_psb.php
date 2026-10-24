<?php
/*
 * Pembiayaan daftar ulang PSB (P-7) — redesain website Tahap 8.
 *
 * Keputusan Human Developer 24 Oktober 2026 (butir 3): halaman tetap di
 * alamat ini (sudah dilindungi workstream keamanan PSB), tampilannya
 * didesain ulang, dan dibuka dari menu "Pembiayaan" Portal Pendaftar.
 * Pendaftar yang masuk melihatnya di dalam kerangka portal; admin memakai
 * kotak cari di kerangka situs; selain itu hanya ajakan masuk.
 *
 * Aturan biaya tetap (AJ): pendaftar mencentang komponen wajib yang dibayar
 * dan mengisi nominal pilihan; kategori, harga, dan total dihitung ulang di
 * server dari katalog `App\Psb\BiayaPsb`. Simpan lewat POST ber-CSRF, lalu
 * kwitansi dibuka di tab yang sama (303), tanpa target _blank.
 */
use App\Http\Csrf;
use App\Psb\BiayaPsb;
use App\Psb\PendaftarSesi;
use App\Psb\PsbAkses;
use App\Psb\PsbException;
use App\Situs\Kerangka;

require_once __DIR__ . '/koneksi.php';

$akses = new PsbAkses($koneksi, new PendaftarSesi($koneksi));
$pesanBiaya = null;

// --- SIMPAN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_cetak'])) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $pesanBiaya = 'Halaman sudah kedaluwarsa. Rincian belum tersimpan; muat ulang halaman lalu simpan lagi.';
    } else {
        $target = $akses->cari(is_string($_POST['no_pendaftaran'] ?? null) ? $_POST['no_pendaftaran'] : '');
        if ($target === null || !$akses->boleh($target)) {
            PsbAkses::tolak(403);
        }
        try {
            BiayaPsb::simpan($koneksi, (string) $target['no_pendaftaran'], BiayaPsb::dariKiriman($target, $_POST));
            header('Location: cetak_biaya_psb.php?noreg=' . rawurlencode((string) $target['no_pendaftaran']), true, 303);
            exit;
        } catch (PsbException $exception) {
            $pesanBiaya = $exception->getMessage();
        }
    }
}

// --- DATA YANG BOLEH DITAMPILKAN ---
$cari = is_string($_GET['cari'] ?? null) ? trim($_GET['cari']) : null;
$pendaftar = null;
$tidakDitemukan = false;
$bukanMilik = false;
$pendaftarMasuk = $akses->pendaftar();
$admin = $akses->admin();
if ($admin) {
    if ($cari !== null && $cari !== '') {
        $pendaftar = $akses->cari($cari, true);
        $tidakDitemukan = $pendaftar === null;
    }
} elseif ($pendaftarMasuk !== null) {
    // Pendaftar hanya melihat rincian miliknya sendiri.
    if ($cari === null || $cari === '' || $cari === (string) $pendaftarMasuk['no_pendaftaran'] || $cari === (string) $pendaftarMasuk['nik']) {
        $pendaftar = $pendaftarMasuk;
    } else {
        $bukanMilik = true;
    }
}

$e = static fn (mixed $v): string => Kerangka::e($v);
$rp = static fn (int|string $n): string => 'Rp ' . number_format((int) $n, 0, ',', '.');

$katalog = null;
$tersimpan = null;
if ($pendaftar !== null) {
    $katalog = BiayaPsb::katalog($pendaftar);
    $stmt = $koneksi->prepare('SELECT * FROM psb_pembayaran WHERE no_pendaftaran = ?');
    $no = (string) $pendaftar['no_pendaftaran'];
    $stmt->bind_param('s', $no);
    $stmt->execute();
    $tersimpan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$catatan = static function () use ($pesanBiaya, $bukanMilik, $tidakDitemukan, $e): void {
    if ($pesanBiaya !== null) {
        echo '<div class="ah-note ah-note--danger" role="alert"><div><strong class="ah-note__title">Belum tersimpan</strong><span>' . $e($pesanBiaya) . '</span></div></div>';
    }
    if ($bukanMilik) {
        echo '<div class="ah-note ah-note--warn" role="alert"><div><span>Anda hanya dapat membuka rincian biaya pendaftaran Anda sendiri.</span></div></div>';
    }
    if ($tidakDitemukan) {
        echo '<div class="ah-note ah-note--danger" role="alert"><div><span>Data pendaftar tidak ditemukan. Periksa kembali nomor pendaftaran atau NIK.</span></div></div>';
    }
};

/** Formulir rincian biaya untuk satu pendaftar. */
$formulir = static function (array $d, array $katalog, ?array $simpan) use ($e, $rp): void {
    $lunas = $simpan !== null && $simpan['status_pembayaran'] === 'Lunas';
    $s = (int) ($simpan['syahriyyah'] ?? $katalog['syahriyyah']);
    $i = (int) ($simpan['infaq'] ?? $katalog['infaq']);
    $psas = (int) ($simpan['seragam_psas'] ?? $katalog['psas']);
    $pramuka = (int) ($simpan['seragam_pramuka'] ?? $katalog['pramuka']);
    $metode = (string) ($simpan['metode_pembayaran'] ?? 'Cash');
    $wajibTersimpan = $simpan === null ? null : (json_decode((string) $simpan['rincian_wajib'], true) ?: []);
    $nominal = static function (string $nama, string $label, int $nilai, string $id, bool $kunci) use ($e): string {
        return '<div class="s-field"><label class="s-label" for="' . $id . '">' . $e($label) . '</label><div class="s-rupiah"><span aria-hidden="true">Rp</span>'
            . '<input class="s-input" id="' . $id . '" name="' . $e($nama) . '" type="text" inputmode="numeric" pattern="[0-9]{1,9}" maxlength="9" required value="' . $nilai . '" data-s-nominal' . ($kunci ? ' readonly' : '') . '></div></div>';
    };
    ?>
    <dl class="s-dl s-panel mb-3">
        <dt>Nama</dt><dd><?= $e($d['nama_lengkap']) ?></dd>
        <dt>No. pendaftaran</dt><dd class="s-mono"><?= $e($d['no_pendaftaran']) ?></dd>
        <dt>Jenjang</dt><dd><?= $e($d['jenjang_tujuan']) ?> · <?= $d['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan' ?></dd>
        <dt>Asal sekolah</dt><dd><?= $e($d['sekolah_asal']) ?></dd>
        <dt>Kategori biaya</dt><dd><?= $e($katalog['kategori']) ?></dd>
        <?php if ($simpan !== null): ?><dt>Pembayaran</dt><dd><span class="ah-badge ah-badge--<?= $lunas ? 'ok' : 'warn' ?>"><?= $lunas ? 'Lunas' : 'Belum lunas' ?></span></dd><?php endif; ?>
    </dl>
    <?php if ($lunas): ?>
        <div class="ah-note ah-note--ok" role="status"><div><strong class="ah-note__title">Sudah lunas</strong><span>Pembayaran sudah ditandai lunas oleh bendahara. Rincian tidak dapat diubah lagi; hubungi panitia PSB bila perlu koreksi.</span></div></div>
    <?php endif; ?>
    <form method="POST" data-s-biaya>
        <?php echo Csrf::input(); ?>
        <input type="hidden" name="no_pendaftaran" value="<?= $e($d['no_pendaftaran']) ?>">
        <div class="s-split" style="grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr))">
            <div>
                <section class="s-panel" aria-labelledby="judul-wajib">
                    <div class="s-panel__head"><h2 id="judul-wajib">Biaya wajib</h2><small class="s-muted">Centang yang dibayar sekarang</small></div>
                    <table class="s-biaya">
                        <tbody>
                        <?php foreach ($katalog['wajib'] as $item => $harga): $cek = $wajibTersimpan === null || isset($wajibTersimpan[$item]); $idCek = 'w-' . substr(md5($item), 0, 10); ?>
                            <tr>
                                <td><label for="<?= $idCek ?>"><input type="checkbox" id="<?= $idCek ?>" name="wajib[]" value="<?= $e($item) ?>" data-harga="<?= (int) $harga ?>"<?= $cek ? ' checked' : '' ?><?= $lunas ? ' disabled' : '' ?>> <?= $e($item) ?></label></td>
                                <td><?= $rp($harga) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr><td>Total wajib dipilih</td><td id="txtTotalWajib"><?= $rp(array_sum(array_intersect_key($katalog['wajib'], $wajibTersimpan ?? $katalog['wajib']))) ?></td></tr></tfoot>
                    </table>
                </section>
                <section class="s-panel" aria-labelledby="judul-pilihan">
                    <div class="s-panel__head"><h2 id="judul-pilihan">Biaya pilihan</h2><small class="s-muted">Boleh diatur atau diangsur; isi 0 bila ditangguhkan</small></div>
                    <div class="s-form s-form--2">
                        <?= $nominal('syahriyyah', 'Syahriyyah (bulanan)', $s, 'inpSyahriyyah', $lunas) ?>
                        <?= $nominal('infaq', 'Infaq bangunan', $i, 'inpInfaq', $lunas) ?>
                        <?php if ($katalog['seragam']): ?>
                            <?= $nominal('seragam_psas', 'Seragam PSAS', $psas, 'inpPSAS', $lunas) ?>
                            <?= $nominal('seragam_pramuka', 'Seragam Pramuka', $pramuka, 'inpPramuka', $lunas) ?>
                        <?php else: ?>
                            <input type="hidden" name="seragam_psas" value="0"><input type="hidden" name="seragam_pramuka" value="0">
                        <?php endif; ?>
                    </div>
                </section>
            </div>
            <div>
                <section class="s-panel" aria-labelledby="judul-cara">
                    <h2 id="judul-cara" class="h6 fw-bold">Cara bayar</h2>
                    <div class="s-radios mb-3" role="radiogroup" aria-labelledby="judul-cara">
                        <label><input type="radio" name="metode_pembayaran" value="Cash"<?= $metode !== 'Transfer' ? ' checked' : '' ?><?= $lunas ? ' disabled' : '' ?>> Tunai di kantor</label>
                        <label><input type="radio" name="metode_pembayaran" value="Transfer"<?= $metode === 'Transfer' ? ' checked' : '' ?><?= $lunas ? ' disabled' : '' ?>> Transfer bank</label>
                    </div>
                    <div id="infoTransfer"<?= $metode === 'Transfer' ? '' : ' hidden' ?>>
                        <ul class="s-rek">
                            <li><span><b>BSI</b> <span class="s-mono">1046-322-987</span><small>a.n. Yayasan Pendidikan Ponpes Al Hasan</small></span><button class="s-btn s-btn--sm s-js-only" type="button" data-s-salin="1046322987" data-s-salin-pesan="Nomor rekening BSI disalin.">Salin<span class="s-vh"> nomor rekening BSI</span></button></li>
                            <li><span><b>BRI</b> <span class="s-mono">0104-01001-218567</span><small>a.n. Yayasan Pendidikan Pondok Pesantren Al Hasan</small></span><button class="s-btn s-btn--sm s-js-only" type="button" data-s-salin="010401001218567" data-s-salin-pesan="Nomor rekening BRI disalin.">Salin<span class="s-vh"> nomor rekening BRI</span></button></li>
                        </ul>
                        <p class="s-hint mt-2 mb-0">Bawa bukti transfer dan kwitansi ke ruang bendahara untuk divalidasi.</p>
                    </div>
                    <p class="s-hint mb-0" id="infoTunai"<?= $metode === 'Transfer' ? ' hidden' : '' ?>>Bayar di ruang bendahara pesantren dengan membawa kwitansi.</p>
                </section>
                <div class="s-total s-total--tempel mt-3">
                    <span>Total dibayar<br><b data-s-total id="txtTotalBayar"><?= $rp($s + $i + ($katalog['seragam'] ? $psas + $pramuka : 0) + array_sum(array_intersect_key($katalog['wajib'], $wajibTersimpan ?? $katalog['wajib']))) ?></b></span>
                    <?php if ($lunas): ?>
                        <a class="s-btn s-btn--pri" href="cetak_biaya_psb.php?noreg=<?= $e(rawurlencode((string) $d['no_pendaftaran'])) ?>">Cetak kwitansi</a>
                    <?php else: ?>
                        <button class="s-btn s-btn--pri" type="submit" name="simpan_cetak" value="1" data-s-memproses="Menyimpan…">Simpan &amp; cetak kwitansi</button>
                    <?php endif; ?>
                </div>
                <p class="s-hint mt-2">Total dihitung ulang oleh server dari komponen yang dipilih.</p>
            </div>
        </div>
    </form>
    <?php
};

if ($pendaftarMasuk !== null && !$admin) {
    // Pendaftar: di dalam kerangka Portal Pendaftar (menu Pembiayaan).
    Kerangka::bukaPortal($pendaftarMasuk, 'biaya', 'Pembiayaan');
    echo '<section class="s-portal-sec" aria-labelledby="judul-biaya"><h1 id="judul-biaya">Pembiayaan daftar ulang</h1>';
    echo '<p class="s-sub">Kategori: ' . ($katalog !== null ? $e($katalog['kategori']) : '—') . '</p>';
    $catatan();
    if ($pendaftar !== null) {
        $formulir($pendaftar, $katalog, $tersimpan);
    }
    echo '</section>';
    Kerangka::tutupPortal();
    exit;
}

$situs = ['judul' => 'Rincian pembiayaan PSB', 'robots' => 'noindex, nofollow'];
include 'header.php';
?>
<div class="s-wrap py-4">
    <header class="s-head" style="padding-top:0">
        <h1>Rincian pembiayaan santri baru</h1>
        <p><?= $admin ? 'Cari pendaftar dengan nomor pendaftaran atau NIK untuk melihat dan mengatur rinciannya.' : 'Rincian pembiayaan dibuka dari Portal Pendaftar.' ?></p>
    </header>
    <?php $catatan(); ?>
    <?php if ($admin): ?>
        <form class="s-cari s-panel" method="GET" role="search">
            <label class="s-vh" for="cari-biaya">Nomor pendaftaran atau NIK</label>
            <input class="s-input" id="cari-biaya" type="text" name="cari" placeholder="No. pendaftaran / NIK" value="<?= $e($cari ?? '') ?>" required>
            <button class="s-btn s-btn--pri" type="submit" data-s-tanpa-kunci>Cari</button>
        </form>
        <?php if ($pendaftar !== null) { $formulir($pendaftar, $katalog, $tersimpan); } ?>
    <?php else: ?>
        <div class="s-kosong">
            <b>Masuk lebih dahulu</b>
            Rincian pembiayaan hanya dapat dibuka oleh pendaftar setelah masuk Portal Pendaftar, atau oleh panitia.
            <div class="s-acts"><a class="s-btn s-btn--pri" href="login_pendaftar.php">Masuk Portal Pendaftar</a></div>
        </div>
    <?php endif; ?>
</div>
<?php include 'footer.php'; ?>
