<?php

declare(strict_types=1);

use App\Api\ApiException;
use App\Izin\IzinException;

require_once __DIR__ . '/_guard.php';

/**
 * Unduhan CSV laporan perizinan.
 *
 * Berisi SELURUH hasil filter (bukan halaman yang sedang terlihat), memakai
 * cakupan dan filter yang sama persis dengan `portal/laporan.php`, dan telah
 * dinetralkan terhadap formula injection oleh `IzinCsvExport`.
 *
 * Dokumentasi setiap kolom: `docs/phase-v2-5/definisi-filter-dan-ekspor.md`
 * dan konstanta `IzinCsvExport::DOKUMENTASI`.
 */

$requestedMode = isset($_GET['mode']) ? (string) $_GET['mode'] : null;

try {
    $ekspor = izin_report_service()->csv($currentUser, $_GET, $requestedMode);
} catch (ApiException | IzinException $exception) {
    http_response_code($exception->status());
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Ekspor CSV gagal: ' . $exception->getMessage();
    exit;
}

$namaBerkas = $ekspor['nama_berkas'];

header('Content-Type: text/csv; charset=utf-8');
// `attachment` + nosniff mencegah berkas dirender sebagai halaman oleh peramban.
header('Content-Disposition: attachment; filename="' . $namaBerkas . '"; filename*=UTF-8\'\'' . rawurlencode($namaBerkas));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Content-Length: ' . strlen($ekspor['konten']));
// Jumlah baris diumumkan agar auditor dapat mencocokkannya dengan total
// ringkasan tanpa membuka isi berkas.
header('X-Laporan-Jumlah-Baris: ' . (int) $ekspor['jumlah_baris']);
header('X-Laporan-Kriteria: ' . $ekspor['kriteria']);
if ($ekspor['terpotong'] === true) {
    header('X-Laporan-Terpotong: 1');
}

echo $ekspor['konten'];
