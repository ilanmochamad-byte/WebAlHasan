<?php

declare(strict_types=1);

/**
 * Router untuk server bawaan PHP (`php -S`) khusus PENGUJIAN Fase 3.
 *
 * Produksi memakai Apache/cPanel dengan `.htaccess`, sehingga file ini tidak
 * dipakai di luar pengujian. Fungsinya hanya meniru rewrite `/api/v1/*` ke
 * `api/v1/index.php` dan `/portal/*` ke berkas portal, agar pengujian kontrak
 * dapat memanggil API lewat HTTP sungguhan (bukan memanggil kelas langsung).
 *
 * Jalankan dari akar proyek:
 *   php -S 127.0.0.1:8099 -t . tests/v2_phase3_router.php
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if (str_starts_with($path, '/api/v1')) {
    require __DIR__ . '/../api/v1/index.php';

    return true;
}

$file = __DIR__ . '/..' . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => false,
    'data' => null,
    'error' => ['code' => 'NOT_FOUND', 'message' => 'Rute tidak ditemukan.', 'details' => []],
]);

return true;
