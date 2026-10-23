<?php

declare(strict_types=1);

use App\Konten\Unggahan;

require_once __DIR__ . '/_guard.php';
admin_require_post();

/**
 * Simpan, ubah, dan hapus berita (hanya POST + CSRF).
 *
 * Redesain Tahap 3 (W-1/W-2): hasil tampil sebagai pesan di halaman,
 * isian dipertahankan saat validasi gagal, query memakai prepared statement,
 * gambar sampul diperiksa jenisnya, dan nama gambar lama diambil dari basis
 * data. Folder unggahan dan folder hapus sengaja tidak diubah (catatan audit
 * perbaikan mutasi V1: penyelarasan folder aset menunggu keputusan terpisah).
 */

$act = (string) ($_GET['act'] ?? '');

/** Kembali ke penyunting dengan isian dan pesan galat per isian. */
function berita_gagal(string $pesan, array $errors, string $kembali): never
{
    ah_old_keep($_POST, ['judul', 'isi'], '_berita');
    $_SESSION['_berita_errors'] = $errors;
    ah_flash_set('danger', $pesan);
    header('Location: ' . $kembali);
    exit;
}

// --- TAMBAH / UBAH BERITA ---
if ($act === 'tambah' || $act === 'update') {
    $id = (int) ($_POST['id'] ?? 0);
    $kembali = $act === 'tambah' ? 'tambah_berita.php' : 'edit_berita.php?id=' . $id;
    $judul = trim((string) ($_POST['judul'] ?? ''));
    $isi = trim((string) ($_POST['isi'] ?? ''));
    $errors = [];
    if ($judul === '' || mb_strlen($judul) > 255) {
        $errors['judul'] = $judul === '' ? 'Judul wajib diisi.' : 'Judul paling banyak 255 karakter.';
    }
    if (trim(strip_tags($isi, '<img>')) === '') {
        $errors['isi'] = 'Isi berita wajib diisi.';
    }
    $lama = null;
    if ($act === 'update') {
        $stmt = mysqli_prepare($koneksi, "SELECT gambar FROM berita WHERE id=?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $lama = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$lama) {
            ah_flash_set('danger', 'Berita tidak ditemukan. Mungkin sudah dihapus.');
            header('Location: admin_berita.php');
            exit;
        }
    }
    $foto = Unggahan::daftar('foto')[0] ?? null;
    if ($foto === null && $act === 'tambah') {
        $errors['foto'] = 'Gambar sampul wajib dipilih.';
    }
    if ($errors !== []) {
        berita_gagal('Berita belum tersimpan. Periksa isian bertanda merah.', $errors, $kembali);
    }
    $gambarBaru = null;
    if ($foto !== null) {
        try {
            $gambarBaru = Unggahan::simpan($foto, APP_ROOT . '/gambar', Unggahan::GAMBAR, Unggahan::MAKS_GAMBAR, true);
        } catch (RuntimeException $exception) {
            berita_gagal('Berita belum tersimpan. ' . $exception->getMessage(), ['foto' => $exception->getMessage()], $kembali);
        }
    }

    if ($act === 'tambah') {
        $tgl = date('Y-m-d');
        $stmt = mysqli_prepare($koneksi, "INSERT INTO berita (judul, isi_berita, gambar, tanggal) VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'ssss', $judul, $isi, $gambarBaru, $tgl);
    } elseif ($gambarBaru !== null) {
        $stmt = mysqli_prepare($koneksi, "UPDATE berita SET judul=?, isi_berita=?, gambar=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, 'sssi', $judul, $isi, $gambarBaru, $id);
    } else {
        $stmt = mysqli_prepare($koneksi, "UPDATE berita SET judul=?, isi_berita=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, 'ssi', $judul, $isi, $id);
    }
    if (!mysqli_stmt_execute($stmt)) {
        error_log('Berita gagal disimpan: ' . mysqli_stmt_error($stmt));
        berita_gagal('Berita belum tersimpan karena galat server. Coba lagi.', [], $kembali);
    }
    // Ganti gambar: gambar lama (nama dari basis data) dihapus dari folder
    // yang sama dengan proses hapus berita.
    if ($act === 'update' && $gambarBaru !== null) {
        $namaLama = pathinfo(str_replace('\\', '/', (string) ($lama['gambar'] ?? '')), PATHINFO_BASENAME);
        if ($namaLama !== '' && $namaLama !== '.' && $namaLama !== '..' && is_file('gambar/' . $namaLama)) {
            unlink('gambar/' . $namaLama);
        }
    }
    ah_old_clear('_berita');
    ah_flash_set('success', $act === 'tambah' ? 'Berita disimpan dan langsung tampil di situs.' : 'Perubahan berita disimpan.');
    header('Location: admin_berita.php');
    exit;
}

// --- PROSES HAPUS BERITA ---
if ($act === 'hapus') {
    $id = (int) ($_POST['id'] ?? 0);

    // Nama gambar diambil dari baris berita, bukan dari permintaan
    $stmt = mysqli_prepare($koneksi, "SELECT gambar FROM berita WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $gbr = basename((string) ($row['gambar'] ?? ''));

    // Hapus file gambar fisik di folder
    if ($gbr !== '' && is_file("gambar/" . $gbr)) {
        unlink("gambar/" . $gbr);
    }

    // Hapus data di database
    $stmt = mysqli_prepare($koneksi, "DELETE FROM berita WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    ah_flash_set(mysqli_stmt_affected_rows($stmt) > 0 ? 'success' : 'info', mysqli_stmt_affected_rows($stmt) > 0 ? 'Berita dihapus dari situs.' : 'Berita tidak ditemukan; tidak ada yang dihapus.');
    header("Location: admin_berita.php");
    exit;
}

http_response_code(422);
exit('Tindakan berita tidak dikenal.');
