<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Bsa\LaporanService;
use App\Ui\Denial;

/**
 * Ekspor CSV laporan keuangan (PRD V6 Fase 5). Hak sama dengan laporan:
 * petugas = santri aktif dalam cakupannya, admin = seluruh santri, orang tua
 * = anaknya saja (tanpa ekspor koreksi beralasan). Referensi transfer,
 * catatan, dan alasan pembatalan tidak ikut rincian transaksi. Setiap ekspor
 * dicatat di audit tanpa nominal; ekspor yang terlalu besar ditolak.
 */

require_once __DIR__ . '/_bsa.php';

$saring = array_filter(['dari' => $_GET['dari'] ?? null, 'sampai' => $_GET['sampai'] ?? null, 'santri' => $_GET['santri'] ?? null, 'unit' => $_GET['unit'] ?? null],
    static fn (mixed $v): bool => is_string($v) && $v !== '');
try {
    $e = laporan_bsa_service()->ekspor($currentUser, ['jenis' => $_GET['jenis'] ?? null] + $saring, 'web');
} catch (BsaException $exception) {
    if ($exception->status === 422) {
        ah_flash_set('danger', $exception->getMessage());
        ah_redirect(bsa_url('bsa_laporan.php', $saring));
    }
    Denial::page('Ekspor laporan keuangan', 'petugas BSA berpenugasan aktif, admin, dan orang tua atas anaknya', $exception->getMessage(), $exception->status);
}
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $e['nama_berkas'] . '"');
header('X-Content-Type-Options: nosniff');
$out = fopen('php://output', 'w');
LaporanService::tulisCsv($out, $e['kolom'], $e['baris']);
fclose($out);
exit;
