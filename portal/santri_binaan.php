<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\MasterData\ProfilSantriService;
use App\Ui\Denial;
use App\Ui\Peran;

/**
 * Profil santri binaan (redesain website Tahap 6, D-10, keputusan AB).
 *
 * PERUBAHAN HAK AKSES: pembimbing (peran Pengurus) dan murobi (peran Guru)
 * dapat membuka profil santri DALAM CAKUPAN penugasan aktif miliknya,
 * hanya-baca, dengan tab Ringkasan · Izin · Sikap · Pembinaan. Data santri
 * lengkap tetap khusus admin (`admin/_guard.php`).
 *
 * Penjagaan di server pada setiap permintaan:
 *   - akun harus masuk; peran aktif menentukan jenis binaan (Pengurus →
 *     pembimbing, Guru → murobi). Admin dialihkan ke profil 360° (D-2);
 *     peran lain (orang tua, Bagian Pendidikan) ditolak 403;
 *   - `ProfilSantriService::cakupanBinaan()` memeriksa akun aktif, role dasar,
 *     data master aktif, penugasan aktif hari ini, tahun ajaran aktif, dan
 *     penempatan santri di kamar/kelas binaan. Santri di luar cakupan dan
 *     santri yang tidak ada sama-sama dijawab 403 (A-5);
 *   - tab Pembinaan memakai layanan V3 dengan akun ini, sehingga kasus
 *     Rahasia tetap hanya tampil bagi pembimbing pemiliknya;
 *   - tidak ada tindakan ubah data. Tombol "Ajukan izin" hanya untuk akun
 *     yang memang memegang peran perizinan pengurus; formulir izin memeriksa
 *     cakupannya sendiri.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$currentUser = authorization()->requireWebUser();
$peran = Peran::active($currentUser);
$santriId = max(0, (int) ($_GET['id'] ?? 0));

if ($peran === Peran::ADMIN) {
    header('Location: ' . app_url('/admin/admin_master_santri.php' . ($santriId > 0 ? '?action=detail&id=' . $santriId : '')));
    exit;
}
$jenis = match ($peran) {
    Peran::PENGURUS => ProfilSantriService::BINAAN_PEMBIMBING,
    Peran::GURU => ProfilSantriService::BINAAN_MUROBI,
    default => null,
};
if ($jenis === null) {
    Denial::page('Profil santri binaan', 'pembimbing dan murobi');
}

$profil = profil_santri_service();
$userId = (int) $currentUser['id'];
$halaman = app_url('/portal/santri_binaan.php');
$labelPeran = $jenis === ProfilSantriService::BINAAN_PEMBIMBING ? 'pembimbing' : 'murobi';

// ---------------------------------------------------------------------------
// Daftar santri binaan
// ---------------------------------------------------------------------------
if ($santriId === 0) {
    $q = App\Database\PageQuery::term($_GET['q'] ?? '');
    $daftar = $profil->daftarBinaan($userId, $jenis, $q, max(1, (int) ($_GET['page'] ?? 1)));
    ah_page_open([
        'title' => 'Santri binaan',
        'user' => $currentUser,
        'active' => 'beranda',
        'description' => 'Santri pada kamar/kelas penugasan ' . $labelPeran . ' aktif Anda. Profil hanya-baca.',
        'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Santri binaan']],
    ]);
    ah_list_search($q, 'Cari nama atau NIS');
    if ($daftar['rows'] === []) {
        echo $q !== ''
            ? ah_empty('Tidak ada santri yang cocok', 'Coba kata lain atau bersihkan pencarian.')
            : ah_empty('Belum ada santri binaan', 'Santri binaan tampil di sini bila Anda memegang penugasan ' . $labelPeran . ' aktif pada semester ini.');
    } else { ?>
        <div class="ah-cards">
            <?php foreach ($daftar['rows'] as $s): ?>
                <a class="ah-icard" href="<?= ah_e($halaman . '?id=' . (int) $s['id']) ?>">
                    <span class="ah-icard__head"><span><?= ah_e($s['nama_santri']) ?></span><span class="ah-badge ah-badge--muted"><?= ah_e($s['jenis_kelamin']) ?></span></span>
                    <span class="ah-icard__meta">NIS <?= ah_e($s['nis']) ?> · <?= ah_e($s['target']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php }
    ah_pagination((int) $daftar['total'], (int) $daftar['page'], (int) $daftar['perPage']);
    ah_page_close();
    exit;
}

// ---------------------------------------------------------------------------
// Profil satu santri (cakupan diperiksa setiap permintaan)
// ---------------------------------------------------------------------------
$cakupan = $profil->cakupanBinaan($userId, $jenis, $santriId);
if ($cakupan === null) {
    Denial::page('Profil santri binaan', 'santri dalam cakupan penugasan ' . $labelPeran . ' aktif Anda', 'Santri ini di luar cakupan binaan Anda.');
}
$ringkas = $profil->ringkasan($santriId);
if ($ringkas === null) {
    Denial::page('Profil santri binaan', 'santri dalam cakupan penugasan ' . $labelPeran . ' aktif Anda');
}

$tabs = ['ringkasan' => 'Ringkasan', 'izin' => 'Izin', 'sikap' => 'Sikap', 'pembinaan' => 'Pembinaan'];
$tab = is_string($_GET['tab'] ?? null) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'ringkasan';
$tabUrl = static fn (string $t): string => $halaman . '?id=' . $santriId . ($t === 'ringkasan' ? '' : '&tab=' . $t);
$nama = (string) $ringkas['nama_santri'];
$bolehAjukan = $jenis === ProfilSantriService::BINAAN_PEMBIMBING
    && in_array(Capabilities::PENGURUS, ui_context($currentUser)['capabilities'], true);

ah_page_open([
    'title' => $nama,
    'heading' => 'Santri binaan',
    'show_heading' => false,
    'user' => $currentUser,
    'active' => 'beranda',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Santri binaan', 'url' => $halaman], ['label' => $nama]],
]);
?>
<section class="ah-card ah-profile-head" aria-label="Ringkasan santri">
    <div class="ah-card__body">
        <span class="ah-avatar" aria-hidden="true"><?= ah_e(mb_strtoupper(mb_substr($nama, 0, 1))) ?></span>
        <div class="ah-profile-head__main"><h1 class="h4 mb-0"><?= ah_e($nama) ?></h1>
            <p class="text-muted mb-0"><?= ah_e(implode(' · ', array_filter(['NIS ' . $ringkas['nis'], $ringkas['kelas'] ?? null, $ringkas['kamar'] ?? null]))) ?></p></div>
        <?= ah_badge('Binaan: ' . $cakupan['target_name'], 'info') ?>
        <?php if ($bolehAjukan): ?><a class="btn btn-primary" href="<?= ah_e(app_url('/portal/izin_buat.php?santri_id=' . $santriId)) ?>"><i class="fas fa-plus" aria-hidden="true"></i> Ajukan izin</a><?php endif; ?>
    </div>
</section>
<?php
echo ah_seg(array_map(static fn (string $k, string $l): array => ['label' => $l, 'url' => $tabUrl($k), 'active' => $k === $tab], array_keys($tabs), $tabs), 'Bagian profil santri binaan');

if ($tab === 'ringkasan'): ?>
    <section class="ah-card" aria-labelledby="ah-ringkasan">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-ringkasan">Ringkasan</h2><span class="text-muted small">Hanya-baca</span></div>
        <div class="ah-card__body"><dl class="ah-kv">
            <div><dt>Kelas</dt><dd><?= ah_e(trim(($ringkas['kelas'] ?? 'Belum ada') . ($ringkas['jenjang'] ? ' · ' . $ringkas['jenjang'] : ''))) ?></dd></div>
            <div><dt>Kamar</dt><dd><?= ah_e($ringkas['kamar'] ?? 'Belum ada') ?></dd></div>
            <div><dt>Jenis kelamin</dt><dd><?= $ringkas['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan' ?></dd></div>
            <div><dt>Wali utama</dt><dd><?= $ringkas['wali_utama'] === null ? 'Belum tercatat' : ah_e($ringkas['wali_utama']['nama'] . ' (' . mb_strtolower((string) $ringkas['wali_utama']['hubungan']) . ')') ?></dd></div>
            <div><dt>Izin semester ini</dt><dd><?= (int) $ringkas['izin_semester'] ?> kali</dd></div>
        </dl></div>
    </section>
<?php elseif ($tab === 'izin'):
    $izin = $profil->izin($santriId); ?>
    <?= ah_stats([
        ['label' => 'Izin semester ini', 'value' => (string) (int) $ringkas['izin_semester']],
        ['label' => 'Disetujui', 'value' => (string) (int) $ringkas['izin_disetujui']],
    ], 'Ringkasan izin') ?>
    <section class="ah-card" aria-labelledby="ah-izin">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-izin">Riwayat izin</h2></div>
        <?php if ($izin === []): ?><div class="ah-card__body"><?= ah_empty('Belum ada izin', 'Santri ini belum pernah diajukan izin.') ?></div>
        <?php else: ?><div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Riwayat izin santri</caption>
            <thead><tr><th scope="col">Tanggal</th><th scope="col">Status</th></tr></thead><tbody>
            <?php foreach ($izin as $i): ?><tr><td><?= ah_e(ah_rentang((string) $i['tgl_izin'], (string) $i['tgl_kembali'], false)) ?></td><td><?= ah_status((string) $i['status']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
    </section>
<?php elseif ($tab === 'sikap'):
    $sikap = $profil->sikap($santriId);
    $sikapUrl = app_url('/portal/sikap_semester.php' . ($jenis === ProfilSantriService::BINAAN_MUROBI ? '?mode=murobi' : '')); ?>
    <section class="ah-card" aria-labelledby="ah-sikap">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-sikap">Sikap semester<?= isset($ringkas['tahun']['tahun']) ? ' ' . ah_e($ringkas['tahun']['semester'] . ' ' . $ringkas['tahun']['tahun']) : '' ?></h2>
            <a class="btn btn-sm btn-outline-primary" href="<?= ah_e($sikapUrl) ?>">Buka Sikap semester</a></div>
        <div class="ah-card__body"><dl class="ah-kv">
            <div><dt>Status</dt><dd><?= $sikap === null ? ah_status('Belum diisi') : ah_status((string) $sikap['status']) ?></dd></div>
            <div><dt>Terakhir diubah</dt><dd><?= $sikap === null ? '—' : ah_e(ah_tanggal((string) $sikap['updated_at'], false)) ?></dd></div>
            <div><dt>Diketahui murobi</dt><dd><?= $sikap !== null && $sikap['diketahui_pada'] ? ah_e(ah_tanggal((string) $sikap['diketahui_pada'], false)) : 'Belum' ?></dd></div>
        </dl></div>
    </section>
<?php else:
    // Layanan V3 dengan akun ini: cakupan dan kerahasiaan ditegakkan layanan.
    $pelanggaran = ['rows' => [], 'total' => 0];
    $kasus = ['rows' => [], 'total' => 0];
    $bacaPelanggaran = true;
    $bacaKasus = true;
    try {
        $pelanggaran = v3_pelanggaran_service()->page($currentUser, ['santri_id' => (string) $santriId]);
    } catch (Throwable) {
        $bacaPelanggaran = false;
    }
    try {
        $kasus = v3_konseling_service()->page($currentUser, ['santri_id' => (string) $santriId]);
    } catch (Throwable) {
        $bacaKasus = false;
    } ?>
    <?= ah_stats([
        ['label' => 'Total poin semester', 'value' => (string) $profil->totalPoin($santriId)],
        ['label' => 'Catatan pelanggaran', 'value' => $bacaPelanggaran ? (string) (int) $pelanggaran['total'] : '—'],
        ['label' => 'Kasus konseling', 'value' => $bacaKasus ? (string) (int) $kasus['total'] : '—'],
    ], 'Ringkasan pembinaan') ?>
    <section class="ah-card" aria-labelledby="ah-pelanggaran">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-pelanggaran">Pelanggaran terbaru</h2></div>
        <?php if (!$bacaPelanggaran): ?><div class="ah-card__body"><p class="text-muted mb-0">Catatan pelanggaran tidak tersedia untuk akun ini.</p></div>
        <?php elseif ($pelanggaran['rows'] === []): ?><div class="ah-card__body"><p class="text-muted mb-0">Belum ada catatan pelanggaran.</p></div>
        <?php else: ?><div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Pelanggaran santri binaan</caption>
            <thead><tr><th scope="col">Waktu</th><th scope="col">Jenis</th><th scope="col">Poin</th><th scope="col">Status</th></tr></thead><tbody>
            <?php foreach (array_slice($pelanggaran['rows'], 0, 10) as $p): ?><tr><td><a class="ah-row-link" href="<?= ah_e(app_url('/portal/v3_pelanggaran_detail.php?id=' . (int) $p['id'])) ?>"><?= ah_e(ah_waktu($p['waktu_kejadian'])) ?></a></td>
                <td><?= ah_e($p['jenis'] ?? $p['kategori']) ?></td><td><?= (int) $p['poin'] ?></td><td><?= ah_status((string) $p['status']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
    </section>
    <section class="ah-card" aria-labelledby="ah-kasus">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-kasus">Kasus konseling</h2></div>
        <?php if (!$bacaKasus): ?><div class="ah-card__body"><p class="text-muted mb-0">Kasus konseling tidak tersedia untuk akun ini.</p></div>
        <?php elseif ($kasus['rows'] === []): ?><div class="ah-card__body"><p class="text-muted mb-0">Belum ada kasus konseling yang dapat Anda lihat.</p></div>
        <?php else: ?><div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Kasus konseling santri binaan</caption>
            <thead><tr><th scope="col">Dibuka</th><th scope="col">Kerahasiaan</th><th scope="col">Status</th></tr></thead><tbody>
            <?php foreach (array_slice($kasus['rows'], 0, 10) as $k): ?><tr><td><a class="ah-row-link" href="<?= ah_e(app_url('/portal/v3_konseling_detail.php?id=' . (int) $k['id'])) ?>"><?= ah_e(ah_tanggal((string) $k['dibuka_pada'], false)) ?></a></td>
                <td><?= ah_e($k['kerahasiaan']) ?></td><td><?= ah_status((string) $k['status']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
    </section>
<?php endif;
ah_page_close();
