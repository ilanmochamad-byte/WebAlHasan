<?php

declare(strict_types=1);

use App\Kesehatan\KesehatanException;
use App\Kesehatan\KesehatanService;
use App\Ui\Denial;

/**
 * Ringkasan kesehatan untuk wali satu kunjungan (PRD V5 Fase 3).
 *
 * Langkah petugas: Susun → Pratinjau (persis seperti dilihat wali) →
 * konfirmasi Terbitkan. Ringkasan disusun TERPISAH dari catatan klinik: teks
 * ditulis sendiri oleh petugas dan tidak disalin otomatis dari keluhan,
 * catatan klinis, obat, atau rujukan. Penggantian dan penerbitan ulang wajib
 * alasan; penarikan wajib alasan. Versi lama tetap tersimpan.
 *
 *   - petugas  : susun, pratinjau, terbitkan, ganti, tarik, dan riwayat;
 *   - admin    : hanya baca versi berlaku dan seluruh riwayat (alasan, pelaku);
 *   - pembimbing/murobi terkait: hanya baca versi yang berlaku.
 *
 * Hak, cakupan, versi, idempotensi, dan notifikasi milik `RingkasanService`.
 */

require_once __DIR__ . '/_kesehatan.php';

$ringkasanService = kesehatan_ringkasan_service();
$id = max(0, (int) ($_GET['id'] ?? 0));
$aksi = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['aksi'] ?? '') : '';
$isian = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
$galat = '';
$pratinjau = null;
$tolak = static function (KesehatanException $e) use ($id): never {
    if ($e->status === 404) {
        Denial::render('Kunjungan tidak ditemukan', $e->getMessage(), 404, '<a class="btn btn-outline-primary" href="' . ah_e(kesehatan_url('kesehatan.php')) . '">Kembali ke daftar</a>', false);
    }
    Denial::page('Ringkasan kesehatan untuk wali', 'petugas kesehatan berpenugasan aktif, admin, serta pembimbing/murobi santri terkait', $e->getMessage(), $e->status);
};

try {
    if ($aksi === 'pratinjau') {
        $pratinjau = $ringkasanService->pratinjau($currentUser, $id, $_POST);
    } elseif ($aksi === 'terbit') {
        $hasil = $ringkasanService->terbitkan($currentUser, $id, $_POST, KesehatanService::KANAL_WEB);
        ah_flash_set('success', $hasil['replayed']
            ? 'Ringkasan ini sudah diterbitkan sebelumnya. Tidak ada versi atau pemberitahuan ganda.'
            : 'Ringkasan versi ' . $hasil['versi'] . ' terbit. ' . $hasil['notifikasi']['in_app'] . ' akun wali menerima pemberitahuan di aplikasi/website.');
        ah_redirect('/portal/kesehatan_ringkasan.php?id=' . $id);
    } elseif ($aksi === 'tarik') {
        $ringkasanService->tarik($currentUser, $id, $_POST, KesehatanService::KANAL_WEB);
        ah_flash_set('success', 'Ringkasan ditarik. Wali hanya melihat keterangan bahwa informasi telah ditarik.');
        ah_redirect('/portal/kesehatan_ringkasan.php?id=' . $id);
    } elseif ($aksi !== '' && $aksi !== 'ubah') {
        throw new KesehatanException('Aksi tidak dikenal.');
    }
} catch (KesehatanException $exception) {
    if (in_array($exception->status, [403, 404], true)) {
        $tolak($exception);
    }
    http_response_code($exception->status);
    $galat = $exception->getMessage();
}

try {
    $info = $ringkasanService->internal($currentUser, $id);
} catch (KesehatanException $exception) {
    $tolak($exception);
}
$k = $info['kunjungan'];
$kepala = $info['ringkasan'];
$petugas = $info['akses']['catat'];
$penggantian = $kepala !== null;
// Isian awal penggantian = versi yang berlaku (bukan catatan klinik).
if ($isian === [] && $info['berlaku'] !== null) {
    $isian = ['ringkasan' => $info['berlaku']['ringkasan'], 'tindakan_umum' => $info['berlaku']['tindakan_umum'] ?? '', 'tindak_lanjut_wali' => $info['berlaku']['tindak_lanjut_wali'] ?? ''];
}
$nilai = static fn (string $f): string => is_scalar($isian[$f] ?? null) ? (string) $isian[$f] : '';

ah_page_open([
    'title' => 'Ringkasan untuk wali · ' . $k['santri']['nama'],
    'heading' => 'Ringkasan untuk wali',
    'user' => $currentUser,
    'active' => 'kesehatan',
    'description' => $k['santri']['nama'] . ' · kunjungan ' . ah_tanggal($k['tanggal'], false),
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Kesehatan', 'url' => kesehatan_url('kesehatan.php')],
        ['label' => 'Detail kunjungan', 'url' => kesehatan_url('kesehatan_detail.php', ['id' => $k['id']])],
        ['label' => 'Ringkasan untuk wali'],
    ],
]);
if ($galat !== '') {
    ah_note('danger', $galat);
}
if (!$petugas): ?>
    <div class="ah-ribbon" role="note"><i class="fas fa-eye mt-1" aria-hidden="true"></i>
        <span><b>Hanya baca.</b> Ringkasan untuk wali diterbitkan oleh petugas kesehatan berpenugasan aktif.</span></div>
<?php endif;

if ($pratinjau !== null): ?>
<section class="ah-card" aria-labelledby="ah-pratinjau-ringkasan">
    <div class="ah-card__head"><span id="ah-pratinjau-ringkasan">Pratinjau · persis seperti dilihat wali</span></div>
    <div class="ah-card__body">
        <?= ah_stepper(['Susun', 'Pratinjau', 'Terbit'], 1, 'Langkah ringkasan') ?>
        <div class="ah-card mb-3"><div class="ah-card__head"><span>Informasi kesehatan · <?= ah_e(ah_tanggal($pratinjau['pratinjau']['tanggal_kunjungan'], false)) ?></span></div><div class="ah-card__body">
            <?php $ringkasanWali = $pratinjau['pratinjau']; require __DIR__ . '/partials/kesehatan_ringkasan_konten.php'; ?>
        </div></div>
        <p class="mb-3"><b>Penerima:</b> <?= $pratinjau['penerima'] > 0
            ? (int) $pratinjau['penerima'] . ' akun wali dengan relasi aktif. Pemberitahuan berisi kalimat umum tanpa nama santri atau isi kesehatan.'
            : 'santri ini belum memiliki akun wali aktif; ringkasan tetap tersimpan dan dapat dibaca bila akun wali dihubungkan.' ?></p>
        <form method="post" class="d-grid gap-3"><?= ah_csrf() ?>
            <input type="hidden" name="aksi" value="terbit">
            <input type="hidden" name="pratinjau_hash" value="<?= ah_e($pratinjau['pratinjau_hash']) ?>">
            <input type="hidden" name="idempotency_key" value="<?= ah_e('ringkasan-' . bin2hex(random_bytes(12))) ?>">
            <?php foreach (['kunjungan_versi', 'revisi_ringkasan', 'ringkasan', 'tindakan_umum', 'tindak_lanjut_wali', 'alasan'] as $f): ?>
                <input type="hidden" name="<?= ah_e($f) ?>" value="<?= ah_e($nilai($f)) ?>">
            <?php endforeach; ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="konfirmasi" name="konfirmasi" value="1" required>
                <label class="form-check-label" for="konfirmasi">Saya sudah memeriksa isi pratinjau dan menyetujui penerbitannya kepada wali.</label></div>
            <?php // Tanpa bilah lengket: di HP bilah itu menutupi kotak konfirmasi tepat di atasnya. ?>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-outline-secondary" type="submit" name="aksi" value="ubah" formnovalidate>Kembali ubah isi</button>
                <button class="btn btn-primary" type="submit">Terbitkan<?= $pratinjau['penggantian'] ? ' versi baru' : '' ?></button>
            </div>
        </form>
    </div>
</section>
<?php else: ?>
<section class="ah-card" aria-labelledby="ah-ringkasan-berlaku">
    <div class="ah-card__head"><span id="ah-ringkasan-berlaku">Yang dilihat wali saat ini</span><?= $kepala === null ? ah_status('Draf', 'Belum terbit') : ah_status($kepala['status']) ?></div>
    <div class="ah-card__body">
        <?php if ($info['berlaku'] !== null): $ringkasanWali = $info['berlaku']; require __DIR__ . '/partials/kesehatan_ringkasan_konten.php'; ?>
            <p class="small text-muted mt-3 mb-0">Versi <?= (int) $info['berlaku']['versi'] ?> · terbit <?= ah_e(ah_waktu($info['berlaku']['diterbitkan_pada'])) ?></p>
        <?php elseif ($kepala !== null): ?><p class="mb-0">Ringkasan telah ditarik. Wali hanya melihat keterangan bahwa informasi ditarik.</p>
        <?php else: ?><p class="text-muted mb-0">Belum ada ringkasan yang diterbitkan untuk kunjungan ini. Wali tidak melihat apa pun.</p><?php endif; ?>
    </div>
</section>
<?php endif;

if ($petugas && $pratinjau === null):
    if (!$info['boleh_terbit']): ?>
        <?php ah_note('info', 'Kunjungan ini masih draf. Tetapkan sebagai tercatat sebelum menyusun ringkasan untuk wali.'); ?>
    <?php else: ?>
<section class="ah-card" aria-labelledby="ah-susun-ringkasan">
    <div class="ah-card__head"><span id="ah-susun-ringkasan"><?= $penggantian ? ($kepala['status'] === 'Terbit' ? 'Ganti ringkasan (versi baru)' : 'Terbitkan ulang ringkasan') : 'Susun ringkasan untuk wali' ?></span></div>
    <div class="ah-card__body">
        <?= ah_stepper(['Susun', 'Pratinjau', 'Terbit'], 0, 'Langkah ringkasan') ?>
        <p class="small text-muted">Tulis dengan bahasa yang mudah dipahami orang tua. Ringkasan ini terpisah dari catatan klinik: jangan menyalin catatan klinis, diagnosis rinci, atau detail obat yang tidak perlu diketahui wali.</p>
        <form method="post" class="row g-3"><?= ah_csrf() ?>
            <input type="hidden" name="aksi" value="pratinjau">
            <input type="hidden" name="kunjungan_versi" value="<?= (int) $k['versi'] ?>">
            <input type="hidden" name="revisi_ringkasan" value="<?= (int) ($kepala['revisi'] ?? 0) ?>">
            <div class="col-12"><label class="form-label" for="ringkasan">Ringkasan kunjungan <span class="text-danger">*</span></label>
                <textarea class="form-control" id="ringkasan" name="ringkasan" rows="4" minlength="10" maxlength="2000" required><?= ah_e($nilai('ringkasan')) ?></textarea></div>
            <div class="col-12"><label class="form-label" for="tindakan-umum">Penanganan di klinik (tindakan umum)</label>
                <textarea class="form-control" id="tindakan-umum" name="tindakan_umum" rows="2" maxlength="1000"><?= ah_e($nilai('tindakan_umum')) ?></textarea></div>
            <div class="col-12"><label class="form-label" for="tindak-lanjut-wali">Yang perlu diperhatikan orang tua (tindak lanjut)</label>
                <textarea class="form-control" id="tindak-lanjut-wali" name="tindak_lanjut_wali" rows="2" maxlength="1000"><?= ah_e($nilai('tindak_lanjut_wali')) ?></textarea>
                <div class="form-text">Bila diisi, pemberitahuan kepada wali menyebut ada informasi yang memerlukan perhatian (tanpa isinya).</div></div>
            <?php if ($penggantian): ?>
                <div class="col-12"><label class="form-label" for="alasan-ganti">Alasan <?= $kepala['status'] === 'Terbit' ? 'penggantian' : 'penerbitan ulang' ?> <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="alasan-ganti" name="alasan" rows="2" minlength="5" maxlength="1000" required><?= ah_e($nilai('alasan')) ?></textarea>
                    <div class="form-text">Versi sebelumnya tetap tersimpan dan dapat ditelusuri petugas serta admin.</div></div>
            <?php endif; ?>
            <div class="col-12 d-flex flex-wrap gap-2 ah-sticky-sm">
                <a class="btn btn-outline-secondary" href="<?= ah_e(kesehatan_url('kesehatan_detail.php', ['id' => $k['id']])) ?>">Kembali ke detail</a>
                <button class="btn btn-primary" type="submit">Lihat pratinjau</button>
            </div>
        </form>
    </div>
</section>
<?php endif;
    if ($kepala !== null && $kepala['status'] === 'Terbit'): ?>
<section class="ah-card ah-no-print" aria-labelledby="ah-tarik-ringkasan">
    <div class="ah-card__head"><span id="ah-tarik-ringkasan">Tarik ringkasan</span></div>
    <div class="ah-card__body">
        <?php ah_action_dialog_open('tarik-ringkasan', 'Tarik ringkasan', 'Tarik ringkasan ini dari wali?', 'outline-danger', $aksi === 'tarik' && $galat !== ''); ?>
            <form method="post"><?= ah_csrf() ?>
                <input type="hidden" name="aksi" value="tarik">
                <input type="hidden" name="revisi_ringkasan" value="<?= (int) $kepala['revisi'] ?>">
                <input type="hidden" name="idempotency_key" value="<?= ah_e('tarik-' . bin2hex(random_bytes(12))) ?>">
                <p class="small">Wali tidak lagi melihat isi ringkasan; yang tampil hanya keterangan bahwa informasi telah ditarik. Versi ini tetap tersimpan.</p>
                <label class="form-label" for="alasan-tarik">Alasan penarikan <span class="text-danger">*</span></label>
                <textarea class="form-control" id="alasan-tarik" name="alasan" rows="2" minlength="5" maxlength="1000" required></textarea>
                <div class="ah-act__actions"><button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Kembali</button><button class="btn btn-danger" type="submit">Tarik ringkasan</button></div>
            </form>
        <?php ah_action_dialog_close(); ?>
    </div>
</section>
<?php endif;
endif;

if ($info['akses']['riwayat'] && $kepala !== null): ?>
<section class="ah-card" aria-labelledby="ah-riwayat-ringkasan">
    <div class="ah-card__head"><span id="ah-riwayat-ringkasan">Riwayat ringkasan</span><span class="text-muted small"><?= count($info['versi']) ?> versi · <?= (int) $info['penerima'] ?> akun wali berhak saat ini</span></div>
    <div class="ah-card__body">
        <?= ah_timeline(array_map(static fn (array $e): array => [
            'waktu' => ah_waktu($e['waktu']),
            'pelaku' => $e['oleh']['nama'] . ' · versi ' . $e['versi'],
            'judul' => ['Terbit' => 'Diterbitkan', 'Ganti' => 'Diganti dengan versi baru', 'Tarik' => 'Ditarik'][$e['jenis']] ?? $e['jenis'],
            'isi' => trim(($e['alasan'] !== null ? 'Alasan: ' . $e['alasan'] . "\n" : '') . ($e['jenis'] !== 'Tarik' ? $e['jumlah_penerima'] . ' akun wali diberi tahu' : '')),
            'tone' => $e['jenis'] === 'Tarik' ? 'danger' : '',
        ], $info['peristiwa']), 'Riwayat ringkasan') ?>
        <?php foreach ($info['versi'] as $v): ?>
            <details class="mt-2"><summary>Versi <?= (int) $v['versi'] ?> · <?= ah_e($v['status']) ?> · <?= ah_e(ah_waktu($v['diterbitkan_pada'])) ?> (sumber kunjungan versi <?= (int) $v['kunjungan_versi'] ?>)</summary>
                <dl class="ah-kv ah-kv--stack mt-2">
                    <div><dt>Ringkasan</dt><dd class="ah-break"><?= nl2br(ah_e($v['ringkasan'])) ?></dd></div>
                    <div><dt>Penanganan di klinik</dt><dd class="ah-break"><?= $v['tindakan_umum'] === null ? '—' : nl2br(ah_e($v['tindakan_umum'])) ?></dd></div>
                    <div><dt>Yang perlu diperhatikan orang tua</dt><dd class="ah-break"><?= $v['tindak_lanjut_wali'] === null ? '—' : nl2br(ah_e($v['tindak_lanjut_wali'])) ?></dd></div>
                    <div><dt>Diterbitkan oleh</dt><dd><?= ah_e($v['oleh']['nama']) ?></dd></div>
                </dl>
            </details>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
<p class="mt-3"><a class="btn btn-outline-secondary" href="<?= ah_e(kesehatan_url('kesehatan_detail.php', ['id' => $k['id']])) ?>">Kembali ke detail kunjungan</a></p>
<?php
ah_page_close();
