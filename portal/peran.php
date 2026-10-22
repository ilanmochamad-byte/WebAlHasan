<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Http\SafeRedirect;
use App\Ui\Navigation;
use App\Ui\Peran;

/**
 * Pemilih peran aktif (redesain website Tahap 2, A-2, keputusan AR).
 *
 * Hanya menerima POST ber-CSRF dari akun yang sudah masuk. Peran disimpan di
 * sesi sampai keluar; tanpa perubahan database. Peran di luar hak nyata akun
 * ditolak, sehingga parameter ini tidak pernah dapat memperluas hak. Setiap
 * halaman tujuan tetap menjaga dirinya sendiri di server.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = authorization()->requireWebUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Metode tidak diizinkan. Pilih peran dari menu Peran.');
}
Csrf::requireValid($_POST['_csrf'] ?? null);

$peran = (string) ($_POST['peran'] ?? '');
if (!Peran::set($user, $peran)) {
    ah_flash_set('danger', 'Peran itu tidak tersedia untuk akun ini.');
    ah_redirect('/portal/index.php');
}

// Kembali ke halaman asal hanya bila halaman itu ada di menu peran baru;
// selain itu ke Beranda peran baru.
$tujuan = app_url('/portal/index.php');
$kembali = SafeRedirect::sanitize($_POST['kembali'] ?? null);
if ($kembali !== null) {
    $context = ui_context($user);
    $map = Navigation::build($user, $context['capabilities'], $context['unread'], $context['v3_capabilities'], $peran);
    parse_str((string) parse_url($kembali, PHP_URL_QUERY), $query);
    $lokasi = Navigation::locate($map['groups'], [], basename((string) parse_url($kembali, PHP_URL_PATH)), $query);
    if ($lokasi !== null) {
        $tujuan = $kembali;
    }
}

ah_flash_set('success', 'Peran aktif: ' . Peran::label($peran) . '.');
header('Location: ' . $tujuan);
exit;
