<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Kesehatan\KesehatanException;
use App\Ui\Denial;

/**
 * Penjaga bersama halaman kesehatan santri (PRD V5 Fase 1–2).
 *
 * Setiap permintaan: akun web wajib masuk, POST wajib CSRF, respons privat
 * tanpa cache, dan hak baca/catat dihitung ulang oleh `KesehatanService`
 * (bukan dari menu atau peran sesi). Variabel setelahnya: $currentUser,
 * $kesehatan (layanan), $aksesKesehatan (baca, catat, sumber, cakupan,
 * riwayat_akses, riwayat_versi, binaan). Pembimbing dan murobi terkait lolos
 * penjaga ini hanya untuk membaca; setiap baris tetap disaring cakupannya.
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
    Denial::page('Kesehatan santri', 'petugas kesehatan berpenugasan aktif, admin, serta pembimbing/murobi santri terkait');
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
        'pembimbing' => 'Pembimbing (binaan)',
        'murobi' => 'Murobi (binaan)',
        'wali' => 'Wali santri (ringkasan)',
        default => $sumber,
    };
}

/** Badge status rujukan/tindak lanjut (warna tetap, tanpa mengubah kamus status bersama). */
function kesehatan_badge(string $status): string
{
    return ah_badge($status, match ($status) {
        'Menunggu hasil', 'Terbuka' => 'warn',
        'Hasil diketahui', 'Selesai' => 'ok',
        default => 'muted',
    });
}

/** Jumlah obat + satuan untuk tampilan ("0,5 tablet"). */
function kesehatan_jumlah(?string $jumlah, ?string $satuan): string
{
    $angka = $jumlah === null ? '' : str_replace('.', ',', $jumlah);

    return trim($angka . ' ' . (string) $satuan);
}

/** Nilai datetime-local dari "Y-m-d H:i:s". */
function kesehatan_input_waktu(?string $waktu): string
{
    return $waktu === null || $waktu === '' ? '' : substr(str_replace(' ', 'T', $waktu), 0, 16);
}
