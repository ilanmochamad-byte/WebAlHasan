<?php

declare(strict_types=1);

use App\Psb\BerkasPsb;
use App\Psb\PendaftarSesi;
use App\Psb\PsbAdmin;

/**
 * Jalur berpenjaga berkas pendaftar PSB (workstream keamanan PSB).
 *
 *   lihat_berkas_psb.php?jenis=file_kk            pendaftar yang masuk (miliknya sendiri)
 *   lihat_berkas_psb.php?id=12&jenis=file_kk      admin
 *   … &unduh=1                                    sebagai lampiran, bukan tampil di peramban
 *
 * Hak akses diperiksa setiap permintaan: admin (peran aktif di basis data)
 * atau pendaftar pemilik berkas. Nama berkas dibaca dari kolom pendaftar itu,
 * tidak pernah dari parameter. Berkas disajikan dengan jenis isi hasil
 * pemeriksaan server, tanpa cache bersama. Jalur ini disiapkan untuk
 * pratinjau berkas di panel pendaftar (keputusan AG, redesain Tahap 8).
 */

require_once __DIR__ . '/app/bootstrap.php';

header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');

$tolak = static function (int $status, string $pesan): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $pesan;
    exit;
};

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    $tolak(405, 'Metode tidak diizinkan.');
}

$jenis = is_string($_GET['jenis'] ?? null) ? $_GET['jenis'] : '';
if (!isset(PsbAdmin::BERKAS[$jenis])) {
    $tolak(404, 'Berkas tidak ditemukan.');
}

$user = authorization()->currentUser();
// Akun yang wajib mengganti password belum boleh membuka data (seperti guard web).
$admin = $user !== null && in_array('admin', $user['roles'], true) && empty($user['force_password_change']);
$pendaftar = (new PendaftarSesi(app_db()))->aktif();
$adaId = array_key_exists('id', $_GET);
$id = $adaId ? (int) filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 0]]) : 0;

if ($admin && $adaId) {
    $row = (new PsbAdmin(app_db()))->find($id);
} elseif ($pendaftar !== null) {
    // Pendaftar hanya mendapat barisnya sendiri; id pendaftar lain ditolak.
    if ($adaId && $id !== (int) $pendaftar['id']) {
        $tolak(403, 'Anda tidak berhak membuka berkas ini.');
    }
    $row = $pendaftar;
} elseif ($user === null) {
    $tolak(401, 'Masuk ke Portal Pendaftar atau sistem lebih dahulu untuk membuka berkas.');
} elseif ($admin) {
    $tolak(404, 'Berkas tidak ditemukan.');
} else {
    $tolak(403, 'Anda tidak berhak membuka berkas ini.');
}

if ($row === null) {
    $tolak(404, 'Berkas tidak ditemukan.');
}
$file = (new BerkasPsb())->baca(is_string($row[$jenis] ?? null) ? $row[$jenis] : null);
if ($file === null) {
    $tolak(404, 'Berkas tidak ditemukan.');
}

$aman = $file['mime'] !== 'application/octet-stream';
$namaUnduh = substr($jenis, 5) . '-' . preg_replace('/[^A-Za-z0-9-]+/', '', (string) $row['no_pendaftaran']) . '.' . $file['ext'];
$lampiran = !$aman || !empty($_GET['unduh']);
header('Content-Type: ' . $file['mime']);
header('Content-Length: ' . $file['size']);
header('Content-Disposition: ' . ($lampiran ? 'attachment' : 'inline') . '; filename="' . $namaUnduh . '"');
if (str_starts_with($file['mime'], 'image/')) {
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    readfile($file['path']);
}
exit;
