<?php

declare(strict_types=1);

/**
 * Alamat lama halaman penempatan santri.
 *
 * Sejak paket "Penempatan Kelas & Kamar Santri" (keputusan pengguna 6 September
 * 2026) halaman ini pindah ke `admin/admin_penempatan_santri.php`. Fitur dan
 * datanya TIDAK dihapus — hanya berpindah alamat dan memakai layanan terpusat
 * `App\MasterData\PenempatanService`.
 *
 * Perilaku berkas ini:
 *
 *   - GET  : dialihkan permanen ke halaman baru, lengkap dengan pemetaan
 *            parameter filter lama (`cari`, `jk`, `sekolah`, `filter_status`)
 *            supaya bookmark dan tautan lama tetap membuka hasil yang sama.
 *   - POST dan metode lain : DIHENTIKAN dengan 410 Gone dan TIDAK pernah
 *            dialihkan, dengan atau tanpa token CSRF. Endpoint AJAX lama
 *            (`update_plot`, `bulk_update_plot`) tidak ber-CSRF, tidak
 *            transaksional, dan tidak beraudit; mengalihkannya secara buta
 *            berarti menjalankan mutasi yang sudah dinyatakan tidak aman.
 *            Klien lama harus memakai halaman baru.
 *
 * Guard admin tetap berlaku lebih dahulu: alamat ini tidak pernah dapat dibuka
 * tanpa sesi admin yang sah.
 */

// Guard admin dipanggil langsung, BUKAN lewat `_guard.php`.
//
// `_guard.php` memeriksa CSRF untuk setiap POST dan menjawab 419 sebelum berkas
// ini sempat menjelaskan apa pun. Justru klien lama yang menjadi sasaran pesan
// 410 di bawah adalah klien yang TIDAK mengirim token CSRF, sehingga pesannya
// tidak akan pernah terbaca. Melewatkan pemeriksaan CSRF aman di sini karena
// berkas ini tidak pernah mengubah data: ia hanya mengalihkan GET atau menolak.
require_once dirname(__DIR__) . '/app/bootstrap.php';

$currentUser = authorization()->requireWebRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(410);
    header('Allow: GET');
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        "Endpoint penempatan lama sudah dihentikan dan TIDAK dialihkan.\n"
        . "Tidak ada data yang diubah oleh permintaan ini.\n"
        . 'Gunakan halaman Master Data → Penempatan Kelas & Kamar (admin/admin_penempatan_santri.php).'
    );
}

/** Pemetaan parameter filter lama ke parameter halaman baru. */
$tujuan = [];
if (is_scalar($_GET['cari'] ?? null) && (string) $_GET['cari'] !== '') {
    $tujuan['q'] = (string) $_GET['cari'];
}
if (in_array($_GET['jk'] ?? '', ['L', 'P'], true)) {
    $tujuan['jk'] = (string) $_GET['jk'];
}
if (is_scalar($_GET['sekolah'] ?? null) && (string) $_GET['sekolah'] !== '') {
    $tujuan['sekolah'] = (string) $_GET['sekolah'];
}
$statusLama = is_scalar($_GET['filter_status'] ?? null) ? (string) $_GET['filter_status'] : '';
if ($statusLama === 'no_class') {
    $tujuan['status'] = 'tanpa_kelas';
} elseif ($statusLama === 'no_room') {
    $tujuan['status'] = 'tanpa_kamar';
}

$query = http_build_query($tujuan);
http_response_code(301);
header('Location: ' . app_url('/admin/admin_penempatan_santri.php') . ($query === '' ? '' : '?' . $query));
exit;
