<?php

declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
admin_require_post();

/**
 * Hapus pendaftar PSB (hanya POST + CSRF). Redesain Tahap 3: hasil tampil
 * sebagai pesan di halaman Pendaftar dengan saringan yang sama.
 */

$id = (int) ($_POST['id'] ?? 0);
$stmt = mysqli_prepare($koneksi, "DELETE FROM psb_pendaftar WHERE id=?");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
ah_flash_set(mysqli_stmt_affected_rows($stmt) > 0 ? 'success' : 'info', mysqli_stmt_affected_rows($stmt) > 0
    ? 'Pendaftar dihapus.' : 'Pendaftar tidak ditemukan; tidak ada yang dihapus.');

parse_str((string) ($_POST['kembali'] ?? ''), $query);
$query = array_intersect_key(is_array($query) ? $query : [], array_flip(['status', 'jk', 'jenjang', 'kecamatan', 'cari', 'page']));
$query = array_filter(array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $query), static fn (string $v): bool => $v !== '');
header('Location: admin_data.php' . ($query === [] ? '' : '?' . http_build_query($query)));
exit;
