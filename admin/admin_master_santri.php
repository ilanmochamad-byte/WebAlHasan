<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;
use App\MasterData\PhotoStorage;

/**
 * Data Santri (koreksi ke-2, keputusan pengguna 30 Agustus 2026).
 *
 * Perubahan utama: identitas orang tua/wali TIDAK lagi diketik sebagai teks
 * bebas pada kolom lama. Admin memilih wali yang sudah terdaftar, atau membuat
 * wali baru langsung dari formulir ini, dan relasinya disimpan bersama santri
 * dalam satu transaksi. Kolom lama `nama_ayah`/`nama_ibu` dipertahankan sebagai
 * cermin dari identitas yang dikonfirmasi, sehingga hanya ada SATU sumber
 * pengeditan.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

// Menandai bahwa potongan tampilan di bawah dimuat dari halaman ber-guard.
define('AH_PARTIAL', true);

$service = master_data_service();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int) $_POST['id'] : null;
    $action = (string) ($_POST['action'] ?? '');

    // Pengiriman ulang (klik ganda, refresh POST, retry jaringan) memakai token
    // yang sama dan hanya berlaku sekali, sehingga tidak pernah menghasilkan
    // santri, wali, atau relasi ganda.
    if ($action === 'save' && !ah_form_token_consume('santri', $_POST['form_token'] ?? null)) {
        master_flash('warning', 'Formulir ini sudah pernah dikirim. Tidak ada data ganda yang dibuat. Muat ulang halaman bila ingin menyimpan perubahan baru.');
        master_redirect('admin_master_santri.php');
    }

    try {
        if ($action === 'save') {
            if (array_intersect(['nama_ayah', 'no_hp_ayah', 'nama_ibu', 'no_hp_ibu'], array_keys($_POST)) !== []) {
                throw new MasterDataException('Kolom lama hanya dapat diperbarui melalui pemilihan wali dan konfirmasi penggantian.');
            }
            $existing = $id ? $service->santri($id) : null;
            $_POST['foto'] = (new PhotoStorage(APP_ROOT . '/gambar_galeri'))->store($_FILES['foto_upload'] ?? null, (string) ($existing['foto'] ?? 'default.jpg'));
            $savedId = $service->saveSantri($_POST, $id ?: null);
            ah_old_clear('_santri_old');
            master_flash('success', ($id ? 'Perubahan santri' : 'Santri baru') . ' berhasil disimpan beserta relasi walinya. ID santri: ' . $savedId . '.');
            master_redirect('admin_master_santri.php?action=detail&id=' . $savedId);
        }
        $service->setSantriState((int) $id, $action);
        master_flash('success', 'Status santri diperbarui tanpa menghapus riwayat, relasi wali, atau absensi lama.');
    } catch (MasterDataException $exception) {
        $oldInput = $_POST;
        $oldFields = ['nis', 'nama_santri', 'jenis_kelamin', 'tempat_lahir', 'tgl_lahir', 'alamat', 'desa', 'kecamatan', 'kab_kota', 'provinsi', 'asal_sekolah', 'sekolah_saat_ini'];
        // A-04: simpan hanya isian wali yang dikenal; konfirmasi penimpaan
        // tidak dipulihkan otomatis karena admin perlu memeriksanya kembali.
        foreach (['Ayah', 'Ibu', 'Wali'] as $relation) {
            foreach (['mode', 'wali_id', 'nama', 'no_hp', 'alamat'] as $key) {
                $flat = 'wali_' . $relation . '_' . $key;
                $oldInput[$flat] = $_POST['wali'][$relation][$key] ?? '';
                $oldFields[] = $flat;
            }
        }
        ah_validation_keep($oldInput, $oldFields, $exception, '_santri_old');
        master_flash('danger', $exception->getMessage());
        master_redirect('admin_master_santri.php?action=' . ($id ? 'edit&id=' . $id : 'create'));
    }
    master_redirect('admin_master_santri.php');
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = ['q' => $_GET['q'] ?? '', 'state' => $_GET['state'] ?? 'active', 'gender' => $_GET['gender'] ?? '', 'kelas_id' => $_GET['kelas_id'] ?? ''];
$result = $service->santriList($filters, $page);
$selected = isset($_GET['id']) ? $service->santri((int) $_GET['id']) : null;
$history = $selected ? $service->membershipHistory((int) $selected['id']) : [];
$relasiWali = $selected ? $service->santriWali((int) $selected['id'], false) : [];
$classes = $service->classes();
$mode = (string) ($_GET['action'] ?? '');

$daftarWali = [];
$daftarTerpotong = false;
if ($mode === 'create' || ($mode === 'edit' && $selected)) {
    $daftarWali = $service->waliCandidates('', 200);
    $daftarTerpotong = count($daftarWali) >= 200;
}

$relasiAktif = static function (array $relasi, string $hubungan): ?array {
    foreach ($relasi as $baris) {
        if ($baris['archived_at'] === null && (string) $baris['hubungan'] === $hubungan) {
            return $baris;
        }
    }
    return null;
};

master_header('Data Santri', [
    'description' => 'Identitas santri dan hubungan walinya. Relasi wali yang terverifikasi adalah sumber utama identitas orang tua.',
    'active' => 'master.santri',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Master Data'],
        ['label' => 'Data Santri'],
    ],
    'actions' => '<a class="btn btn-outline-primary" href="admin_wali_rekonsiliasi.php">Rekonsiliasi wali</a>'
        . '<a class="btn btn-outline-primary" href="export_master.php?entity=santri&amp;' . master_e(http_build_query($filters)) . '">Ekspor CSV</a>'
        . '<a class="btn btn-primary" href="admin_master_santri.php?action=create">Tambah Santri</a>',
]);
?>

<?php if ($mode === 'create' || ($mode === 'edit' && $selected)): $record = $selected ?? []; ?>
<section class="ah-card" aria-labelledby="ah-form-santri">
    <div class="ah-card__head"><span id="ah-form-santri"><?= $selected ? 'Ubah santri #' . (int) $selected['id'] : 'Tambah santri' ?></span></div>
    <div class="ah-card__body">
    <form method="post" enctype="multipart/form-data">
        <?= master_csrf() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="form_token" value="<?= master_e(ah_form_token('santri')) ?>">
        <?php if ($selected): ?><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><?php endif; ?>

        <fieldset class="ah-fieldset">
            <legend>Identitas santri</legend>
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label" for="nis">NIS</label>
                    <input class="form-control" id="nis" name="nis" maxlength="20" required value="<?= master_e(ah_old('nis', $record, '_santri_old')) ?>"><?= ah_field_error('nis','_santri_old') ?></div>
                <div class="col-md-6"><label class="form-label" for="nama_santri">Nama santri</label>
                    <input class="form-control" id="nama_santri" name="nama_santri" maxlength="100" required value="<?= master_e(ah_old('nama_santri', $record, '_santri_old')) ?>"><?= ah_field_error('nama_santri','_santri_old') ?></div>
                <div class="col-md-3"><label class="form-label" for="jenis_kelamin">Jenis kelamin</label>
                    <select class="form-select" id="jenis_kelamin" name="jenis_kelamin" required>
                        <option value="L" <?= ah_old('jenis_kelamin', $record, '_santri_old') === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                        <option value="P" <?= ah_old('jenis_kelamin', $record, '_santri_old') === 'P' ? 'selected' : '' ?>>Perempuan</option>
                    </select><?= ah_field_error('jenis_kelamin','_santri_old') ?></div>
                <div class="col-md-4"><label class="form-label" for="tempat_lahir">Tempat lahir</label>
                    <input class="form-control" id="tempat_lahir" name="tempat_lahir" maxlength="50" value="<?= master_e(ah_old('tempat_lahir', $record, '_santri_old')) ?>"><?= ah_field_error('tempat_lahir','_santri_old') ?></div>
                <div class="col-md-3"><label class="form-label" for="tgl_lahir">Tanggal lahir</label>
                    <input class="form-control" id="tgl_lahir" type="date" name="tgl_lahir" required value="<?= master_e(ah_old('tgl_lahir', $record, '_santri_old')) ?>"><?= ah_field_error('tgl_lahir','_santri_old') ?></div>
                <div class="col-md-5"><label class="form-label" for="foto_upload">Foto baru <span class="text-muted fw-normal">(opsional, maks. 2 MB)</span></label>
                    <input class="form-control" id="foto_upload" type="file" name="foto_upload" accept="image/jpeg,image/png,image/webp"
                           aria-describedby="bantuan_foto">
                    <div class="form-text" id="bantuan_foto">Foto lama tidak dihapus saat diganti.</div></div>
            </div>
        </fieldset>

        <fieldset class="ah-fieldset">
            <legend>Alamat</legend>
            <div class="row g-3">
                <div class="col-12"><label class="form-label" for="alamat">Alamat</label>
                    <textarea class="form-control" id="alamat" name="alamat" rows="2"><?= master_e(ah_old('alamat', $record, '_santri_old')) ?></textarea><?= ah_field_error('alamat','_santri_old') ?></div>
                <?php foreach (['desa' => 'Desa/Kelurahan', 'kecamatan' => 'Kecamatan', 'kab_kota' => 'Kabupaten/Kota', 'provinsi' => 'Provinsi'] as $name => $label): ?>
                    <div class="col-md-3"><label class="form-label" for="<?= $name ?>"><?= $label ?></label>
                        <input class="form-control" id="<?= $name ?>" name="<?= $name ?>" maxlength="50" value="<?= master_e(ah_old($name, $record, '_santri_old')) ?>"><?= ah_field_error('<?= $name ?>','_santri_old') ?></div>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <fieldset class="ah-fieldset">
            <legend>Sekolah</legend>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label" for="asal_sekolah">Sekolah asal</label>
                    <input class="form-control" id="asal_sekolah" name="asal_sekolah" maxlength="100" value="<?= master_e(ah_old('asal_sekolah', $record, '_santri_old')) ?>"><?= ah_field_error('asal_sekolah','_santri_old') ?></div>
                <div class="col-md-6"><label class="form-label" for="sekolah_saat_ini">Sekolah saat ini</label>
                    <input class="form-control" id="sekolah_saat_ini" name="sekolah_saat_ini" maxlength="50" value="<?= master_e(ah_old('sekolah_saat_ini', $record, '_santri_old')) ?>"><?= ah_field_error('sekolah_saat_ini','_santri_old') ?></div>
            </div>
        </fieldset>

        <h2 class="h6 mt-4">Orang tua dan wali</h2>
        <p class="text-muted small">
            Pilih wali yang sudah terdaftar bila keluarganya sudah ada di sistem — inilah cara menyatukan saudara kandung
            di bawah satu identitas wali. Sistem tidak pernah menggabungkan identitas sendiri berdasarkan nama atau nomor HP.
        </p>
        <?php foreach ([
            ['Ayah', 'Ayah', 'Identitas ayah santri.', $record['nama_ayah'] ?? null],
            ['Ibu', 'Ibu', 'Identitas ibu santri.', $record['nama_ibu'] ?? null],
            ['Wali', 'Wali lain', 'Wali selain ayah/ibu, misalnya kakek, paman, atau kakak yang menjadi penanggung jawab.', null],
        ] as [$hubungan, $judul, $keterangan, $nilaiLama]):
            $relasi = $selected ? $relasiAktif($relasiWali, $hubungan) : null;
            $nilaiLamaHp = $record[$hubungan === 'Ayah' ? 'no_hp_ayah' : 'no_hp_ibu'] ?? '';
            require __DIR__ . '/_santri_wali_field.php';
        endforeach; ?>

        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="submit">Simpan santri dan relasi wali</button>
            <a class="btn btn-outline-secondary" href="admin_master_santri.php">Batal</a>
        </div>
    </form>
    </div>
</section>
<?php ah_old_clear('_santri_old'); ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-wali-blok]').forEach(function (blok) {
        var slug = blok.getAttribute('data-wali-blok');
        var panelPilih = blok.querySelector('[data-wali-panel="pilih"]');
        var panelBaru = blok.querySelector('[data-wali-panel="baru"]');
        var select = blok.querySelector('[data-wali-select="' + slug + '"]');
        var cari = blok.querySelector('[data-wali-cari="' + slug + '"]');
        var hasil = blok.querySelector('[data-wali-hasil="' + slug + '"]');
        var ringkas = blok.querySelector('[data-wali-ringkas="' + slug + '"]');

        function sync() {
            var mode = blok.querySelector('[data-wali-mode="' + slug + '"]:checked');
            var nilai = mode ? mode.value : 'abaikan';
            panelPilih.hidden = nilai !== 'pilih';
            panelBaru.hidden = nilai !== 'baru';
            var panelTimpa = blok.querySelector('[data-wali-panel="timpa"]');
            if (panelTimpa) panelTimpa.hidden = nilai !== 'pilih' && nilai !== 'baru';
        }
        blok.querySelectorAll('[data-wali-mode="' + slug + '"]').forEach(function (radio) {
            radio.addEventListener('change', sync);
        });
        sync();

        if (select && ringkas) {
            select.addEventListener('change', function () {
                var opsi = select.options[select.selectedIndex];
                ringkas.textContent = select.value ? 'Terpilih: ' + opsi.textContent.trim() : '';
            });
        }

        if (cari && hasil && select) {
            var timer = null;
            cari.addEventListener('input', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(function () {
                    var q = cari.value.trim();
                    if (q.length < 2) { hasil.textContent = ''; return; }
                    hasil.textContent = 'Mencari…';
                    fetch('get_wali_json.php?q=' + encodeURIComponent(q), {credentials: 'same-origin'})
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            hasil.textContent = '';
                            if (!data.ok || data.jumlah === 0) {
                                hasil.textContent = 'Tidak ada wali terdaftar yang cocok. Gunakan "Buat wali baru" bila memang orang baru.';
                                return;
                            }
                            var ul = document.createElement('ul');
                            ul.className = 'list-unstyled mb-0';
                            data.data.forEach(function (w) {
                                var li = document.createElement('li');
                                li.className = 'py-1 border-bottom';
                                var btn = document.createElement('button');
                                btn.type = 'button';
                                btn.className = 'btn btn-sm btn-outline-primary me-2';
                                btn.textContent = 'Pilih';
                                btn.addEventListener('click', function () {
                                    var ada = Array.prototype.some.call(select.options, function (o) { return o.value === String(w.id); });
                                    if (!ada) {
                                        var opt = document.createElement('option');
                                        opt.value = String(w.id);
                                        opt.textContent = w.nama + (w.no_hp ? ' · ' + w.no_hp : '') + ' · ' + w.jumlah_santri + ' santri · ID ' + w.id;
                                        select.appendChild(opt);
                                    }
                                    select.value = String(w.id);
                                    select.dispatchEvent(new Event('change'));
                                });
                                var span = document.createElement('span');
                                span.textContent = w.nama + (w.no_hp ? ' · ' + w.no_hp : '') + ' · ID ' + w.id
                                    + (w.jumlah_santri ? ' · sudah terhubung: ' + w.santri : ' · belum punya santri');
                                li.appendChild(btn);
                                li.appendChild(span);
                                ul.appendChild(li);
                            });
                            hasil.appendChild(ul);
                        })
                        .catch(function () { hasil.textContent = 'Pencarian gagal. Gunakan daftar lengkap di sebelah kanan.'; });
                }, 250);
            });
        }
    });
});
</script>

<?php elseif ($mode === 'detail' && $selected): ?>
<section class="ah-card" aria-labelledby="ah-detail-santri">
    <div class="ah-card__head"><span id="ah-detail-santri">Detail santri #<?= (int) $selected['id'] ?></span>
        <a class="btn btn-sm btn-outline-secondary" href="?action=edit&amp;id=<?= (int) $selected['id'] ?>">Ubah</a></div>
    <div class="ah-card__body">
        <div class="row g-3">
            <div class="col-md-2"><small class="text-muted d-block">NIS</small><?= master_e($selected['nis']) ?></div>
            <div class="col-md-5"><small class="text-muted d-block">Nama</small><?= master_e($selected['nama_santri']) ?></div>
            <div class="col-md-2"><small class="text-muted d-block">Jenis kelamin</small><?= $selected['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan' ?></div>
            <div class="col-md-3"><small class="text-muted d-block">Status data</small><?= ah_state_badge($selected) ?></div>
            <div class="col-md-6"><small class="text-muted d-block">Tempat, tanggal lahir</small><?= master_e($selected['tempat_lahir']) ?>, <?= master_e($selected['tgl_lahir']) ?></div>
            <div class="col-md-6"><small class="text-muted d-block">Sekolah saat ini</small><?= master_e($selected['sekolah_saat_ini'] ?: '-') ?></div>
            <div class="col-12"><small class="text-muted d-block">Alamat</small><?= master_e($selected['alamat']) ?>, <?= master_e($selected['desa']) ?>, <?= master_e($selected['kecamatan']) ?>, <?= master_e($selected['kab_kota']) ?>, <?= master_e($selected['provinsi']) ?></div>
        </div>

        <h2 class="h6 mt-4">Hubungan wali</h2>
        <p class="text-muted small">Ini adalah relasi wali yang terverifikasi — sumber utama identitas orang tua, bukan sekadar teks pada kolom lama.</p>
        <?php if ($relasiWali === []): ?>
            <?= ah_empty(
                'Belum ada relasi wali',
                'Santri ini belum terhubung ke identitas wali mana pun. Hubungkan lewat tombol Ubah, atau selesaikan lewat halaman Rekonsiliasi Wali.',
                '<a class="btn btn-sm btn-primary" href="?action=edit&amp;id=' . (int) $selected['id'] . '">Hubungkan wali</a>'
            ) ?>
        <?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table">
                <caption class="ah-visually-hidden">Relasi wali santri ini</caption>
                <thead><tr><th scope="col">Hubungan</th><th scope="col">Wali</th><th scope="col">Nomor HP</th><th scope="col">Santri lain</th><th scope="col">Status relasi</th></tr></thead>
                <tbody>
                <?php foreach ($relasiWali as $relasi): ?>
                    <tr>
                        <td><?= master_e($relasi['hubungan']) ?><?= (int) $relasi['is_primary'] === 1 ? ' ' . ah_badge('Kontak utama', 'info') : '' ?></td>
                        <td><a href="admin_wali.php?action=detail&amp;id=<?= (int) $relasi['wali_id'] ?>"><?= master_e($relasi['nama']) ?></a>
                            <span class="ah-cell-sub">ID <?= (int) $relasi['wali_id'] ?></span></td>
                        <td><?= master_e($relasi['no_hp'] ?: '-') ?></td>
                        <td><?= max(0, (int) $relasi['jumlah_santri'] - 1) ?> santri lain</td>
                        <td><?= $relasi['archived_at'] ? ah_badge('Arsip', 'muted') : ah_badge('Aktif', 'ok') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>

        <?php
        $cerminBerbeda = [];
        foreach (['Ayah' => 'nama_ayah', 'Ibu' => 'nama_ibu'] as $hubungan => $kolom) {
            $relasi = $relasiAktif($relasiWali, $hubungan);
            $lama = trim((string) ($selected[$kolom] ?? ''));
            if ($lama !== '' && ($relasi === null || strcasecmp($lama, trim((string) $relasi['nama'])) !== 0)) {
                $cerminBerbeda[] = $kolom . ' = "' . $lama . '"';
            }
        }
        if ($cerminBerbeda !== []):
            ah_note(
                'warning',
                'Kolom lama belum sejalan dengan relasi wali: ' . implode('; ', $cerminBerbeda) . '.',
                '<p class="small mb-0 mt-2">Nilai lama tidak diubah otomatis. Selesaikan lewat tombol Ubah '
                    . '(pilih wali yang benar lalu centang konfirmasi penggantian) atau lewat halaman Rekonsiliasi Wali.</p>'
            );
        endif;
        ?>

        <h2 class="h6 mt-4">Riwayat keanggotaan kelas</h2>
        <?php if ($history === []): ?>
            <p class="text-muted">Belum ada riwayat kelas.</p>
        <?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table">
                <caption class="ah-visually-hidden">Riwayat keanggotaan kelas santri ini</caption>
                <thead><tr><th scope="col">Tahun</th><th scope="col">Kelas</th><th scope="col">Mulai</th><th scope="col">Selesai</th><th scope="col">Status</th></tr></thead>
                <tbody>
                <?php foreach ($history as $membership): ?>
                    <tr><td><?= master_e($membership['tahun'] . ' ' . $membership['semester']) ?></td>
                        <td><?= master_e($membership['nama_kelas']) ?></td>
                        <td><?= master_e($membership['tanggal_mulai'] ?: '-') ?></td>
                        <td><?= master_e($membership['tanggal_selesai'] ?: '-') ?></td>
                        <td><?= master_e($membership['status']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<form method="get" class="ah-card ah-no-print">
    <div class="ah-card__body">
        <fieldset class="ah-fieldset mb-0">
            <legend>Pencarian dan filter</legend>
            <div class="row g-2 align-items-end">
                <div class="col-md-4"><label class="form-label" for="q">Pencarian</label>
                    <input class="form-control" id="q" name="q" value="<?= master_e($filters['q']) ?>" placeholder="Nama, NIS, atau sekolah"></div>
                <div class="col-md-2"><label class="form-label" for="state">Status</label>
                    <select class="form-select" id="state" name="state">
                        <?php foreach (['active' => 'Aktif', 'inactive' => 'Nonaktif', 'archived' => 'Arsip', 'all' => 'Semua'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $filters['state'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-2"><label class="form-label" for="gender">Jenis kelamin</label>
                    <select class="form-select" id="gender" name="gender"><option value="">Semua</option>
                        <option value="L" <?= $filters['gender'] === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                        <option value="P" <?= $filters['gender'] === 'P' ? 'selected' : '' ?>>Perempuan</option>
                    </select></div>
                <div class="col-md-2"><label class="form-label" for="kelas_id">Kelas aktif</label>
                    <select class="form-select" id="kelas_id" name="kelas_id"><option value="">Semua</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= (int) $class['id'] ?>" <?= (int) $filters['kelas_id'] === (int) $class['id'] ? 'selected' : '' ?>><?= master_e($class['nama_kelas']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-2 d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">Terapkan</button>
                    <a class="btn btn-outline-secondary" href="admin_master_santri.php">Bersihkan</a></div>
            </div>
        </fieldset>
    </div>
</form>

<section class="ah-card" aria-labelledby="ah-daftar-santri">
    <div class="ah-card__head"><span id="ah-daftar-santri">Daftar santri</span>
        <span class="text-muted small"><?= count($result['rows']) ?> dari <?= (int) $result['total'] ?> data</span></div>
    <?php if ($result['rows'] === []): ?>
        <div class="ah-card__body"><?= ah_empty('Tidak ada santri yang sesuai filter', 'Ubah kata kunci atau filter di atas, atau tambahkan santri baru.',
            '<a class="btn btn-sm btn-primary" href="admin_master_santri.php?action=create">Tambah Santri</a>') ?></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table">
            <caption class="ah-visually-hidden">Daftar santri sesuai filter</caption>
            <thead><tr><th scope="col">ID</th><th scope="col">NIS</th><th scope="col">Nama</th><th scope="col">L/P</th><th scope="col">Kelas</th><th scope="col">Status</th><th scope="col">Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $row): ?>
                <tr>
                    <td><?= (int) $row['id'] ?></td>
                    <td><?= master_e($row['nis']) ?></td>
                    <td class="fw-semibold"><?= master_e($row['nama_santri']) ?></td>
                    <td><?= master_e($row['jenis_kelamin']) ?></td>
                    <td><?= master_e($row['nama_kelas'] ?: '-') ?></td>
                    <td><?= ah_state_badge($row) ?></td>
                    <td><div class="ah-actions">
                        <a class="btn btn-sm btn-outline-primary" href="?action=detail&amp;id=<?= (int) $row['id'] ?>">Detail</a>
                        <a class="btn btn-sm btn-outline-secondary" href="?action=edit&amp;id=<?= (int) $row['id'] ?>">Ubah</a>
                        <form data-confirm="Ubah status santri ini? Kelayakan pilihan santri pada layanan mengikuti status aktif; relasi wali, absensi, dan perizinan lama tidak dihapus." method="post"><?= master_csrf() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary" name="action" value="<?= (int) $row['is_active'] === 1 ? 'deactivate' : 'activate' ?>"><?= (int) $row['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
                        <form method="post" onsubmit="return confirm('Arsipkan santri ini? Santri berhenti muncul pada daftar aktif, tetapi relasi wali, riwayat kelas, absensi, dan perizinan lama TIDAK dihapus.')">
                            <?= master_csrf() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" name="action" value="<?= $row['archived_at'] ? 'restore' : 'archive' ?>"><?= $row['archived_at'] ? 'Pulihkan' : 'Arsipkan' ?></button></form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php master_pagination((int) $result['total'], $page, 20); ?>

<section class="ah-card" aria-labelledby="ah-impor">
    <div class="ah-card__head"><span id="ah-impor">Impor Format Santri Lama</span></div>
    <div class="ah-card__body">
        <p class="text-muted">Urutan kolom: NIS, nama, L/P, tempat lahir, tanggal lahir (YYYY-MM-DD), alamat, desa, kecamatan, kabupaten/kota, provinsi, ayah, HP ayah, ibu, HP ibu, sekolah asal, sekolah saat ini.</p>
        <?php ah_note('info', 'Impor menyimpan nama ayah/ibu pada kolom lama dan TIDAK membuat identitas wali secara otomatis.',
            '<p class="small mb-0 mt-2">Ini disengaja: pembuatan wali otomatis per baris impor justru melahirkan identitas ganda. '
            . 'Santri hasil impor akan muncul pada halaman Rekonsiliasi Wali untuk dihubungkan ke identitas yang benar atas konfirmasi Anda.</p>'); ?>
        <div class="row g-2 align-items-end">
            <div class="col-md-6"><label class="form-label" for="importFile">Berkas Excel</label>
                <input class="form-control" type="file" id="importFile" accept=".xlsx,.xls"></div>
            <div class="col-md-3"><button class="btn btn-primary" id="importButton" type="button">Validasi dan impor</button></div>
        </div>
        <pre class="bg-light border rounded p-3 mt-3 mb-0" id="importResult" tabindex="0" aria-live="polite">Baris valid akan disimpan; baris gagal dilaporkan tanpa membatalkan baris lain.</pre>
    </div>
</section>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
document.getElementById('importButton').addEventListener('click', async function () {
    const file = document.getElementById('importFile').files[0];
    const result = document.getElementById('importResult');
    if (!file) { result.textContent = 'Pilih berkas Excel terlebih dahulu.'; return; }
    this.disabled = true; result.textContent = 'Memvalidasi dan mengimpor...';
    try {
        const workbook = XLSX.read(await file.arrayBuffer(), {type: 'array', cellDates: false});
        const rows = XLSX.utils.sheet_to_json(workbook.Sheets[workbook.SheetNames[0]], {header: 1, raw: false, defval: ''});
        const body = new FormData(); body.append('payload', JSON.stringify(rows)); body.append('_csrf', window.ALHASAN_CSRF);
        const response = await fetch('proses_import_santri.php', {method: 'POST', body});
        result.textContent = await response.text();
    } catch (error) { result.textContent = 'Impor gagal dibaca atau dikirim. Periksa format berkas lalu coba lagi.'; }
    finally { this.disabled = false; }
});
</script>
<?php master_footer(); ?>
