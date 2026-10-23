<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;
use App\MasterData\PenempatanConflictException;
use App\MasterData\PenempatanService;

/**
 * Data santri › Penempatan kelas & kamar (redesain website Tahap 6, D-5).
 *
 * Alur: centang santri → bilah bawah → pilih kelas/kamar tujuan → tinjau
 * sebelum–sesudah → terapkan. Satu santri lewat ⋯ › Ubah penempatan (panel).
 * Keputusan AE: bila jenis kelamin santri tidak sesuai asrama kamar tujuan,
 * langkah tinjau menampilkan peringatan per santri; penempatan hanya dapat
 * dilanjutkan dengan "Saya mengerti" + alasan, yang diperiksa ULANG oleh
 * `PenempatanService::apply()` dan dicatat di audit.
 *
 * Halaman ini bekerja penuh tanpa JavaScript: bilah pilihan tetap tampil,
 * panel menjadi bagian halaman, dan setiap langkah adalah formulir server.
 *
 * Halaman ini menggantikan tampilan lama `admin/admin_santri.php` (alamat lama
 * tetap mengalihkan) dan kartu "Tempatkan santri" di halaman Kelas.
 *
 * Keamanan:
 *   - hanya admin (`_guard.php` → `requireWebRole('admin')`);
 *   - seluruh mutasi WAJIB POST; `?action=` lewat GET ditolak 405;
 *   - CSRF diperiksa `_guard.php` untuk setiap POST;
 *   - tidak ada nilai GET/POST yang masuk ke SQL; entitas divalidasi ulang di
 *     server (dropdown bukan validasi);
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
    exit('Perubahan penempatan hanya dapat dikirim melalui POST dari halaman Penempatan.');
}

$tinjauan = null;
$tujuan = null;
$galat = null;
$opsi = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $tahap = is_string($_POST['tahap'] ?? null) ? $_POST['tahap'] : '';
    $santriIds = is_array($_POST['santri_ids'] ?? null) ? $_POST['santri_ids'] : [];
    $opsi = [
        'kelas_id' => $_POST['kelas_id'] ?? null,
        'kamar_id' => $_POST['kamar_id'] ?? null,
        'tanggal_mulai' => $_POST['tanggal_mulai'] ?? '',
        'alasan' => $_POST['alasan'] ?? '',
        'konfirmasi_asrama' => $_POST['konfirmasi_asrama'] ?? '',
        'alasan_asrama' => $_POST['alasan_asrama'] ?? '',
    ];

    try {
        if (!in_array($aksi, PenempatanService::AKSI, true)) {
            throw new MasterDataException('Tindakan penempatan tidak dikenal.');
        }
        if (!in_array($tahap, ['tujuan', 'tinjau', 'terapkan', 'langsung'], true)) {
            throw new MasterDataException('Tahap penempatan tidak dikenal.');
        }

        // Langkah "pilih tujuan" hanya untuk menempatkan; mengeluarkan
        // langsung ke tinjauan beralasan.
        if ($tahap === 'tujuan' && in_array($aksi, [PenempatanService::AKSI_KELAS_KELUARKAN, PenempatanService::AKSI_KAMAR_KELUARKAN], true)) {
            $tahap = 'tinjau';
        }
        if ($tahap === 'tujuan') {
            $tujuan = ['aksi' => $aksi, 'santri' => array_values($service->previewSantri($santriIds))];
        } elseif ($tahap === 'tinjau') {
            $tinjauan = $service->preview($aksi, $santriIds, $opsi);
            $tinjauan['token'] = ah_form_token('penempatan');
        } else {
            if ($tahap === 'langsung') {
                // Jalur cepat lama: hanya untuk SATU santri dan hanya untuk
                // penempatan. Aturan asrama tetap ditegakkan layanan.
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
        // Konfirmasi asrama yang belum lengkap: tampilkan lagi tinjauannya.
        if (in_array($tahap ?? '', ['terapkan', 'langsung'], true) && str_starts_with($galat, 'Penempatan tidak sesuai asrama')) {
            try {
                $tinjauan = $service->preview($aksi, $santriIds, $opsi);
                $tinjauan['token'] = ah_form_token('penempatan');
                $galat = 'Centang “Saya mengerti” dan isi alasan (minimal 5 karakter) untuk santri yang tidak sesuai asrama, atau hapus mereka dari pilihan.';
            } catch (MasterDataException) {
                // Pesan asli tetap ditampilkan.
            }
        }
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
        ? (int) $hasil['diterapkan'] . ' santri dikeluarkan dari ' . $jenis . ' pada semester aktif.'
        : (int) $hasil['diterapkan'] . ' santri ditempatkan di ' . $jenis . ' ' . $target . '.';
    if ((int) $hasil['tidak_berubah'] > 0) {
        $pesan .= ' ' . (int) $hasil['tidak_berubah'] . ' santri sudah berada pada keadaan itu sehingga tidak diubah.';
    }

    return $pesan . ' Riwayat semester sebelumnya tidak disentuh.';
}

/**
 * @param array<string, mixed> $kamar
 */
function penempatan_label_kamar(array $kamar): string
{
    $kapasitas = (int) $kamar['kapasitas'];
    $terisi = (int) $kamar['terisi'];
    $asrama = in_array($kamar['asrama'] ?? null, ['Putra', 'Putri'], true) ? ' · ' . $kamar['asrama'] : '';
    if ($kapasitas < 1) {
        return $kamar['nama_kamar'] . $asrama . ' (' . $terisi . ' penghuni — kapasitas belum diatur)';
    }

    return $kamar['nama_kamar'] . $asrama . ' (' . $terisi . '/' . $kapasitas
        . ($terisi >= $kapasitas ? ' — penuh' : ' — sisa ' . ($kapasitas - $terisi)) . ')';
}

/** Nilai tersembunyi filter + halaman, agar tindakan kembali ke daftar yang sama. */
$filterHidden = static function () use ($filters, $page): string {
    $html = '';
    foreach ($filters as $nama => $nilai) {
        $html .= '<input type="hidden" name="filter[' . ah_e($nama) . ']" value="' . ah_e($nilai) . '">';
    }

    return $html . '<input type="hidden" name="filter[page]" value="' . (int) $page . '">';
};

$year = $service->activeYear();
$yearId = $year === null ? 0 : (int) $year['id'];
$kelasOptions = $service->classOptions();
$kamarOptions = $yearId > 0 ? $service->roomOptions($yearId) : [];
$sekolahOptions = $service->schoolOptions();
$kembali = $halaman . '?' . ah_query([], $filters);

master_header('Data santri', [
    'active' => 'santri',
    'heading' => 'Data santri',
    'description' => $year === null ? 'Belum ada semester aktif.' : 'Penempatan semester aktif: ' . $year['semester'] . ' ' . $year['tahun'] . '. Riwayat semester sebelumnya tidak pernah diubah.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Data santri', 'url' => app_url('/admin/admin_master_santri.php')], ['label' => 'Penempatan']],
]);

if ($galat !== null) {
    ah_note('danger', $galat);
}
if ($year === null) {
    ah_note('warning', 'Belum ada tahun ajaran aktif. Aktifkan satu semester di Pengaturan › Tahun ajaran sebelum menempatkan santri.');
}

// ---------------------------------------------------------------------------
// Langkah: pilih tujuan
// ---------------------------------------------------------------------------
if ($tujuan !== null):
    $keKelas = $tujuan['aksi'] === PenempatanService::AKSI_KELAS_TETAPKAN;
    $nama = array_map(static fn (array $s): string => (string) $s['nama_santri'], $tujuan['santri']);
    echo ah_stepper(['Pilih santri', 'Pilih tujuan', 'Tinjau & terapkan'], 1, 'Langkah penempatan'); ?>
    <section class="ah-card" aria-labelledby="ah-tujuan">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-tujuan"><?= $keKelas ? 'Tempatkan ke kelas' : 'Tempatkan ke kamar' ?></h2></div>
        <div class="ah-card__body">
            <p><?= count($nama) ?> santri dipilih: <?= ah_e(implode(', ', array_slice($nama, 0, 8))) ?><?= count($nama) > 8 ? ' dan ' . (count($nama) - 8) . ' lainnya' : '' ?>.</p>
            <form method="post" class="row g-3 align-items-end">
                <?= master_csrf() ?><?= $filterHidden() ?>
                <input type="hidden" name="tahap" value="tinjau">
                <input type="hidden" name="action" value="<?= master_e($tujuan['aksi']) ?>">
                <?php foreach ($tujuan['santri'] as $s): ?><input type="hidden" name="santri_ids[]" value="<?= (int) $s['id'] ?>"><?php endforeach; ?>
                <?php if ($keKelas): ?>
                    <div class="col-md-6"><label class="form-label" for="tujuan_kelas">Kelas tujuan</label>
                        <select class="form-select" id="tujuan_kelas" name="kelas_id" required><option value="">Pilih kelas…</option>
                            <?php foreach ($kelasOptions as $kelas): ?><option value="<?= (int) $kelas['id'] ?>"><?= master_e($kelas['nama_kelas'] . ' (' . $kelas['jenjang'] . ')') ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="col-md-3"><label class="form-label" for="tujuan_tanggal">Tanggal mulai</label>
                        <input class="form-control" type="date" id="tujuan_tanggal" name="tanggal_mulai" value="<?= master_e(date('Y-m-d')) ?>"></div>
                <?php else: ?>
                    <div class="col-md-8"><label class="form-label" for="tujuan_kamar">Kamar/kobong tujuan</label>
                        <select class="form-select" id="tujuan_kamar" name="kamar_id" required><option value="">Pilih kamar…</option>
                            <?php foreach ($kamarOptions as $kamar): ?><option value="<?= (int) $kamar['id'] ?>" <?= (int) $kamar['kapasitas'] < 1 ? 'disabled' : '' ?>><?= master_e(penempatan_label_kamar($kamar)) ?></option><?php endforeach; ?>
                        </select></div>
                <?php endif; ?>
                <div class="col-12 ah-actbar mb-0">
                    <a class="btn btn-outline-secondary" href="<?= master_e($kembali) ?>">Batal</a>
                    <button class="btn btn-primary" type="submit">Tinjau perubahan</button>
                </div>
            </form>
        </div>
    </section>
    <?php
    master_footer();
    exit;
endif;

// ---------------------------------------------------------------------------
// Langkah: tinjau sebelum–sesudah
// ---------------------------------------------------------------------------
if ($tinjauan !== null):
    $peringatan = $tinjauan['peringatan'] ?? [];
    $sisaIds = array_map(static fn (array $b): int => (int) $b['santri_id'], $tinjauan['baris']);
    echo ah_stepper(['Pilih santri', 'Pilih tujuan', 'Tinjau & terapkan'], 2, 'Langkah penempatan'); ?>
    <section class="ah-card" aria-labelledby="ah-tinjauan">
        <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-tinjauan">Tinjau perubahan penempatan</h2>
            <span class="text-muted small"><?= (int) $tinjauan['jumlah'] ?> santri</span></div>
        <div class="ah-card__body">
            <p class="mb-2"><?php if ($tinjauan['target'] === null): ?>
                Tindakan: <strong>mengeluarkan dari <?= master_e($tinjauan['jenis']) ?></strong> pada semester aktif.
            <?php else: ?>
                Tujuan: <strong><?= master_e($tinjauan['target']['nama']) ?></strong><?= !empty($tinjauan['target']['asrama']) ? ' (asrama ' . master_e(mb_strtolower($tinjauan['target']['asrama'])) . ')' : '' ?>.
            <?php endif; ?></p>
            <?php if ($tinjauan['kapasitas'] !== null): $k = $tinjauan['kapasitas']; ?>
                <p class="mb-2">Kapasitas <?= master_e($k['nama_kamar']) ?>: terisi <?= (int) $k['terisi'] ?> dari <?= (int) $k['kapasitas'] ?>,
                    sisa <?= max(0, (int) $k['sisa']) ?>, ditambah <?= (int) $k['tambahan'] ?> santri.
                    <?= $k['cukup'] ? ah_badge('Kapasitas cukup', 'ok') : ah_badge('Kapasitas tidak cukup', 'danger') ?></p>
            <?php endif; ?>
            <?php if ($tinjauan['masalah'] !== []): ?>
                <?php ah_note('danger', 'Perubahan tidak dapat diterapkan.', '<ul class="mb-0 mt-2"><li>' . implode('</li><li>', array_map('master_e', $tinjauan['masalah'])) . '</li></ul>'); ?>
            <?php endif; ?>
            <?php if ($peringatan !== []): ?>
                <?php ah_note('warning', 'Tidak sesuai asrama kamar tujuan:', '<ul class="mb-0 mt-2"><li>' . implode('</li><li>', array_map('master_e', $peringatan)) . '</li></ul>'
                    . '<p class="small mb-0 mt-2">Hapus santri itu dari pilihan, atau centang “Saya mengerti” dan isi alasan untuk tetap menempatkan. Alasan tercatat di audit.</p>'); ?>
            <?php endif; ?>
        </div>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards">
            <caption class="ah-visually-hidden">Rincian perubahan penempatan yang akan diterapkan</caption>
            <thead><tr><th scope="col">Santri</th><th scope="col">Sebelum</th><th scope="col">Sesudah</th><th scope="col">Perubahan</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
            <tbody>
            <?php foreach ($tinjauan['baris'] as $baris): ?>
                <tr>
                    <td><span class="ah-cell-wrap"><b><?= master_e($baris['nama_santri']) ?></b><span class="ah-cell-sub">NIS <?= master_e($baris['nis']) ?></span></span></td>
                    <td><?= master_e($baris['sebelum'] ?? 'belum ditempatkan') ?></td>
                    <td><?= master_e($baris['sesudah'] ?? 'tanpa ' . $tinjauan['jenis']) ?><?= !empty($baris['asrama_tidak_sesuai']) ? ' ' . ah_badge('Tidak sesuai asrama', 'warn') : '' ?></td>
                    <td><?= master_e(match ($baris['perubahan']) {
                        'masuk' => 'Ditempatkan',
                        'pindah' => 'Dipindahkan',
                        'keluar' => 'Dikeluarkan',
                        'tetap' => 'Tidak berubah',
                        default => 'Tidak berubah',
                    }) ?></td>
                    <td data-label=""><?php if (count($sisaIds) > 1): ?>
                        <form method="post" class="d-inline"><?= master_csrf() ?><?= $filterHidden() ?>
                            <input type="hidden" name="tahap" value="tinjau"><input type="hidden" name="action" value="<?= master_e($tinjauan['aksi']) ?>">
                            <?php if ($tinjauan['target'] !== null): ?><input type="hidden" name="<?= $tinjauan['jenis'] === 'kelas' ? 'kelas_id' : 'kamar_id' ?>" value="<?= (int) $tinjauan['target']['id'] ?>"><?php endif; ?>
                            <input type="hidden" name="tanggal_mulai" value="<?= master_e($tinjauan['tanggal_mulai']) ?>">
                            <?php foreach ($sisaIds as $sid): if ($sid === (int) $baris['santri_id']) { continue; } ?><input type="hidden" name="santri_ids[]" value="<?= $sid ?>"><?php endforeach; ?>
                            <button class="btn btn-sm btn-link" type="submit">Hapus dari pilihan</button>
                        </form>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="ah-card__body">
            <form method="post" data-confirm="Terapkan perubahan penempatan ini? Seluruh santri berubah bersama-sama; bila satu gagal, tidak ada yang tersimpan.">
                <?= master_csrf() ?><?= $filterHidden() ?>
                <input type="hidden" name="action" value="<?= master_e($tinjauan['aksi']) ?>">
                <input type="hidden" name="tahap" value="terapkan">
                <input type="hidden" name="form_token" value="<?= master_e($tinjauan['token']) ?>">
                <?php if ($tinjauan['target'] !== null): ?>
                    <input type="hidden" name="<?= $tinjauan['jenis'] === 'kelas' ? 'kelas_id' : 'kamar_id' ?>" value="<?= (int) $tinjauan['target']['id'] ?>">
                <?php endif; ?>
                <?php if ($tinjauan['jenis'] === 'kelas'): ?><input type="hidden" name="tanggal_mulai" value="<?= master_e($tinjauan['tanggal_mulai']) ?>"><?php endif; ?>
                <?php foreach ($sisaIds as $sid): ?><input type="hidden" name="santri_ids[]" value="<?= $sid ?>"><?php endforeach; ?>
                <?php if ($tinjauan['target'] === null): ?>
                    <div class="mb-3">
                        <label class="form-label" for="alasan">Alasan mengeluarkan santri <span class="text-danger" aria-hidden="true">*</span></label>
                        <textarea class="form-control" id="alasan" name="alasan" rows="2" maxlength="500" required aria-describedby="bantuan_alasan"><?= master_e(is_string($_POST['alasan'] ?? null) ? $_POST['alasan'] : '') ?></textarea>
                        <div class="form-text" id="bantuan_alasan">Alasan wajib diisi dan ikut tercatat pada audit.</div>
                    </div>
                <?php endif; ?>
                <?php if ($peringatan !== []): ?>
                    <div class="ah-danger-zone mb-3">
                        <label class="d-inline-flex align-items-start gap-2 mb-2">
                            <input type="checkbox" name="konfirmasi_asrama" value="1" class="mt-1" required>
                            <span>Saya mengerti <?= count($peringatan) ?> santri akan ditempatkan di kamar yang tidak sesuai asramanya.</span>
                        </label>
                        <label class="form-label" for="alasan_asrama">Alasan <span class="text-danger" aria-hidden="true">*</span></label>
                        <input class="form-control" id="alasan_asrama" name="alasan_asrama" minlength="5" maxlength="500" required value="<?= master_e(is_string($_POST['alasan_asrama'] ?? null) ? $_POST['alasan_asrama'] : '') ?>">
                    </div>
                <?php endif; ?>
                <div class="ah-actbar mb-0">
                    <a class="btn btn-outline-secondary" href="<?= master_e($kembali) ?>">Batal</a>
                    <button class="btn btn-primary" type="submit" <?= $tinjauan['masalah'] === [] ? '' : 'disabled' ?>>Terapkan perubahan</button>
                </div>
            </form>
        </div>
    </section>
    <?php
    master_footer();
    exit;
endif;

// ---------------------------------------------------------------------------
// Daftar + panel "Ubah penempatan" satu santri
// ---------------------------------------------------------------------------
$daftar = $yearId > 0
    ? $service->listPage($filters, $yearId, $page)
    : ['rows' => [], 'total' => 0, 'page' => 1, 'perPage' => 20];
$page = (int) $daftar['page'];
$ringkasan = $yearId > 0
    ? $service->summary($yearId)
    : ['santri_aktif' => 0, 'tanpa_kelas' => 0, 'tanpa_kamar' => 0, 'nonaktif_berkamar' => 0];
$adaFilter = array_filter($filters, static fn (mixed $value): bool => $value !== '' && $value !== 0) !== [];
$saringan = (int) ($filters['q'] !== '') + (int) ($filters['kelas_id'] > 0) + (int) ($filters['kamar_id'] > 0) + (int) ($filters['status'] !== '') + (int) ($filters['jk'] !== '') + (int) ($filters['sekolah'] !== '');

echo ah_stats([
    ['label' => 'Santri aktif', 'value' => (string) (int) $ringkasan['santri_aktif'], 'url' => $halaman],
    ['label' => 'Tanpa kelas', 'value' => (string) (int) $ringkasan['tanpa_kelas'], 'url' => $halaman . '?status=tanpa_kelas', 'warn' => (int) $ringkasan['tanpa_kelas'] > 0],
    ['label' => 'Tanpa kamar', 'value' => (string) (int) $ringkasan['tanpa_kamar'], 'url' => $halaman . '?status=tanpa_kamar', 'warn' => (int) $ringkasan['tanpa_kamar'] > 0],
    ['label' => 'Nonaktif tapi berkamar', 'value' => (string) (int) $ringkasan['nonaktif_berkamar'], 'url' => $halaman . '?status=nonaktif_berkamar', 'warn' => (int) $ringkasan['nonaktif_berkamar'] > 0],
], 'Ringkasan penempatan semester aktif');

if ($filters['status'] === 'nonaktif_berkamar') {
    ah_note('warning', 'Santri nonaktif atau arsip yang masih menempati kamar pada semester aktif.',
        '<p class="small mb-0 mt-2">Mereka tidak dapat ditempatkan baru, tetapi dapat dikeluarkan agar tempatnya tersedia: centang lalu pilih “Keluarkan dari kamar”.</p>');
}

$ubahId = max(0, (int) ($_GET['ubah'] ?? 0));
if ($ubahId > 0 && $yearId > 0):
    $ubah = $service->rowFor($ubahId, $yearId);
    if ($ubah !== null):
        $tutup = $halaman . '?' . ah_query(['ubah' => null], $filters + ['page' => $page]);
        ah_panel_open(['title' => 'Ubah penempatan', 'close_url' => $tutup, 'description' => $ubah['nama_santri'] . ' · NIS ' . $ubah['nis'] . ' · ' . ($ubah['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan')]); ?>
        <dl class="ah-kv mb-3"><div><dt>Kelas sekarang</dt><dd><?= master_e($ubah['nama_kelas'] ?? 'Belum ada') ?></dd></div>
            <div><dt>Kamar sekarang</dt><dd><?= master_e($ubah['nama_kamar'] ?? 'Belum ada') ?></dd></div></dl>
        <form method="post" class="mb-3"><?= master_csrf() ?><?= $filterHidden() ?>
            <input type="hidden" name="tahap" value="tinjau"><input type="hidden" name="action" value="<?= PenempatanService::AKSI_KELAS_TETAPKAN ?>">
            <input type="hidden" name="santri_ids[]" value="<?= $ubahId ?>">
            <label class="form-label" for="ubah_kelas">Kelas</label>
            <select class="form-select mb-2" id="ubah_kelas" name="kelas_id" required><option value="">Pilih kelas…</option>
                <?php foreach ($kelasOptions as $kelas): ?><option value="<?= (int) $kelas['id'] ?>" <?= (int) ($ubah['id_kelas'] ?? 0) === (int) $kelas['id'] ? 'selected' : '' ?>><?= master_e($kelas['nama_kelas'] . ' (' . $kelas['jenjang'] . ')') ?></option><?php endforeach; ?>
            </select>
            <label class="form-label" for="ubah_tanggal">Tanggal mulai</label>
            <input class="form-control mb-2" type="date" id="ubah_tanggal" name="tanggal_mulai" value="<?= master_e(date('Y-m-d')) ?>">
            <button class="btn btn-outline-primary" type="submit">Tinjau penempatan kelas</button>
        </form>
        <form method="post"><?= master_csrf() ?><?= $filterHidden() ?>
            <input type="hidden" name="tahap" value="tinjau"><input type="hidden" name="action" value="<?= PenempatanService::AKSI_KAMAR_TETAPKAN ?>">
            <input type="hidden" name="santri_ids[]" value="<?= $ubahId ?>">
            <label class="form-label" for="ubah_kamar">Kamar/kobong</label>
            <select class="form-select mb-2" id="ubah_kamar" name="kamar_id" required><option value="">Pilih kamar…</option>
                <?php foreach ($kamarOptions as $kamar): ?><option value="<?= (int) $kamar['id'] ?>" <?= (int) ($ubah['id_kamar'] ?? 0) === (int) $kamar['id'] ? 'selected' : '' ?> <?= (int) $kamar['kapasitas'] < 1 ? 'disabled' : '' ?>><?= master_e(penempatan_label_kamar($kamar)) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-outline-primary" type="submit">Tinjau penempatan kamar</button>
        </form>
        <?php ah_panel_close();
    endif;
endif;

ah_filter_open($saringan); ?>
    <div class="ah-filter__wide"><label class="form-label" for="q">Cari nama atau NIS</label>
        <input class="form-control" type="search" id="q" name="q" maxlength="100" value="<?= master_e($filters['q']) ?>"></div>
    <div><label class="form-label" for="filter_kelas">Kelas</label>
        <select class="form-select" id="filter_kelas" name="kelas_id"><option value="">Semua kelas</option>
            <?php foreach ($kelasOptions as $kelas): ?><option value="<?= (int) $kelas['id'] ?>" <?= (int) $filters['kelas_id'] === (int) $kelas['id'] ? 'selected' : '' ?>><?= master_e($kelas['nama_kelas']) ?></option><?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="filter_kamar">Kamar</label>
        <select class="form-select" id="filter_kamar" name="kamar_id"><option value="">Semua kamar</option>
            <?php foreach ($kamarOptions as $kamar): ?><option value="<?= (int) $kamar['id'] ?>" <?= (int) $filters['kamar_id'] === (int) $kamar['id'] ? 'selected' : '' ?>><?= master_e($kamar['nama_kamar']) ?></option><?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="">Semua santri</option>
            <option value="tanpa_kelas" <?= $filters['status'] === 'tanpa_kelas' ? 'selected' : '' ?>>Tanpa kelas</option>
            <option value="tanpa_kamar" <?= $filters['status'] === 'tanpa_kamar' ? 'selected' : '' ?>>Tanpa kamar</option>
            <option value="tanpa_keduanya" <?= $filters['status'] === 'tanpa_keduanya' ? 'selected' : '' ?>>Tanpa kelas dan kamar</option>
            <option value="tanpa_tempat" <?= $filters['status'] === 'tanpa_tempat' ? 'selected' : '' ?>>Tanpa kelas atau kamar</option>
            <option value="nonaktif_berkamar" <?= $filters['status'] === 'nonaktif_berkamar' ? 'selected' : '' ?>>Nonaktif/arsip tapi berkamar</option>
        </select></div>
    <details class="ah-filter__more"<?= $filters['jk'] !== '' || $filters['sekolah'] !== '' ? ' open' : '' ?>><summary>Filter lanjutan</summary><div class="ah-filter__more-body">
        <div><label class="form-label" for="jk">L/P</label>
            <select class="form-select" id="jk" name="jk"><option value="">Semua</option>
                <option value="L" <?= $filters['jk'] === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                <option value="P" <?= $filters['jk'] === 'P' ? 'selected' : '' ?>>Perempuan</option>
            </select></div>
        <div><label class="form-label" for="sekolah">Unit sekolah</label>
            <select class="form-select" id="sekolah" name="sekolah"><option value="">Semua unit</option>
                <?php foreach ($sekolahOptions as $sekolah): ?><option value="<?= master_e($sekolah) ?>" <?= $filters['sekolah'] === $sekolah ? 'selected' : '' ?>><?= master_e($sekolah) ?></option><?php endforeach; ?>
            </select></div>
    </div></details>
<?php ah_filter_close();

if ($daftar['rows'] === []): ?>
    <section class="ah-card"><div class="ah-card__body">
        <?= $adaFilter
            ? ah_empty('Tidak ada santri yang cocok', 'Ubah kata kunci atau bersihkan saringan.', '<a class="btn btn-sm btn-outline-secondary" href="' . master_e($halaman) . '">Bersihkan saringan</a>')
            : ah_empty('Belum ada santri aktif untuk ditempatkan', 'Tambahkan santri di Data santri, atau aktifkan tahun ajaran terlebih dahulu.', '<a class="btn btn-sm btn-primary" href="admin_master_santri.php?action=create">Tambah santri</a>') ?>
    </div></section>
<?php else: ?>
<form method="post" data-ah-selection="santri">
    <?= master_csrf() ?><?= $filterHidden() ?>
    <p class="small text-muted mb-2">Centang hanya berlaku untuk baris yang terlihat; santri pada halaman lain tidak pernah ikut terpilih.</p>
    <div class="ah-card ah-table-wrap">
        <table class="ah-table ah-table--cards" id="tabel-penempatan">
            <caption class="ah-visually-hidden">Santri beserta kelas dan kamar semester aktif, <?= (int) $daftar['total'] ?> data</caption>
            <thead><tr>
                <th scope="col"><input class="form-check-input" type="checkbox" data-ah-select-all aria-label="Pilih semua santri pada halaman ini"></th>
                <th scope="col">NIS</th><th scope="col">Nama</th><th scope="col">L/P</th><th scope="col" class="ah-col-opt">Unit</th>
                <th scope="col">Kelas</th><th scope="col">Kamar</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($daftar['rows'] as $row): $sid = (int) $row['id'];
                $tidakSesuai = PenempatanService::asramaTidakSesuai((string) $row['jenis_kelamin'], $row['asrama'] ?? null); ?>
                <tr>
                    <td data-label="Pilih"><input class="form-check-input" type="checkbox" name="santri_ids[]" value="<?= $sid ?>" data-ah-select aria-label="Pilih <?= master_e($row['nama_santri']) ?>"></td>
                    <td><?= master_e($row['nis']) ?></td>
                    <td><a class="ah-row-link" href="admin_master_santri.php?action=detail&amp;id=<?= $sid ?>"><?= master_e($row['nama_santri']) ?></a>
                        <?php if ((int) $row['jumlah_kamar'] > 1): ?><br><?= ah_badge('Konflik: ' . (int) $row['jumlah_kamar'] . ' kamar', 'danger') ?><?php endif; ?></td>
                    <td><?= master_e($row['jenis_kelamin']) ?></td>
                    <td class="ah-col-opt"><?= master_e($row['sekolah_saat_ini'] ?: '—') ?></td>
                    <td><?= $row['nama_kelas'] === null ? ah_badge('Belum ada kelas', 'warn') : master_e($row['nama_kelas']) ?></td>
                    <td><?php
                        if ($row['nama_kamar'] !== null) {
                            echo master_e($row['nama_kamar']) . ($tidakSesuai ? ' ' . ah_badge('Tidak sesuai asrama', 'warn') : '');
                        } elseif ($row['id_kamar'] !== null) {
                            // Baris penempatan ada tetapi kamarnya tidak ditemukan: konflik data.
                            echo ah_badge('Kamar tidak ditemukan', 'danger');
                        } else {
                            echo ah_badge('Belum ada kamar', 'warn');
                        }
                    ?></td>
                    <td data-label=""><?= ah_menu([
                        ['label' => 'Ubah penempatan', 'url' => $halaman . '?' . ah_query(['ubah' => $sid], $filters + ['page' => $page]) . '#ah-panel', 'icon' => 'fa-pen'],
                        ['label' => 'Profil santri', 'url' => 'admin_master_santri.php?action=detail&id=' . $sid, 'icon' => 'fa-id-card'],
                    ], 'Tindakan untuk ' . $row['nama_santri']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= ah_selection_bar(
        '<button class="btn btn-sm btn-outline-light" type="submit" name="action" value="' . PenempatanService::AKSI_KELAS_KELUARKAN . '">Keluarkan dari kelas</button>'
        . '<button class="btn btn-sm btn-outline-light" type="submit" name="action" value="' . PenempatanService::AKSI_KAMAR_KELUARKAN . '">Keluarkan dari kamar</button>'
        . '<button class="btn btn-sm btn-light" type="submit" name="action" value="' . PenempatanService::AKSI_KAMAR_TETAPKAN . '">Tempatkan ke kamar…</button>'
        . '<button class="btn btn-sm btn-light" type="submit" name="action" value="' . PenempatanService::AKSI_KELAS_TETAPKAN . '">Tempatkan ke kelas…</button>'
    ) ?>
    <input type="hidden" name="tahap" value="tujuan">
</form>
<?php master_pagination((int) $daftar['total'], $page, (int) $daftar['perPage']); ?>
<?php endif; ?>
<?php master_footer(); ?>
