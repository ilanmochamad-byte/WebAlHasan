<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Bsa\BsaService;
use App\Ui\Denial;

/**
 * Penyesuaian tarif per santri (PRD V6 Fase 2): potongan atau tambahan
 * beralasan untuk rentang periode. Berlaku pada tagihan yang terbit dari
 * tarif sesudah penyesuaian disimpan; tagihan yang sudah terbit tidak
 * berubah (koreksinya lewat revisi tagihan). Pencabutan beralasan, tanpa
 * menghapus riwayat.
 *
 * Petugas BSA dalam cakupan mengelola; admin hanya membaca; orang tua tidak
 * membuka halaman ini (alasan penyesuaian bersifat internal).
 */

require_once __DIR__ . '/_bsa.php';

bsa_wajib_fase2($tagihanBsa);
$judul = 'Penyesuaian tarif';
$santriId = max(0, (int) ($_GET['santri_id'] ?? 0));
$alamat = bsa_url('bsa_penyesuaian.php', ['santri_id' => $santriId]);
$galat = null;
$isian = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['aksi'] ?? '') === 'cabut') {
            $h = $tagihanBsa->cabutPenyesuaian($currentUser, max(0, (int) ($_POST['penyesuaian_id'] ?? 0)), $_POST, BsaService::KANAL_WEB);
            ah_flash_set('success', $h['replayed'] ? 'Penyesuaian ini sudah dicabut sebelumnya.' : 'Penyesuaian dicabut. Tagihan yang sudah terbit tidak berubah.');
        } else {
            $h = $tagihanBsa->buatPenyesuaian($currentUser, $santriId, $_POST, BsaService::KANAL_WEB);
            ah_flash_set('success', $h['replayed'] ? 'Penyesuaian ini sudah tersimpan sebelumnya.' : 'Penyesuaian tersimpan. Berlaku pada tagihan berikutnya yang terbit dari tarif.');
        }
        ah_redirect($alamat);
    } catch (BsaException $exception) {
        if (in_array($exception->status, [403, 404], true)) {
            Denial::page($judul, 'pengurus dengan penugasan BSA aktif untuk santri dalam cakupannya', $exception->getMessage(), $exception->status);
        }
        if (($_POST['aksi'] ?? '') === 'cabut') {
            ah_flash_set('danger', 'Penyesuaian belum dicabut. ' . $exception->getMessage());
            ah_redirect($alamat);
        }
        http_response_code($exception->status);
        $galat = $exception->getMessage();
        $isian = $_POST;
    }
}
try {
    $d = $tagihanBsa->penyesuaianSantri($currentUser, $santriId);
} catch (BsaException $exception) {
    if ($exception->status === 404) {
        Denial::render('Santri tidak ditemukan', 'Data keuangan santri yang Anda buka tidak ada atau bukan hak Anda.', 404,
            '<a class="btn btn-outline-primary" href="' . ah_e(bsa_url('bsa.php')) . '">Kembali ke daftar</a>', false);
    }
    Denial::page($judul, 'petugas BSA berpenugasan aktif dan admin', $exception->getMessage(), $exception->status);
}
$s = $d['santri'];
$kelola = $d['akses']['kelola'] && $s['aktif'];
$nilai = static fn (string $kolom, string $bawaan = ''): string => is_string($isian[$kolom] ?? null) ? (string) $isian[$kolom] : $bawaan;
$kembali = bsa_url('bsa_santri.php', ['id' => $s['id']]);

ah_page_open([
    'title' => $judul . ' · ' . $s['nama'],
    'heading' => $judul,
    'user' => $currentUser,
    'active' => 'bsa',
    'description' => $s['nama'] . ' · NIS ' . $s['nis'] . ($s['unit'] ? ' · unit ' . $s['unit'] : ''),
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => bsa_judul($aksesBsa), 'url' => bsa_url('bsa.php')],
        ['label' => $s['nama'], 'url' => $kembali], ['label' => 'Penyesuaian']],
]);
if ($galat !== null) {
    ah_note('danger', 'Penyesuaian belum tersimpan. ' . $galat);
}
?>
<section class="ah-card" aria-labelledby="ah-daftar-penyesuaian">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-daftar-penyesuaian">Penyesuaian</h2><span class="text-muted small"><?= count($d['rows']) ?> penyesuaian</span></div>
    <?php if ($d['rows'] === []): ?>
        <div class="ah-card__body"><p class="text-muted mb-0">Belum ada penyesuaian. Tagihan santri ini mengikuti tarif unitnya.</p></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Penyesuaian tarif santri</caption>
            <thead><tr><th scope="col">Penyesuaian</th><th scope="col" class="text-end">Nominal</th><th scope="col">Berlaku</th><th scope="col">Status</th><?= $kelola ? '<th scope="col"><span class="ah-visually-hidden">Tindakan</span></th>' : '' ?></tr></thead><tbody>
            <?php foreach ($d['rows'] as $j): ?><tr>
                <td data-label="Penyesuaian"><span class="ah-cell-wrap"><?= ah_e($j['jenis'] . ' · ' . $j['nama']) ?><span class="ah-cell-sub ah-break">Alasan: <?= ah_e($j['alasan']) ?> · <?= ah_e($j['pembuat']['nama']) ?></span>
                    <?php if ($j['dicabut'] !== null): ?><span class="ah-cell-sub ah-break">Dicabut <?= ah_e(ah_waktu($j['dicabut']['pada'])) ?> oleh <?= ah_e($j['dicabut']['oleh']) ?>: <?= ah_e($j['dicabut']['alasan']) ?></span><?php endif; ?></span></td>
                <td data-label="Nominal" class="text-end"><?= ah_e(($j['jenis'] === 'Potongan' ? '−' : '+') . bsa_rp($j['nominal'])) ?></td>
                <td data-label="Berlaku"><?= ah_e(bsa_periode($j['berlaku_mulai']) . ' – ' . ($j['berlaku_sampai'] === null ? 'seterusnya' : bsa_periode($j['berlaku_sampai']))) ?></td>
                <td data-label="Status"><?= ah_status($j['status']) ?></td>
                <?php if ($kelola): ?><td data-label="Tindakan"><?php if ($j['status'] === 'Aktif'):
                    ah_action_dialog_open('cabut-' . (int) $j['id'], 'Cabut', 'Cabut penyesuaian ' . $j['nama'], 'sm btn-outline-danger'); ?>
                    <form method="post" action="<?= ah_e($alamat) ?>">
                        <?= ah_csrf() ?><input type="hidden" name="aksi" value="cabut"><input type="hidden" name="penyesuaian_id" value="<?= (int) $j['id'] ?>">
                        <input type="hidden" name="idempotency_key" value="<?= ah_e(bin2hex(random_bytes(16))) ?>">
                        <p class="small">Tagihan yang sudah terbit tidak berubah; pencabutan hanya berlaku untuk tagihan berikutnya.</p>
                        <label class="form-label" for="cabut-alasan-<?= (int) $j['id'] ?>">Alasan <span class="text-danger" aria-hidden="true">*</span></label>
                        <input class="form-control mb-2" id="cabut-alasan-<?= (int) $j['id'] ?>" name="alasan" required minlength="5" maxlength="500">
                        <button class="btn btn-danger" type="submit" data-busy-label="Menyimpan…">Cabut penyesuaian</button>
                    </form>
                <?php ah_action_dialog_close(); endif; ?></td><?php endif; ?>
            </tr><?php endforeach; ?>
            </tbody></table></div>
    <?php endif; ?>
</section>
<?php if ($kelola): ?>
<section class="ah-card" aria-labelledby="ah-form-penyesuaian">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-form-penyesuaian">Tambah penyesuaian</h2></div>
    <div class="ah-card__body">
        <form method="post" action="<?= ah_e($alamat) ?>" id="form-penyesuaian">
            <?= ah_csrf() ?>
            <input type="hidden" name="idempotency_key" value="<?= ah_e(bsa_kunci_formulir($isian)) ?>">
            <fieldset class="mb-3"><legend class="form-label fs-6">Jenis <span class="text-danger" aria-hidden="true">*</span></legend>
                <?php foreach (['Potongan', 'Tambahan'] as $v): ?>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="jenis" id="jenis-<?= ah_e(strtolower($v)) ?>" value="<?= ah_e($v) ?>" required<?= $nilai('jenis', 'Potongan') === $v ? ' checked' : '' ?>>
                        <label class="form-check-label" for="jenis-<?= ah_e(strtolower($v)) ?>"><?= ah_e($v) ?></label></div>
                <?php endforeach; ?>
            </fieldset>
            <div class="row g-2 mb-3">
                <div class="col-sm-7"><label class="form-label" for="nama">Keterangan <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control" id="nama" name="nama" required minlength="3" maxlength="100" value="<?= ah_e($nilai('nama')) ?>" placeholder="Mis. Potongan syahriah">
                    <p class="form-text mb-0">Tampil di rincian tagihan, termasuk bagi orang tua.</p></div>
                <div class="col-sm-5"><label class="form-label" for="nominal">Nominal (Rp) <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control" type="text" inputmode="numeric" id="nominal" name="nominal" required maxlength="13" pattern="[0-9]{1,3}(\.[0-9]{3})*|[0-9]+" autocomplete="off" value="<?= ah_e($nilai('nominal')) ?>"></div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-sm-6"><label class="form-label" for="berlaku_mulai">Berlaku mulai <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control" type="month" id="berlaku_mulai" name="berlaku_mulai" required pattern="[0-9]{4}-[0-9]{2}" value="<?= ah_e($nilai('berlaku_mulai', date('Y-m'))) ?>"></div>
                <div class="col-sm-6"><label class="form-label" for="berlaku_sampai">Berlaku sampai <span class="text-muted fw-normal">(kosong = seterusnya)</span></label>
                    <input class="form-control" type="month" id="berlaku_sampai" name="berlaku_sampai" pattern="[0-9]{4}-[0-9]{2}" value="<?= ah_e($nilai('berlaku_sampai')) ?>"></div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="alasan">Alasan <span class="text-danger" aria-hidden="true">*</span></label>
                <input class="form-control" id="alasan" name="alasan" required minlength="5" maxlength="500" value="<?= ah_e($nilai('alasan')) ?>">
                <p class="form-text mb-0">Tersimpan untuk petugas dan admin; tidak tampil kepada orang tua.</p>
            </div>
            <div class="ah-actbar mt-4 mb-0">
                <a class="btn btn-outline-secondary" href="<?= ah_e($kembali) ?>">Kembali</a>
                <button class="btn btn-primary" type="submit" data-busy-label="Menyimpan…">Simpan penyesuaian</button>
            </div>
        </form>
    </div>
</section>
<?php endif;
ah_page_close();
