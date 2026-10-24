<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Psb\PendaftarData;
use App\Psb\PendaftarSesi;
use App\Psb\PsbException;

/**
 * Kirim formulir PSB publik.
 *
 * Workstream keamanan PSB: hanya POST ber-CSRF, isian diperiksa server dan
 * disimpan dengan query terikat (`PendaftarData`). Aturan penerimaan tetap:
 * status awal Baru, nomor PSB-tahun-acak, NISN tidak boleh ganda. Peramban
 * yang mendaftar mendapat hak mencetak bukti pendaftaran itu saja.
 */

require_once __DIR__ . '/koneksi.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['daftar'])) {
    header('Location: psb.php');
    exit;
}
if (!Csrf::validate($_POST['_csrf'] ?? null)) {
    header('Location: psb.php?status=token');
    exit;
}

try {
    $baru = (new PendaftarData($koneksi))->daftar($_POST);
    (new PendaftarSesi($koneksi))->izinkanBukti($baru['id'], $baru['no']);
    unset($_SESSION['_psb_daftar_galat']);
    header('Location: psb.php?status=sukses&noreg=' . rawurlencode($baru['no']));
} catch (PsbException $exception) {
    $_SESSION['_psb_daftar_galat'] = array_values($exception->errors() ?: [$exception->getMessage()]);
    header('Location: psb.php?status=gagal');
}
exit;
