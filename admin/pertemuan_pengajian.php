<?php

declare(strict_types=1);

/**
 * Alamat lama "Pertemuan Pengajian" — jalur kompatibilitas.
 *
 * Sejak koreksi ke-4 (30 Agustus 2026) jadwal dan pertemuan berada dalam satu
 * modul bertab `admin/admin_pengajian.php`. Alamat ini dipertahankan agar
 * tautan aplikasi, bookmark guru, dan riwayat peramban tetap berfungsi.
 *
 * - Permintaan GET diarahkan ke tab Pertemuan dengan konteks jadwal terbawa.
 * - Permintaan POST TIDAK dialihkan, melainkan diteruskan ke modul terpadu
 *   sehingga badan permintaan, `Csrf::requireValid`, dan validasi status
 *   pertemuan tetap dijalankan penuh.
 *
 * Guard tidak dilonggarkan: modul tujuan memakai `requireWebUser()` lalu
 * membatasi role admin/guru, dan `ScheduleService` tetap memeriksa kepemilikan
 * jadwal serta pertemuan di server.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST['tab'] = 'pertemuan';
    $_REQUEST['tab'] = 'pertemuan';
    require __DIR__ . '/admin_pengajian.php';
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$query = $_GET;
$query['tab'] = 'pertemuan';
header('Location: ' . app_url('/admin/admin_pengajian.php') . '?' . http_build_query($query), true, 302);
exit;
