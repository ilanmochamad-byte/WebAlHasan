<?php

declare(strict_types=1);

use App\Penugasan\PenugasanException;
use App\Penugasan\PenugasanJenis;
use App\Penugasan\PenugasanService;

/**
 * Pusat Penugasan (fondasi PRD V3–V6, keputusan pengguna 7 September 2026).
 *
 * Satu halaman admin untuk seluruh penugasan fungsional: murobi, pembimbing,
 * guru mata pelajaran, Bagian Pendidikan, bendahara pembiayaan bulanan,
 * panitia PSB, dan bendahara PSB — ditambah master minimum mata pelajaran.
 *
 * Yang dipegang halaman ini:
 *   - penugasan BUKAN role login; ia menghasilkan capability yang dihitung
 *     server dari akun aktif + role dasar + relasi master + masa berlaku +
 *     tahun ajaran + cakupan;
 *   - seluruh mutasi lewat POST + CSRF dan hanya memanggil `PenugasanService`
 *     (transaksi + audit di layanan); tidak ada query di halaman;
 *   - tidak ada penghapusan permanen: penugasan diakhiri atau dinonaktifkan;
 *   - seluruh keluaran di-escape.
 *
 * Halaman lama `admin_murobi.php` dan `admin_pembimbing.php` tetap berfungsi
 * dan menautkan ke sini.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = penugasan_service();
$aktorId = (int) $currentUser['id'];

$jenis = (string) ($_GET['jenis'] ?? $_POST['jenis'] ?? PenugasanJenis::MUROBI);
$tabMapel = $jenis === 'mata_pelajaran';
if (!$tabMapel && !PenugasanJenis::valid($jenis)) {
    $jenis = PenugasanJenis::MUROBI;
}
$definisi = $tabMapel ? null : PenugasanJenis::definisi($jenis);

/** Filter daftar yang dipertahankan pada pengalihan. */
$filterKeys = ['q', 'tahun_ajaran_id', 'status', 'kelas_id', 'kamar_id', 'mata_pelajaran_id', 'jenjang', 'gelombang', 'page'];
$kembaliKe = static function (array $ganti = []) use ($jenis, $filterKeys): string {
    $query = ['jenis' => $jenis];
    foreach ($filterKeys as $key) {
        if (isset($_GET[$key]) && $_GET[$key] !== '') {
            $query[$key] = (string) $_GET[$key];
        }
    }
    $query = array_merge($query, $ganti);

    return 'admin_penugasan.php?' . http_build_query(array_filter($query, static fn ($v) => $v !== null && $v !== ''));
};

$bucket = '_penugasan_old';
$simpanIsian = static function (array $fields, Throwable $exception) use ($bucket): void {
    ah_old_keep($_POST, $fields, $bucket);
    $_SESSION[$bucket . '_errors'] = $exception instanceof PenugasanException && $exception->errors() !== []
        ? $exception->errors()
        : [];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $tujuan = $kembaliKe();
    try {
        switch ($action) {
            case 'buat':
                if ($tabMapel) {
                    throw new PenugasanException('Aksi tidak dikenal.');
                }
                $baru = $service->buat($jenis, $_POST, $aktorId);
                master_flash('success', 'Penugasan ' . $definisi['label'] . ' #' . $baru . ' tersimpan. Penugasan ini TIDAK membuat akun; capability efektif mengikuti akun, role dasar, dan masa berlaku.');
                break;

            case 'ubah':
                $service->ubah($jenis, $id, $_POST, $aktorId);
                master_flash('success', 'Penugasan #' . $id . ' diperbarui. Perubahan cakupan dan masa berlaku tercatat pada audit.');
                break;

            case 'akhiri':
                $service->akhiri($jenis, $id, (string) ($_POST['tanggal_selesai'] ?? ''), (string) ($_POST['alasan'] ?? ''), $aktorId);
                master_flash('success', 'Penugasan #' . $id . ' diakhiri secara historis. Riwayatnya tetap tersimpan; capability berakhir setelah tanggal selesai.');
                break;

            case 'nonaktifkan':
                $service->nonaktifkan($jenis, $id, (string) ($_POST['alasan'] ?? ''), $aktorId);
                master_flash('success', 'Penugasan #' . $id . ' dinonaktifkan. Capability dicabut pada pemeriksaan server berikutnya; akun dan role dasar tidak berubah.');
                break;

            case 'aktifkan':
                $service->aktifkan($jenis, $id, (string) ($_POST['alasan'] ?? ''), $aktorId);
                master_flash('success', 'Penugasan #' . $id . ' diaktifkan kembali.');
                break;

            case 'mapel_simpan':
                $mapelId = $service->simpanMataPelajaran($_POST, $id > 0 ? $id : null, $aktorId);
                master_flash('success', 'Mata pelajaran #' . $mapelId . ' tersimpan.');
                $tujuan = 'admin_penugasan.php?jenis=mata_pelajaran';
                break;

            case 'mapel_status':
                $service->setMataPelajaranState($id, (string) ($_POST['status_action'] ?? ''), $aktorId);
                master_flash('success', 'Status mata pelajaran diperbarui tanpa menghapus riwayat.');
                $tujuan = 'admin_penugasan.php?jenis=mata_pelajaran';
                break;

            default:
                throw new PenugasanException('Aksi tidak dikenal.');
        }
    } catch (PenugasanException $exception) {
        if (in_array($action, ['buat', 'ubah', 'mapel_simpan'], true)) {
            $simpanIsian(
                ['guru_id', 'pengurus_id', 'tahun_ajaran_id', 'target_type', 'kamar_id', 'kelas_id', 'mata_pelajaran_id',
                    'jenjang', 'gelombang', 'tanggal_mulai', 'tanggal_selesai', 'catatan', 'alasan', 'kode', 'nama', 'kategori'],
                $exception
            );
            if ($action === 'ubah' && $id > 0) {
                $tujuan = $kembaliKe(['edit' => $id]);
            }
            if ($action === 'mapel_simpan') {
                $tujuan = 'admin_penugasan.php?jenis=mata_pelajaran' . ($id > 0 ? '&edit=' . $id : '');
            }
        }
        master_flash('danger', $exception->getMessage());
    } catch (RuntimeException $exception) {
        master_flash('danger', $exception->getMessage());
    }
    master_redirect($tujuan);
}

// ---------------------------------------------------------------------------
// Tab dan kerangka
// ---------------------------------------------------------------------------
$tabs = [];
foreach (PenugasanJenis::semua() as $kunci => $def) {
    $tabs[] = ['label' => $def['label'], 'url' => 'admin_penugasan.php?jenis=' . $kunci, 'active' => !$tabMapel && $jenis === $kunci];
}
$tabs[] = ['label' => 'Mata Pelajaran', 'url' => 'admin_penugasan.php?jenis=mata_pelajaran', 'active' => $tabMapel];

master_header('Pusat Penugasan', [
    'description' => 'Satu pusat untuk seluruh penugasan fungsional. Penugasan bukan role login: ia menghasilkan capability '
        . 'yang dihitung server dari akun aktif, role dasar, relasi master, masa berlaku, tahun ajaran, dan cakupan.',
    'active' => 'master.penugasan',
    'tabs' => $tabs,
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Penugasan'],
        ['label' => 'Pusat Penugasan'],
    ],
    'actions' => '<a class="btn btn-outline-secondary" href="' . ah_e(app_url('/admin/admin_akun.php')) . '">Akun &amp; Hak Akses</a>',
]);

$old = static fn (string $field, ?string $default = null): string => ah_old($field, $default === null ? null : [$field => $default], $bucket);
$err = static fn (string $field): string => ah_field_error($field, $bucket);
$adaOld = isset($_SESSION[$bucket]);

// ===========================================================================
// Tab master mata pelajaran (minimum untuk penugasan guru)
// ===========================================================================
if ($tabMapel) {
    $q = App\Database\PageQuery::term($_GET['q'] ?? '');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $daftarMapel = $service->mataPelajaranDaftar($q, $page);
    $editId = (int) ($_GET['edit'] ?? 0);
    $edit = $editId > 0 ? $service->mataPelajaran($editId) : null;
    if ($editId > 0 && $edit === null) {
        ah_note('warning', 'Mata pelajaran yang diminta tidak ditemukan.');
    }
    ah_note(
        'info',
        'Master mata pelajaran minimum. Dipakai hanya untuk penugasan guru mata pelajaran (kesiapan PRD V4).',
        '<p class="small mb-0 mt-2">Formulir nilai, nilai semester, finalisasi, dan rapor <strong>belum</strong> dibangun pada tahap ini. '
            . 'Tabel warisan <code>mapel</code> tidak disentuh.</p>'
    );
    ?>
    <section class="ah-card" aria-labelledby="ah-form-mapel">
        <div class="ah-card__head"><span id="ah-form-mapel"><?= $edit ? 'Ubah mata pelajaran' : 'Tambah mata pelajaran' ?></span></div>
        <div class="ah-card__body">
            <form method="post" class="row g-3">
                <?= master_csrf() ?>
                <input type="hidden" name="jenis" value="mata_pelajaran">
                <input type="hidden" name="action" value="mapel_simpan">
                <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
                <div class="col-md-3"><label class="form-label" for="kode">Kode <span class="text-muted fw-normal">(opsional)</span></label>
                    <input class="form-control" id="kode" name="kode" maxlength="20" value="<?= ah_e($adaOld ? $old('kode') : (string) ($edit['kode'] ?? '')) ?>"><?= $err('kode') ?></div>
                <div class="col-md-4"><label class="form-label" for="nama">Nama mata pelajaran</label>
                    <input class="form-control" id="nama" name="nama" maxlength="100" required value="<?= ah_e($adaOld ? $old('nama') : (string) ($edit['nama'] ?? '')) ?>"><?= $err('nama') ?></div>
                <div class="col-md-3"><label class="form-label" for="kategori">Kategori <span class="text-muted fw-normal">(opsional)</span></label>
                    <input class="form-control" id="kategori" name="kategori" maxlength="30" value="<?= ah_e($adaOld ? $old('kategori') : (string) ($edit['kategori'] ?? '')) ?>"><?= $err('kategori') ?></div>
                <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Simpan</button></div>
                <?php if ($edit): ?><div class="col-12"><a class="btn btn-sm btn-outline-secondary" href="admin_penugasan.php?jenis=mata_pelajaran">Batal ubah</a></div><?php endif; ?>
            </form>
        </div>
    </section>

    <?php ah_list_search($q, 'Cari nama, kode, atau kategori', ['jenis' => 'mata_pelajaran']); ?>
    <section class="ah-card" aria-labelledby="ah-daftar-mapel">
        <div class="ah-card__head"><span id="ah-daftar-mapel">Daftar mata pelajaran</span>
            <span class="text-muted small"><?= count($daftarMapel['rows']) ?> dari <?= (int) $daftarMapel['total'] ?></span></div>
        <?php if ($daftarMapel['rows'] === []): ?>
            <div class="ah-card__body"><?= $q !== '' ? ah_empty('Tidak ada hasil', 'Coba kata lain atau bersihkan pencarian.') : ah_empty('Belum ada mata pelajaran', 'Tambahkan mata pelajaran pada formulir di atas sebelum menugaskan guru pengampu.') ?></div>
        <?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table">
                <caption class="ah-visually-hidden">Daftar mata pelajaran</caption>
                <thead><tr><th scope="col">Kode</th><th scope="col">Nama</th><th scope="col">Kategori</th><th scope="col">Penugasan</th><th scope="col">Status</th><th scope="col">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($daftarMapel['rows'] as $mp): ?>
                    <tr>
                        <td><?= master_e($mp['kode'] ?? '—') ?></td>
                        <td><?= master_e($mp['nama']) ?></td>
                        <td><?= master_e($mp['kategori'] ?? '—') ?></td>
                        <td><?= (int) $mp['jumlah_penugasan'] ?> penugasan</td>
                        <td><?= ah_state_badge($mp) ?></td>
                        <td><div class="ah-actions">
                            <a class="btn btn-sm btn-outline-primary" href="admin_penugasan.php?jenis=mata_pelajaran&amp;edit=<?= (int) $mp['id'] ?>">Ubah</a>
                            <?php if (empty($mp['archived_at'])): ?>
                            <form method="post" data-confirm="Ubah status mata pelajaran ini? Penugasan guru yang memakainya tidak dihapus, tetapi capability nilai tidak efektif selama mata pelajaran nonaktif.">
                                <?= master_csrf() ?><input type="hidden" name="jenis" value="mata_pelajaran"><input type="hidden" name="action" value="mapel_status"><input type="hidden" name="id" value="<?= (int) $mp['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" name="status_action" value="<?= (int) $mp['is_active'] === 1 ? 'deactivate' : 'activate' ?>"><?= (int) $mp['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                            </form>
                            <?php endif; ?>
                            <form method="post" data-confirm="Ubah status arsip mata pelajaran ini? Riwayat penugasan tetap tersimpan.">
                                <?= master_csrf() ?><input type="hidden" name="jenis" value="mata_pelajaran"><input type="hidden" name="action" value="mapel_status"><input type="hidden" name="id" value="<?= (int) $mp['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" name="status_action" value="<?= $mp['archived_at'] ? 'restore' : 'archive' ?>"><?= $mp['archived_at'] ? 'Pulihkan' : 'Arsipkan' ?></button>
                            </form>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <?php
    master_pagination((int) $daftarMapel['total'], (int) $daftarMapel['page'], 20);
    ah_old_clear($bucket);
    master_footer();
    exit;
}

// ===========================================================================
// Tab penugasan
// ===========================================================================
$filters = [
    'q' => App\Database\PageQuery::term($_GET['q'] ?? ''),
    'tahun_ajaran_id' => (int) ($_GET['tahun_ajaran_id'] ?? 0),
    'status' => in_array((string) ($_GET['status'] ?? ''), ['aktif', 'akan_datang', 'berakhir', 'nonaktif'], true) ? (string) $_GET['status'] : '',
    'kelas_id' => (int) ($_GET['kelas_id'] ?? 0),
    'kamar_id' => (int) ($_GET['kamar_id'] ?? 0),
    'mata_pelajaran_id' => (int) ($_GET['mata_pelajaran_id'] ?? 0),
    'jenjang' => App\Database\PageQuery::term($_GET['jenjang'] ?? ''),
    'gelombang' => App\Database\PageQuery::term($_GET['gelombang'] ?? ''),
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$daftar = $service->daftar($jenis, $filters, $page);
$opsi = $service->opsi($jenis);
$editId = (int) ($_GET['edit'] ?? 0);
$edit = $editId > 0 ? $service->detail($jenis, $editId) : null;
if ($editId > 0 && $edit === null) {
    ah_note('warning', 'Penugasan yang diminta tidak ditemukan pada jenis ini.');
}
$statusTone = static fn (string $status): string => match ($status) {
    PenugasanService::STATUS_AKTIF => 'ok',
    PenugasanService::STATUS_AKAN_DATANG => 'info',
    PenugasanService::STATUS_BERAKHIR => 'muted',
    default => 'warn',
};
$labelTahun = static fn (array $t): string => $t['tahun'] . ' ' . $t['semester'] . ($t['status'] === 'Aktif' ? ' — Aktif' : '');

ah_note(
    'info',
    $definisi['label'] . ' adalah penugasan, bukan role. ' . $definisi['keterangan'],
    '<ul class="small mb-0 mt-2">'
        . '<li>Menghasilkan capability: ' . implode(', ', array_map(static fn (string $c): string => '<code>' . ah_e($c) . '</code>', $definisi['capabilities'])) . ' (kesiapan PRD ' . ah_e($definisi['prd']) . ').</li>'
        . '<li>Capability efektif hanya bila ' . ah_e($definisi['label_subjek']) . ' mempunyai <strong>akun aktif ber-role <code>' . ah_e($definisi['role_dasar']) . '</code></strong>, '
        . 'penugasan aktif pada tanggal berjalan, dan tahun ajaran ' . ($definisi['wajib_tahun_aktif'] ? 'berstatus <strong>Aktif</strong>' : 'belum diarsipkan') . '.</li>'
        . '<li>Penugasan tidak pernah dihapus: <strong>akhiri</strong> (tanggal selesai) atau <strong>nonaktifkan</strong>. Berakhirnya penugasan tidak menonaktifkan akun dan tidak mencabut role dasar.</li>'
        . ($definisi['lama'] ? '<li>Halaman lama <a href="' . ah_e(app_url('/admin/admin_' . $jenis . '.php')) . '">admin_' . ah_e($jenis) . '.php</a> tetap tersedia untuk arsip/pulihkan.</li>' : '')
        . '</ul>'
);
?>

<form method="get" class="ah-card ah-no-print">
    <input type="hidden" name="jenis" value="<?= ah_e($jenis) ?>">
    <div class="ah-card__body">
        <fieldset class="ah-fieldset mb-0">
            <legend>Cari dan saring</legend>
            <div class="row g-2 align-items-end">
                <div class="col-md-4"><label class="form-label" for="q">Nama <?= ah_e($definisi['label_subjek']) ?> / username / cakupan</label>
                    <input class="form-control" type="search" id="q" name="q" maxlength="100" value="<?= ah_e($filters['q']) ?>"></div>
                <div class="col-md-3"><label class="form-label" for="f_tahun">Tahun ajaran</label>
                    <select class="form-select" id="f_tahun" name="tahun_ajaran_id"><option value="">Semua</option>
                        <?php foreach ($opsi['tahun'] as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $filters['tahun_ajaran_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= ah_e($labelTahun($t)) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-2"><label class="form-label" for="f_status">Status</label>
                    <select class="form-select" id="f_status" name="status">
                        <?php foreach (['' => 'Semua', 'aktif' => 'Aktif', 'akan_datang' => 'Akan Datang', 'berakhir' => 'Berakhir', 'nonaktif' => 'Dinonaktifkan'] as $v => $l): ?>
                            <option value="<?= ah_e($v) ?>" <?= $filters['status'] === $v ? 'selected' : '' ?>><?= ah_e($l) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <?php if ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_TARGET): ?>
                    <div class="col-md-3"><label class="form-label" for="f_kamar">Kamar</label>
                        <select class="form-select" id="f_kamar" name="kamar_id"><option value="">Semua</option>
                            <?php foreach ($opsi['kamar'] as $k): ?><option value="<?= (int) $k['id'] ?>" <?= $filters['kamar_id'] === (int) $k['id'] ? 'selected' : '' ?>><?= ah_e($k['nama_kamar']) ?></option><?php endforeach; ?>
                        </select></div>
                <?php endif; ?>
                <?php if (in_array($definisi['cakupan'], [PenugasanJenis::CAKUPAN_TARGET, PenugasanJenis::CAKUPAN_MAPEL_KELAS], true)): ?>
                    <div class="col-md-3"><label class="form-label" for="f_kelas">Kelas</label>
                        <select class="form-select" id="f_kelas" name="kelas_id"><option value="">Semua</option>
                            <?php foreach ($opsi['kelas'] as $k): ?><option value="<?= (int) $k['id'] ?>" <?= $filters['kelas_id'] === (int) $k['id'] ? 'selected' : '' ?>><?= ah_e($k['nama_kelas'] . ' (' . $k['jenjang'] . ')') ?></option><?php endforeach; ?>
                        </select></div>
                <?php endif; ?>
                <?php if ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_MAPEL_KELAS): ?>
                    <div class="col-md-3"><label class="form-label" for="f_mapel">Mata pelajaran</label>
                        <select class="form-select" id="f_mapel" name="mata_pelajaran_id"><option value="">Semua</option>
                            <?php foreach ($opsi['mata_pelajaran'] as $m): ?><option value="<?= (int) $m['id'] ?>" <?= $filters['mata_pelajaran_id'] === (int) $m['id'] ? 'selected' : '' ?>><?= ah_e($m['nama']) ?></option><?php endforeach; ?>
                        </select></div>
                <?php endif; ?>
                <?php if ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_JENJANG): ?>
                    <div class="col-md-3"><label class="form-label" for="f_jenjang">Unit/jenjang</label>
                        <select class="form-select" id="f_jenjang" name="jenjang"><option value="">Semua</option>
                            <?php foreach ($opsi['jenjang'] as $j): ?><option value="<?= ah_e($j) ?>" <?= $filters['jenjang'] === $j ? 'selected' : '' ?>><?= ah_e($j) ?></option><?php endforeach; ?>
                        </select></div>
                <?php endif; ?>
                <?php if ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_GELOMBANG): ?>
                    <div class="col-md-3"><label class="form-label" for="f_gelombang">Gelombang</label>
                        <input class="form-control" id="f_gelombang" name="gelombang" maxlength="50" value="<?= ah_e($filters['gelombang']) ?>"></div>
                <?php endif; ?>
                <div class="col-md-3 d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">Terapkan</button>
                    <a class="btn btn-outline-secondary" href="admin_penugasan.php?jenis=<?= ah_e($jenis) ?>">Bersihkan</a></div>
            </div>
        </fieldset>
    </div>
</form>

<?php
// ---------------------------------------------------------------------------
// Formulir buat / ubah
// ---------------------------------------------------------------------------
$modeUbah = $edit !== null;
$nilai = static function (string $field, mixed $default = '') use ($adaOld, $old, $edit): string {
    if ($adaOld) {
        return $old($field, (string) $default);
    }

    return (string) ($edit[$field] ?? $default);
};
$subjekKolom = $definisi['subjek_kolom'];
?>
<section class="ah-card" aria-labelledby="ah-form-penugasan">
    <div class="ah-card__head"><span id="ah-form-penugasan"><?= $modeUbah ? 'Ubah penugasan #' . (int) $edit['id'] : 'Tambah penugasan ' . ah_e($definisi['label']) ?></span>
        <?php if ($modeUbah): ?><a class="btn btn-sm btn-outline-secondary" href="<?= ah_e($kembaliKe(['edit' => null])) ?>">Batal ubah</a><?php endif; ?></div>
    <div class="ah-card__body">
        <?php if ($opsi['subjek'] === []): ?>
            <p class="text-muted mb-0">Belum ada <?= ah_e($definisi['label_subjek']) ?> aktif. Tambahkan data <?= ah_e($definisi['label_subjek']) ?> terlebih dahulu.</p>
        <?php elseif ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_MAPEL_KELAS && $opsi['mata_pelajaran'] === []): ?>
            <p class="text-muted mb-0">Belum ada mata pelajaran aktif. <a href="admin_penugasan.php?jenis=mata_pelajaran">Tambahkan mata pelajaran</a> terlebih dahulu.</p>
        <?php else: ?>
        <form method="post" class="row g-3" data-confirm="<?= $modeUbah ? 'Simpan perubahan penugasan? Cakupan dan masa berlaku baru berlaku pada pemeriksaan server berikutnya dan tercatat pada audit.' : 'Simpan penugasan ' . ah_e($definisi['label']) . '? Penugasan tidak membuat akun; capability efektif mengikuti akun, role dasar, dan masa berlaku.' ?>">
            <?= master_csrf() ?>
            <input type="hidden" name="jenis" value="<?= ah_e($jenis) ?>">
            <input type="hidden" name="action" value="<?= $modeUbah ? 'ubah' : 'buat' ?>">
            <?php if ($modeUbah): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>

            <div class="col-md-4"><label class="form-label" for="<?= ah_e($subjekKolom) ?>"><?= ah_e($definisi['label_subjek']) ?></label>
                <?php if ($modeUbah): ?>
                    <input class="form-control" id="<?= ah_e($subjekKolom) ?>" value="<?= ah_e($edit['subjek_nama'] . ($edit['subjek_keterangan'] ? ' — ' . $edit['subjek_keterangan'] : '')) ?>" disabled>
                    <div class="form-text">Ganti orang berarti penugasan baru; akhiri penugasan ini lalu buat yang baru.</div>
                <?php else: ?>
                    <select class="form-select" id="<?= ah_e($subjekKolom) ?>" name="<?= ah_e($subjekKolom) ?>" required>
                        <option value="">Pilih <?= ah_e($definisi['label_subjek']) ?> aktif</option>
                        <?php foreach ($opsi['subjek'] as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" <?= $nilai($subjekKolom) === (string) $s['id'] ? 'selected' : '' ?>><?= ah_e($s['nama'] . ($s['keterangan'] ? ' — ' . $s['keterangan'] : '')) ?></option>
                        <?php endforeach; ?>
                    </select><?= $err($subjekKolom) ?>
                <?php endif; ?>
            </div>

            <div class="col-md-3"><label class="form-label" for="tahun_ajaran_id">Tahun ajaran<?= $definisi['cakupan'] === PenugasanJenis::CAKUPAN_GELOMBANG ? ' penerimaan' : '' ?></label>
                <?php if ($modeUbah): ?>
                    <input class="form-control" id="tahun_ajaran_id" value="<?= ah_e($edit['tahun'] . ' ' . $edit['semester']) ?>" disabled>
                <?php else: ?>
                    <select class="form-select" id="tahun_ajaran_id" name="tahun_ajaran_id" required>
                        <?php foreach ($opsi['tahun'] as $t): ?>
                            <option value="<?= (int) $t['id'] ?>" <?= $adaOld ? ($nilai('tahun_ajaran_id') === (string) $t['id'] ? 'selected' : '') : ($t['status'] === 'Aktif' ? 'selected' : '') ?>><?= ah_e($labelTahun($t)) ?></option>
                        <?php endforeach; ?>
                    </select><?= $err('tahun_ajaran_id') ?>
                    <?php if (!$definisi['wajib_tahun_aktif']): ?><div class="form-text">PSB boleh memakai tahun ajaran yang belum aktif.</div><?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_TARGET): ?>
                <div class="col-md-2"><label class="form-label" for="target_type">Jenis kelompok</label>
                    <select class="form-select" id="target_type" name="target_type">
                        <?php foreach (['Kamar', 'Kelas'] as $tt): ?><option value="<?= $tt ?>" <?= $nilai('target_type', 'Kamar') === $tt ? 'selected' : '' ?>><?= $tt ?></option><?php endforeach; ?>
                    </select><?= $err('target_type') ?></div>
                <div class="col-md-3 target-kamar"><label class="form-label" for="kamar_id">Kamar binaan</label>
                    <select class="form-select" id="kamar_id" name="kamar_id"><option value="">Pilih kamar</option>
                        <?php foreach ($opsi['kamar'] as $k): ?><option value="<?= (int) $k['id'] ?>" <?= $nilai('kamar_id') === (string) $k['id'] ? 'selected' : '' ?>><?= ah_e($k['nama_kamar']) ?></option><?php endforeach; ?>
                    </select><?= $err('kamar_id') ?></div>
                <div class="col-md-3 target-kelas"><label class="form-label" for="kelas_id">Kelas binaan</label>
                    <select class="form-select" id="kelas_id" name="kelas_id"><option value="">Pilih kelas</option>
                        <?php foreach ($opsi['kelas'] as $k): ?><option value="<?= (int) $k['id'] ?>" <?= $nilai('kelas_id') === (string) $k['id'] ? 'selected' : '' ?>><?= ah_e($k['nama_kelas'] . ' (' . $k['jenjang'] . ')') ?></option><?php endforeach; ?>
                    </select><?= $err('kelas_id') ?></div>
            <?php elseif ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_MAPEL_KELAS): ?>
                <div class="col-md-3"><label class="form-label" for="mata_pelajaran_id">Mata pelajaran</label>
                    <select class="form-select" id="mata_pelajaran_id" name="mata_pelajaran_id" required><option value="">Pilih mata pelajaran</option>
                        <?php foreach ($opsi['mata_pelajaran'] as $m): ?><option value="<?= (int) $m['id'] ?>" <?= $nilai('mata_pelajaran_id') === (string) $m['id'] ? 'selected' : '' ?>><?= ah_e(($m['kode'] ? $m['kode'] . ' — ' : '') . $m['nama']) ?></option><?php endforeach; ?>
                    </select><?= $err('mata_pelajaran_id') ?></div>
                <div class="col-md-3"><label class="form-label" for="kelas_id">Kelas</label>
                    <select class="form-select" id="kelas_id" name="kelas_id" required><option value="">Pilih kelas</option>
                        <?php foreach ($opsi['kelas'] as $k): ?><option value="<?= (int) $k['id'] ?>" <?= $nilai('kelas_id') === (string) $k['id'] ? 'selected' : '' ?>><?= ah_e($k['nama_kelas'] . ' (' . $k['jenjang'] . ')') ?></option><?php endforeach; ?>
                    </select><?= $err('kelas_id') ?>
                    <div class="form-text">Semester mengikuti tahun ajaran yang dipilih.</div></div>
            <?php elseif ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_JENJANG): ?>
                <div class="col-md-3"><label class="form-label" for="jenjang">Unit/jenjang <span class="text-muted fw-normal">(opsional)</span></label>
                    <select class="form-select" id="jenjang" name="jenjang"><option value="">Seluruh unit</option>
                        <?php foreach ($opsi['jenjang'] as $j): ?><option value="<?= ah_e($j) ?>" <?= $nilai('jenjang') === $j ? 'selected' : '' ?>><?= ah_e($j) ?></option><?php endforeach; ?>
                    </select><?= $err('jenjang') ?></div>
            <?php else: ?>
                <div class="col-md-3"><label class="form-label" for="gelombang">Gelombang <span class="text-muted fw-normal">(opsional)</span></label>
                    <input class="form-control" id="gelombang" name="gelombang" maxlength="50" placeholder="Kosong = seluruh gelombang" value="<?= ah_e($nilai('gelombang')) ?>"><?= $err('gelombang') ?></div>
            <?php endif; ?>

            <div class="col-md-3"><label class="form-label" for="tanggal_mulai">Tanggal mulai</label>
                <input class="form-control" type="date" id="tanggal_mulai" name="tanggal_mulai" required value="<?= ah_e($nilai('tanggal_mulai', $modeUbah ? '' : date('Y-m-d'))) ?>"><?= $err('tanggal_mulai') ?></div>
            <div class="col-md-3"><label class="form-label" for="tanggal_selesai">Tanggal selesai <span class="text-muted fw-normal">(opsional)</span></label>
                <input class="form-control" type="date" id="tanggal_selesai" name="tanggal_selesai" value="<?= ah_e($nilai('tanggal_selesai')) ?>"><?= $err('tanggal_selesai') ?></div>
            <div class="col-md-6"><label class="form-label" for="catatan">Catatan <span class="text-muted fw-normal">(opsional)</span></label>
                <input class="form-control" id="catatan" name="catatan" maxlength="500" value="<?= ah_e($nilai('catatan')) ?>"><?= $err('catatan') ?></div>
            <?php if ($modeUbah): ?>
                <div class="col-md-6"><label class="form-label" for="alasan">Alasan perubahan <span class="text-danger">(wajib)</span></label>
                    <input class="form-control" id="alasan" name="alasan" minlength="5" maxlength="500" required value="<?= ah_e($adaOld ? $old('alasan') : '') ?>"><?= $err('alasan') ?></div>
            <?php endif; ?>
            <div class="col-12"><button class="btn btn-primary"><?= $modeUbah ? 'Simpan perubahan' : 'Simpan penugasan' ?></button></div>
        </form>
        <?php endif; ?>
    </div>
</section>

<?php
// ---------------------------------------------------------------------------
// Daftar
// ---------------------------------------------------------------------------
$efektifCache = [];
$efektif = static function (int $subjekId) use (&$efektifCache, $service, $jenis): array {
    return $efektifCache[$subjekId] ??= $service->capabilityEfektif($jenis, $subjekId);
};
?>
<section class="ah-card" aria-labelledby="ah-daftar-penugasan">
    <div class="ah-card__head"><span id="ah-daftar-penugasan">Daftar penugasan <?= ah_e($definisi['label']) ?></span>
        <span class="text-muted small"><?= count($daftar['rows']) ?> dari <?= (int) $daftar['total'] ?></span></div>
    <?php if ($daftar['rows'] === []): ?>
        <div class="ah-card__body"><?= ($filters['q'] !== '' || $filters['status'] !== '' || $filters['tahun_ajaran_id'] > 0)
            ? ah_empty('Tidak ada penugasan sesuai filter', 'Ubah kata kunci, tahun ajaran, status, atau cakupan.')
            : ah_empty('Belum ada penugasan ' . $definisi['label'], 'Tambahkan penugasan pada formulir di atas.') ?></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table">
            <caption class="ah-visually-hidden">Daftar penugasan <?= ah_e($definisi['label']) ?> beserta masa berlaku, cakupan, status, dan capability efektif</caption>
            <thead><tr>
                <th scope="col"><?= ah_e($definisi['label_subjek']) ?></th><th scope="col">Tahun ajaran</th><th scope="col">Cakupan</th>
                <th scope="col">Masa berlaku</th><th scope="col">Status</th><th scope="col">Capability efektif akun</th><th scope="col">Tindakan</th>
            </tr></thead>
            <tbody>
            <?php foreach ($daftar['rows'] as $r):
                $rid = (int) $r['id'];
                $status = (string) $r['status_label'];
                $ef = $efektif((int) $r['subjek_id']);
                $hidup = (int) $r['is_active'] === 1 && empty($r['archived_at']);
                ?>
                <tr>
                    <td><strong><?= master_e($r['subjek_nama']) ?></strong>
                        <?php if (!empty($r['subjek_keterangan'])): ?><span class="ah-cell-sub"><?= master_e($r['subjek_keterangan']) ?></span><?php endif; ?>
                        <?php if ((int) $r['subjek_aktif'] !== 1 || !empty($r['subjek_arsip'])): ?><?= ah_badge('Data master nonaktif', 'danger') ?><?php endif; ?>
                        <span class="ah-cell-sub">#<?= $rid ?><?= $r['created_by_nama'] ? ' · oleh ' . master_e($r['created_by_nama']) : '' ?></span></td>
                    <td><?= master_e($r['tahun'] . ' ' . $r['semester']) ?>
                        <?php if ($r['tahun_status'] !== 'Aktif'): ?><span class="ah-cell-sub"><?= ah_badge($definisi['wajib_tahun_aktif'] ? 'Tahun tidak aktif' : 'Tahun belum aktif', 'muted') ?></span><?php endif; ?></td>
                    <td><?= master_e($r['cakupan_teks']) ?>
                        <?php if (isset($r['kelas_aktif']) && $r['kelas_id'] !== null && ((int) $r['kelas_aktif'] !== 1 || !empty($r['kelas_arsip']))): ?><span class="ah-cell-sub"><?= ah_badge('Kelas nonaktif', 'warn') ?></span><?php endif; ?>
                        <?php if (isset($r['mapel_aktif']) && ((int) $r['mapel_aktif'] !== 1 || !empty($r['mapel_arsip']))): ?><span class="ah-cell-sub"><?= ah_badge('Mata pelajaran nonaktif', 'warn') ?></span><?php endif; ?></td>
                    <td class="small"><?= master_e($r['masa_berlaku']) ?>
                        <?php if (!empty($r['diakhiri_pada'])): ?><span class="ah-cell-sub">Diakhiri <?= master_e($r['diakhiri_oleh_nama'] ? 'oleh ' . $r['diakhiri_oleh_nama'] : '') ?>: <?= master_e($r['alasan_pengakhiran'] ?? '') ?></span><?php endif; ?>
                        <?php if (!empty($r['catatan'])): ?><span class="ah-cell-sub">Catatan: <?= master_e($r['catatan']) ?></span><?php endif; ?></td>
                    <td><?= ah_badge($status, $statusTone($status)) ?></td>
                    <td class="small">
                        <?php if ($ef['akun'] === null): ?>
                            <?= ah_badge('Belum punya akun', 'warn') ?>
                            <span class="ah-cell-sub"><?= master_e($ef['pesan']) ?></span>
                        <?php else: ?>
                            <span class="ah-cell-sub">@<?= master_e($ef['akun']['username']) ?> · role: <?= master_e($ef['akun']['roles'] === [] ? 'tanpa role' : implode(', ', $ef['akun']['roles'])) ?></span>
                            <?php if ($ef['capabilities'] === []): ?>
                                <?= ah_badge('Belum efektif', 'muted') ?>
                                <?php if ($ef['pesan'] !== null): ?><span class="ah-cell-sub"><?= master_e($ef['pesan']) ?></span><?php endif; ?>
                            <?php else: ?>
                                <span class="d-flex flex-wrap gap-1 mt-1"><?php foreach ($ef['capabilities'] as $cap): ?><?= ah_badge($cap, 'ok') ?><?php endforeach; ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><div class="ah-actions flex-column align-items-start">
                        <?php if (empty($r['archived_at'])): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= ah_e($kembaliKe(['edit' => $rid])) ?>#ah-form-penugasan">Ubah</a>
                        <?php endif; ?>
                        <?php if ($hidup && $status !== PenugasanService::STATUS_BERAKHIR): ?>
                            <details><summary class="btn btn-sm btn-outline-secondary">Akhiri…</summary>
                                <form method="post" class="mt-2 d-grid gap-1" style="min-width:14rem" data-confirm="Akhiri penugasan #<?= $rid ?>? Riwayat tetap tersimpan; capability berakhir setelah tanggal selesai.">
                                    <?= master_csrf() ?><input type="hidden" name="jenis" value="<?= ah_e($jenis) ?>"><input type="hidden" name="action" value="akhiri"><input type="hidden" name="id" value="<?= $rid ?>">
                                    <label class="form-label small mb-0" for="akhiri_tgl_<?= $rid ?>">Tanggal selesai</label>
                                    <input class="form-control form-control-sm" type="date" id="akhiri_tgl_<?= $rid ?>" name="tanggal_selesai" required value="<?= ah_e(max(date('Y-m-d'), (string) $r['tanggal_mulai'])) ?>">
                                    <label class="form-label small mb-0" for="akhiri_alasan_<?= $rid ?>">Alasan</label>
                                    <input class="form-control form-control-sm" id="akhiri_alasan_<?= $rid ?>" name="alasan" minlength="5" maxlength="500" required>
                                    <button class="btn btn-sm btn-secondary">Akhiri penugasan</button>
                                </form></details>
                        <?php endif; ?>
                        <?php if (empty($r['archived_at'])): ?>
                            <details><summary class="btn btn-sm <?= $hidup ? 'btn-outline-danger' : 'btn-outline-success' ?>"><?= $hidup ? 'Nonaktifkan…' : 'Aktifkan…' ?></summary>
                                <form method="post" class="mt-2 d-grid gap-1" style="min-width:14rem" data-confirm="<?= $hidup ? 'Nonaktifkan penugasan #' . $rid . '? Capability dicabut pada pemeriksaan server berikutnya; akun dan role dasar tidak berubah.' : 'Aktifkan kembali penugasan #' . $rid . '? Pemeriksaan tumpang tindih dijalankan ulang.' ?>">
                                    <?= master_csrf() ?><input type="hidden" name="jenis" value="<?= ah_e($jenis) ?>"><input type="hidden" name="action" value="<?= $hidup ? 'nonaktifkan' : 'aktifkan' ?>"><input type="hidden" name="id" value="<?= $rid ?>">
                                    <label class="form-label small mb-0" for="status_alasan_<?= $rid ?>">Alasan</label>
                                    <input class="form-control form-control-sm" id="status_alasan_<?= $rid ?>" name="alasan" minlength="5" maxlength="500" required>
                                    <button class="btn btn-sm <?= $hidup ? 'btn-danger' : 'btn-success' ?>"><?= $hidup ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                                </form></details>
                        <?php else: ?>
                            <span class="text-muted small">Diarsipkan; kelola dari halaman lama.</span>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php if ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_TARGET): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var type = document.getElementById('target_type');
    if (!type) { return; }
    var sync = function () {
        document.querySelectorAll('.target-kamar').forEach(function (el) { el.classList.toggle('d-none', type.value !== 'Kamar'); });
        document.querySelectorAll('.target-kelas').forEach(function (el) { el.classList.toggle('d-none', type.value !== 'Kelas'); });
    };
    type.addEventListener('change', sync);
    sync();
});
</script>
<?php endif; ?>
<?php
master_pagination((int) $daftar['total'], (int) $daftar['page'], 20);
ah_old_clear($bucket);
master_footer();
