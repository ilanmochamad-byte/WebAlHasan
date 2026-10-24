<?php

declare(strict_types=1);

/**
 * Laporan layanan klinik (PRD V5 Fase 5).
 *
 * Angka kunjungan, obat, rujukan, tindak lanjut, dan ringkasan wali dalam
 * satu periode, serta daftar kunjungan yang masih menunggu hasil rujukan
 * atau memiliki tindak lanjut terbuka. Petugas kesehatan dan admin melihat
 * seluruh santri; pembimbing dan murobi terkait hanya kunjungan Tercatat
 * santri binaannya — penyaring yang sama dengan daftar, dipasang di server
 * (`LaporanService`). Admin juga melihat pengawasan: koreksi, publikasi
 * ringkasan, akses rincian, antrean notifikasi, dan aktivitas petugas.
 *
 * Laporan sengaja TIDAK memuat isi klinis (keluhan, obat, tujuan/hasil
 * rujukan, uraian tindak lanjut, teks ringkasan, alasan koreksi); isi hanya
 * di halaman detail yang setiap pembukaannya tercatat. Tautan angka membuka
 * daftar kunjungan dengan saringan yang sama sehingga angka dapat ditelusuri.
 */

require_once __DIR__ . '/_kesehatan.php';

use App\Kesehatan\KesehatanException;
use App\Kesehatan\KesehatanService;

$binaan = $aksesKesehatan['cakupan'] === KesehatanService::CAKUPAN_BINAAN;
$galat = null;
try {
    $lap = kesehatan_laporan_service()->laporan($currentUser, $_GET);
} catch (KesehatanException $exception) {
    if ($exception->status !== 422) {
        throw $exception;
    }
    // Periode tidak valid: tampilkan pesan dan laporan bawaan, bukan halaman kosong.
    $galat = $exception->getMessage();
    $lap = kesehatan_laporan_service()->laporan($currentUser, ['kelas_id' => $_GET['kelas_id'] ?? null]);
}
$f = $lap['filter'];
$p = $lap['periode'];
$k = $lap['kunjungan'];
$angka = static fn (int $n): string => number_format($n, 0, ',', '.');
$namaBulan = static fn (string $bulan): string => ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][(int) substr($bulan, 5, 2) - 1]
    . ' ' . substr($bulan, 0, 4);
// Tautan ke daftar kunjungan dengan periode yang sama (kelas tidak tersedia pada saringan daftar).
$keDaftar = static fn (array $saring = []): ?string => $f['kelas_id'] === ''
    ? kesehatan_url('kesehatan.php', ['dari' => $p['dari'], 'sampai' => $p['sampai']] + $saring) : null;

ah_page_open([
    'title' => 'Laporan layanan klinik',
    'user' => $currentUser,
    'active' => 'kesehatan',
    'description' => ($binaan ? 'Santri binaan Anda, kunjungan tercatat saja. ' : '')
        . 'Periode ' . ah_rentang($p['dari'], $p['sampai'], false) . ' (' . $p['hari'] . ' hari). Tanpa isi klinis.',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Kesehatan', 'url' => kesehatan_url('kesehatan.php')],
        ['label' => 'Laporan'],
    ],
    'actions' => '<a class="btn btn-outline-secondary" href="' . ah_e(kesehatan_url('kesehatan.php')) . '"><i class="fas fa-list" aria-hidden="true"></i> Daftar kunjungan</a>',
]);
if ($galat !== null): ?>
    <div class="alert alert-warning" role="alert"><?= ah_e($galat) ?> Laporan di bawah memakai periode bawaan.</div>
<?php endif; ?>
    <div class="ah-ribbon" role="note"><i class="fas fa-shield mt-1" aria-hidden="true"></i>
        <span><b>Hanya angka.</b> <?= $binaan
            ? 'Laporan dihitung dari kunjungan tercatat santri dalam cakupan penugasan aktif Anda; draf petugas dan santri lain tidak dihitung.'
            : 'Isi klinis tidak ditampilkan. Buka detail kunjungan untuk membaca rincian; setiap pembukaan tercatat di riwayat akses.' ?></span></div>
<?php
$aktif = (int) (($_GET['dari'] ?? '') !== '') + (int) (($_GET['sampai'] ?? '') !== '') + (int) ($f['kelas_id'] !== '');
ah_filter_open($aktif);
?>
    <div><label class="form-label" for="dari">Dari tanggal</label>
        <input class="form-control" type="date" id="dari" name="dari" value="<?= ah_e($p['dari']) ?>" required></div>
    <div><label class="form-label" for="sampai">Sampai</label>
        <input class="form-control" type="date" id="sampai" name="sampai" value="<?= ah_e($p['sampai']) ?>" required></div>
    <div><label class="form-label" for="kelas_id">Kelas saat ini</label>
        <select class="form-select" id="kelas_id" name="kelas_id">
            <option value="">Semua kelas</option>
            <?php foreach ($lap['kelas_pilihan'] as $kelas): ?>
                <option value="<?= (int) $kelas['id'] ?>"<?= $f['kelas_id'] === (string) $kelas['id'] ? ' selected' : '' ?>><?= ah_e($kelas['nama']) ?></option>
            <?php endforeach; ?>
            <option value="tanpa"<?= $f['kelas_id'] === 'tanpa' ? ' selected' : '' ?>>Belum ditempatkan di kelas</option>
        </select></div>
<?php ah_filter_close();

echo '<h2 class="h6 mt-3">Kunjungan</h2>';
echo ah_stats(array_values(array_filter([
    ['label' => 'Kunjungan', 'value' => $angka($k['total']), 'url' => $keDaftar(), 'hint' => $angka($k['santri']) . ' santri'],
    ['label' => 'Tercatat', 'value' => $angka($k['tercatat']), 'url' => $binaan ? null : $keDaftar(['status' => 'Tercatat'])],
    $k['draf'] === null ? null : ['label' => 'Draf', 'value' => $angka($k['draf']), 'url' => $keDaftar(['status' => 'Draf'])],
    ['label' => 'Dengan obat', 'value' => $angka($k['dengan_obat']), 'hint' => $angka($lap['obat']['pemberian']) . ' pemberian obat'],
    ['label' => 'Dikoreksi', 'value' => $angka($k['dikoreksi']), 'hint' => 'versi lebih dari satu'],
])), 'Ringkasan kunjungan');

echo '<h2 class="h6 mt-3">Rujukan dan tindak lanjut</h2>';
$r = $lap['rujukan'];
$t = $lap['tindak_lanjut'];
echo ah_stats([
    ['label' => 'Rujukan', 'value' => $angka($r['total']), 'hint' => $angka($k['dengan_rujukan']) . ' kunjungan', 'url' => $keDaftar(['rujukan' => 'ada'])],
    ['label' => 'Menunggu hasil', 'value' => $angka($r['menunggu']), 'warn' => $r['menunggu'] > 0, 'url' => $keDaftar(['rujukan' => 'menunggu'])],
    ['label' => 'Hasil diketahui', 'value' => $angka($r['diketahui'])],
    ['label' => 'Tindak lanjut terbuka', 'value' => $angka($t['terbuka']), 'warn' => $t['terbuka'] > 0, 'hint' => 'dari ' . $angka($t['total']) . ' tindak lanjut',
        'url' => $keDaftar(['tindak_lanjut' => 'terbuka'])],
    ['label' => 'Lewat target', 'value' => $angka($t['terlambat']), 'warn' => $t['terlambat'] > 0],
    ['label' => 'Selesai', 'value' => $angka($t['selesai'])],
], 'Ringkasan rujukan dan tindak lanjut');

if ($lap['ringkasan_wali'] !== null) {
    $w = $lap['ringkasan_wali'];
    echo '<h2 class="h6 mt-3">Ringkasan untuk wali</h2>';
    echo ah_stats([
        ['label' => 'Berlaku (terbit)', 'value' => $angka($w['terbit'])],
        ['label' => 'Ditarik', 'value' => $angka($w['ditarik'])],
        ['label' => 'Tercatat tanpa ringkasan', 'value' => $angka($w['belum']), 'hint' => 'belum pernah diterbitkan'],
    ], 'Ringkasan untuk wali');
}

/** Tabel kunjungan tertunda (tanpa isi rincian). */
$tabelTertunda = static function (string $id, string $judul, array $data, string $kolomTanggal, string $kosong): void {
    ?>
    <section class="ah-card mt-3" aria-labelledby="<?= ah_e($id) ?>">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="<?= ah_e($id) ?>"><?= ah_e($judul) ?></h2>
            <span class="text-muted small"><?= (int) $data['total'] ?> kunjungan · semua periode</span></div>
        <?php if ($data['rows'] === []): ?>
            <p class="text-muted mb-0 p-3"><?= ah_e($kosong) ?></p>
        <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden"><?= ah_e($judul) ?>, satu baris per kunjungan</caption>
            <thead><tr><th scope="col">Kunjungan</th><th scope="col">Santri</th><th scope="col">Jumlah</th><th scope="col"><?= ah_e($kolomTanggal) ?></th></tr></thead><tbody>
            <?php foreach ($data['rows'] as $row): ?>
                <tr>
                    <td data-label="Kunjungan"><a class="ah-row-link" href="<?= ah_e(kesehatan_url('kesehatan_detail.php', ['id' => $row['kunjungan_id']])) ?>"><?= ah_e(ah_waktu($row['waktu_kunjungan'])) ?></a>
                        <?= $row['status'] === 'Draf' ? ' ' . ah_status('Draf') : '' ?></td>
                    <td data-label="Santri"><span class="ah-cell-wrap"><?= ah_e($row['santri']['nama']) ?><span class="ah-cell-sub">NIS <?= ah_e($row['santri']['nis']) ?><?= $row['kelas'] !== null ? ' · ' . ah_e($row['kelas']) : '' ?></span></span></td>
                    <td data-label="Jumlah"><?= (int) $row['jumlah'] ?></td>
                    <td data-label="<?= ah_e($kolomTanggal) ?>"><?= $row['tanggal'] === null ? '<span class="text-muted">—</span>' : ah_e(ah_tanggal($row['tanggal'], false)) ?>
                        <?= $row['lewat'] > 0 ? ' ' . ah_badge('lewat', 'warn') : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php if ($data['total'] > count($data['rows'])): ?>
            <p class="text-muted small mb-0 p-3">Menampilkan <?= count($data['rows']) ?> terlama dari <?= (int) $data['total'] ?>. Gunakan saringan daftar kunjungan untuk selengkapnya.</p>
        <?php endif; endif; ?>
    </section>
    <?php
};
$tabelTertunda('ah-tertunda-rujukan', 'Rujukan menunggu hasil', $lap['tertunda']['rujukan'], 'Dirujuk sejak', 'Tidak ada rujukan yang menunggu hasil.');
$tabelTertunda('ah-tertunda-tl', 'Tindak lanjut belum selesai', $lap['tertunda']['tindak_lanjut'], 'Target terdekat', 'Tidak ada tindak lanjut yang terbuka.');
?>
    <div class="row g-3 mt-0">
        <section class="col-lg-6" aria-labelledby="ah-per-kelas"><div class="ah-card h-100">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-per-kelas">Per kelas saat ini</h2></div>
            <?php if ($lap['per_kelas'] === []): ?><p class="text-muted mb-0 p-3">Tidak ada kunjungan pada periode ini.</p><?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table"><thead><tr><th scope="col">Kelas</th><th scope="col" class="text-end">Kunjungan</th><th scope="col" class="text-end">Santri</th></tr></thead><tbody>
                <?php foreach ($lap['per_kelas'] as $row): ?>
                    <tr><td><?= $row['kelas'] === null ? '<span class="text-muted">Belum ditempatkan</span>' : ah_e($row['kelas']['nama']) ?></td>
                        <td class="text-end"><?= $angka($row['kunjungan']) ?></td><td class="text-end"><?= $angka($row['santri']) ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </div></section>
        <section class="col-lg-6" aria-labelledby="ah-per-bulan"><div class="ah-card h-100">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-per-bulan">Per bulan</h2></div>
            <?php if ($lap['per_bulan'] === []): ?><p class="text-muted mb-0 p-3">Tidak ada kunjungan pada periode ini.</p><?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table"><thead><tr><th scope="col">Bulan</th><th scope="col" class="text-end">Kunjungan</th><th scope="col" class="text-end">Santri</th></tr></thead><tbody>
                <?php foreach ($lap['per_bulan'] as $row): ?>
                    <tr><td><?= ah_e($namaBulan($row['bulan'])) ?></td>
                        <td class="text-end"><?= $angka($row['kunjungan']) ?></td><td class="text-end"><?= $angka($row['santri']) ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </div></section>
    </div>
<?php
$awas = $lap['pengawasan'];
if ($awas !== null):
    $kor = $awas['koreksi'];
    ?>
    <h2 class="h5 mt-4" id="ah-pengawasan">Pengawasan admin</h2>
    <p class="text-muted small">Berdasarkan waktu peristiwa dalam periode, seluruh santri. Teks alasan dibaca di riwayat versi masing-masing kunjungan.</p>
    <?= ah_stats([
        ['label' => 'Koreksi catatan', 'value' => $angka($kor['total']), 'hint' => $angka($kor['beralasan']) . ' beralasan'],
        ['label' => 'Koreksi wajib beralasan tanpa alasan', 'value' => $angka($kor['tanpa_alasan_wajib']), 'warn' => $kor['tanpa_alasan_wajib'] > 0,
            'hint' => 'harus selalu 0'],
    ], 'Ringkasan koreksi') ?>
    <div class="row g-3 mt-0">
        <section class="col-lg-6" aria-labelledby="ah-per-petugas"><div class="ah-card h-100">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-per-petugas">Aktivitas petugas</h2></div>
            <?php if ($awas['per_petugas'] === []): ?><p class="text-muted mb-0 p-3">Belum ada aktivitas.</p><?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table"><thead><tr><th scope="col">Petugas</th><th scope="col" class="text-end">Kunjungan</th><th scope="col" class="text-end">Koreksi</th></tr></thead><tbody>
                <?php foreach ($awas['per_petugas'] as $row): ?>
                    <tr><td><?= ah_e($row['nama']) ?></td><td class="text-end"><?= $angka($row['kunjungan']) ?></td><td class="text-end"><?= $angka($row['koreksi']) ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </div></section>
        <section class="col-lg-6" aria-labelledby="ah-publikasi"><div class="ah-card h-100">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-publikasi">Publikasi ringkasan wali</h2></div>
            <?php if ($awas['peristiwa_ringkasan'] === []): ?><p class="text-muted mb-0 p-3">Belum ada peristiwa publikasi.</p><?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table"><thead><tr><th scope="col">Peristiwa</th><th scope="col" class="text-end">Jumlah</th><th scope="col" class="text-end">Beralasan</th><th scope="col" class="text-end">Penerima</th></tr></thead><tbody>
                <?php foreach ($awas['peristiwa_ringkasan'] as $row): ?>
                    <tr><td><?= ah_e($row['jenis']) ?></td><td class="text-end"><?= $angka($row['jumlah']) ?></td><td class="text-end"><?= $angka($row['beralasan']) ?></td><td class="text-end"><?= $angka($row['penerima']) ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </div></section>
        <section class="col-lg-6" aria-labelledby="ah-akses"><div class="ah-card h-100">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-akses">Pembukaan rincian dan ringkasan</h2></div>
            <?php if ($awas['akses'] === []): ?><p class="text-muted mb-0 p-3">Belum ada pembukaan tercatat.</p><?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table"><thead><tr><th scope="col">Sumber hak</th><th scope="col">Aksi</th><th scope="col">Kanal</th><th scope="col" class="text-end">Kali</th><th scope="col" class="text-end">Akun</th></tr></thead><tbody>
                <?php foreach ($awas['akses'] as $row): ?>
                    <tr><td><?= ah_e(kesehatan_sumber_label($row['sumber'])) ?></td><td><?= ah_e($row['aksi']) ?></td><td><?= ah_e(strtoupper($row['kanal'])) ?></td>
                        <td class="text-end"><?= $angka($row['jumlah']) ?></td><td class="text-end"><?= $angka($row['pengguna']) ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </div></section>
        <section class="col-lg-6" aria-labelledby="ah-notifikasi"><div class="ah-card h-100">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-notifikasi">Antrean notifikasi kesehatan</h2></div>
            <?php if ($awas['notifikasi'] === []): ?><p class="text-muted mb-0 p-3">Tidak ada notifikasi kesehatan pada periode ini.</p><?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table"><thead><tr><th scope="col">Kanal</th><th scope="col">Status</th><th scope="col" class="text-end">Jumlah</th></tr></thead><tbody>
                <?php foreach ($awas['notifikasi'] as $row): ?>
                    <tr><td><?= ah_e($row['kanal']) ?></td><td><?= ah_e($row['status']) ?></td><td class="text-end"><?= $angka($row['jumlah']) ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </div></section>
    </div>
    <section class="ah-card mt-3" aria-labelledby="ah-koreksi-terbaru">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-koreksi-terbaru">Koreksi terbaru</h2><span class="text-muted small">paling banyak 20</span></div>
        <?php if ($awas['koreksi_terbaru'] === []): ?><p class="text-muted mb-0 p-3">Tidak ada koreksi pada periode ini.</p><?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Koreksi terbaru</caption>
            <thead><tr><th scope="col">Waktu</th><th scope="col">Santri</th><th scope="col">Versi</th><th scope="col">Oleh</th><th scope="col">Alasan</th></tr></thead><tbody>
            <?php foreach ($awas['koreksi_terbaru'] as $row): ?>
                <tr>
                    <td data-label="Waktu"><a class="ah-row-link" href="<?= ah_e(kesehatan_url('kesehatan_riwayat.php', ['id' => $row['kunjungan_id']])) ?>"><?= ah_e(ah_waktu($row['waktu'])) ?></a></td>
                    <td data-label="Santri"><span class="ah-cell-wrap"><?= ah_e($row['santri']['nama']) ?><span class="ah-cell-sub">NIS <?= ah_e($row['santri']['nis']) ?></span></span></td>
                    <td data-label="Versi"><?= (int) $row['versi_sebelum'] ?> → <?= (int) $row['versi'] ?></td>
                    <td data-label="Oleh"><?= ah_e($row['oleh']) ?></td>
                    <td data-label="Alasan"><?= $row['beralasan'] ? ah_badge('Ada', 'ok') : ah_badge('Tidak ada (draf)', 'muted') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
    </section>
<?php endif;
ah_page_close();
