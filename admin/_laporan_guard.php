<?php

declare(strict_types=1);

// A-07: keputusan pengguna 30 Agustus 2026, laporan web admin/guru baca-saja.
// Guard master data tetap admin-only dan tidak diubah oleh pengecualian ini.
if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

$currentUser = authorization()->requireWebUser();
$isAdmin = in_array('admin', $currentUser['roles'], true);
$isGuru = in_array('guru', $currentUser['roles'], true) && (int) ($currentUser['guru_id'] ?? 0) > 0;
if (!$isAdmin && !$isGuru) {
    App\Ui\Denial::render('Akun ini tidak memiliki hak laporan kehadiran.', 'Laporan tersedia untuk admin dan guru sesuai jadwalnya sendiri.');
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit('Laporan kehadiran hanya dapat dibaca.');
}
// Layanan report/meeting tetap memeriksa teacher_id dan kepemilikan pertemuan.
