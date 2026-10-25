<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Http\Csrf;
use App\Ui\Denial;

/**
 * Penjaga bersama halaman keuangan santri/BSA (PRD V6 Fase 1–2).
 *
 * Setiap permintaan: akun web wajib masuk, POST wajib CSRF, respons privat
 * tanpa cache, dan hak baca/kelola dihitung ulang oleh `BsaService` (bukan
 * dari menu atau peran sesi). Variabel setelahnya: $currentUser, $bsa
 * (layanan Fase 1), $tagihanBsa (layanan tagihan Fase 2), $aksesBsa (baca,
 * kelola, sumber, jenjang).
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$currentUser = authorization()->requireWebUser();
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? null);
}

$bsa = bsa_service();
$tagihanBsa = tagihan_service();
try {
    $aksesBsa = $bsa->akses($currentUser);
} catch (BsaException $exception) {
    Denial::render('Modul keuangan santri belum tersedia', $exception->getMessage(), $exception->status, null, false);
}
if (!$aksesBsa['baca']) {
    Denial::page('Keuangan santri', 'petugas BSA berpenugasan aktif, admin, dan orang tua atas anaknya');
}

function bsa_url(string $halaman, array $query = []): string
{
    return app_url('/portal/' . $halaman) . ($query === [] ? '' : '?' . http_build_query($query));
}

/** Rupiah bulat dengan titik pemisah ribuan. */
function bsa_rp(int $nominal): string
{
    return 'Rp' . number_format($nominal, 0, ',', '.');
}

/** Judul menu/halaman menurut hak: orang tua melihat "Keuangan anak". */
function bsa_judul(array $akses): string
{
    return $akses['sumber'] === 'wali' ? 'Keuangan anak' : 'Keuangan santri';
}

/** Cakupan petugas dalam kata biasa. */
function bsa_cakupan_teks(array $akses): string
{
    return $akses['jenjang'] === null ? 'seluruh unit' : 'unit ' . implode(', ', $akses['jenjang']);
}

/** Kunci idempotensi formulir: dipakai ulang dari POST yang gagal, selain itu dibuat baru. */
function bsa_kunci_formulir(array $isian): string
{
    $kunci = is_string($isian['idempotency_key'] ?? null) ? (string) $isian['idempotency_key'] : '';

    return preg_match('/^[A-Za-z0-9._:-]{8,100}$/D', $kunci) === 1 ? $kunci : bin2hex(random_bytes(16));
}

/** Periode 'TTTT-BB' sebagai "Oktober 2026". */
function bsa_periode(string $periode): string
{
    static $bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    if (preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])$/D', $periode, $m) !== 1) {
        return $periode;
    }

    return $bulan[(int) $m[2] - 1] . ' ' . $m[1];
}

/** Halaman Fase 2 hanya tersedia bila migrasi 028 terpasang. */
function bsa_wajib_fase2(\App\Bsa\TagihanService $layanan): void
{
    if (!$layanan->tersedia()) {
        Denial::render('Modul tagihan belum tersedia', 'Modul tagihan bulanan lengkap (PRD V6 Fase 2) belum dipasang pada basis data ini.', 503, null, false);
    }
}

/**
 * Navigasi bagian keuangan (tautan biasa, tanpa JavaScript). Petugas dan
 * admin: santri, tunggakan, pembayaran, penerbitan, tarif; orang tua: anak,
 * belum lunas, pembayaran. Hak tetap diperiksa halaman tujuan.
 */
function bsa_nav(array $akses, string $aktif, \App\Bsa\TagihanService $layanan): string
{
    if (!$layanan->tersedia()) {
        return '';
    }
    $wali = $akses['sumber'] === 'wali';
    $butir = $wali
        ? ['bsa.php' => 'Anak', 'bsa_tunggakan.php' => 'Belum lunas', 'bsa_pembayaran.php' => 'Pembayaran']
        : ['bsa.php' => 'Santri', 'bsa_tunggakan.php' => 'Tunggakan', 'bsa_pembayaran.php' => 'Pembayaran', 'bsa_penerbitan.php' => 'Penerbitan', 'bsa_tarif.php' => 'Tarif'];

    return ah_seg(array_map(static fn (string $halaman, string $label): array => ['label' => $label, 'url' => bsa_url($halaman), 'active' => $halaman === $aktif],
        array_keys($butir), array_values($butir)), 'Bagian keuangan');
}

/** Status pembayaran dalam kata yang dipahami orang tua dan petugas. */
function bsa_status_bayar(string $status): string
{
    return ah_status($status, match ($status) {
        'Menunggu' => 'Menunggu verifikasi',
        default => $status,
    });
}

/** Nilai periode 'TTTT-BB' dari GET/POST bila sah, selain itu bawaan. */
function bsa_nilai_periode(mixed $value, string $bawaan = ''): string
{
    return is_string($value) && preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $value) === 1 ? $value : $bawaan;
}
