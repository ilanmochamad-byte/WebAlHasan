<?php
declare(strict_types=1);
/*
 * Cek keaslian rapor (U-10) — redesain website Tahap 8.
 *
 * Masuk ke kerangka situs (navbar dan kaki). Pemeriksaan tetap lewat
 * `App\V4\VerifikasiPublik` dengan CSRF, pembatasan, dan header keamanan yang
 * sama; tidak menampilkan data santri. CSP tetap ketat: hanya ditambah
 * stylesheet dan gambar dari situs sendiri (`'self'`) untuk kerangka, tanpa
 * CDN, tanpa skrip sebaris.
 */
require_once __DIR__ . '/app/bootstrap.php';

use App\Http\Csrf;
use App\Situs\Kerangka;
use App\V4\{VerifikasiPublik, V4Exception};

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$result = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null);
    try {
        $upload = isset($_FILES['pdf']) && ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $_FILES['pdf'] : null;
        $result = (new VerifikasiPublik(app_db()))->check((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $_POST['kode'] ?? null, $upload);
    } catch (V4Exception $e) {
        http_response_code($e->status);
        if ($e->status === 429) {
            header('Retry-After: 60');
        }
        $error = $e->getMessage();
    } catch (Throwable) {
        http_response_code(503);
        $error = 'Pemeriksaan belum tersedia. Coba lagi nanti.';
    }
}
$status = $result === null ? '' : (string) $result['status'];
$badge = match ($status) {
    'Terbit', 'Diterbitkan' => 'ok',
    'Dicabut', 'Ditarik', 'Dibatalkan' => 'danger',
    'Diganti', 'Sudah diganti' => 'warn',
    default => 'info',
};
Kerangka::buka([
    'judul' => 'Cek keaslian rapor',
    'deskripsi' => 'Periksa status penerbitan dan kecocokan berkas rapor Pesantren Al Hasan.',
    'bootstrap' => false,
    'robots' => 'noindex',
    'skrip' => ['/assets/ui/verifikasi-rapor.js'],
]);
$e = static fn (mixed $v): string => Kerangka::e($v);
?>
<div class="s-wrap">
    <div class="s-read" style="max-width:560px;margin:0 auto;padding:1.5rem 0 2rem">
        <header class="s-head" style="padding-top:0">
            <ol class="s-crumbs"><li><a href="<?= $e(app_url('/index.php')) ?>">Beranda</a></li><li aria-current="page">Cek keaslian rapor</li></ol>
            <h1>Cek keaslian rapor</h1>
            <p>Masukkan kode verifikasi yang tercetak di rapor. Identitas dan nilai santri tidak ditampilkan.</p>
        </header>
        <?php if ($error): ?>
            <div class="ah-note ah-note--danger" role="alert"><div><strong class="ah-note__title">Belum dapat diperiksa</strong><span><?= $e($error) ?></span></div></div>
        <?php endif; ?>
        <form class="s-panel s-form" method="post" enctype="multipart/form-data">
            <?= ah_csrf() ?>
            <div class="s-field">
                <label class="s-label" for="kode">Kode verifikasi <em>*</em></label>
                <input class="s-input s-mono" id="kode" name="kode" autocomplete="off" required pattern="[a-f0-9]{48}" maxlength="48" spellcheck="false" aria-describedby="kode-bantu">
                <small class="s-hint" id="kode-bantu">48 karakter huruf a–f dan angka, tanpa spasi.</small>
            </div>
            <div class="s-field">
                <label class="s-label" for="pdf">Berkas PDF rapor (opsional)</label>
                <input class="s-input" id="pdf" type="file" name="pdf" accept="application/pdf" aria-describedby="pdf-bantu">
                <small class="s-hint" id="pdf-bantu">Untuk mencocokkan isi berkas dengan salinan resmi · maksimal 10 MB.</small>
            </div>
            <div><button class="s-btn s-btn--pri" type="submit">Periksa</button></div>
        </form>
        <?php if ($result): ?>
            <section class="s-panel" role="status" aria-labelledby="hasil-judul" style="margin-top:1rem">
                <div class="s-panel__head"><h2 id="hasil-judul">Hasil pemeriksaan</h2><span class="ah-badge ah-badge--<?= $e($badge) ?>"><?= $e($status) ?></span></div>
                <dl class="s-dl">
                    <dt>Status</dt><dd><?= $e($status) ?></dd>
                    <dt>Versi</dt><dd><?= (int) $result['version'] ?></dd>
                    <dt>Tanggal terbit</dt><dd><?= $e(Kerangka::tanggal($result['tanggal_terbit']) ?: $result['tanggal_terbit']) ?></dd>
                    <?php if (array_key_exists('cocok', $result)): ?>
                        <dt>Berkas PDF</dt><dd><?= $result['cocok'] ? 'Cocok dengan salinan resmi.' : 'Tidak cocok dengan salinan resmi.' ?></dd>
                    <?php endif; ?>
                </dl>
            </section>
        <?php endif; ?>
    </div>
</div>
<?php Kerangka::tutup(); ?>
