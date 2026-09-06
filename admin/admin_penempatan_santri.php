<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;
use App\MasterData\PenempatanConflictException;
use App\MasterData\PenempatanService;

/**
 * Penempatan Kelas & Kamar Santri (keputusan pengguna 6 September 2026).
 *
 * Halaman ini menggantikan tampilan lama `admin/admin_santri.php`. Fitur dan
 * datanya TIDAK dihapus: alamat lama tetap hidup sebagai pengalihan untuk
 * permintaan GET (bookmark lama), sedangkan endpoint POST lamanya dihentikan
 * secara eksplisit — bukan dialihkan diam-diam — karena tidak ber-CSRF dan
 * tidak transaksional.
 *
 * Seluruh perubahan data dikerjakan `App\MasterData\PenempatanService` dalam
 * satu transaksi. Halaman ini hanya menyusun masukan, menampilkan tinjauan,
 * dan melaporkan hasil.
 *
 * Keamanan:
 *   - hanya admin (`_guard.php` → `requireWebRole('admin')`);
 *   - seluruh mutasi WAJIB POST; metode lain untuk aksi ditolak 405;
 *   - CSRF diperiksa `_guard.php` untuk setiap POST;
 *   - tidak ada nilai GET/POST yang masuk ke SQL; seluruh entitas divalidasi
 *     ulang di server (dropdown bukan validasi);
 *   - token formulir sekali pakai mencegah penerapan ganda saat refresh/retry.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = penempatan_service();
$halaman = 'admin_penempatan_santri.php';

$filterInput = is_array($_POST['filter'] ?? null) ? $_POST['filter'] : $_GET;
/** @var array<string, mixed> $filters */
$filters = $service->normalizeFilters($filterInput);
$page = max(1, (int) (is_scalar($filterInput['page'] ?? null) ? $filterInput['page'] : 1));

// Endpoint mutasi hanya menerima POST. Alamat lama yang memanggil aksi lewat
// GET ditolak, bukan dijalankan diam-diam.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['action'])) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Perubahan penempatan hanya dapat dikirim melalui POST dari halaman Penempatan Kelas & Kamar.');
}

$tinjauan = null;
$galat = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $tahap = is_string($_POST['tahap'] ?? null) ? $_POST['tahap'] : '';
    $santriIds = is_array($_POST['santri_ids'] ?? null) ? $_POST['santri_ids'] : [];
    $opsi = [
        'kelas_id' => $_POST['kelas_id'] ?? null,
        'kamar_id' => $_POST['kamar_id'] ?? null,
        'tanggal_mulai' => $_POST['tanggal_mulai'] ?? '',
        'alasan' => $_POST['alasan'] ?? '',
    ];

    try {
        if (!in_array($aksi, PenempatanService::AKSI, true)) {
            throw new MasterDataException('Tindakan penempatan tidak dikenal.');
        }
        if (!in_array($tahap, ['tinjau', 'terapkan', 'langsung'], true)) {
            throw new MasterDataException('Tahap penempatan tidak dikenal.');
        }

        if ($tahap === 'tinjau') {
            $tinjauan = $service->preview($aksi, $santriIds, $opsi);
            $tinjauan['token'] = ah_form_token('penempatan');
        } else {
            if ($tahap === 'langsung') {
                // Jalur cepat baris tabel: hanya untuk SATU santri dan hanya
                // untuk penempatan (bukan pengeluaran, yang selalu meminta
                // konfirmasi dan alasan pada layar tinjauan).
                if (count($santriIds) !== 1) {
                    throw new MasterDataException('Penempatan cepat hanya untuk satu santri. Gunakan tinjauan untuk beberapa santri.');
                }
                if (!in_array($aksi, [PenempatanService::AKSI_KELAS_TETAPKAN, PenempatanService::AKSI_KAMAR_TETAPKAN], true)) {
                    throw new MasterDataException('Mengeluarkan santri harus melalui layar konfirmasi beralasan.');
                }
            } elseif (!ah_form_token_consume('penempatan', $_POST['form_token'] ?? null)) {
                master_flash('warning', 'Tinjauan ini sudah pernah diterapkan. Tidak ada perubahan ganda yang dibuat. Muat ulang halaman bila ingin melakukan perubahan baru.');
                master_redirect($halaman . '?' . ah_query([], $filters));
            }

            $hasil = $service->apply($aksi, $santriIds, $opsi, (int) $currentUser['id']);
            master_flash('success', penempatan_pesan_hasil($hasil));
            master_redirect($halaman . '?' . ah_query([], $filters));
        }
    } catch (PenempatanConflictException $exception) {
        http_response_code(409);
        $galat = $exception->getMessage();
    } catch (MasterDataException $exception) {
        http_response_code(422);
        $galat = $exception->getMessage();
    }
}

/**
 * @param array<string, mixed> $hasil
 */
function penempatan_pesan_hasil(array $hasil): string
{
    $target = $hasil['target']['nama'] ?? null;
    $jenis = str_contains((string) $hasil['aksi'], 'kelas') ? 'kelas' : 'kamar';
    $pesan = $target === null
        ? 'Berhasil: ' . (int) $hasil['diterapkan'] . ' santri dikeluarkan dari ' . $jenis . ' pada semester aktif.'
        : 'Berhasil: ' . (int) $hasil['diterapkan'] . ' santri ditempatkan pada ' . $jenis . ' ' . $target . '.';
    if ((int) $hasil['tidak_berubah'] > 0) {
        $pesan .= ' ' . (int) $hasil['tidak_berubah'] . ' santri sudah berada pada keadaan itu sehingga tidak diubah (tidak ada data ganda).';
    }
    $pesan .= ' Riwayat semester sebelumnya tidak disentuh.';

    return $pesan;
}

/**
 * Kamar yang kapasitasnya belum diatur tidak dapat dijadikan tujuan; server
 * menolaknya. Menandainya di sini mencegah admin memilih sesuatu yang pasti
 * gagal — pilihan tetap terlihat pada filter agar penghuninya tetap dapat
 * ditemukan.
 *
 * @param array<string, mixed> $kamar
 */
function penempatan_kamar_nonaktif(array $kamar): bool
{
    return (int) $kamar['kapasitas'] < 1;
}

/**
 * @param array<string, mixed> $kamar
 */
function penempatan_label_kamar(array $kamar): string
{
    $kapasitas = (int) $kamar['kapasitas'];
    $terisi = (int) $kamar['terisi'];
    if ($kapasitas < 1) {
        return $kamar['nama_kamar'] . ' (' . $terisi . ' penghuni — kapasitas belum diatur)';
    }

    return $kamar['nama_kamar'] . ' (' . $terisi . '/' . $kapasitas
        . ($terisi >= $kapasitas ? ' — penuh' : ' — sisa ' . ($kapasitas - $terisi)) . ')';
}

$year = $service->activeYear();
$yearId = $year === null ? 0 : (int) $year['id'];
$kelasOptions = $service->classOptions();
$kamarOptions = $yearId > 0 ? $service->roomOptions($yearId) : [];
$sekolahOptions = $service->schoolOptions();
$daftar = $yearId > 0
    ? $service->listPage($filters, $yearId, $page)
    : ['rows' => [], 'total' => 0, 'page' => 1, 'perPage' => 20];
$page = (int) $daftar['page'];
$ringkasan = $yearId > 0
    ? $service->summary($yearId)
    : ['santri_aktif' => 0, 'tanpa_kelas' => 0, 'tanpa_kamar' => 0, 'nonaktif_berkamar' => 0];
$adaFilter = array_filter($filters, static fn (mixed $value): bool => $value !== '' && $value !== 0) !== [];

master_header('Penempatan Kelas & Kamar', [
    'description' => 'Menempatkan santri ke kelas dan kamar pada semester aktif, satu per satu maupun sekaligus. Riwayat semester sebelumnya tidak pernah diubah.',
    'active' => 'master.penempatan',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Master Data'],
        ['label' => 'Penempatan Kelas & Kamar'],
    ],
    'actions' => '<a class="btn btn-outline-secondary" href="admin_master_santri.php">Data Santri</a>'
        . '<a class="btn btn-outline-secondary" href="admin_kelas.php">Data Kelas</a>'
        . '<a class="btn btn-outline-secondary" href="admin_kamar.php">Data Kamar</a>',
]);

if ($galat !== null) {
    ah_note('danger', $galat);
}
if ($year === null) {
    ah_note('warning', 'Belum ada tahun ajaran aktif. Aktifkan satu semester pada halaman Tahun Ajaran sebelum menempatkan santri.');
} else {
    ah_note('info', 'Semester aktif: ' . $year['tahun'] . ' ' . $year['semester']
        . '. Setiap perubahan hanya berlaku untuk semester ini dan seluruhnya tercatat pada audit.');
}
if ($filters['status'] === 'nonaktif_berkamar') {
    ah_note(
        'warning',
        'Daftar ini memuat santri nonaktif atau yang sudah diarsipkan tetapi masih menempati kamar pada semester aktif.',
        '<p class="small mb-0 mt-2">Mereka tidak dapat ditempatkan ke kelas atau kamar baru, tetapi <strong>dapat dikeluarkan</strong> '
        . 'agar tempatnya kembali tersedia: centang santrinya lalu gunakan tombol “Keluarkan dari kamar…”. '
        . 'Data santri, riwayat, dan audit tetap utuh.</p>'
    );
}
?>

<style>
/* Tata letak kolom "Penempatan cepat" saja. Sisanya memakai komponen bersama
   `assets/ui/alhasan.css` tanpa perubahan. */
.pn-cepat { display: grid; gap: .35rem; min-width: 15rem; }
.pn-cepat__baris { display: flex; gap: .35rem; align-items: center; }
.pn-cepat__baris select { flex: 1 1 9rem; min-width: 0; }
.pn-cepat__baris .btn { white-space: nowrap; }
</style>

<section class="ah-stats" aria-label="Ringkasan penempatan semester aktif">
    <div class="ah-stat"><span class="ah-stat__label">Santri aktif</span>
        <span class="ah-stat__value"><?= (int) $ringkasan['santri_aktif'] ?></span>
        <span class="ah-stat__hint">Santri aktif dan belum diarsipkan.</span></div>
    <div class="ah-stat"><span class="ah-stat__label">Belum punya kelas</span>
        <span class="ah-stat__value"><?= (int) $ringkasan['tanpa_kelas'] ?></span>
        <span class="ah-stat__hint">Pada semester aktif.</span></div>
    <div class="ah-stat"><span class="ah-stat__label">Belum punya kamar</span>
        <span class="ah-stat__value"><?= (int) $ringkasan['tanpa_kamar'] ?></span>
        <span class="ah-stat__hint">Pada semester aktif.</span></div>
    <div class="ah-stat"><span class="ah-stat__label">Nonaktif tapi masih berkamar</span>
        <span class="ah-stat__value"><?= (int) $ringkasan['nonaktif_berkamar'] ?></span>
        <span class="ah-stat__hint">Tempatnya masih terpakai.
            <a href="<?= master_e($halaman . '?status=nonaktif_berkamar') ?>">Lihat dan bebaskan</a>.</span></div>
</section>

<?php if ($tinjauan !== null): ?>
<section class="ah-card" aria-labelledby="ah-tinjauan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-tinjauan">Konfirmasi perubahan penempatan</h2></div>
    <div class="ah-card__body">
        <p class="mb-2">
            <?= (int) $tinjauan['jumlah'] ?> santri terpilih
            (<?= $tinjauan['mode'] === 'massal' ? 'tindakan massal' : 'tindakan individual' ?>).
            <?php if ($tinjauan['target'] === null): ?>
                Tindakan: <strong>mengeluarkan dari <?= master_e($tinjauan['jenis']) ?></strong> pada semester aktif.
            <?php else: ?>
                Tujuan: <strong><?= master_e($tinjauan['target']['nama']) ?></strong>.
            <?php endif; ?>
        </p>
        <?php if ($tinjauan['kapasitas'] !== null): $k = $tinjauan['kapasitas']; ?>
            <p class="mb-2">Kapasitas kamar <strong><?= master_e($k['nama_kamar']) ?></strong>:
                terisi <?= (int) $k['terisi'] ?> dari <?= (int) $k['kapasitas'] ?>,
                sisa <?= max(0, (int) $k['sisa']) ?> tempat, perlu ditambahkan <?= (int) $k['tambahan'] ?> santri.
                <?= $k['cukup'] ? ah_badge('Kapasitas cukup', 'ok') : ah_badge('Kapasitas tidak cukup', 'danger') ?></p>
        <?php endif; ?>

        <?php if ($tinjauan['masalah'] !== []): ?>
            <?php ah_note('danger', 'Perubahan tidak dapat diterapkan.', '<ul class="mb-0 mt-2"><li>'
                . implode('</li><li>', array_map('master_e', $tinjauan['masalah'])) . '</li></ul>'); ?>
        <?php endif; ?>

        <div class="ah-table-wrap"><table class="ah-table">
            <caption class="ah-visually-hidden">Rincian perubahan penempatan yang akan diterapkan</caption>
            <thead><tr><th scope="col">NIS</th><th scope="col">Nama santri</th><th scope="col">Sebelum</th><th scope="col">Sesudah</th><th scope="col">Perubahan</th></tr></thead>
            <tbody>
            <?php foreach ($tinjauan['baris'] as $baris): ?>
                <tr>
                    <td><?= master_e($baris['nis']) ?></td>
                    <td><?= master_e($baris['nama_santri']) ?></td>
                    <td><?= master_e($baris['sebelum'] ?? 'belum ditempatkan') ?></td>
                    <td><?= master_e($baris['sesudah'] ?? 'tanpa ' . $tinjauan['jenis']) ?></td>
                    <td><?= master_e(match ($baris['perubahan']) {
                        'masuk' => 'Ditempatkan',
                        'pindah' => 'Dipindahkan',
                        'keluar' => 'Dikeluarkan',
                        'tetap' => 'Tidak berubah (sudah di sana)',
                        default => 'Tidak berubah (belum ditempatkan)',
                    }) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>

        <form method="post" class="mt-3" data-confirm="Terapkan perubahan penempatan ini? Seluruh santri berubah bersama-sama; bila satu gagal, tidak ada yang tersimpan.">
            <?= master_csrf() ?>
            <input type="hidden" name="action" value="<?= master_e($tinjauan['aksi']) ?>">
            <input type="hidden" name="tahap" value="terapkan">
            <input type="hidden" name="form_token" value="<?= master_e($tinjauan['token']) ?>">
            <?php if ($tinjauan['target'] !== null): ?>
                <input type="hidden" name="<?= $tinjauan['jenis'] === 'kelas' ? 'kelas_id' : 'kamar_id' ?>" value="<?= (int) $tinjauan['target']['id'] ?>">
            <?php endif; ?>
            <?php if ($tinjauan['jenis'] === 'kelas'): ?>
                <input type="hidden" name="tanggal_mulai" value="<?= master_e($tinjauan['tanggal_mulai']) ?>">
            <?php endif; ?>
            <?php foreach ($tinjauan['baris'] as $baris): ?>
                <input type="hidden" name="santri_ids[]" value="<?= (int) $baris['santri_id'] ?>">
            <?php endforeach; ?>
            <?php foreach ($filters as $nama => $nilai): ?>
                <input type="hidden" name="filter[<?= master_e($nama) ?>]" value="<?= master_e($nilai) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="filter[page]" value="<?= (int) $page ?>">

            <?php if ($tinjauan['target'] === null): ?>
                <div class="mb-3">
                    <label class="form-label" for="alasan">Alasan mengeluarkan santri <span class="text-danger" aria-hidden="true">*</span></label>
                    <textarea class="form-control" id="alasan" name="alasan" rows="2" maxlength="500" required
                              aria-describedby="bantuan_alasan"><?= master_e(is_string($_POST['alasan'] ?? null) ? $_POST['alasan'] : '') ?></textarea>
                    <div class="form-text" id="bantuan_alasan">Alasan wajib diisi dan ikut tercatat pada audit.</div>
                </div>
            <?php endif; ?>

            <div class="ah-actions">
                <button class="btn btn-primary" type="submit" <?= $tinjauan['masalah'] === [] ? '' : 'disabled' ?>>Terapkan perubahan</button>
                <a class="btn btn-outline-secondary" href="<?= master_e($halaman . '?' . ah_query([], $filters)) ?>">Batal</a>
            </div>
        </form>
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
                <div class="col-md-2"><label class="form-label" for="jk">Jenis kelamin</label>
                    <select class="form-select" id="jk" name="jk">
                        <option value="">Semua</option>
                        <option value="L" <?= $filters['jk'] === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                        <option value="P" <?= $filters['jk'] === 'P' ? 'selected' : '' ?>>Perempuan</option>
                    </select></div>
                <div class="col-md-3"><label class="form-label" for="sekolah">Unit sekolah</label>
                    <select class="form-select" id="sekolah" name="sekolah">
                        <option value="">Semua unit</option>
                        <?php foreach ($sekolahOptions as $sekolah): ?>
                            <option value="<?= master_e($sekolah) ?>" <?= $filters['sekolah'] === $sekolah ? 'selected' : '' ?>><?= master_e($sekolah) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-2"><label class="form-label" for="filter_kelas">Kelas</label>
                    <select class="form-select" id="filter_kelas" name="kelas_id">
                        <option value="">Semua kelas</option>
                        <?php foreach ($kelasOptions as $kelas): ?>
                            <option value="<?= (int) $kelas['id'] ?>" <?= (int) $filters['kelas_id'] === (int) $kelas['id'] ? 'selected' : '' ?>><?= master_e($kelas['nama_kelas'] . ' (' . $kelas['jenjang'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-2"><label class="form-label" for="filter_kamar">Kamar</label>
                    <select class="form-select" id="filter_kamar" name="kamar_id">
                        <option value="">Semua kamar</option>
                        <?php foreach ($kamarOptions as $kamar): ?>
                            <option value="<?= (int) $kamar['id'] ?>" <?= (int) $filters['kamar_id'] === (int) $kamar['id'] ? 'selected' : '' ?>><?= master_e($kamar['nama_kamar']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-4"><label class="form-label" for="status">Status penempatan</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">Semua santri</option>
                        <option value="tanpa_kelas" <?= $filters['status'] === 'tanpa_kelas' ? 'selected' : '' ?>>Belum mempunyai kelas</option>
                        <option value="tanpa_kamar" <?= $filters['status'] === 'tanpa_kamar' ? 'selected' : '' ?>>Belum mempunyai kamar</option>
                        <option value="tanpa_keduanya" <?= $filters['status'] === 'tanpa_keduanya' ? 'selected' : '' ?>>Belum mempunyai kelas dan kamar</option>
                        <option value="nonaktif_berkamar" <?= $filters['status'] === 'nonaktif_berkamar' ? 'selected' : '' ?>>Nonaktif/arsip tetapi masih berkamar</option>
                    </select></div>
                <div class="col-md-4 d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">Terapkan filter</button>
                    <a class="btn btn-outline-secondary" href="<?= master_e($halaman) ?>">Bersihkan</a>
                </div>
            </div>
        </fieldset>
    </div>
</form>

<?php if ($daftar['rows'] === []): ?>
    <section class="ah-card"><div class="ah-card__body">
        <?= $adaFilter
            ? ah_empty('Tidak ada santri yang cocok dengan pencarian', 'Ubah kata kunci atau bersihkan filter di atas.',
                '<a class="btn btn-sm btn-outline-secondary" href="' . master_e($halaman) . '">Bersihkan filter</a>')
            : ah_empty('Belum ada santri aktif untuk ditempatkan', 'Tambahkan santri pada halaman Data Santri, atau aktifkan tahun ajaran terlebih dahulu.',
                '<a class="btn btn-sm btn-primary" href="admin_master_santri.php?action=create">Tambah Santri</a>') ?>
    </div></section>
<?php else: ?>

<form method="post" id="ah-penempatan-massal">
    <?= master_csrf() ?>
    <input type="hidden" name="tahap" value="tinjau">
    <?php foreach ($filters as $nama => $nilai): ?>
        <input type="hidden" name="filter[<?= master_e($nama) ?>]" value="<?= master_e($nilai) ?>">
    <?php endforeach; ?>
    <input type="hidden" name="filter[page]" value="<?= (int) $page ?>">

    <section class="ah-card" aria-labelledby="ah-daftar-penempatan">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-daftar-penempatan">Daftar santri</h2>
            <span class="text-muted small"><?= count($daftar['rows']) ?> dari <?= (int) $daftar['total'] ?> santri</span></div>
        <div class="ah-table-wrap"><table class="ah-table" id="tabel-penempatan">
            <caption class="ah-visually-hidden">Santri aktif beserta kelas dan kamar pada semester aktif</caption>
            <thead><tr>
                <th scope="col"><input class="form-check-input" type="checkbox" id="ah-pilih-semua" aria-label="Pilih semua santri pada halaman ini"></th>
                <th scope="col">NIS</th>
                <th scope="col">Nama santri</th>
                <th scope="col">L/P</th>
                <th scope="col">Unit sekolah</th>
                <th scope="col">Kelas saat ini</th>
                <th scope="col">Kamar saat ini</th>
                <th scope="col">Penempatan cepat</th>
            </tr></thead>
            <tbody>
            <?php foreach ($daftar['rows'] as $row): $sid = (int) $row['id']; ?>
                <tr>
                    <td><input class="form-check-input ah-pilih-santri" type="checkbox" name="santri_ids[]" value="<?= $sid ?>"
                               aria-label="Pilih <?= master_e($row['nama_santri']) ?>"></td>
                    <td><?= master_e($row['nis']) ?></td>
                    <td class="fw-semibold"><?= master_e($row['nama_santri']) ?>
                        <?php if ((int) $row['jumlah_kamar'] > 1): ?><br><?= ah_badge('Konflik: ' . (int) $row['jumlah_kamar'] . ' kamar', 'danger') ?><?php endif; ?></td>
                    <td><?= master_e($row['jenis_kelamin']) ?></td>
                    <td><?= master_e($row['sekolah_saat_ini'] ?: '-') ?></td>
                    <td><?= $row['nama_kelas'] === null ? ah_badge('Belum ada kelas', 'warn') : master_e($row['nama_kelas']) ?></td>
                    <td><?php
                        if ($row['nama_kamar'] !== null) {
                            echo master_e($row['nama_kamar']);
                        } elseif ($row['id_kamar'] !== null) {
                            // Baris penempatan ada tetapi kamarnya tidak ditemukan:
                            // konflik data, bukan "belum ditempatkan".
                            echo ah_badge('Kamar #' . (int) $row['id_kamar'] . ' tidak ditemukan', 'danger');
                        } else {
                            echo ah_badge('Belum ada kamar', 'warn');
                        }
                    ?></td>
                    <td><div class="pn-cepat">
                        <?php /* Nama aksesibel memakai aria-label, bukan elemen tersembunyi:
                                 elemen berposisi absolut di dalam tabel lebar dapat lolos dari
                                 wadah bergulir dan membuat halaman menggeser horizontal. */ ?>
                        <div class="pn-cepat__baris">
                            <select class="form-select form-select-sm" id="kelas-<?= $sid ?>" name="kelas_id" form="baris-kelas-<?= $sid ?>" required
                                    aria-label="Tempatkan <?= master_e($row['nama_santri']) ?> ke kelas">
                                <option value="">Pilih kelas…</option>
                                <?php foreach ($kelasOptions as $kelas): ?>
                                    <option value="<?= (int) $kelas['id'] ?>" <?= (int) $row['id_kelas'] === (int) $kelas['id'] ? 'selected' : '' ?>><?= master_e($kelas['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline-primary" type="submit" form="baris-kelas-<?= $sid ?>">Simpan kelas</button>
                        </div>
                        <div class="pn-cepat__baris">
                            <select class="form-select form-select-sm" id="kamar-<?= $sid ?>" name="kamar_id" form="baris-kamar-<?= $sid ?>" required
                                    aria-label="Tempatkan <?= master_e($row['nama_santri']) ?> ke kamar">
                                <option value="">Pilih kamar…</option>
                                <?php foreach ($kamarOptions as $kamar): ?>
                                    <option value="<?= (int) $kamar['id'] ?>" <?= (int) $row['id_kamar'] === (int) $kamar['id'] ? 'selected' : '' ?>
                                            <?= penempatan_kamar_nonaktif($kamar) ? 'disabled' : '' ?>>
                                        <?= master_e(penempatan_label_kamar($kamar)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline-primary" type="submit" form="baris-kamar-<?= $sid ?>">Simpan kamar</button>
                        </div>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <section class="ah-card" aria-labelledby="ah-massal">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-massal">Penempatan beberapa santri sekaligus</h2></div>
        <div class="ah-card__body">
            <p class="mb-3" id="ah-terpilih" role="status">
                <span data-jumlah-terpilih>0</span> santri terpilih pada halaman ini.
                Centang hanya berlaku untuk baris yang terlihat; santri pada halaman lain tidak pernah ikut terpilih.
            </p>
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="massal_kelas">Tempatkan ke kelas</label>
                    <select class="form-select" id="massal_kelas" name="kelas_id">
                        <option value="">Pilih kelas…</option>
                        <?php foreach ($kelasOptions as $kelas): ?>
                            <option value="<?= (int) $kelas['id'] ?>"><?= master_e($kelas['nama_kelas'] . ' (' . $kelas['jenjang'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="massal_tanggal">Tanggal mulai kelas</label>
                    <input class="form-control" type="date" id="massal_tanggal" name="tanggal_mulai" value="<?= master_e(date('Y-m-d')) ?>">
                </div>
                <div class="col-md-5 ah-actions">
                    <button class="btn btn-primary" type="submit" name="action" value="<?= PenempatanService::AKSI_KELAS_TETAPKAN ?>">Tinjau penempatan kelas</button>
                    <button class="btn btn-outline-danger" type="submit" name="action" value="<?= PenempatanService::AKSI_KELAS_KELUARKAN ?>">Keluarkan dari kelas…</button>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="massal_kamar">Tempatkan ke kamar</label>
                    <select class="form-select" id="massal_kamar" name="kamar_id">
                        <option value="">Pilih kamar…</option>
                        <?php foreach ($kamarOptions as $kamar): ?>
                            <option value="<?= (int) $kamar['id'] ?>" <?= penempatan_kamar_nonaktif($kamar) ? 'disabled' : '' ?>>
                                <?= master_e(penempatan_label_kamar($kamar)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8 ah-actions">
                    <button class="btn btn-primary" type="submit" name="action" value="<?= PenempatanService::AKSI_KAMAR_TETAPKAN ?>">Tinjau penempatan kamar</button>
                    <button class="btn btn-outline-danger" type="submit" name="action" value="<?= PenempatanService::AKSI_KAMAR_KELUARKAN ?>">Keluarkan dari kamar…</button>
                </div>
            </div>
            <p class="text-muted small mb-0 mt-3">
                Setiap tombol membuka layar konfirmasi lebih dahulu. Perubahan baru tersimpan setelah Anda menekan
                “Terapkan perubahan”, dan seluruh santri berubah bersama-sama: bila satu gagal, tidak ada yang tersimpan.
            </p>
        </div>
    </section>
</form>

<?php /* Formulir baris tabel. Dipisahkan supaya tidak ada formulir bersarang;
         kontrolnya dihubungkan lewat atribut form= sehingga tetap berfungsi
         tanpa JavaScript. */ ?>
<div hidden>
    <?php foreach ($daftar['rows'] as $row): $sid = (int) $row['id']; ?>
        <?php foreach (['kelas' => PenempatanService::AKSI_KELAS_TETAPKAN, 'kamar' => PenempatanService::AKSI_KAMAR_TETAPKAN] as $jenis => $aksiCepat): ?>
            <form method="post" id="baris-<?= $jenis ?>-<?= $sid ?>">
                <?= master_csrf() ?>
                <input type="hidden" name="action" value="<?= master_e($aksiCepat) ?>">
                <input type="hidden" name="tahap" value="langsung">
                <input type="hidden" name="santri_ids[]" value="<?= $sid ?>">
                <?php if ($jenis === 'kelas'): ?><input type="hidden" name="tanggal_mulai" value="<?= master_e(date('Y-m-d')) ?>"><?php endif; ?>
                <?php foreach ($filters as $nama => $nilai): ?>
                    <input type="hidden" name="filter[<?= master_e($nama) ?>]" value="<?= master_e($nilai) ?>">
                <?php endforeach; ?>
                <input type="hidden" name="filter[page]" value="<?= (int) $page ?>">
            </form>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>

<?php master_pagination((int) $daftar['total'], $page, (int) $daftar['perPage']); ?>

<script>
// Bantuan tampilan saja. Seluruh keputusan keamanan, kapasitas, dan validasi
// tetap dilakukan server; halaman ini bekerja penuh tanpa JavaScript.
(function () {
    var semua = document.getElementById('ah-pilih-semua');
    var kotak = Array.prototype.slice.call(document.querySelectorAll('.ah-pilih-santri'));
    var jumlah = document.querySelector('[data-jumlah-terpilih]');
    if (!kotak.length || !jumlah) { return; }
    function perbarui() {
        var terpilih = kotak.filter(function (k) { return k.checked; }).length;
        jumlah.textContent = String(terpilih);
        if (semua) { semua.checked = terpilih === kotak.length && terpilih > 0; }
    }
    if (semua) {
        semua.addEventListener('change', function () {
            kotak.forEach(function (k) { k.checked = semua.checked; });
            perbarui();
        });
    }
    kotak.forEach(function (k) { k.addEventListener('change', perbarui); });
    perbarui();
})();
</script>
<?php endif; ?>
<?php master_footer(); ?>
