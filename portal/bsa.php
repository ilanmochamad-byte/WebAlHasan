<?php

declare(strict_types=1);

use App\Database\PageQuery;

/**
 * Daftar keuangan santri (PRD V6 Fase 1).
 *
 * Petugas BSA melihat santri aktif dalam cakupan unit penugasannya; admin
 * melihat seluruh santri (hanya baca); orang tua melihat anak dengan relasi
 * wali aktif. Daftar berasal dari `BsaService::daftarSantri()`, sehingga
 * santri di luar hak tidak pernah dimuat.
 */

require_once __DIR__ . '/_bsa.php';

$q = PageQuery::term($_GET['q'] ?? '');
$daftar = $bsa->daftarSantri($currentUser, ['q' => $q, 'page' => $_GET['page'] ?? 1]);
$sumber = $aksesBsa['sumber'];
$wali = $sumber === 'wali';

ah_page_open([
    'title' => bsa_judul($aksesBsa),
    'user' => $currentUser,
    'active' => 'bsa',
    'description' => match (true) {
        $aksesBsa['kelola'] => 'BSA — tagihan bulanan dan tabungan santri aktif pada ' . bsa_cakupan_teks($aksesBsa) . '.',
        $wali => 'Tagihan dan tabungan anak Anda di BSA (Bank Santri Al Hasan).',
        default => 'Pengawasan admin: hanya baca. Transaksi dicatat petugas BSA berpenugasan aktif.',
    },
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => bsa_judul($aksesBsa)]],
]);
echo bsa_nav($aksesBsa, 'bsa.php', $tagihanBsa);
if (!$aksesBsa['kelola'] && !$wali): ?>
    <div class="ah-ribbon" role="note"><i class="fas fa-eye mt-1" aria-hidden="true"></i>
        <span><b>Hanya baca.</b> Admin mengawasi tagihan, saldo, dan transaksi BSA tetapi tidak mencatat atau mengubahnya.</span></div>
<?php endif;
if (!$wali) {
    ah_list_search($q, 'Cari nama santri atau NIS');
}
if ($daftar['rows'] === []) {
    echo match (true) {
        $q !== '' => ah_empty('Tidak ada santri yang cocok', 'Coba kata lain atau bersihkan pencarian.'),
        $wali => ah_empty('Belum ada data anak', 'Data keuangan tampil untuk anak yang terhubung dengan akun Anda.'),
        $aksesBsa['kelola'] => ah_empty('Belum ada santri dalam cakupan', 'Santri aktif pada ' . bsa_cakupan_teks($aksesBsa) . ' akan tampil di sini.'),
        default => ah_empty('Belum ada santri', 'Santri aktif akan tampil di sini.'),
    };
} else { ?>
    <section class="ah-card" aria-labelledby="ah-daftar-bsa">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-daftar-bsa"><?= $wali ? 'Anak' : 'Santri' ?></h2><span class="text-muted small"><?= (int) $daftar['total'] ?> santri</span></div>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Saldo tabungan dan sisa tagihan per santri</caption>
            <thead><tr><th scope="col">Santri</th><th scope="col">Kelas</th><th scope="col" class="text-end">Saldo tabungan</th><th scope="col" class="text-end">Sisa tagihan</th></tr></thead><tbody>
            <?php foreach ($daftar['rows'] as $r): ?>
                <tr>
                    <td data-label="Santri"><a class="ah-row-link" href="<?= ah_e(bsa_url('bsa_santri.php', ['id' => $r['id']])) ?>"><span class="ah-cell-wrap"><?= ah_e($r['nama']) ?><span class="ah-cell-sub">NIS <?= ah_e($r['nis']) ?></span></span></a></td>
                    <td data-label="Kelas"><?= $r['kelas'] === null ? '<span class="text-muted">Belum ditempatkan</span>' : ah_e($r['kelas'] . ($r['unit'] ? ' · ' . $r['unit'] : '')) ?></td>
                    <td data-label="Saldo tabungan" class="text-end"><?= ah_e(bsa_rp($r['saldo'])) ?></td>
                    <td data-label="Sisa tagihan" class="text-end"><?= $r['sisa_tagihan'] > 0 ? ah_e(bsa_rp($r['sisa_tagihan'])) . ' <span class="ah-cell-sub">' . (int) $r['tagihan_terbuka'] . ' tagihan</span>' : '<span class="text-muted">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
    </section>
<?php }
ah_pagination((int) $daftar['total'], (int) $daftar['page'], (int) $daftar['per_page']);
ah_page_close();
