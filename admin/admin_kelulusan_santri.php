<?php

declare(strict_types=1);

use App\MasterData\AlumniConflictException;
use App\MasterData\AlumniService;
use App\MasterData\MasterDataException;

/**
 * Kelulusan & Mutasi Keluar Santri
 * (paket "Koreksi Pengelolaan Alumni", keputusan pengguna 6 September 2026).
 *
 * Halaman ini MEMULIHKAN alur yang sempat hilang dari antarmuka: tombol dan
 * formulir pemindahan santri menjadi alumni. Sebelum paket ini, halaman Master
 * Data Santri sudah tidak lagi memiliki tindakan tersebut, sementara
 * `admin/proses_mutasi_alumni.php` masih hidup tanpa satu pun tautan menuju
 * ke sana — sehingga arsip alumni tidak dapat diisi lewat antarmuka.
 *
 * Dua alur yang dilayani:
 *
 *   1. INDIVIDUAL — dibuka dari tombol "Luluskan / Mutasi keluar" pada baris
 *      santri di Master Data Santri (`?santri_id=N`).
 *   2. MASSAL — memilih satu kelas aktif, meninjau daftar santrinya, lalu
 *      memproses seluruhnya sekaligus (`?kelas_id=N`).
 *
 * Keduanya melewati layar konfirmasi yang sama dan dikerjakan
 * `App\MasterData\AlumniService::terapkan()` dalam SATU transaksi.
 *
 * Keamanan:
 *   - hanya admin (`_guard.php` → `requireWebRole('admin')`);
 *   - seluruh mutasi WAJIB POST; `?action=` lewat GET ditolak 405;
 *   - CSRF diperiksa `_guard.php` untuk setiap POST;
 *   - tidak ada nilai GET/POST yang masuk ke SQL; seluruh entitas divalidasi
 *     ulang di server (nilai dropdown dan hidden field TIDAK dipercaya);
 *   - token formulir sekali pakai mencegah penerapan ganda saat klik ganda,
 *     refresh POST, atau retry jaringan.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = alumni_service();
$halaman = 'admin_kelulusan_santri.php';

// Endpoint mutasi hanya menerima POST. Alamat lama yang memanggil aksi lewat
// GET ditolak, bukan dijalankan diam-diam.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['action'])) {
    http_response_code(405);
    header('Allow: GET, POST');
    header('Content-Type: text/plain; charset=utf-8');
    exit('Proses kelulusan/mutasi hanya dapat dikirim melalui POST dari halaman Kelulusan & Mutasi Keluar.');
}

$year = $service->activeYear();
$yearId = $year === null ? 0 : (int) $year['id'];

$tinjauan = null;
$galat = null;

/** Sumber pemilihan santri: 'santri' (individual) atau 'kelas' (massal). */
$sumber = '';
$santriId = 0;
$kelasId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tahap = is_string($_POST['tahap'] ?? null) ? $_POST['tahap'] : '';
    $santriIds = is_array($_POST['santri_ids'] ?? null) ? $_POST['santri_ids'] : [];
    $sumber = in_array($_POST['sumber'] ?? '', ['santri', 'kelas'], true) ? (string) $_POST['sumber'] : '';
    $santriId = max(0, (int) ($_POST['santri_id'] ?? 0));
    $kelasId = max(0, (int) ($_POST['kelas_id'] ?? 0));
    $opsi = [
        'status_keluar' => $_POST['status_keluar'] ?? '',
        'tgl_keluar' => $_POST['tgl_keluar'] ?? '',
        'tahun_angkatan' => $_POST['tahun_angkatan'] ?? '',
        'tingkat' => $_POST['tingkat'] ?? '',
        'catatan' => $_POST['catatan'] ?? '',
    ];

    try {
        if (!in_array($tahap, ['tinjau', 'terapkan'], true)) {
            throw new MasterDataException('Tahap proses tidak dikenal.');
        }

        if ($tahap === 'tinjau') {
            $tinjauan = $service->tinjau($santriIds, $opsi);
            $tinjauan['token'] = ah_form_token('alumni_proses');
            $tinjauan['sumber'] = $sumber;
            $tinjauan['santri_id'] = $santriId;
            $tinjauan['kelas_id'] = $kelasId;
            $tinjauan['opsi'] = $opsi;
        } else {
            if (!ah_form_token_consume('alumni_proses', $_POST['form_token'] ?? null)) {
                master_flash(
                    'warning',
                    'Tinjauan ini sudah pernah diterapkan. Tidak ada catatan alumni ganda yang dibuat. '
                    . 'Muat ulang halaman bila ingin memproses santri lain.'
                );
                master_redirect('admin_alumni.php');
            }

            $hasil = $service->terapkan($santriIds, $opsi, (int) $currentUser['id']);
            if ($hasil['mode'] === 'individu' && $hasil['alumni_id'] !== null) {
                master_flash(
                    'success',
                    'Berhasil: 1 santri dipindahkan ke arsip alumni dengan status ' . $hasil['status_keluar']
                    . ' per ' . $hasil['tgl_keluar'] . '. Baris santri TIDAK dihapus — statusnya menjadi arsip, '
                    . 'dan seluruh riwayat kelas, kamar, relasi wali, serta akun tetap tersimpan.'
                );
                master_redirect('admin_alumni.php?action=detail&id=' . (int) $hasil['alumni_id']);
            }
            master_flash(
                'success',
                'Berhasil: ' . (int) $hasil['jumlah'] . ' santri dipindahkan ke arsip alumni dengan status '
                . $hasil['status_keluar'] . ' per ' . $hasil['tgl_keluar'] . '. Seluruhnya diproses dalam satu '
                . 'transaksi. Baris santri TIDAK dihapus dan seluruh riwayatnya tetap tersimpan.'
            );
            master_redirect('admin_alumni.php');
        }
    } catch (AlumniConflictException $exception) {
        http_response_code(409);
        $galat = $exception->getMessage();
    } catch (MasterDataException $exception) {
        http_response_code(422);
        $galat = $exception->getMessage();
    }
} else {
    $santriId = max(0, (int) ($_GET['santri_id'] ?? 0));
    $kelasId = max(0, (int) ($_GET['kelas_id'] ?? 0));
    $sumber = $santriId > 0 ? 'santri' : ($kelasId > 0 ? 'kelas' : '');
}

$kelasOptions = $service->classOptions($yearId);
$kelasTerpilih = $kelasId > 0 ? $service->kelas($kelasId) : null;

/** @var array<int, array<string, mixed>> $kandidat */
$kandidat = [];
if ($yearId > 0 && $tinjauan === null) {
    if ($sumber === 'santri' && $santriId > 0) {
        $kandidat = $service->kandidat([$santriId], $yearId);
    } elseif ($sumber === 'kelas' && $kelasTerpilih !== null) {
        $kandidat = $service->kandidat($service->santriAktifPadaKelas($kelasId, $yearId), $yearId);
    }
}
$layak = array_values(array_filter($kandidat, static fn (array $row): bool => $row['layak'] === true));
$ditolak = array_values(array_filter($kandidat, static fn (array $row): bool => $row['layak'] !== true));

$nilai = static function (string $field, string $default) use ($tinjauan): string {
    if (is_array($tinjauan) && isset($tinjauan['opsi'][$field])) {
        return (string) $tinjauan['opsi'][$field];
    }
    if (isset($_POST[$field]) && is_scalar($_POST[$field])) {
        return (string) $_POST[$field];
    }

    return $default;
};

master_header('Kelulusan & Mutasi Keluar', [
    'description' => 'Memindahkan santri aktif menjadi alumni — satu per satu atau sekelas sekaligus. '
        . 'Baris santri tidak pernah dihapus: statusnya menjadi arsip dan seluruh riwayatnya tetap tersimpan.',
    'active' => 'alumni.kelulusan',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Data Alumni', 'url' => app_url('/admin/admin_alumni.php')],
        ['label' => 'Kelulusan & Mutasi Keluar'],
    ],
    'actions' => '<a class="btn btn-outline-secondary" href="admin_alumni.php">Arsip Alumni</a>'
        . '<a class="btn btn-outline-secondary" href="admin_master_santri.php">Data Santri</a>',
]);

if ($galat !== null) {
    ah_note('danger', $galat);
}
if ($year === null) {
    ah_note(
        'warning',
        'Belum ada tahun ajaran aktif. Aktifkan satu semester pada halaman Tahun Ajaran sebelum memproses kelulusan.',
        '<p class="small mb-0 mt-2"><a href="' . master_e(app_url('/admin/admin_tahun.php')) . '">Buka halaman Tahun Ajaran</a></p>'
    );
} else {
    ah_note(
        'info',
        'Semester aktif: ' . $year['tahun'] . ' ' . $year['semester']
        . '. Penutupan kelas dan kamar hanya berlaku untuk semester ini; riwayat semester sebelumnya tidak disentuh.'
    );
}
?>

<?php if ($tinjauan !== null): ?>
<section class="ah-card" aria-labelledby="ah-tinjauan-alumni">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-tinjauan-alumni">Konfirmasi kelulusan / mutasi keluar</h2></div>
    <div class="ah-card__body">
        <?php ah_note(
            'warning',
            $tinjauan['mode'] === 'massal'
                ? 'Proses ini memengaruhi SELURUH ' . (int) $tinjauan['jumlah'] . ' santri di bawah ini sekaligus.'
                : 'Periksa kembali data di bawah sebelum memproses.',
            '<ul class="mb-0 mt-2">'
            . '<li>Baris santri <strong>tidak dihapus</strong>; statusnya menjadi arsip sehingga tidak lagi muncul pada daftar operasional.</li>'
            . '<li>Penempatan kelas aktif ditutup (status “Selesai”); barisnya tetap ada sebagai riwayat.</li>'
            . '<li>Penempatan kamar semester berjalan dilepas agar tempatnya kembali tersedia; nama kamarnya disimpan pada catatan alumni dan audit.</li>'
            . '<li>Relasi wali, akun wali, absensi, perizinan, dan pembiayaan <strong>tidak disentuh</strong>.</li>'
            . '<li>Seluruh santri diproses dalam satu transaksi: bila satu gagal, tidak ada satu pun yang berubah.</li>'
            . '</ul>'
        ); ?>

        <dl class="row mb-3">
            <dt class="col-sm-3">Status keluar</dt><dd class="col-sm-9"><?= master_e($tinjauan['status_keluar']) ?></dd>
            <dt class="col-sm-3">Tanggal keluar</dt><dd class="col-sm-9"><?= master_e($tinjauan['tgl_keluar']) ?></dd>
            <dt class="col-sm-3">Tahun angkatan</dt><dd class="col-sm-9"><?= master_e($tinjauan['tahun_angkatan']) ?></dd>
            <dt class="col-sm-3">Tingkat terakhir</dt><dd class="col-sm-9"><?= master_e($tinjauan['tingkat']) ?></dd>
            <dt class="col-sm-3">Catatan</dt><dd class="col-sm-9"><?= $tinjauan['catatan'] === '' ? '<span class="text-muted">tidak ada</span>' : master_e($tinjauan['catatan']) ?></dd>
        </dl>

        <?php if ($tinjauan['masalah'] !== []): ?>
            <?php ah_note('danger', 'Proses tidak dapat dijalankan.', '<ul class="mb-0 mt-2"><li>'
                . implode('</li><li>', array_map('master_e', $tinjauan['masalah'])) . '</li></ul>'); ?>
        <?php endif; ?>

        <?php if ($tinjauan['baris'] !== []): ?>
        <div class="ah-table-wrap"><table class="ah-table">
            <caption class="ah-visually-hidden">Santri yang akan dipindahkan ke arsip alumni</caption>
            <thead><tr>
                <th scope="col">NIS</th><th scope="col">Nama santri</th><th scope="col">Unit terakhir</th>
                <th scope="col">Kelas aktif</th><th scope="col">Kamar aktif</th>
            </tr></thead>
            <tbody>
            <?php foreach ($tinjauan['baris'] as $baris): ?>
                <tr>
                    <td><?= master_e($baris['nis']) ?></td>
                    <td class="fw-semibold"><?= master_e($baris['nama_santri']) ?></td>
                    <td><?= master_e($baris['unit_terakhir'] ?: '-') ?></td>
                    <td><?= $baris['kelas_terakhir'] === null ? '<span class="text-muted">tanpa kelas</span>' : master_e($baris['kelas_terakhir']) ?></td>
                    <td><?= $baris['kamar_terakhir'] === null ? '<span class="text-muted">tanpa kamar</span>' : master_e($baris['kamar_terakhir']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>

        <form method="post" class="mt-3"
              data-confirm="Proses kelulusan/mutasi ini sekarang? Santri terpilih menjadi alumni dan statusnya berubah menjadi arsip. Seluruhnya berubah bersama-sama; bila satu gagal, tidak ada yang tersimpan.">
            <?= master_csrf() ?>
            <input type="hidden" name="tahap" value="terapkan">
            <input type="hidden" name="form_token" value="<?= master_e($tinjauan['token']) ?>">
            <input type="hidden" name="sumber" value="<?= master_e((string) $tinjauan['sumber']) ?>">
            <input type="hidden" name="santri_id" value="<?= (int) $tinjauan['santri_id'] ?>">
            <input type="hidden" name="kelas_id" value="<?= (int) $tinjauan['kelas_id'] ?>">
            <input type="hidden" name="status_keluar" value="<?= master_e($tinjauan['status_keluar']) ?>">
            <input type="hidden" name="tgl_keluar" value="<?= master_e($tinjauan['tgl_keluar']) ?>">
            <input type="hidden" name="tahun_angkatan" value="<?= master_e($tinjauan['tahun_angkatan']) ?>">
            <input type="hidden" name="tingkat" value="<?= master_e($tinjauan['tingkat']) ?>">
            <input type="hidden" name="catatan" value="<?= master_e($tinjauan['catatan']) ?>">
            <?php foreach ($tinjauan['baris'] as $baris): ?>
                <input type="hidden" name="santri_ids[]" value="<?= (int) $baris['santri_id'] ?>">
            <?php endforeach; ?>

            <div class="ah-actions">
                <button class="btn btn-primary" type="submit" <?= $tinjauan['masalah'] === [] && $tinjauan['baris'] !== [] ? '' : 'disabled' ?>>
                    Proses <?= (int) count($tinjauan['baris']) ?> santri menjadi alumni
                </button>
                <a class="btn btn-outline-secondary" href="<?= master_e($halaman
                    . ((int) $tinjauan['santri_id'] > 0 ? '?santri_id=' . (int) $tinjauan['santri_id'] : ((int) $tinjauan['kelas_id'] > 0 ? '?kelas_id=' . (int) $tinjauan['kelas_id'] : ''))) ?>">Batal</a>
            </div>
        </form>
    </div>
</section>
<?php endif; ?>

<?php if ($tinjauan === null): ?>

<section class="ah-card" aria-labelledby="ah-pilih-sumber">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-pilih-sumber">Pilih santri yang akan diproses</h2></div>
    <div class="ah-card__body">
        <p class="text-muted">
            Pemrosesan individual dimulai dari tombol <strong>“Luluskan / Mutasi keluar”</strong> pada baris santri di
            halaman Data Santri. Pemrosesan massal dimulai dengan memilih kelas aktif di bawah ini.
        </p>
        <form method="get" action="<?= master_e($halaman) ?>" class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label" for="kelas_id">Kelas pada semester aktif</label>
                <select class="form-select" id="kelas_id" name="kelas_id" <?= $kelasOptions === [] ? 'disabled' : '' ?>>
                    <option value="">— pilih kelas —</option>
                    <?php foreach ($kelasOptions as $kelas): ?>
                        <option value="<?= (int) $kelas['id'] ?>" <?= $kelasId === (int) $kelas['id'] ? 'selected' : '' ?>>
                            <?= master_e($kelas['nama_kelas'] . ' (' . $kelas['jenjang'] . ') — ' . (int) $kelas['jumlah_aktif'] . ' santri aktif') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit" <?= $kelasOptions === [] ? 'disabled' : '' ?>>Tampilkan santri kelas ini</button>
                <a class="btn btn-outline-secondary" href="<?= master_e($halaman) ?>">Bersihkan</a>
            </div>
        </form>
        <?php if ($kelasOptions === []): ?>
            <p class="text-muted mt-3 mb-0">Belum ada kelas yang dapat dipilih pada semester aktif.</p>
        <?php endif; ?>
    </div>
</section>

<?php if ($sumber !== '' && $kandidat === []): ?>
    <section class="ah-card"><div class="ah-card__body">
        <?= ah_empty(
            'Tidak ada santri yang dapat diproses',
            $sumber === 'kelas'
                ? 'Kelas ini tidak memiliki santri aktif pada semester berjalan, atau kelasnya tidak ditemukan.'
                : 'Santri yang diminta tidak ditemukan pada master data.',
            '<a class="btn btn-sm btn-outline-secondary" href="admin_master_santri.php">Buka Data Santri</a>'
        ) ?>
    </div></section>
<?php elseif ($kandidat !== []): ?>

<?php if ($ditolak !== []): ?>
<section class="ah-card" aria-labelledby="ah-dikecualikan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-dikecualikan">Dikecualikan dari proses (<?= count($ditolak) ?>)</h2></div>
    <div class="ah-card__body">
        <p class="text-muted">Santri berikut <strong>tidak</strong> ikut diproses. Ia tidak dihitung dan tidak menghasilkan catatan alumni ganda.</p>
        <div class="ah-table-wrap"><table class="ah-table">
            <caption class="ah-visually-hidden">Santri yang dikecualikan beserta alasannya</caption>
            <thead><tr><th scope="col">NIS</th><th scope="col">Nama santri</th><th scope="col">Alasan dikecualikan</th><th scope="col">Catatan alumni</th></tr></thead>
            <tbody>
            <?php foreach ($ditolak as $row): ?>
                <tr>
                    <td><?= master_e($row['nis']) ?></td>
                    <td class="fw-semibold"><?= master_e($row['nama_santri']) ?></td>
                    <td><?= master_e($row['halangan']) ?></td>
                    <td><?= $row['alumni_id'] === null
                        ? '<span class="text-muted">-</span>'
                        : '<a href="admin_alumni.php?action=detail&amp;id=' . (int) $row['alumni_id'] . '">Lihat catatan #' . (int) $row['alumni_id'] . '</a>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</section>
<?php endif; ?>

<?php if ($layak === []): ?>
    <section class="ah-card"><div class="ah-card__body">
        <?= ah_empty(
            'Seluruh santri sudah diproses sebelumnya',
            'Tidak ada santri yang masih layak diluluskan atau dimutasikan dari pilihan ini. '
            . 'Catatan alumni yang sudah ada tidak diproses ulang.',
            '<a class="btn btn-sm btn-outline-secondary" href="admin_alumni.php">Buka arsip alumni</a>'
        ) ?>
    </div></section>
<?php else: ?>

<form method="post">
    <?= master_csrf() ?>
    <input type="hidden" name="tahap" value="tinjau">
    <input type="hidden" name="sumber" value="<?= master_e($sumber) ?>">
    <input type="hidden" name="santri_id" value="<?= (int) $santriId ?>">
    <input type="hidden" name="kelas_id" value="<?= (int) $kelasId ?>">
    <?php foreach ($layak as $row): ?>
        <input type="hidden" name="santri_ids[]" value="<?= (int) $row['santri_id'] ?>">
    <?php endforeach; ?>

    <section class="ah-card" aria-labelledby="ah-daftar-proses">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-daftar-proses">
            <?= $sumber === 'kelas' && $kelasTerpilih !== null
                ? 'Santri kelas ' . master_e((string) $kelasTerpilih['nama_kelas'])
                : 'Santri yang akan diproses' ?></h2>
            <span class="text-muted small"><?= count($layak) ?> santri akan diproses</span></div>
        <div class="ah-table-wrap"><table class="ah-table">
            <caption class="ah-visually-hidden">Ringkasan santri, kelas aktif, dan kamar aktif sebelum konfirmasi</caption>
            <thead><tr>
                <th scope="col">NIS</th><th scope="col">Nama santri</th><th scope="col">L/P</th>
                <th scope="col">Unit sekolah</th><th scope="col">Kelas aktif</th><th scope="col">Kamar aktif</th>
            </tr></thead>
            <tbody>
            <?php foreach ($layak as $row): ?>
                <tr>
                    <td><?= master_e($row['nis']) ?></td>
                    <td class="fw-semibold"><?= master_e($row['nama_santri']) ?></td>
                    <td><?= master_e($row['jenis_kelamin']) ?></td>
                    <td><?= master_e($row['unit_terakhir'] ?: '-') ?></td>
                    <td><?= $row['kelas_aktif'] === null ? ah_badge('Tanpa kelas', 'warn') : master_e($row['kelas_aktif']) ?></td>
                    <td><?= $row['kamar_aktif'] === null ? ah_badge('Tanpa kamar', 'warn') : master_e($row['kamar_aktif']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <section class="ah-card" aria-labelledby="ah-form-keluar">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-form-keluar">Keterangan keluar</h2></div>
        <div class="ah-card__body">
            <fieldset class="ah-fieldset">
                <legend>Data yang disimpan pada catatan alumni</legend>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="status_keluar">Status keluar</label>
                        <select class="form-select" id="status_keluar" name="status_keluar" required>
                            <?php foreach (AlumniService::STATUS as $status): ?>
                                <option value="<?= master_e($status) ?>" <?= $nilai('status_keluar', 'Lulus') === $status ? 'selected' : '' ?>><?= master_e($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="tgl_keluar">Tanggal keluar</label>
                        <input class="form-control" type="date" id="tgl_keluar" name="tgl_keluar" required
                               value="<?= master_e($nilai('tgl_keluar', date('Y-m-d'))) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="tahun_angkatan">Tahun angkatan / tahun keluar</label>
                        <input class="form-control" id="tahun_angkatan" name="tahun_angkatan" maxlength="10" required
                               placeholder="2026 atau 2025/2026"
                               value="<?= master_e($nilai('tahun_angkatan', (string) ($year['tahun'] ?? date('Y')))) ?>"
                               aria-describedby="bantuan_tahun">
                        <div class="form-text" id="bantuan_tahun">Format “2026” atau “2025/2026”.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="tingkat">Tingkat terakhir</label>
                        <select class="form-select" id="tingkat" name="tingkat" required>
                            <?php foreach (AlumniService::TINGKAT as $tingkat): ?>
                                <option value="<?= master_e($tingkat) ?>" <?= $nilai('tingkat', 'Ibtida') === $tingkat ? 'selected' : '' ?>><?= master_e($tingkat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="catatan">Catatan <span class="text-muted fw-normal">(opsional)</span></label>
                        <textarea class="form-control" id="catatan" name="catatan" rows="2" maxlength="500"
                                  aria-describedby="bantuan_catatan"><?= master_e($nilai('catatan', '')) ?></textarea>
                        <div class="form-text" id="bantuan_catatan">Catatan ikut tersimpan pada catatan alumni dan pada audit.</div>
                    </div>
                </div>
            </fieldset>
            <div class="ah-actions">
                <button class="btn btn-primary" type="submit">Tinjau sebelum memproses</button>
                <a class="btn btn-outline-secondary" href="admin_master_santri.php">Batal</a>
            </div>
        </div>
    </section>
</form>

<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php master_footer(); ?>
