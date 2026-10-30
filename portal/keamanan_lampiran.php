<?php

declare(strict_types=1);

use App\V3\V3Exception;

require_once __DIR__ . '/_keamanan.php';

/**
 * Keamanan › unduh lampiran pelanggaran (penugasan lintas guru/pengurus Fase 3).
 *
 * Bagian dari catatan pelanggaran lengkap (PK-T4). Hak dan cakupan diperiksa
 * `PelanggaranService::attachment()` dalam mode Keamanan; setiap unduhan
 * dicatat di audit tanpa isi. Di luar cakupan dan tidak ada → 404.
 */

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(404);
    exit;
}
try {
    $file = v3_pelanggaran_service()->attachment($pantauUser, (int) $id);
} catch (V3Exception $exception) {
    http_response_code($exception->status === 503 ? 503 : 404);
    exit;
}
header('Content-Type: ' . $file['mime']);
header('Content-Length: ' . (string) $file['size']);
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($file['name']));
readfile($file['path']);
exit;
