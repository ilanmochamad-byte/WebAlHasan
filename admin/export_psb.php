<?php

declare(strict_types=1);

use App\Psb\PsbAdmin;

require_once __DIR__ . '/_guard.php';

/**
 * Ekspor pendaftar PSB ke Excel sesuai saringan daftar (redesain Tahap 3).
 *
 * Berkas .xlsx dibuat di server (sebelumnya JSON yang dirakit SheetJS di
 * peramban dan kotak alert peramban saat kosong). Kolom sama dengan ekspor lama. Saringan
 * dibaca `PsbAdmin::filter()` dan diikat sebagai parameter query.
 */

$filter = PsbAdmin::filter($_GET);
$baris = (new PsbAdmin($koneksi))->exportRows($filter);
if (count($baris) === 1) {
    $query = http_build_query(array_filter($filter, static fn (string $v): bool => $v !== ''));
    ah_flash_set('info', 'Tidak ada pendaftar yang cocok dengan saringan untuk diekspor.');
    ah_redirect(app_url('/admin/admin_data.php') . ($query !== '' ? '?' . $query : ''));
}
ah_xlsx_download('Data_PSB_AlHasan_' . date('Y-m-d') . '.xlsx', $baris);
