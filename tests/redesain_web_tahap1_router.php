<?php

declare(strict_types=1);

/**
 * Router server pengembangan untuk uji redesain website Tahap 1.
 *
 *   php -S 127.0.0.1:8931 tests/redesain_web_tahap1_router.php
 *
 * Sama dengan router uji lain (folder internal ditolak 403), ditambah satu
 * alamat uji `/__uji/komponen.php` yang menyajikan galeri komponen dari
 * tests/fixtures. Alamat itu tidak ada di server produksi.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/__uji/komponen.php') {
    require __DIR__ . '/fixtures/redesain_web_komponen.php';
    return true;
}
if (preg_match('#^/(?:app|bin|database|docs|storage|tests|vendor)(?:/|$)#i', $path)) {
    http_response_code(403);
    exit;
}
if (str_starts_with($path, '/api/v1/')) {
    require dirname(__DIR__) . '/api/v1/index.php';
    return true;
}

return false;
