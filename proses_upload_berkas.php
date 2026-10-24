<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Psb\BerkasPsb;
use App\Psb\PendaftarSesi;
use App\Psb\PsbAdmin;
use App\Psb\PsbException;

/**
 * Unggah berkas pendaftar dari Portal Pendaftar.
 *
 * Workstream keamanan PSB: hanya pendaftar yang sedang masuk (diperiksa ulang
 * per permintaan), hanya POST ber-CSRF, isi berkas diperiksa server (jenis
 * sebenarnya JPG/PNG/PDF, gambar/PDF sah, maksimal 2 MB), disimpan privat di
 * `storage/private/psb/` dengan nama acak, dan kolom diperbarui dengan query
 * terikat untuk baris milik sendiri. Berkas lama tidak dihapus.
 */

require_once __DIR__ . '/koneksi.php';

$sesiPendaftar = new PendaftarSesi($koneksi);
$pendaftar = $sesiPendaftar->wajib('login_pendaftar.php');

$selesai = static function (string $pesan): never {
    $_SESSION['_psb_portal_pesan'] = $pesan;
    header('Location: portal_pendaftar.php');
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: portal_pendaftar.php');
    exit;
}
// Kiriman melebihi post_max_size: PHP mengosongkan $_POST dan $_FILES.
if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $selesai('Unggahan terlalu besar. Setiap berkas paling besar 2 MB; unggah sebagian berkas lebih dahulu.');
}
if (!Csrf::validate($_POST['_csrf'] ?? null)) {
    $selesai('Halaman sudah kedaluwarsa. Berkas belum tersimpan; muat ulang halaman lalu unggah lagi.');
}

$berkas = new BerkasPsb();
$tersimpan = [];
$galat = [];
foreach (array_keys(PsbAdmin::BERKAS) as $kolom) {
    $file = $_FILES[$kolom] ?? null;
    if (!is_array($file) || is_array($file['name'] ?? null) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        continue;
    }
    try {
        $nama = $berkas->simpan($file, $kolom);
        // Kolom berasal dari daftar tetap PsbAdmin::BERKAS, bukan dari kiriman.
        $stmt = $koneksi->prepare('UPDATE psb_pendaftar SET ' . $kolom . ' = ? WHERE id = ?');
        $id = (int) $pendaftar['id'];
        $stmt->bind_param('si', $nama, $id);
        $stmt->execute();
        $stmt->close();
        $tersimpan[] = PsbAdmin::BERKAS[$kolom];
    } catch (PsbException $exception) {
        $galat[] = $exception->getMessage();
    }
}

if ($tersimpan === [] && $galat === []) {
    $selesai('Pilih berkas yang akan diunggah lebih dahulu.');
}
$selesai(trim(($tersimpan === [] ? '' : 'Berkas berhasil diperbarui: ' . implode(', ', $tersimpan) . '. ')
    . ($galat === [] ? '' : 'Belum tersimpan: ' . implode(' ', $galat))));
