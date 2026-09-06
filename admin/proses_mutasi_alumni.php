<?php

declare(strict_types=1);

/**
 * Alamat lama pemroses kelulusan/mutasi alumni.
 *
 * Sejak paket "Koreksi Pengelolaan Alumni" (keputusan pengguna 6 September
 * 2026) seluruh pemrosesan pindah ke `admin/admin_kelulusan_santri.php` yang
 * memakai `App\MasterData\AlumniService`. Fitur dan datanya TIDAK dihapus —
 * hanya berpindah alamat dan menjadi transaksional.
 *
 * Perilaku berkas ini:
 *
 *   - GET  : dialihkan permanen ke halaman baru, sehingga bookmark dan tautan
 *            lama tetap membuka alur yang benar.
 *   - POST dan metode lain : DIHENTIKAN dengan 410 Gone dan TIDAK PERNAH
 *            dialihkan. Endpoint lama memproses massal dengan `INSERT IGNORE`
 *            di dalam perulangan TANPA transaksi: kegagalan penyimpanan alumni
 *            ditelan diam-diam sementara santrinya tetap diarsipkan dan
 *            kelasnya tetap ditutup, kamarnya tidak pernah dilepas, dan tidak
 *            ada satu pun jejak audit. Mengalihkan POST secara buta berarti
 *            menjalankan kembali mutasi yang sudah dinyatakan tidak aman.
 *
 * Guard admin tetap berlaku lebih dahulu: alamat ini tidak pernah dapat dibuka
 * tanpa sesi admin yang sah.
 */

// Guard admin dipanggil langsung, BUKAN lewat `_guard.php` — persis alasan yang
// sama seperti `admin/admin_santri.php`: `_guard.php` menjawab 419 untuk POST
// tanpa CSRF sebelum berkas ini sempat menjelaskan apa pun, padahal klien lama
// yang menjadi sasaran pesan 410 di bawah justru klien yang tidak mengirim
// token. Melewatkan pemeriksaan CSRF aman di sini karena berkas ini tidak
// pernah mengubah data: ia hanya mengalihkan GET atau menolak.
require_once dirname(__DIR__) . '/app/bootstrap.php';

$currentUser = authorization()->requireWebRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(410);
    header('Allow: GET');
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        "Endpoint mutasi alumni lama sudah dihentikan dan TIDAK dialihkan.\n"
        . "Tidak ada data yang diubah oleh permintaan ini.\n"
        . 'Gunakan halaman Kelulusan & Mutasi Keluar (admin/admin_kelulusan_santri.php) '
        . 'yang memproses seluruh santri dalam satu transaksi beserta audit.'
    );
}

/** Pemetaan parameter lama ke halaman baru: satu-satunya yang bermakna adalah kelas. */
$tujuan = [];
$idKelas = filter_var($_GET['id_kelas'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($idKelas !== false && $idKelas !== null) {
    $tujuan['kelas_id'] = $idKelas;
}
$idSantri = filter_var($_GET['id_santri'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($idSantri !== false && $idSantri !== null) {
    $tujuan['santri_id'] = $idSantri;
}

$query = http_build_query($tujuan);
http_response_code(301);
header('Location: ' . app_url('/admin/admin_kelulusan_santri.php') . ($query === '' ? '' : '?' . $query));
exit;
