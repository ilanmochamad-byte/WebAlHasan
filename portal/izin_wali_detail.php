<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Izin\IzinException;
use App\Izin\IzinWaliService;

require_once __DIR__ . '/_izin_wali.php';

/**
 * Rincian pengajuan izin wali (PRD V7 Fase 1).
 *
 * Isi yang tampil ditentukan `IzinWaliService::detail()` per hak akun: admin
 * dan pembimbing tujuan yang masih sah melihat isi lengkap; wali pengaju
 * melihat isi dan peristiwa; wali lain hanya status ringkas; selain itu 404.
 * Admin dapat menetapkan pembimbing sah (POST + CSRF, versi data, kunci
 * idempotensi); tombol hanya cermin hak — server memeriksa ulang.
 */

$mode = (string) portal_requested_mode($currentUser, $userCapabilities);
$svc = izin_wali_service();
$id = (int) ($_GET['id'] ?? ($_POST['pengajuan_id'] ?? 0));

$galatPenetapan = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['aksi'] ?? '') !== 'tetapkan_pembimbing' || $mode !== Capabilities::ADMIN) {
        izin_wali_tolak(IzinException::forbidden('Tindakan ini hanya untuk admin.'), $currentUser, $userCapabilities, $mode);
    }
    try {
        $hasil = $svc->tetapkanPembimbing(
            $currentUser,
            $id,
            $_POST['pengurus_id'] ?? null,
            is_string($_POST['alasan'] ?? null) ? $_POST['alasan'] : null,
            $_POST['version'] ?? null,
            is_string($_POST['idempotency_key'] ?? null) ? $_POST['idempotency_key'] : null,
            portal_request_meta()
        );
        portal_flash_set('sukses', $hasil['idempotent_replay']
            ? 'Penetapan pembimbing sudah tercatat sebelumnya; kiriman ulang tidak mengubah apa pun.'
            : 'Pembimbing ditetapkan. Pengajuan masuk antrean pembimbing tersebut.');
        header('Location: ' . izin_wali_detail_url($id), true, 303);
        exit;
    } catch (IzinException $exception) {
        if (!in_array($exception->status(), [409, 422], true)) {
            izin_wali_tolak($exception, $currentUser, $userCapabilities, $mode);
        }
        http_response_code($exception->status());
        $galatPenetapan = $exception->getMessage();
    }
}

try {
    $detail = $svc->detail($currentUser, $id);
    $kandidat = ($detail['tindakan']['tetapkan_pembimbing'] ?? false) && $mode === Capabilities::ADMIN
        ? $svc->kandidatPembimbing($currentUser, $id) : [];
} catch (IzinException $exception) {
    izin_wali_tolak($exception, $currentUser, $userCapabilities, $mode);
}

$p = $detail['pengajuan'];
$peranAkses = (string) $detail['peran_akses'];
$wali = $peranAkses === IzinWaliService::PERAN_WALI;

portal_header('Pengajuan wali #' . (int) $p['id'], $userCapabilities, $mode, $currentUser, [
    'active' => $wali ? 'izin_anak' : 'izin',
    'description' => $p['santri']['nama'] . ' · ' . ah_rentang($p['tgl_izin'], $p['tgl_kembali']),
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        $wali ? ['label' => 'Izin anak', 'url' => app_url('/portal/izin.php')] : ['label' => 'Perizinan', 'url' => app_url('/portal/izin_ringkasan.php')],
        ['label' => 'Pengajuan wali', 'url' => app_url('/portal/izin_wali.php')],
        ['label' => '#' . (int) $p['id']],
    ],
]);
portal_flash_render();
if ($galatPenetapan !== null) {
    ah_note('danger', 'Penetapan belum tersimpan.', '<p class="mb-0 mt-1">' . portal_e($galatPenetapan) . '</p>');
}
?>
<div class="ah-split">
    <section class="ah-card" aria-labelledby="ah-rincian-wali">
        <div class="ah-card__head"><span id="ah-rincian-wali">Rincian</span><?= izin_wali_status((string) $p['status']) ?></div>
        <div class="ah-card__body">
            <dl class="ah-info-list">
                <div><dt>Santri</dt><dd><?= portal_e($p['santri']['nama'] . ' · ' . $p['santri']['nis']) ?></dd></div>
                <div><dt>Rentang izin</dt><dd><?= portal_e(ah_rentang($p['tgl_izin'], $p['tgl_kembali'])) ?></dd></div>
                <div><dt>Tahap</dt><dd><?= portal_e(IzinWaliService::statusLabel((string) $p['status'])) ?></dd></div>
                <div><dt>Diajukan</dt><dd><?= portal_e(ah_waktu((string) $p['diajukan_pada'])) ?><?= $wali ? ' oleh ' . portal_e($p['pengaju']) : ' oleh ' . portal_e((string) ($p['wali']['nama'] ?? '—')) ?></dd></div>
                <?php if (!$wali || !$p['ringkas']): ?>
                    <div><dt>Alasan</dt><dd><?= nl2br(portal_e((string) $p['alasan'])) ?></dd></div>
                    <div><dt>Catatan</dt><dd><?= $p['catatan'] === null ? '—' : nl2br(portal_e((string) $p['catatan'])) ?></dd></div>
                <?php endif; ?>
                <?php if (!$wali): ?>
                    <div><dt>Pembimbing tujuan</dt><dd><?= portal_e($p['pembimbing']['nama'] ?? 'Belum ditetapkan') ?>
                        <?php if ($p['perlu_admin']): ?> <?= ah_badge('Perlu penetapan admin', 'warn') ?><?php endif; ?></dd></div>
                    <div><dt>Routing</dt><dd><?= portal_e((string) ($p['pembimbing']['routing_catatan'] ?? '—')) ?></dd></div>
                    <div><dt>Versi data</dt><dd><?= (int) $p['version'] ?></dd></div>
                <?php elseif (!$p['ringkas'] && ($p['pembimbing'] ?? null) !== null): ?>
                    <div><dt>Pembimbing</dt><dd><?= portal_e((string) $p['pembimbing']) ?></dd></div>
                <?php endif; ?>
            </dl>
            <?php if ($wali && $p['ringkas']): ?>
                <?php ah_note('info', 'Pengajuan ini dibuat wali lain. Anda hanya melihat statusnya.'); ?>
            <?php endif; ?>
        </div>
    </section>

    <aside>
        <?php if ($kandidat !== [] || ($detail['tindakan']['tetapkan_pembimbing'] ?? false) && $mode === Capabilities::ADMIN): ?>
            <section class="ah-card" aria-labelledby="ah-penetapan">
                <div class="ah-card__head"><span id="ah-penetapan">Tetapkan pembimbing</span></div>
                <div class="ah-card__body">
                    <?php if ($kandidat === []): ?>
                        <?php ah_note('warning', 'Belum ada pembimbing sah untuk santri ini.', '<p class="mb-0 mt-1">Buat penugasan pembimbing aktif untuk kamar/kelas santri di Pusat Penugasan, pastikan pengurusnya memiliki akun aktif, lalu kembali ke halaman ini.</p>'); ?>
                    <?php else: ?>
                        <form method="post" action="<?= portal_e(app_url('/portal/izin_wali_detail.php') . '?id=' . (int) $p['id']) ?>">
                            <?= portal_csrf() ?>
                            <input type="hidden" name="aksi" value="tetapkan_pembimbing">
                            <input type="hidden" name="mode" value="<?= portal_e($mode) ?>">
                            <input type="hidden" name="pengajuan_id" value="<?= (int) $p['id'] ?>">
                            <input type="hidden" name="version" value="<?= (int) $p['version'] ?>">
                            <input type="hidden" name="idempotency_key" value="<?= portal_e(is_string($_POST['idempotency_key'] ?? null) && $galatPenetapan !== null ? $_POST['idempotency_key'] : portal_idempotency_key()) ?>">
                            <div class="mb-3">
                                <label class="form-label" for="pengurus_id">Pembimbing sah</label>
                                <select class="form-select" id="pengurus_id" name="pengurus_id" required>
                                    <option value="">— Pilih —</option>
                                    <?php foreach ($kandidat as $calon): ?>
                                        <option value="<?= (int) $calon['pengurus_id'] ?>"><?= portal_e($calon['nama'] . ' · ' . implode(', ', $calon['targets'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="form-text mb-0">Hanya pengurus dengan penugasan pembimbing aktif atas kamar/kelas santri dan akun aktif.</p>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="alasan_penetapan">Alasan penetapan</label>
                                <textarea class="form-control" id="alasan_penetapan" name="alasan" rows="2" required minlength="3" maxlength="1000"></textarea>
                            </div>
                            <button class="btn btn-primary" type="submit" data-busy-label="Menyimpan…">Tetapkan pembimbing</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($detail['riwayat'] !== []): ?>
            <section class="ah-card" aria-labelledby="ah-riwayat-wali">
                <div class="ah-card__head"><span id="ah-riwayat-wali">Riwayat</span></div>
                <div class="ah-card__body"><ol class="list-unstyled mb-0">
                    <?php foreach ($detail['riwayat'] as $r): ?>
                        <li class="mb-2"><b><?= portal_e(portal_peristiwa_label((string) $r['peristiwa'])) ?></b>
                            <span class="d-block small text-muted"><?= portal_e(ah_waktu((string) $r['waktu'])) ?><?= isset($r['pelaku']) && $r['pelaku'] !== null ? ' · ' . portal_e((string) $r['pelaku']) : '' ?></span>
                            <?php if (!empty($r['alasan'])): ?><span class="d-block small"><?= portal_e((string) $r['alasan']) ?></span><?php endif; ?></li>
                    <?php endforeach; ?>
                </ol></div>
            </section>
        <?php endif; ?>

        <?php if (($detail['penetapan'] ?? []) !== []): ?>
            <section class="ah-card" aria-labelledby="ah-riwayat-penetapan">
                <div class="ah-card__head"><span id="ah-riwayat-penetapan">Riwayat penetapan pembimbing</span></div>
                <div class="ah-card__body"><ol class="list-unstyled mb-0">
                    <?php foreach ($detail['penetapan'] as $t): ?>
                        <li class="mb-2"><b><?= portal_e(($t['pembimbing_sebelum'] ?? 'Belum ada') . ' → ' . $t['pembimbing']) ?></b>
                            <span class="d-block small text-muted"><?= portal_e(ah_waktu((string) $t['ditetapkan_pada']) . ' · ' . (string) ($t['penetap'] ?? '—') . ' · versi ' . $t['versi_sebelum'] . '→' . $t['versi_sesudah']) ?></span>
                            <span class="d-block small"><?= portal_e((string) $t['alasan']) ?></span></li>
                    <?php endforeach; ?>
                </ol></div>
            </section>
        <?php endif; ?>
    </aside>
</div>
<?php portal_footer(); ?>
