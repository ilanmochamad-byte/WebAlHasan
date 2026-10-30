<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Izin\IzinException;

require_once __DIR__ . '/_ui.php';

/**
 * Antrean tindakan per peran (Fase 2 §6, §7, §13), tampilan redesain
 * website Tahap 4 (I-3): daftar di kiri, detail dan tombol putuskan di kanan.
 *
 *   - Murobi   : pengajuan berstatus `Diajukan` yang DIARAHKAN kepadanya.
 *   - Admin    : pengajuan berstatus `Perlu Penetapan Admin` (routing tidak tunggal).
 *   - Pengurus : pengajuan miliknya yang belum diputus.
 *   - Orang tua: tidak memiliki antrean tindakan (hanya membaca status).
 *
 * Keputusan N: setelah memutuskan, detail tetap terbuka dengan pesan hasil
 * dan tombol "Berikutnya"; murobi sendiri yang pindah ke pengajuan berikut.
 * Cakupan tetap dipaksakan di server oleh IzinRepository::conditions(), dan
 * setiap keputusan diperiksa ulang `IzinWorkflowService::decide()`.
 */

$requestedMode = portal_requested_mode($currentUser, $userCapabilities);
$page = max(1, (int) ($_GET['page'] ?? 1));
$q = is_string($_GET['q'] ?? null) ? mb_substr(trim((string) $_GET['q']), 0, 100) : '';

try {
    $result = izin_service()->list($currentUser, ['antrean' => '1', 'q' => $q], $page, 20, $requestedMode);
} catch (IzinException $exception) {
    http_response_code($exception->status());
    exit(portal_e($exception->getMessage()));
}

$scope = $result['scope'];
$mode = (string) $scope['mode'];
$id = (int) ($_GET['id'] ?? 0);
$detail = null;
$galatDetail = null;
if ($id > 0) {
    try {
        $detail = izin_service()->detail($currentUser, $id, $mode);
    } catch (IzinException $exception) {
        // Di luar cakupan diperlakukan sama dengan tidak ada (403 di detail).
        http_response_code($exception->status());
        $galatDetail = $exception->getMessage();
    }
}

$judul = match ($mode) {
    Capabilities::MUROBI => 'Menunggu keputusan Anda',
    Capabilities::ADMIN => 'Menunggu penetapan murobi',
    Capabilities::PENGURUS => 'Pengajuan Anda yang belum diputus',
    default => 'Antrean izin',
};
$terlama = null;
foreach ($result['rows'] as $row) {
    $waktu = (string) ($row['diajukan_pada'] ?? $row['created_at'] ?? '');
    if ($waktu !== '' && ($terlama === null || $waktu < $terlama)) {
        $terlama = $waktu;
    }
}
$berikutnya = null;
foreach ($result['rows'] as $row) {
    if ((int) $row['id'] !== $id) {
        $berikutnya = $row;
        break;
    }
}
$antreanUrl = static fn (array $replace = []): string => app_url('/portal/izin_antrean.php') . '?' . ah_query(array_merge(['mode' => $mode], $replace));

portal_header($judul, $userCapabilities, $mode, $currentUser, [
    'description' => (int) $result['total'] . ' pengajuan' . ($terlama !== null ? ' · terlama ' . portal_lama($terlama) : ''),
    'active' => $mode === Capabilities::MUROBI ? 'antrean' : 'izin',
]);
portal_flash_render();
portal_mode_switcher($userCapabilities, $mode, app_url('/portal/izin_antrean.php'));

if ($mode === Capabilities::ORANG_TUA) {
    ah_note('info', 'Orang tua hanya dapat melihat status dan riwayat izin anak, tanpa tombol tindakan.',
        '<p class="mb-0 mt-2"><a href="' . portal_e(app_url('/portal/izin.php')) . '">Buka Izin anak</a></p>');
    portal_footer();
    exit;
}
?>
<div class="ah-split ah-split--list<?= $id > 0 ? ' has-detail' : '' ?>">
    <section class="ah-card ah-split__list" aria-labelledby="ah-antrean-daftar">
        <div class="ah-card__head"><span id="ah-antrean-daftar">Antrean</span><span class="text-muted small"><?= (int) $result['total'] ?></span></div>
        <form method="get" class="ah-card__body pb-0 ah-no-print" role="search">
            <input type="hidden" name="mode" value="<?= portal_e($mode) ?>">
            <label class="ah-visually-hidden" for="antrean-q">Cari di antrean</label>
            <div class="d-flex gap-2"><input class="form-control" type="search" id="antrean-q" name="q" maxlength="100" value="<?= portal_e($q) ?>" placeholder="Cari nama, NIS, atau alasan">
                <button class="btn btn-outline-secondary" type="submit">Cari</button></div>
        </form>
        <?php if ($result['rows'] === []): ?>
            <div class="ah-card__body"><?= ah_empty('Antrean kosong', $q !== '' ? 'Tidak ada pengajuan yang cocok dengan pencarian.' : 'Tidak ada pengajuan yang menunggu tindakan Anda saat ini.') ?></div>
        <?php else: ?>
            <ul class="ah-qlist mt-2">
                <?php foreach ($result['rows'] as $row): $telat = portal_izin_tertunda($row); ?>
                    <li><a href="<?= portal_e($antreanUrl(['id' => (int) $row['id']])) ?>" class="<?= $telat ? 'is-late' : '' ?>"<?= (int) $row['id'] === $id ? ' aria-current="true"' : '' ?>>
                        <span class="ah-qlist__main"><b>#<?= (int) $row['id'] ?> <?= portal_e($row['nama_santri']) ?></b>
                            <small><?= portal_e(mb_strimwidth((string) $row['alasan'], 0, 40, '…')) ?> · <?= portal_e(ah_rentang((string) $row['tgl_izin'], (string) $row['tgl_kembali'], false)) ?> · <?= portal_e(portal_label((string) $row['pengurus_label'])) ?></small>
                            <?php if ($telat): ?><small><?= ah_badge('Menunggu ' . portal_lama((string) ($row['diajukan_pada'] ?? $row['created_at'])), 'warn') ?></small><?php endif; ?>
                        </span>
                        <?= portal_status((string) $row['status']) ?>
                    </a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php portal_pagination((int) $result['total'], (int) $result['page'], (int) $result['per_page']); ?>
    </section>

    <div class="ah-split__col">
        <?php if ($id > 0): ?>
            <p class="ah-only-split-sm mb-0"><a class="btn btn-sm btn-outline-secondary" href="<?= portal_e($antreanUrl(['id' => null])) ?>"><i class="fas fa-chevron-left" aria-hidden="true"></i> Kembali ke antrean</a></p>
        <?php endif; ?>
        <?php if ($galatDetail !== null): ?>
            <?php ah_note('danger', $galatDetail); ?>
        <?php elseif ($detail === null): ?>
            <section class="ah-card"><div class="ah-card__body"><?= ah_empty('Pilih pengajuan', 'Pilih satu pengajuan di daftar untuk melihat rinciannya dan memutuskan.') ?></div></section>
        <?php else:
            $izin = $detail['pengajuan'];
            $aksi = izin_workflow_service()->actionsFor($izin, $scope, (int) $currentUser['id']);
            try {
                $jumlah = izin_service()->santriSemesterCount($currentUser, (int) $izin['santri_id'], $mode);
                $jumlahTeks = $jumlah['total'] . ' pengajuan (' . $jumlah['disetujui'] . ' disetujui) · ' . $jumlah['semester'];
            } catch (IzinException) {
                $jumlahTeks = '—';
            }
            $riwayatSantri = izin_service()->list($currentUser, ['santri_id' => (int) $izin['santri_id']], 1, 6, $mode)['rows'];
            $bucket = '_portal_' . (int) $izin['id'] . '_putuskan';
            ?>
            <section class="ah-card" aria-labelledby="ah-antrean-detail">
                <div class="ah-card__head"><span id="ah-antrean-detail">#<?= (int) $izin['id'] ?> · <?= portal_e($izin['nama_santri']) ?></span><?= portal_status((string) $izin['status']) ?></div>
                <div class="ah-card__body">
                    <dl class="ah-info-list">
                        <div><dt>Rentang</dt><dd><?= portal_e(ah_rentang((string) $izin['tgl_izin'], (string) $izin['tgl_kembali'])) ?></dd></div>
                        <div><dt>Alasan</dt><dd><?= nl2br(portal_e($izin['alasan'])) ?></dd></div>
                        <?php if ($izin['catatan_pengurus'] !== null && $izin['catatan_pengurus'] !== ''): ?>
                            <div><dt>Catatan pengurus</dt><dd><?= nl2br(portal_e($izin['catatan_pengurus'])) ?></dd></div>
                        <?php endif; ?>
                        <div><dt>Pengurus</dt><dd><?= portal_e(portal_label((string) $izin['pengurus_label'])) ?></dd></div>
                        <div><dt>Izin semester ini</dt><dd><?= portal_e($jumlahTeks) ?></dd></div>
                        <div><dt>Diajukan</dt><dd><?= portal_e(ah_waktu((string) ($izin['diajukan_pada'] ?? $izin['created_at']))) ?> · menunggu <?= portal_e(portal_lama((string) ($izin['diajukan_pada'] ?? $izin['created_at']))) ?></dd></div>
                    </dl>

                    <?php if ($aksi['putuskan_murobi']): ?>
                        <form class="mt-3" method="post" action="<?= portal_e(app_url('/portal/izin_aksi.php')) ?>"
                              data-confirm="Keputusan dicatat beserta nama dan alasan Anda, lalu diteruskan kepada pengurus dan wali santri."
                              data-confirm-title="Simpan keputusan izin #<?= (int) $izin['id'] ?>?" data-confirm-ok="Simpan keputusan">
                            <?= portal_csrf() ?>
                            <input type="hidden" name="aksi" value="putuskan">
                            <input type="hidden" name="kembali" value="antrean">
                            <input type="hidden" name="mode" value="<?= portal_e($mode) ?>">
                            <input type="hidden" name="pengajuan_id" value="<?= (int) $izin['id'] ?>">
                            <input type="hidden" name="version" value="<?= (int) $izin['version'] ?>">
                            <input type="hidden" name="idempotency_key" value="<?= portal_e(portal_idempotency_key()) ?>">
                            <label class="form-label" for="alasan_keputusan">Alasan keputusan <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="alasan_keputusan" name="alasan" rows="3" required minlength="3" maxlength="2000" aria-describedby="alasan_keputusan_bantu"><?= portal_e(ah_old('alasan', null, $bucket)) ?></textarea><?= ah_field_error('alasan', $bucket) ?>
                            <p class="form-text" id="alasan_keputusan_bantu">Wajib diisi, terutama bila menolak. Wali dapat membaca alasan ini.</p>
                            <div class="ah-actbar mt-2">
                                <span class="small text-muted">Putuskan sebagai murobi</span>
                                <div class="ah-actbar__btns">
                                    <button class="btn btn-outline-danger" type="submit" name="hasil" value="Ditolak">Tolak</button>
                                    <button class="btn btn-primary" type="submit" name="hasil" value="Disetujui">Setujui</button>
                                </div>
                            </div>
                        </form>
                    <?php elseif (in_array((string) $izin['status'], ['Disetujui', 'Ditolak', 'Dibatalkan'], true)): ?>
                        <?php ah_note('ok', 'Pengajuan ini sudah ' . strtolower((string) $izin['status']) . '.',
                            $berikutnya !== null
                                ? '<p class="mb-0 mt-2"><a class="btn btn-primary" href="' . portal_e($antreanUrl(['id' => (int) $berikutnya['id']])) . '">Berikutnya: #' . (int) $berikutnya['id'] . ' ›</a></p>'
                                : '<p class="mb-0 mt-2">Tidak ada pengajuan lain yang menunggu.</p>'); ?>
                    <?php else: ?>
                        <div class="ah-acts mt-3">
                            <?php if ($aksi['tetapkan_murobi']): ?>
                                <a class="btn btn-primary" href="<?= portal_e(portal_detail_url((int) $izin['id'], $mode, ['buka' => 'tetapkan']) . '#aksi-tetapkan') ?>">Tetapkan murobi</a>
                            <?php endif; ?>
                            <a class="btn btn-outline-primary" href="<?= portal_e(portal_detail_url((int) $izin['id'], $mode)) ?>">Buka detail lengkap</a>
                        </div>
                        <?php if ($izin['routing_catatan'] !== null): ?>
                            <p class="small text-muted mt-2 mb-0"><?= portal_e($izin['routing_catatan']) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="ah-card" aria-labelledby="ah-antrean-riwayat">
                <div class="ah-card__head"><span id="ah-antrean-riwayat">Riwayat izin santri ini</span></div>
                <?php $lain = array_values(array_filter($riwayatSantri, static fn (array $r): bool => (int) $r['id'] !== (int) $izin['id'])); ?>
                <?php if ($lain === []): ?>
                    <div class="ah-card__body"><p class="text-muted mb-0">Belum ada izin lain yang dapat Anda lihat untuk santri ini.</p></div>
                <?php else: ?>
                    <ul class="ah-qlist">
                        <?php foreach (array_slice($lain, 0, 5) as $r): ?>
                            <li><a href="<?= portal_e(portal_detail_url((int) $r['id'], $mode)) ?>"><span class="ah-qlist__main"><b>#<?= (int) $r['id'] ?> · <?= portal_e(ah_rentang((string) $r['tgl_izin'], (string) $r['tgl_kembali'], false)) ?></b><small><?= portal_e(mb_strimwidth((string) $r['alasan'], 0, 60, '…')) ?></small></span><?= portal_status((string) $r['status']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
            <?php ah_old_clear($bucket); ?>
        <?php endif; ?>
    </div>
</div>
<?php portal_footer(); ?>
