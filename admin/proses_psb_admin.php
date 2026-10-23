<?php

declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
admin_require_post();

/**
 * Keputusan pendaftar PSB: ubah status dan migrasi ke data santri.
 *
 * Hanya POST + CSRF (perbaikan mutasi V1 lewat GET). Redesain Tahap 3: hasil
 * tampil sebagai pesan di halaman Pendaftar (POST/Redirect/GET), bukan
 * kotak alert peramban; aturan status dan nomor induk santri (NIS) tidak berubah.
 */

$act = $_POST['act'] ?? '';
$id = (int) ($_POST['id'] ?? 0);

/** Kembali ke daftar dengan saringan yang sama; hanya kunci yang dikenal. */
function psb_kembali(?int $id = null): string
{
    parse_str((string) ($_POST['kembali'] ?? ''), $query);
    $query = array_intersect_key(is_array($query) ? $query : [], array_flip(['status', 'jk', 'jenjang', 'kecamatan', 'cari', 'page']));
    $query = array_filter(array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $query), static fn (string $v): bool => $v !== '');
    if ($id !== null) {
        $query['id'] = (string) $id;
    }

    return 'admin_data.php' . ($query === [] ? '' : '?' . http_build_query($query));
}

// 1. UBAH STATUS (Diterima / Ditolak / Cadangan)
if ($act === 'status') {
    $status = (string) ($_POST['val'] ?? '');
    if (!in_array($status, ['Diterima', 'Ditolak', 'Cadangan'], true)) {
        http_response_code(422);
        exit('Status pendaftar tidak valid.');
    }
    $stmt = mysqli_prepare($koneksi, "UPDATE psb_pendaftar SET status=? WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'si', $status, $id);
    mysqli_stmt_execute($stmt);
    ah_flash_set(mysqli_stmt_affected_rows($stmt) > 0 ? 'success' : 'info', mysqli_stmt_affected_rows($stmt) > 0
        ? 'Status pendaftar menjadi ' . $status . '.' : 'Status pendaftar tidak berubah.');
    header('Location: ' . psb_kembali($id));
    exit;
}

// 2. MIGRASI DATA PENDAFTAR KE DATA SANTRI DENGAN NIS CUSTOM
if ($act === 'migrasi') {
    $stmt = mysqli_prepare($koneksi, "SELECT * FROM psb_pendaftar WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $d = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$d) {
        ah_flash_set('danger', 'Pendaftar tidak ditemukan.');
        header('Location: ' . psb_kembali());
        exit;
    }

    // NIS = 2 digit tahun + kode unit (01 SMP Terpadu, 02 selainnya) + 3 digit urutan.
    $prefix = date('y') . ($d['jenjang_tujuan'] === 'SMP Terpadu Al Hasan' ? '01' : '02');
    $like = $prefix . '%';
    $stmt = mysqli_prepare($koneksi, "SELECT MAX(nis) AS maxKode FROM santri WHERE nis LIKE ?");
    mysqli_stmt_bind_param($stmt, 's', $like);
    mysqli_stmt_execute($stmt);
    $nisTerakhir = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['maxKode'] ?? null;
    $urutan = $nisTerakhir ? (int) substr((string) $nisTerakhir, 4, 3) + 1 : 1;
    $nisBaru = $prefix . sprintf('%03s', $urutan);

    $stmt = mysqli_prepare($koneksi, "INSERT INTO santri
        (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi, nama_ayah, no_hp_ayah, nama_ibu, no_hp_ibu, asal_sekolah, sekolah_saat_ini, foto)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'default.jpg')");
    mysqli_stmt_bind_param($stmt, str_repeat('s', 16), $nisBaru, $d['nama_lengkap'], $d['jenis_kelamin'], $d['tempat_lahir'], $d['tgl_lahir'],
        $d['alamat_jalan'], $d['desa'], $d['kecamatan'], $d['kab_kota'], $d['provinsi'], $d['nama_ayah'], $d['no_hp_wali'],
        $d['nama_ibu'], $d['no_hp_wali'], $d['sekolah_asal'], $d['jenjang_tujuan']);

    if (mysqli_stmt_execute($stmt)) {
        $stmt = mysqli_prepare($koneksi, "UPDATE psb_pendaftar SET status='Dimigrasi' WHERE id=?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        ah_flash_set('success', 'Migrasi berhasil. ' . $d['nama_lengkap'] . ' masuk data santri dengan NIS ' . $nisBaru . '.');
    } else {
        // Galat basis data dicatat di log server, tidak ditampilkan mentah.
        error_log('Migrasi PSB gagal untuk pendaftar #' . $id . ': ' . mysqli_stmt_error($stmt));
        ah_flash_set('danger', 'Migrasi gagal. Data santri tidak berubah; periksa kelengkapan biodata lalu coba lagi.');
    }
    header('Location: ' . psb_kembali($id));
    exit;
}

http_response_code(422);
exit('Tindakan pendaftar tidak dikenal.');
