<?php

declare(strict_types=1);

use App\Kesehatan\KesehatanException;
use App\Kesehatan\KesehatanService;
use App\Ui\Denial;

/**
 * Kesehatan anak untuk orang tua/wali (PRD V5 Fase 3).
 *
 * Hanya ringkasan yang sengaja diterbitkan petugas klinik, dari proyeksi
 * khusus `RingkasanService` yang tidak membaca catatan klinis. Relasi wali
 * aktif (akun, master wali, `santri_wali`, santri) diperiksa server pada
 * setiap daftar dan detail — termasuk ketika dibuka dari tautan notifikasi.
 * Tidak ada, milik anak lain, atau relasi dicabut sama-sama ditolak 403.
 * Belum masuk → diarahkan ke halaman masuk lalu kembali ke sini.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$currentUser = authorization()->requireWebUser();
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

$service = kesehatan_ringkasan_service();
$id = max(0, (int) ($_GET['id'] ?? 0));
try {
    $akses = $service->aksesWali($currentUser);
    if (!$akses['wali']) {
        Denial::page('Kesehatan anak', 'akun orang tua/wali yang aktif');
    }
    $detail = $id > 0 ? $service->detailWali($currentUser, $id, KesehatanService::KANAL_WEB) : null;
    $anakPilih = (int) ($_GET['santri_id'] ?? 0);
    $daftar = $id > 0 ? null : $service->daftarWali($currentUser, ['santri_id' => $anakPilih, 'page' => $_GET['page'] ?? 1]);
} catch (KesehatanException $exception) {
    Denial::render('Informasi kesehatan tidak dapat diakses', $exception->getMessage(), $exception->status,
        '<a class="btn btn-outline-primary" href="' . ah_e(app_url('/portal/kesehatan_wali.php')) . '">Kembali ke daftar</a>', false);
}

$judul = static fn (array $r): string => 'Informasi kesehatan · ' . ah_tanggal($r['tanggal_kunjungan'], false);
ah_page_open([
    'title' => $detail !== null ? $judul($detail) : 'Kesehatan anak',
    'heading' => $detail !== null ? $judul($detail) : 'Kesehatan anak',
    'user' => $currentUser,
    'active' => 'kesehatan_wali',
    'description' => $detail !== null ? $detail['santri']['nama'] : 'Ringkasan kunjungan klinik yang diterbitkan pesantren untuk anak Anda.',
    'breadcrumbs' => $detail !== null ? [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Kesehatan anak', 'url' => app_url('/portal/kesehatan_wali.php')],
        ['label' => ah_tanggal($detail['tanggal_kunjungan'], false)],
    ] : null,
]);

if ($detail !== null): ?>
<section class="ah-card" aria-labelledby="ah-info-kesehatan">
    <div class="ah-card__head"><span id="ah-info-kesehatan"><?= ah_e($judul($detail)) ?></span><?= ah_status($detail['status']) ?></div>
    <div class="ah-card__body">
        <?php $ringkasanWali = $detail; require __DIR__ . '/partials/kesehatan_ringkasan_konten.php'; ?>
        <p class="small text-muted mt-3 mb-0">Diterbitkan <?= ah_e(ah_waktu($detail['diterbitkan_pada'])) ?>. Untuk pertanyaan, hubungi klinik pesantren.</p>
    </div>
</section>
<p><a href="<?= ah_e(app_url('/portal/kesehatan_wali.php')) ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> Semua informasi kesehatan</a></p>
<?php else:
    if (count($daftar['anak']) > 1) {
        $chips = [['label' => 'Semua anak', 'url' => '?' . ah_query(['santri_id' => null, 'page' => null]), 'active' => $anakPilih === 0]];
        foreach ($daftar['anak'] as $s) {
            $chips[] = ['label' => $s['nama'], 'url' => '?' . ah_query(['santri_id' => $s['id'], 'page' => null]), 'active' => $anakPilih === $s['id']];
        }
        echo ah_chips($chips, 'Pilih anak');
    } ?>
<section class="ah-card" aria-labelledby="ah-daftar-kesehatan">
    <div class="ah-card__head"><span id="ah-daftar-kesehatan">Informasi kesehatan</span><span class="text-muted small"><?= (int) $daftar['total'] ?> informasi</span></div>
    <?php if ($daftar['rows'] === []): ?><div class="ah-card__body"><?= ah_empty('Belum ada informasi kesehatan', 'Informasi muncul setelah petugas klinik pesantren menerbitkan ringkasan kunjungan anak Anda.') ?></div>
    <?php else: ?><ul class="ah-qlist ah-pub-list"><?php foreach ($daftar['rows'] as $r): ?>
        <li><a href="<?= ah_e(app_url('/portal/kesehatan_wali.php?id=' . $r['id'])) ?>"><span class="ah-qlist__main"><b><?= ah_e($judul($r)) ?></b>
            <small><?= ah_e($r['santri']['nama'] . ($r['diperbarui'] ? ' · diperbarui' : '') . ($r['ada_tindak_lanjut'] ? ' · ada yang perlu diperhatikan' : '')) ?></small></span><?= ah_status($r['status']) ?></a></li>
    <?php endforeach; ?></ul><?php endif; ?>
</section>
<?php ah_pagination((int) $daftar['total'], (int) $daftar['page'], (int) $daftar['per_page']);
endif;
ah_page_close();
