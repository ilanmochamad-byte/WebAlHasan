<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Bsa\BsaService;
use App\Ui\Denial;

/**
 * Tarif bulanan per unit/jenjang (PRD V6 Fase 2).
 *
 * Petugas BSA menetapkan tarif untuk unit dalam cakupannya mulai satu
 * periode; admin hanya membaca; orang tua tidak membuka halaman ini (403 dari
 * `TagihanService`). Tarif lama tidak diubah: perubahan = tarif baru mulai
 * periode tertentu, atau mengganti tarif periode mulai yang sama dengan
 * alasan. Tagihan yang sudah terbit menyimpan snapshot komponen sehingga
 * tidak ikut berubah.
 */

require_once __DIR__ . '/_bsa.php';

bsa_wajib_fase2($tagihanBsa);
$galat = null;
$isian = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $hasil = $tagihanBsa->buatTarif($currentUser, $_POST, BsaService::KANAL_WEB);
        ah_flash_set('success', $hasil['replayed'] ? 'Tarif ini sudah tersimpan sebelumnya. Tidak ada tarif ganda.'
            : ($hasil['menggantikan_id'] !== null ? 'Tarif tersimpan dan menggantikan tarif periode mulai yang sama. Tagihan yang sudah terbit tidak berubah.' : 'Tarif tersimpan.'));
        ah_redirect(bsa_url('bsa_tarif.php'));
    } catch (BsaException $exception) {
        if (in_array($exception->status, [403, 404], true)) {
            Denial::page('Tarif bulanan', 'pengurus dengan penugasan BSA aktif untuk unit dalam cakupannya', $exception->getMessage(), $exception->status);
        }
        http_response_code($exception->status);
        $galat = $exception->getMessage();
        $isian = $_POST;
    }
}
try {
    $d = $tagihanBsa->daftarTarif($currentUser);
} catch (BsaException $exception) {
    Denial::page('Tarif bulanan', 'petugas BSA berpenugasan aktif dan admin', $exception->getMessage(), $exception->status);
}
$kelola = $d['akses']['kelola'];
$nilai = static fn (string $kolom, string $bawaan = ''): string => is_string($isian[$kolom] ?? null) ? (string) $isian[$kolom] : $bawaan;
$komponen = is_array($isian['komponen'] ?? null) ? array_values($isian['komponen']) : [];
$perUnit = [];
foreach ($d['tarif'] as $t) {
    $perUnit[$t['unit']][] = $t;
}

ah_page_open([
    'title' => 'Tarif bulanan',
    'user' => $currentUser,
    'active' => 'bsa',
    'description' => $kelola ? 'Tarif tagihan bulanan per unit pada ' . bsa_cakupan_teks($aksesBsa) . '. Perubahan tarif tidak mengubah tagihan yang sudah terbit.'
        : 'Pengawasan admin: hanya baca. Tarif ditetapkan petugas BSA berpenugasan aktif.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => bsa_judul($aksesBsa), 'url' => bsa_url('bsa.php')], ['label' => 'Tarif']],
]);
echo bsa_nav($aksesBsa, 'bsa_tarif.php', $tagihanBsa);
if ($galat !== null) {
    ah_note('danger', 'Tarif belum tersimpan. ' . $galat);
}
if ($d['unit'] === []) {
    echo ah_empty('Belum ada unit', 'Unit/jenjang diambil dari kelas aktif. Tambahkan kelas lebih dahulu di Data pesantren.');
}
foreach ($d['unit'] as $unit):
    $rows = $perUnit[$unit] ?? [];
    $berlaku = array_values(array_filter($rows, static fn (array $t): bool => $t['status'] === 'Berlaku')); ?>
    <section class="ah-card" aria-labelledby="ah-tarif-<?= ah_e(md5($unit)) ?>">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-tarif-<?= ah_e(md5($unit)) ?>">Unit <?= ah_e($unit) ?></h2>
            <span class="text-muted small"><?= $berlaku === [] ? 'Belum ada tarif' : 'Terbaru mulai ' . ah_e(bsa_periode($berlaku[0]['berlaku_mulai'])) ?></span></div>
        <?php if ($rows === []): ?>
            <div class="ah-card__body"><p class="text-muted mb-0">Belum ada tarif untuk unit ini. Santri unit ini dilewati saat penerbitan sampai tarif ditetapkan.</p></div>
        <?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Riwayat tarif unit <?= ah_e($unit) ?></caption>
                <thead><tr><th scope="col">Mulai berlaku</th><th scope="col">Komponen</th><th scope="col" class="text-end">Total</th><th scope="col">Status</th><th scope="col">Ditetapkan</th></tr></thead><tbody>
                <?php foreach ($rows as $t): ?><tr>
                    <td data-label="Mulai berlaku"><?= ah_e(bsa_periode($t['berlaku_mulai'])) ?></td>
                    <td data-label="Komponen"><span class="ah-cell-wrap"><?php foreach ($t['komponen'] as $k): ?><span class="d-block"><?= ah_e($k['nama']) ?> · <?= ah_e(bsa_rp($k['nominal'])) ?></span><?php endforeach; ?>
                        <?php if ($t['alasan'] !== null): ?><span class="ah-cell-sub ah-break">Alasan: <?= ah_e($t['alasan']) ?></span><?php endif; ?></span></td>
                    <td data-label="Total" class="text-end"><?= ah_e(bsa_rp($t['total'])) ?></td>
                    <td data-label="Status"><?= ah_status($t['status']) ?></td>
                    <td data-label="Ditetapkan"><span class="ah-cell-wrap"><?= ah_e($t['pembuat']['nama']) ?><span class="ah-cell-sub"><?= ah_e(ah_waktu($t['dibuat_pada'])) ?></span></span></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
        <?php endif; ?>
    </section>
<?php endforeach;
if ($kelola && $d['unit'] !== []): ?>
<section class="ah-card" aria-labelledby="ah-form-tarif">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-form-tarif">Tetapkan tarif</h2></div>
    <div class="ah-card__body">
        <form method="post" action="<?= ah_e(bsa_url('bsa_tarif.php')) ?>" id="form-tarif">
            <?= ah_csrf() ?>
            <input type="hidden" name="idempotency_key" value="<?= ah_e(bsa_kunci_formulir($isian)) ?>">
            <div class="row g-2 mb-3">
                <div class="col-sm-6"><label class="form-label" for="unit">Unit/jenjang <span class="text-danger" aria-hidden="true">*</span></label>
                    <select class="form-select" id="unit" name="unit" required>
                        <?php foreach ($d['unit'] as $u): ?><option value="<?= ah_e($u) ?>"<?= $nilai('unit') === $u ? ' selected' : '' ?>><?= ah_e($u) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-sm-6"><label class="form-label" for="berlaku_mulai">Mulai berlaku (bulan) <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control" type="month" id="berlaku_mulai" name="berlaku_mulai" required pattern="[0-9]{4}-[0-9]{2}" value="<?= ah_e($nilai('berlaku_mulai', date('Y-m'))) ?>">
                    <p class="form-text mb-0">Berlaku untuk periode ini dan sesudahnya sampai ada tarif baru.</p></div>
            </div>
            <fieldset class="mb-3">
                <legend class="form-label fs-6">Komponen biaya <span class="text-danger" aria-hidden="true">*</span></legend>
                <p class="form-text mt-0">Isi paling sedikit satu baris. Baris yang dibiarkan kosong diabaikan.</p>
                <?php for ($i = 0; $i < 5; $i++):
                    $k = is_array($komponen[$i] ?? null) ? $komponen[$i] : [];
                    $kn = is_string($k['nama'] ?? null) ? $k['nama'] : '';
                    $kv = is_string($k['nominal'] ?? null) ? $k['nominal'] : ''; ?>
                    <div class="row g-2 mb-2">
                        <div class="col-7"><label class="form-label small" for="komponen-<?= $i ?>-nama">Nama komponen <?= $i + 1 ?></label>
                            <input class="form-control" id="komponen-<?= $i ?>-nama" name="komponen[<?= $i ?>][nama]" maxlength="100"<?= $i === 0 ? ' required' : '' ?> value="<?= ah_e($kn) ?>"></div>
                        <div class="col-5"><label class="form-label small" for="komponen-<?= $i ?>-nominal">Nominal (Rp)</label>
                            <input class="form-control" type="text" inputmode="numeric" id="komponen-<?= $i ?>-nominal" name="komponen[<?= $i ?>][nominal]" maxlength="13" pattern="[0-9]{1,3}(\.[0-9]{3})*|[0-9]+" autocomplete="off"<?= $i === 0 ? ' required' : '' ?> value="<?= ah_e($kv) ?>"></div>
                    </div>
                <?php endfor; ?>
            </fieldset>
            <div class="mb-3">
                <label class="form-label" for="alasan">Alasan perubahan <span class="text-muted fw-normal">(wajib bila unit sudah pernah punya tarif)</span></label>
                <input class="form-control" id="alasan" name="alasan" maxlength="500" value="<?= ah_e($nilai('alasan')) ?>">
            </div>
            <p class="form-text">Tarif baru tidak mengubah tagihan atau kuitansi yang sudah terbit. Bila tarif periode mulai yang sama sudah ada, tarif itu ditandai Diganti dan tetap tersimpan.</p>
            <div class="ah-actbar mt-4 mb-0">
                <button class="btn btn-primary" type="submit" data-busy-label="Menyimpan…">Simpan tarif</button>
            </div>
        </form>
    </div>
</section>
<?php endif;
ah_page_close();
