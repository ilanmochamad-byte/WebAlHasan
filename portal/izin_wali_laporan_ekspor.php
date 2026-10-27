<?php

declare(strict_types=1);

use App\Izin\IzinException;

require_once __DIR__ . '/_izin_wali.php';

/**
 * Ekspor CSV laporan perizinan lintas asal (PRD V7 Fase 3). Cakupan, saringan,
 * dan proyeksi sama dengan `izin_wali_laporan.php`; tanpa alasan izin. Setiap
 * ekspor dicatat di audit (peran, saringan, jumlah baris) tanpa isi.
 */

$mode = (string) portal_requested_mode($currentUser, $userCapabilities);
try {
    $ekspor = izin_laporan_service()->ekspor($currentUser, $_GET + ['peran' => $mode]);
} catch (IzinException $exception) {
    if ($exception->status() === 422) {
        ah_flash_set('danger', $exception->getMessage());
        header('Location: ' . app_url('/portal/izin_wali_laporan.php'));
        exit;
    }
    izin_wali_tolak($exception, $currentUser, $userCapabilities, $mode);
}
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $ekspor['nama_berkas'] . '"');
header('X-Content-Type-Options: nosniff');
echo $ekspor['csv'];
exit;
