<?php
/*
 * Masuk Portal Pendaftar (P-3) — redesain website Tahap 8.
 *
 * Tetap NISN + No. HP wali (keputusan AP). No. HP disamarkan dengan tombol
 * tampilkan. Workstream keamanan PSB tetap: formulir ber-CSRF ke
 * auth_pendaftar.php, pesan dipilih dari daftar tetap (bukan teks dari
 * alamat), dan pesan gagal tidak membedakan NISN terdaftar atau tidak.
 */
require_once __DIR__ . '/koneksi.php';

use App\Situs\Kerangka;

$pesanMasuk = [
    'gagal' => ['danger', 'NISN atau No. HP wali tidak cocok. Periksa kembali nomor yang dipakai saat mendaftar, tanpa spasi atau tanda hubung.'],
    'terkunci' => ['danger', (new App\Psb\PendaftarSesi($koneksi))->pesanTerkunci()],
    'token' => ['warn', 'Halaman masuk sudah kedaluwarsa. Silakan coba masuk lagi.'],
    'sesi' => ['warn', 'Sesi Anda berakhir atau data masuk berubah. Silakan masuk lagi.'],
    'keluar' => ['ok', 'Anda sudah keluar dari Portal Pendaftar.'],
][is_string($_GET['pesan'] ?? null) ? $_GET['pesan'] : ''] ?? null;
$e = static fn (mixed $v): string => Kerangka::e($v);

$situs = ['judul' => 'Masuk Portal Pendaftar', 'polos' => true, 'bootstrap' => false, 'robots' => 'noindex'];
include 'header.php'; ?>
<div class="s-polos">
    <div class="s-polos__kartu">
        <section class="s-panel" aria-labelledby="judul-masuk">
            <h1 id="judul-masuk" class="h4 fw-bold mb-1">Portal Pendaftar PSB</h1>
            <p class="s-muted">Masuk untuk melihat status, melengkapi berkas, dan mencetak bukti pendaftaran.</p>
            <?php if ($pesanMasuk !== null) { ?>
                <div class="ah-note ah-note--<?= $e($pesanMasuk[0]) ?>" role="<?= $pesanMasuk[0] === 'ok' ? 'status' : 'alert' ?>"><div><span><?= $e($pesanMasuk[1]) ?></span></div></div>
            <?php } ?>
            <form class="s-form" action="auth_pendaftar.php" method="POST">
                <?php echo App\Http\Csrf::input(); ?>
                <div class="s-field">
                    <label class="s-label" for="nisn">NISN calon santri <em>*</em></label>
                    <input class="s-input" id="nisn" name="nisn" type="text" inputmode="numeric" pattern="[0-9]{1,20}" maxlength="20" required autocomplete="username" aria-describedby="bantu-nisn">
                    <small class="s-hint" id="bantu-nisn">10 angka, seperti saat mendaftar.</small>
                </div>
                <div class="s-field">
                    <label class="s-label" for="hp">No. HP wali <em>*</em></label>
                    <div class="s-reveal">
                        <input class="s-input" id="hp" name="hp" type="password" inputmode="numeric" maxlength="15" required autocomplete="current-password" aria-describedby="bantu-hp">
                        <button class="s-btn s-btn--sm s-btn--teks" type="button" data-s-reveal="hp" aria-pressed="false" aria-controls="hp">Tampilkan</button>
                    </div>
                    <small class="s-hint" id="bantu-hp">Nomor HP/WhatsApp wali yang diisi di formulir pendaftaran.</small>
                </div>
                <button class="s-btn s-btn--pri s-btn--blk" type="submit" data-s-memproses="Memeriksa…">Masuk</button>
            </form>
            <p class="s-hint mt-3 mb-1">Lupa nomor HP yang didaftarkan? Hubungi panitia PSB.</p>
            <p class="mb-0">Belum mendaftar? <a href="<?= $e(app_url('/psb.php')) ?>"><b>Daftar sekarang</b></a></p>
        </section>
    </div>
</div>
<?php include 'footer.php'; ?>
