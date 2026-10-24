<?php

declare(strict_types=1);

use App\Kesehatan\KesehatanException;
use App\Kesehatan\KesehatanService;
use App\Ui\Denial;

/**
 * Koreksi berversi satu kunjungan klinik (PRD V5 Fase 2).
 *
 * Hanya petugas kesehatan berpenugasan aktif; admin, pembimbing, murobi, dan
 * akun lain ditolak di server (`KesehatanService::koreksi()`). Formulir
 * membawa versi yang sedang dilihat (`versi`) dan kunci idempotensi:
 *   - kunjungan Tercatat: alasan wajib; hasilnya versi baru, versi lama tetap
 *     dapat dibaca di Riwayat versi;
 *   - kunjungan Draf: alasan boleh kosong; "Tetapkan sebagai tercatat"
 *     membuat kunjungan tampil kepada pembimbing/murobi terkait;
 *   - bila petugas lain sudah menyimpan versi lebih baru, server menolak 409
 *     tanpa menimpa versi itu. Isian dirender ulang dari POST (tidak disimpan
 *     ke sesi) agar dapat disalin sebelum memuat ulang.
 */

require_once __DIR__ . '/_kesehatan.php';
require_once __DIR__ . '/_kesehatan_form.php';

if (!$aksesKesehatan['catat']) {
    Denial::page('Koreksi kunjungan klinik', 'pengurus dengan penugasan kesehatan aktif',
        $aksesKesehatan['baca'] ? 'Akun ini hanya membaca catatan kesehatan dan tidak mengubah isi klinis.' : '');
}

$id = max(0, (int) ($_GET['id'] ?? 0));
$galat = null;
$konflik = false;
$isian = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $hasil = $kesehatan->koreksi($currentUser, $id, $_POST, KesehatanService::KANAL_WEB);
        ah_flash_set('success', $hasil['replayed']
            ? 'Koreksi ini sudah tersimpan sebelumnya (versi ' . $hasil['versi'] . '). Tidak ada versi ganda.'
            : 'Koreksi tersimpan sebagai versi ' . $hasil['versi'] . '.');
        ah_redirect(kesehatan_url('kesehatan_detail.php', ['id' => $hasil['id']]));
    } catch (KesehatanException $exception) {
        if ($exception->status === 403) {
            Denial::page('Koreksi kunjungan klinik', 'pengurus dengan penugasan kesehatan aktif', $exception->getMessage());
        }
        http_response_code($exception->status);
        $galat = $exception->getMessage();
        $konflik = $exception->status === 409;
        $isian = $_POST;
    }
}

try {
    $k = $kesehatan->detail($currentUser, $id, KesehatanService::KANAL_WEB);
} catch (KesehatanException $exception) {
    if ($exception->status === 404) {
        Denial::render('Kunjungan tidak ditemukan', 'Kunjungan yang Anda buka tidak ada.', 404, '<a class="btn btn-outline-primary" href="' . ah_e(kesehatan_url('kesehatan.php')) . '">Kembali ke daftar</a>', false);
    }
    Denial::page('Koreksi kunjungan klinik', 'pengurus dengan penugasan kesehatan aktif', $exception->getMessage(), $exception->status);
}

$dariPost = $isian !== [];
$nilai = static function (string $kolom, ?string $tersimpan) use ($isian, $dariPost): string {
    if ($dariPost) {
        return is_string($isian[$kolom] ?? null) ? (string) $isian[$kolom] : '';
    }

    return (string) $tersimpan;
};
$versi = $dariPost && is_string($isian['versi'] ?? null) && ctype_digit($isian['versi']) ? (int) $isian['versi'] : (int) $k['versi'];
$kunci = preg_match('/^[A-Za-z0-9._:-]{8,100}$/D', $nilai('idempotency_key', '')) === 1 && !$konflik ? $nilai('idempotency_key', '') : bin2hex(random_bytes(16));
$draf = $k['status'] === KesehatanService::STATUS_DRAF;
$tersimpan = static fn (array $rows): array => array_map(static fn (array $r): array => array_map(static fn (mixed $v): string => $v === null ? '' : (string) $v, $r), $rows);

ah_page_open([
    'title' => ($draf ? 'Lanjutkan draf ' : 'Koreksi kunjungan ') . $k['santri']['nama'],
    'heading' => $draf ? 'Lanjutkan draf kunjungan' : 'Koreksi kunjungan',
    'user' => $currentUser,
    'active' => 'kesehatan',
    'description' => $k['santri']['nama'] . ' · NIS ' . $k['santri']['nis'] . ' · versi ' . (int) $k['versi'],
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Kesehatan', 'url' => kesehatan_url('kesehatan.php')],
        ['label' => 'Detail kunjungan', 'url' => kesehatan_url('kesehatan_detail.php', ['id' => $k['id']])], ['label' => $draf ? 'Lanjutkan draf' : 'Koreksi']],
]);
if ($galat !== null) {
    ah_note('danger', ($konflik ? 'Koreksi tidak disimpan. ' : 'Koreksi belum tersimpan. ') . $galat);
    if ($konflik) {
        echo '<p><a class="btn btn-outline-primary" href="' . ah_e(kesehatan_url('kesehatan_koreksi.php', ['id' => $k['id']])) . '">Muat versi terbaru</a></p>';
    }
}
?>
<div class="ah-ribbon" role="note"><i class="fas fa-clock-rotate-left mt-1" aria-hidden="true"></i>
    <span><?= $draf
        ? 'Draf belum tampil kepada pembimbing/murobi. Setiap penyimpanan tetap tercatat sebagai versi.'
        : 'Koreksi tidak menghapus catatan lama: versi ' . (int) $k['versi'] . ' tetap tersimpan dan dapat dibaca di Riwayat versi. Alasan wajib diisi.' ?></span></div>
<form method="post" action="<?= ah_e(kesehatan_url('kesehatan_koreksi.php', ['id' => $k['id']])) ?>" id="form-koreksi">
    <?= ah_csrf() ?>
    <input type="hidden" name="idempotency_key" value="<?= ah_e($kunci) ?>">
    <input type="hidden" name="versi" value="<?= (int) $versi ?>">
    <section class="ah-card" aria-labelledby="ah-koreksi-utama">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-koreksi-utama">Kunjungan</h2><?= ah_status($k['status']) ?></div>
        <div class="ah-card__body">
            <p class="mb-3"><b><?= ah_e($k['santri']['nama']) ?></b> <span class="text-muted">· NIS <?= ah_e($k['santri']['nis']) ?></span></p>
            <div class="mb-3">
                <label class="form-label" for="waktu_kunjungan">Waktu kunjungan <span class="text-danger" aria-hidden="true">*</span></label>
                <input class="form-control" type="datetime-local" id="waktu_kunjungan" name="waktu_kunjungan" required max="<?= ah_e(date('Y-m-d\TH:i')) ?>"
                    value="<?= ah_e($dariPost ? $nilai('waktu_kunjungan', null) : kesehatan_input_waktu($k['waktu_kunjungan'])) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="keluhan">Keluhan <span class="text-danger" aria-hidden="true">*</span></label>
                <textarea class="form-control" id="keluhan" name="keluhan" rows="3" required maxlength="1000"><?= ah_e($nilai('keluhan', $k['keluhan'])) ?></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label" for="catatan_klinis">Catatan klinis <span class="text-muted fw-normal">(opsional, internal)</span></label>
                <textarea class="form-control" id="catatan_klinis" name="catatan_klinis" rows="4" maxlength="4000"><?= ah_e($nilai('catatan_klinis', $k['catatan_klinis'])) ?></textarea>
            </div>
            <div class="mb-4">
                <label class="form-label" for="tindakan">Tindakan yang dilakukan <span class="text-muted fw-normal">(opsional)</span></label>
                <textarea class="form-control" id="tindakan" name="tindakan" rows="3" maxlength="2000"><?= ah_e($nilai('tindakan', $k['tindakan'])) ?></textarea>
            </div>
            <?php kesehatan_form_rincian(
                $dariPost ? kesehatan_form_baris($isian['obat'] ?? []) : $tersimpan($k['obat']),
                $dariPost ? kesehatan_form_baris($isian['rujukan'] ?? []) : $tersimpan($k['rujukan']),
                $dariPost ? kesehatan_form_baris($isian['tindak_lanjut'] ?? []) : $tersimpan($k['tindak_lanjut']),
                $dariPost ? [] : ['obat' => 2, 'rujukan' => 1, 'tindak_lanjut' => 1]
            ); ?>
            <div class="mb-0">
                <label class="form-label" for="alasan">Alasan koreksi<?= $draf ? ' <span class="text-muted fw-normal">(opsional untuk draf)</span>' : ' <span class="text-danger" aria-hidden="true">*</span>' ?></label>
                <textarea class="form-control" id="alasan" name="alasan" rows="2" maxlength="1000"<?= $draf ? '' : ' required minlength="5"' ?> aria-describedby="alasan-bantu"><?= ah_e($nilai('alasan', '')) ?></textarea>
                <p class="form-text mb-0" id="alasan-bantu">Hanya dibaca petugas kesehatan dan admin di Riwayat versi.</p>
            </div>
        </div>
    </section>
    <div class="ah-actbar mt-3 mb-0">
        <a class="btn btn-outline-secondary" href="<?= ah_e(kesehatan_url('kesehatan_detail.php', ['id' => $k['id']])) ?>">Batal</a>
        <?php if ($draf): ?>
            <button class="btn btn-outline-primary" type="submit" name="status" value="Draf" data-busy-label="Menyimpan…">Simpan draf</button>
            <button class="btn btn-primary" type="submit" name="status" value="Tercatat" data-busy-label="Menyimpan…">Tetapkan sebagai tercatat</button>
        <?php else: ?>
            <input type="hidden" name="status" value="Tercatat">
            <button class="btn btn-primary" type="submit" data-busy-label="Menyimpan…">Simpan koreksi</button>
        <?php endif; ?>
    </div>
</form>
<?php
ah_page_close();
