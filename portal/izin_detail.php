<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Izin\IzinException;

require_once __DIR__ . '/_ui.php';

/**
 * Detail izin (redesain website Tahap 4, I-5; tampilan orang tua I-7).
 *
 * Kolom kanan hanya menampilkan tindakan yang boleh untuk akun dan status
 * ini; setiap tombol membuka dialog berisi isian yang dibutuhkan saja
 * (pengganti sampai empat formulir bertumpuk). Riwayat status dan koreksi
 * digabung dalam satu linimasa. "Versi data" dan "Kandidat routing" hanya
 * di ⋯ › Info teknis untuk admin.
 *
 * Tombol HANYA cermin dari hak yang dihitung server. Menampilkan atau
 * menyembunyikannya tidak pernah menjadi kontrol akses: setiap POST diperiksa
 * ulang oleh IzinWorkflowService (PRD 5.2), termasuk versi data.
 */

$requestedMode = portal_requested_mode($currentUser, $userCapabilities);
$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    // Tanpa nomor pengajuan yang sah tidak ada detail yang dibuka (sebelumnya
    // pengajuan pertama dalam cakupan ikut tampil).
    http_response_code(404);
    portal_header('Pengajuan tidak ditemukan', $userCapabilities, $userCapabilities[0] ?? '', $currentUser);
    ah_note('warning', 'Nomor pengajuan tidak valid.', '<p class="mb-0 mt-2"><a href="' . portal_e(app_url('/portal/izin.php')) . '">Kembali ke daftar</a></p>');
    portal_footer();
    exit;
}

try {
    $detail = izin_service()->detail($currentUser, $id, $requestedMode);
} catch (IzinException $exception) {
    // Cakupan diperiksa di server: mengubah parameter ID tidak pernah membuka
    // pengajuan milik pengurus, murobi, atau orang tua lain.
    http_response_code($exception->status());
    portal_header('Akses ditolak', $userCapabilities, $userCapabilities[0] ?? '', $currentUser);
    ah_note('danger', (int) $exception->status() . ' — ' . $exception->getMessage(),
        '<p class="mb-0 mt-2"><a href="' . portal_e(app_url('/portal/izin.php')) . '">Kembali ke daftar</a></p>');
    portal_footer();
    exit;
}

$scope = $detail['scope'];
$mode = (string) $scope['mode'];
$izin = $detail['pengajuan'];
$keputusan = $detail['keputusan'];
$ortu = $mode === Capabilities::ORANG_TUA;
$aksi = izin_workflow_service()->actionsFor($izin, $scope);
$kandidatMurobi = ($aksi['tetapkan_murobi'] ?? false) ? izin_workflow_service()->eligibleMurobi() : [];
$versi = (int) $izin['version'];
$iz = (int) $izin['id'];
$bucket = static fn (string $aksiForm): string => '_portal_' . $iz . '_' . $aksiForm;
$adaGalat = static fn (string $aksiForm): bool => !empty($_SESSION[$bucket($aksiForm) . '_errors']) || !empty($_SESSION[$bucket($aksiForm)]);
$infoTeknis = $mode === Capabilities::ADMIN && ($_GET['info'] ?? '') === 'teknis';

$hidden = static function (string $aksiForm) use ($mode, $iz, $versi): string {
    return portal_csrf()
        . '<input type="hidden" name="aksi" value="' . portal_e($aksiForm) . '">'
        . '<input type="hidden" name="mode" value="' . portal_e($mode) . '">'
        . '<input type="hidden" name="pengajuan_id" value="' . $iz . '">'
        . '<input type="hidden" name="version" value="' . $versi . '">'
        . '<input type="hidden" name="idempotency_key" value="' . portal_e(portal_idempotency_key()) . '">';
};

// Linimasa: riwayat status + koreksi, terlama di atas.
$linimasa = [];
foreach ($detail['riwayat'] as $entry) {
    $isi = [];
    if ($entry['status_sesudah'] !== null) {
        $isi[] = ($entry['status_sebelum'] ?? '—') . ' → ' . $entry['status_sesudah'];
    }
    if ($entry['alasan'] !== null && $entry['alasan'] !== '') {
        $isi[] = (string) $entry['alasan'];
    }
    $linimasa[] = ['t' => (string) $entry['created_at'], 'waktu' => ah_waktu((string) $entry['created_at']),
        'pelaku' => portal_label((string) ($entry['pelaku_nama'] ?? ($izin['is_legacy'] ? 'Data warisan' : 'Sistem'))),
        'judul' => portal_peristiwa_label((string) $entry['peristiwa']), 'isi' => implode("\n", $isi),
        'tone' => (string) $entry['status_sesudah'] === 'Ditolak' ? 'danger' : ((string) $entry['status_sesudah'] === 'Perlu Penetapan Admin' ? 'warn' : '')];
}
foreach ($detail['koreksi'] as $koreksi) {
    $linimasa[] = ['t' => (string) $koreksi['dikoreksi_pada'], 'waktu' => ah_waktu((string) $koreksi['dikoreksi_pada']),
        'pelaku' => portal_label((string) ($koreksi['pelaku_nama'] ?? 'Data warisan')),
        'judul' => 'Koreksi keputusan: ' . $koreksi['hasil_sebelum'] . ' → ' . $koreksi['hasil_sesudah'],
        'isi' => 'Alasan koreksi: ' . $koreksi['alasan_koreksi'] . "\nAlasan keputusan sebelumnya: " . mb_strimwidth((string) $koreksi['alasan_sebelum'], 0, 200, '…'),
        'tone' => 'warn'];
}
usort($linimasa, static fn (array $a, array $b): int => strcmp($a['t'], $b['t']));

// Tindakan utama untuk bilah bawah HP.
$utama = match (true) {
    $aksi['putuskan_murobi'] => ['aksi-putuskan', 'Putuskan'],
    $aksi['tetapkan_murobi'] && $izin['status'] === 'Perlu Penetapan Admin' => ['aksi-tetapkan', 'Tetapkan murobi'],
    $aksi['putuskan_admin'] => ['aksi-putuskan', 'Putuskan sebagai admin pengganti'],
    $aksi['batalkan'] => ['aksi-batalkan', 'Batalkan pengajuan'],
    $aksi['koreksi'] => ['aksi-koreksi', 'Koreksi keputusan'],
    default => null,
};

$tindakanHead = '<button class="btn btn-outline-secondary" type="button" data-ah-print hidden><i class="fas fa-print" aria-hidden="true"></i> Cetak</button>';
if ($mode === Capabilities::ADMIN) {
    $tindakanHead .= ah_menu([
        ['label' => $infoTeknis ? 'Sembunyikan info teknis' : 'Info teknis', 'icon' => 'fa-circle-info', 'url' => portal_detail_url($iz, $mode, $infoTeknis ? [] : ['info' => 'teknis'])],
    ], 'Tindakan lain', false);
}

portal_header('Izin #' . $iz . ' · ' . $izin['nama_santri'], $userCapabilities, $mode, $currentUser, [
    'active' => $ortu ? 'izin_anak' : ($mode === Capabilities::MUROBI ? 'antrean' : 'izin'),
    'description' => mb_strimwidth((string) $izin['alasan'], 0, 60, '…') . ' · ' . ah_rentang((string) $izin['tgl_izin'], (string) $izin['tgl_kembali']),
    'actions' => $tindakanHead,
    'breadcrumbs' => $ortu
        ? [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Izin anak', 'url' => app_url('/portal/izin.php')], ['label' => '#' . $iz]]
        : [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Perizinan', 'url' => app_url('/portal/izin_ringkasan.php')],
            ['label' => 'Pengajuan', 'url' => app_url('/portal/izin.php') . '?mode=' . rawurlencode($mode)], ['label' => '#' . $iz]],
]);
portal_flash_render();

if ($izin['is_legacy']) {
    ah_note('warning', 'Arsip sistem lama. Sistem sebelumnya tidak mencatat pengurus pengaju, murobi tujuan, maupun pemberi keputusan, sehingga kolom pelaku sengaja dikosongkan.');
}
?>
<div class="ah-split">
    <div class="ah-split__col">
        <section class="ah-card" aria-labelledby="ah-rincian">
            <div class="ah-card__head"><span id="ah-rincian">Rincian</span><?= portal_status((string) $izin['status']) ?></div>
            <div class="ah-card__body">
                <dl class="ah-info-list">
                    <div><dt>Santri</dt><dd><?php if (in_array($mode, [Capabilities::PENGURUS, Capabilities::MUROBI, Capabilities::ADMIN], true)): ?><a href="<?= portal_e(app_url('/portal/santri_binaan.php?id=' . (int) $izin['santri_id'])) ?>"><?= portal_e($izin['nama_santri']) ?></a><?php else: ?><?= portal_e($izin['nama_santri']) ?><?php endif; ?> · <?= portal_e($izin['nis']) ?></dd></div>
                    <div><dt>Rentang izin</dt><dd><?= portal_e(ah_rentang((string) $izin['tgl_izin'], (string) $izin['tgl_kembali'])) ?></dd></div>
                    <div><dt>Alasan</dt><dd><?= nl2br(portal_e($izin['alasan'])) ?></dd></div>
                    <?php if ($izin['catatan_pengurus'] !== null && $izin['catatan_pengurus'] !== ''): ?>
                        <div><dt>Catatan untuk murobi</dt><dd><?= nl2br(portal_e($izin['catatan_pengurus'])) ?></dd></div>
                    <?php endif; ?>
                    <div><dt>Pengurus</dt><dd><?= portal_e(portal_label((string) $izin['pengurus_label'])) ?></dd></div>
                    <div><dt>Murobi</dt><dd><?= portal_e(portal_label((string) $izin['murobi_label'])) ?></dd></div>
                    <?php if (!$ortu): ?>
                        <div><dt>Semester</dt><dd><?= $izin['tahun_ajaran'] === null ? 'Arsip sistem lama' : portal_e($izin['tahun_ajaran'] . ' ' . $izin['semester']) ?></dd></div>
                        <?php if ($izin['routing_catatan'] !== null): ?>
                            <div><dt>Penerusan</dt><dd><?= portal_e($izin['routing_catatan']) ?></dd></div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($izin['dibatalkan_pada'] !== null): ?>
                        <div><dt>Pembatalan</dt><dd><?= portal_e(portal_label((string) ($izin['pembatal_nama'] ?? 'Data warisan')) . ' · ' . ah_waktu((string) $izin['dibatalkan_pada'])) ?><span class="ah-cell-sub"><?= portal_e((string) $izin['alasan_pembatalan']) ?></span></dd></div>
                    <?php endif; ?>
                </dl>
            </div>
        </section>

        <section class="ah-card" aria-labelledby="ah-keputusan">
            <div class="ah-card__head"><span id="ah-keputusan">Keputusan</span><?= $keputusan === null ? '' : portal_status((string) $keputusan['hasil']) ?></div>
            <div class="ah-card__body">
                <?php if ($keputusan === null): ?>
                    <p class="text-muted mb-0"><?= $izin['is_legacy']
                        ? 'Arsip sistem lama hanya menyimpan status akhir tanpa alasan atau pemberi keputusan.'
                        : ($izin['status'] === 'Dibatalkan' ? 'Pengajuan dibatalkan sebelum diputus.' : 'Belum ada keputusan. Murobi memutuskan setelah pengajuan diterimanya.') ?></p>
                <?php else: ?>
                    <dl class="ah-info-list">
                        <div><dt>Diputus oleh</dt><dd><?= portal_e(portal_label((string) ($keputusan['pemberi_keputusan'] ?? 'Data warisan'))) ?><?= $ortu ? '' : ' · ' . portal_e($keputusan['kapasitas']) ?></dd></div>
                        <div><dt>Waktu</dt><dd><?= portal_e(ah_waktu((string) $keputusan['diputus_pada'])) ?></dd></div>
                        <div><dt>Alasan keputusan</dt><dd><?= nl2br(portal_e($keputusan['alasan'])) ?></dd></div>
                        <?php if (!$ortu && ($keputusan['kapasitas'] ?? '') === 'Admin Pengganti' && ($keputusan['alasan_penggantian'] ?? null) !== null): ?>
                            <div><dt>Alasan penggantian</dt><dd><?= nl2br(portal_e((string) $keputusan['alasan_penggantian'])) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                <?php endif; ?>
            </div>
        </section>

        <section class="ah-card" aria-labelledby="ah-riwayat">
            <div class="ah-card__head"><span id="ah-riwayat">Riwayat</span></div>
            <div class="ah-card__body"><?= ah_timeline(array_map(static fn (array $i): array => array_diff_key($i, ['t' => true]), $linimasa), 'Riwayat status dan koreksi') ?></div>
        </section>
    </div>

    <div class="ah-split__col">
        <?php if ($ortu): ?>
            <section class="ah-card"><div class="ah-card__body">
                <p class="mb-0 small text-muted">Halaman ini hanya-baca. Hubungi pengurus santri bila ada pertanyaan tentang izin ini.</p>
            </div></section>
        <?php else: ?>
        <section class="ah-card ah-no-print" aria-labelledby="ah-tindakan">
            <div class="ah-card__head"><span id="ah-tindakan">Tindakan</span></div>
            <div class="ah-card__body">
                <div class="ah-acts">
                <?php if ($aksi['putuskan_murobi'] || $aksi['putuskan_admin']): $b = $bucket('putuskan'); ?>
                    <?php ah_action_dialog_open('aksi-putuskan', $aksi['putuskan_murobi'] ? 'Putuskan' : 'Putuskan sebagai admin pengganti',
                        'Putuskan izin #' . $iz, $aksi['putuskan_murobi'] ? 'primary' : 'outline-primary', $adaGalat('putuskan')); ?>
                        <form method="post" action="<?= portal_e(app_url('/portal/izin_aksi.php')) ?>">
                            <?= $hidden('putuskan') ?>
                            <?php if (!$aksi['putuskan_murobi']): ?>
                                <p class="small text-muted">Admin memutus sebagai <b>Admin Pengganti</b>. Alasan penggantian wajib dan disimpan bersama keputusan serta audit.</p>
                            <?php endif; ?>
                            <label class="form-label" for="hasil">Hasil</label>
                            <select class="form-select mb-3" id="hasil" name="hasil" required>
                                <?php foreach (['Disetujui', 'Ditolak'] as $h): ?>
                                    <option value="<?= $h ?>"<?= ah_old('hasil', ['hasil' => 'Disetujui'], $b) === $h ? ' selected' : '' ?>><?= $h ?></option>
                                <?php endforeach; ?>
                            </select><?= ah_field_error('hasil', $b) ?>
                            <label class="form-label" for="alasan_keputusan">Alasan keputusan <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="alasan_keputusan" name="alasan" rows="3" required minlength="3" maxlength="2000"><?= portal_e(ah_old('alasan', null, $b)) ?></textarea><?= ah_field_error('alasan', $b) ?>
                            <?php if (!$aksi['putuskan_murobi']): ?>
                                <label class="form-label mt-3" for="alasan_penggantian">Alasan penggantian murobi <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="alasan_penggantian" name="alasan_penggantian" rows="2" required minlength="3" maxlength="1000" placeholder="Contoh: murobi berhalangan dan izin dibutuhkan hari ini"><?= portal_e(ah_old('alasan_penggantian', null, $b)) ?></textarea><?= ah_field_error('alasan_penggantian', $b) ?>
                            <?php endif; ?>
                            <div class="ah-act__actions">
                                <button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Batal</button>
                                <button class="btn btn-primary" type="submit">Simpan keputusan</button>
                            </div>
                        </form>
                    <?php ah_action_dialog_close(); ?>
                <?php endif; ?>

                <?php if ($aksi['tetapkan_murobi']): $b = $bucket('tetapkan'); ?>
                    <?php ah_action_dialog_open('aksi-tetapkan', 'Tetapkan murobi', 'Tetapkan murobi untuk izin #' . $iz,
                        $izin['status'] === 'Perlu Penetapan Admin' ? 'primary' : 'outline-primary', $adaGalat('tetapkan') || ($_GET['buka'] ?? '') === 'tetapkan'); ?>
                        <?php if ($kandidatMurobi === []): ?>
                            <p class="text-muted mb-0">Belum ada guru dengan penugasan murobi aktif pada tahun ajaran aktif. Buat penugasan murobi lebih dahulu di menu Penugasan.</p>
                            <div class="ah-act__actions"><button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Tutup</button></div>
                        <?php else: ?>
                            <form method="post" action="<?= portal_e(app_url('/portal/izin_aksi.php')) ?>">
                                <?= $hidden('tetapkan') ?>
                                <p class="small text-muted">Antrean keputusan berpindah ke murobi yang dipilih; riwayat penetapan tetap tersimpan.</p>
                                <label class="form-label" for="murobi_guru_id">Murobi tujuan <span class="text-danger">*</span></label>
                                <select class="form-select mb-3" id="murobi_guru_id" name="murobi_guru_id" required>
                                    <option value="">— Pilih murobi —</option>
                                    <?php foreach ($kandidatMurobi as $kandidat): ?>
                                        <option value="<?= (int) $kandidat['guru_id'] ?>"<?= (int) ah_old('murobi_guru_id', $izin, $b) === (int) $kandidat['guru_id'] ? ' selected' : '' ?>><?= portal_e($kandidat['nama_guru']) ?></option>
                                    <?php endforeach; ?>
                                </select><?= ah_field_error('murobi_guru_id', $b) ?>
                                <label class="form-label" for="alasan_penetapan">Alasan penetapan <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="alasan_penetapan" name="alasan" rows="2" required minlength="3" maxlength="1000"><?= portal_e(ah_old('alasan', null, $b)) ?></textarea><?= ah_field_error('alasan', $b) ?>
                                <div class="ah-act__actions">
                                    <button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Batal</button>
                                    <button class="btn btn-primary" type="submit">Tetapkan murobi</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php ah_action_dialog_close(); ?>
                <?php endif; ?>

                <?php if ($aksi['koreksi']): $b = $bucket('koreksi'); ?>
                    <?php ah_action_dialog_open('aksi-koreksi', 'Koreksi keputusan', 'Koreksi keputusan izin #' . $iz, 'outline-primary', $adaGalat('koreksi')); ?>
                        <form method="post" action="<?= portal_e(app_url('/portal/izin_aksi.php')) ?>">
                            <?= $hidden('koreksi') ?>
                            <p class="small text-muted">Koreksi menyimpan nilai sebelum dan sesudah beserta alasannya sebagai peristiwa baru. Keputusan dan riwayat sebelumnya tidak dihapus.</p>
                            <label class="form-label" for="hasil_koreksi">Hasil setelah koreksi</label>
                            <select class="form-select mb-3" id="hasil_koreksi" name="hasil" required>
                                <?php foreach (['Disetujui', 'Ditolak'] as $h): ?>
                                    <option value="<?= $h ?>"<?= ah_old('hasil', ['hasil' => $izin['status']], $b) === $h ? ' selected' : '' ?>><?= $h ?></option>
                                <?php endforeach; ?>
                            </select><?= ah_field_error('hasil', $b) ?>
                            <label class="form-label" for="alasan_hasil_koreksi">Alasan keputusan setelah koreksi <span class="text-danger">*</span></label>
                            <textarea class="form-control mb-3" id="alasan_hasil_koreksi" name="alasan" rows="2" required minlength="3" maxlength="2000"><?= portal_e(ah_old('alasan', null, $b)) ?></textarea><?= ah_field_error('alasan', $b) ?>
                            <label class="form-label" for="alasan_koreksi">Alasan koreksi <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="alasan_koreksi" name="alasan_koreksi" rows="2" required minlength="3" maxlength="1000"><?= portal_e(ah_old('alasan_koreksi', null, $b)) ?></textarea><?= ah_field_error('alasan_koreksi', $b) ?>
                            <div class="ah-act__actions">
                                <button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Batal</button>
                                <button class="btn btn-warning" type="submit">Simpan koreksi</button>
                            </div>
                        </form>
                    <?php ah_action_dialog_close(); ?>
                <?php endif; ?>

                <?php if ($aksi['batalkan']): $b = $bucket('batalkan'); ?>
                    <?php ah_action_dialog_open('aksi-batalkan', 'Batalkan pengajuan', 'Batalkan izin #' . $iz . '?', 'outline-danger', $adaGalat('batalkan')); ?>
                        <form method="post" action="<?= portal_e(app_url('/portal/izin_aksi.php')) ?>">
                            <?= $hidden('batalkan') ?>
                            <p class="small text-muted">Pengajuan berhenti diproses. Pembatalan hanya mungkin sebelum ada keputusan dan tidak menghapus riwayat.</p>
                            <label class="form-label" for="alasan_pembatalan">Alasan pembatalan <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="alasan_pembatalan" name="alasan" rows="2" required minlength="3" maxlength="1000"><?= portal_e(ah_old('alasan', null, $b)) ?></textarea><?= ah_field_error('alasan', $b) ?>
                            <div class="ah-act__actions">
                                <button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Kembali</button>
                                <button class="btn btn-danger" type="submit">Batalkan pengajuan</button>
                            </div>
                        </form>
                    <?php ah_action_dialog_close(); ?>
                <?php endif; ?>
                </div>
                <?php if ($utama === null): ?>
                    <p class="small text-muted mb-0">Tidak ada tindakan yang dapat Anda lakukan pada pengajuan berstatus <?= portal_e(strtolower((string) $izin['status'])) ?>.</p>
                <?php elseif ($mode === Capabilities::ADMIN && !$aksi['koreksi']): ?>
                    <p class="small text-muted mb-0 mt-2">Koreksi keputusan tersedia setelah ada keputusan.</p>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($infoTeknis): ?>
            <section class="ah-card" aria-labelledby="ah-teknis">
                <div class="ah-card__head"><span id="ah-teknis">Info teknis</span></div>
                <div class="ah-card__body">
                    <dl class="ah-info-list">
                        <div><dt>Versi data</dt><dd>v<?= $versi ?></dd></div>
                        <div><dt>Asal data</dt><dd><?= portal_e(portal_sumber_label($izin)) ?><?= $izin['is_legacy'] ? ' · ID lama ' . (int) $izin['legacy_perizinan_id'] : '' ?></dd></div>
                        <div><dt>Kandidat routing</dt><dd><?= $izin['routing_pada'] === null ? 'Belum dijalankan' : (int) $izin['routing_kandidat'] . ' kandidat · ' . portal_e(ah_waktu((string) $izin['routing_pada'])) ?></dd></div>
                        <?php if ($izin['murobi_ditetapkan_pada'] !== null): ?>
                            <div><dt>Penetapan murobi</dt><dd><?= portal_e(($izin['penetap_nama'] ?? 'Admin') . ' · ' . ah_waktu((string) $izin['murobi_ditetapkan_pada'])) ?></dd></div>
                        <?php endif; ?>
                        <div><dt>Diajukan pada</dt><dd><?= portal_e((string) ($izin['diajukan_pada'] ?? 'Arsip sistem lama')) ?></dd></div>
                    </dl>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php if ($utama !== null && !$ortu): ?>
    <div class="ah-actbar ah-only-sm ah-no-print">
        <a class="btn btn-primary" href="#<?= portal_e($utama[0]) ?>" data-ah-open-dialog="<?= portal_e($utama[0]) ?>"><?= portal_e($utama[1]) ?></a>
    </div>
<?php endif; ?>
<?php foreach (['putuskan', 'tetapkan', 'batalkan', 'koreksi'] as $formAction) { ah_old_clear($bucket($formAction)); } portal_footer(); ?>
