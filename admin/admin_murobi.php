<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;

/**
 * Penugasan Murobi.
 *
 * **Koreksi ke-3 (30 Agustus 2026).** Keterangan lama pada halaman ini
 * menyatakan bahwa penugasan murobi "tidak memberi akun atau akses approval
 * izin". Kalimat itu benar untuk V1, tetapi menyesatkan sejak V2: penugasan
 * murobi aktif MEMANG menjadi sumber kemampuan keputusan perizinan — hanya
 * saja kemampuan itu baru muncul bila guru yang bersangkutan juga mempunyai
 * akun ber-role `guru`. Penugasan sendiri tetap tidak membuat akun.
 *
 * Aturan V2 yang ditampilkan halaman ini:
 *   - murobi = guru + penugasan murobi aktif (bukan role terpisah);
 *   - penugasan tidak pernah membuat akun login;
 *   - guru tanpa jadwal mengajar tetap dapat ditugaskan sebagai murobi.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = master_data_service();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            $service->saveMurobi($_POST, (int) $currentUser['id']);
            master_flash('success', 'Penugasan murobi berhasil disimpan. Penugasan ini tidak membuat akun login.');
        } else {
            $service->setMurobiState((int) ($_POST['id'] ?? 0), $action);
            master_flash('success', 'Status penugasan murobi diperbarui tanpa menghapus riwayat.');
        }
    } catch (MasterDataException $exception) {
        master_flash('danger', $exception->getMessage());
    }
    master_redirect('admin_murobi.php');
}

$rows = $service->murobi();
$gurus = $service->guruOptions();
$years = $service->years();
$classes = $service->classes();
$rooms = $service->kamarOptions();

master_header('Penugasan Murobi', [
    'description' => 'Menghubungkan guru dengan kelompok binaan (kamar atau kelas) pada satu semester.',
    'active' => 'master.murobi',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Penugasan'],
        ['label' => 'Murobi'],
    ],
]);

ah_note(
    'info',
    'Murobi bukan role tersendiri: murobi adalah guru yang memegang penugasan murobi aktif.',
    '<ul class="small mb-0 mt-2">'
        . '<li>Menyimpan penugasan di halaman ini <strong>tidak membuat akun login</strong> untuk guru tersebut.</li>'
        . '<li>Kemampuan <strong>approval izin</strong> baru muncul bila guru tersebut memiliki akun aktif ber-role <code>guru</code> '
        . '<em>dan</em> penugasan ini aktif pada tahun ajaran aktif. Tanpa salah satunya, permintaan keputusan tetap ditolak server.</li>'
        . '<li>Guru <strong>tanpa jadwal mengajar</strong> tetap dapat ditugaskan sebagai murobi.</li>'
        . '<li>Menonaktifkan atau mengarsipkan penugasan langsung mencabut kemampuan keputusan pada pemeriksaan server berikutnya.</li>'
        . '</ul>'
);
?>

<section class="ah-card" aria-labelledby="ah-form-murobi">
    <div class="ah-card__head"><span id="ah-form-murobi">Tambah penugasan</span></div>
    <div class="ah-card__body">
        <form method="post" class="row g-3">
            <?= master_csrf() ?>
            <input type="hidden" name="action" value="save">
            <div class="col-md-4"><label class="form-label" for="guru_id">Guru</label>
                <select class="form-select" id="guru_id" name="guru_id" required>
                    <option value="">Pilih guru</option>
                    <?php foreach ($gurus as $g): ?><option value="<?= (int) $g['id'] ?>"><?= master_e($g['nama_guru']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-md-3"><label class="form-label" for="tahun_ajaran_id">Tahun ajaran</label>
                <select class="form-select" id="tahun_ajaran_id" name="tahun_ajaran_id" required>
                    <?php foreach ($years as $year): if ($year['archived_at']) { continue; } ?>
                        <option value="<?= (int) $year['id'] ?>"><?= master_e($year['tahun'] . ' ' . $year['semester'] . ($year['status'] === 'Aktif' ? ' — Aktif' : '')) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-2"><label class="form-label" for="target_type">Jenis kelompok</label>
                <select class="form-select" id="target_type" name="target_type"><option>Kamar</option><option>Kelas</option></select></div>
            <div class="col-md-3 target-kamar"><label class="form-label" for="kamar_id">Kamar binaan</label>
                <select class="form-select" id="kamar_id" name="kamar_id">
                    <option value="">Pilih kamar</option>
                    <?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>"><?= master_e($room['nama_kamar']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-md-3 target-kelas d-none"><label class="form-label" for="kelas_id">Kelas binaan</label>
                <select class="form-select" id="kelas_id" name="kelas_id">
                    <option value="">Pilih kelas</option>
                    <?php foreach ($classes as $class): if ($class['archived_at']) { continue; } ?>
                        <option value="<?= (int) $class['id'] ?>"><?= master_e($class['nama_kelas']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-3"><label class="form-label" for="tanggal_mulai">Tanggal mulai</label>
                <input class="form-control" type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-3"><label class="form-label" for="tanggal_selesai">Tanggal selesai <span class="text-muted fw-normal">(opsional)</span></label>
                <input class="form-control" type="date" id="tanggal_selesai" name="tanggal_selesai"></div>
            <div class="col-12"><button class="btn btn-primary">Simpan penugasan</button></div>
        </form>
    </div>
</section>

<section class="ah-card" aria-labelledby="ah-daftar-murobi">
    <div class="ah-card__head"><span id="ah-daftar-murobi">Daftar penugasan</span></div>
    <?php if ($rows === []): ?>
        <div class="ah-card__body"><?= ah_empty('Belum ada penugasan murobi', 'Tambahkan penugasan pada formulir di atas untuk menetapkan guru sebagai murobi kelompok binaan.') ?></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table">
            <caption class="ah-visually-hidden">Daftar penugasan murobi</caption>
            <thead><tr><th scope="col">Guru</th><th scope="col">Semester</th><th scope="col">Kelompok</th><th scope="col">Rentang</th><th scope="col">Status</th><th scope="col">Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= master_e($r['nama_guru']) ?></td>
                    <td><?= master_e($r['tahun'] . ' ' . $r['semester']) ?></td>
                    <td><?= master_e($r['target_type'] . ': ' . $r['target_name']) ?></td>
                    <td><?= master_e($r['tanggal_mulai'] . ' — ' . ($r['tanggal_selesai'] ?: 'seterusnya')) ?></td>
                    <td><?= ah_state_badge($r) ?></td>
                    <td><div class="ah-actions">
                        <form method="post" onsubmit="return confirm('Ubah status penugasan ini? Bila dinonaktifkan, kemampuan approval izin guru tersebut dicabut pada pemeriksaan server berikutnya.')">
                            <?= master_csrf() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary" name="action" value="<?= (int) $r['is_active'] === 1 ? 'deactivate' : 'activate' ?>"><?= (int) $r['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
                        <form method="post" onsubmit="return confirm('Arsipkan penugasan ini? Riwayat pengajuan dan keputusan lama TIDAK dihapus.')">
                            <?= master_csrf() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" name="action" value="<?= $r['archived_at'] ? 'restore' : 'archive' ?>"><?= $r['archived_at'] ? 'Pulihkan' : 'Arsipkan' ?></button></form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var type = document.getElementById('target_type');
    var sync = function () {
        document.querySelector('.target-kamar').classList.toggle('d-none', type.value !== 'Kamar');
        document.querySelector('.target-kelas').classList.toggle('d-none', type.value !== 'Kelas');
    };
    type.addEventListener('change', sync);
    sync();
});
</script>
<?php master_footer(); ?>
