<?php

declare(strict_types=1);

use App\Kesehatan\KesehatanException;
use App\Kesehatan\KesehatanService;
use App\Ui\Denial;

/**
 * Detail satu kunjungan klinik (PRD V5 Fase 1–2).
 *
 * Isi klinis, obat, rujukan, dan tindak lanjut hanya tampil di sini, dan
 * setiap pembukaan halaman ini tercatat di `kesehatan_akses_log` (siapa,
 * kunjungan, sumber hak, kanal, waktu) tanpa menyalin isi.
 *
 *   - petugas  : tombol Koreksi (atau Lanjutkan draf), Catat hasil rujukan,
 *                dan Riwayat versi;
 *   - admin    : Riwayat versi dan Riwayat akses, tanpa tombol ubah;
 *   - pembimbing/murobi terkait: hanya baca kunjungan Tercatat santri dalam
 *                cakupan; di luar cakupan dan tidak ada sama-sama 403.
 */

require_once __DIR__ . '/_kesehatan.php';

$id = max(0, (int) ($_GET['id'] ?? 0));
try {
    $k = $kesehatan->detail($currentUser, $id, KesehatanService::KANAL_WEB);
} catch (KesehatanException $exception) {
    if ($exception->status === 404) {
        Denial::render('Kunjungan tidak ditemukan', 'Kunjungan yang Anda buka tidak ada.', 404, '<a class="btn btn-outline-primary" href="' . ah_e(kesehatan_url('kesehatan.php')) . '">Kembali ke daftar</a>', false);
    }
    Denial::page('Detail kunjungan klinik', 'petugas kesehatan berpenugasan aktif, admin, serta pembimbing/murobi santri terkait', $exception->getMessage(), $exception->status);
}
$a = $k['akses'];
$riwayat = $a['riwayat_akses'] ? $kesehatan->riwayatAkses($currentUser, $id, $_GET['page'] ?? 1) : null;
// PRD V5 Fase 3: status ringkasan wali (null bila migrasi 026 belum terpasang).
try {
    $infoRingkasan = kesehatan_ringkasan_service()->internal($currentUser, $id);
} catch (KesehatanException) {
    $infoRingkasan = null;
}
$teks = static fn (?string $isi): string => $isi === null || $isi === '' ? '<span class="text-muted">—</span>' : nl2br(ah_e($isi));
$draf = $k['status'] === KesehatanService::STATUS_DRAF;

$aksi = '';
if ($a['catat']) {
    $aksi .= '<a class="btn btn-primary" href="' . ah_e(kesehatan_url('kesehatan_koreksi.php', ['id' => $k['id']])) . '"><i class="fas fa-pen" aria-hidden="true"></i> '
        . ($draf ? 'Lanjutkan draf' : 'Koreksi') . '</a>';
}
if ($a['riwayat_versi']) {
    $aksi .= ' <a class="btn btn-outline-secondary" href="' . ah_e(kesehatan_url('kesehatan_riwayat.php', ['id' => $k['id']])) . '">Riwayat versi (' . (int) $k['versi'] . ')</a>';
}

ah_page_open([
    'title' => 'Kunjungan ' . $k['santri']['nama'],
    'heading' => 'Detail kunjungan',
    'user' => $currentUser,
    'active' => 'kesehatan',
    'description' => ah_waktu($k['waktu_kunjungan']) . ' · NIS ' . $k['santri']['nis'],
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Kesehatan', 'url' => kesehatan_url('kesehatan.php')], ['label' => 'Detail kunjungan']],
    'actions' => $aksi,
]);
if (!$a['catat']): ?>
    <div class="ah-ribbon" role="note"><i class="fas fa-eye mt-1" aria-hidden="true"></i>
        <span><b>Hanya baca.</b> Pembukaan rincian ini tercatat di riwayat akses.</span></div>
<?php elseif ($draf): ?>
    <div class="ah-ribbon" role="note"><i class="fas fa-file-pen mt-1" aria-hidden="true"></i>
        <span><b>Draf.</b> Belum tampil kepada pembimbing/murobi. Lanjutkan draf lalu tetapkan sebagai tercatat bila sudah lengkap.</span></div>
<?php endif; ?>
<section class="ah-card" aria-labelledby="ah-kunjungan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-kunjungan"><?= ah_e($k['santri']['nama']) ?></h2><?= ah_status($k['status']) ?></div>
    <div class="ah-card__body"><dl class="ah-kv">
        <div><dt>Waktu kunjungan</dt><dd><?= ah_e(ah_waktu($k['waktu_kunjungan'])) ?></dd></div>
        <div><dt>Santri</dt><dd><?= ah_e($k['santri']['nama'] . ' · NIS ' . $k['santri']['nis']) ?></dd></div>
        <div><dt>Petugas pencatat</dt><dd><?= ah_e($k['petugas']['nama']) ?></dd></div>
        <div><dt>Dicatat pada</dt><dd><?= ah_e(ah_waktu($k['dicatat_pada'])) ?></dd></div>
        <div><dt>Versi</dt><dd><?= (int) $k['versi'] ?></dd></div>
    </dl>
    <p class="mb-0 mt-3"><a href="<?= ah_e(kesehatan_url('kesehatan.php', ['santri_id' => $k['santri']['id']])) ?>">Riwayat kesehatan santri ini</a></p></div>
</section>
<section class="ah-card" aria-labelledby="ah-isi-klinis">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-isi-klinis">Catatan klinik</h2><span class="text-muted small">Internal</span></div>
    <div class="ah-card__body"><dl class="ah-kv ah-kv--stack">
        <div><dt>Keluhan</dt><dd class="ah-break"><?= $teks($k['keluhan']) ?></dd></div>
        <div><dt>Catatan klinis</dt><dd class="ah-break"><?= $teks($k['catatan_klinis']) ?></dd></div>
        <div><dt>Tindakan</dt><dd class="ah-break"><?= $teks($k['tindakan']) ?></dd></div>
    </dl></div>
</section>
<section class="ah-card" aria-labelledby="ah-obat">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-obat">Obat yang diberikan</h2><span class="text-muted small"><?= count($k['obat']) ?> obat</span></div>
    <?php if ($k['obat'] === []): ?><div class="ah-card__body"><p class="text-muted mb-0">Tidak ada obat yang dicatat.</p></div>
    <?php else: ?><div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Obat yang diberikan pada kunjungan ini</caption>
        <thead><tr><th scope="col">Obat</th><th scope="col">Jumlah</th><th scope="col">Petunjuk</th><th scope="col">Waktu pemberian</th></tr></thead><tbody>
        <?php foreach ($k['obat'] as $o): ?><tr>
            <td data-label="Obat"><span class="ah-break"><?= ah_e($o['nama_obat']) ?></span></td>
            <td data-label="Jumlah"><?= ah_e(kesehatan_jumlah($o['jumlah'], $o['satuan']) ?: '—') ?></td>
            <td data-label="Petunjuk"><span class="ah-break"><?= ah_e($o['petunjuk'] ?? '—') ?></span></td>
            <td data-label="Waktu pemberian"><?= ah_e(ah_waktu($o['waktu_pemberian'])) ?></td>
        </tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
</section>
<section class="ah-card" aria-labelledby="ah-rujukan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-rujukan">Rujukan</h2><span class="text-muted small"><?= count($k['rujukan']) ?> rujukan</span></div>
    <div class="ah-card__body">
    <?php if ($k['rujukan'] === []): ?><p class="text-muted mb-0">Tidak ada rujukan.</p>
    <?php else: foreach ($k['rujukan'] as $r): ?>
        <div class="border rounded p-2 mb-2">
            <div class="d-flex flex-wrap justify-content-between gap-2"><b class="ah-break"><?= ah_e($r['tujuan']) ?></b><?= kesehatan_badge($r['status']) ?></div>
            <dl class="ah-kv ah-kv--stack mt-2">
                <div><dt>Tanggal rujukan</dt><dd><?= ah_e(ah_tanggal($r['tanggal_rujukan'])) ?></dd></div>
                <div><dt>Alasan/catatan</dt><dd class="ah-break"><?= $teks($r['alasan']) ?></dd></div>
                <div><dt>Hasil</dt><dd class="ah-break"><?= $r['hasil'] === null ? '<span class="text-muted">Belum diketahui</span>' : $teks($r['hasil']) . ' <span class="text-muted small">(' . ah_e(ah_tanggal($r['tanggal_hasil'])) . ')</span>' ?></dd></div>
            </dl>
            <?php if ($a['catat'] && $r['status'] === KesehatanService::RUJUKAN_MENUNGGU): ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= ah_e(kesehatan_url('kesehatan_hasil_rujukan.php', ['id' => $k['id'], 'rujukan' => $r['id']])) ?>">Catat hasil rujukan</a>
            <?php endif; ?>
        </div>
    <?php endforeach; endif; ?>
    </div>
</section>
<section class="ah-card" aria-labelledby="ah-tindak-lanjut">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-tindak-lanjut">Tindak lanjut</h2><span class="text-muted small"><?= count($k['tindak_lanjut']) ?> tindak lanjut</span></div>
    <?php if ($k['tindak_lanjut'] === []): ?><div class="ah-card__body"><p class="text-muted mb-0">Tidak ada tindak lanjut.</p></div>
    <?php else: ?><div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Tindak lanjut kunjungan ini</caption>
        <thead><tr><th scope="col">Uraian</th><th scope="col">Target</th><th scope="col">Status</th></tr></thead><tbody>
        <?php foreach ($k['tindak_lanjut'] as $t): ?><tr>
            <td data-label="Uraian"><span class="ah-break"><?= nl2br(ah_e($t['uraian'])) ?></span></td>
            <td data-label="Target"><?= ah_e($t['tanggal_target'] === null ? '—' : ah_tanggal($t['tanggal_target'])) ?></td>
            <td data-label="Status"><?= kesehatan_badge($t['status']) ?><?= $t['tanggal_selesai'] !== null ? ' <span class="text-muted small">' . ah_e(ah_tanggal($t['tanggal_selesai'], false)) . '</span>' : '' ?></td>
        </tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
</section>
<?php if ($infoRingkasan !== null): $rk = $infoRingkasan['ringkasan']; ?>
<section class="ah-card" aria-labelledby="ah-ringkasan-wali">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-ringkasan-wali">Ringkasan untuk wali</h2><?= $rk === null ? ah_status('Draf', 'Belum terbit') : ah_status($rk['status']) ?></div>
    <div class="ah-card__body">
        <p class="mb-2"><?= $rk === null ? 'Wali belum menerima informasi apa pun tentang kunjungan ini. Catatan klinik di atas tidak pernah tampil kepada wali.'
            : ($rk['status'] === 'Terbit' ? 'Versi ' . (int) $rk['versi_berlaku'] . ' sedang dilihat wali. Koreksi catatan klinik tidak mengubah ringkasan ini.' : 'Ringkasan telah ditarik dari wali.') ?></p>
        <a class="btn btn-sm btn-outline-primary" href="<?= ah_e(kesehatan_url('kesehatan_ringkasan.php', ['id' => $k['id']])) ?>"><?= match (true) {
            $infoRingkasan['boleh_terbit'] && $rk === null => 'Susun ringkasan untuk wali',
            $infoRingkasan['boleh_terbit'] => 'Kelola ringkasan untuk wali',
            default => 'Lihat ringkasan untuk wali',
        } ?></a>
    </div>
</section>
<?php endif; ?>
<?php if ($riwayat !== null): ?>
<section class="ah-card" aria-labelledby="ah-riwayat-akses">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-riwayat-akses">Riwayat akses</h2><span class="text-muted small"><?= (int) $riwayat['total'] ?> pembukaan</span></div>
    <?php if ($riwayat['rows'] === []): ?><div class="ah-card__body"><p class="text-muted mb-0">Belum ada pembukaan tercatat.</p></div>
    <?php else: ?><div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Riwayat akses rincian kunjungan</caption>
        <thead><tr><th scope="col">Waktu</th><th scope="col">Pengguna</th><th scope="col">Sebagai</th><th scope="col">Bagian</th><th scope="col">Kanal</th></tr></thead><tbody>
        <?php foreach ($riwayat['rows'] as $ra): ?><tr>
            <td data-label="Waktu"><?= ah_e(ah_waktu($ra['waktu'])) ?></td>
            <td data-label="Pengguna"><?= ah_e($ra['pengguna']['nama']) ?></td>
            <td data-label="Sebagai"><?= ah_e(kesehatan_sumber_label($ra['sumber'])) ?></td>
            <td data-label="Bagian"><?= match ($ra['aksi']) { 'baca_versi' => 'Riwayat versi', 'baca_ringkasan' => 'Ringkasan wali', default => 'Rincian' } ?></td>
            <td data-label="Kanal"><?= $ra['kanal'] === 'api' ? 'Aplikasi/API' : 'Website' ?></td>
        </tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
</section>
<?php ah_pagination((int) $riwayat['total'], (int) $riwayat['page'], (int) $riwayat['per_page']);
endif; ?>
<p class="mt-3"><a class="btn btn-outline-secondary" href="<?= ah_e(kesehatan_url('kesehatan.php')) ?>">Kembali ke daftar</a></p>
<?php
ah_page_close();
