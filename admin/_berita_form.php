<?php

declare(strict_types=1);

use App\Http\Csrf;

require_once __DIR__ . '/_guard.php';

/**
 * Penyunting berita bersama untuk tulis dan ubah (redesain Tahap 3, W-2).
 *
 * Di dalam kerangka admin: isi di kiri, kartu Simpan dan Gambar sampul di
 * kanan (HP: di bawah, tombol Simpan menempel di bawah). Tanpa status draf
 * (keputusan AH), sehingga tombol Pratinjau menampilkan berita seperti di
 * situs sebelum disimpan. Penyunting teks memakai Summernote yang sudah
 * dipakai halaman lama; tanpa JavaScript tetap berupa kotak teks biasa.
 *
 * @var array<string, mixed>|null $berita baris berita saat mengubah
 * @var array<string, mixed> $currentUser
 */

$ubah = isset($berita) && is_array($berita);
$judul = ah_old('judul', $ubah ? $berita : null, '_berita');
$isi = ah_old('isi', $ubah ? ['isi' => $berita['isi_berita']] : null, '_berita');
$gambar = $ubah ? (string) $berita['gambar'] : '';
$tanggal = $ubah && $berita['tanggal'] ? (int) strtotime((string) $berita['tanggal']) : time();

ah_page_open([
    'title' => $ubah ? 'Ubah berita' : 'Tulis berita',
    'user' => $currentUser,
    'active' => 'konten',
    'show_heading' => true,
    'breadcrumbs' => [['label' => 'Konten website', 'url' => app_url('/admin/admin_berita.php')], ['label' => 'Berita', 'url' => app_url('/admin/admin_berita.php')], ['label' => $ubah ? 'Ubah berita' : 'Tulis berita']],
]);
?>
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
<form class="ah-editor" method="post" enctype="multipart/form-data" action="proses_berita.php?act=<?= $ubah ? 'update' : 'tambah' ?>" id="form-berita">
    <?= Csrf::input() ?>
    <?php if ($ubah): ?><input type="hidden" name="id" value="<?= (int) $berita['id'] ?>"><?php endif; ?>
    <div class="ah-card ah-card__body">
        <div class="mb-3">
            <label class="form-label" for="judul">Judul <span aria-hidden="true">*</span></label>
            <input class="form-control form-control-lg" id="judul" name="judul" maxlength="255" required value="<?= ah_e($judul) ?>"><?= ah_field_error('judul', '_berita') ?>
        </div>
        <div class="mb-1">
            <label class="form-label" for="isi">Isi berita <span aria-hidden="true">*</span></label>
            <textarea class="form-control" id="isi" name="isi" rows="14" required><?= ah_e($isi) ?></textarea><?= ah_field_error('isi', '_berita') ?>
            <p class="small text-muted mt-1 mb-0">Penyunting teks: tebal, miring, daftar, tautan, dan gambar.</p>
        </div>
    </div>
    <div class="ah-editor__side">
        <section class="ah-card" aria-labelledby="judul-simpan">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="judul-simpan">Simpan</h2></div>
            <div class="ah-card__body">
                <p class="mb-1"><span class="ah-meta">Tanggal</span><br><b><?= ah_e(\App\Ui\Layout::date($tanggal)) ?></b></p>
                <p class="small text-muted">Berita langsung tampil di situs setelah disimpan.</p>
                <div class="ah-actions ah-sticky-sm">
                    <button class="btn btn-outline-secondary" type="button" data-berita-pratinjau hidden>Pratinjau</button>
                    <button class="btn btn-primary" type="submit">Simpan &amp; tampilkan</button>
                </div>
            </div>
        </section>
        <section class="ah-card" aria-labelledby="judul-sampul">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="judul-sampul">Gambar sampul<?= $ubah ? '' : ' <span aria-hidden="true">*</span>' ?></h2></div>
            <div class="ah-card__body">
                <?php if ($gambar !== ''): ?><img class="ah-editor__cover" src="<?= ah_e(app_url('/gambar/' . rawurlencode($gambar))) ?>" alt="Gambar sampul saat ini"><?php endif; ?>
                <label class="ah-drop" data-ah-drop>
                    <span><?= $ubah ? 'Ganti gambar (opsional)' : 'Tarik gambar ke sini atau pilih berkas' ?></span>
                    <input type="file" name="foto" accept="image/jpeg,image/png,image/webp,image/gif"<?= $ubah ? '' : ' required' ?> data-berita-foto>
                    <span class="ah-drop__hint">JPG, PNG, WEBP, atau GIF · paling besar 5 MB</span>
                </label>
                <?= ah_field_error('foto', '_berita') ?>
            </div>
        </section>
    </div>
</form>
<dialog class="ah-dialog ah-preview" id="pratinjau-berita" aria-labelledby="pratinjau-judul">
    <div class="ah-dialog__box">
        <p class="ah-meta mb-1">Pratinjau · seperti di situs</p>
        <h2 class="ah-dialog__title" id="pratinjau-judul"></h2>
        <img class="ah-editor__cover" alt="" hidden data-pratinjau-sampul>
        <div class="ah-preview__body" data-pratinjau-isi></div>
        <div class="ah-dialog__actions"><button class="btn btn-primary" type="button" data-pratinjau-tutup>Tutup pratinjau</button></div>
    </div>
</dialog>
<script>
(function () {
    var $ = window.jQuery;
    if ($ && $.fn.summernote) {
        $('#isi').summernote({
            placeholder: 'Tulis isi berita di sini…',
            tabsize: 2,
            height: 380,
            toolbar: [
                ['style', ['style']],
                ['font', ['bold', 'italic', 'underline', 'clear']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['insert', ['link', 'picture']],
                ['view', ['codeview']]
            ]
        });
    }
    var tombol = document.querySelector('[data-berita-pratinjau]');
    var dialog = document.getElementById('pratinjau-berita');
    if (!tombol || !dialog || typeof dialog.showModal !== 'function') { return; }
    tombol.hidden = false;
    var sampulLama = <?= json_encode($gambar !== '' ? app_url('/gambar/' . rawurlencode($gambar)) : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    tombol.addEventListener('click', function () {
        dialog.querySelector('#pratinjau-judul').textContent = document.getElementById('judul').value || '(tanpa judul)';
        var isi = ($ && $.fn.summernote) ? $('#isi').summernote('code') : document.getElementById('isi').value;
        dialog.querySelector('[data-pratinjau-isi]').innerHTML = isi;
        var img = dialog.querySelector('[data-pratinjau-sampul]');
        var foto = document.querySelector('[data-berita-foto]');
        if (foto && foto.files && foto.files[0]) {
            img.src = URL.createObjectURL(foto.files[0]);
            img.hidden = false;
        } else {
            img.hidden = !sampulLama;
            if (sampulLama) { img.src = sampulLama; }
        }
        dialog.showModal();
    });
    dialog.querySelector('[data-pratinjau-tutup]').addEventListener('click', function () { dialog.close(); tombol.focus(); });
})();
</script>
<?php
ah_old_clear('_berita');
ah_page_close();
