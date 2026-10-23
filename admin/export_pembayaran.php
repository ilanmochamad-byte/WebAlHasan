<?php

declare(strict_types=1);

use App\Psb\PsbPembayaran;

require_once __DIR__ . '/_guard.php';

/**
 * Ekspor pembayaran PSB ke Excel sesuai saringan daftar (redesain Tahap 3).
 * Berkas .xlsx dibuat di server; kolom sama dengan ekspor lama.
 */

$filter = PsbPembayaran::filter($_GET);
$baris = (new PsbPembayaran($koneksi))->exportRows($filter);
if (count($baris) === 1) {
    $query = http_build_query(array_filter($filter, static fn (string $v): bool => $v !== ''));
    ah_flash_set('info', 'Tidak ada tagihan yang cocok dengan saringan untuk diekspor.');
    ah_redirect(app_url('/admin/admin_pembayaran_psb.php') . ($query !== '' ? '?' . $query : ''));
}
ah_xlsx_download('Keuangan_PSB_AlHasan_' . date('Y-m-d') . '.xlsx', $baris);
