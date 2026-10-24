<?php

declare(strict_types=1);

use App\Penugasan\PenugasanException;
use App\Penugasan\PenugasanJenis;
use App\Penugasan\PenugasanService;

/**
 * Pusat Penugasan (fondasi PRD V3–V6, keputusan pengguna 7 September 2026).
 *
 * Satu halaman admin untuk seluruh penugasan fungsional: murobi, pembimbing,
 * guru mata pelajaran, Bagian Pendidikan, petugas kesehatan (PRD V5),
 * bendahara pembiayaan bulanan, panitia PSB, dan bendahara PSB. Master mata pelajaran pindah ke
 * `admin_mata_pelajaran.php` (M-5, redesain Tahap 7); POST lamanya tetap
 * diterima di sini.
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
// Panel: tambah, ubah, kelola (akhiri/nonaktifkan/arsip) — redesain Tahap 6, G-1.
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
        if (!$tabMapel && !$service->skemaTersedia($jenis)) {
            throw new PenugasanException('Tabel penugasan ' . $definisi['label'] . ' belum dipasang. Jalankan migrasi basis data terbaru terlebih dahulu.');
        }
        switch ($action) {
            case 'buat':
                if ($tabMapel) {
                    throw new PenugasanException('Aksi tidak dikenal.');
                }
                $baru = $service->buat($jenis, $_POST, $aktorId);
                master_flash('success', 'Penugasan ' . $definisi['label'] . ' tersimpan. Penugasan tidak membuat akun; hak baru berlaku bila akun aktif, berperan sesuai, dan masa berlaku sedang berjalan.');
                break;

            case 'ubah':
                $service->ubah($jenis, $id, $_POST, $aktorId);
                master_flash('success', 'Penugasan diperbarui. Perubahan cakupan dan masa berlaku tercatat pada audit.');
                break;

            case 'akhiri':
                $service->akhiri($jenis, $id, (string) ($_POST['tanggal_selesai'] ?? ''), (string) ($_POST['alasan'] ?? ''), $aktorId);
                master_flash('success', 'Penugasan diakhiri. Riwayatnya tetap tersimpan; hak berakhir setelah tanggal selesai.');
                break;

            case 'nonaktifkan':
                $service->nonaktifkan($jenis, $id, (string) ($_POST['alasan'] ?? ''), $aktorId);
                master_flash('success', 'Penugasan dinonaktifkan. Hak dicabut pada pemeriksaan server berikutnya; akun dan peran dasar tidak berubah.');
                break;

            case 'aktifkan':
                $service->aktifkan($jenis, $id, (string) ($_POST['alasan'] ?? ''), $aktorId);
                master_flash('success', 'Penugasan diaktifkan kembali.');
                break;

            case 'arsipkan':
            case 'pulihkan':
                // G-1: arsip/pulihkan penugasan murobi/pembimbing (sebelumnya
                // hanya di halaman lama yang kini dialihkan ke sini).
                $service->statusLama($jenis, $id, $action === 'arsipkan' ? 'archive' : 'restore', $aktorId, (string) ($_POST['alasan'] ?? ''));
                master_flash('success', 'Penugasan ' . ($action === 'arsipkan' ? 'diarsipkan' : 'dipulihkan') . '. Riwayatnya tetap tersimpan.');
                break;

            case 'mapel_simpan':
                $mapelId = $service->simpanMataPelajaran($_POST, $id > 0 ? $id : null, $aktorId);
                master_flash('success', 'Mata pelajaran #' . $mapelId . ' tersimpan.');
                $tujuan = 'admin_mata_pelajaran.php';
                break;

            case 'mapel_status':
                $service->setMataPelajaranState($id, (string) ($_POST['status_action'] ?? ''), $aktorId);
                master_flash('success', 'Status mata pelajaran diperbarui tanpa menghapus riwayat.');
                $tujuan = 'admin_mata_pelajaran.php';
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
            if ($action === 'buat') {
                $tujuan = $kembaliKe(['tambah' => 1]);
            }
            if ($action === 'mapel_simpan') {
                $tujuan = 'admin_mata_pelajaran.php?action=' . ($id > 0 ? 'edit&id=' . $id : 'create');
            }
        }
        master_flash('danger', $exception->getMessage());
    } catch (RuntimeException $exception) {
        master_flash('danger', $exception->getMessage());
    }
    master_redirect($tujuan);
}

// ---------------------------------------------------------------------------
// Tab Mata pelajaran pindah ke Penilaian & rapor › Mata pelajaran (M-5,
// redesain Tahap 7). GET dialihkan permanen; POST lama di atas tetap diproses.
// ---------------------------------------------------------------------------
if ($tabMapel) {
    $lanjut = array_filter(['q' => (string) ($_GET['q'] ?? ''), 'action' => isset($_GET['edit']) ? 'edit' : null, 'id' => isset($_GET['edit']) ? (int) $_GET['edit'] : null]);
    header('Location: ' . app_url('/admin/admin_mata_pelajaran.php') . ($lanjut === [] ? '' : '?' . http_build_query($lanjut)), true, 301);
    exit;
}
$old = static fn (string $field, ?string $default = null): string => ah_old($field, $default === null ? null : [$field => $default], $bucket);
$err = static fn (string $field): string => ah_field_error($field, $bucket);
$adaOld = isset($_SESSION[$bucket]);

// ===========================================================================
// G-1 Pusat penugasan: menu jenis + daftar + panel
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
$kelolaId = (int) ($_GET['kelola'] ?? 0);
$kelola = $kelolaId > 0 ? $service->detail($jenis, $kelolaId) : null;
$tambah = isset($_GET['tambah']);
$aksiPanel = is_string($_GET['aksi'] ?? null) ? $_GET['aksi'] : '';
$statusTone = static fn (string $status): string => match ($status) {
    PenugasanService::STATUS_AKTIF => 'ok',
    PenugasanService::STATUS_AKAN_DATANG => 'info',
    PenugasanService::STATUS_BERAKHIR => 'muted',
    default => 'warn',
};
$labelTahun = static fn (array $t): string => $t['semester'] . ' ' . $t['tahun'] . ($t['status'] === 'Aktif' ? ' (aktif)' : '');
$masa = static fn (string $mulai, ?string $selesai): string => $selesai ? ah_rentang($mulai, $selesai, false) : 'Mulai ' . ah_tanggal($mulai, false);
$efektifCache = [];
$efektif = static function (int $subjekId) use (&$efektifCache, $service, $jenis): array {
    return $efektifCache[$subjekId] ??= $service->capabilityEfektif($jenis, $subjekId);
};
$mendatang = in_array($jenis, PenugasanJenis::MENDATANG, true);
$kosongFilter = array_fill_keys(array_keys($filters), '') + ['status' => 'aktif'];
$jumlahAktif = [];
foreach (PenugasanJenis::semua() as $kunci => $def) {
    $jumlahAktif[$kunci] = (int) $service->daftar($kunci, ['q' => '', 'tahun_ajaran_id' => 0, 'status' => 'aktif', 'kelas_id' => 0, 'kamar_id' => 0, 'mata_pelajaran_id' => 0, 'jenjang' => '', 'gelombang' => ''], 1)['total'];
}
$tutup = $kembaliKe(['edit' => null, 'kelola' => null, 'tambah' => null, 'aksi' => null]);

master_header('Penugasan', [
    'active' => 'penugasan',
    'description' => 'Penugasan bukan peran login: hak yang didapat dihitung server dari akun aktif, peran dasar, masa berlaku, tahun ajaran, dan cakupan.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Pengaturan'], ['label' => 'Penugasan'], ['label' => $definisi['label']]],
    'actions' => '<a class="btn btn-primary" href="' . ah_e($kembaliKe(['tambah' => 1, 'edit' => null, 'kelola' => null])) . '"><i class="fas fa-plus" aria-hidden="true"></i> Tambah penugasan</a>',
]);

// --- Panel tambah/ubah ------------------------------------------------------
if ($tambah || $edit !== null):
    $modeUbah = $edit !== null;
    $nilai = static function (string $field, mixed $default = '') use ($adaOld, $old, $edit): string {
        if ($adaOld) {
            return $old($field, (string) $default);
        }
        return (string) ($edit[$field] ?? $default);
    };
    $subjekKolom = $definisi['subjek_kolom'];
    ah_panel_open(['title' => $modeUbah ? 'Ubah penugasan ' . $definisi['label'] : 'Tambah penugasan ' . $definisi['label'], 'close_url' => $tutup,
        'description' => $modeUbah ? $edit['subjek_nama'] . ' · ' . $edit['cakupan_teks'] : PenugasanJenis::penjelasan($jenis)]);
    echo ah_error_summary($bucket);
    if (!$service->skemaTersedia($jenis)): ?>
        <p class="text-muted mb-0">Tabel penugasan <?= ah_e($definisi['label']) ?> belum dipasang. Jalankan migrasi basis data terbaru terlebih dahulu.</p>
    <?php elseif ($opsi['subjek'] === []): ?>
        <p class="text-muted mb-0">Belum ada <?= ah_e(mb_strtolower($definisi['label_subjek'])) ?> aktif. Tambahkan datanya di Data pesantren › Asatidz &amp; pengurus terlebih dahulu.</p>
    <?php elseif ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_MAPEL_KELAS && $opsi['mata_pelajaran'] === []): ?>
        <p class="text-muted mb-0">Belum ada mata pelajaran aktif. <a href="admin_mata_pelajaran.php?action=create">Tambahkan mata pelajaran</a> terlebih dahulu.</p>
    <?php else: ?>
    <form method="post" data-confirm="<?= $modeUbah ? 'Simpan perubahan penugasan? Cakupan dan masa berlaku baru berlaku pada pemeriksaan server berikutnya dan tercatat pada audit.' : 'Simpan penugasan ' . ah_e($definisi['label']) . '? Penugasan tidak membuat akun.' ?>">
        <?= master_csrf() ?>
        <input type="hidden" name="jenis" value="<?= ah_e($jenis) ?>">
        <input type="hidden" name="action" value="<?= $modeUbah ? 'ubah' : 'buat' ?>">
        <?php if ($modeUbah): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
        <div class="mb-3"><label class="form-label" for="<?= ah_e($subjekKolom) ?>"><?= ah_e($definisi['label_subjek']) ?></label>
            <?php if ($modeUbah): ?>
                <input class="form-control" id="<?= ah_e($subjekKolom) ?>" value="<?= ah_e($edit['subjek_nama'] . ($edit['subjek_keterangan'] ? ' — ' . $edit['subjek_keterangan'] : '')) ?>" disabled>
                <div class="form-text">Ganti orang berarti penugasan baru: akhiri penugasan ini lalu buat yang baru.</div>
            <?php else: ?>
                <select class="form-select" id="<?= ah_e($subjekKolom) ?>" name="<?= ah_e($subjekKolom) ?>" required>
                    <option value="">Pilih <?= ah_e(mb_strtolower($definisi['label_subjek'])) ?> aktif</option>
                    <?php foreach ($opsi['subjek'] as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $nilai($subjekKolom) === (string) $s['id'] ? 'selected' : '' ?>><?= ah_e($s['nama'] . ($s['keterangan'] ? ' — ' . $s['keterangan'] : '')) ?></option><?php endforeach; ?>
                </select><?= $err($subjekKolom) ?>
            <?php endif; ?></div>
        <div class="mb-3"><label class="form-label" for="tahun_ajaran_id">Tahun ajaran<?= $definisi['cakupan'] === PenugasanJenis::CAKUPAN_GELOMBANG ? ' penerimaan' : '' ?></label>
            <?php if ($modeUbah): ?>
                <input class="form-control" id="tahun_ajaran_id" value="<?= ah_e($edit['semester'] . ' ' . $edit['tahun']) ?>" disabled>
            <?php else: ?>
                <select class="form-select" id="tahun_ajaran_id" name="tahun_ajaran_id" required>
                    <?php foreach ($opsi['tahun'] as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $adaOld ? ($nilai('tahun_ajaran_id') === (string) $t['id'] ? 'selected' : '') : ($t['status'] === 'Aktif' ? 'selected' : '') ?>><?= ah_e($labelTahun($t)) ?></option><?php endforeach; ?>
                </select><?= $err('tahun_ajaran_id') ?>
            <?php endif; ?></div>
        <?php if ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_TARGET): ?>
            <div class="mb-3"><label class="form-label" for="target_type">Kelompok binaan</label>
                <select class="form-select" id="target_type" name="target_type">
                    <?php foreach (['Kamar' => 'Kamar/kobong', 'Kelas' => 'Kelas'] as $tt => $ttl): ?><option value="<?= $tt ?>" <?= $nilai('target_type', 'Kamar') === $tt ? 'selected' : '' ?>><?= $ttl ?></option><?php endforeach; ?>
                </select><?= $err('target_type') ?></div>
            <div class="mb-3" data-ah-when="target_type" data-ah-when-value="Kamar"><label class="form-label" for="kamar_id">Kamar binaan</label>
                <select class="form-select" id="kamar_id" name="kamar_id"><option value="">Pilih kamar</option>
                    <?php foreach ($opsi['kamar'] as $k): ?><option value="<?= (int) $k['id'] ?>" <?= $nilai('kamar_id') === (string) $k['id'] ? 'selected' : '' ?>><?= ah_e($k['nama_kamar']) ?></option><?php endforeach; ?>
                </select><?= $err('kamar_id') ?><div class="form-text">Dipakai bila kelompok binaan Kamar.</div></div>
            <div class="mb-3" data-ah-when="target_type" data-ah-when-value="Kelas"><label class="form-label" for="kelas_id">Kelas binaan</label>
                <select class="form-select" id="kelas_id" name="kelas_id"><option value="">Pilih kelas</option>
                    <?php foreach ($opsi['kelas'] as $k): ?><option value="<?= (int) $k['id'] ?>" <?= $nilai('kelas_id') === (string) $k['id'] ? 'selected' : '' ?>><?= ah_e($k['nama_kelas'] . ' (' . $k['jenjang'] . ')') ?></option><?php endforeach; ?>
                </select><?= $err('kelas_id') ?><div class="form-text">Dipakai bila kelompok binaan Kelas.</div></div>
        <?php elseif ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_MAPEL_KELAS): ?>
            <div class="mb-3"><label class="form-label" for="mata_pelajaran_id">Mata pelajaran</label>
                <select class="form-select" id="mata_pelajaran_id" name="mata_pelajaran_id" required><option value="">Pilih mata pelajaran</option>
                    <?php foreach ($opsi['mata_pelajaran'] as $m): ?><option value="<?= (int) $m['id'] ?>" <?= $nilai('mata_pelajaran_id') === (string) $m['id'] ? 'selected' : '' ?>><?= ah_e(($m['kode'] ? $m['kode'] . ' — ' : '') . $m['nama']) ?></option><?php endforeach; ?>
                </select><?= $err('mata_pelajaran_id') ?></div>
            <div class="mb-3"><label class="form-label" for="kelas_id">Kelas</label>
                <select class="form-select" id="kelas_id" name="kelas_id" required><option value="">Pilih kelas</option>
                    <?php foreach ($opsi['kelas'] as $k): ?><option value="<?= (int) $k['id'] ?>" <?= $nilai('kelas_id') === (string) $k['id'] ? 'selected' : '' ?>><?= ah_e($k['nama_kelas'] . ' (' . $k['jenjang'] . ')') ?></option><?php endforeach; ?>
                </select><?= $err('kelas_id') ?></div>
        <?php elseif ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_JENJANG): ?>
            <div class="mb-3"><label class="form-label" for="jenjang">Unit/jenjang <span class="text-muted fw-normal">(opsional)</span></label>
                <select class="form-select" id="jenjang" name="jenjang"><option value="">Seluruh unit</option>
                    <?php foreach ($opsi['jenjang'] as $j): ?><option value="<?= ah_e($j) ?>" <?= $nilai('jenjang') === $j ? 'selected' : '' ?>><?= ah_e($j) ?></option><?php endforeach; ?>
                </select><?= $err('jenjang') ?></div>
        <?php elseif ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_GELOMBANG): ?>
            <div class="mb-3"><label class="form-label" for="gelombang">Gelombang <span class="text-muted fw-normal">(opsional)</span></label>
                <input class="form-control" id="gelombang" name="gelombang" maxlength="50" placeholder="Kosong = seluruh gelombang" value="<?= ah_e($nilai('gelombang')) ?>"><?= $err('gelombang') ?></div>
        <?php else: ?>
            <p class="form-text mb-3">Cakupan: seluruh santri aktif. Penugasan berlaku sampai tanggal selesai atau dinonaktifkan, tidak gugur saat semester berganti.</p>
        <?php endif; ?>
        <div class="row g-2 mb-3">
            <div class="col-sm-6"><label class="form-label" for="tanggal_mulai">Tanggal mulai</label>
                <input class="form-control" type="date" id="tanggal_mulai" name="tanggal_mulai" required value="<?= ah_e($nilai('tanggal_mulai', $modeUbah ? '' : date('Y-m-d'))) ?>"><?= $err('tanggal_mulai') ?></div>
            <div class="col-sm-6"><label class="form-label" for="tanggal_selesai">Tanggal selesai <span class="text-muted fw-normal">(opsional)</span></label>
                <input class="form-control" type="date" id="tanggal_selesai" name="tanggal_selesai" value="<?= ah_e($nilai('tanggal_selesai')) ?>"><?= $err('tanggal_selesai') ?></div>
        </div>
        <div class="mb-3"><label class="form-label" for="catatan">Catatan <span class="text-muted fw-normal">(opsional)</span></label>
            <input class="form-control" id="catatan" name="catatan" maxlength="500" value="<?= ah_e($nilai('catatan')) ?>"><?= $err('catatan') ?></div>
        <?php if ($modeUbah): ?>
            <div class="mb-3"><label class="form-label" for="alasan">Alasan perubahan <span class="text-danger" aria-hidden="true">*</span></label>
                <input class="form-control" id="alasan" name="alasan" minlength="5" maxlength="500" required value="<?= ah_e($adaOld ? $old('alasan') : '') ?>"><?= $err('alasan') ?></div>
        <?php endif; ?>
        <div class="ah-actbar mb-0"><a class="btn btn-outline-secondary" href="<?= ah_e($tutup) ?>">Batal</a><button class="btn btn-primary" type="submit"><?= $modeUbah ? 'Simpan perubahan' : 'Simpan penugasan' ?></button></div>
    </form>
    <?php endif;
    ah_panel_close();
endif;

// --- Panel kelola: akhiri, nonaktifkan/aktifkan, arsip/pulihkan ---------------
if ($kelola !== null):
    $rid = (int) $kelola['id'];
    $status = (string) $kelola['status_label'];
    $hidup = (int) $kelola['is_active'] === 1 && empty($kelola['archived_at']);
    $ef = $service->capabilityEfektif($jenis, (int) $kelola['subjek_id']);
    ah_panel_open(['title' => $kelola['subjek_nama'] . ' · ' . $definisi['label'], 'close_url' => $tutup, 'description' => $kelola['cakupan_teks']]); ?>
    <dl class="ah-kv mb-3">
        <div><dt>Semester</dt><dd><?= master_e($kelola['semester'] . ' ' . $kelola['tahun']) ?></dd></div>
        <div><dt>Masa berlaku</dt><dd><?= master_e($masa((string) $kelola['tanggal_mulai'], $kelola['tanggal_selesai'] ?: null)) ?></dd></div>
        <div><dt>Status</dt><dd><?= ah_badge($status, $statusTone($status)) ?><?= !empty($kelola['archived_at']) ? ' ' . ah_badge('Arsip', 'muted') : '' ?></dd></div>
        <div><dt>Akun</dt><dd><?= $ef['akun'] === null ? 'Belum punya akun' : '@' . master_e($ef['akun']['username']) ?></dd></div>
    </dl>
    <?php if ($ef['pesan'] !== null): ?><p class="small text-muted"><?= master_e($ef['pesan']) ?></p><?php endif; ?>
    <?php if (!empty($kelola['diakhiri_pada'])): ?><p class="small">Diakhiri<?= $kelola['diakhiri_oleh_nama'] ? ' oleh ' . master_e($kelola['diakhiri_oleh_nama']) : '' ?>: <?= master_e($kelola['alasan_pengakhiran'] ?? '') ?></p><?php endif; ?>
    <div class="d-flex flex-wrap gap-2">
        <?php if (empty($kelola['archived_at'])): ?>
            <a class="btn btn-outline-primary" href="<?= ah_e($kembaliKe(['edit' => $rid, 'kelola' => null])) ?>"><i class="fas fa-pen" aria-hidden="true"></i> Ubah</a>
        <?php endif; ?>
        <?php if ($hidup && $status !== PenugasanService::STATUS_BERAKHIR): ?>
            <?php ah_action_dialog_open('aksi-akhiri', 'Akhiri penugasan', 'Akhiri penugasan ini?', 'outline-secondary', $aksiPanel === 'akhiri'); ?>
                <form method="post"><?= master_csrf() ?><input type="hidden" name="jenis" value="<?= ah_e($jenis) ?>"><input type="hidden" name="action" value="akhiri"><input type="hidden" name="id" value="<?= $rid ?>">
                    <p class="small text-muted">Riwayat tetap tersimpan; hak berakhir setelah tanggal selesai. Akun dan peran dasar tidak berubah.</p>
                    <label class="form-label" for="akhiri_tgl">Tanggal selesai</label>
                    <input class="form-control mb-2" type="date" id="akhiri_tgl" name="tanggal_selesai" required value="<?= ah_e(max(date('Y-m-d'), (string) $kelola['tanggal_mulai'])) ?>">
                    <label class="form-label" for="akhiri_alasan">Alasan <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control mb-2" id="akhiri_alasan" name="alasan" minlength="5" maxlength="500" required>
                    <div class="ah-dialog__actions"><button class="btn btn-secondary" type="submit">Akhiri penugasan</button></div>
                </form>
            <?php ah_action_dialog_close(); ?>
        <?php endif; ?>
        <?php if (empty($kelola['archived_at'])): ?>
            <?php ah_action_dialog_open('aksi-status', $hidup ? 'Nonaktifkan' : 'Aktifkan', $hidup ? 'Nonaktifkan penugasan ini?' : 'Aktifkan kembali penugasan ini?', $hidup ? 'outline-danger' : 'outline-success', $aksiPanel === 'status'); ?>
                <form method="post"><?= master_csrf() ?><input type="hidden" name="jenis" value="<?= ah_e($jenis) ?>"><input type="hidden" name="action" value="<?= $hidup ? 'nonaktifkan' : 'aktifkan' ?>"><input type="hidden" name="id" value="<?= $rid ?>">
                    <p class="small text-muted"><?= $hidup ? 'Hak dicabut pada pemeriksaan server berikutnya; akun dan peran dasar tidak berubah.' : 'Pemeriksaan tumpang tindih dijalankan ulang.' ?></p>
                    <label class="form-label" for="status_alasan">Alasan <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control mb-2" id="status_alasan" name="alasan" minlength="5" maxlength="500" required>
                    <div class="ah-dialog__actions"><button class="btn <?= $hidup ? 'btn-danger' : 'btn-success' ?>" type="submit"><?= $hidup ? 'Nonaktifkan' : 'Aktifkan' ?></button></div>
                </form>
            <?php ah_action_dialog_close(); ?>
        <?php endif; ?>
        <?php if ($definisi['lama']): $arsip = !empty($kelola['archived_at']); ?>
            <?php ah_action_dialog_open('aksi-arsip', $arsip ? 'Pulihkan dari arsip' : 'Arsipkan', $arsip ? 'Pulihkan penugasan ini?' : 'Arsipkan penugasan ini?', $arsip ? 'outline-primary' : 'outline-danger', $aksiPanel === 'arsip'); ?>
                <form method="post"><?= master_csrf() ?><input type="hidden" name="jenis" value="<?= ah_e($jenis) ?>"><input type="hidden" name="action" value="<?= $arsip ? 'pulihkan' : 'arsipkan' ?>"><input type="hidden" name="id" value="<?= $rid ?>">
                    <label class="form-label" for="arsip_alasan">Alasan <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control mb-2" id="arsip_alasan" name="alasan" minlength="5" maxlength="500" required>
                    <div class="ah-dialog__actions"><button class="btn <?= $arsip ? 'btn-primary' : 'btn-danger' ?>" type="submit"><?= $arsip ? 'Pulihkan' : 'Arsipkan' ?></button></div>
                </form>
            <?php ah_action_dialog_close(); ?>
        <?php endif; ?>
    </div>
    <?php ah_panel_close();
endif;
?>
<div class="ah-jenis">
    <nav aria-label="Jenis penugasan">
        <form method="get" class="ah-jenis__select ah-card ah-card__body mb-0">
            <label class="form-label" for="pilih-jenis">Jenis penugasan</label>
            <div class="d-flex gap-2"><select class="form-select" id="pilih-jenis" name="jenis" data-ah-autosubmit>
                <?php foreach (PenugasanJenis::semua() as $kunci => $def): ?>
                    <option value="<?= ah_e($kunci) ?>" <?= $kunci === $jenis ? 'selected' : '' ?>><?= ah_e($def['label'] . ' (' . $jumlahAktif[$kunci] . ')' . (in_array($kunci, PenugasanJenis::MENDATANG, true) ? ' — modul mendatang' : '')) ?></option>
                <?php endforeach; ?>
            </select><button class="btn btn-outline-secondary" type="submit">Buka</button></div>
        </form>
        <ul class="ah-jenis__menu">
            <?php foreach (PenugasanJenis::semua() as $kunci => $def):
                if ($kunci === PenugasanJenis::MENDATANG[0]): ?><li class="ah-jenis__group" aria-hidden="true">Disiapkan untuk modul mendatang</li><?php endif; ?>
                <li><a href="admin_penugasan.php?jenis=<?= ah_e($kunci) ?>"<?= $kunci === $jenis ? ' aria-current="page"' : '' ?><?= in_array($kunci, PenugasanJenis::MENDATANG, true) ? ' class="is-future"' : '' ?>>
                    <span><?= ah_e($def['label']) ?><?= in_array($kunci, PenugasanJenis::MENDATANG, true) ? '<span class="ah-visually-hidden"> (modul mendatang)</span>' : '' ?></span>
                    <span class="ah-chips__count" aria-label="<?= $jumlahAktif[$kunci] ?> aktif"><?= $jumlahAktif[$kunci] ?></span></a></li>
            <?php endforeach; ?>
        </ul>
    </nav>
    <div>
        <?php ah_note($mendatang ? 'warning' : 'info', PenugasanJenis::penjelasan($jenis)); ?>
        <?php
        $saringan = (int) ($filters['q'] !== '') + (int) ($filters['tahun_ajaran_id'] > 0) + (int) ($filters['status'] !== '') + (int) ($filters['kamar_id'] > 0) + (int) ($filters['kelas_id'] > 0) + (int) ($filters['mata_pelajaran_id'] > 0) + (int) ($filters['jenjang'] !== '') + (int) ($filters['gelombang'] !== '');
        ah_filter_open($saringan, ['jenis' => $jenis]); ?>
            <div class="ah-filter__wide"><label class="form-label" for="q">Cari <?= ah_e(mb_strtolower($definisi['label_subjek'])) ?> atau cakupan</label>
                <input class="form-control" type="search" id="q" name="q" maxlength="100" value="<?= ah_e($filters['q']) ?>"></div>
            <div><label class="form-label" for="f_tahun">Tahun ajaran</label>
                <select class="form-select" id="f_tahun" name="tahun_ajaran_id"><option value="">Semua</option>
                    <?php foreach ($opsi['tahun'] as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $filters['tahun_ajaran_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= ah_e($labelTahun($t)) ?></option><?php endforeach; ?>
                </select></div>
            <div><label class="form-label" for="f_status">Status</label>
                <select class="form-select" id="f_status" name="status">
                    <?php foreach (['' => 'Semua', 'aktif' => 'Aktif', 'akan_datang' => 'Akan datang', 'berakhir' => 'Selesai', 'nonaktif' => 'Dinonaktifkan'] as $v => $l): ?><option value="<?= ah_e($v) ?>" <?= $filters['status'] === $v ? 'selected' : '' ?>><?= ah_e($l) ?></option><?php endforeach; ?>
                </select></div>
            <?php if ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_TARGET || $definisi['cakupan'] === PenugasanJenis::CAKUPAN_MAPEL_KELAS || $definisi['cakupan'] === PenugasanJenis::CAKUPAN_JENJANG || $definisi['cakupan'] === PenugasanJenis::CAKUPAN_GELOMBANG): ?>
            <details class="ah-filter__more"<?= $filters['kamar_id'] > 0 || $filters['kelas_id'] > 0 || $filters['mata_pelajaran_id'] > 0 || $filters['jenjang'] !== '' || $filters['gelombang'] !== '' ? ' open' : '' ?>><summary>Filter lanjutan</summary><div class="ah-filter__more-body">
                <?php if ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_TARGET): ?>
                    <div><label class="form-label" for="f_kamar">Kamar</label><select class="form-select" id="f_kamar" name="kamar_id"><option value="">Semua</option>
                        <?php foreach ($opsi['kamar'] as $k): ?><option value="<?= (int) $k['id'] ?>" <?= $filters['kamar_id'] === (int) $k['id'] ? 'selected' : '' ?>><?= ah_e($k['nama_kamar']) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
                <?php if (in_array($definisi['cakupan'], [PenugasanJenis::CAKUPAN_TARGET, PenugasanJenis::CAKUPAN_MAPEL_KELAS], true)): ?>
                    <div><label class="form-label" for="f_kelas">Kelas</label><select class="form-select" id="f_kelas" name="kelas_id"><option value="">Semua</option>
                        <?php foreach ($opsi['kelas'] as $k): ?><option value="<?= (int) $k['id'] ?>" <?= $filters['kelas_id'] === (int) $k['id'] ? 'selected' : '' ?>><?= ah_e($k['nama_kelas']) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
                <?php if ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_MAPEL_KELAS): ?>
                    <div><label class="form-label" for="f_mapel">Mata pelajaran</label><select class="form-select" id="f_mapel" name="mata_pelajaran_id"><option value="">Semua</option>
                        <?php foreach ($opsi['mata_pelajaran'] as $m): ?><option value="<?= (int) $m['id'] ?>" <?= $filters['mata_pelajaran_id'] === (int) $m['id'] ? 'selected' : '' ?>><?= ah_e($m['nama']) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
                <?php if ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_JENJANG): ?>
                    <div><label class="form-label" for="f_jenjang">Unit/jenjang</label><select class="form-select" id="f_jenjang" name="jenjang"><option value="">Semua</option>
                        <?php foreach ($opsi['jenjang'] as $j): ?><option value="<?= ah_e($j) ?>" <?= $filters['jenjang'] === $j ? 'selected' : '' ?>><?= ah_e($j) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
                <?php if ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_GELOMBANG): ?>
                    <div><label class="form-label" for="f_gelombang">Gelombang</label><input class="form-control" id="f_gelombang" name="gelombang" maxlength="50" value="<?= ah_e($filters['gelombang']) ?>"></div>
                <?php endif; ?>
            </div></details>
            <?php endif; ?>
        <?php ah_filter_close(['jenis' => $jenis]); ?>

        <div class="ah-card ah-table-wrap">
            <table class="ah-table ah-table--cards" id="daftar-penugasan">
                <caption class="ah-visually-hidden">Penugasan <?= ah_e($definisi['label']) ?>, <?= (int) $daftar['total'] ?> data</caption>
                <thead><tr><th scope="col"><?= ah_e($definisi['label_subjek']) ?></th><th scope="col">Cakupan</th><th scope="col">Masa berlaku</th><th scope="col" class="ah-col-opt">Hak yang didapat</th><th scope="col">Status</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
                <tbody>
                <?php foreach ($daftar['rows'] as $r):
                    $rid = (int) $r['id'];
                    $status = (string) $r['status_label'];
                    $hidup = (int) $r['is_active'] === 1 && empty($r['archived_at']); ?>
                    <tr<?= !empty($r['archived_at']) ? ' class="ah-row-muted"' : '' ?>>
                        <td><span class="ah-cell-wrap"><a class="ah-row-link" href="<?= ah_e($kembaliKe(['kelola' => $rid, 'edit' => null, 'tambah' => null])) ?>"><?= master_e($r['subjek_nama']) ?></a>
                            <?php if ((int) $r['subjek_aktif'] !== 1 || !empty($r['subjek_arsip'])): ?><span class="ah-cell-sub"><?= ah_badge('Data orang nonaktif', 'danger') ?></span><?php endif; ?></span></td>
                        <td><span class="ah-cell-wrap"><?= master_e($r['cakupan_teks']) ?><span class="ah-cell-sub"><?= master_e($r['semester'] . ' ' . $r['tahun']) ?></span></span></td>
                        <td><?= master_e($masa((string) $r['tanggal_mulai'], $r['tanggal_selesai'] ?: null)) ?></td>
                        <td class="ah-col-opt"><span class="ah-hak"><?php
                            $ef = $efektif((int) $r['subjek_id']);
                            if ($mendatang || $status !== PenugasanService::STATUS_AKTIF) {
                                echo ah_badge($mendatang ? 'Belum ada — menunggu modul baru' : 'Tidak berlaku saat ini', 'muted');
                            } elseif ($ef['akun'] === null) {
                                echo ah_badge('Belum punya akun', 'warn');
                            } elseif ($ef['capabilities'] === []) {
                                echo ah_badge('Belum berlaku', 'muted');
                            } else {
                                foreach (PenugasanJenis::hakLabel($jenis) as $hak) { echo ah_badge($hak, 'ok'); }
                            } ?></span></td>
                        <td><?= ah_badge($status === PenugasanService::STATUS_BERAKHIR ? 'Selesai' : $status, $statusTone($status)) ?><?= !empty($r['archived_at']) ? ' ' . ah_badge('Arsip', 'muted') : '' ?></td>
                        <td data-label=""><?= ah_menu(array_values(array_filter([
                            ['label' => 'Lihat', 'url' => $kembaliKe(['kelola' => $rid, 'edit' => null, 'tambah' => null]), 'icon' => 'fa-eye'],
                            empty($r['archived_at']) ? ['label' => 'Ubah', 'url' => $kembaliKe(['edit' => $rid, 'kelola' => null, 'tambah' => null]), 'icon' => 'fa-pen'] : null,
                            $hidup && $status !== PenugasanService::STATUS_BERAKHIR ? ['label' => 'Akhiri penugasan…', 'url' => $kembaliKe(['kelola' => $rid, 'aksi' => 'akhiri', 'edit' => null, 'tambah' => null]) . '#aksi-akhiri', 'icon' => 'fa-flag-checkered'] : null,
                            empty($r['archived_at']) ? ['label' => $hidup ? 'Nonaktifkan…' : 'Aktifkan…', 'url' => $kembaliKe(['kelola' => $rid, 'aksi' => 'status', 'edit' => null, 'tambah' => null]) . '#aksi-status', 'icon' => $hidup ? 'fa-ban' : 'fa-check', 'danger' => $hidup] : null,
                        ])), 'Tindakan untuk penugasan ' . $r['subjek_nama']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($daftar['rows'] === []): ?>
                    <tr><td colspan="6" data-label=""><?= $saringan > 0
                        ? ah_empty('Tidak ada penugasan yang cocok', 'Ubah kata kunci, tahun ajaran, status, atau cakupan.')
                        : ah_empty('Belum ada penugasan ' . $definisi['label'], 'Tambahkan penugasan lewat tombol di kepala halaman.') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php master_pagination((int) $daftar['total'], (int) $daftar['page'], 20); ?>
    </div>
</div>
<?php
ah_old_clear($bucket);
master_footer();
