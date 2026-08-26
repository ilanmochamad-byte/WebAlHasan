<?php

declare(strict_types=1);

use App\Api\ApiException;
use App\Izin\IzinException;
use App\Report\IzinPrintRenderer;

require_once __DIR__ . '/_guard.php';

/**
 * Halaman cetak laporan perizinan (HTML ramah cetak → PDF lewat dialog cetak).
 *
 * Memakai `document()`, yang mengambil SELURUH baris sesuai filter — bukan satu
 * halaman. Filter, cakupan, dan otorisasinya identik dengan `portal/laporan.php`
 * karena keduanya melewati `izin_report_service()` yang sama.
 *
 * Halaman ini tidak memuat kerangka portal (navbar) supaya keluaran cetaknya
 * bersih; tombol "Cetak / Simpan PDF" disediakan `IzinPrintRenderer`.
 */

$requestedMode = isset($_GET['mode']) ? (string) $_GET['mode'] : null;

try {
    $dokumen = izin_report_service()->document($currentUser, $_GET, $requestedMode);
} catch (ApiException | IzinException $exception) {
    http_response_code($exception->status());
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Laporan tidak dapat dicetak</title>'
        . '<p style="font-family:Arial,sans-serif">Laporan tidak dapat dicetak: '
        . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// Laporan memuat data pribadi santri: jangan pernah disimpan cache bersama.
header('Cache-Control: private, no-store, max-age=0');

echo IzinPrintRenderer::render($dokumen);
