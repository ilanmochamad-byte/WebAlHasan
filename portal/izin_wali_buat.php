<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Izin\IzinException;

require_once __DIR__ . '/_izin_wali.php';

/**
 * Formulir pengajuan izin oleh wali (PRD V7 Fase 1).
 *
 * Isian sama dengan formulir V2 — tanggal izin, tanggal kembali, alasan,
 * catatan — tanpa lampiran. Daftar anak hanya kenyamanan: relasi wali aktif
 * diperiksa ulang `IzinWaliService::buat()` di dalam transaksi. Kunci
 * idempotensi dibuat saat formulir dirender dan dipertahankan ketika
 * formulir dirender ulang sesudah galat, sehingga klik ganda atau kirim ulang
 * hanya menghasilkan satu pengajuan.
 */

$mode = (string) portal_requested_mode($currentUser, $userCapabilities);
if ($mode !== Capabilities::ORANG_TUA) {
    izin_wali_tolak(IzinException::forbidden('Pengajuan izin wali hanya dapat dibuat dari peran orang tua/wali.'), $currentUser, $userCapabilities, $mode);
}
$svc = izin_wali_service();

$galat = null;
$isian = ['santri_id' => (string) ($_GET['santri_id'] ?? ''), 'tgl_izin' => date('Y-m-d'), 'tgl_kembali' => date('Y-m-d'), 'alasan' => '', 'catatan' => ''];
$kunci = portal_idempotency_key();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($isian) as $kolom) {
        $isian[$kolom] = is_string($_POST[$kolom] ?? null) ? (string) $_POST[$kolom] : '';
    }
    $kunci = is_string($_POST['idempotency_key'] ?? null) ? (string) $_POST['idempotency_key'] : '';
    try {
        $hasil = $svc->buat($currentUser, $isian, $kunci, portal_request_meta());
        portal_flash_set('sukses', $hasil['idempotent_replay']
            ? 'Pengajuan #' . (int) $hasil['id'] . ' sudah tersimpan sebelumnya. Kiriman ulang tidak membuat pengajuan baru.'
            : 'Pengajuan #' . (int) $hasil['id'] . ' terkirim dan menunggu '
                . ($hasil['pembimbing_ditetapkan'] ? 'tinjauan pembimbing.' : 'penetapan pembimbing oleh admin.'));
        header('Location: ' . izin_wali_detail_url((int) $hasil['id']), true, 303);
        exit;
    } catch (IzinException $exception) {
        if (!in_array($exception->status(), [409, 422], true)) {
            izin_wali_tolak($exception, $currentUser, $userCapabilities, $mode);
        }
        http_response_code($exception->status());
        $galat = $exception->getMessage();
    }
}

try {
    $anak = $svc->anak($currentUser);
} catch (IzinException $exception) {
    izin_wali_tolak($exception, $currentUser, $userCapabilities, $mode);
}

portal_header('Ajukan izin anak', $userCapabilities, $mode, $currentUser, [
    'active' => 'izin_anak',
    'description' => 'Pengajuan ditinjau pembimbing lebih dahulu, kemudian diputus murobi.',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Izin anak', 'url' => app_url('/portal/izin.php')],
        ['label' => 'Pengajuan wali', 'url' => app_url('/portal/izin_wali.php')],
        ['label' => 'Ajukan izin'],
    ],
]);
if ($galat !== null) {
    ah_note('danger', 'Pengajuan belum terkirim.', '<p class="mb-0 mt-1">' . portal_e($galat) . '</p>');
}
?>
<section class="ah-card" aria-labelledby="ah-form-wali">
    <div class="ah-card__head"><span id="ah-form-wali">Rincian izin</span></div>
    <div class="ah-card__body">
        <?php if ($anak === []): ?>
            <?= ah_empty('Belum ada anak yang dapat diajukan', 'Akun ini belum terhubung dengan santri aktif. Hubungi admin pesantren untuk menghubungkan akun wali.') ?>
        <?php else: ?>
            <form method="post" action="<?= portal_e(app_url('/portal/izin_wali_buat.php')) ?>" id="form-izin-wali">
                <?= portal_csrf() ?>
                <input type="hidden" name="mode" value="<?= portal_e($mode) ?>">
                <input type="hidden" name="idempotency_key" value="<?= portal_e($kunci) ?>">
                <div class="mb-3">
                    <label class="form-label" for="santri_id">Anak <span class="text-danger">*</span></label>
                    <select class="form-select" id="santri_id" name="santri_id" required>
                        <option value="">— Pilih anak —</option>
                        <?php foreach ($anak as $item): ?>
                            <option value="<?= (int) $item['id'] ?>"<?= (string) $item['id'] === $isian['santri_id'] || count($anak) === 1 ? ' selected' : '' ?>><?= portal_e($item['nama'] . ' · ' . $item['nis']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label" for="tgl_izin">Tanggal izin <span class="text-danger">*</span></label>
                        <input class="form-control" type="date" id="tgl_izin" name="tgl_izin" value="<?= portal_e($isian['tgl_izin']) ?>" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="tgl_kembali">Tanggal kembali <span class="text-danger">*</span></label>
                        <input class="form-control" type="date" id="tgl_kembali" name="tgl_kembali" value="<?= portal_e($isian['tgl_kembali']) ?>" required>
                        <p class="form-text mb-0">Tidak boleh mendahului tanggal izin.</p>
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label" for="alasan">Alasan <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="alasan" name="alasan" rows="3" required minlength="3" maxlength="2000"><?= portal_e($isian['alasan']) ?></textarea>
                    <p class="form-text mb-0">Alasan hanya terlihat oleh Anda, pembimbing, murobi, dan admin — tidak oleh wali lain.</p>
                </div>
                <div class="mt-3">
                    <label class="form-label" for="catatan">Catatan <span class="text-muted">(opsional)</span></label>
                    <textarea class="form-control" id="catatan" name="catatan" rows="2" maxlength="1000"><?= portal_e($isian['catatan']) ?></textarea>
                </div>
                <div class="ah-actbar mt-4 mb-0">
                    <a class="btn btn-outline-secondary" href="<?= portal_e(app_url('/portal/izin_wali.php')) ?>">Batal</a>
                    <button class="btn btn-primary" type="submit" data-busy-label="Mengirim…">Kirim pengajuan</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php portal_footer(); ?>
