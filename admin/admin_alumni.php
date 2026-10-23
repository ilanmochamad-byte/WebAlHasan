<?php

declare(strict_types=1);

use App\MasterData\AlumniConflictException;
use App\MasterData\AlumniService;
use App\MasterData\MasterDataException;

/**
 * Arsip Alumni & Mutasi
 * (paket "Koreksi Pengelolaan Alumni", keputusan pengguna 6 September 2026).
 *
 * Halaman ini menggantikan versi lama yang:
 *
 *   - MENGHAPUS catatan alumni SECARA PERMANEN lewat GET (`?hapus=ID`),
 *     sekaligus menghapus berkas fotonya dari disk — tanpa CSRF, tanpa alasan,
 *     tanpa audit, dan tanpa kemungkinan pemulihan;
 *   - menyusun seluruh klausa WHERE dengan interpolasi `$_GET` langsung ke SQL;
 *   - menampilkan nama, NIS, alamat, dan nomor HP tanpa escaping;
 *   - menggambar kerangka halamannya sendiri sehingga terasa seperti aplikasi
 *     yang berbeda dari halaman admin lain.
 *
 * Versi ini memakai kerangka bersama `App\Ui\Layout` (lewat `_master_ui.php`),
 * `App\MasterData\AlumniService` untuk seluruh pembacaan dan perubahan, dan
 * mengganti penghapusan permanen dengan arsip/pemulihan beralasan.
 *
 * Keamanan:
 *   - hanya admin (`_guard.php` → `requireWebRole('admin')`);
 *   - seluruh perubahan WAJIB POST; `?action=` yang bersifat mengubah data
 *     tidak ada lagi, dan alamat lama `?hapus=ID` ditolak 405 secara eksplisit;
 *   - CSRF diperiksa `_guard.php` untuk setiap POST;
 *   - seluruh nilai filter dikirim sebagai parameter terikat, bukan disambung;
 *   - seluruh keluaran di-escape dengan `master_e()`.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = alumni_service();
$halaman = 'admin_alumni.php';

/**
 * Alamat lama `admin_alumni.php?hapus=ID` DIHENTIKAN, bukan dialihkan diam-diam.
 *
 * Mengalihkannya berarti menjalankan kembali penghapusan permanen yang sudah
 * dinyatakan tidak aman; menjawab 405 membuat perilakunya jelas bagi siapa pun
 * yang masih memakai bookmark atau tautan lama.
 */
if (isset($_GET['hapus'])) {
    http_response_code(405);
    header('Allow: GET, POST');
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        "Penghapusan permanen catatan alumni sudah dihentikan.\n"
        . "Tidak ada data yang diubah oleh permintaan ini.\n"
        . 'Gunakan tindakan Arsipkan pada halaman Data Alumni: catatan tetap tersimpan, beralasan, tercatat pada audit, dan dapat dipulihkan.'
    );
}

$galat = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $alumniId = max(0, (int) ($_POST['id'] ?? 0));
    $alasan = is_scalar($_POST['alasan'] ?? null) ? (string) $_POST['alasan'] : '';

    $namaAlumni = static fn (): string => 'Catatan alumni ' . (string) ($service->find($alumniId)['nama_santri'] ?? '');
    try {
        switch ($aksi) {
            case 'koreksi':
                if (!ah_form_token_consume('alumni_koreksi', $_POST['form_token'] ?? null)) {
                    master_flash('warning', 'Formulir ini sudah pernah dikirim. Tidak ada perubahan ganda yang dibuat. Muat ulang halaman bila ingin menyimpan perubahan baru.');
                    master_redirect($halaman . '?action=detail&id=' . $alumniId);
                }
                $service->koreksi($alumniId, $_POST, (int) $currentUser['id']);
                master_flash('success', $namaAlumni() . ' dikoreksi. Nilai sebelum dan sesudah tercatat pada audit.');
                master_redirect($halaman . '?action=detail&id=' . $alumniId);
                // no break — master_redirect() memanggil exit.

            case 'arsip':
                $service->arsipkan($alumniId, $alasan, (int) $currentUser['id']);
                master_flash('success', $namaAlumni() . ' diarsipkan. Datanya TIDAK dihapus, foto tidak disentuh, dan catatan ini dapat dipulihkan kembali.');
                master_redirect($halaman . '?action=detail&id=' . $alumniId);

            case 'pulihkan':
                $service->pulihkan($alumniId, $alasan, (int) $currentUser['id']);
                master_flash('success', $namaAlumni() . ' dipulihkan. Status santri, kelas, dan kamar TIDAK ikut diubah.');
                master_redirect($halaman . '?action=detail&id=' . $alumniId);

            case 'batalkan':
                $hasil = $service->batalkan($alumniId, $alasan, (int) $currentUser['id']);
                master_flash(
                    'success',
                    'Kelulusan/mutasi dibatalkan. Catatan alumni diarsipkan dan santri '
                    . $hasil['nama_santri'] . ' diaktifkan kembali.',
                    '<p class="small mb-0 mt-2">Penempatan kelas dan kamar <strong>tidak</strong> dibuat otomatis. '
                    . 'Tentukan penempatannya lewat halaman '
                    . '<a href="' . master_e(app_url('/admin/admin_penempatan_santri.php')) . '">Penempatan Kelas &amp; Kamar</a>.</p>'
                );
                master_redirect($halaman . '?action=detail&id=' . $alumniId);

            case 'hubungkan':
                $service->hubungkanSantri($alumniId, (int) ($_POST['santri_id'] ?? 0), (int) $currentUser['id']);
                master_flash('success', $namaAlumni() . ' dihubungkan ke santri sumbernya.');
                master_redirect($halaman . '?action=detail&id=' . $alumniId);

            default:
                throw new MasterDataException('Tindakan alumni tidak dikenal.');
        }
    } catch (AlumniConflictException $exception) {
        http_response_code(409);
        $galat = $exception->getMessage();
    } catch (MasterDataException $exception) {
        http_response_code(422);
        $galat = $exception->getMessage();
    }
}

$mode = is_string($_GET['action'] ?? null) ? (string) $_GET['action'] : '';
$mode = in_array($mode, ['detail', 'koreksi'], true) ? $mode : '';
$dipilih = $mode !== '' ? $service->find(max(0, (int) ($_GET['id'] ?? 0))) : null;
if ($dipilih === null) {
    $mode = '';
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = $service->normalizeFilters($_GET);
$daftar = $service->listPage($filters, $page);
$page = (int) $daftar['page'];
$ringkasan = $service->summary();
$tahunOptions = $service->yearOptions();
$adaFilter = array_filter($filters, static fn (mixed $v): bool => $v !== '' && $v !== 'active') !== [];

/**
 * Sumber foto alumni. Berkas foto TIDAK PERNAH dihapus oleh halaman ini —
 * termasuk saat catatannya diarsipkan.
 */
$fotoUrl = static function (array $row): ?string {
    $foto = trim((string) ($row['foto'] ?? ''));
    if ($foto === '' || $foto === 'default.jpg' || str_contains($foto, '/') || str_contains($foto, '\\')) {
        return null;
    }

    return is_file(APP_ROOT . '/gambar_galeri/' . $foto) ? app_url('/gambar_galeri/' . rawurlencode($foto)) : null;
};

$lencanaStatus = static function (string $status): string {
    return match ($status) {
        'Lulus' => ah_badge('Lulus', 'ok'),
        'Pindah' => ah_badge('Pindah', 'warn'),
        'Berhenti' => ah_badge('Berhenti', 'danger'),
        default => ah_badge($status, 'muted'),
    };
};

$lencanaArsip = static function (array $row): string {
    if ($row['archived_at'] === null) {
        return ah_badge('Aktif', 'ok');
    }

    return ah_badge(($row['jenis_arsip'] ?? '') === 'pembatalan' ? 'Dibatalkan' : 'Diarsipkan', 'muted');
};

$aksiPanel = is_string($_GET['aksi'] ?? null) ? $_GET['aksi'] : '';
$tutup = $halaman . (($q = ah_query(['action' => null, 'id' => null, 'aksi' => null])) === '' ? '' : '?' . $q);
$detailUrl = static fn (int $id, string $aksi = ''): string => $halaman . '?' . ah_query(['action' => 'detail', 'id' => $id, 'aksi' => $aksi === '' ? null : $aksi]);

master_header('Kelulusan & alumni', [
    'active' => 'santri',
    'heading' => 'Data santri',
    'description' => 'Santri yang sudah lulus, pindah, atau berhenti. Catatan alumni tidak pernah dihapus permanen — hanya diarsipkan beralasan dan dapat dipulihkan.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Data santri', 'url' => app_url('/admin/admin_master_santri.php')], ['label' => 'Kelulusan & alumni']],
    'actions' => '<a class="btn btn-primary" href="admin_kelulusan_santri.php"><i class="fas fa-graduation-cap" aria-hidden="true"></i> Proses kelulusan</a>',
]);

if ($galat !== null) {
    ah_note('danger', $galat);
}
if ($ringkasan['tanpa_santri'] > 0) {
    ah_note('warning', $ringkasan['tanpa_santri'] . ' catatan alumni aktif belum terhubung ke santri sumbernya.',
        '<p class="small mb-0 mt-2">Data warisan sebelum sistem menyimpan referensi santri; tetap ditampilkan dan tidak dihapus. Hubungkan satu per satu dari panel detail. '
        . '<a href="?' . master_e(ah_query(['tautan' => 'tanpa_santri', 'page' => null])) . '">Lihat daftarnya</a>.</p>');
}

// --- Panel detail / koreksi -------------------------------------------------
if ($mode !== '' && $dipilih !== null):
    $aid = (int) $dipilih['id'];
    $aktif = $dipilih['archived_at'] === null;
    if ($mode === 'koreksi' && $aktif):
        ah_panel_open(['title' => 'Koreksi keterangan keluar', 'close_url' => $detailUrl($aid), 'description' => (string) $dipilih['nama_santri']]); ?>
        <p class="small text-muted">Yang dapat dikoreksi hanya keterangan keluar. Identitas, alamat, data orang tua saat keluar, dan foto adalah rekaman keadaan saat santri keluar.</p>
        <form method="post" data-confirm="Simpan koreksi catatan alumni ini? Nilai sebelum dan sesudah tercatat pada audit.">
            <?= master_csrf() ?>
            <input type="hidden" name="action" value="koreksi"><input type="hidden" name="id" value="<?= $aid ?>">
            <input type="hidden" name="form_token" value="<?= master_e(ah_form_token('alumni_koreksi')) ?>">
            <div class="mb-2"><label class="form-label" for="k_status">Status keluar</label>
                <select class="form-select" id="k_status" name="status_keluar" required>
                    <?php foreach (AlumniService::STATUS as $status): ?><option value="<?= master_e($status) ?>" <?= (string) $dipilih['status_keluar'] === $status ? 'selected' : '' ?>><?= master_e($status) ?></option><?php endforeach; ?>
                </select></div>
            <div class="mb-2"><label class="form-label" for="k_tgl">Tanggal keluar</label>
                <input class="form-control" type="date" id="k_tgl" name="tgl_keluar" required value="<?= master_e($dipilih['tgl_keluar']) ?>"></div>
            <div class="mb-2"><label class="form-label" for="k_tahun">Tahun angkatan</label>
                <input class="form-control" id="k_tahun" name="tahun_angkatan" maxlength="10" required value="<?= master_e($dipilih['tahun_angkatan']) ?>"></div>
            <div class="mb-2"><label class="form-label" for="k_tingkat">Tingkat terakhir</label>
                <select class="form-select" id="k_tingkat" name="tingkat" required>
                    <?php foreach (AlumniService::TINGKAT as $tingkat): ?><option value="<?= master_e($tingkat) ?>" <?= (string) $dipilih['tingkat'] === $tingkat ? 'selected' : '' ?>><?= master_e($tingkat) ?></option><?php endforeach; ?>
                </select></div>
            <div class="mb-2"><label class="form-label" for="k_unit">Unit / sekolah terakhir</label>
                <input class="form-control" id="k_unit" name="unit_terakhir" maxlength="50" required value="<?= master_e($dipilih['unit_terakhir']) ?>"></div>
            <div class="mb-2"><label class="form-label" for="k_kelas">Kelas terakhir</label>
                <input class="form-control" id="k_kelas" name="kelas_terakhir" maxlength="50" value="<?= master_e($dipilih['kelas_terakhir'] ?? '') ?>"></div>
            <div class="mb-2"><label class="form-label" for="k_kamar">Kamar terakhir</label>
                <input class="form-control" id="k_kamar" name="kamar_terakhir" maxlength="50" value="<?= master_e($dipilih['kamar_terakhir'] ?? '') ?>"></div>
            <div class="mb-3"><label class="form-label" for="k_catatan">Catatan / alasan koreksi</label>
                <textarea class="form-control" id="k_catatan" name="catatan" rows="2" maxlength="500"><?= master_e($dipilih['catatan'] ?? '') ?></textarea></div>
            <div class="ah-actbar mb-0"><a class="btn btn-outline-secondary" href="<?= master_e($detailUrl($aid)) ?>">Batal</a><button class="btn btn-primary" type="submit">Simpan koreksi</button></div>
        </form>
        <?php ah_panel_close();
    else:
        $foto = $fotoUrl($dipilih);
        ah_panel_open(['title' => (string) $dipilih['nama_santri'], 'close_url' => $tutup, 'description' => 'NIS ' . $dipilih['nis'] . ' · ' . $dipilih['status_keluar'] . ' ' . ah_tanggal((string) $dipilih['tgl_keluar'], false)]);
        if (!$aktif) {
            ah_note('info', 'Catatan ini ' . (($dipilih['jenis_arsip'] ?? '') === 'pembatalan' ? 'dibatalkan' : 'diarsipkan') . ' sejak ' . ah_tanggal((string) $dipilih['archived_at'], false) . '.',
                '<p class="small mb-0 mt-2">Alasan: ' . master_e((string) ($dipilih['alasan_arsip'] ?? '—')) . '</p>');
        }
        if ($foto !== null): ?><img src="<?= master_e($foto) ?>" alt="Foto <?= master_e($dipilih['nama_santri']) ?>" class="img-fluid rounded border mb-3" style="max-height:140px;object-fit:cover"><?php endif; ?>
        <div class="d-flex flex-wrap gap-1 mb-2"><?= $lencanaStatus((string) $dipilih['status_keluar']) ?><?= $lencanaArsip($dipilih) ?></div>
        <h3 class="h6">Penempatan terakhir</h3>
        <dl class="ah-kv mb-3">
            <div><dt>Unit / sekolah</dt><dd><?= master_e($dipilih['unit_terakhir'] ?: '—') ?></dd></div>
            <div><dt>Kelas</dt><dd><?= master_e($dipilih['kelas_terakhir'] ?: '—') ?></dd></div>
            <div><dt>Kamar</dt><dd><?= master_e($dipilih['kamar_terakhir'] ?: '—') ?></dd></div>
            <div><dt>Tingkat · angkatan</dt><dd><?= master_e($dipilih['tingkat'] . ' · ' . $dipilih['tahun_angkatan']) ?></dd></div>
        </dl>
        <h3 class="h6">Orang tua saat keluar</h3>
        <dl class="ah-kv mb-3">
            <div><dt>Ayah</dt><dd><?= master_e(trim(($dipilih['nama_ayah'] ?: '—') . ($dipilih['no_hp_ayah'] ? ' · ' . $dipilih['no_hp_ayah'] : ''))) ?></dd></div>
            <div><dt>Ibu</dt><dd><?= master_e(trim(($dipilih['nama_ibu'] ?: '—') . ($dipilih['no_hp_ibu'] ? ' · ' . $dipilih['no_hp_ibu'] : ''))) ?></dd></div>
        </dl>
        <h3 class="h6">Jejak proses</h3>
        <dl class="ah-kv mb-3">
            <div><dt>Santri sumber</dt><dd><?= $dipilih['santri_id'] === null ? ah_badge('Belum terhubung', 'warn')
                : '<a href="admin_master_santri.php?action=detail&amp;id=' . (int) $dipilih['santri_id'] . '">Buka profil santri</a> ' . ((int) $dipilih['santri_aktif'] === 1 && $dipilih['santri_archived_at'] === null ? ah_badge('Aktif', 'ok') : ah_badge('Arsip', 'muted')) ?></dd></div>
            <div><dt>Diproses</dt><dd><?= master_e(($dipilih['dibuat_oleh'] ?: 'tidak tercatat') . ' · ' . ($dipilih['created_at'] ? ah_tanggal((string) $dipilih['created_at'], false) : 'data warisan')) ?></dd></div>
            <div><dt>Diubah terakhir</dt><dd><?= master_e($dipilih['diubah_oleh'] ? $dipilih['diubah_oleh'] . ' · ' . ah_tanggal((string) $dipilih['updated_at'], false) : '—') ?></dd></div>
            <div><dt>Catatan</dt><dd><?= master_e($dipilih['catatan'] ?: '—') ?></dd></div>
        </dl>
        <h3 class="h6">Tindakan</h3>
        <div class="d-flex flex-wrap gap-2">
        <?php if ($aktif): ?>
            <a class="btn btn-outline-primary" href="<?= master_e($halaman . '?' . ah_query(['action' => 'koreksi', 'id' => $aid, 'aksi' => null])) ?>"><i class="fas fa-pen" aria-hidden="true"></i> Koreksi keterangan</a>
            <?php if ($dipilih['santri_id'] === null): ?>
                <?php ah_action_dialog_open('aksi-hubungkan', 'Hubungkan ke santri', 'Hubungkan ke santri sumber', 'outline-primary', $aksiPanel === 'hubungkan'); ?>
                    <form method="post" data-confirm="Hubungkan catatan alumni ini ke santri tersebut? Hanya bila Anda yakin orangnya sama; dicatat pada audit.">
                        <?= master_csrf() ?><input type="hidden" name="action" value="hubungkan"><input type="hidden" name="id" value="<?= $aid ?>">
                        <p class="small text-muted">Sistem tidak menebak dari kesamaan nama. Pilih santri yang benar.</p>
                        <label class="form-label" for="santri_hubung">Santri</label>
                        <input class="form-control mb-2" type="search" hidden data-ah-option-filter="santri_hubung" placeholder="Ketik nama atau NIS untuk menyaring" aria-label="Saring daftar santri" autocomplete="off">
                        <select class="form-select mb-2" id="santri_hubung" name="santri_id" required><option value="">Pilih santri</option>
                            <?php foreach (master_data_service()->santriListAll() as $s): ?><option value="<?= (int) $s['id'] ?>" <?= strcasecmp((string) $s['nis'], (string) $dipilih['nis']) === 0 ? 'selected' : '' ?>><?= master_e($s['nama_santri'] . ' — NIS ' . $s['nis']) ?></option><?php endforeach; ?>
                        </select>
                        <div class="ah-dialog__actions"><button class="btn btn-primary" type="submit">Hubungkan</button></div>
                    </form>
                <?php ah_action_dialog_close(); ?>
            <?php endif; ?>
            <?php ah_action_dialog_open('aksi-arsip', 'Arsipkan', 'Arsipkan catatan alumni ini?', 'outline-danger', $aksiPanel === 'arsip'); ?>
                <form method="post"><?= master_csrf() ?><input type="hidden" name="action" value="arsip"><input type="hidden" name="id" value="<?= $aid ?>">
                    <p class="small text-muted">Pengganti penghapusan permanen: catatan tidak dihapus, foto tidak disentuh, dan dapat dipulihkan. Status santri sumber tidak ikut berubah.</p>
                    <label class="form-label" for="alasan_arsip">Alasan <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control mb-2" id="alasan_arsip" name="alasan" maxlength="500" required minlength="5">
                    <div class="ah-dialog__actions"><button class="btn btn-danger" type="submit">Arsipkan</button></div>
                </form>
            <?php ah_action_dialog_close(); ?>
            <?php if ($dipilih['santri_id'] !== null): ?>
                <?php ah_action_dialog_open('aksi-batal', 'Batalkan kelulusan', 'Batalkan kelulusan/mutasi ini?', 'outline-danger', $aksiPanel === 'batal'); ?>
                    <form method="post"><?= master_csrf() ?><input type="hidden" name="action" value="batalkan"><input type="hidden" name="id" value="<?= $aid ?>">
                        <p class="small text-muted">Catatan alumni diarsipkan dan santri diaktifkan kembali. Penempatan kelas dan kamar TIDAK dibuat otomatis.</p>
                        <label class="form-label" for="alasan_batal">Alasan <span class="text-danger" aria-hidden="true">*</span></label>
                        <input class="form-control mb-2" id="alasan_batal" name="alasan" maxlength="500" required minlength="5">
                        <div class="ah-dialog__actions"><button class="btn btn-danger" type="submit">Batalkan kelulusan</button></div>
                    </form>
                <?php ah_action_dialog_close(); ?>
            <?php endif; ?>
        <?php else: ?>
            <?php ah_action_dialog_open('aksi-pulihkan', 'Pulihkan', 'Pulihkan catatan alumni ini?', 'outline-primary', $aksiPanel === 'pulihkan'); ?>
                <form method="post"><?= master_csrf() ?><input type="hidden" name="action" value="pulihkan"><input type="hidden" name="id" value="<?= $aid ?>">
                    <p class="small text-muted">Catatan kembali tampil sebagai alumni aktif. Status santri, kelas, dan kamar tidak ikut diubah.</p>
                    <label class="form-label" for="alasan_pulih">Alasan <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control mb-2" id="alasan_pulih" name="alasan" maxlength="500" required minlength="5">
                    <div class="ah-dialog__actions"><button class="btn btn-primary" type="submit">Pulihkan</button></div>
                </form>
            <?php ah_action_dialog_close(); ?>
        <?php endif; ?>
        </div>
        <?php ah_panel_close();
    endif;
endif;

// --- Daftar -----------------------------------------------------------------
$saring = (int) ($filters['q'] !== '') + (int) ($filters['status'] !== '') + (int) ($filters['tahun'] !== '') + (int) ($filters['tingkat'] !== '') + (int) ($filters['state'] !== 'active') + (int) ($filters['tautan'] !== '');
ah_filter_open($saring, [], $halaman); ?>
    <div class="ah-filter__wide"><label class="form-label" for="q">Cari nama atau NIS</label>
        <input class="form-control" type="search" id="q" name="q" maxlength="100" value="<?= master_e($filters['q']) ?>"></div>
    <div><label class="form-label" for="status">Status keluar</label>
        <select class="form-select" id="status" name="status"><option value="">Semua status</option>
            <?php foreach (AlumniService::STATUS as $status): ?><option value="<?= master_e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= master_e($status) ?></option><?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="tahun">Tahun</label>
        <select class="form-select" id="tahun" name="tahun"><option value="">Semua tahun</option>
            <?php foreach ($tahunOptions as $tahun): ?><option value="<?= master_e($tahun) ?>" <?= $filters['tahun'] === $tahun ? 'selected' : '' ?>><?= master_e($tahun) ?></option><?php endforeach; ?>
        </select></div>
    <details class="ah-filter__more"<?= $filters['tingkat'] !== '' || $filters['state'] !== 'active' || $filters['tautan'] !== '' ? ' open' : '' ?>><summary>Filter lanjutan</summary><div class="ah-filter__more-body">
        <div><label class="form-label" for="tingkat">Tingkat</label>
            <select class="form-select" id="tingkat" name="tingkat"><option value="">Semua tingkat</option>
                <?php foreach (AlumniService::TINGKAT as $tingkat): ?><option value="<?= master_e($tingkat) ?>" <?= $filters['tingkat'] === $tingkat ? 'selected' : '' ?>><?= master_e($tingkat) ?></option><?php endforeach; ?>
            </select></div>
        <div><label class="form-label" for="state">Status catatan</label>
            <select class="form-select" id="state" name="state">
                <?php foreach (['active' => 'Aktif', 'archived' => 'Diarsipkan', 'all' => 'Semua catatan'] as $value => $label): ?><option value="<?= $value ?>" <?= $filters['state'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
            </select></div>
        <div><label class="form-label" for="tautan">Referensi santri</label>
            <select class="form-select" id="tautan" name="tautan"><option value="">Semua</option>
                <option value="dengan_santri" <?= $filters['tautan'] === 'dengan_santri' ? 'selected' : '' ?>>Sudah terhubung</option>
                <option value="tanpa_santri" <?= $filters['tautan'] === 'tanpa_santri' ? 'selected' : '' ?>>Belum terhubung (data warisan)</option>
            </select></div>
    </div></details>
<?php ah_filter_close(); ?>

<div class="ah-card ah-table-wrap">
    <table class="ah-table ah-table--cards" id="daftar-alumni">
        <caption class="ah-visually-hidden">Daftar alumni, <?= (int) $daftar['total'] ?> catatan</caption>
        <thead><tr><th scope="col">NIS</th><th scope="col">Nama</th><th scope="col">Status keluar</th><th scope="col">Tanggal keluar</th><th scope="col" class="ah-col-opt">Tingkat</th><th scope="col" class="ah-col-opt">Tahun</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
        <tbody>
        <?php foreach ($daftar['rows'] as $row): $rid = (int) $row['id']; $aktif = $row['archived_at'] === null; ?>
            <tr<?= $aktif ? '' : ' class="ah-row-muted"' ?>>
                <td><?= master_e($row['nis']) ?></td>
                <td><a class="ah-row-link" href="<?= master_e($detailUrl($rid)) ?>"><?= master_e($row['nama_santri']) ?></a>
                    <?php if (!$aktif): ?> <?= $lencanaArsip($row) ?><?php endif; ?>
                    <?php if ($row['santri_id'] === null): ?><span class="ah-cell-sub">Belum terhubung ke santri</span><?php endif; ?></td>
                <td><?= $lencanaStatus((string) $row['status_keluar']) ?></td>
                <td><?= master_e(ah_tanggal((string) $row['tgl_keluar'], false)) ?></td>
                <td class="ah-col-opt"><?= master_e($row['tingkat']) ?></td>
                <td class="ah-col-opt"><?= master_e($row['tahun_angkatan']) ?></td>
                <td data-label=""><?= ah_menu(array_values(array_filter([
                    ['label' => 'Lihat detail', 'url' => $detailUrl($rid), 'icon' => 'fa-eye'],
                    $aktif ? ['label' => 'Koreksi keterangan', 'url' => $halaman . '?' . ah_query(['action' => 'koreksi', 'id' => $rid, 'aksi' => null]), 'icon' => 'fa-pen'] : null,
                    $aktif && $row['santri_id'] === null ? ['label' => 'Hubungkan ke santri', 'url' => $detailUrl($rid, 'hubungkan') . '#aksi-hubungkan', 'icon' => 'fa-link'] : null,
                    !$aktif ? ['label' => 'Pulihkan…', 'url' => $detailUrl($rid, 'pulihkan') . '#aksi-pulihkan', 'icon' => 'fa-rotate-left'] : null,
                    $aktif && $row['santri_id'] !== null ? ['label' => 'Batalkan kelulusan…', 'url' => $detailUrl($rid, 'batal') . '#aksi-batal', 'icon' => 'fa-arrow-rotate-left', 'danger' => true] : null,
                    $aktif ? ['label' => 'Arsipkan…', 'url' => $detailUrl($rid, 'arsip') . '#aksi-arsip', 'icon' => 'fa-box-archive', 'danger' => true] : null,
                ])), 'Tindakan untuk ' . $row['nama_santri']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($daftar['rows'] === []): ?>
            <tr><td colspan="7" data-label=""><?= $adaFilter
                ? ah_empty('Tidak ada alumni yang cocok', 'Ubah kata kunci atau bersihkan saringan.', '<a class="btn btn-sm btn-outline-secondary" href="' . master_e($halaman) . '">Bersihkan saringan</a>')
                : ah_empty('Belum ada catatan alumni', 'Data alumni terisi dari proses kelulusan & mutasi keluar.', '<a class="btn btn-sm btn-primary" href="admin_kelulusan_santri.php">Proses kelulusan</a>') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php master_pagination((int) $daftar['total'], $page, (int) $daftar['perPage']); ?>
<?php master_footer(); ?>
