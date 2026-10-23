<?php

declare(strict_types=1);

require_once __DIR__ . '/_guard.php';

/**
 * Konten website › Ubah berita (redesain website Tahap 3, W-2). ID dibaca
 * sebagai bilangan bulat dan dicari dengan prepared statement.
 */

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$stmt = $koneksi->prepare('SELECT * FROM berita WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$berita = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($berita === null) {
    ah_flash_set('danger', 'Berita tidak ditemukan. Mungkin sudah dihapus.');
    ah_redirect(app_url('/admin/admin_berita.php'));
}
require __DIR__ . '/_berita_form.php';
