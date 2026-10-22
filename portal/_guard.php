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
 * tombol mode. Kini cakupan mengikuti peran aktif di sesi. Alamat lama yang
 * membawa `?mode=` untuk peran lain akun ini TIDAK mengganti peran diam-diam:
 * pengguna diberi pilihan eksplisit "Buka sebagai …" atau melanjutkan dengan
 * peran aktif (A-2, A-5). `?mode=` di luar hak akun diabaikan, seperti
 * sebelumnya. Layanan perizinan tetap mengabaikan cakupan yang bukan milik akun.
 *
 * @param array<string, mixed> $user
 * @param array<int, string> $capabilities
 */
function portal_requested_mode(array $user, array $capabilities): ?string
{
    $aktif = Peran::active($user);
    $modeAktif = Peran::izinMode($aktif, $capabilities);
    $diminta = isset($_GET['mode']) ? (string) $_GET['mode'] : null;
    if ($diminta !== null && $diminta !== $modeAktif && in_array($diminta, $capabilities, true)) {
        $peran = Peran::fromIzinMode($diminta);
        if ($peran !== null && $peran !== $aktif && in_array($peran, Peran::available($user), true)) {
            // Tanpa peran aktif yang memiliki perizinan, alamat dibuka dengan
            // cakupan bawaan layanan seperti sebelumnya.
            $lanjut = null;
            if ($modeAktif !== null) {
                $query = $_GET;
                unset($query['mode']);
                $lanjut = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') . ($query === [] ? '' : '?' . http_build_query($query));
            }
            \App\Ui\Denial::otherRole((string) $aktif, [$peran], \App\Http\SafeRedirect::currentRequest(), $lanjut);
        }
    }

    return $modeAktif ?? ($diminta !== null && in_array($diminta, $capabilities, true) ? $diminta : null);
}
