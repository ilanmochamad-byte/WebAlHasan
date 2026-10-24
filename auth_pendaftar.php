<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Psb\PendaftarSesi;

/**
 * Penangan POST masuk Portal Pendaftar (keputusan AP: tetap NISN + No. HP
 * wali). Workstream keamanan PSB: hanya POST + CSRF, pembatasan percobaan
 * gagal, regenerasi sesi, dan query terikat lewat `PendaftarSesi`.
 */

require_once __DIR__ . '/koneksi.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login_pendaftar.php');
    exit;
}
if (!Csrf::validate($_POST['_csrf'] ?? null)) {
    // Halaman masuk dibuka terlalu lama atau dari situs lain.
    header('Location: login_pendaftar.php?pesan=token');
    exit;
}

$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null;
$hasil = (new PendaftarSesi($koneksi))->masuk(
    is_scalar($_POST['nisn'] ?? null) ? (string) $_POST['nisn'] : '',
    is_scalar($_POST['hp'] ?? null) ? (string) $_POST['hp'] : '',
    $ip
);

header('Location: ' . ($hasil === 'masuk' ? 'portal_pendaftar.php' : 'login_pendaftar.php?pesan=' . $hasil));
exit;
