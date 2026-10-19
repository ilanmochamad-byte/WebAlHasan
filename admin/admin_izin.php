<?php

declare(strict_types=1);

/**
 * Alamat kompatibilitas perizinan V1 — keputusan cleanup 19 Oktober 2026.
 * Tabel perizinan dan seluruh riwayatnya dipertahankan; hasil migrasi dapat
 * dibaca di portal V2 dengan ID yang sama dan label Data warisan.
 * Implementasi tulis lama dipensiunkan. IZIN_LEGACY_ENABLED dan ?legacy=1
 * tidak lagi dapat membukanya; kode sebelum cleanup tersimpan dalam Git.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Cache-Control: no-store');
authorization()->requireWebRole('admin');

// Tidak memakai _guard.php: semua mutasi ditolak, termasuk POST tanpa CSRF.
// Tidak ada penerusan body/aksi ke portal dan tidak ada penulisan database.
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)
    || isset($_GET['status']) || isset($_GET['hapus'])) {
    http_response_code(410);
    header('Allow: GET, HEAD');
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        "Penulisan izin melalui alamat lama sudah dihentikan.\n"
        . "Tidak ada data izin yang diubah oleh permintaan ini.\n"
        . 'Gunakan Perizinan → Daftar Pengajuan di ' . app_url('/portal/izin.php')
    );
}

$tujuan = app_url('/portal/izin.php');
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id !== false) {
    // Migrasi V2 mempertahankan ID lama; guard dan cakupan detail tetap berlaku.
    $tujuan = app_url('/portal/izin_detail.php') . '?id=' . $id;
}
header('Location: ' . $tujuan, true, 302);
exit;
