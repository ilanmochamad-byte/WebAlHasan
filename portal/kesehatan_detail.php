<?php

declare(strict_types=1);

use App\Kesehatan\KesehatanException;
use App\Kesehatan\KesehatanService;
use App\Ui\Denial;

/**
 * Detail satu kunjungan klinik (PRD V5 Fase 1).
 *
 * Isi klinis hanya tampil di sini, dan setiap pembukaan halaman ini tercatat
 * di `kesehatan_akses_log` (siapa, kunjungan, sumber hak, kanal, waktu) tanpa
 * menyalin isi. Admin melihat bagian "Riwayat akses"; tidak ada tombol ubah
 * bagi siapa pun pada Fase 1 (koreksi berversi datang di Fase 2).
 */

require_once __DIR__ . '/_kesehatan.php';

$id = max(0, (int) ($_GET['id'] ?? 0));
try {
    $k = $kesehatan->detail($currentUser, $id, KesehatanService::KANAL_WEB);
} catch (KesehatanException $exception) {
    if ($exception->status === 404) {
        Denial::render('Kunjungan tidak ditemukan', 'Kunjungan yang Anda buka tidak ada.', 404, '<a class="btn btn-outline-primary" href="' . ah_e(kesehatan_url('kesehatan.php')) . '">Kembali ke daftar</a>', false);
    }
    Denial::page('Detail kunjungan klinik', 'petugas kesehatan berpenugasan aktif dan admin', $exception->getMessage(), $exception->status);
}
$riwayat = $k['akses']['riwayat_akses'] ? $kesehatan->riwayatAkses($currentUser, $id, $_GET['page'] ?? 1) : null;
$teks = static fn (?string $isi): string => $isi === null || $isi === '' ? '<span class="text-muted">—</span>' : nl2br(ah_e($isi));

ah_page_open([
    'title' => 'Kunjungan ' . $k['santri']['nama'],
    'heading' => 'Detail kunjungan',
    'user' => $currentUser,
    'active' => 'kesehatan',
    'description' => ah_waktu($k['waktu_kunjungan']) . ' · NIS ' . $k['santri']['nis'],
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Kesehatan', 'url' => kesehatan_url('kesehatan.php')], ['label' => 'Detail kunjungan']],
]);
if (!$k['akses']['catat']): ?>
    <div class="ah-ribbon" role="note"><i class="fas fa-eye mt-1" aria-hidden="true"></i>
        <span><b>Hanya baca.</b> Pembukaan rincian ini tercatat di riwayat akses.</span></div>
<?php endif; ?>
<section class="ah-card" aria-labelledby="ah-kunjungan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-kunjungan"><?= ah_e($k['santri']['nama']) ?></h2><?= ah_status($k['status']) ?></div>
    <div class="ah-card__body"><dl class="ah-kv">
        <div><dt>Waktu kunjungan</dt><dd><?= ah_e(ah_waktu($k['waktu_kunjungan'])) ?></dd></div>
        <div><dt>Santri</dt><dd><?= ah_e($k['santri']['nama'] . ' · NIS ' . $k['santri']['nis']) ?></dd></div>
        <div><dt>Petugas pencatat</dt><dd><?= ah_e($k['petugas']['nama']) ?></dd></div>
        <div><dt>Dicatat pada</dt><dd><?= ah_e(ah_waktu($k['dicatat_pada'])) ?></dd></div>
        <div><dt>Versi</dt><dd><?= (int) $k['versi'] ?></dd></div>
    </dl></div>
</section>
<section class="ah-card" aria-labelledby="ah-isi-klinis">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-isi-klinis">Catatan klinik</h2><span class="text-muted small">Internal</span></div>
    <div class="ah-card__body"><dl class="ah-kv ah-kv--stack">
        <div><dt>Keluhan</dt><dd class="ah-break"><?= $teks($k['keluhan']) ?></dd></div>
        <div><dt>Catatan klinis</dt><dd class="ah-break"><?= $teks($k['catatan_klinis']) ?></dd></div>
        <div><dt>Tindakan</dt><dd class="ah-break"><?= $teks($k['tindakan']) ?></dd></div>
    </dl></div>
</section>
<?php if ($riwayat !== null): ?>
<section class="ah-card" aria-labelledby="ah-riwayat-akses">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-riwayat-akses">Riwayat akses</h2><span class="text-muted small"><?= (int) $riwayat['total'] ?> pembukaan</span></div>
    <?php if ($riwayat['rows'] === []): ?><div class="ah-card__body"><p class="text-muted mb-0">Belum ada pembukaan tercatat.</p></div>
    <?php else: ?><div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Riwayat akses rincian kunjungan</caption>
        <thead><tr><th scope="col">Waktu</th><th scope="col">Pengguna</th><th scope="col">Sebagai</th><th scope="col">Kanal</th></tr></thead><tbody>
        <?php foreach ($riwayat['rows'] as $a): ?><tr>
            <td data-label="Waktu"><?= ah_e(ah_waktu($a['waktu'])) ?></td>
            <td data-label="Pengguna"><?= ah_e($a['pengguna']['nama']) ?></td>
            <td data-label="Sebagai"><?= ah_e(kesehatan_sumber_label($a['sumber'])) ?></td>
            <td data-label="Kanal"><?= $a['kanal'] === 'api' ? 'Aplikasi/API' : 'Website' ?></td>
        </tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
</section>
<?php ah_pagination((int) $riwayat['total'], (int) $riwayat['page'], (int) $riwayat['per_page']);
endif; ?>
<p class="mt-3"><a class="btn btn-outline-secondary" href="<?= ah_e(kesehatan_url('kesehatan.php')) ?>">Kembali ke daftar</a></p>
<?php
ah_page_close();
