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

$isianFormulir = ['nama_lengkap', 'nisn', 'nik', 'tempat_lahir', 'tgl_lahir', 'jenis_kelamin', 'jenjang_tujuan', 'sekolah_asal', 'alamat_jalan',
    'nama_provinsi', 'nama_kabupaten', 'nama_kecamatan', 'nama_desa', 'nama_ayah', 'nama_ibu', 'no_hp_wali', 'no_hp_wali_ulang'];
try {
    $galatTahap8 = [];
    if (($_POST['setuju_privasi'] ?? '') !== '1') {
        $galatTahap8['setuju_privasi'] = 'Centang persetujuan Kebijakan Privasi untuk mengirim pendaftaran.';
    }
    if (array_key_exists('no_hp_wali_ulang', $_POST) && (string) $_POST['no_hp_wali_ulang'] !== (string) ($_POST['no_hp_wali'] ?? '')) {
        $galatTahap8['no_hp_wali_ulang'] = 'No. HP wali yang diketik ulang belum sama.';
    }
    $pendaftarData = new PendaftarData($koneksi);
    if ($galatTahap8 !== []) {
        // Tampilkan sekaligus galat isian lain (tanpa menyimpan apa pun).
        try {
            $pendaftarData->periksaPendaftaran($_POST);
        } catch (PsbException $exception) {
            throw new PsbException('Data belum tersimpan.', $exception->errors() + $galatTahap8);
        }
        throw new PsbException('Data belum tersimpan.', $galatTahap8);
    }
    $baru = $pendaftarData->daftar($_POST);
    (new PendaftarSesi($koneksi))->izinkanBukti($baru['id'], $baru['no']);
    unset($_SESSION['_psb_daftar_galat'], $_SESSION['_psb_daftar_isian']);
    header('Location: psb.php?status=sukses&noreg=' . rawurlencode($baru['no']));
} catch (PsbException $exception) {
    $galat = [];
    foreach ($exception->errors() ?: ['' => $exception->getMessage()] as $kunci => $pesan) {
        $galat[is_string($kunci) && $kunci !== '' ? $kunci : 'umum'] = (string) $pesan;
    }
    $_SESSION['_psb_daftar_galat'] = $galat;
    $_SESSION['_psb_daftar_isian'] = array_map(
        static fn (mixed $v): string => is_scalar($v) ? mb_substr((string) $v, 0, 1000) : '',
        array_intersect_key($_POST, array_flip($isianFormulir))
    );
    header('Location: psb.php?status=gagal');
}
exit;
