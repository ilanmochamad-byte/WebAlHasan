<?php

declare(strict_types=1);

use App\Database\PageQuery;

require_once __DIR__ . '/_guard.php';

/**
 * Konten website › Berita (redesain website Tahap 3, W-1).
 *
 * Daftar berhalaman dengan pencarian judul dan saringan bulan, gambar mini di
 * kiri judul, dan menu ⋯ berlabel (Ubah, Lihat di situs, Hapus dengan dialog
 * yang menyebut judul). Berita tanpa status dan kategori: langsung tampil di
 * situs setelah disimpan, seperti sebelumnya (keputusan AH).
 */

$q = PageQuery::term($_GET['q'] ?? '');
$bulan = is_string($_GET['bulan'] ?? null) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['bulan']) ? $_GET['bulan'] : '';
$params = [];
$where = [];
if ($bulan !== '') {
    $where[] = 'tanggal BETWEEN ? AND LAST_DAY(?)';
    array_push($params, $bulan . '-01', $bulan . '-01');
}
$sql = 'SELECT id, judul, gambar, tanggal FROM berita' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
$daftar = PageQuery::fetch($koneksi, $sql, $params, 'id DESC', max(1, (int) ($_GET['page'] ?? 1)), $q, ['judul']);
$bulanAda = $koneksi->query("SELECT DISTINCT DATE_FORMAT(tanggal, '%Y-%m') AS b FROM berita WHERE tanggal IS NOT NULL ORDER BY b DESC LIMIT 36")?->fetch_all(MYSQLI_ASSOC) ?? [];
$namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

ah_page_open([
    'title' => 'Berita',
    'heading' => 'Konten website',
    'user' => $currentUser,
    'active' => 'konten',
    'description' => 'Berita langsung tampil di situs setelah disimpan.',
    'actions' => '<a class="btn btn-outline-secondary" href="' . ah_e(app_url('/berita.php')) . '" target="_blank" rel="noopener">Lihat situs ↗</a>'
        . '<a class="btn btn-primary" href="tambah_berita.php">Tulis berita</a>',
]);
ah_filter_open(($q !== '' ? 1 : 0) + ($bulan !== '' ? 1 : 0));
?>
    <div class="ah-filter__wide"><label class="form-label" for="berita-q">Cari judul</label>
        <input class="form-control" type="search" id="berita-q" name="q" maxlength="100" value="<?= ah_e($q) ?>"></div>
    <div><label class="form-label" for="berita-bulan">Bulan</label>
        <select class="form-select" id="berita-bulan" name="bulan"><option value="">Semua bulan</option>
            <?php foreach ($bulanAda as $row): [$y, $m] = explode('-', (string) $row['b']); ?>
                <option value="<?= ah_e($row['b']) ?>"<?= $bulan === $row['b'] ? ' selected' : '' ?>><?= ah_e($namaBulan[(int) $m - 1] . ' ' . $y) ?></option>
            <?php endforeach; ?>
        </select></div>
<?php ah_filter_close(); ?>
<div class="ah-card ah-table-wrap">
    <table class="ah-table ah-table--cards" id="daftar-berita">
        <caption class="ah-visually-hidden">Berita di situs, <?= (int) $daftar['total'] ?> data</caption>
        <thead><tr><th scope="col">Judul</th><th scope="col">Tanggal</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
        <tbody>
        <?php foreach ($daftar['rows'] as $row): ?>
            <?php $gambar = (string) $row['gambar']; ?>
            <tr>
                <td><span class="ah-title-cell">
                    <?php if ($gambar !== ''): ?><img class="ah-thumb" src="<?= ah_e(app_url('/gambar/' . rawurlencode($gambar))) ?>" alt="" loading="lazy"><?php else: ?><span class="ah-thumb ah-thumb--empty" aria-hidden="true">—</span><?php endif; ?>
                    <a class="ah-row-link" href="edit_berita.php?id=<?= (int) $row['id'] ?>"><?= ah_e($row['judul']) ?></a></span></td>
                <td><?= $row['tanggal'] ? ah_e(\App\Ui\Layout::date((int) strtotime((string) $row['tanggal']))) : '—' ?></td>
                <td data-label=""><?= ah_menu([
                    ['label' => 'Ubah', 'url' => 'edit_berita.php?id=' . (int) $row['id'], 'icon' => 'fa-pen'],
                    ['label' => 'Lihat di situs', 'url' => app_url('/detail.php?id=' . (int) $row['id']), 'icon' => 'fa-arrow-up-right-from-square'],
                    ['label' => 'Hapus', 'icon' => 'fa-trash', 'danger' => true, 'action' => 'proses_berita.php?act=hapus', 'fields' => ['id' => (int) $row['id']],
                        'confirm' => ['title' => 'Hapus berita "' . $row['judul'] . '"?', 'text' => 'Berita dan gambar sampulnya hilang dari situs dan tidak dapat dikembalikan.', 'ok' => 'Hapus berita']],
                ], 'Tindakan untuk ' . $row['judul']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($daftar['rows'] === []): ?>
            <tr><td colspan="3" data-label=""><?= ah_empty($q !== '' || $bulan !== '' ? 'Tidak ada berita yang cocok' : 'Belum ada berita', $q !== '' || $bulan !== '' ? 'Bersihkan saringan untuk melihat semua berita.' : 'Tulis berita pertama; berita langsung tampil di situs.', '<a class="btn btn-primary" href="tambah_berita.php">Tulis berita</a>') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
ah_pagination((int) $daftar['total'], (int) $daftar['page'], (int) $daftar['perPage']);
ah_page_close();
