<?php

declare(strict_types=1);

use App\Profil\ProfilException;

/**
 * Foto profil guru/pengurus/wali untuk panel admin (PRD-PROFIL-IMPORT PI-5).
 * Hanya admin (guard `_guard.php`); berkas dibaca dari folder privat.
 *
 *   GET ?jenis=guru|pengurus|wali&id=N
 */

require_once __DIR__ . '/_guard.php';

$jenis = (string) ($_GET['jenis'] ?? '');
$id = (int) ($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !in_array($jenis, ['guru', 'pengurus', 'wali'], true) || $id < 1) {
    http_response_code(404);
    exit;
}
try {
    $berkas = profil_service()->fotoPemilik($jenis, $id);
} catch (ProfilException) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $berkas['mime']);
header('Content-Length: ' . (string) filesize($berkas['path']));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($berkas['path']);
