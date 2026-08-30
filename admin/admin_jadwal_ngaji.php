<?php

declare(strict_types=1);

/**
 * Alamat lama "Jadwal Pengajian" — jalur kompatibilitas.
 *
 * Sejak koreksi ke-4 (30 Agustus 2026) jadwal dan pertemuan berada dalam satu
 * modul bertab `admin/admin_pengajian.php`. Alamat ini dipertahankan agar
 * tautan internal, bookmark, dan riwayat peramban tetap berfungsi.
 *
 * - Permintaan GET diarahkan ke tab Jadwal dengan seluruh filter terbawa.
 * - Permintaan POST TIDAK dialihkan, melainkan diteruskan ke modul terpadu
 *   sehingga badan permintaan, pemeriksaan CSRF, dan validasi mutasi tetap
 *   dijalankan penuh. Pengalihan yang melewati validasi mutasi tidak dipakai.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST['tab'] = 'jadwal';
    $_REQUEST['tab'] = 'jadwal';
    require __DIR__ . '/admin_pengajian.php';
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$query = $_GET;
$query['tab'] = 'jadwal';
header('Location: ' . app_url('/admin/admin_pengajian.php') . '?' . http_build_query($query), true, 302);
exit;
