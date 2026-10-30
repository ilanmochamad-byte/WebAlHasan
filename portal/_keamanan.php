<?php

declare(strict_types=1);

use App\Keamanan\KeamananAkses;
use App\Ui\Denial;

/**
 * Penjaga bersama halaman Keamanan (penugasan lintas guru/pengurus Fase 3).
 *
 * Setiap permintaan: akun web wajib masuk, hak dihitung ulang dari penugasan
 * Keamanan aktif MILIK SENDIRI (bukan menu, peran sesi, atau pengawasan
 * admin), hanya GET/HEAD (mutasi apa pun → 405), dan respons privat tanpa
 * cache. Variabel setelahnya:
 *   $currentUser  akun untuk kerangka halaman;
 *   $pantauUser   akun yang sama dengan penanda `KeamananAkses`, diteruskan ke
 *                 layanan agar memakai mode baca Keamanan (layanan memeriksa
 *                 ulang haknya sendiri).
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$currentUser = authorization()->requireWebUser();
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if (!KeamananAkses::sah(capabilities(), $currentUser)) {
    Denial::page('Pantauan Keamanan', 'akun dengan penugasan Keamanan aktif (hanya baca)');
}
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit('Pantauan Keamanan hanya dapat dibaca.');
}
$pantauUser = KeamananAkses::tandai($currentUser);

function keamanan_url(string $halaman, array $query = []): string
{
    return app_url('/portal/' . $halaman) . ($query === [] ? '' : '?' . http_build_query($query));
}

/** Lencana status izin lintas asal (label sama dengan rincian V7). */
function keamanan_izin_status(string $status): string
{
    $tone = match ($status) {
        'Menunggu Pembimbing', 'Diajukan' => 'info',
        'Perlu Penetapan Pembimbing', 'Perlu Perbaikan Wali', 'Perlu Penetapan Admin' => 'warn',
        'Ditolak Pembimbing', 'Ditolak' => 'danger',
        'Disetujui' => 'ok',
        default => 'muted',
    };

    return ah_badge(\App\Izin\IzinWaliService::statusLabel($status), $tone);
}

/** Halaman galat dengan status asli; tidak membocorkan isi. */
function keamanan_tolak(int $status, string $pesan): never
{
    Denial::render(
        $status === 404 ? 'Data tidak ditemukan' : ($status === 503 ? 'Data belum dapat ditampilkan' : 'Akses ditolak'),
        $pesan,
        in_array($status, [403, 404, 422, 503], true) ? $status : 403,
        null,
        false
    );
}
