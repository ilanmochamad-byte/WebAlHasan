<?php

declare(strict_types=1);

/**
 * Daftar kunjungan klinik (PRD V5 Fase 1–2).
 *
 * Petugas kesehatan berpenugasan aktif dan admin membaca daftar kunjungan
 * seluruh santri; pembimbing dan murobi terkait (Fase 2) hanya kunjungan
 * Tercatat santri dalam cakupan penugasan aktifnya — disaring per baris di
 * server, bukan di halaman ini. Daftar sengaja TIDAK memuat isi klinis
 * (keluhan, catatan, tindakan, obat, rujukan): isi hanya tampil di halaman
 * detail yang setiap pembukaannya tercatat di riwayat akses. Penanda jumlah
 * obat, rujukan menunggu hasil, dan tindak lanjut terbuka dihitung per
 * kunjungan sehingga satu kunjungan tetap satu baris.
 *
 * Penyaring operasional (santri, tanggal, status rujukan, tindak lanjut)
 * dan ringkasan angkanya memakai kueri yang sama dengan daftar, sehingga
 * angka tidak pernah memuat baris yang tidak boleh dilihat akun ini.
 */

require_once __DIR__ . '/_kesehatan.php';

$daftar = $kesehatan->daftar($currentUser, $_GET);
$f = $daftar['filter'];
$catat = $aksesKesehatan['catat'];
$binaan = $aksesKesehatan['cakupan'] === App\Kesehatan\KesehatanService::CAKUPAN_BINAAN;
$satuSantri = $f['santri_id'] > 0 && $daftar['rows'] !== [] ? $daftar['rows'][0]['santri'] : null;

ah_page_open([
    'title' => $satuSantri !== null ? 'Riwayat kesehatan ' . $satuSantri['nama'] : 'Kesehatan santri',
    'heading' => $satuSantri !== null ? 'Riwayat kesehatan santri' : null,
    'user' => $currentUser,
    'active' => 'kesehatan',
    'description' => $satuSantri !== null
        ? $satuSantri['nama'] . ' · NIS ' . $satuSantri['nis'] . ' — kunjungan urut terbaru.'
        : ($catat ? 'Kunjungan klinik seluruh santri aktif. Isi klinis hanya tampil di halaman detail.'
            : ($binaan ? 'Kunjungan tercatat santri binaan Anda. Hanya baca; setiap pembukaan detail tercatat.'
                : 'Pengawasan admin: hanya baca. Setiap pembukaan detail tercatat di riwayat akses.')),
    'breadcrumbs' => array_values(array_filter([
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        $f['santri_id'] > 0 ? ['label' => 'Kesehatan', 'url' => kesehatan_url('kesehatan.php')] : ['label' => 'Kesehatan'],
        $f['santri_id'] > 0 ? ['label' => 'Riwayat santri'] : null,
    ])),
    'actions' => $catat ? '<a class="btn btn-primary" href="' . ah_e(kesehatan_url('kesehatan_buat.php', $f['santri_id'] > 0 ? ['santri_id' => $f['santri_id']] : [])) . '"><i class="fas fa-plus" aria-hidden="true"></i> Catat kunjungan</a>' : '',
]);
if (!$catat): ?>
    <div class="ah-ribbon" role="note"><i class="fas fa-eye mt-1" aria-hidden="true"></i>
        <span><b>Hanya baca.</b> <?= $binaan
            ? 'Pembimbing dan murobi membaca catatan kesehatan santri dalam cakupan penugasan aktif, tanpa mengubah. Draf petugas tidak tampil.'
            : 'Admin mengawasi catatan kesehatan tetapi tidak mengubah isi klinis. Pencatatan dilakukan petugas kesehatan berpenugasan aktif.' ?></span></div>
<?php endif;

$r = $daftar['ringkasan'];
$saringan = ['santri_id' => $f['santri_id'] ?: null];
echo ah_stats(array_values(array_filter([
    ['label' => 'Kunjungan', 'value' => (string) $r['kunjungan']],
    ['label' => 'Dengan obat', 'value' => (string) $r['dengan_obat']],
    ['label' => 'Rujukan menunggu hasil', 'value' => (string) $r['rujukan_menunggu'], 'warn' => $r['rujukan_menunggu'] > 0,
        'url' => '?' . ah_query(['rujukan' => 'menunggu', 'page' => null])],
    ['label' => 'Tindak lanjut terbuka', 'value' => (string) $r['tindak_lanjut_terbuka'], 'warn' => $r['tindak_lanjut_terbuka'] > 0,
        'url' => '?' . ah_query(['tindak_lanjut' => 'terbuka', 'page' => null])],
    $binaan ? null : ['label' => 'Draf', 'value' => (string) $r['draf'], 'url' => '?' . ah_query(['status' => 'Draf', 'page' => null])],
])), 'Ringkasan kunjungan sesuai saringan');

$aktif = (int) ($f['q'] !== '') + (int) ($f['dari'] !== '') + (int) ($f['sampai'] !== '') + (int) ($f['rujukan'] !== '') + (int) ($f['tindak_lanjut'] !== '') + (int) ($f['status'] !== '');
ah_filter_open($aktif, $saringan);
?>
    <div class="ah-filter__wide"><label class="form-label" for="q">Cari santri</label>
        <input class="form-control" type="search" id="q" name="q" maxlength="100" value="<?= ah_e($f['q']) ?>" placeholder="Nama santri atau NIS"></div>
    <div><label class="form-label" for="dari">Dari tanggal</label>
        <input class="form-control" type="date" id="dari" name="dari" value="<?= ah_e($f['dari']) ?>"></div>
    <div><label class="form-label" for="sampai">Sampai</label>
        <input class="form-control" type="date" id="sampai" name="sampai" value="<?= ah_e($f['sampai']) ?>"></div>
    <div><label class="form-label" for="rujukan">Rujukan</label>
        <select class="form-select" id="rujukan" name="rujukan">
            <?php foreach (['' => 'Semua', 'menunggu' => 'Menunggu hasil', 'diketahui' => 'Hasil diketahui', 'ada' => 'Ada rujukan', 'tanpa' => 'Tanpa rujukan'] as $v => $label): ?>
                <option value="<?= ah_e($v) ?>"<?= $f['rujukan'] === $v ? ' selected' : '' ?>><?= ah_e($label) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="tindak_lanjut">Tindak lanjut</label>
        <select class="form-select" id="tindak_lanjut" name="tindak_lanjut">
            <?php foreach (['' => 'Semua', 'terbuka' => 'Ada yang terbuka', 'tanpa_terbuka' => 'Tidak ada yang terbuka'] as $v => $label): ?>
                <option value="<?= ah_e($v) ?>"<?= $f['tindak_lanjut'] === $v ? ' selected' : '' ?>><?= ah_e($label) ?></option>
            <?php endforeach; ?>
        </select></div>
    <?php if (!$binaan): ?>
    <div><label class="form-label" for="status">Status catatan</label>
        <select class="form-select" id="status" name="status">
            <?php foreach (['' => 'Semua', 'Tercatat' => 'Tercatat', 'Draf' => 'Draf'] as $v => $label): ?>
                <option value="<?= ah_e($v) ?>"<?= $f['status'] === $v ? ' selected' : '' ?>><?= ah_e($label) ?></option>
            <?php endforeach; ?>
        </select></div>
    <?php endif; ?>
<?php ah_filter_close($saringan);

if ($daftar['rows'] === []) {
    echo $aktif > 0 || $f['santri_id'] > 0
        ? ah_empty('Tidak ada kunjungan yang cocok', 'Ubah atau bersihkan saringan.')
        : ah_empty('Belum ada kunjungan tercatat',
            $catat ? 'Kunjungan klinik yang Anda catat akan tampil di sini.'
                : ($binaan ? 'Kunjungan tercatat santri binaan Anda akan tampil di sini.' : 'Kunjungan yang dicatat petugas kesehatan akan tampil di sini.'),
            $catat ? '<a class="btn btn-primary" href="' . ah_e(kesehatan_url('kesehatan_buat.php')) . '">Catat kunjungan</a>' : null);
} else { ?>
    <section class="ah-card" aria-labelledby="ah-daftar-kunjungan">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-daftar-kunjungan">Kunjungan klinik</h2><span class="text-muted small"><?= (int) $daftar['total'] ?> kunjungan</span></div>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Daftar kunjungan klinik, satu baris per kunjungan</caption>
            <thead><tr><th scope="col">Waktu</th><th scope="col">Santri</th><th scope="col">Petugas</th><th scope="col">Rincian</th><th scope="col">Status</th></tr></thead><tbody>
            <?php foreach ($daftar['rows'] as $row): $p = $row['penanda']; ?>
                <tr>
                    <td data-label="Waktu"><a class="ah-row-link" href="<?= ah_e(kesehatan_url('kesehatan_detail.php', ['id' => $row['id']])) ?>"><?= ah_e(ah_waktu($row['waktu_kunjungan'])) ?></a></td>
                    <td data-label="Santri"><span class="ah-cell-wrap"><?= ah_e($row['santri']['nama']) ?><span class="ah-cell-sub">NIS <?= ah_e($row['santri']['nis']) ?></span></span></td>
                    <td data-label="Petugas"><?= ah_e($row['petugas']['nama']) ?></td>
                    <td data-label="Rincian"><span class="d-inline-flex flex-wrap gap-1">
                        <?= $p['obat'] > 0 ? ah_badge($p['obat'] . ' obat', 'muted') : '' ?>
                        <?= $p['rujukan_menunggu'] > 0 ? ah_badge('Rujukan menunggu hasil', 'warn') : ($p['rujukan'] > 0 ? ah_badge('Rujukan', 'ok') : '') ?>
                        <?= $p['tindak_lanjut_terbuka'] > 0 ? ah_badge($p['tindak_lanjut_terbuka'] . ' tindak lanjut terbuka', 'warn') : '' ?>
                        <?= $p['obat'] + $p['rujukan'] + $p['tindak_lanjut'] === 0 ? '<span class="text-muted">—</span>' : '' ?>
                    </span></td>
                    <td data-label="Status"><?= ah_status($row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
    </section>
<?php }
ah_pagination((int) $daftar['total'], (int) $daftar['page'], (int) $daftar['per_page']);
ah_page_close();
