<?php

declare(strict_types=1);

/**
 * Alamat lama "Akun Pengurus & Orang Tua" — jalur transisi.
 *
 * Sejak koreksi ke-1 (30 Agustus 2026) pengelolaan seluruh akun dan hak akses
 * berada dalam satu pusat `admin/admin_akun.php`.
 *
 * - Permintaan GET diarahkan ke pusat tersebut dengan tab peran yang sesuai.
 * - Permintaan POST TIDAK dialihkan. Ia diteruskan ke pusat akun sehingga
 *   `admin/_guard.php` (role admin + `Csrf::requireValid`) dan seluruh
 *   validasi mutasi tetap dijalankan penuh. Redirect yang melewati validasi
 *   mutasi/CSRF sengaja tidak dipakai.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/admin_akun.php';
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$kind = (string) ($_GET['kind'] ?? '');
$query = [];
if ($kind === 'pengurus' || $kind === 'orang_tua') {
    $query['role'] = $kind;
}
if (!empty($_GET['q'])) {
    $query['q'] = (string) $_GET['q'];
}

header('Location: ' . app_url('/admin/admin_akun.php') . ($query === [] ? '' : '?' . http_build_query($query)), true, 302);
exit;
