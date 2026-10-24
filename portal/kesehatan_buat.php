<?php

declare(strict_types=1);

use App\Database\PageQuery;
use App\Kesehatan\KesehatanException;
use App\Kesehatan\KesehatanService;
use App\Ui\Denial;

/**
 * Catat kunjungan klinik (PRD V5 Fase 1–2).
 *
 * Hanya petugas kesehatan berpenugasan aktif. Admin, pembimbing, murobi, dan
 * akun lain ditolak di server (`KesehatanService::buat()`), bukan hanya
 * disembunyikan tombolnya. Fase 2 menambah obat yang diberikan, rujukan,
 * tindak lanjut (`_kesehatan_form.php`), dan pilihan "Simpan sebagai draf":
 * draf tidak tampil kepada pembimbing/murobi sampai ditetapkan tercatat.
 *
 * Kunci idempotensi dibuat saat formulir dirender dan dikirim tersembunyi:
 * klik ganda, muat ulang POST, atau retry jaringan memakai kunci yang sama dan
 * menghasilkan tepat satu kunjungan. Bila validasi gagal, formulir dirender
 * ulang langsung dari POST (tanpa menyimpan isi klinis ke sesi) dengan kunci
 * yang sama.
 */

require_once __DIR__ . '/_kesehatan.php';
require_once __DIR__ . '/_kesehatan_form.php';

if (!$aksesKesehatan['catat']) {
    Denial::page('Catat kunjungan klinik', 'pengurus dengan penugasan kesehatan aktif',
        $aksesKesehatan['baca'] ? 'Admin hanya membaca catatan kesehatan dan tidak mengubah isi klinis.' : '');
}

$galat = null;
$isian = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $hasil = $kesehatan->buat($currentUser, $_POST, KesehatanService::KANAL_WEB);
        ah_flash_set('success', $hasil['replayed']
            ? 'Kunjungan ini sudah tersimpan sebelumnya. Tidak ada kunjungan ganda yang dibuat.'
            : (($_POST['status'] ?? '') === 'Draf' ? 'Draf kunjungan tersimpan. Draf belum tampil kepada pembimbing/murobi.' : 'Kunjungan tercatat.'));
        ah_redirect(kesehatan_url('kesehatan_detail.php', ['id' => $hasil['id']]));
    } catch (KesehatanException $exception) {
        if ($exception->status === 403) {
            Denial::page('Catat kunjungan klinik', 'pengurus dengan penugasan kesehatan aktif', $exception->getMessage());
        }
        http_response_code($exception->status);
        $galat = $exception->getMessage();
        $isian = $_POST;
    }
}

$nilai = static fn (string $kolom, string $bawaan = ''): string => is_string($isian[$kolom] ?? null) ? (string) $isian[$kolom] : $bawaan;
$kunci = preg_match('/^[A-Za-z0-9._:-]{8,100}$/D', $nilai('idempotency_key')) === 1 ? $nilai('idempotency_key') : bin2hex(random_bytes(16));
$q = PageQuery::term($_GET['q'] ?? '');
$pilihan = $kesehatan->santriPilihan($currentUser, $q);
$santriTerpilih = (int) ($nilai('santri_id') ?: (is_scalar($_GET['santri_id'] ?? null) ? $_GET['santri_id'] : 0));

ah_page_open([
    'title' => 'Catat kunjungan',
    'user' => $currentUser,
    'active' => 'kesehatan',
    'description' => 'Isi hanya fakta yang benar-benar terjadi. Sistem tidak menyarankan diagnosis atau pengobatan.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Kesehatan', 'url' => kesehatan_url('kesehatan.php')], ['label' => 'Catat kunjungan']],
]);
if ($galat !== null) {
    ah_note('danger', 'Kunjungan belum tersimpan. ' . $galat);
}
?>
<section class="ah-card" aria-labelledby="ah-form-kunjungan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-form-kunjungan">Kunjungan klinik</h2></div>
    <div class="ah-card__body">
        <?php if ($pilihan['total'] > count($pilihan['rows']) || $q !== ''): ?>
            <form method="get" class="mb-3 ah-no-print" role="search">
                <label class="form-label" for="cari-santri">Cari santri</label>
                <div class="d-flex gap-2"><input class="form-control" type="search" id="cari-santri" name="q" maxlength="100" value="<?= ah_e($q) ?>" placeholder="Nama santri atau NIS">
                    <button class="btn btn-outline-secondary" type="submit">Cari</button></div>
                <p class="form-text mb-0">Menampilkan <?= count($pilihan['rows']) ?> dari <?= (int) $pilihan['total'] ?> santri aktif<?= $q !== '' ? ' yang cocok' : '' ?>.</p>
            </form>
        <?php endif; ?>
        <?php if ($pilihan['rows'] === [] && $q === ''): ?>
            <?= ah_empty('Belum ada santri aktif', 'Kunjungan hanya dapat dicatat untuk santri aktif.') ?>
        <?php else: ?>
        <form method="post" action="<?= ah_e(kesehatan_url('kesehatan_buat.php', $q !== '' ? ['q' => $q] : [])) ?>" id="form-kunjungan">
            <?= ah_csrf() ?>
            <input type="hidden" name="idempotency_key" value="<?= ah_e($kunci) ?>">
            <div class="mb-3">
                <label class="form-label" for="santri_id">Santri <span class="text-danger" aria-hidden="true">*</span></label>
                <input class="form-control mb-2" type="search" hidden data-ah-option-filter="santri_id" placeholder="Ketik nama atau NIS untuk menyaring" aria-label="Saring daftar santri" aria-describedby="santri-filter-status" autocomplete="off">
                <span class="ah-visually-hidden" id="santri-filter-status" aria-live="polite"></span>
                <select class="form-select" id="santri_id" name="santri_id" required>
                    <option value="">— Pilih santri (<?= (int) $pilihan['total'] ?>) —</option>
                    <?php foreach ($pilihan['rows'] as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"<?= $s['id'] === $santriTerpilih ? ' selected' : '' ?>><?= ah_e($s['nama'] . ' · ' . $s['nis']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="waktu_kunjungan">Waktu kunjungan <span class="text-danger" aria-hidden="true">*</span></label>
                <input class="form-control" type="datetime-local" id="waktu_kunjungan" name="waktu_kunjungan" required max="<?= ah_e(date('Y-m-d\TH:i')) ?>" value="<?= ah_e($nilai('waktu_kunjungan', date('Y-m-d\TH:i'))) ?>">
                <p class="form-text mb-0">Tidak boleh di masa depan.</p>
            </div>
            <div class="mb-3">
                <label class="form-label" for="keluhan">Keluhan <span class="text-danger" aria-hidden="true">*</span></label>
                <textarea class="form-control" id="keluhan" name="keluhan" rows="3" required maxlength="1000"><?= ah_e($nilai('keluhan')) ?></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label" for="catatan_klinis">Catatan klinis <span class="text-muted fw-normal">(opsional, internal)</span></label>
                <textarea class="form-control" id="catatan_klinis" name="catatan_klinis" rows="4" maxlength="4000"><?= ah_e($nilai('catatan_klinis')) ?></textarea>
                <p class="form-text mb-0">Hanya dibaca petugas kesehatan, admin, serta pembimbing/murobi santri terkait. Tidak tampil kepada orang tua.</p>
            </div>
            <div class="mb-3">
                <label class="form-label" for="tindakan">Tindakan yang dilakukan <span class="text-muted fw-normal">(opsional)</span></label>
                <textarea class="form-control" id="tindakan" name="tindakan" rows="3" maxlength="2000"><?= ah_e($nilai('tindakan')) ?></textarea>
            </div>
            <?php kesehatan_form_rincian(kesehatan_form_baris($isian['obat'] ?? []), kesehatan_form_baris($isian['rujukan'] ?? []), kesehatan_form_baris($isian['tindak_lanjut'] ?? []),
                $isian === [] ? ['obat' => 3, 'rujukan' => 1, 'tindak_lanjut' => 2] : []); ?>
            <div class="ah-actbar mt-4 mb-0">
                <a class="btn btn-outline-secondary" href="<?= ah_e(kesehatan_url('kesehatan.php')) ?>">Batal</a>
                <button class="btn btn-outline-primary" type="submit" name="status" value="Draf" data-busy-label="Menyimpan…">Simpan sebagai draf</button>
                <button class="btn btn-primary" type="submit" name="status" value="Tercatat" data-busy-label="Menyimpan…">Simpan kunjungan</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</section>
<?php
ah_page_close();
