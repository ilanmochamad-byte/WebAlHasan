<?php

declare(strict_types=1);

// Router lokal untuk smoke test Expo web + API pada origin yang sama.
// Ditolak bila target database bukan *_test dan tidak untuk deployment produksi.
if (!str_ends_with((string) getenv('DB_NAME'), '_test')) {
    http_response_code(500);
    exit('Router Fase 5 hanya boleh memakai database *_test.');
}
$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
if (str_starts_with($path, '/api/v1')) {
    require dirname(__DIR__) . '/api/v1/index.php';
    return true;
}
if ($path !== '/' && is_file((string) ($_SERVER['DOCUMENT_ROOT'] ?? '') . $path)) {
    return false;
}
$index = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/index.html';
if (is_file($index)) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($index);
    return true;
}
http_response_code(404);
echo 'Build Expo web belum tersedia.';
