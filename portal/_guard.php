<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Ui\Peran;

require_once dirname(__DIR__) . '/app/bootstrap.php';

/**
 * Guard portal perizinan berbasis kemampuan.
 *
 * Halaman portal wajib memanggil file ini sebelum menghasilkan output apa pun.
 * Variabel yang tersedia setelahnya: $currentUser, $userCapabilities, $koneksi.
 */
$portalContext = portal_guard()->requireAnyPerizinan();
$currentUser = $portalContext['user'];
$userCapabilities = $portalContext['capabilities'];
$koneksi = app_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
}

/**
 * Cakupan perizinan yang diminta halaman (redesain website Tahap 2, A-2).
 *
 * Sebelumnya setiap halaman perizinan membaca `?mode=` dan menampilkan deretan
 * tombol mode. Kini cakupan bawaan mengikuti peran aktif di sesi. Alamat lama
 * yang masih membawa `?mode=` tetap berlaku dan sekaligus menyelaraskan peran
 * aktif. Layanan perizinan tetap mengabaikan cakupan yang bukan milik akun.
 *
 * @param array<string, mixed> $user
 * @param array<int, string> $capabilities
 */
function portal_requested_mode(array $user, array $capabilities): ?string
{
    $diminta = isset($_GET['mode']) ? (string) $_GET['mode'] : null;
    if ($diminta !== null && in_array($diminta, $capabilities, true)) {
        $peran = Peran::fromIzinMode($diminta);
        if ($peran !== null) {
            Peran::set($user, $peran);
        }

        return $diminta;
    }

    return Peran::izinMode(Peran::active($user), $capabilities) ?? $diminta;
}
