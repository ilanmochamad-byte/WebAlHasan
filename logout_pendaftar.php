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

include 'header.php'; ?>
<div class="container py-5 mt-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow-lg border-0 rounded-4">
                <div class="card-body p-5 text-center">
                    <h3 class="fw-bold">Keluar Portal Pendaftar?</h3>
                    <?php if (http_response_code() === 419) { ?>
                        <div class="alert alert-warning small">Halaman sudah kedaluwarsa. Tekan tombol di bawah sekali lagi.</div>
                    <?php } ?>
                    <form method="POST" class="mt-4">
                        <?php echo Csrf::input(); ?>
                        <button type="submit" class="btn btn-outline-danger w-100 fw-bold rounded-pill py-2">Keluar Portal</button>
                    </form>
                    <a href="portal_pendaftar.php" class="btn btn-link mt-2">Kembali ke portal</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
