<?php

declare(strict_types=1);

use App\Database\PageQuery;
use App\Http\Csrf;
use App\Konten\Unggahan;

require_once __DIR__ . '/_guard.php';

/**
 * Konten website › Unduhan (redesain website Tahap 3, W-4).
 *
 * Daftar dokumen berhalaman (judul, jenis, ukuran, tanggal unggah) di dalam
 * kerangka admin; unggah di panel (judul + berkas, jenis dibatasi); menu ⋯
 * berisi Unduh dan Hapus (dialog). Hapus hanya POST dan nama berkas dari DB.
 */

$self = app_url('/admin/admin_download.php');

// --- UNGGAH DOKUMEN ---
if (isset($_POST['upload_file'])) {
    $judul = mb_substr(trim((string) ($_POST['judul'] ?? '')), 0, 255);
    $berkas = Unggahan::daftar('file')[0] ?? null;
    try {
        if ($judul === '') {
            throw new RuntimeException('Judul dokumen wajib diisi.');
        }
        if ($berkas === null) {
            throw new RuntimeException('Pilih berkas dokumen lebih dahulu.');
        }
        $nama = Unggahan::simpan($berkas, __DIR__ . '/file_download', Unggahan::DOKUMEN, Unggahan::MAKS_DOKUMEN);
        $stmt = mysqli_prepare($koneksi, "INSERT INTO download (judul, nama_file) VALUES (?, ?)");
        mysqli_stmt_bind_param($stmt, 'ss', $judul, $nama);
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('Dokumen tidak dapat disimpan. Coba lagi.');
        }
        ah_flash_set('success', 'Dokumen "' . $judul . '" diunggah dan tampil di halaman unduhan situs.');
        header('Location: admin_download.php');
    } catch (RuntimeException $exception) {
        ah_old_keep($_POST, ['judul'], '_unduhan');
        ah_flash_set('danger', 'Dokumen belum diunggah. ' . $exception->getMessage());
        header('Location: admin_download.php?action=unggah');
    }
    exit;
}

// Proses Hapus (hanya POST; nama berkas diambil dari baris download, bukan dari permintaan)
if (isset($_GET['hapus'])) {
    admin_require_post();
}
if (isset($_POST['hapus'])) {
    $id = (int) $_POST['hapus'];
    $stmt = mysqli_prepare($koneksi, "SELECT nama_file FROM download WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $nama_berkas = basename((string) ($row['nama_file'] ?? ''));
    if ($nama_berkas !== '' && is_file('file_download/' . $nama_berkas)) {
        unlink('file_download/' . $nama_berkas);
    }
    $stmt = mysqli_prepare($koneksi, "DELETE FROM download WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    ah_flash_set(mysqli_stmt_affected_rows($stmt) > 0 ? 'success' : 'info', mysqli_stmt_affected_rows($stmt) > 0 ? 'Dokumen dihapus dari halaman unduhan.' : 'Dokumen tidak ditemukan; tidak ada yang dihapus.');
    header('Location: admin_download.php');
    exit;
}

$q = PageQuery::term($_GET['q'] ?? '');
$daftar = PageQuery::fetch($koneksi, 'SELECT id, judul, nama_file, tanggal FROM download', [], 'id DESC', max(1, (int) ($_GET['page'] ?? 1)), $q, ['judul']);
$unggah = ($_GET['action'] ?? '') === 'unggah';
$listUrl = $self . ($q !== '' ? '?' . http_build_query(['q' => $q]) : '');

ah_page_open([
    'title' => 'Unduhan',
    'heading' => 'Konten website',
    'user' => $currentUser,
    'active' => 'konten',
    'description' => 'Dokumen langsung tersedia di halaman unduhan situs.',
    'actions' => '<a class="btn btn-outline-secondary" href="' . ah_e(app_url('/download.php')) . '" target="_blank" rel="noopener">Lihat situs ↗</a>'
        . '<a class="btn btn-primary" href="' . ah_e($self . '?action=unggah') . '">Unggah dokumen</a>',
]);

if ($unggah):
    ah_panel_open(['title' => 'Unggah dokumen', 'close_url' => $listUrl, 'description' => 'Isian bertanda * wajib.']);
    ?>
    <form method="post" action="admin_download.php" enctype="multipart/form-data">
        <?= Csrf::input() ?>
        <div class="mb-3"><label class="form-label" for="unduhan-judul">Judul dokumen <span aria-hidden="true">*</span></label>
            <input class="form-control" id="unduhan-judul" name="judul" maxlength="255" required placeholder="Misal: Brosur PSB 2026" value="<?= ah_e(ah_old('judul', null, '_unduhan')) ?>"></div>
        <label class="ah-drop" data-ah-drop>
            <span>Tarik berkas ke sini atau pilih berkas</span>
            <input type="file" name="file" required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.txt,.zip,.jpg,.jpeg,.png">
            <span class="ah-drop__hint">PDF, dokumen Office/ODF, TXT, ZIP, JPG, PNG · paling besar 20 MB</span>
        </label>
        <div class="ah-panel__foot">
            <a class="btn btn-outline-secondary" href="<?= ah_e($listUrl) ?>">Batal</a>
            <button class="btn btn-primary" type="submit" name="upload_file" value="1">Unggah</button>
        </div>
    </form>
    <?php
    ah_panel_close();
    ah_old_clear('_unduhan');
endif;
ah_filter_open($q !== '' ? 1 : 0);
?>
    <div class="ah-filter__wide"><label class="form-label" for="unduhan-q">Cari judul</label>
        <input class="form-control" type="search" id="unduhan-q" name="q" maxlength="100" value="<?= ah_e($q) ?>"></div>
<?php ah_filter_close(); ?>
<div class="ah-card ah-table-wrap">
    <table class="ah-table ah-table--cards" id="daftar-unduhan">
        <caption class="ah-visually-hidden">Dokumen unduhan, <?= (int) $daftar['total'] ?> data</caption>
        <thead><tr><th scope="col">Judul dokumen</th><th scope="col">Jenis</th><th scope="col">Ukuran</th><th scope="col">Diunggah</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
        <tbody>
        <?php foreach ($daftar['rows'] as $row): ?>
            <?php $berkas = (string) $row['nama_file']; $path = __DIR__ . '/file_download/' . pathinfo(str_replace('\\', '/', $berkas), PATHINFO_BASENAME); ?>
            <tr>
                <td><b><?= ah_e($row['judul']) ?></b></td>
                <td><?= ah_e(strtoupper(pathinfo($berkas, PATHINFO_EXTENSION)) ?: '—') ?></td>
                <td><?= ah_e(Unggahan::ukuran($path)) ?></td>
                <td><?= $row['tanggal'] ? ah_e(\App\Ui\Layout::date((int) strtotime((string) $row['tanggal']))) : '—' ?></td>
                <td data-label=""><?= ah_menu([
                    ['label' => 'Unduh', 'url' => 'file_download/' . rawurlencode($berkas), 'icon' => 'fa-download'],
                    ['label' => 'Hapus', 'icon' => 'fa-trash', 'danger' => true, 'action' => 'admin_download.php', 'fields' => ['hapus' => (int) $row['id']],
                        'confirm' => ['title' => 'Hapus dokumen "' . $row['judul'] . '"?', 'text' => 'Dokumen hilang dari halaman unduhan situs dan berkasnya dihapus permanen.', 'ok' => 'Hapus dokumen']],
                ], 'Tindakan untuk ' . $row['judul']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($daftar['rows'] === []): ?>
            <tr><td colspan="5" data-label=""><?= ah_empty($q !== '' ? 'Tidak ada dokumen yang cocok' : 'Belum ada dokumen', $q !== '' ? 'Bersihkan pencarian untuk melihat semua dokumen.' : 'Unggah brosur atau formulir agar dapat diunduh pengunjung situs.') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
ah_pagination((int) $daftar['total'], (int) $daftar['page'], (int) $daftar['perPage']);
ah_page_close();
