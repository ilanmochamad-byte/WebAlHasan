<?php

declare(strict_types=1);

use App\Database\PageQuery;

/**
 * Daftar kunjungan klinik (PRD V5 Fase 1).
 *
 * Petugas kesehatan berpenugasan aktif dan admin membaca daftar kunjungan
 * seluruh santri. Daftar sengaja TIDAK memuat isi klinis (keluhan, catatan,
 * tindakan): isi hanya tampil di halaman detail yang setiap pembukaannya
 * tercatat di riwayat akses. Tombol "Catat kunjungan" hanya untuk petugas;
 * admin hanya membaca (server menolak mutasi admin).
 */

require_once __DIR__ . '/_kesehatan.php';

$q = PageQuery::term($_GET['q'] ?? '');
$daftar = $kesehatan->daftar($currentUser, ['q' => $q, 'page' => $_GET['page'] ?? 1]);
$catat = $aksesKesehatan['catat'];

ah_page_open([
    'title' => 'Kesehatan santri',
    'user' => $currentUser,
    'active' => 'kesehatan',
    'description' => $catat
        ? 'Kunjungan klinik seluruh santri aktif. Isi klinis hanya tampil di halaman detail.'
        : 'Pengawasan admin: hanya baca. Setiap pembukaan detail tercatat di riwayat akses.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Kesehatan']],
    'actions' => $catat ? '<a class="btn btn-primary" href="' . ah_e(kesehatan_url('kesehatan_buat.php')) . '"><i class="fas fa-plus" aria-hidden="true"></i> Catat kunjungan</a>' : '',
]);
if (!$catat): ?>
    <div class="ah-ribbon" role="note"><i class="fas fa-eye mt-1" aria-hidden="true"></i>
        <span><b>Hanya baca.</b> Admin mengawasi catatan kesehatan tetapi tidak mengubah isi klinis. Pencatatan dilakukan petugas kesehatan berpenugasan aktif.</span></div>
<?php endif;
ah_list_search($q, 'Cari nama santri atau NIS');
if ($daftar['rows'] === []) {
    echo $q !== ''
        ? ah_empty('Tidak ada kunjungan yang cocok', 'Coba kata lain atau bersihkan pencarian.')
        : ah_empty('Belum ada kunjungan tercatat', $catat ? 'Kunjungan klinik yang Anda catat akan tampil di sini.' : 'Kunjungan yang dicatat petugas kesehatan akan tampil di sini.',
            $catat ? '<a class="btn btn-primary" href="' . ah_e(kesehatan_url('kesehatan_buat.php')) . '">Catat kunjungan</a>' : null);
} else { ?>
    <section class="ah-card" aria-labelledby="ah-daftar-kunjungan">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-daftar-kunjungan">Kunjungan klinik</h2><span class="text-muted small"><?= (int) $daftar['total'] ?> kunjungan</span></div>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Daftar kunjungan klinik</caption>
            <thead><tr><th scope="col">Waktu</th><th scope="col">Santri</th><th scope="col">Petugas</th><th scope="col">Status</th></tr></thead><tbody>
            <?php foreach ($daftar['rows'] as $r): ?>
                <tr>
                    <td data-label="Waktu"><a class="ah-row-link" href="<?= ah_e(kesehatan_url('kesehatan_detail.php', ['id' => $r['id']])) ?>"><?= ah_e(ah_waktu($r['waktu_kunjungan'])) ?></a></td>
                    <td data-label="Santri"><span class="ah-cell-wrap"><?= ah_e($r['santri']['nama']) ?><span class="ah-cell-sub">NIS <?= ah_e($r['santri']['nis']) ?></span></span></td>
                    <td data-label="Petugas"><?= ah_e($r['petugas']['nama']) ?></td>
                    <td data-label="Status"><?= ah_status($r['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
    </section>
<?php }
ah_pagination((int) $daftar['total'], (int) $daftar['page'], (int) $daftar['per_page']);
ah_page_close();
