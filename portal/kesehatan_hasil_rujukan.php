<?php

declare(strict_types=1);

use App\Kesehatan\KesehatanException;
use App\Kesehatan\KesehatanService;
use App\Ui\Denial;

/**
 * Catat hasil satu rujukan (PRD V5 Fase 2).
 *
 * Hanya petugas kesehatan berpenugasan aktif. Pencatatan hasil memakai jalur
 * koreksi berversi yang sama (`KesehatanService::hasilRujukan()`): versi
 * kunjungan naik satu, versi sebelumnya tetap dapat dibaca, alasan wajib bila
 * kunjungan sudah Tercatat, dan versi lama yang dibawa formulir ditolak 409.
 */

require_once __DIR__ . '/_kesehatan.php';

if (!$aksesKesehatan['catat']) {
    Denial::page('Catat hasil rujukan', 'pengurus dengan penugasan kesehatan aktif',
        $aksesKesehatan['baca'] ? 'Akun ini hanya membaca catatan kesehatan dan tidak mengubah isi klinis.' : '');
}

$id = max(0, (int) ($_GET['id'] ?? 0));
$rujukanId = max(0, (int) ($_GET['rujukan'] ?? 0));
$galat = null;
$isian = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $hasil = $kesehatan->hasilRujukan($currentUser, $id, $rujukanId, $_POST, KesehatanService::KANAL_WEB);
        ah_flash_set('success', $hasil['replayed'] ? 'Hasil rujukan ini sudah tersimpan sebelumnya.' : 'Hasil rujukan tersimpan sebagai versi ' . $hasil['versi'] . '.');
        ah_redirect(kesehatan_url('kesehatan_detail.php', ['id' => $hasil['id']]));
    } catch (KesehatanException $exception) {
        if ($exception->status === 403) {
            Denial::page('Catat hasil rujukan', 'pengurus dengan penugasan kesehatan aktif', $exception->getMessage());
        }
        http_response_code($exception->status);
        $galat = $exception->getMessage();
        $isian = $exception->status === 409 ? [] : $_POST;
    }
}

try {
    $k = $kesehatan->detail($currentUser, $id, KesehatanService::KANAL_WEB);
} catch (KesehatanException $exception) {
    Denial::render('Kunjungan tidak ditemukan', 'Kunjungan yang Anda buka tidak ada.', 404, '<a class="btn btn-outline-primary" href="' . ah_e(kesehatan_url('kesehatan.php')) . '">Kembali ke daftar</a>', false);
}
$rujukan = null;
foreach ($k['rujukan'] as $r) {
    if ($r['id'] === $rujukanId) {
        $rujukan = $r;
    }
}
if ($rujukan === null) {
    Denial::render('Rujukan tidak ditemukan', 'Rujukan ini tidak ada pada kunjungan tersebut.', 404, '<a class="btn btn-outline-primary" href="' . ah_e(kesehatan_url('kesehatan_detail.php', ['id' => $k['id']])) . '">Kembali ke detail</a>', false);
}
$nilai = static fn (string $kolom, string $bawaan = ''): string => is_string($isian[$kolom] ?? null) ? (string) $isian[$kolom] : $bawaan;
$kunci = preg_match('/^[A-Za-z0-9._:-]{8,100}$/D', $nilai('idempotency_key')) === 1 ? $nilai('idempotency_key') : bin2hex(random_bytes(16));
$draf = $k['status'] === KesehatanService::STATUS_DRAF;

ah_page_open([
    'title' => 'Hasil rujukan ' . $k['santri']['nama'],
    'heading' => 'Catat hasil rujukan',
    'user' => $currentUser,
    'active' => 'kesehatan',
    'description' => $k['santri']['nama'] . ' · ' . ah_tanggal($rujukan['tanggal_rujukan']),
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Kesehatan', 'url' => kesehatan_url('kesehatan.php')],
        ['label' => 'Detail kunjungan', 'url' => kesehatan_url('kesehatan_detail.php', ['id' => $k['id']])], ['label' => 'Hasil rujukan']],
]);
if ($galat !== null) {
    ah_note('danger', 'Hasil belum tersimpan. ' . $galat);
}
?>
<section class="ah-card" aria-labelledby="ah-hasil-rujukan">
    <div class="ah-card__head"><h2 class="h6 mb-0 ah-break" id="ah-hasil-rujukan"><?= ah_e($rujukan['tujuan']) ?></h2><?= kesehatan_badge($rujukan['status']) ?></div>
    <div class="ah-card__body">
        <?php if ($rujukan['status'] !== KesehatanService::RUJUKAN_MENUNGGU): ?>
            <p class="mb-0">Hasil rujukan ini sudah tercatat. Untuk mengubahnya, gunakan <a href="<?= ah_e(kesehatan_url('kesehatan_koreksi.php', ['id' => $k['id']])) ?>">koreksi kunjungan</a>.</p>
        <?php else: ?>
        <form method="post" action="<?= ah_e(kesehatan_url('kesehatan_hasil_rujukan.php', ['id' => $k['id'], 'rujukan' => $rujukan['id']])) ?>">
            <?= ah_csrf() ?>
            <input type="hidden" name="idempotency_key" value="<?= ah_e($kunci) ?>">
            <input type="hidden" name="versi" value="<?= (int) $k['versi'] ?>">
            <div class="mb-3">
                <label class="form-label" for="hasil">Hasil rujukan <span class="text-danger" aria-hidden="true">*</span></label>
                <textarea class="form-control" id="hasil" name="hasil" rows="4" required maxlength="4000"><?= ah_e($nilai('hasil')) ?></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label" for="tanggal_hasil">Tanggal hasil</label>
                <input class="form-control" type="date" id="tanggal_hasil" name="tanggal_hasil" min="<?= ah_e($rujukan['tanggal_rujukan']) ?>" max="<?= ah_e(date('Y-m-d')) ?>" value="<?= ah_e($nilai('tanggal_hasil', date('Y-m-d'))) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="alasan">Alasan pembaruan<?= $draf ? ' <span class="text-muted fw-normal">(opsional untuk draf)</span>' : ' <span class="text-danger" aria-hidden="true">*</span>' ?></label>
                <textarea class="form-control" id="alasan" name="alasan" rows="2" maxlength="1000"<?= $draf ? '' : ' required minlength="5"' ?>><?= ah_e($nilai('alasan', 'Hasil rujukan diterima.')) ?></textarea>
                <p class="form-text mb-0">Versi <?= (int) $k['versi'] ?> tetap tersimpan di Riwayat versi.</p>
            </div>
            <div class="ah-actbar mt-3 mb-0">
                <a class="btn btn-outline-secondary" href="<?= ah_e(kesehatan_url('kesehatan_detail.php', ['id' => $k['id']])) ?>">Batal</a>
                <button class="btn btn-primary" type="submit" data-busy-label="Menyimpan…">Simpan hasil</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</section>
<?php
ah_page_close();
