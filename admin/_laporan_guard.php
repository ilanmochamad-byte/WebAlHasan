<?php

declare(strict_types=1);

// A-07: keputusan pengguna 30 Agustus 2026, laporan web admin/guru baca-saja.
// Keputusan H (redesain website Tahap 4, 22 Oktober 2026): Bagian Pendidikan
// membaca laporan kehadiran SELURUH jadwal, hanya-baca (J-6).
// Guard master data tetap admin-only dan tidak diubah oleh pengecualian ini.
if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Report\ReportFilter;

$currentUser = authorization()->requireWebUser();
$isAdmin = in_array('admin', $currentUser['roles'], true);
$isGuru = in_array('guru', $currentUser['roles'], true) && (int) ($currentUser['guru_id'] ?? 0) > 0;
// Bagian Pendidikan = pengurus dengan penugasan Pendidikan aktif MILIK SENDIRI
// (bukan pengawasan admin). Hak dihitung ulang dari penugasan pada setiap
// permintaan; peran aktif di sesi tidak memberi hak.
$isPendidikan = in_array('pengurus', $currentUser['roles'], true)
    && in_array(
        capabilities()->featureSource($currentUser, App\Penugasan\PenugasanJenis::CAP_RAPOR_VERIFIKASI),
        [App\Auth\Capabilities::SUMBER_PENUGASAN, App\Auth\Capabilities::SUMBER_KEDUANYA],
        true
    );
if (!$isAdmin && !$isGuru && !$isPendidikan) {
    App\Ui\Denial::render(
        'Akun ini tidak memiliki hak laporan kehadiran.',
        'Laporan tersedia untuk admin, guru sesuai jadwalnya sendiri, dan Bagian Pendidikan (hanya-baca).'
    );
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit('Laporan kehadiran hanya dapat dibaca.');
}
// Penanda baca-seluruh-jadwal hanya dipasang di jalur web ini. REST API dan
// aplikasi guru tidak pernah membawa penanda ini, sehingga kontraknya tetap.
// Akun Bagian Pendidikan yang juga guru membaca seluruh jadwal hanya saat
// peran aktifnya Bagian Pendidikan; sebagai guru cakupannya tetap jadwal
// sendiri (peran hanya mempersempit, tidak pernah memberi hak).
if ($isPendidikan && !$isAdmin && (!$isGuru || App\Ui\Peran::active($currentUser) === App\Ui\Peran::PENDIDIKAN)) {
    $currentUser[ReportFilter::BACA_SEMUA_JADWAL] = true;
}
// Layanan report/meeting tetap memeriksa teacher_id dan kepemilikan pertemuan.
