<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Izin\IzinException;
use App\Izin\IzinWaliService;

require_once __DIR__ . '/_izin_wali.php';

/**
 * Rincian pengajuan izin wali (PRD V7 Fase 1–2).
 *
 * Isi yang tampil ditentukan `IzinWaliService::detail()` per hak akun: admin,
 * pembimbing tujuan yang masih sah, dan murobi tujuan sesudah penerusan
 * melihat isi lengkap beserta jejak tinjauan/versi; wali pengaju melihat isi,
 * catatan pembimbing, dan hasil; wali lain hanya status ringkas; selain itu
 * 404. Tindakan (POST + CSRF, versi data, kunci idempotensi):
 *   admin      → tetapkan pembimbing sah;
 *   pembimbing → minta perbaikan, tolak, teruskan ke murobi;
 *   wali pengaju → kirim perbaikan saat diminta, batalkan sebelum keputusan.
 * Penetapan murobi dan keputusan murobi/admin pengganti memakai halaman
 * perizinan V2 pada ID yang sama. Tombol hanya cermin hak — server memeriksa
 * ulang setiap kiriman.
 */

$mode = (string) portal_requested_mode($currentUser, $userCapabilities);
$svc = izin_wali_service();
$id = (int) ($_GET['id'] ?? ($_POST['pengajuan_id'] ?? 0));
$aksiPost = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['aksi'] ?? '') : '';

$galat = null;
if ($aksiPost !== '') {
    // Gerbang peran halaman; hak sebenarnya diperiksa layanan.
    $modeDiizinkan = match ($aksiPost) {
        'tetapkan_pembimbing' => Capabilities::ADMIN,
        'tinjau' => Capabilities::PENGURUS,
        'perbaiki', 'batalkan' => Capabilities::ORANG_TUA,
        default => null,
    };
    if ($modeDiizinkan === null || $mode !== $modeDiizinkan) {
        izin_wali_tolak(IzinException::forbidden('Tindakan ini tidak tersedia untuk peran yang sedang aktif.'), $currentUser, $userCapabilities, $mode);
    }
    $kunci = is_string($_POST['idempotency_key'] ?? null) ? $_POST['idempotency_key'] : null;
    $versi = $_POST['version'] ?? null;
    $teks = static fn (string $nama): ?string => is_string($_POST[$nama] ?? null) ? $_POST[$nama] : null;
    try {
        $hasil = match ($aksiPost) {
            'tetapkan_pembimbing' => $svc->tetapkanPembimbing($currentUser, $id, $_POST['pengurus_id'] ?? null, $teks('alasan'), $versi, $kunci, portal_request_meta()),
            'tinjau' => $svc->tinjau($currentUser, $id, $teks('tindakan'), $teks('catatan'), $versi, $kunci, portal_request_meta()),
            'perbaiki' => $svc->perbaiki($currentUser, $id, [
                'tgl_izin' => $teks('tgl_izin'),
                'tgl_kembali' => $teks('tgl_kembali'),
                'alasan' => $teks('alasan'),
                'catatan' => $teks('catatan'),
            ], $versi, $kunci, portal_request_meta()),
            default => $svc->batalkan($currentUser, $id, $teks('alasan'), $versi, $kunci, portal_request_meta()),
        };
        $pesan = match ($aksiPost) {
            'tetapkan_pembimbing' => 'Pembimbing ditetapkan. Pengajuan masuk antrean pembimbing tersebut.',
            'tinjau' => match ((string) ($hasil['tindakan'] ?? '')) {
                'minta_perbaikan' => 'Permintaan perbaikan terkirim. Pengajuan menunggu perbaikan wali pengaju.',
                'tolak' => 'Pengajuan ditolak pada tahap pembimbing. Rentang tanggal dilepas.',
                default => ($hasil['perlu_penetapan_murobi'] ?? false)
                    ? 'Pengajuan diteruskan. Murobi tidak tunggal, sehingga menunggu penetapan murobi oleh admin.'
                    : 'Pengajuan diteruskan ke murobi.',
            },
            'perbaiki' => 'Perbaikan terkirim. Pengajuan kembali menunggu tinjauan pembimbing.',
            default => 'Pengajuan dibatalkan.',
        };
        portal_flash_set('sukses', $hasil['idempotent_replay'] ? 'Tindakan ini sudah tercatat sebelumnya; kiriman ulang tidak mengubah apa pun.' : $pesan);
        header('Location: ' . izin_wali_detail_url($id), true, 303);
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
    $detail = $svc->detail($currentUser, $id);
    $kandidat = ($detail['tindakan']['tetapkan_pembimbing'] ?? false) && $mode === Capabilities::ADMIN
        ? $svc->kandidatPembimbing($currentUser, $id) : [];
} catch (IzinException $exception) {
    izin_wali_tolak($exception, $currentUser, $userCapabilities, $mode);
}

$p = $detail['pengajuan'];
$peranAkses = (string) $detail['peran_akses'];
$wali = $peranAkses === IzinWaliService::PERAN_WALI;
$tindakan = $detail['tindakan'];
$pengaju = $wali && !$p['ringkas'];

/** Nilai isian sesudah galat: kiriman terakhir untuk aksi yang sama, selain itu bawaan. */
$lama = static fn (string $aksi, string $nama, ?string $bawaan = null): string => $galat !== null && $aksiPost === $aksi && is_string($_POST[$nama] ?? null)
    ? (string) $_POST[$nama] : (string) $bawaan;
$tersembunyi = static function (string $aksi, array $tambahan = []) use ($p, $mode, $galat, $aksiPost): string {
    $kunci = $galat !== null && $aksiPost === $aksi && ($tambahan['tindakan'] ?? null) === ($_POST['tindakan'] ?? null) && is_string($_POST['idempotency_key'] ?? null)
        ? $_POST['idempotency_key'] : portal_idempotency_key();
    $html = portal_csrf()
        . '<input type="hidden" name="aksi" value="' . portal_e($aksi) . '">'
        . '<input type="hidden" name="mode" value="' . portal_e($mode) . '">'
        . '<input type="hidden" name="pengajuan_id" value="' . (int) $p['id'] . '">'
        . '<input type="hidden" name="version" value="' . (int) ($p['version'] ?? 0) . '">'
        . '<input type="hidden" name="idempotency_key" value="' . portal_e($kunci) . '">';
    foreach ($tambahan as $nama => $nilai) {
        $html .= '<input type="hidden" name="' . portal_e($nama) . '" value="' . portal_e($nilai) . '">';
    }

    return $html;
};
$terbuka = static fn (string $aksi, ?string $sub = null): bool => $galat !== null && $aksiPost === $aksi && ($sub === null || ($_POST['tindakan'] ?? null) === $sub);

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
if ($galat !== null) {
    ah_note('danger', 'Tindakan belum tersimpan.', '<p class="mb-0 mt-1">' . portal_e($galat) . '</p>');
}
$perbaikanTerakhir = null;
foreach ($detail['tinjauan'] ?? [] as $t) {
    $perbaikanTerakhir = $t;
}
if ($pengaju && ($tindakan['perbaiki'] ?? false) && $perbaikanTerakhir !== null && $perbaikanTerakhir['tindakan'] === 'minta_perbaikan') {
    ah_note('warning', 'Pembimbing meminta perbaikan.', '<p class="mb-0 mt-1">' . nl2br(portal_e((string) $perbaikanTerakhir['catatan'])) . '</p>');
}
if ((string) $p['status'] === 'Ditolak Pembimbing' && !($wali && $p['ringkas']) && $perbaikanTerakhir !== null && $perbaikanTerakhir['tindakan'] === 'tolak') {
    ah_note('danger', 'Ditolak pada tahap pembimbing (bukan keputusan murobi).', '<p class="mb-0 mt-1">' . nl2br(portal_e((string) $perbaikanTerakhir['catatan'])) . '</p>');
}
$keputusan = $detail['keputusan'] ?? null;
?>
<div class="ah-split">
    <div class="ah-split__col">
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
                        <?php if ($p['perlu_admin']): ?> <?= ah_badge($p['status'] === 'Perlu Penetapan Admin' ? 'Perlu penetapan murobi' : 'Perlu penetapan admin', 'warn') ?><?php endif; ?></dd></div>
                    <div><dt>Routing pembimbing</dt><dd><?= portal_e((string) ($p['pembimbing']['routing_catatan'] ?? '—')) ?></dd></div>
                    <?php if ($p['murobi']['routing_catatan'] !== null): ?>
                        <div><dt>Murobi</dt><dd><?= portal_e($p['murobi']['nama'] ?? 'Belum ditetapkan') ?><span class="ah-cell-sub"><?= portal_e((string) $p['murobi']['routing_catatan']) ?></span></dd></div>
                    <?php endif; ?>
                    <div><dt>Versi data</dt><dd><?= (int) $p['version'] ?></dd></div>
                <?php elseif (!$p['ringkas']): ?>
                    <?php if (($p['pembimbing'] ?? null) !== null): ?><div><dt>Pembimbing</dt><dd><?= portal_e((string) $p['pembimbing']) ?></dd></div><?php endif; ?>
                    <?php if (($p['murobi'] ?? null) !== null): ?><div><dt>Murobi</dt><dd><?= portal_e((string) $p['murobi']) ?></dd></div><?php endif; ?>
                <?php endif; ?>
                <?php $batal = $pengaju ? ($detail['pembatalan'] ?? null) : ($wali ? null : $p['pembatalan']); ?>
                <?php if ($batal !== null): ?>
                    <div><dt>Pembatalan</dt><dd><?= portal_e(ah_waktu((string) $batal['waktu'])) ?> · oleh wali pengaju<span class="ah-cell-sub"><?= portal_e((string) $batal['alasan']) ?></span></dd></div>
                <?php endif; ?>
            </dl>
            <?php if ($wali && $p['ringkas']): ?>
                <?php ah_note('info', 'Pengajuan ini dibuat wali lain. Anda hanya melihat statusnya.'); ?>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($keputusan !== null): ?>
        <section class="ah-card" aria-labelledby="ah-keputusan-wali">
            <div class="ah-card__head"><span id="ah-keputusan-wali">Keputusan <?= portal_e(strtolower((string) $keputusan['oleh'])) ?></span><?= izin_wali_status((string) $keputusan['hasil']) ?></div>
            <div class="ah-card__body"><dl class="ah-info-list">
                <div><dt>Diputus</dt><dd><?= portal_e(ah_waktu((string) $keputusan['waktu'])) ?><?= isset($keputusan['pemberi']) && $keputusan['pemberi'] !== null ? ' · ' . portal_e((string) $keputusan['pemberi']) : '' ?></dd></div>
                <div><dt>Alasan keputusan</dt><dd><?= nl2br(portal_e((string) $keputusan['alasan'])) ?></dd></div>
            </dl></div>
        </section>
    <?php endif; ?>

    <?php if (($detail['perbaikan'] ?? []) !== []): ?>
        <section class="ah-card" aria-labelledby="ah-versi-isi">
            <div class="ah-card__head"><span id="ah-versi-isi">Versi isi sebelum perbaikan</span></div>
            <div class="ah-card__body"><ol class="list-unstyled mb-0">
                <?php foreach ($detail['perbaikan'] as $w): ?>
                    <li class="mb-3"><b>Versi <?= (int) $w['versi_sebelum'] ?> → <?= (int) $w['versi_sesudah'] ?></b>
                        <span class="d-block small text-muted"><?= portal_e(ah_waktu((string) $w['waktu'])) ?><?= $w['pengirim'] !== null ? ' · ' . portal_e((string) $w['pengirim']) : '' ?></span>
                        <span class="d-block small">Sebelum: <?= portal_e(ah_rentang($w['sebelum']['tgl_izin'], $w['sebelum']['tgl_kembali'])) ?> — <?= portal_e((string) $w['sebelum']['alasan']) ?><?= $w['sebelum']['catatan'] !== null ? ' (' . portal_e((string) $w['sebelum']['catatan']) . ')' : '' ?></span>
                        <span class="d-block small">Sesudah: <?= portal_e(ah_rentang($w['sesudah']['tgl_izin'], $w['sesudah']['tgl_kembali'])) ?> — <?= portal_e((string) $w['sesudah']['alasan']) ?><?= $w['sesudah']['catatan'] !== null ? ' (' . portal_e((string) $w['sesudah']['catatan']) . ')' : '' ?></span></li>
                <?php endforeach; ?>
            </ol></div>
        </section>
    <?php endif; ?>
    </div>

    <aside class="ah-split__col">
        <?php if ($peranAkses === IzinWaliService::PERAN_PEMBIMBING && ($tindakan['teruskan'] ?? false) && $mode === Capabilities::PENGURUS): ?>
            <section class="ah-card ah-no-print" aria-labelledby="ah-tinjauan">
                <div class="ah-card__head"><span id="ah-tinjauan">Tinjauan pembimbing</span></div>
                <div class="ah-card__body"><div class="ah-acts">
                    <?php ah_action_dialog_open('aksi-teruskan', 'Teruskan ke murobi', 'Teruskan pengajuan #' . (int) $p['id'], 'primary', $terbuka('tinjau', 'teruskan')); ?>
                        <form method="post" action="<?= portal_e(izin_wali_detail_url((int) $p['id'])) ?>">
                            <?= $tersembunyi('tinjau', ['tindakan' => 'teruskan']) ?>
                            <p class="small text-muted">Pengajuan yang sama diteruskan ke murobi santri. Bila murobi tidak tunggal, admin menetapkan murobi lebih dahulu. Keputusan izin tetap di tangan murobi.</p>
                            <label class="form-label" for="catatan_teruskan">Catatan untuk murobi (opsional)</label>
                            <textarea class="form-control" id="catatan_teruskan" name="catatan" rows="2" maxlength="1000"><?= portal_e($terbuka('tinjau', 'teruskan') ? $lama('tinjau', 'catatan') : '') ?></textarea>
                            <div class="ah-act__actions">
                                <button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Batal</button>
                                <button class="btn btn-primary" type="submit" data-busy-label="Meneruskan…">Teruskan</button>
                            </div>
                        </form>
                    <?php ah_action_dialog_close(); ?>
                    <?php ah_action_dialog_open('aksi-minta-perbaikan', 'Minta perbaikan', 'Minta perbaikan kepada wali pengaju', 'outline-primary', $terbuka('tinjau', 'minta_perbaikan')); ?>
                        <form method="post" action="<?= portal_e(izin_wali_detail_url((int) $p['id'])) ?>">
                            <?= $tersembunyi('tinjau', ['tindakan' => 'minta_perbaikan']) ?>
                            <p class="small text-muted">Hanya wali pengaju yang dapat memperbaiki lalu mengirim ulang. Rentang tanggal tetap ditahan selama menunggu perbaikan.</p>
                            <label class="form-label" for="catatan_perbaikan">Yang perlu diperbaiki <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="catatan_perbaikan" name="catatan" rows="3" required minlength="3" maxlength="2000"><?= portal_e($terbuka('tinjau', 'minta_perbaikan') ? $lama('tinjau', 'catatan') : '') ?></textarea>
                            <div class="ah-act__actions">
                                <button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Batal</button>
                                <button class="btn btn-primary" type="submit" data-busy-label="Mengirim…">Kirim permintaan</button>
                            </div>
                        </form>
                    <?php ah_action_dialog_close(); ?>
                    <?php ah_action_dialog_open('aksi-tolak', 'Tolak', 'Tolak pengajuan #' . (int) $p['id'] . '?', 'outline-danger', $terbuka('tinjau', 'tolak')); ?>
                        <form method="post" action="<?= portal_e(izin_wali_detail_url((int) $p['id'])) ?>">
                            <?= $tersembunyi('tinjau', ['tindakan' => 'tolak']) ?>
                            <p class="small text-muted">Penolakan menutup pengajuan pada tahap pembimbing — bukan keputusan murobi — dan melepas rentang tanggal.</p>
                            <label class="form-label" for="alasan_tolak">Alasan penolakan <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="alasan_tolak" name="catatan" rows="3" required minlength="3" maxlength="2000"><?= portal_e($terbuka('tinjau', 'tolak') ? $lama('tinjau', 'catatan') : '') ?></textarea>
                            <div class="ah-act__actions">
                                <button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Kembali</button>
                                <button class="btn btn-danger" type="submit" data-busy-label="Menolak…">Tolak pengajuan</button>
                            </div>
                        </form>
                    <?php ah_action_dialog_close(); ?>
                </div></div>
            </section>
        <?php endif; ?>

        <?php if ($pengaju && $mode === Capabilities::ORANG_TUA && (($tindakan['perbaiki'] ?? false) || ($tindakan['batalkan'] ?? false))): ?>
            <section class="ah-card ah-no-print" aria-labelledby="ah-tindakan-wali">
                <div class="ah-card__head"><span id="ah-tindakan-wali">Tindakan</span></div>
                <div class="ah-card__body"><div class="ah-acts">
                    <?php if ($tindakan['perbaiki'] ?? false): ?>
                        <?php ah_action_dialog_open('aksi-perbaiki', 'Perbaiki dan kirim ulang', 'Perbaiki pengajuan #' . (int) $p['id'], 'primary', $terbuka('perbaiki') || ($_GET['buka'] ?? '') === 'perbaiki'); ?>
                            <form method="post" action="<?= portal_e(izin_wali_detail_url((int) $p['id'])) ?>">
                                <?= $tersembunyi('perbaiki') ?>
                                <div class="row g-2">
                                    <div class="col-6"><label class="form-label" for="tgl_izin">Tanggal izin <span class="text-danger">*</span></label>
                                        <input class="form-control" type="date" id="tgl_izin" name="tgl_izin" required value="<?= portal_e($lama('perbaiki', 'tgl_izin', (string) $p['tgl_izin'])) ?>"></div>
                                    <div class="col-6"><label class="form-label" for="tgl_kembali">Tanggal kembali <span class="text-danger">*</span></label>
                                        <input class="form-control" type="date" id="tgl_kembali" name="tgl_kembali" required value="<?= portal_e($lama('perbaiki', 'tgl_kembali', (string) $p['tgl_kembali'])) ?>"></div>
                                </div>
                                <label class="form-label mt-3" for="alasan_perbaikan">Alasan izin <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="alasan_perbaikan" name="alasan" rows="3" required minlength="3" maxlength="2000"><?= portal_e($lama('perbaiki', 'alasan', (string) $p['alasan'])) ?></textarea>
                                <label class="form-label mt-3" for="catatan_wali">Catatan (opsional)</label>
                                <textarea class="form-control" id="catatan_wali" name="catatan" rows="2" maxlength="1000"><?= portal_e($lama('perbaiki', 'catatan', $p['catatan'] === null ? '' : (string) $p['catatan'])) ?></textarea>
                                <p class="small text-muted mt-2 mb-0">Isi lama tetap tersimpan sebagai versi sebelumnya.</p>
                                <div class="ah-act__actions">
                                    <button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Batal</button>
                                    <button class="btn btn-primary" type="submit" data-busy-label="Mengirim…">Kirim ulang</button>
                                </div>
                            </form>
                        <?php ah_action_dialog_close(); ?>
                    <?php endif; ?>
                    <?php if ($tindakan['batalkan'] ?? false): ?>
                        <?php ah_action_dialog_open('aksi-batalkan-wali', 'Batalkan pengajuan', 'Batalkan pengajuan #' . (int) $p['id'] . '?', 'outline-danger', $terbuka('batalkan')); ?>
                            <form method="post" action="<?= portal_e(izin_wali_detail_url((int) $p['id'])) ?>">
                                <?= $tersembunyi('batalkan') ?>
                                <p class="small text-muted">Pembatalan hanya mungkin sebelum murobi memutus dan tidak menghapus riwayat.</p>
                                <label class="form-label" for="alasan_batal">Alasan pembatalan <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="alasan_batal" name="alasan" rows="2" required minlength="3" maxlength="1000"><?= portal_e($lama('batalkan', 'alasan')) ?></textarea>
                                <div class="ah-act__actions">
                                    <button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Kembali</button>
                                    <button class="btn btn-danger" type="submit" data-busy-label="Membatalkan…">Batalkan pengajuan</button>
                                </div>
                            </form>
                        <?php ah_action_dialog_close(); ?>
                    <?php endif; ?>
                </div></div>
            </section>
        <?php endif; ?>

        <?php if (($tindakan['buka_v2'] ?? false) && in_array($mode, [Capabilities::ADMIN, Capabilities::MUROBI], true)): ?>
            <section class="ah-card ah-no-print" aria-labelledby="ah-keputusan-v2">
                <div class="ah-card__head"><span id="ah-keputusan-v2">Keputusan izin</span></div>
                <div class="ah-card__body">
                    <p class="small text-muted"><?= ($tindakan['putuskan'] ?? false) || ($tindakan['putuskan_pengganti'] ?? false)
                        ? 'Pengajuan sudah diteruskan pembimbing. Penetapan murobi dan keputusan memakai halaman perizinan dengan nomor yang sama.'
                        : 'Pengajuan ini sudah selesai diproses pada halaman perizinan dengan nomor yang sama.' ?></p>
                    <a class="btn btn-outline-primary" href="<?= portal_e(app_url('/portal/izin_detail.php') . '?id=' . (int) $p['id'] . '&mode=' . rawurlencode($mode)) ?>">Buka izin #<?= (int) $p['id'] ?></a>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($kandidat !== [] || ($tindakan['tetapkan_pembimbing'] ?? false) && $mode === Capabilities::ADMIN): ?>
            <section class="ah-card" aria-labelledby="ah-penetapan">
                <div class="ah-card__head"><span id="ah-penetapan">Tetapkan pembimbing</span></div>
                <div class="ah-card__body">
                    <?php if ($kandidat === []): ?>
                        <?php ah_note('warning', 'Belum ada pembimbing sah untuk santri ini.', '<p class="mb-0 mt-1">Buat penugasan pembimbing aktif untuk kamar/kelas santri di Pusat Penugasan, pastikan pengurusnya memiliki akun aktif, lalu kembali ke halaman ini.</p>'); ?>
                    <?php else: ?>
                        <form method="post" action="<?= portal_e(izin_wali_detail_url((int) $p['id'])) ?>">
                            <?= $tersembunyi('tetapkan_pembimbing') ?>
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
                                <textarea class="form-control" id="alasan_penetapan" name="alasan" rows="2" required minlength="3" maxlength="1000"><?= portal_e($lama('tetapkan_pembimbing', 'alasan')) ?></textarea>
                            </div>
                            <button class="btn btn-primary" type="submit" data-busy-label="Menyimpan…">Tetapkan pembimbing</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (($detail['tinjauan'] ?? []) !== []): ?>
            <section class="ah-card" aria-labelledby="ah-riwayat-tinjauan">
                <div class="ah-card__head"><span id="ah-riwayat-tinjauan">Tinjauan pembimbing</span></div>
                <div class="ah-card__body"><ol class="list-unstyled mb-0">
                    <?php foreach ($detail['tinjauan'] as $t): ?>
                        <li class="mb-2"><b><?= portal_e(match ($t['tindakan']) {
                            'minta_perbaikan' => 'Diminta perbaikan',
                            'tolak' => 'Ditolak pembimbing',
                            default => 'Diteruskan ke murobi',
                        }) ?></b>
                            <span class="d-block small text-muted"><?= portal_e(ah_waktu((string) $t['waktu']) . ' · ' . $t['pembimbing']) ?><?= isset($t['versi_sebelum']) ? ' · versi ' . (int) $t['versi_sebelum'] . '→' . (int) $t['versi_sesudah'] : '' ?></span>
                            <?php if ($t['catatan'] !== null): ?><span class="d-block small"><?= portal_e((string) $t['catatan']) ?></span><?php endif; ?>
                            <?php if (($t['murobi_routing_kandidat'] ?? null) !== null): ?><span class="d-block small text-muted"><?= $t['murobi'] !== null ? 'Murobi: ' . portal_e((string) $t['murobi']) : 'Murobi tidak tunggal (' . (int) $t['murobi_routing_kandidat'] . ' kandidat) — menunggu penetapan admin' ?></span><?php endif; ?></li>
                    <?php endforeach; ?>
                </ol></div>
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

        <?php if (($detail['notifikasi'] ?? []) !== []): ?>
            <section class="ah-card" aria-labelledby="ah-jejak-notifikasi">
                <div class="ah-card__head"><span id="ah-jejak-notifikasi">Jejak notifikasi</span></div>
                <div class="ah-card__body"><ol class="list-unstyled mb-0">
                    <?php foreach ($detail['notifikasi'] as $n): ?>
                        <li class="mb-2"><b><?= portal_e($n['peristiwa_label']) ?></b> · <?= portal_e($n['kanal']) ?>
                            <span class="d-block small text-muted"><?= portal_e((string) ($n['penerima'] ?? '—') . ' · ' . $n['status']
                                . ($n['kode_galat'] !== null ? ' (' . $n['kode_galat'] . ')' : '') . ' · ' . ah_waktu((string) $n['dibuat'])) ?></span></li>
                    <?php endforeach; ?>
                </ol></div>
            </section>
        <?php endif; ?>
    </aside>
</div>
<?php portal_footer(); ?>
