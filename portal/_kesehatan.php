<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Kesehatan\KesehatanException;
use App\Ui\Denial;

/**
 * Penjaga bersama halaman kesehatan santri (PRD V5 Fase 1).
 *
 * Setiap permintaan: akun web wajib masuk, POST wajib CSRF, respons privat
 * tanpa cache, dan hak baca/catat dihitung ulang oleh `KesehatanService`
 * (bukan dari menu atau peran sesi). Variabel setelahnya: $currentUser,
 * $kesehatan (layanan), $aksesKesehatan (baca, catat, sumber, riwayat_akses).
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$currentUser = authorization()->requireWebUser();
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? null);
}

$kesehatan = kesehatan_service();
try {
    $aksesKesehatan = $kesehatan->akses($currentUser);
} catch (KesehatanException $exception) {
    Denial::render('Modul kesehatan belum tersedia', $exception->getMessage(), $exception->status, null, false);
}
if (!$aksesKesehatan['baca']) {
    Denial::page('Kesehatan santri', 'petugas kesehatan berpenugasan aktif dan admin');
}

function kesehatan_url(string $halaman, array $query = []): string
{
    return app_url('/portal/' . $halaman) . ($query === [] ? '' : '?' . http_build_query($query));
}

/** Label sumber hak pada riwayat akses. */
function kesehatan_sumber_label(string $sumber): string
{
    return match ($sumber) {
        'petugas' => 'Petugas kesehatan',
        'admin' => 'Admin (pengawasan)',
        'admin_petugas' => 'Admin yang juga petugas',
        default => $sumber,
    };
}
