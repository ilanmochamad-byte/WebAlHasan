<?php
use App\Http\Csrf;
use App\Psb\PendaftarSesi;

require_once __DIR__ . '/koneksi.php';

/*
 * Keluar dari Portal Pendaftar. Workstream keamanan PSB: keluar hanya lewat
 * POST ber-CSRF (tombol di portal). GET ke alamat lama menampilkan tombol
 * konfirmasi, tanpa mengakhiri sesi. Hanya kunci sesi pendaftar yang dihapus.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        http_response_code(419);
    } else {
        (new PendaftarSesi($koneksi))->keluar();
        header('Location: login_pendaftar.php?pesan=keluar');
        exit;
    }
}

$situs = ['judul' => 'Keluar Portal Pendaftar', 'polos' => true, 'bootstrap' => false, 'robots' => 'noindex'];
include 'header.php'; ?>
<div class="s-polos">
    <div class="s-polos__kartu">
        <section class="s-panel" aria-labelledby="judul-keluar">
            <h1 id="judul-keluar" class="h4 fw-bold">Keluar Portal Pendaftar?</h1>
            <p class="s-muted">Masuk lagi dengan NISN dan No. HP wali untuk membuka portal.</p>
            <?php if (http_response_code() === 419) { ?>
                <div class="ah-note ah-note--warn" role="alert"><div><span>Halaman sudah kedaluwarsa. Tekan tombol di bawah sekali lagi.</span></div></div>
            <?php } ?>
            <form method="POST" class="s-acts">
                <?php echo Csrf::input(); ?>
                <button type="submit" class="s-btn s-btn--bahaya">Keluar Portal</button>
                <a href="portal_pendaftar.php" class="s-btn s-btn--teks">Kembali ke portal</a>
            </form>
        </section>
    </div>
</div>
<?php include 'footer.php'; ?>
