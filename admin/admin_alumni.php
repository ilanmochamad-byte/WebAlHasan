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

    try {
        switch ($aksi) {
            case 'koreksi':
                if (!ah_form_token_consume('alumni_koreksi', $_POST['form_token'] ?? null)) {
                    master_flash('warning', 'Formulir ini sudah pernah dikirim. Tidak ada perubahan ganda yang dibuat. Muat ulang halaman bila ingin menyimpan perubahan baru.');
                    master_redirect($halaman . '?action=detail&id=' . $alumniId);
                }
                $service->koreksi($alumniId, $_POST, (int) $currentUser['id']);
                master_flash('success', 'Catatan alumni #' . $alumniId . ' berhasil dikoreksi. Nilai sebelum dan sesudah tercatat pada audit.');
                master_redirect($halaman . '?action=detail&id=' . $alumniId);
                // no break — master_redirect() memanggil exit.

            case 'arsip':
                $service->arsipkan($alumniId, $alasan, (int) $currentUser['id']);
                master_flash('success', 'Catatan alumni #' . $alumniId . ' diarsipkan. Datanya TIDAK dihapus, foto tidak disentuh, dan catatan ini dapat dipulihkan kembali.');
                master_redirect($halaman . '?action=detail&id=' . $alumniId);

            case 'pulihkan':
                $service->pulihkan($alumniId, $alasan, (int) $currentUser['id']);
                master_flash('success', 'Catatan alumni #' . $alumniId . ' dipulihkan. Status santri, kelas, dan kamar TIDAK ikut diubah.');
                master_redirect($halaman . '?action=detail&id=' . $alumniId);

            case 'batalkan':
                $hasil = $service->batalkan($alumniId, $alasan, (int) $currentUser['id']);
                master_flash(
                    'success',
                    'Kelulusan/mutasi dibatalkan. Catatan alumni #' . $alumniId . ' diarsipkan dan santri '
                    . $hasil['nama_santri'] . ' diaktifkan kembali.',
                    '<p class="small mb-0 mt-2">Penempatan kelas dan kamar <strong>tidak</strong> dibuat otomatis. '
                    . 'Tentukan penempatannya lewat halaman '
                    . '<a href="' . master_e(app_url('/admin/admin_penempatan_santri.php')) . '">Penempatan Kelas &amp; Kamar</a>.</p>'
                );
                master_redirect($halaman . '?action=detail&id=' . $alumniId);

            case 'hubungkan':
                $service->hubungkanSantri($alumniId, (int) ($_POST['santri_id'] ?? 0), (int) $currentUser['id']);
                master_flash('success', 'Catatan alumni #' . $alumniId . ' dihubungkan ke santri sumbernya.');
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

master_header('Data Alumni', [
    'description' => 'Arsip santri yang sudah lulus, pindah, atau berhenti. Catatan alumni tidak pernah dihapus permanen — '
        . 'hanya diarsipkan, beralasan, dan dapat dipulihkan.',
    'active' => 'alumni',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Data Alumni'],
    ],
    'actions' => '<a class="btn btn-primary" href="admin_kelulusan_santri.php">Kelulusan / Mutasi Keluar</a>'
        . '<a class="btn btn-outline-secondary" href="admin_master_santri.php">Data Santri</a>',
]);

if ($galat !== null) {
    ah_note('danger', $galat);
}
if ($ringkasan['tanpa_santri'] > 0) {
    ah_note(
        'warning',
        $ringkasan['tanpa_santri'] . ' catatan alumni aktif belum terhubung ke santri sumbernya.',
        '<p class="small mb-0 mt-2">Ini adalah data warisan yang dibuat sebelum sistem menyimpan referensi santri. '
        . 'Datanya <strong>tetap ditampilkan dan tidak dihapus</strong>. Sistem tidak memasangkannya secara otomatis '
        . 'kecuali NIS-nya cocok persis satu santri — jalankan <code>php bin/alumni_backfill.php</code> untuk laporan, '
        . 'atau hubungkan satu per satu dari halaman detail. '
        . '<a href="?' . master_e(ah_query(['tautan' => 'tanpa_santri', 'page' => null])) . '">Lihat daftarnya</a>.</p>'
    );
}
?>

<section class="ah-stats" aria-label="Ringkasan arsip alumni">
    <div class="ah-stat"><span class="ah-stat__label">Catatan aktif</span>
        <span class="ah-stat__value"><?= (int) $ringkasan['aktif'] ?></span>
        <span class="ah-stat__hint">Alumni yang berlaku saat ini.</span></div>
    <div class="ah-stat"><span class="ah-stat__label">Diarsipkan</span>
        <span class="ah-stat__value"><?= (int) $ringkasan['arsip'] ?></span>
        <span class="ah-stat__hint">Tetap tersimpan dan dapat dipulihkan.</span></div>
    <div class="ah-stat"><span class="ah-stat__label">Tanpa referensi santri</span>
        <span class="ah-stat__value"><?= (int) $ringkasan['tanpa_santri'] ?></span>
        <span class="ah-stat__hint">Data warisan yang perlu diperiksa admin.</span></div>
</section>

<?php if ($mode !== '' && $dipilih !== null): $foto = $fotoUrl($dipilih); ?>
<section class="ah-card" aria-labelledby="ah-detail-alumni">
    <div class="ah-card__head">
        <h2 class="h6 mb-0" id="ah-detail-alumni">Catatan alumni #<?= (int) $dipilih['id'] ?></h2>
        <span class="d-flex gap-1"><?= $lencanaArsip($dipilih) ?><?= $lencanaStatus((string) $dipilih['status_keluar']) ?></span>
    </div>
    <div class="ah-card__body">
        <?php if ($dipilih['archived_at'] !== null): ?>
            <?php ah_note(
                'info',
                'Catatan ini berstatus ' . (($dipilih['jenis_arsip'] ?? '') === 'pembatalan' ? 'DIBATALKAN' : 'ARSIP')
                . ' sejak ' . (string) $dipilih['archived_at'] . '.',
                '<p class="small mb-0 mt-2">Alasan: ' . master_e((string) ($dipilih['alasan_arsip'] ?? '-')) . '</p>'
            ); ?>
        <?php endif; ?>

        <div class="row g-3">
            <?php if ($foto !== null): ?>
                <div class="col-md-2">
                    <img src="<?= master_e($foto) ?>" alt="Foto <?= master_e($dipilih['nama_santri']) ?>"
                         class="img-fluid rounded border" style="max-height:150px;object-fit:cover">
                </div>
            <?php endif; ?>
            <div class="col">
                <div class="row g-3">
                    <div class="col-md-3"><small class="text-muted d-block">NIS</small><?= master_e($dipilih['nis']) ?></div>
                    <div class="col-md-5"><small class="text-muted d-block">Nama santri</small><span class="fw-semibold"><?= master_e($dipilih['nama_santri']) ?></span></div>
                    <div class="col-md-2"><small class="text-muted d-block">Jenis kelamin</small><?= $dipilih['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan' ?></div>
                    <div class="col-md-2"><small class="text-muted d-block">Tingkat terakhir</small><?= master_e($dipilih['tingkat']) ?></div>
                    <div class="col-md-4"><small class="text-muted d-block">Tempat, tanggal lahir</small><?= master_e($dipilih['tempat_lahir']) ?>, <?= master_e($dipilih['tgl_lahir']) ?></div>
                    <div class="col-md-4"><small class="text-muted d-block">Status keluar</small><?= master_e($dipilih['status_keluar']) ?> per <?= master_e($dipilih['tgl_keluar']) ?></div>
                    <div class="col-md-4"><small class="text-muted d-block">Tahun angkatan</small><?= master_e($dipilih['tahun_angkatan']) ?></div>
                    <div class="col-12"><small class="text-muted d-block">Alamat</small>
                        <?= master_e(implode(', ', array_filter([
                            (string) $dipilih['alamat'],
                            (string) $dipilih['desa'] === '' ? '' : 'Ds. ' . $dipilih['desa'],
                            (string) $dipilih['kecamatan'] === '' ? '' : 'Kec. ' . $dipilih['kecamatan'],
                            (string) $dipilih['kab_kota'],
                            (string) $dipilih['provinsi'],
                        ]))) ?></div>
                </div>
            </div>
        </div>

        <h3 class="h6 mt-4">Penempatan terakhir</h3>
        <div class="row g-3">
            <div class="col-md-4"><small class="text-muted d-block">Unit / sekolah terakhir</small><?= master_e($dipilih['unit_terakhir'] ?: '-') ?></div>
            <div class="col-md-4"><small class="text-muted d-block">Kelas terakhir</small><?= master_e($dipilih['kelas_terakhir'] ?: '-') ?></div>
            <div class="col-md-4"><small class="text-muted d-block">Kamar terakhir</small><?= master_e($dipilih['kamar_terakhir'] ?: '-') ?></div>
        </div>

        <h3 class="h6 mt-4">Snapshot orang tua saat diproses</h3>
        <p class="text-muted small">Nilai ini dibekukan pada saat kelulusan/mutasi. Identitas wali terkini tetap dikelola pada halaman Orang Tua / Wali dan tidak pernah dihapus oleh proses alumni.</p>
        <div class="row g-3">
            <div class="col-md-3"><small class="text-muted d-block">Nama ayah</small><?= master_e($dipilih['nama_ayah'] ?: '-') ?></div>
            <div class="col-md-3"><small class="text-muted d-block">No. HP ayah</small><?= master_e($dipilih['no_hp_ayah'] ?: '-') ?></div>
            <div class="col-md-3"><small class="text-muted d-block">Nama ibu</small><?= master_e($dipilih['nama_ibu'] ?: '-') ?></div>
            <div class="col-md-3"><small class="text-muted d-block">No. HP ibu</small><?= master_e($dipilih['no_hp_ibu'] ?: '-') ?></div>
        </div>

        <h3 class="h6 mt-4">Jejak proses</h3>
        <div class="row g-3">
            <div class="col-md-3"><small class="text-muted d-block">Santri sumber</small>
                <?= $dipilih['santri_id'] === null
                    ? ah_badge('Belum terhubung', 'warn')
                    : '<a href="admin_master_santri.php?action=detail&amp;id=' . (int) $dipilih['santri_id'] . '">Santri #' . (int) $dipilih['santri_id'] . '</a>' ?></div>
            <div class="col-md-3"><small class="text-muted d-block">Status santri sumber</small>
                <?= $dipilih['santri_id'] === null ? '-' : ((int) $dipilih['santri_aktif'] === 1 && $dipilih['santri_archived_at'] === null ? ah_badge('Aktif', 'ok') : ah_badge('Arsip / nonaktif', 'muted')) ?></div>
            <div class="col-md-3"><small class="text-muted d-block">Diproses oleh</small><?= master_e($dipilih['dibuat_oleh'] ?: 'tidak tercatat (data warisan)') ?></div>
            <div class="col-md-3"><small class="text-muted d-block">Waktu proses</small><?= master_e($dipilih['created_at'] ?: 'tidak tercatat (data warisan)') ?></div>
            <div class="col-md-3"><small class="text-muted d-block">Diubah terakhir oleh</small><?= master_e($dipilih['diubah_oleh'] ?: '-') ?></div>
            <div class="col-md-3"><small class="text-muted d-block">Waktu perubahan</small><?= master_e($dipilih['updated_at'] ?: '-') ?></div>
            <div class="col-md-6"><small class="text-muted d-block">Catatan</small><?= master_e($dipilih['catatan'] ?: '-') ?></div>
        </div>
    </div>
</section>

<?php if ($dipilih['santri_id'] === null): ?>
<section class="ah-card" aria-labelledby="ah-hubungkan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-hubungkan">Hubungkan ke santri sumber</h2></div>
    <div class="ah-card__body">
        <p class="text-muted">
            Catatan ini berasal dari data lama yang belum menyimpan referensi santri. Masukkan <strong>ID santri</strong>
            yang benar — sistem tidak menebaknya dari kesamaan nama. ID dapat dilihat pada halaman
            <a href="admin_master_santri.php">Data Santri</a>.
        </p>
        <form method="post" class="row g-2 align-items-end"
              data-confirm="Hubungkan catatan alumni ini ke santri tersebut? Pemasangan ini dicatat pada audit dan hanya boleh dilakukan bila Anda yakin orangnya sama.">
            <?= master_csrf() ?>
            <input type="hidden" name="action" value="hubungkan">
            <input type="hidden" name="id" value="<?= (int) $dipilih['id'] ?>">
            <div class="col-md-4">
                <label class="form-label" for="santri_id_hubung">ID santri</label>
                <input class="form-control" type="number" min="1" step="1" id="santri_id_hubung" name="santri_id" required>
            </div>
            <div class="col-md-4"><button class="btn btn-outline-primary" type="submit">Hubungkan</button></div>
        </form>
    </div>
</section>
<?php endif; ?>

<?php if ($mode === 'koreksi' && $dipilih['archived_at'] === null): ?>
<section class="ah-card" aria-labelledby="ah-koreksi">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-koreksi">Koreksi keterangan keluar</h2></div>
    <div class="ah-card__body">
        <p class="text-muted">
            Yang dapat dikoreksi hanya keterangan keluarnya. Identitas, NIS, alamat, snapshot orang tua, dan foto
            <strong>tidak</strong> diubah dari sini — semuanya adalah rekaman keadaan saat santri keluar.
        </p>
        <form method="post" data-confirm="Simpan koreksi catatan alumni ini? Nilai sebelum dan sesudah tercatat pada audit.">
            <?= master_csrf() ?>
            <input type="hidden" name="action" value="koreksi">
            <input type="hidden" name="id" value="<?= (int) $dipilih['id'] ?>">
            <input type="hidden" name="form_token" value="<?= master_e(ah_form_token('alumni_koreksi')) ?>">
            <fieldset class="ah-fieldset">
                <legend>Keterangan keluar</legend>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="k_status">Status keluar</label>
                        <select class="form-select" id="k_status" name="status_keluar" required>
                            <?php foreach (AlumniService::STATUS as $status): ?>
                                <option value="<?= master_e($status) ?>" <?= (string) $dipilih['status_keluar'] === $status ? 'selected' : '' ?>><?= master_e($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="k_tgl">Tanggal keluar</label>
                        <input class="form-control" type="date" id="k_tgl" name="tgl_keluar" required value="<?= master_e($dipilih['tgl_keluar']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="k_tahun">Tahun angkatan</label>
                        <input class="form-control" id="k_tahun" name="tahun_angkatan" maxlength="10" required value="<?= master_e($dipilih['tahun_angkatan']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="k_tingkat">Tingkat terakhir</label>
                        <select class="form-select" id="k_tingkat" name="tingkat" required>
                            <?php foreach (AlumniService::TINGKAT as $tingkat): ?>
                                <option value="<?= master_e($tingkat) ?>" <?= (string) $dipilih['tingkat'] === $tingkat ? 'selected' : '' ?>><?= master_e($tingkat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="k_unit">Unit / sekolah terakhir</label>
                        <input class="form-control" id="k_unit" name="unit_terakhir" maxlength="50" required value="<?= master_e($dipilih['unit_terakhir']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="k_kelas">Kelas terakhir</label>
                        <input class="form-control" id="k_kelas" name="kelas_terakhir" maxlength="50" value="<?= master_e($dipilih['kelas_terakhir'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="k_kamar">Kamar terakhir</label>
                        <input class="form-control" id="k_kamar" name="kamar_terakhir" maxlength="50" value="<?= master_e($dipilih['kamar_terakhir'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="k_catatan">Catatan / alasan koreksi</label>
                        <textarea class="form-control" id="k_catatan" name="catatan" rows="2" maxlength="500"
                                  aria-describedby="bantuan_koreksi"><?= master_e($dipilih['catatan'] ?? '') ?></textarea>
                        <div class="form-text" id="bantuan_koreksi">Catatan ikut tersimpan pada audit sebagai alasan perubahan.</div>
                    </div>
                </div>
            </fieldset>
            <div class="ah-actions">
                <button class="btn btn-primary" type="submit">Simpan koreksi</button>
                <a class="btn btn-outline-secondary" href="?action=detail&amp;id=<?= (int) $dipilih['id'] ?>">Batal</a>
            </div>
        </form>
    </div>
</section>
<?php endif; ?>

<section class="ah-card" aria-labelledby="ah-tindakan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-tindakan">Tindakan</h2></div>
    <div class="ah-card__body">
        <?php if ($dipilih['archived_at'] === null): ?>
            <div class="mb-4">
                <h3 class="h6">Koreksi data</h3>
                <p class="text-muted small mb-2">Memperbaiki status keluar, tanggal, tahun, tingkat, atau keterangan penempatan terakhir.</p>
                <a class="btn btn-outline-primary" href="?action=koreksi&amp;id=<?= (int) $dipilih['id'] ?>">Buka formulir koreksi</a>
            </div>

            <div class="mb-4">
                <h3 class="h6">Arsipkan catatan</h3>
                <p class="text-muted small mb-2">
                    Pengganti penghapusan permanen. Catatan berhenti tampil pada daftar aktif, tetapi
                    <strong>tidak dihapus</strong>, berkas fotonya tidak disentuh, dan catatan dapat dipulihkan.
                    Status santri sumber <strong>tidak</strong> ikut berubah.
                </p>
                <form method="post" class="row g-2 align-items-end"
                      data-confirm="Arsipkan catatan alumni ini? Datanya tetap tersimpan dan dapat dipulihkan. Status santri, kelas, dan kamar tidak ikut berubah.">
                    <?= master_csrf() ?>
                    <input type="hidden" name="action" value="arsip">
                    <input type="hidden" name="id" value="<?= (int) $dipilih['id'] ?>">
                    <div class="col-md-8">
                        <label class="form-label" for="alasan_arsip">Alasan mengarsipkan <span class="text-danger" aria-hidden="true">*</span></label>
                        <input class="form-control" id="alasan_arsip" name="alasan" maxlength="500" required minlength="5"
                               aria-describedby="bantuan_arsip">
                        <div class="form-text" id="bantuan_arsip">Wajib diisi dan ikut tercatat pada audit.</div>
                    </div>
                    <div class="col-md-4"><button class="btn btn-outline-danger" type="submit">Arsipkan</button></div>
                </form>
            </div>

            <?php if ($dipilih['santri_id'] !== null): ?>
            <div>
                <h3 class="h6">Batalkan kelulusan / mutasi</h3>
                <p class="text-muted small mb-2">
                    Mengarsipkan catatan alumni <strong>dan</strong> mengaktifkan kembali santri sumber pada master data.
                    Penempatan kelas dan kamar <strong>tidak</strong> dibuat otomatis — tentukan sendiri lewat halaman
                    Penempatan Kelas &amp; Kamar setelahnya.
                </p>
                <form method="post" class="row g-2 align-items-end"
                      data-confirm="Batalkan kelulusan/mutasi ini? Catatan alumni diarsipkan dan santri diaktifkan kembali. Penempatan kelas dan kamar TIDAK dibuat otomatis.">
                    <?= master_csrf() ?>
                    <input type="hidden" name="action" value="batalkan">
                    <input type="hidden" name="id" value="<?= (int) $dipilih['id'] ?>">
                    <div class="col-md-8">
                        <label class="form-label" for="alasan_batal">Alasan pembatalan <span class="text-danger" aria-hidden="true">*</span></label>
                        <input class="form-control" id="alasan_batal" name="alasan" maxlength="500" required minlength="5">
                    </div>
                    <div class="col-md-4"><button class="btn btn-outline-danger" type="submit">Batalkan kelulusan / mutasi</button></div>
                </form>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <h3 class="h6">Pulihkan catatan</h3>
            <p class="text-muted small mb-2">
                Catatan kembali tampil sebagai alumni aktif. Status santri, kelas, dan kamar
                <strong>tidak</strong> ikut diaktifkan kembali — itu keputusan terpisah.
            </p>
            <form method="post" class="row g-2 align-items-end"
                  data-confirm="Pulihkan catatan alumni ini? Status santri, kelas, dan kamar tidak ikut diubah.">
                <?= master_csrf() ?>
                <input type="hidden" name="action" value="pulihkan">
                <input type="hidden" name="id" value="<?= (int) $dipilih['id'] ?>">
                <div class="col-md-8">
                    <label class="form-label" for="alasan_pulih">Alasan pemulihan <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control" id="alasan_pulih" name="alasan" maxlength="500" required minlength="5">
                </div>
                <div class="col-md-4"><button class="btn btn-outline-primary" type="submit">Pulihkan</button></div>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<form method="get" class="ah-card ah-no-print" action="<?= master_e($halaman) ?>">
    <div class="ah-card__body">
        <fieldset class="ah-fieldset mb-0">
            <legend>Pencarian dan filter</legend>
            <div class="row g-2 align-items-end">
                <div class="col-md-3"><label class="form-label" for="q">Nama atau NIS</label>
                    <input class="form-control" type="search" id="q" name="q" maxlength="100" value="<?= master_e($filters['q']) ?>"></div>
                <div class="col-md-2"><label class="form-label" for="status">Status keluar</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">Semua status</option>
                        <?php foreach (AlumniService::STATUS as $status): ?>
                            <option value="<?= master_e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= master_e($status) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-2"><label class="form-label" for="tahun">Tahun angkatan</label>
                    <select class="form-select" id="tahun" name="tahun">
                        <option value="">Semua tahun</option>
                        <?php foreach ($tahunOptions as $tahun): ?>
                            <option value="<?= master_e($tahun) ?>" <?= $filters['tahun'] === $tahun ? 'selected' : '' ?>><?= master_e($tahun) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-2"><label class="form-label" for="tingkat">Tingkat</label>
                    <select class="form-select" id="tingkat" name="tingkat">
                        <option value="">Semua tingkat</option>
                        <?php foreach (AlumniService::TINGKAT as $tingkat): ?>
                            <option value="<?= master_e($tingkat) ?>" <?= $filters['tingkat'] === $tingkat ? 'selected' : '' ?>><?= master_e($tingkat) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-3"><label class="form-label" for="state">Status catatan</label>
                    <select class="form-select" id="state" name="state">
                        <?php foreach (['active' => 'Aktif', 'archived' => 'Diarsipkan', 'all' => 'Semua catatan'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $filters['state'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-3"><label class="form-label" for="tautan">Referensi santri</label>
                    <select class="form-select" id="tautan" name="tautan">
                        <option value="">Semua</option>
                        <option value="dengan_santri" <?= $filters['tautan'] === 'dengan_santri' ? 'selected' : '' ?>>Sudah terhubung</option>
                        <option value="tanpa_santri" <?= $filters['tautan'] === 'tanpa_santri' ? 'selected' : '' ?>>Belum terhubung (data warisan)</option>
                    </select></div>
                <div class="col-md-4 d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">Terapkan filter</button>
                    <a class="btn btn-outline-secondary" href="<?= master_e($halaman) ?>">Bersihkan</a>
                </div>
            </div>
        </fieldset>
    </div>
</form>

<section class="ah-card" aria-labelledby="ah-daftar-alumni">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-daftar-alumni">Daftar alumni</h2>
        <span class="text-muted small"><?= count($daftar['rows']) ?> dari <?= (int) $daftar['total'] ?> catatan</span></div>
    <?php if ($daftar['rows'] === []): ?>
        <div class="ah-card__body">
            <?= $adaFilter
                ? ah_empty('Tidak ada alumni yang cocok dengan pencarian', 'Ubah kata kunci atau bersihkan filter di atas.',
                    '<a class="btn btn-sm btn-outline-secondary" href="' . master_e($halaman) . '">Bersihkan filter</a>')
                : ah_empty('Belum ada catatan alumni', 'Arsip alumni terisi dari halaman Kelulusan & Mutasi Keluar, satu santri maupun sekelas sekaligus.',
                    '<a class="btn btn-sm btn-primary" href="admin_kelulusan_santri.php">Proses kelulusan / mutasi</a>') ?>
        </div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table">
            <caption class="ah-visually-hidden">Daftar catatan alumni sesuai filter</caption>
            <thead><tr>
                <th scope="col">NIS</th><th scope="col">Nama santri</th><th scope="col">Status keluar</th>
                <th scope="col">Tanggal keluar</th><th scope="col">Tingkat</th><th scope="col">Tahun</th>
                <th scope="col">Catatan</th><th scope="col">Diproses oleh</th><th scope="col">Aksi</th>
            </tr></thead>
            <tbody>
            <?php foreach ($daftar['rows'] as $row): ?>
                <tr>
                    <td><?= master_e($row['nis']) ?></td>
                    <td class="fw-semibold"><?= master_e($row['nama_santri']) ?>
                        <?php if ($row['santri_id'] === null): ?><br><?= ah_badge('Tanpa referensi santri', 'warn') ?><?php endif; ?></td>
                    <td><?= $lencanaStatus((string) $row['status_keluar']) ?></td>
                    <td><?= master_e($row['tgl_keluar']) ?></td>
                    <td><?= master_e($row['tingkat']) ?></td>
                    <td><?= master_e($row['tahun_angkatan']) ?></td>
                    <td><?= $lencanaArsip($row) ?></td>
                    <td><?= master_e($row['dibuat_oleh'] ?: '-') ?><br>
                        <span class="ah-cell-sub"><?= master_e($row['created_at'] ?: 'data warisan') ?></span></td>
                    <td><div class="ah-actions">
                        <a class="btn btn-sm btn-outline-primary" href="?action=detail&amp;id=<?= (int) $row['id'] ?>">Detail</a>
                        <?php if ($row['archived_at'] === null): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="?action=koreksi&amp;id=<?= (int) $row['id'] ?>">Koreksi</a>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php master_pagination((int) $daftar['total'], $page, (int) $daftar['perPage']); ?>

<?php master_footer(); ?>
