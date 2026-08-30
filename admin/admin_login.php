<?php

declare(strict_types=1);

use App\Http\SafeRedirect;

/**
 * Alamat lama halaman masuk — dipertahankan sebagai jalur kompatibilitas.
 *
 * Sejak koreksi ke-7 (30 Agustus 2026) seluruh sistem internal memakai satu
 * pintu masuk `/portal/`. Berkas ini tidak lagi menggambar formulir sendiri;
 * ia mengarahkan pengguna ke pintu masuk baru sambil membawa pesan dan tujuan
 * `next` yang sah, sehingga bookmark serta tautan lama tetap berfungsi.
 *
 * Penangan POST login tetap `admin/cek_login.php`, sehingga formulir lama yang
 * masih mengirim ke sana tidak rusak.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$query = [];

$pesan = (string) ($_GET['pesan'] ?? '');
if (in_array($pesan, ['gagal', 'sesi', 'logout', 'terkunci', 'tanpa_akses'], true)) {
    $query['pesan'] = $pesan;
}

$next = SafeRedirect::sanitize($_GET['next'] ?? null);
if ($next !== null) {
    $query['next'] = $next;
}

header('Location: ' . app_url('/portal/index.php') . ($query === [] ? '' : '?' . http_build_query($query)), true, 302);
exit;
