<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Psb\PsbAdmin;
use App\Psb\PsbPembayaran;

require_once __DIR__ . '/_guard.php';

/**
 * PSB › Pembayaran (redesain website Tahap 3, K-6).
 *
 * Ringkasan, chip status, saringan, daftar berhalaman, dan panel rincian
 * (pengganti modal per baris). "Tandai lunas" memakai dialog; "Batal lunas"
 * mewajibkan alasan yang diperiksa server SEBELUM status berubah (koreksi
 * audit F-2); tanpa JavaScript alasan diisi di formulir panel `?batal=1`.
 * ada di menu ⋯ dengan alasan yang dicatat di audit. Status sesuai enum:
 * Belum Lunas / Lunas. Ekspor Excel dibuat di server.
 */

// Aksi ubah status hanya lewat POST; GET ?act= lama ditolak tanpa mutasi.
if (isset($_GET['act'])) {
    admin_require_post();
}
$bayar = new PsbPembayaran($koneksi);
$self = app_url('/admin/admin_pembayaran_psb.php');
$act = $_POST['act'] ?? '';

if ($act === 'lunas' || $act === 'batal') {
    $id = (int) ($_POST['id'] ?? 0);
    parse_str((string) ($_POST['kembali'] ?? ''), $query);
    $query = array_filter(PsbPembayaran::filter(is_array($query) ? $query : []), static fn (string $v): bool => $v !== '');
    $alasan = trim((string) ($_POST['alasan'] ?? ''));
    // Koreksi audit F-2: batal lunas wajib beralasan, diperiksa sebelum UPDATE
    // sehingga status, waktu lunas, dan audit tidak berubah bila ditolak.
    if ($act === 'batal' && ($alasan === '' || mb_strlen($alasan) > 500)) {
        ah_flash_set('danger', $alasan === '' ? 'Alasan pembatalan wajib diisi. Status lunas tidak berubah.'
            : 'Alasan pembatalan paling banyak 500 karakter. Status lunas tidak berubah.');
        header('Location: admin_pembayaran_psb.php?' . http_build_query($query + ['id' => $id, 'batal' => 1]));
        exit;
    }
    $sebelum = $bayar->find($id);
    if ($act === 'lunas') {
        $stmt = mysqli_prepare($koneksi, "UPDATE psb_pembayaran SET status_pembayaran='Lunas', waktu_lunas=NOW() WHERE id=?");
    } else {
        $stmt = mysqli_prepare($koneksi, "UPDATE psb_pembayaran SET status_pembayaran='Belum Lunas', waktu_lunas=NULL WHERE id=?");
    }
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    if ($sebelum === null) {
        ah_flash_set('info', 'Tagihan tidak ditemukan; tidak ada yang berubah.');
    } elseif ($act === 'lunas') {
        ah_flash_set('success', 'Pembayaran ' . $sebelum['nama_lengkap'] . ' ditandai lunas.');
    } else {
        audit_logger()->log('psb.pembayaran.batal_lunas', 'psb_pembayaran', $id,
            ['status_pembayaran' => $sebelum['status_pembayaran'], 'waktu_lunas' => $sebelum['waktu_lunas']],
            ['status_pembayaran' => 'Belum Lunas', 'alasan' => $alasan], (int) $currentUser['id']);
        ah_flash_set('success', 'Status lunas ' . $sebelum['nama_lengkap'] . ' dibatalkan.');
    }
    header('Location: admin_pembayaran_psb.php?' . http_build_query($query + ['id' => $id]));
    exit;
}

$filter = PsbPembayaran::filter($_GET);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$dipilih = $id > 0 ? $bayar->find($id) : null;
$daftar = $bayar->page($filter, max(1, (int) ($_GET['page'] ?? 1)));
$counts = $bayar->statusCounts($filter);
$ringkas = $bayar->ringkasan();
$saringan = array_filter($filter, static fn (string $v): bool => $v !== '');
$kembali = http_build_query($saringan + ($daftar['page'] > 1 ? ['page' => $daftar['page']] : []));
$listUrl = $self . ($kembali !== '' ? '?' . $kembali : '');
$rupiah = static fn (int $nilai): string => 'Rp ' . number_format($nilai, 0, ',', '.');
$tahun = beranda_service()->tahunAktif();
if ($id > 0 && $dipilih === null) {
    http_response_code(404);
}

ah_page_open([
    'title' => 'Pembayaran PSB',
    'heading' => 'Pembayaran PSB' . ($tahun !== null ? ' ' . $tahun['tahun'] : ''),
    'user' => $currentUser,
    'active' => 'psb.pembayaran',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'PSB'], ['label' => 'Pembayaran']],
    'actions' => '<a class="btn btn-outline-secondary" href="' . ah_e(app_url('/admin/export_pembayaran.php') . ($kembali !== '' ? '?' . $kembali : '')) . '">Ekspor Excel</a>',
]);
if ($id > 0 && $dipilih === null) {
    ah_note('danger', 'Tagihan tidak ditemukan; pilih dari daftar.');
}
echo ah_stats([
    ['label' => 'Belum lunas', 'value' => number_format($ringkas['belum'], 0, ',', '.'), 'url' => $self . '?status=' . rawurlencode('Belum Lunas'), 'warn' => $ringkas['belum'] > 0],
    ['label' => 'Lunas', 'value' => number_format($ringkas['lunas'], 0, ',', '.'), 'url' => $self . '?status=Lunas'],
    ['label' => 'Kas masuk', 'value' => $rupiah($ringkas['kas']), 'url' => app_url('/admin/admin_rekap_keuangan.php')],
], 'Ringkasan pembayaran PSB');
$tanpaStatus = array_diff_key($saringan, ['status' => 1]);
$chips = [];
foreach (['Belum lunas' => 'Belum Lunas', 'Lunas' => 'Lunas', 'Semua' => ''] as $label => $nilai) {
    $query = http_build_query($tanpaStatus + ($nilai === '' ? [] : ['status' => $nilai]));
    $chips[] = ['label' => $label, 'count' => $counts[$nilai === '' ? 'Semua' : $nilai], 'active' => $filter['status'] === $nilai, 'url' => $self . ($query !== '' ? '?' . $query : '')];
}
echo ah_chips($chips, 'Saring menurut status pembayaran');

if ($dipilih !== null):
    $d = $dipilih;
    $pid = (int) $d['id'];
    $wajib = PsbPembayaran::rincianWajib($d['rincian_wajib']);
    $hidden = static fn (string $act): string => Csrf::input()
        . '<input type="hidden" name="act" value="' . ah_e($act) . '"><input type="hidden" name="id" value="' . $pid . '">'
        . '<input type="hidden" name="kembali" value="' . ah_e($kembali) . '">';
    ah_panel_open(['title' => 'Rincian · ' . $d['nama_lengkap'], 'close_url' => $listUrl,
        'description' => $d['no_pendaftaran'] . ' · ' . $d['jenjang_tujuan'] . ' · ' . ($d['jenis_kelamin'] === 'P' ? 'Perempuan' : 'Laki-laki')]);
    ?>
    <dl class="ah-kv">
        <dt>Status</dt><dd><?= ah_status((string) $d['status_pembayaran']) ?><?= $d['waktu_lunas'] ? ' <span class="ah-meta">' . ah_e(date('d-m-Y H.i', strtotime((string) $d['waktu_lunas']))) . '</span>' : '' ?></dd>
        <dt>Kategori biaya</dt><dd><?= ah_e($d['kategori_biaya']) ?></dd>
        <dt>Metode</dt><dd><?= ah_e($d['metode_pembayaran']) ?></dd>
    </dl>
    <h3 class="h6 mt-3">A. Biaya pilihan</h3>
    <ul class="ah-list-plain">
        <li><span>Syahriyyah (bulanan)</span><b><?= $rupiah((int) $d['syahriyyah']) ?></b></li>
        <?php foreach (['infaq' => 'Infaq bangunan', 'seragam_psas' => 'Seragam PSAS', 'seragam_pramuka' => 'Seragam Pramuka'] as $kolom => $label): ?>
            <?php if ((int) $d[$kolom] > 0): ?><li><span><?= ah_e($label) ?></span><b><?= $rupiah((int) $d[$kolom]) ?></b></li><?php endif; ?>
        <?php endforeach; ?>
    </ul>
    <h3 class="h6 mt-3">B. Biaya wajib terbayar</h3>
    <?php if ($wajib === []): ?>
        <p class="ah-meta">Belum ada komponen wajib yang dicentang pendaftar.</p>
    <?php else: ?>
        <ul class="ah-list-plain">
            <?php foreach ($wajib as $nama => $harga): ?><li><span><?= ah_e($nama) ?></span><b><?= $rupiah($harga) ?></b></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <ul class="ah-list-plain mt-2"><li><span><b>Total tagihan</b></span><b><?= $rupiah((int) $d['total_keseluruhan']) ?></b></li></ul>
    <div class="ah-panel__foot ah-decision">
        <a class="btn btn-outline-secondary" href="<?= ah_e(app_url('/cetak_biaya_psb.php?noreg=' . rawurlencode((string) $d['no_pendaftaran']))) ?>" target="_blank" rel="noopener">Cetak kwitansi</a>
        <?php if ($d['status_pembayaran'] === 'Belum Lunas'): ?>
            <form method="post" action="admin_pembayaran_psb.php" data-confirm="Tagihan <?= ah_e($d['nama_lengkap']) ?> sebesar <?= ah_e($rupiah((int) $d['total_keseluruhan'])) ?> ditandai lunas sekarang." data-confirm-title="Tandai lunas?" data-confirm-ok="Tandai lunas"><?= $hidden('lunas') ?><button class="btn btn-primary" type="submit">Tandai lunas</button></form>
        <?php elseif (isset($_GET['batal'])): ?>
            <form method="post" action="admin_pembayaran_psb.php" class="w-100" id="form-batal-lunas">
                <?= Csrf::input() ?>
                <input type="hidden" name="act" value="batal"><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="kembali" value="<?= ah_e($kembali) ?>">
                <label class="form-label" for="alasan-batal">Alasan pembatalan <span aria-hidden="true">*</span></label>
                <textarea class="form-control mb-2" id="alasan-batal" name="alasan" rows="3" maxlength="500" required></textarea>
                <p class="small text-muted">Status kembali Belum Lunas dan waktu lunas dihapus. Alasan dicatat di audit.</p>
                <div class="ah-actions"><a class="btn btn-outline-secondary" href="<?= ah_e($self . '?' . http_build_query(array_filter(['id' => $pid] + $saringan))) ?>">Batal</a><button class="btn btn-danger" type="submit">Batalkan status lunas</button></div>
            </form>
        <?php else: ?>
            <noscript><a class="btn btn-outline-danger" href="<?= ah_e($self . '?' . http_build_query($saringan + ['id' => $pid, 'batal' => 1])) ?>">Batal lunas</a></noscript>
            <?= ah_menu([
                ['label' => 'Batal lunas', 'icon' => 'fa-rotate-left', 'danger' => true, 'action' => 'admin_pembayaran_psb.php',
                    'fields' => ['act' => 'batal', 'id' => $pid, 'kembali' => $kembali],
                    'confirm' => ['title' => 'Batalkan status lunas ' . $d['nama_lengkap'] . '?', 'text' => 'Status kembali Belum Lunas dan waktu lunas dihapus. Alasan dicatat di audit.',
                        'ok' => 'Batal lunas', 'reason' => 'alasan', 'reason_label' => 'Alasan pembatalan']],
            ], 'Tindakan lain untuk ' . $d['nama_lengkap'], false) ?>
        <?php endif; ?>
    </div>
    <?php
    ah_panel_close();
endif;

ah_filter_open(count(array_diff_key($saringan, ['status' => 1])), ['status' => $filter['status']]);
?>
    <div class="ah-filter__wide"><label class="form-label" for="bayar-cari">Cari</label>
        <input class="form-control" type="search" id="bayar-cari" name="cari" maxlength="100" value="<?= ah_e($filter['cari']) ?>" placeholder="Nama atau no. daftar"></div>
    <div><label class="form-label" for="bayar-jenjang">Unit tujuan</label>
        <select class="form-select" id="bayar-jenjang" name="jenjang"><option value="">Semua unit</option>
            <?php foreach (PsbAdmin::UNIT as $unit): ?><option<?= $filter['jenjang'] === $unit ? ' selected' : '' ?>><?= ah_e($unit) ?></option><?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="bayar-jk">L/P</label>
        <select class="form-select" id="bayar-jk" name="jk"><option value="">Semua</option><option value="L"<?= $filter['jk'] === 'L' ? ' selected' : '' ?>>Laki-laki</option><option value="P"<?= $filter['jk'] === 'P' ? ' selected' : '' ?>>Perempuan</option></select></div>
<?php ah_filter_close(['status' => $filter['status']]); ?>
<div class="ah-card ah-table-wrap">
    <table class="ah-table ah-table--cards" id="daftar-pembayaran">
        <caption class="ah-visually-hidden">Tagihan PSB, <?= (int) $daftar['total'] ?> data</caption>
        <thead><tr><th scope="col">Pendaftar</th><th scope="col">Unit</th><th scope="col" class="ah-col-secondary">Metode</th><th scope="col">Total tagihan</th><th scope="col">Status</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
        <tbody>
        <?php foreach ($daftar['rows'] as $row): ?>
            <tr<?= (int) $row['id'] === $id ? ' class="is-selected"' : '' ?>>
                <td><b><?= ah_e($row['nama_lengkap']) ?></b><span class="ah-cell-sub"><?= ah_e($row['no_pendaftaran']) ?></span></td>
                <td><?= ah_e($row['jenjang_tujuan']) ?><span class="ah-cell-sub"><?= ah_e($row['kategori_biaya']) ?></span></td>
                <td class="ah-col-secondary"><?= ah_e($row['metode_pembayaran']) ?></td>
                <td><?= $rupiah((int) $row['total_keseluruhan']) ?></td>
                <td><?= ah_status((string) $row['status_pembayaran']) ?></td>
                <td data-label=""><a class="btn btn-sm btn-outline-primary" href="<?= ah_e($self . '?' . http_build_query($saringan + ($daftar['page'] > 1 ? ['page' => $daftar['page']] : []) + ['id' => (int) $row['id']])) ?>">Rincian <span class="ah-visually-hidden"><?= ah_e($row['nama_lengkap']) ?></span></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($daftar['rows'] === []): ?>
            <tr><td colspan="6" data-label=""><?= ah_empty('Tidak ada tagihan yang cocok', $saringan === [] ? 'Tagihan tampil setelah pendaftar memilih biaya.' : 'Bersihkan saringan atau pilih chip status lain.') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
ah_pagination((int) $daftar['total'], (int) $daftar['page'], PsbPembayaran::PER_PAGE);
ah_page_close();
