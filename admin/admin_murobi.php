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
        if (($action ?? '') === 'save') { ah_validation_keep($_POST, ['guru_id', 'tahun_ajaran_id', 'target_type', 'kamar_id', 'kelas_id', 'tanggal_mulai', 'tanggal_selesai'], $exception, '_murobi_old'); }
        master_flash('danger', $exception->getMessage());
    }
    master_redirect('admin_murobi.php');
}

$q = App\Database\PageQuery::term($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = $service->murobiPage($q, $page);
$rows = $result['rows'];
$page = $result['page'];

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

<?php ah_list_search($q, 'Cari nama, tahun, atau kelompok'); ?>
<section class="ah-card" aria-labelledby="ah-form-murobi">
    <div class="ah-card__head"><span id="ah-form-murobi">Tambah penugasan</span></div>
    <div class="ah-card__body">
        <form method="post" class="row g-3" data-confirm="Simpan penugasan murobi? Guru memperoleh kemampuan keputusan izin sesuai kelompok dan masa aktif penugasan.">
            <?= master_csrf() ?>
            <input type="hidden" name="action" value="save">
            <div class="col-md-4"><label class="form-label" for="guru_id">Guru</label>
                <select class="form-select" id="guru_id" name="guru_id" required>
                    <option value="" <?php if (isset($_SESSION['_murobi_old']['guru_id'])): ?><?= ah_old('guru_id',null,'_murobi_old') === (string)('') ? 'selected' : '' ?><?php else: ?><?php endif; ?>>Pilih guru</option>
                    <?php foreach ($gurus as $g): ?><option value="<?= (int) $g['id'] ?>" <?php if (isset($_SESSION['_murobi_old']['guru_id'])): ?><?= ah_old('guru_id',null,'_murobi_old') === (string)((int) $g['id']) ? 'selected' : '' ?><?php else: ?><?php endif; ?>><?= master_e($g['nama_guru']) ?></option><?php endforeach; ?>
                </select><?= ah_field_error('guru_id','_murobi_old') ?></div>
            <div class="col-md-3"><label class="form-label" for="tahun_ajaran_id">Tahun ajaran</label>
                <select class="form-select" id="tahun_ajaran_id" name="tahun_ajaran_id" required>
                    <?php foreach ($years as $year): if ($year['archived_at']) { continue; } ?>
                        <option value="<?= (int) $year['id'] ?>" <?php if (isset($_SESSION['_murobi_old']['tahun_ajaran_id'])): ?><?= ah_old('tahun_ajaran_id',null,'_murobi_old') === (string)((int) $year['id']) ? 'selected' : '' ?><?php else: ?><?php endif; ?>><?= master_e($year['tahun'] . ' ' . $year['semester'] . ($year['status'] === 'Aktif' ? ' — Aktif' : '')) ?></option>
                    <?php endforeach; ?>
                </select><?= ah_field_error('tahun_ajaran_id','_murobi_old') ?></div>
            <div class="col-md-2"><label class="form-label" for="target_type">Jenis kelompok</label>
                <select class="form-select" id="target_type" name="target_type"><option <?php if (isset($_SESSION['_murobi_old']['target_type'])): ?><?= ah_old('target_type',null,'_murobi_old') === (string)('Kamar') ? 'selected' : '' ?><?php else: ?><?php endif; ?>>Kamar</option><option <?php if (isset($_SESSION['_murobi_old']['target_type'])): ?><?= ah_old('target_type',null,'_murobi_old') === (string)('Kelas') ? 'selected' : '' ?><?php else: ?><?php endif; ?>>Kelas</option></select><?= ah_field_error('target_type','_murobi_old') ?></div>
            <div class="col-md-3 target-kamar"><label class="form-label" for="kamar_id">Kamar binaan</label>
                <select class="form-select" id="kamar_id" name="kamar_id">
                    <option value="" <?php if (isset($_SESSION['_murobi_old']['kamar_id'])): ?><?= ah_old('kamar_id',null,'_murobi_old') === (string)('') ? 'selected' : '' ?><?php else: ?><?php endif; ?>>Pilih kamar</option>
                    <?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>" <?php if (isset($_SESSION['_murobi_old']['kamar_id'])): ?><?= ah_old('kamar_id',null,'_murobi_old') === (string)((int) $room['id']) ? 'selected' : '' ?><?php else: ?><?php endif; ?>><?= master_e($room['nama_kamar']) ?></option><?php endforeach; ?>
                </select><?= ah_field_error('kamar_id','_murobi_old') ?></div>
            <div class="col-md-3 target-kelas d-none"><label class="form-label" for="kelas_id">Kelas binaan</label>
                <select class="form-select" id="kelas_id" name="kelas_id">
                    <option value="" <?php if (isset($_SESSION['_murobi_old']['kelas_id'])): ?><?= ah_old('kelas_id',null,'_murobi_old') === (string)('') ? 'selected' : '' ?><?php else: ?><?php endif; ?>>Pilih kelas</option>
                    <?php foreach ($classes as $class): if ($class['archived_at']) { continue; } ?>
                        <option value="<?= (int) $class['id'] ?>" <?php if (isset($_SESSION['_murobi_old']['kelas_id'])): ?><?= ah_old('kelas_id',null,'_murobi_old') === (string)((int) $class['id']) ? 'selected' : '' ?><?php else: ?><?php endif; ?>><?= master_e($class['nama_kelas']) ?></option>
                    <?php endforeach; ?>
                </select><?= ah_field_error('kelas_id','_murobi_old') ?></div>
            <div class="col-md-3"><label class="form-label" for="tanggal_mulai">Tanggal mulai</label>
                <input class="form-control" type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?= ah_e(ah_old('tanggal_mulai', ['tanggal_mulai'=>date('Y-m-d')], '_murobi_old')) ?>" required><?= ah_field_error('tanggal_mulai','_murobi_old') ?></div>
            <div class="col-md-3"><label class="form-label" for="tanggal_selesai">Tanggal selesai <span class="text-muted fw-normal">(opsional)</span></label>
                <input class="form-control" type="date" id="tanggal_selesai" name="tanggal_selesai" value="<?= ah_e(ah_old('tanggal_selesai', null, '_murobi_old')) ?>"><?= ah_field_error('tanggal_selesai','_murobi_old') ?></div>
            <div class="col-12"><button class="btn btn-primary">Simpan penugasan</button></div>
        </form>
    </div>
</section>

<section class="ah-card" aria-labelledby="ah-daftar-murobi">
    <div class="ah-card__head"><span id="ah-daftar-murobi">Daftar penugasan</span></div>
    <?php if ($rows === []): ?>
        <div class="ah-card__body"><?= $q !== '' ? ah_empty('Tidak ada hasil sesuai pencarian', 'Coba kata lain atau bersihkan pencarian untuk melihat daftar lengkap.') : ah_empty('Belum ada penugasan murobi', 'Tambahkan penugasan pada formulir di atas untuk menetapkan guru sebagai murobi kelompok binaan.') ?></div>
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
                        <form method="post" onsubmit="return confirm('Ubah status arsip penugasan ini? Kemampuan murobi mengikuti penugasan aktif; riwayat pengajuan dan keputusan lama TIDAK dihapus.')">
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
<?php master_pagination((int) $result['total'], $page, 20); ah_old_clear('_murobi_old'); master_footer(); ?>
