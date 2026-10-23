<?php

declare(strict_types=1);

use App\Database\PageQuery;
use App\Http\Csrf;
use App\Konten\Unggahan;

require_once __DIR__ . '/_guard.php';

/**
 * Konten website › Galeri (redesain website Tahap 3, W-3).
 *
 * Kisi foto terbaru lebih dulu, tanpa album (keputusan AI). Beberapa foto
 * dapat diunggah sekaligus; setiap foto tetap satu baris galeri (tanpa
 * perubahan database). Klik foto membuka panel untuk mengubah judul dan
 * keterangan atau menghapus. Hapus hanya POST dan nama berkas dari DB.
 */

$self = app_url('/admin/admin_galeri.php');
$folder = APP_ROOT . '/gambar_galeri';

// --- UNGGAH (satu atau beberapa foto) ---
if (isset($_POST['upload_foto'])) {
    $berkas = Unggahan::daftar('foto');
    $judul = trim((string) ($_POST['judul'] ?? ''));
    $ket = trim((string) ($_POST['keterangan'] ?? ''));
    $berhasil = 0;
    $gagal = [];
    if ($berkas === []) {
        $gagal[] = 'Pilih minimal satu foto.';
    }
    foreach ($berkas as $foto) {
        try {
            $nama = Unggahan::simpan($foto, $folder, Unggahan::GAMBAR, Unggahan::MAKS_GAMBAR, true);
            $judulFoto = $judul !== '' && count($berkas) === 1 ? mb_substr($judul, 0, 255) : Unggahan::judulDari($foto['name']);
            $stmt = mysqli_prepare($koneksi, "INSERT INTO galeri (judul, keterangan, gambar) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'sss', $judulFoto, $ket, $nama);
            if (mysqli_stmt_execute($stmt)) {
                $berhasil++;
            } else {
                $gagal[] = $foto['name'] . ': tidak dapat disimpan.';
            }
        } catch (RuntimeException $exception) {
            $gagal[] = $exception->getMessage();
        }
    }
    ah_flash_set($gagal === [] ? 'success' : ($berhasil > 0 ? 'warning' : 'danger'),
        $berhasil . ' foto diunggah' . ($gagal === [] ? '. Judul dan keterangan dapat diubah dengan mengeklik foto.' : ' · ' . count($gagal) . ' gagal.'),
        $gagal === [] ? null : '<ul class="mb-0 mt-1 small">' . implode('', array_map(static fn (string $p): string => '<li>' . ah_e($p) . '</li>', $gagal)) . '</ul>');
    header('Location: admin_galeri.php');
    exit;
}

// --- UBAH JUDUL & KETERANGAN ---
if (isset($_POST['ubah'])) {
    $id = (int) $_POST['ubah'];
    $judul = mb_substr(trim((string) ($_POST['judul'] ?? '')), 0, 255);
    $ket = trim((string) ($_POST['keterangan'] ?? ''));
    if ($judul === '') {
        ah_flash_set('danger', 'Judul foto wajib diisi.');
    } else {
        $stmt = mysqli_prepare($koneksi, "UPDATE galeri SET judul=?, keterangan=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, 'ssi', $judul, $ket, $id);
        mysqli_stmt_execute($stmt);
        ah_flash_set('success', 'Judul dan keterangan foto disimpan.');
    }
    header('Location: admin_galeri.php?id=' . $id);
    exit;
}

// Proses Hapus (hanya POST; nama berkas diambil dari baris galeri, bukan dari permintaan)
if (isset($_GET['hapus'])) {
    admin_require_post();
}
if (isset($_POST['hapus'])) {
    $id = (int) $_POST['hapus'];
    $stmt = mysqli_prepare($koneksi, "SELECT gambar FROM galeri WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $nama_berkas = basename((string) ($row['gambar'] ?? ''));
    if ($nama_berkas !== '' && is_file('../gambar_galeri/' . $nama_berkas)) {
        unlink('../gambar_galeri/' . $nama_berkas);
    }
    $stmt = mysqli_prepare($koneksi, "DELETE FROM galeri WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    ah_flash_set(mysqli_stmt_affected_rows($stmt) > 0 ? 'success' : 'info', mysqli_stmt_affected_rows($stmt) > 0 ? 'Foto dihapus dari galeri.' : 'Foto tidak ditemukan; tidak ada yang dihapus.');
    header('Location: admin_galeri.php');
    exit;
}

$daftar = PageQuery::fetch($koneksi, 'SELECT id, judul, keterangan, gambar, tanggal FROM galeri', [], 'tanggal DESC, id DESC', max(1, (int) ($_GET['page'] ?? 1)), '', [], 24);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$dipilih = null;
if ($id > 0) {
    $stmt = $koneksi->prepare('SELECT * FROM galeri WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $dipilih = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}
$listUrl = $self . ($daftar['page'] > 1 ? '?page=' . (int) $daftar['page'] : '');
$src = static fn (string $nama): string => app_url('/gambar_galeri/' . rawurlencode($nama));

ah_page_open([
    'title' => 'Galeri',
    'heading' => 'Konten website',
    'user' => $currentUser,
    'active' => 'konten',
    'description' => 'Foto langsung tampil di galeri situs, terbaru lebih dulu.',
    'actions' => '<a class="btn btn-outline-secondary" href="' . ah_e(app_url('/galeri.php')) . '" target="_blank" rel="noopener">Lihat situs ↗</a>'
        . '<a class="btn btn-primary" href="#unggah-foto">Unggah foto</a>',
]);
if ($id > 0 && $dipilih === null) {
    ah_note('danger', 'Foto tidak ditemukan; pilih dari kisi.');
}

if ($dipilih !== null):
    ah_panel_open(['title' => 'Ubah foto', 'close_url' => $listUrl]);
    ?>
    <img class="ah-editor__cover" src="<?= ah_e($src((string) $dipilih['gambar'])) ?>" alt="<?= ah_e($dipilih['judul']) ?>">
    <form method="post" action="admin_galeri.php">
        <?= Csrf::input() ?>
        <input type="hidden" name="ubah" value="<?= (int) $dipilih['id'] ?>">
        <div class="mb-2"><label class="form-label" for="galeri-judul">Judul <span aria-hidden="true">*</span></label>
            <input class="form-control" id="galeri-judul" name="judul" maxlength="255" required value="<?= ah_e($dipilih['judul']) ?>"></div>
        <div class="mb-2"><label class="form-label" for="galeri-ket">Keterangan</label>
            <textarea class="form-control" id="galeri-ket" name="keterangan" rows="3"><?= ah_e($dipilih['keterangan'] ?? '') ?></textarea></div>
        <div class="ah-panel__foot">
            <a class="btn btn-outline-secondary" href="<?= ah_e($listUrl) ?>">Batal</a>
            <button class="btn btn-primary" type="submit">Simpan</button>
        </div>
    </form>
    <div class="ah-danger-zone mt-3">
        <p class="small mb-2">Foto dihapus dari galeri situs dan berkasnya dihapus permanen.</p>
        <form method="post" action="admin_galeri.php" data-confirm="Foto &quot;<?= ah_e($dipilih['judul']) ?>&quot; hilang dari galeri situs dan tidak dapat dikembalikan." data-confirm-title="Hapus foto ini?" data-confirm-ok="Hapus foto" data-confirm-tone="danger">
            <?= Csrf::input() ?>
            <input type="hidden" name="hapus" value="<?= (int) $dipilih['id'] ?>">
            <button class="btn btn-outline-danger" type="submit">Hapus foto</button>
        </form>
    </div>
    <?php
    ah_panel_close();
endif;
?>
<form method="post" action="admin_galeri.php" enctype="multipart/form-data" class="ah-card ah-card__body" id="unggah-foto" aria-labelledby="judul-unggah">
    <h2 class="h6" id="judul-unggah">Unggah foto</h2>
    <?= Csrf::input() ?>
    <label class="ah-drop" data-ah-drop>
        <span>Tarik beberapa foto ke sini atau pilih berkas</span>
        <input type="file" name="foto[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required>
        <span class="ah-drop__hint">JPG, PNG, WEBP, atau GIF · paling besar 5 MB per foto. Judul diambil dari nama berkas dan dapat diubah sesudahnya.</span>
    </label>
    <details class="mt-2"><summary class="small">Judul dan keterangan (opsional)</summary>
        <div class="row g-2 mt-1">
            <div class="col-md-5"><label class="form-label" for="unggah-judul">Judul (bila satu foto)</label><input class="form-control" id="unggah-judul" name="judul" maxlength="255"></div>
            <div class="col-md-7"><label class="form-label" for="unggah-ket">Keterangan (untuk semua foto)</label><input class="form-control" id="unggah-ket" name="keterangan"></div>
        </div>
    </details>
    <div class="ah-actions mt-2"><button class="btn btn-primary" type="submit" name="upload_foto" value="1">Unggah</button></div>
</form>
<?php if ($daftar['rows'] === []): ?>
    <div class="ah-card ah-card__body"><?= ah_empty('Belum ada foto', 'Unggah foto kegiatan; foto langsung tampil di galeri situs.') ?></div>
<?php else: ?>
    <ul class="ah-media" aria-label="Foto galeri, <?= (int) $daftar['total'] ?> foto">
        <?php foreach ($daftar['rows'] as $row): ?>
            <li><a href="<?= ah_e($self . '?' . http_build_query(($daftar['page'] > 1 ? ['page' => $daftar['page']] : []) + ['id' => (int) $row['id']])) ?>"<?= (int) $row['id'] === $id ? ' aria-current="true"' : '' ?>>
                <img src="<?= ah_e($src((string) $row['gambar'])) ?>" alt="" loading="lazy">
                <span class="ah-media__cap"><b><?= ah_e($row['judul']) ?></b><small><?= $row['tanggal'] ? ah_e(\App\Ui\Layout::date((int) strtotime((string) $row['tanggal']))) : '' ?><?= (string) ($row['keterangan'] ?? '') !== '' ? ' · ' . ah_e(mb_strimwidth((string) $row['keterangan'], 0, 60, '…')) : '' ?></small></span>
            </a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php
ah_pagination((int) $daftar['total'], (int) $daftar['page'], 24);
ah_page_close();
