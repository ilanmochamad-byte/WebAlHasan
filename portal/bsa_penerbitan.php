<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Bsa\BsaService;
use App\Ui\Denial;

/**
 * Penerbitan tagihan bulanan massal (PRD V6 Fase 2).
 *
 * Langkah: pilih periode, jatuh tempo, dan unit → pratinjau (GET, tidak
 * menulis apa pun) → Terbitkan (POST dengan hash pratinjau dan kunci
 * idempotensi). Server menghitung ulang rencana di dalam transaksi; bila
 * santri/tarif/penyesuaian/tagihan berubah sejak pratinjau, penerbitan
 * ditolak tanpa tagihan baru. Santri yang sudah punya tagihan periode itu
 * tidak mendapat tagihan kedua dan snapshot tagihannya tidak berubah.
 *
 * `?id=` menampilkan hasil satu penerbitan. Admin hanya membaca riwayat
 * penerbitan; orang tua ditolak (403).
 */

require_once __DIR__ . '/_bsa.php';

bsa_wajib_fase2($tagihanBsa);
if ($aksesBsa['sumber'] === 'wali') {
    Denial::page('Penerbitan tagihan', 'petugas BSA berpenugasan aktif dan admin', 'Orang tua hanya melihat keuangan anaknya.');
}
$judul = 'Penerbitan tagihan';
$kembali = bsa_url('bsa_penerbitan.php');
$crumbs = [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => bsa_judul($aksesBsa), 'url' => bsa_url('bsa.php')], ['label' => 'Penerbitan', 'url' => $kembali]];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $hasil = $tagihanBsa->terbitkanMassal($currentUser, $_POST, BsaService::KANAL_WEB);
        ah_flash_set('success', $hasil['replayed'] ? 'Penerbitan ini sudah dijalankan sebelumnya. Tidak ada tagihan ganda.' : $hasil['jumlah_terbit'] . ' tagihan terbit.');
        ah_redirect(bsa_url('bsa_penerbitan.php', ['id' => $hasil['id']]));
    } catch (BsaException $exception) {
        if (in_array($exception->status, [403], true)) {
            Denial::page($judul, 'pengurus dengan penugasan BSA aktif', $exception->getMessage(), $exception->status);
        }
        ah_flash_set('danger', 'Tagihan belum terbit. ' . $exception->getMessage());
        ah_redirect(bsa_url('bsa_penerbitan.php', array_filter([
            'periode' => is_string($_POST['periode'] ?? null) ? $_POST['periode'] : null,
            'jatuh_tempo' => is_string($_POST['jatuh_tempo'] ?? null) ? $_POST['jatuh_tempo'] : null,
            'unit' => is_string($_POST['unit'] ?? null) ? $_POST['unit'] : null,
            'pratinjau' => '1',
        ], static fn ($v): bool => $v !== null && $v !== '')));
    }
}

// --------------------------------------------------------------------- rincian
if (isset($_GET['id'])) {
    try {
        $b = $tagihanBsa->penerbitan($currentUser, max(0, (int) $_GET['id']));
    } catch (BsaException $exception) {
        if ($exception->status === 404) {
            Denial::render('Penerbitan tidak ditemukan', 'Penerbitan yang Anda buka tidak ada atau di luar cakupan Anda.', 404,
                '<a class="btn btn-outline-primary" href="' . ah_e($kembali) . '">Kembali</a>', false);
        }
        Denial::page($judul, 'petugas BSA berpenugasan aktif dan admin', $exception->getMessage(), $exception->status);
    }
    ah_page_open([
        'title' => 'Penerbitan ' . bsa_periode($b['periode']),
        'user' => $currentUser,
        'active' => 'bsa',
        'description' => ($b['unit'] === null ? 'Seluruh unit dalam cakupan' : 'Unit ' . $b['unit']) . ' · jatuh tempo ' . ah_tanggal($b['jatuh_tempo'], false) . ' · oleh ' . $b['pembuat']['nama'] . ', ' . ah_waktu($b['waktu']),
        'breadcrumbs' => [...$crumbs, ['label' => bsa_periode($b['periode'])]],
    ]);
    echo bsa_nav($aksesBsa, 'bsa_penerbitan.php', $tagihanBsa);
    echo ah_stats([
        ['label' => 'Terbit', 'value' => (string) $b['jumlah']['terbit']],
        ['label' => 'Sudah ada', 'value' => (string) $b['jumlah']['sudah_ada'], 'hint' => 'Tidak diterbitkan ulang'],
        ['label' => 'Dilewati', 'value' => (string) $b['jumlah']['dilewati'], 'warn' => $b['jumlah']['dilewati'] > 0],
    ], 'Hasil penerbitan');
    ?>
    <section class="ah-card" aria-labelledby="ah-hasil">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-hasil">Hasil per santri</h2><span class="text-muted small"><?= count($b['rows']) ?> santri</span></div>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Hasil penerbitan per santri</caption>
            <thead><tr><th scope="col">Santri</th><th scope="col">Unit</th><th scope="col">Hasil</th><th scope="col" class="text-end">Total</th></tr></thead><tbody>
            <?php foreach ($b['rows'] as $r): ?><tr>
                <td data-label="Santri"><span class="ah-cell-wrap"><?= ah_e($r['santri']['nama']) ?><span class="ah-cell-sub">NIS <?= ah_e($r['santri']['nis']) ?></span></span></td>
                <td data-label="Unit"><?= $r['unit'] === null ? '<span class="text-muted">—</span>' : ah_e($r['unit']) ?></td>
                <td data-label="Hasil"><?= ah_status($r['hasil'] === 'SudahAda' ? 'Sudah ada' : $r['hasil']) ?><?= $r['keterangan'] !== null ? '<span class="ah-cell-sub">' . ah_e($r['keterangan']) . '</span>' : '' ?></td>
                <td data-label="Total" class="text-end"><?= $r['tagihan_id'] !== null ? '<a href="' . ah_e(bsa_url('bsa_tagihan.php', ['id' => $r['tagihan_id']])) . '">' . ah_e(bsa_rp((int) $r['total'])) . '</a>' : '—' ?></td>
            </tr><?php endforeach; ?>
            </tbody></table></div>
    </section>
    <p class="mt-3"><a class="btn btn-outline-secondary" href="<?= ah_e($kembali) ?>">Kembali ke penerbitan</a></p>
    <?php
    ah_page_close();
    exit;
}

// ------------------------------------------------------------ pratinjau + riwayat
$periode = bsa_nilai_periode($_GET['periode'] ?? null, date('Y-m'));
$jatuhTempo = is_string($_GET['jatuh_tempo'] ?? null) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $_GET['jatuh_tempo']) === 1 ? $_GET['jatuh_tempo'] : $periode . '-10';
$unit = is_string($_GET['unit'] ?? null) ? trim($_GET['unit']) : '';
$pratinjau = null;
$galat = null;
if ($aksesBsa['kelola'] && ($_GET['pratinjau'] ?? '') === '1') {
    try {
        $pratinjau = $tagihanBsa->pratinjau($currentUser, ['periode' => $periode, 'jatuh_tempo' => $jatuhTempo, 'unit' => $unit]);
    } catch (BsaException $exception) {
        if ($exception->status === 403) {
            Denial::page($judul, 'pengurus dengan penugasan BSA aktif', $exception->getMessage(), $exception->status);
        }
        $galat = $exception->getMessage();
    }
}
try {
    $riwayat = $tagihanBsa->daftarPenerbitan($currentUser, $_GET['page'] ?? 1);
    $unitPilihan = $tagihanBsa->unitAkses($aksesBsa);
} catch (BsaException $exception) {
    Denial::page($judul, 'petugas BSA berpenugasan aktif dan admin', $exception->getMessage(), $exception->status);
}

ah_page_open([
    'title' => $judul,
    'user' => $currentUser,
    'active' => 'bsa',
    'description' => $aksesBsa['kelola'] ? 'Terbitkan tagihan bulanan dari tarif unit untuk santri aktif pada ' . bsa_cakupan_teks($aksesBsa) . '. Tinjau pratinjau sebelum menerbitkan.'
        : 'Pengawasan admin: hanya baca riwayat penerbitan.',
    'breadcrumbs' => [...array_slice($crumbs, 0, 2), ['label' => 'Penerbitan']],
]);
echo bsa_nav($aksesBsa, 'bsa_penerbitan.php', $tagihanBsa);
if ($aksesBsa['kelola']): ?>
<section class="ah-card" aria-labelledby="ah-form-pratinjau">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-form-pratinjau">1. Pilih periode</h2></div>
    <div class="ah-card__body">
        <form method="get" action="<?= ah_e(bsa_url('bsa_penerbitan.php')) ?>" id="form-pratinjau">
            <input type="hidden" name="pratinjau" value="1">
            <div class="row g-2">
                <div class="col-sm-4"><label class="form-label" for="periode">Periode (bulan)</label>
                    <input class="form-control" type="month" id="periode" name="periode" required pattern="[0-9]{4}-[0-9]{2}" value="<?= ah_e($periode) ?>"></div>
                <div class="col-sm-4"><label class="form-label" for="jatuh_tempo">Jatuh tempo</label>
                    <input class="form-control" type="date" id="jatuh_tempo" name="jatuh_tempo" required value="<?= ah_e($jatuhTempo) ?>">
                    <p class="form-text mb-0">Antara awal periode dan tiga bulan sesudahnya.</p></div>
                <div class="col-sm-4"><label class="form-label" for="unit">Unit</label>
                    <select class="form-select" id="unit" name="unit">
                        <option value="">Seluruh unit dalam cakupan</option>
                        <?php foreach ($unitPilihan as $u): ?><option value="<?= ah_e($u) ?>"<?= mb_strtolower($unit) === mb_strtolower($u) ? ' selected' : '' ?>><?= ah_e($u) ?></option><?php endforeach; ?>
                    </select></div>
            </div>
            <div class="ah-actbar mt-3 mb-0"><button class="btn btn-outline-primary" type="submit">Lihat pratinjau</button></div>
        </form>
    </div>
</section>
<?php
    if ($galat !== null) {
        ah_note('danger', $galat);
    }
    if ($pratinjau !== null):
        $kelompok = ['Terbit' => [], 'SudahAda' => [], 'Dilewati' => []];
        foreach ($pratinjau['rows'] as $r) {
            $kelompok[$r['hasil']][] = $r;
        }
        $lewati = [];
        foreach ($kelompok['Dilewati'] as $r) {
            $lewati[(string) $r['keterangan']][] = $r;
        }
        echo ah_stats([
            ['label' => 'Akan terbit', 'value' => (string) $pratinjau['jumlah']['Terbit'], 'hint' => 'Total ' . bsa_rp($pratinjau['total_terbit'])],
            ['label' => 'Sudah ada', 'value' => (string) $pratinjau['jumlah']['SudahAda'], 'hint' => 'Tidak diterbitkan ulang'],
            ['label' => 'Dilewati', 'value' => (string) $pratinjau['jumlah']['Dilewati'], 'warn' => $pratinjau['jumlah']['Dilewati'] > 0],
        ], 'Ringkasan pratinjau'); ?>
<section class="ah-card" aria-labelledby="ah-pratinjau">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-pratinjau">2. Pratinjau <?= ah_e(bsa_periode($pratinjau['periode'])) ?></h2>
        <span class="text-muted small">Jatuh tempo <?= ah_e(ah_tanggal($pratinjau['jatuh_tempo'], false)) ?></span></div>
    <?php if ($kelompok['Terbit'] === []): ?>
        <div class="ah-card__body"><p class="text-muted mb-0">Tidak ada tagihan baru untuk diterbitkan.</p></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Tagihan yang akan terbit</caption>
            <thead><tr><th scope="col">Santri</th><th scope="col">Unit</th><th scope="col">Penyesuaian</th><th scope="col" class="text-end">Total</th></tr></thead><tbody>
            <?php foreach ($kelompok['Terbit'] as $r): ?><tr>
                <td data-label="Santri"><span class="ah-cell-wrap"><?= ah_e($r['nama']) ?><span class="ah-cell-sub">NIS <?= ah_e($r['nis']) ?><?= $r['kelas'] ? ' · ' . ah_e($r['kelas']) : '' ?></span></span></td>
                <td data-label="Unit"><?= ah_e((string) $r['unit']) ?></td>
                <td data-label="Penyesuaian"><?php if ($r['penyesuaian'] === []): ?><span class="text-muted">—</span><?php else: foreach ($r['penyesuaian'] as $j): ?>
                    <span class="d-block"><?= ah_e($j['nama']) ?> <?= ah_e(($j['jenis'] === 'Potongan' ? '−' : '+') . bsa_rp($j['nominal'])) ?></span><?php endforeach; endif; ?></td>
                <td data-label="Total" class="text-end"><?= ah_e(bsa_rp((int) $r['total'])) ?></td>
            </tr><?php endforeach; ?>
            </tbody></table></div>
    <?php endif; ?>
    <?php if ($kelompok['SudahAda'] !== [] || $lewati !== []): ?>
        <div class="ah-card__body">
            <?php if ($kelompok['SudahAda'] !== []): ?>
                <details class="mb-2"><summary><?= count($kelompok['SudahAda']) ?> santri sudah punya tagihan periode ini</summary>
                    <ul class="mb-0 mt-2"><?php foreach ($kelompok['SudahAda'] as $r): ?><li><?= ah_e($r['nama']) ?> (NIS <?= ah_e($r['nis']) ?>) — <a href="<?= ah_e(bsa_url('bsa_tagihan.php', ['id' => $r['tagihan_id']])) ?>"><?= ah_e((string) $r['keterangan']) ?></a></li><?php endforeach; ?></ul></details>
            <?php endif; ?>
            <?php foreach ($lewati as $alasan => $daftar): ?>
                <details class="mb-2"><summary><?= count($daftar) ?> santri dilewati: <?= ah_e($alasan) ?></summary>
                    <ul class="mb-0 mt-2"><?php foreach ($daftar as $r): ?><li><?= ah_e($r['nama']) ?> (NIS <?= ah_e($r['nis']) ?>)</li><?php endforeach; ?></ul></details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($kelompok['Terbit'] !== []): ?>
        <div class="ah-card__body">
            <form method="post" action="<?= ah_e(bsa_url('bsa_penerbitan.php')) ?>" id="form-terbit">
                <?= ah_csrf() ?>
                <input type="hidden" name="idempotency_key" value="<?= ah_e(bin2hex(random_bytes(16))) ?>">
                <input type="hidden" name="periode" value="<?= ah_e($pratinjau['periode']) ?>">
                <input type="hidden" name="jatuh_tempo" value="<?= ah_e($pratinjau['jatuh_tempo']) ?>">
                <input type="hidden" name="unit" value="<?= ah_e((string) $pratinjau['unit']) ?>">
                <input type="hidden" name="pratinjau_hash" value="<?= ah_e($pratinjau['hash']) ?>">
                <p class="form-text">Tagihan menyalin komponen tarif dan penyesuaian santri saat ini. Perubahan tarif sesudahnya tidak mengubah tagihan ini. Tagihan tidak memotong tabungan.</p>
                <div class="ah-actbar mt-3 mb-0">
                    <a class="btn btn-outline-secondary" href="<?= ah_e($kembali) ?>">Batal</a>
                    <button class="btn btn-primary" type="submit" data-busy-label="Menerbitkan…">Terbitkan <?= (int) $pratinjau['jumlah']['Terbit'] ?> tagihan</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</section>
<?php endif;
endif; ?>
<section class="ah-card" aria-labelledby="ah-riwayat-penerbitan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-riwayat-penerbitan">Riwayat penerbitan</h2><span class="text-muted small"><?= (int) $riwayat['total'] ?> penerbitan</span></div>
    <?php if ($riwayat['rows'] === []): ?>
        <div class="ah-card__body"><p class="text-muted mb-0">Belum ada penerbitan massal.</p></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Riwayat penerbitan tagihan</caption>
            <thead><tr><th scope="col">Periode</th><th scope="col">Unit</th><th scope="col" class="text-end">Terbit</th><th scope="col">Oleh</th></tr></thead><tbody>
            <?php foreach ($riwayat['rows'] as $b): ?><tr>
                <td data-label="Periode"><a class="ah-row-link" href="<?= ah_e(bsa_url('bsa_penerbitan.php', ['id' => $b['id']])) ?>"><?= ah_e(bsa_periode($b['periode'])) ?></a></td>
                <td data-label="Unit"><?= ah_e($b['unit'] ?? 'Seluruh cakupan') ?></td>
                <td data-label="Terbit" class="text-end"><?= (int) $b['jumlah']['terbit'] ?><span class="ah-cell-sub"><?= (int) $b['jumlah']['sudah_ada'] ?> sudah ada · <?= (int) $b['jumlah']['dilewati'] ?> dilewati</span></td>
                <td data-label="Oleh"><span class="ah-cell-wrap"><?= ah_e($b['pembuat']['nama']) ?><span class="ah-cell-sub"><?= ah_e(ah_waktu($b['waktu'])) ?></span></span></td>
            </tr><?php endforeach; ?>
            </tbody></table></div>
    <?php endif; ?>
</section>
<?php
ah_pagination((int) $riwayat['total'], (int) $riwayat['page'], (int) $riwayat['per_page']);
ah_page_close();
