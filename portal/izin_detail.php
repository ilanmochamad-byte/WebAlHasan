<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Izin\IzinException;

require_once __DIR__ . '/_ui.php';

$requestedMode = isset($_GET['mode']) ? (string) $_GET['mode'] : null;
$id = (int) ($_GET['id'] ?? 0);

try {
    $detail = izin_service()->detail($currentUser, $id, $requestedMode);
} catch (IzinException $exception) {
    // Cakupan diperiksa di server: mengubah parameter ID tidak pernah membuka
    // pengajuan milik pengurus, murobi, atau orang tua lain.
    http_response_code($exception->status());
    portal_header('Akses ditolak', $userCapabilities, $userCapabilities[0] ?? '', $currentUser);
    echo '<div class="alert alert-danger"><strong>' . (int) $exception->status() . '</strong> — ' . portal_e($exception->getMessage()) . '</div>';
    echo '<a class="btn btn-outline-secondary" href="' . portal_e(app_url('/portal/izin.php')) . '">Kembali ke daftar</a>';
    portal_footer();
    exit;
}

$scope = $detail['scope'];
$izin = $detail['pengajuan'];
$keputusan = $detail['keputusan'];

// Tombol di bawah HANYA cermin dari hak yang dihitung server. Menampilkan atau
// menyembunyikannya tidak pernah menjadi kontrol akses: setiap POST diperiksa
// ulang oleh IzinWorkflowService (PRD 5.2).
$aksi = izin_workflow_service()->actionsFor($izin, $scope);
$kandidatMurobi = ($aksi['tetapkan_murobi'] ?? false) ? izin_workflow_service()->eligibleMurobi() : [];
$versi = (int) $izin['version'];

portal_header('Detail Izin #' . (int) $izin['id'], $userCapabilities, $scope['mode'], $currentUser, ['show_heading' => false]);
portal_flash_render();
?>
<div class="d-flex flex-wrap justify-content-between align-items-center border-bottom pb-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Detail Izin #<?= (int) $izin['id'] ?></h1>
        <p class="text-muted mb-0"><?= portal_e($scope['label']) ?></p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= portal_e(app_url('/portal/izin.php') . '?mode=' . rawurlencode($scope['mode'])) ?>">Kembali ke daftar</a>
</div>

<?php if ($izin['is_legacy']): ?>
    <div class="alert alert-warning">
        <strong>Data warisan.</strong> Pengajuan ini berasal dari tabel <code>perizinan</code> V1 dengan ID
        <?= (int) $izin['legacy_perizinan_id'] ?>. Sistem lama tidak mencatat pengurus pengaju, murobi tujuan,
        maupun pemberi keputusan, sehingga kolom pelaku sengaja dikosongkan dan tidak diisi akun pengganti.
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>Rincian pengajuan</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Santri</dt><dd class="col-sm-8"><?= portal_e($izin['nama_santri']) ?> (<?= portal_e($izin['nis']) ?>)</dd>
                    <dt class="col-sm-4">Tanggal izin</dt><dd class="col-sm-8"><?= portal_e($izin['tgl_izin']) ?></dd>
                    <dt class="col-sm-4">Tanggal kembali</dt><dd class="col-sm-8"><?= portal_e($izin['tgl_kembali']) ?></dd>
                    <dt class="col-sm-4">Alasan</dt><dd class="col-sm-8"><?= nl2br(portal_e($izin['alasan'])) ?></dd>
                    <dt class="col-sm-4">Catatan pengurus</dt><dd class="col-sm-8"><?= $izin['catatan_pengurus'] === null ? '<span class="text-muted">—</span>' : nl2br(portal_e($izin['catatan_pengurus'])) ?></dd>
                    <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?= portal_status_badge((string) $izin['status']) ?></dd>
                    <dt class="col-sm-4">Pengurus pengaju</dt><dd class="col-sm-8"><?= portal_e($izin['pengurus_label']) ?></dd>
                    <dt class="col-sm-4">Murobi tujuan</dt><dd class="col-sm-8"><?= portal_e($izin['murobi_label']) ?></dd>
                    <dt class="col-sm-4">Tahun ajaran</dt><dd class="col-sm-8"><?= $izin['tahun_ajaran'] === null ? '<span class="text-muted">Data warisan</span>' : portal_e($izin['tahun_ajaran'] . ' ' . $izin['semester']) ?></dd>
                    <dt class="col-sm-4">Diajukan pada</dt><dd class="col-sm-8"><?= portal_e($izin['diajukan_pada'] ?? 'Data warisan') ?></dd>
                    <dt class="col-sm-4">Routing</dt><dd class="col-sm-8">
                        <?= $izin['routing_catatan'] === null
                            ? '<span class="text-muted">' . ($izin['is_legacy'] ? 'Data warisan' : 'Belum dijalankan') . '</span>'
                            : portal_e($izin['routing_catatan']) ?>
                        <?php if ($izin['routing_pada'] !== null): ?>
                            <br><span class="text-muted small">Kandidat: <?= (int) $izin['routing_kandidat'] ?> — <?= portal_e($izin['routing_pada']) ?></span>
                        <?php endif; ?>
                    </dd>
                    <?php if ($izin['murobi_ditetapkan_pada'] !== null): ?>
                        <dt class="col-sm-4">Penetapan murobi</dt><dd class="col-sm-8">
                            <?= portal_e(($izin['penetap_nama'] ?? 'Admin') . ' — ' . $izin['murobi_ditetapkan_pada']) ?>
                        </dd>
                    <?php endif; ?>
                    <?php if ($izin['dibatalkan_pada'] !== null): ?>
                        <dt class="col-sm-4">Pembatalan</dt><dd class="col-sm-8">
                            <?= portal_e(($izin['pembatal_nama'] ?? 'Data warisan') . ' — ' . $izin['dibatalkan_pada']) ?>
                            <br><span class="text-muted small"><?= portal_e((string) $izin['alasan_pembatalan']) ?></span>
                        </dd>
                    <?php endif; ?>
                    <dt class="col-sm-4">Versi data</dt><dd class="col-sm-8"><span class="text-muted small">v<?= $versi ?></span></dd>
                </dl>
            </div>
        </div>

        <?php if ($aksi['putuskan_murobi'] || $aksi['putuskan_admin']): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <strong><?= $aksi['putuskan_murobi'] ? 'Keputusan murobi' : 'Keputusan Admin Pengganti' ?></strong>
                </div>
                <div class="card-body">
                    <?php if (!$aksi['putuskan_murobi']): ?>
                        <p class="text-muted small">
                            Admin memutus sebagai <strong>Admin Pengganti</strong>. Alasan penggantian wajib diisi dan
                            disimpan bersama keputusan serta audit.
                        </p>
                    <?php endif; ?>
                    <form data-confirm="Simpan keputusan izin ini? Keputusan berlaku bagi pengajuan dan dicatat beserta pelaku serta alasan." method="post" action="<?= portal_e(app_url('/portal/izin_aksi.php')) ?>">
                        <?= portal_csrf() ?>
                        <input type="hidden" name="aksi" value="putuskan">
                        <input type="hidden" name="mode" value="<?= portal_e($scope['mode']) ?>">
                        <input type="hidden" name="pengajuan_id" value="<?= (int) $izin['id'] ?>">
                        <input type="hidden" name="version" value="<?= $versi ?>">
                        <input type="hidden" name="idempotency_key" value="<?= portal_e(portal_idempotency_key()) ?>">
                        <div class="mb-3">
                            <label class="form-label" for="hasil">Hasil</label>
                            <select class="form-select" id="hasil" name="hasil" required>
                                <option value="Disetujui" <?= ah_old('hasil',['hasil'=>'Disetujui'],'_portal_' . (int)$izin['id'] . '_putuskan') === 'Disetujui' ? 'selected' : '' ?>>Disetujui</option>
                                <option value="Ditolak" <?= ah_old('hasil',['hasil'=>'Disetujui'],'_portal_' . (int)$izin['id'] . '_putuskan') === 'Ditolak' ? 'selected' : '' ?>>Ditolak</option>
                            </select><?= ah_field_error('hasil','_portal_' . (int)$izin['id'] . '_putuskan') ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="alasan_keputusan">Alasan keputusan</label>
                            <textarea class="form-control" id="alasan_keputusan" name="alasan" rows="2" required minlength="3" maxlength="2000"><?= portal_e(ah_old('alasan',null,'_portal_' . (int)$izin['id'] . '_putuskan')) ?></textarea><?= ah_field_error('alasan','_portal_' . (int)$izin['id'] . '_putuskan') ?>
                        </div>
                        <?php if (!$aksi['putuskan_murobi']): ?>
                            <div class="mb-3">
                                <label class="form-label" for="alasan_penggantian">Alasan penggantian murobi <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="alasan_penggantian" name="alasan_penggantian" rows="2" required minlength="3" maxlength="1000"
                                          placeholder="Contoh: murobi berhalangan dan izin dibutuhkan hari ini"><?= portal_e(ah_old('alasan_penggantian',null,'_portal_' . (int)$izin['id'] . '_putuskan')) ?></textarea><?= ah_field_error('alasan_penggantian','_portal_' . (int)$izin['id'] . '_putuskan') ?>
                            </div>
                        <?php endif; ?>
                        <button class="btn btn-success">Simpan keputusan</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($aksi['tetapkan_murobi']): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Tetapkan / ganti murobi</strong></div>
                <div class="card-body">
                    <?php if ($kandidatMurobi === []): ?>
                        <p class="text-muted mb-0">
                            Belum ada guru dengan penugasan murobi aktif pada tahun ajaran aktif.
                            Buat penugasan murobi lebih dulu pada menu master data.
                        </p>
                    <?php else: ?>
                        <form data-confirm="Ganti murobi tujuan? Antrean keputusan berpindah ke murobi yang dipilih; riwayat penetapan tetap tersimpan." method="post" action="<?= portal_e(app_url('/portal/izin_aksi.php')) ?>">
                            <?= portal_csrf() ?>
                            <input type="hidden" name="aksi" value="tetapkan">
                            <input type="hidden" name="mode" value="<?= portal_e($scope['mode']) ?>">
                            <input type="hidden" name="pengajuan_id" value="<?= (int) $izin['id'] ?>">
                            <input type="hidden" name="version" value="<?= $versi ?>">
                            <input type="hidden" name="idempotency_key" value="<?= portal_e(portal_idempotency_key()) ?>">
                            <div class="mb-3">
                                <label class="form-label" for="murobi_guru_id">Murobi tujuan</label>
                                <select class="form-select" id="murobi_guru_id" name="murobi_guru_id" required>
                                    <option value="">— Pilih murobi —</option>
                                    <?php foreach ($kandidatMurobi as $kandidat): ?>
                                        <option value="<?= (int) $kandidat['guru_id'] ?>" <?= (int) ah_old('murobi_guru_id', $izin, '_portal_' . (int)$izin['id'] . '_tetapkan') === (int) $kandidat['guru_id'] ? 'selected' : '' ?>>
                                            <?= portal_e($kandidat['nama_guru']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select><?= ah_field_error('murobi_guru_id','_portal_' . (int)$izin['id'] . '_tetapkan') ?>
                                <div class="form-text">Hanya guru dengan penugasan murobi aktif yang dapat ditetapkan.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="alasan_penetapan">Alasan penetapan <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="alasan_penetapan" name="alasan" rows="2" required minlength="3" maxlength="1000"><?= portal_e(ah_old('alasan',null,'_portal_' . (int)$izin['id'] . '_tetapkan')) ?></textarea><?= ah_field_error('alasan','_portal_' . (int)$izin['id'] . '_tetapkan') ?>
                            </div>
                            <button class="btn btn-primary">Tetapkan murobi</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($aksi['batalkan']): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Batalkan pengajuan</strong></div>
                <div class="card-body">
                    <p class="text-muted small">Pembatalan hanya mungkin sebelum ada keputusan dan tidak menghapus riwayat.</p>
                    <form data-confirm="Batalkan pengajuan ini? Pengajuan berhenti diproses; riwayat pengajuan tetap tersimpan." method="post" action="<?= portal_e(app_url('/portal/izin_aksi.php')) ?>">
                        <?= portal_csrf() ?>
                        <input type="hidden" name="aksi" value="batalkan">
                        <input type="hidden" name="mode" value="<?= portal_e($scope['mode']) ?>">
                        <input type="hidden" name="pengajuan_id" value="<?= (int) $izin['id'] ?>">
                        <input type="hidden" name="version" value="<?= $versi ?>">
                        <input type="hidden" name="idempotency_key" value="<?= portal_e(portal_idempotency_key()) ?>">
                        <div class="mb-3">
                            <label class="form-label" for="alasan_pembatalan">Alasan pembatalan <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="alasan_pembatalan" name="alasan" rows="2" required minlength="3" maxlength="1000"><?= portal_e(ah_old('alasan',null,'_portal_' . (int)$izin['id'] . '_batalkan')) ?></textarea><?= ah_field_error('alasan','_portal_' . (int)$izin['id'] . '_batalkan') ?>
                        </div>
                        <button class="btn btn-outline-danger">Batalkan pengajuan</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($aksi['koreksi']): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Koreksi keputusan (admin)</strong></div>
                <div class="card-body">
                    <p class="text-muted small">
                        Koreksi menyimpan nilai sebelum dan sesudah beserta alasannya sebagai peristiwa baru.
                        Keputusan dan riwayat sebelumnya tidak dihapus.
                    </p>
                    <form data-confirm="Simpan koreksi keputusan? Status terkini berubah dan peristiwa koreksi dicatat; keputusan serta riwayat sebelumnya tidak dihapus." method="post" action="<?= portal_e(app_url('/portal/izin_aksi.php')) ?>">
                        <?= portal_csrf() ?>
                        <input type="hidden" name="aksi" value="koreksi">
                        <input type="hidden" name="mode" value="<?= portal_e($scope['mode']) ?>">
                        <input type="hidden" name="pengajuan_id" value="<?= (int) $izin['id'] ?>">
                        <input type="hidden" name="version" value="<?= $versi ?>">
                        <input type="hidden" name="idempotency_key" value="<?= portal_e(portal_idempotency_key()) ?>">
                        <div class="mb-3">
                            <label class="form-label" for="hasil_koreksi">Hasil setelah koreksi</label>
                            <select class="form-select" id="hasil_koreksi" name="hasil" required>
                                <option value="Disetujui" <?= ah_old('hasil',['hasil'=>$izin['status']],'_portal_' . (int)$izin['id'] . '_koreksi') === 'Disetujui' ? 'selected' : '' ?>>Disetujui</option>
                                <option value="Ditolak" <?= ah_old('hasil',['hasil'=>$izin['status']],'_portal_' . (int)$izin['id'] . '_koreksi') === 'Ditolak' ? 'selected' : '' ?>>Ditolak</option>
                            </select><?= ah_field_error('hasil','_portal_' . (int)$izin['id'] . '_koreksi') ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="alasan_hasil_koreksi">Alasan keputusan setelah koreksi</label>
                            <textarea class="form-control" id="alasan_hasil_koreksi" name="alasan" rows="2" required minlength="3" maxlength="2000"><?= portal_e(ah_old('alasan',null,'_portal_' . (int)$izin['id'] . '_koreksi')) ?></textarea><?= ah_field_error('alasan','_portal_' . (int)$izin['id'] . '_koreksi') ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="alasan_koreksi">Alasan koreksi <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="alasan_koreksi" name="alasan_koreksi" rows="2" required minlength="3" maxlength="1000"><?= portal_e(ah_old('alasan_koreksi',null,'_portal_' . (int)$izin['id'] . '_koreksi')) ?></textarea><?= ah_field_error('alasan_koreksi','_portal_' . (int)$izin['id'] . '_koreksi') ?>
                        </div>
                        <button class="btn btn-warning">Simpan koreksi</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($scope['mode'] === Capabilities::ORANG_TUA): ?>
            <div class="alert alert-secondary small">
                Akun orang tua bersifat baca-saja: tidak tersedia tombol pengajuan, keputusan, pembatalan, atau koreksi.
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>Keputusan</strong></div>
            <div class="card-body">
                <?php if ($keputusan === null): ?>
                    <p class="text-muted mb-0">
                        <?= $izin['is_legacy']
                            ? 'Data warisan: sistem lama hanya menyimpan status akhir tanpa alasan atau pemberi keputusan.'
                            : 'Belum ada keputusan untuk pengajuan ini.' ?>
                    </p>
                <?php else: ?>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Hasil</dt><dd class="col-sm-8"><?= portal_e($keputusan['hasil']) ?></dd>
                        <dt class="col-sm-4">Alasan keputusan</dt><dd class="col-sm-8"><?= nl2br(portal_e($keputusan['alasan'])) ?></dd>
                        <dt class="col-sm-4">Kapasitas</dt><dd class="col-sm-8"><?= portal_e($keputusan['kapasitas']) ?></dd>
                        <dt class="col-sm-4">Pemberi keputusan</dt><dd class="col-sm-8"><?= portal_e($keputusan['pemberi_keputusan'] ?? 'Data warisan') ?></dd>
                        <dt class="col-sm-4">Waktu</dt><dd class="col-sm-8"><?= portal_e($keputusan['diputus_pada']) ?></dd>
                        <?php if (($keputusan['kapasitas'] ?? '') === 'Admin Pengganti' && ($keputusan['alasan_penggantian'] ?? null) !== null): ?>
                            <dt class="col-sm-4">Alasan penggantian</dt><dd class="col-sm-8"><?= nl2br(portal_e((string) $keputusan['alasan_penggantian'])) ?></dd>
                        <?php endif; ?>
                    </dl>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($detail['koreksi'] !== []): ?>
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-white"><strong>Koreksi keputusan</strong></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Waktu</th><th>Perubahan</th><th>Alasan koreksi</th><th>Pelaku</th></tr></thead>
                        <tbody>
                        <?php foreach ($detail['koreksi'] as $koreksi): ?>
                            <tr>
                                <td class="small"><?= portal_e($koreksi['dikoreksi_pada']) ?></td>
                                <td class="small">
                                    <?= portal_e($koreksi['hasil_sebelum'] . ' → ' . $koreksi['hasil_sesudah']) ?>
                                    <br><span class="text-muted">Alasan sebelum: <?= portal_e(mb_strimwidth((string) $koreksi['alasan_sebelum'], 0, 80, '…')) ?></span>
                                </td>
                                <td class="small"><?= portal_e($koreksi['alasan_koreksi']) ?></td>
                                <td class="small"><?= portal_e($koreksi['pelaku_nama'] ?? 'Data warisan') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><strong>Riwayat status</strong></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Waktu</th><th>Peristiwa</th><th>Pelaku</th></tr></thead>
                    <tbody>
                    <?php foreach ($detail['riwayat'] as $entry): ?>
                        <tr>
                            <td class="small"><?= portal_e($entry['created_at']) ?></td>
                            <td class="small">
                                <?= portal_e($entry['peristiwa']) ?>
                                <?php if ($entry['status_sesudah'] !== null): ?>
                                    <br><span class="text-muted"><?= portal_e(($entry['status_sebelum'] ?? '—') . ' → ' . $entry['status_sesudah']) ?></span>
                                <?php endif; ?>
                                <?php if ($entry['alasan'] !== null): ?>
                                    <br><span class="text-muted"><?= portal_e($entry['alasan']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= portal_e($entry['pelaku_nama'] ?? 'Data warisan') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($detail['riwayat'] === []): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">Belum ada riwayat.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php foreach (['putuskan','tetapkan','batalkan','koreksi'] as $formAction) { ah_old_clear('_portal_' . (int)$izin['id'] . '_' . $formAction); } portal_footer(); ?>
