<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Izin\IzinException;

require_once __DIR__ . '/_ui.php';

/**
 * Ajukan izin (Fase 2 §2; redesain website Tahap 4, I-4).
 *
 * Satu kotak pilih santri yang dapat dicari menggantikan daftar + dropdown
 * ganda; memilih santri tidak lagi memuat ulang halaman. Panel "Akan
 * diteruskan ke" membaca murobi tujuan dan jumlah izin santri semester ini
 * (keputusan Q) dari server lewat `?info=1`, yang memeriksa cakupan yang
 * SAMA dengan pengajuan. "Alasan cepat" hanya mengisi awal teks alasan
 * (keputusan M); tidak ada kolom database baru.
 *
 * Daftar santri yang tampil DIBATASI SERVER pada cakupan penugasan pembimbing
 * aktif pengurus. Memilih santri di luar cakupan lewat manipulasi form tetap
 * ditolak `403` oleh IzinWorkflowService::create(); daftar ini hanya
 * kenyamanan tampilan.
 */

$requestedMode = portal_requested_mode($currentUser, $userCapabilities);

// --- Info panel (JSON, baca-saja) ------------------------------------------
if (($_GET['info'] ?? '') === '1') {
    $santriId = (int) ($_GET['santri_id'] ?? 0);
    try {
        $tujuan = izin_workflow_service()->routingPreview($currentUser, $santriId, $requestedMode);
        $jumlah = izin_service()->santriSemesterCount($currentUser, $santriId, $requestedMode);
    } catch (IzinException $exception) {
        ah_json(['ok' => false, 'message' => $exception->getMessage()], $exception->status());
    }
    ah_json([
        'ok' => true,
        'murobi' => portal_tujuan_teks($tujuan),
        'jumlah' => portal_jumlah_teks($jumlah),
    ]);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$query = is_string($_GET['q'] ?? null) ? mb_substr(trim((string) $_GET['q']), 0, 100) : '';

try {
    $pilihan = izin_workflow_service()->selectableSantri($currentUser, $query, $page, 100, $requestedMode);
} catch (IzinException $exception) {
    http_response_code($exception->status());
    portal_header('Akses ditolak', $userCapabilities, $userCapabilities[0] ?? '', $currentUser);
    ah_note('danger', (int) $exception->status() . ' — ' . $exception->getMessage(),
        '<p class="mb-0 mt-2"><a href="' . portal_e(app_url('/portal/izin.php')) . '">Kembali ke daftar</a></p>');
    ah_old_clear('_portal_0_buat'); portal_footer();
    exit;
}

$scope = $pilihan['scope'];
$mode = (string) $scope['mode'];
$today = date('Y-m-d');
$bucket = '_portal_0_buat';
$santriTerpilih = (int) (ah_old('santri_id', null, $bucket) ?: ($_GET['santri_id'] ?? 0));

/**
 * @param array{murobi:array<int,string>, perlu_admin:bool} $tujuan
 */
function portal_tujuan_teks(array $tujuan): string
{
    if (!$tujuan['perlu_admin']) {
        return $tujuan['murobi'][0];
    }

    return $tujuan['murobi'] === []
        ? 'Belum ada murobi untuk kamar/kelas santri; admin akan menetapkan murobi.'
        : 'Lebih dari satu murobi (' . implode(', ', $tujuan['murobi']) . '); admin akan menetapkan.';
}

/**
 * @param array{total:int, disetujui:int, semester:string} $jumlah
 */
function portal_jumlah_teks(array $jumlah): string
{
    return $jumlah['total'] . ' pengajuan, ' . $jumlah['disetujui'] . ' disetujui (' . $jumlah['semester'] . ')';
}

// Panel untuk santri yang sudah terpilih (tanpa JavaScript, atau setelah
// validasi gagal) dihitung server dengan pemeriksaan cakupan yang sama.
$infoMurobi = 'Pilih santri lebih dahulu';
$infoJumlah = '—';
$infoTerisi = false;
if ($santriTerpilih > 0) {
    try {
        $infoMurobi = portal_tujuan_teks(izin_workflow_service()->routingPreview($currentUser, $santriTerpilih, $mode));
        $infoJumlah = portal_jumlah_teks(izin_service()->santriSemesterCount($currentUser, $santriTerpilih, $mode));
        $infoTerisi = true;
    } catch (IzinException) {
        $santriTerpilih = 0;
    }
}

portal_header('Ajukan izin santri', $userCapabilities, $mode, $currentUser, [
    'active' => 'izin',
    'description' => $scope['label'],
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Perizinan', 'url' => app_url('/portal/izin_ringkasan.php')],
        ['label' => 'Ajukan izin'],
    ],
]);
portal_flash_render();
portal_mode_switcher($userCapabilities, $mode, app_url('/portal/izin_buat.php'));
echo ah_error_summary($bucket, 'Pengajuan belum terkirim.');

if ($mode === Capabilities::ADMIN): ?>
    <div class="ah-ribbon" role="note"><i class="fas fa-triangle-exclamation mt-1" aria-hidden="true"></i>
        <span><b>Mode admin.</b> Admin dapat mengajukan izin untuk santri mana pun bila diperlukan. Tindakan tercatat di audit sebagai pengajuan oleh admin, tanpa pengurus pengaju.</span></div>
<?php endif; ?>

<div class="ah-split">
    <section class="ah-card" aria-labelledby="ah-form-izin">
        <div class="ah-card__head"><span id="ah-form-izin">Rincian izin</span></div>
        <div class="ah-card__body">
            <?php if ($pilihan['rows'] === [] && $query === ''): ?>
                <?= ah_empty(
                    'Belum ada santri yang dapat diajukan',
                    $mode === Capabilities::PENGURUS
                        ? 'Belum ada penugasan pembimbing aktif untuk akun ini. Hubungi admin.'
                        : 'Belum ada santri aktif pada data santri.'
                ) ?>
            <?php else: ?>
                <?php if ($pilihan['total'] > count($pilihan['rows']) || $query !== ''): ?>
                    <form method="get" class="mb-3 ah-no-print" role="search">
                        <input type="hidden" name="mode" value="<?= portal_e($mode) ?>">
                        <label class="form-label" for="cari-santri">Cari santri</label>
                        <div class="d-flex gap-2"><input class="form-control" type="search" id="cari-santri" name="q" maxlength="100" value="<?= portal_e($query) ?>" placeholder="Nama santri atau NIS">
                            <button class="btn btn-outline-secondary" type="submit">Cari</button></div>
                        <p class="form-text mb-0">Menampilkan <?= count($pilihan['rows']) ?> dari <?= (int) $pilihan['total'] ?> santri<?= $query !== '' ? ' yang cocok' : '' ?>.</p>
                    </form>
                <?php endif; ?>
                <form method="post" action="<?= portal_e(app_url('/portal/izin_aksi.php')) ?>" id="form-pengajuan">
                    <?= portal_csrf() ?>
                    <input type="hidden" name="aksi" value="buat">
                    <input type="hidden" name="return_page" value="<?= (int) $page ?>">
                    <input type="hidden" name="return_q" value="<?= portal_e($query) ?>">
                    <input type="hidden" name="mode" value="<?= portal_e($mode) ?>">
                    <input type="hidden" name="idempotency_key" value="<?= portal_e(portal_idempotency_key()) ?>">

                    <div class="mb-3">
                        <label class="form-label" for="santri_id">Santri <span class="text-danger">*</span></label>
                        <input class="form-control mb-2" type="search" hidden data-ah-option-filter="santri_id" placeholder="Ketik nama atau NIS untuk menyaring" aria-label="Saring daftar santri" aria-describedby="santri-filter-status" autocomplete="off">
                        <span class="ah-visually-hidden" id="santri-filter-status" aria-live="polite"></span>
                        <select class="form-select" id="santri_id" name="santri_id" required>
                            <option value="">— Pilih santri (<?= (int) $pilihan['total'] ?>) —</option>
                            <?php foreach ($pilihan['rows'] as $row): ?>
                                <option value="<?= (int) $row['id'] ?>"<?= (int) $row['id'] === $santriTerpilih ? ' selected' : '' ?>>
                                    <?= portal_e($row['nama_santri'] . ' · ' . $row['nis'] . (($row['target_name'] ?? '') !== '' ? ' · ' . $row['target_name'] : '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select><?= ah_field_error('santri_id', $bucket) ?>
                        <p class="form-text mb-0">Hanya santri dalam cakupan Anda; server memeriksa ulang setiap pengiriman.</p>
                    </div>

                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label" for="tgl_izin">Tanggal izin <span class="text-danger">*</span></label>
                            <input class="form-control" type="date" id="tgl_izin" name="tgl_izin" value="<?= portal_e(ah_old('tgl_izin', ['tgl_izin' => $today], $bucket)) ?>" required><?= ah_field_error('tgl_izin', $bucket) ?>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="tgl_kembali">Tanggal kembali <span class="text-danger">*</span></label>
                            <input class="form-control" type="date" id="tgl_kembali" name="tgl_kembali" value="<?= portal_e(ah_old('tgl_kembali', ['tgl_kembali' => $today], $bucket)) ?>" required><?= ah_field_error('tgl_kembali', $bucket) ?>
                            <p class="form-text mb-0">Tidak boleh mendahului tanggal izin.</p>
                        </div>
                    </div>

                    <div class="mt-3">
                        <span class="form-label d-block" id="alasan-cepat-label">Alasan cepat</span>
                        <div class="ah-quick" role="group" aria-labelledby="alasan-cepat-label">
                            <?php foreach (['Pulang', 'Berobat', 'Acara keluarga', 'Lainnya'] as $cepat): ?>
                                <button class="btn btn-sm btn-outline-secondary" type="button" aria-pressed="false" data-ah-prefix="<?= portal_e($cepat) ?>: " data-ah-prefix-target="alasan"><?= portal_e($cepat) ?></button>
                            <?php endforeach; ?>
                        </div>
                        <label class="form-label" for="alasan">Alasan <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="alasan" name="alasan" rows="3" required minlength="3" maxlength="2000" placeholder="Contoh: Pulang: menghadiri pernikahan kakak"><?= portal_e(ah_old('alasan', null, $bucket)) ?></textarea><?= ah_field_error('alasan', $bucket) ?>
                    </div>

                    <div class="mt-3">
                        <label class="form-label" for="catatan_pengurus">Catatan untuk murobi <span class="text-muted">(opsional)</span></label>
                        <textarea class="form-control" id="catatan_pengurus" name="catatan_pengurus" rows="2" maxlength="1000"><?= portal_e(ah_old('catatan_pengurus', null, $bucket)) ?></textarea><?= ah_field_error('catatan_pengurus', $bucket) ?>
                    </div>

                    <div class="ah-actbar mt-4 mb-0">
                        <a class="btn btn-outline-secondary" href="<?= portal_e(app_url('/portal/izin.php') . '?mode=' . rawurlencode($mode)) ?>">Batal</a>
                        <button class="btn btn-primary" type="submit" data-busy-label="Mengirim…">Kirim pengajuan</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <aside class="ah-card" aria-labelledby="ah-tujuan" data-ah-izin-info="form-pengajuan"
           data-ah-izin-url="<?= portal_e(app_url('/portal/izin_buat.php') . '?' . http_build_query(['info' => '1', 'mode' => $mode])) ?>"
           data-ah-izin-loaded="<?= $infoTerisi ? '1' : '0' ?>">
        <div class="ah-card__head"><span id="ah-tujuan">Akan diteruskan ke</span></div>
        <div class="ah-card__body" aria-live="polite">
            <dl class="ah-info-list">
                <div><dt>Murobi</dt><dd data-ah-info="murobi"><?= portal_e($infoMurobi) ?></dd></div>
                <div><dt>Durasi</dt><dd data-ah-info="durasi">—</dd></div>
                <div><dt>Izin semester ini</dt><dd data-ah-info="jumlah"><?= portal_e($infoJumlah) ?></dd></div>
            </dl>
            <?php ah_note('info', 'Pengurus dan wali santri menerima notifikasi setelah murobi memutuskan.'); ?>
            <p class="small text-muted mb-0">Murobi dicari dari penugasan aktif kamar/kelas santri. Bila tidak tepat satu, pengajuan masuk antrean penetapan admin.</p>
        </div>
    </aside>
</div>
<?php ah_old_clear($bucket); portal_footer(); ?>
