<?php

declare(strict_types=1);

use App\Auth\AuthService;
use App\Http\Csrf;
use App\Http\SafeRedirect;

/**
 * Penangan POST login — satu-satunya, dipakai bersama oleh pintu masuk baru
 * `/portal/` dan alamat lama `/admin/admin_login.php`.
 *
 * Alamat berkas ini SENGAJA dipertahankan (koreksi ke-7, bagian D): formulir
 * lama, bookmark, dan skrip uji yang masih mengirim ke sini tetap berfungsi
 * tanpa jalur autentikasi kedua.
 *
 * Pengamanan yang dipertahankan: CSRF wajib, hash password `password_verify`,
 * regenerasi ID sesi di `AuthService::attempt()`, dan audit percobaan masuk.
 * Ditambahkan: pembatasan percobaan masuk berbasis audit (`LoginThrottle`).
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$pintuMasuk = app_url('/portal/index.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $pintuMasuk);
    exit;
}

Csrf::requireValid($_POST['_csrf'] ?? null);

// Tujuan yang diminta sebelum masuk. Hanya alamat internal yang diizinkan;
// tujuan eksternal atau tidak valid dibuang tanpa diikuti.
$next = SafeRedirect::sanitize($_POST['next'] ?? null);
$suffix = $next === null ? '' : '&next=' . rawurlencode($next);

$username = (string) ($_POST['username'] ?? '');
$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null;

if (login_throttle()->status($username, $ip)['terkunci']) {
    audit_logger()->log('login_throttled', 'user', null, null, ['username' => strtolower(trim($username))]);
    header('Location: ' . $pintuMasuk . '?pesan=terkunci' . $suffix);
    exit;
}

$service = new AuthService(auth_repository(), audit_logger());
if (!$service->attempt($username, (string) ($_POST['password'] ?? ''))) {
    header('Location: ' . $pintuMasuk . '?pesan=gagal' . $suffix);
    exit;
}

if (!empty($_SESSION['force_password_change'])) {
    header('Location: ' . app_url('/admin/ubah_password.php') . ($next === null ? '' : '?next=' . rawurlencode($next)));
    exit;
}

// Tujuan pasca-login berasal dari `LandingRouter`: satu beranda untuk seluruh
// peran, yang menyusun panel dan menu dari kemampuan NYATA akun. Beranda dan
// setiap halaman tujuan tetap memeriksa haknya sendiri di sisi server.
$user = authorization()->currentUser();
$destination = $user === null ? null : landing_router()->url($user);

if ($destination !== null) {
    header('Location: ' . ($next ?? $destination));
    exit;
}

// Akun tanpa role/hubungan data yang sah: dijelaskan di pintu masuk, tanpa
// diberi akses tambahan apa pun.
header('Location: ' . $pintuMasuk . '?pesan=tanpa_akses');
exit;
