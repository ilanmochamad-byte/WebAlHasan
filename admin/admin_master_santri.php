<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;
use App\MasterData\PhotoStorage;

/**
 * Data santri (redesain website Tahap 6: D-1 daftar, D-2 profil 360°,
 * D-3 formulir, D-4 impor).
 *
 * Identitas orang tua/wali tetap dipilih dari wali terdaftar atau dibuat dari
 * formulir ini (koreksi ke-2, 30 Agustus 2026); kolom lama `nama_ayah`/
 * `nama_ibu` hanya cermin. Penggantian nilai kolom lama kini dikonfirmasi lewat
 * dialog yang muncul ketika server mendeteksi perbedaan (D-3), bukan kotak
 * centang di formulir.
 *
 * Impor (keputusan AC): berkas diperiksa di server TANPA menyimpan, lalu baris
 * yang siap diimpor setelah dikonfirmasi; lihat `proses_import_santri.php`.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

// Menandai bahwa potongan tampilan di bawah dimuat dari halaman ber-guard.
define('AH_PARTIAL', true);

$service = master_data_service();
$halaman = 'admin_master_santri.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int) $_POST['id'] : null;
    $action = (string) ($_POST['action'] ?? '');

    // Pengiriman ulang (klik ganda, refresh POST, retry jaringan) memakai token
    // yang sama dan hanya berlaku sekali, sehingga tidak pernah menghasilkan
    // santri, wali, atau relasi ganda.
    if ($action === 'save' && !ah_form_token_consume('santri', $_POST['form_token'] ?? null)) {
        master_flash('warning', 'Formulir ini sudah pernah dikirim. Tidak ada data ganda yang dibuat. Muat ulang halaman bila ingin menyimpan perubahan baru.');
        master_redirect($halaman);
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
            unset($_SESSION['_santri_timpa']);
            master_flash('success', ($id ? 'Perubahan data ' : 'Santri baru ') . ($service->santri($savedId)['nama_santri'] ?? '') . ' tersimpan beserta relasi walinya.');
            master_redirect($halaman . '?action=detail&id=' . $savedId);
        }
        $service->setSantriState((int) $id, $action);
        $nama = (string) ($service->santri((int) $id)['nama_santri'] ?? 'Santri');
        master_flash('success', match ($action) {
            'deactivate' => $nama . ' dinonaktifkan.',
            'activate' => $nama . ' diaktifkan kembali.',
            'archive' => $nama . ' diarsipkan.',
            'restore' => $nama . ' dipulihkan dari arsip.',
            default => 'Status santri diperbarui.',
        } . ' Relasi wali, riwayat kelas, absensi, dan perizinan lama tidak dihapus.');
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
        // D-3: perbedaan dengan kolom lama dikonfirmasi lewat dialog.
        if (preg_match('/kolom lama (Ayah|Ibu) berbeda dengan identitas wali/u', $exception->getMessage(), $cocok) === 1) {
            $_SESSION['_santri_timpa'] = $cocok[1];
        } else {
            unset($_SESSION['_santri_timpa']);
            master_flash('danger', $exception->getMessage());
        }
        if ($action === 'save') {
            master_redirect($halaman . '?action=' . ($id ? 'edit&id=' . $id : 'create'));
        }
    }
    master_redirect($id && $action !== 'save' && isset($_POST['kembali_detail']) ? $halaman . '?action=detail&id=' . (int) $id : $halaman);
}

$mode = (string) ($_GET['action'] ?? '');
$selected = isset($_GET['id']) ? $service->santri((int) $_GET['id']) : null;
if (in_array($mode, ['edit', 'detail'], true) && $selected === null) {
    http_response_code(404);
    $mode = '';
    master_flash('warning', 'Santri tidak ditemukan. Pilih santri dari daftar.');
}

/**
 * Menu ⋯ satu santri (D-1 baris, D-2 kepala profil).
 *
 * @param array<string, mixed> $row
 * @return array<int, array<string, mixed>>
 */
$menuSantri = static function (array $row, bool $diProfil = false) use ($halaman): array {
    $id = (int) $row['id'];
    $nama = (string) $row['nama_santri'];
    $aktif = (int) $row['is_active'] === 1 && $row['archived_at'] === null;
    $kembali = $diProfil ? ['kembali_detail' => 1] : [];
    $items = [];
    if (!$diProfil) {
        $items[] = ['label' => 'Ubah', 'url' => $halaman . '?action=edit&id=' . $id, 'icon' => 'fa-pen'];
    }
    $items[] = ['label' => 'Atur penempatan', 'url' => 'admin_penempatan_santri.php?q=' . rawurlencode((string) $row['nis']), 'icon' => 'fa-bed'];
    if ($aktif) {
        // Alur kelulusan/mutasi: tautan hanya membuka formulirnya; perubahan
        // tetap lewat POST berkonfirmasi di halaman tujuan.
        $items[] = ['label' => 'Luluskan / mutasi keluar', 'url' => 'admin_kelulusan_santri.php?santri_id=' . $id, 'icon' => 'fa-graduation-cap'];
    }
    if ($row['archived_at'] === null) {
        $items[] = (int) $row['is_active'] === 1
            ? ['label' => 'Nonaktifkan', 'icon' => 'fa-user-slash', 'action' => $halaman, 'fields' => ['id' => $id, 'action' => 'deactivate'] + $kembali,
                'confirm' => ['title' => 'Nonaktifkan ' . $nama . '?', 'text' => 'Santri nonaktif tidak dapat dipilih di layanan baru. Relasi wali, absensi, dan perizinan lama tetap tersimpan.', 'ok' => 'Nonaktifkan']]
            : ['label' => 'Aktifkan', 'icon' => 'fa-user-check', 'action' => $halaman, 'fields' => ['id' => $id, 'action' => 'activate'] + $kembali,
                'confirm' => ['title' => 'Aktifkan ' . $nama . '?', 'text' => 'Santri kembali dapat dipilih di layanan.', 'ok' => 'Aktifkan']];
        $items[] = ['label' => 'Arsipkan', 'icon' => 'fa-box-archive', 'danger' => true, 'action' => $halaman, 'fields' => ['id' => $id, 'action' => 'archive'] + $kembali,
            'confirm' => ['title' => 'Arsipkan ' . $nama . '?', 'text' => 'Santri berhenti muncul pada daftar aktif. Relasi wali, riwayat kelas, absensi, dan perizinan lama TIDAK dihapus dan dapat dipulihkan.', 'ok' => 'Arsipkan']];
    } else {
        $items[] = ['label' => 'Pulihkan dari arsip', 'icon' => 'fa-rotate-left', 'action' => $halaman, 'fields' => ['id' => $id, 'action' => 'restore'] + $kembali,
            'confirm' => ['title' => 'Pulihkan ' . $nama . '?', 'text' => 'Santri kembali ke daftar aktif.', 'ok' => 'Pulihkan']];
    }

    return $items;
};

$crumbs = [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Data santri', 'url' => app_url('/admin/' . $halaman)]];

// ===========================================================================
// D-3 Formulir santri
// ===========================================================================
if ($mode === 'create' || $mode === 'edit') {
    $record = $selected ?? [];
    $relasiWali = $selected ? $service->santriWali((int) $selected['id'], false) : [];
    $daftarWali = $service->waliCandidates('', 200);
    $daftarTerpotong = count($daftarWali) >= 200;
    $relasiAktif = static function (array $relasi, string $hubungan): ?array {
        foreach ($relasi as $baris) {
            if ($baris['archived_at'] === null && (string) $baris['hubungan'] === $hubungan) {
                return $baris;
            }
        }
        return null;
    };
    $timpa = is_string($_SESSION['_santri_timpa'] ?? null) ? $_SESSION['_santri_timpa'] : null;
    unset($_SESSION['_santri_timpa']);

    master_header($selected ? 'Ubah santri' : 'Tambah santri', [
        'active' => 'santri',
        'description' => $selected ? (string) $selected['nama_santri'] . ' · NIS ' . $selected['nis'] : 'Data santri baru beserta orang tua/walinya.',
        'breadcrumbs' => array_merge($crumbs, [['label' => $selected ? (string) $selected['nama_santri'] : 'Tambah santri']]),
    ]);
    echo ah_error_summary('_santri_old');
    $bagian = ['identitas' => 'Identitas', 'alamat' => 'Alamat', 'sekolah' => 'Sekolah', 'wali' => 'Orang tua/wali'];
    ?>
    <?php if ($timpa !== null): ?>
        <?php ah_action_dialog_open('dialog-timpa', 'Konfirmasi penggantian data ' . mb_strtolower($timpa), 'Ganti data ' . mb_strtolower($timpa) . ' pada kolom lama?', 'outline-danger', true, 'fa-triangle-exclamation'); ?>
            <p class="mb-2">Nama atau nomor HP <?= ah_e(mb_strtolower($timpa)) ?> yang tersimpan di data lama santri ini berbeda dengan identitas wali yang Anda pilih.</p>
            <p class="small text-muted">Bila Anda yakin wali terpilih benar, data lama diganti dengan identitas wali itu. Nilai sebelum dan sesudah dicatat pada audit.</p>
            <div class="ah-dialog__actions">
                <button class="btn btn-danger" type="submit" form="form-santri" name="konfirmasi_timpa[<?= ah_e($timpa) ?>]" value="1">Ganti dan simpan</button>
            </div>
        <?php ah_action_dialog_close(); ?>
    <?php endif; ?>
    <div class="ah-formnav">
        <nav class="ah-formnav__side ah-no-print" aria-label="Bagian formulir">
            <ul class="ah-list-plain"><?php foreach ($bagian as $kunci => $label): ?><li><a href="#bagian-<?= $kunci ?>"><?= ah_e($label) ?></a></li><?php endforeach; ?></ul>
        </nav>
        <form method="post" enctype="multipart/form-data" id="form-santri" class="ah-formnav__main">
            <?= master_csrf() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="form_token" value="<?= master_e(ah_form_token('santri')) ?>">
            <?php if ($selected): ?><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><?php endif; ?>

            <section class="ah-card" id="bagian-identitas" aria-labelledby="judul-identitas">
                <div class="ah-card__head"><h2 class="h6 mb-0" id="judul-identitas">Identitas</h2></div>
                <div class="ah-card__body"><div class="row g-3">
                    <div class="col-md-3"><label class="form-label" for="nis">NIS <span class="text-danger" aria-hidden="true">*</span></label>
                        <input class="form-control" id="nis" name="nis" maxlength="20" required value="<?= master_e(ah_old('nis', $record, '_santri_old')) ?>"><?= ah_field_error('nis', '_santri_old') ?></div>
                    <div class="col-md-6"><label class="form-label" for="nama_santri">Nama lengkap <span class="text-danger" aria-hidden="true">*</span></label>
                        <input class="form-control" id="nama_santri" name="nama_santri" maxlength="100" required value="<?= master_e(ah_old('nama_santri', $record, '_santri_old')) ?>"><?= ah_field_error('nama_santri', '_santri_old') ?></div>
                    <div class="col-md-3"><label class="form-label" for="jenis_kelamin">Jenis kelamin <span class="text-danger" aria-hidden="true">*</span></label>
                        <select class="form-select" id="jenis_kelamin" name="jenis_kelamin" required>
                            <option value="L" <?= ah_old('jenis_kelamin', $record, '_santri_old') === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                            <option value="P" <?= ah_old('jenis_kelamin', $record, '_santri_old') === 'P' ? 'selected' : '' ?>>Perempuan</option>
                        </select><?= ah_field_error('jenis_kelamin', '_santri_old') ?></div>
                    <div class="col-md-4"><label class="form-label" for="tempat_lahir">Tempat lahir</label>
                        <input class="form-control" id="tempat_lahir" name="tempat_lahir" maxlength="50" value="<?= master_e(ah_old('tempat_lahir', $record, '_santri_old')) ?>"><?= ah_field_error('tempat_lahir', '_santri_old') ?></div>
                    <div class="col-md-3"><label class="form-label" for="tgl_lahir">Tanggal lahir <span class="text-danger" aria-hidden="true">*</span></label>
                        <input class="form-control" id="tgl_lahir" type="date" name="tgl_lahir" required value="<?= master_e(ah_old('tgl_lahir', $record, '_santri_old')) ?>"><?= ah_field_error('tgl_lahir', '_santri_old') ?></div>
                    <div class="col-md-5"><label class="form-label" for="foto_upload">Foto <span class="text-muted fw-normal">(opsional, JPG/PNG/WebP, maks. 2 MB)</span></label>
                        <input class="form-control" id="foto_upload" type="file" name="foto_upload" accept="image/jpeg,image/png,image/webp" aria-describedby="bantuan_foto">
                        <div class="form-text" id="bantuan_foto">Foto lama tidak dihapus saat diganti.</div></div>
                </div></div>
            </section>

            <section class="ah-card" id="bagian-alamat" aria-labelledby="judul-alamat">
                <div class="ah-card__head"><h2 class="h6 mb-0" id="judul-alamat">Alamat</h2></div>
                <div class="ah-card__body"><div class="row g-3">
                    <div class="col-12"><label class="form-label" for="alamat">Alamat</label>
                        <textarea class="form-control" id="alamat" name="alamat" rows="2"><?= master_e(ah_old('alamat', $record, '_santri_old')) ?></textarea><?= ah_field_error('alamat', '_santri_old') ?></div>
                    <?php foreach (['desa' => 'Desa/Kelurahan', 'kecamatan' => 'Kecamatan', 'kab_kota' => 'Kabupaten/Kota', 'provinsi' => 'Provinsi'] as $name => $label): ?>
                        <div class="col-md-3"><label class="form-label" for="<?= $name ?>"><?= $label ?></label>
                            <input class="form-control" id="<?= $name ?>" name="<?= $name ?>" maxlength="50" value="<?= master_e(ah_old($name, $record, '_santri_old')) ?>"><?= ah_field_error($name, '_santri_old') ?></div>
                    <?php endforeach; ?>
                </div></div>
            </section>

            <section class="ah-card" id="bagian-sekolah" aria-labelledby="judul-sekolah">
                <div class="ah-card__head"><h2 class="h6 mb-0" id="judul-sekolah">Sekolah</h2></div>
                <div class="ah-card__body"><div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="asal_sekolah">Sekolah asal</label>
                        <input class="form-control" id="asal_sekolah" name="asal_sekolah" maxlength="100" value="<?= master_e(ah_old('asal_sekolah', $record, '_santri_old')) ?>"><?= ah_field_error('asal_sekolah', '_santri_old') ?></div>
                    <div class="col-md-6"><label class="form-label" for="sekolah_saat_ini">Sekolah saat ini</label>
                        <input class="form-control" id="sekolah_saat_ini" name="sekolah_saat_ini" maxlength="50" value="<?= master_e(ah_old('sekolah_saat_ini', $record, '_santri_old')) ?>"><?= ah_field_error('sekolah_saat_ini', '_santri_old') ?></div>
                </div></div>
            </section>

            <section class="ah-card" id="bagian-wali" aria-labelledby="judul-wali">
                <div class="ah-card__head"><h2 class="h6 mb-0" id="judul-wali">Orang tua/wali</h2></div>
                <div class="ah-card__body">
                    <p class="text-muted small">Cari wali yang sudah terdaftar — inilah cara menyatukan saudara kandung di bawah satu identitas wali. Bila belum ada, pilih “Buat wali baru”. Sistem tidak pernah menggabungkan identitas sendiri berdasarkan nama atau nomor HP.</p>
                    <?php foreach ([
                        ['Ayah', 'Ayah', 'Identitas ayah santri.', $record['nama_ayah'] ?? null],
                        ['Ibu', 'Ibu', 'Identitas ibu santri.', $record['nama_ibu'] ?? null],
                        ['Wali', 'Wali lain', 'Wali selain ayah/ibu, misalnya kakek, paman, atau kakak yang menjadi penanggung jawab.', null],
                    ] as [$hubungan, $judul, $keterangan, $nilaiLama]):
                        $relasi = $selected ? $relasiAktif($relasiWali, $hubungan) : null;
                        $nilaiLamaHp = $record[$hubungan === 'Ayah' ? 'no_hp_ayah' : 'no_hp_ibu'] ?? '';
                        require __DIR__ . '/_santri_wali_field.php';
                    endforeach; ?>
                </div>
            </section>

            <div class="ah-actbar">
                <a class="btn btn-outline-secondary" href="<?= master_e($selected ? $halaman . '?action=detail&id=' . (int) $selected['id'] : $halaman) ?>">Batal</a>
                <button class="btn btn-primary" type="submit">Simpan santri</button>
            </div>
        </form>
    </div>
    <?php
    ah_old_clear('_santri_old');
    ?>
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
            }
            blok.querySelectorAll('[data-wali-mode="' + slug + '"]').forEach(function (radio) { radio.addEventListener('change', sync); });
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
                                    hasil.textContent = 'Tidak ada wali terdaftar yang cocok. Pilih "Buat wali baru" bila memang orang baru.';
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
                                            opt.textContent = w.nama + (w.no_hp ? ' · ' + w.no_hp : '') + ' · ' + w.jumlah_santri + ' santri';
                                            select.appendChild(opt);
                                        }
                                        select.value = String(w.id);
                                        select.dispatchEvent(new Event('change'));
                                    });
                                    var span = document.createElement('span');
                                    span.textContent = w.nama + (w.no_hp ? ' · ' + w.no_hp : '') + (w.jumlah_santri ? ' · sudah terhubung: ' + w.santri : ' · belum punya santri');
                                    li.appendChild(btn);
                                    li.appendChild(span);
                                    ul.appendChild(li);
                                });
                                hasil.appendChild(ul);
                            })
                            .catch(function () { hasil.textContent = 'Pencarian gagal. Gunakan daftar wali terpilih.'; });
                    }, 250);
                });
            }
        });
    });
    </script>
    <?php
    master_footer();
    exit;
}

// ===========================================================================
// D-2 Profil santri 360°
// ===========================================================================
if ($mode === 'detail' && $selected !== null) {
    $sid = (int) $selected['id'];
    $profil = profil_santri_service();
    $ringkas = $profil->ringkasan($sid) ?? [];
    $tabs = ['biodata' => 'Biodata', 'wali' => 'Orang tua/wali', 'tempat' => 'Kelas & kamar', 'izin' => 'Izin', 'pembinaan' => 'Pembinaan', 'rapor' => 'Nilai & rapor'];
    $tab = is_string($_GET['tab'] ?? null) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'biodata';
    $tabUrl = static fn (string $t): string => $halaman . '?action=detail&id=' . $sid . ($t === 'biodata' ? '' : '&tab=' . $t);
    $nama = (string) $selected['nama_santri'];
    master_header($nama, [
        'active' => 'santri',
        'heading' => 'Data santri',
        'show_heading' => false,
        'breadcrumbs' => array_merge($crumbs, [['label' => $nama]]),
    ]);
    $sub = implode(' · ', array_filter(['NIS ' . $selected['nis'], $ringkas['kelas'] ?? null, $ringkas['kamar'] ?? null]));
    ?>
    <section class="ah-card ah-profile-head" aria-label="Ringkasan santri">
        <div class="ah-card__body">
            <span class="ah-avatar" aria-hidden="true"><?= ah_e(mb_strtoupper(mb_substr($nama, 0, 1))) ?></span>
            <div class="ah-profile-head__main"><h1 class="h4 mb-0"><?= ah_e($nama) ?></h1><p class="text-muted mb-0"><?= ah_e($sub) ?></p></div>
            <?= ah_state_badge($selected) ?>
            <a class="btn btn-outline-primary" href="<?= master_e($halaman . '?action=edit&id=' . $sid) ?>"><i class="fas fa-pen" aria-hidden="true"></i> Ubah</a>
            <?= ah_menu($menuSantri($selected, true), 'Tindakan lain untuk ' . $nama, false) ?>
        </div>
    </section>
    <?php
    echo ah_seg(array_map(static fn (string $k, string $l): array => ['label' => $l, 'url' => $tabUrl($k), 'active' => $k === $tab], array_keys($tabs), $tabs), 'Bagian profil santri');

    if ($tab === 'biodata'): ?>
        <section class="ah-card" aria-labelledby="ah-biodata">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-biodata">Identitas</h2></div>
            <div class="ah-card__body"><dl class="ah-kv">
                <div><dt>NIS</dt><dd><?= ah_e($selected['nis']) ?></dd></div>
                <div><dt>Jenis kelamin</dt><dd><?= $selected['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan' ?></dd></div>
                <div><dt>Tempat, tanggal lahir</dt><dd><?= ah_e(trim((string) $selected['tempat_lahir'] . ', ' . ah_tanggal((string) $selected['tgl_lahir'], false), ', ')) ?></dd></div>
                <div><dt>Sekolah saat ini</dt><dd><?= ah_e($selected['sekolah_saat_ini'] ?: '—') ?></dd></div>
                <div><dt>Sekolah asal</dt><dd><?= ah_e($selected['asal_sekolah'] ?: '—') ?></dd></div>
                <div><dt>Alamat</dt><dd><?= ah_e(implode(', ', array_filter([(string) $selected['alamat'], (string) $selected['desa'], (string) $selected['kecamatan'], (string) $selected['kab_kota'], (string) $selected['provinsi']])) ?: '—') ?></dd></div>
            </dl></div>
        </section>
    <?php elseif ($tab === 'wali'):
        $relasiWali = $service->santriWali($sid, false); ?>
        <section class="ah-card" aria-labelledby="ah-wali">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-wali">Orang tua/wali</h2>
                <a class="btn btn-sm btn-outline-primary" href="<?= master_e($halaman . '?action=edit&id=' . $sid . '#bagian-wali') ?>">Ubah wali</a></div>
            <?php if ($relasiWali === []): ?>
                <div class="ah-card__body"><?= ah_empty('Belum ada relasi wali', 'Santri ini belum terhubung ke identitas wali mana pun. Hubungkan lewat Ubah wali, atau selesaikan lewat Rekonsiliasi wali.') ?></div>
            <?php else: ?>
                <div class="ah-table-wrap"><table class="ah-table ah-table--cards">
                    <caption class="ah-visually-hidden">Relasi wali santri ini</caption>
                    <thead><tr><th scope="col">Wali</th><th scope="col">Hubungan</th><th scope="col">Nomor HP</th><th scope="col">Santri lain</th><th scope="col">Status relasi</th></tr></thead>
                    <tbody>
                    <?php foreach ($relasiWali as $relasi): ?>
                        <tr>
                            <td><a class="ah-row-link" href="admin_wali.php?action=detail&amp;id=<?= (int) $relasi['wali_id'] ?>"><?= master_e($relasi['nama']) ?></a></td>
                            <td><?= master_e($relasi['hubungan']) ?><?= (int) $relasi['is_primary'] === 1 ? ' ' . ah_badge('Kontak utama', 'info') : '' ?></td>
                            <td><?= master_e($relasi['no_hp'] ?: '—') ?></td>
                            <td><?= max(0, (int) $relasi['jumlah_santri'] - 1) ?> santri lain</td>
                            <td><?= $relasi['archived_at'] ? ah_badge('Arsip', 'muted') : ah_badge('Aktif', 'ok') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>
        <?php
        $cerminBerbeda = [];
        foreach (['Ayah' => 'nama_ayah', 'Ibu' => 'nama_ibu'] as $hubungan => $kolom) {
            $relasi = null;
            foreach ($relasiWali as $baris) {
                if ($baris['archived_at'] === null && (string) $baris['hubungan'] === $hubungan) { $relasi = $baris; break; }
            }
            $lama = trim((string) ($selected[$kolom] ?? ''));
            if ($lama !== '' && ($relasi === null || strcasecmp($lama, trim((string) $relasi['nama'])) !== 0)) {
                $cerminBerbeda[] = mb_strtolower($hubungan) . ' "' . $lama . '"';
            }
        }
        if ($cerminBerbeda !== []):
            ah_note('warning', 'Data lama belum sejalan dengan relasi wali: ' . implode('; ', $cerminBerbeda) . '.',
                '<p class="small mb-0 mt-2">Nilai lama tidak diubah otomatis. Selesaikan lewat Ubah wali atau halaman <a href="admin_wali_rekonsiliasi.php">Rekonsiliasi wali</a>.</p>');
        endif;
    elseif ($tab === 'tempat'):
        $history = $service->membershipHistory($sid);
        $kamarRiwayat = $profil->riwayatKamar($sid); ?>
        <section class="ah-card" aria-labelledby="ah-tempat">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-tempat">Semester aktif<?= isset($ringkas['tahun']['tahun']) ? ': ' . ah_e($ringkas['tahun']['semester'] . ' ' . $ringkas['tahun']['tahun']) : '' ?></h2>
                <a class="btn btn-sm btn-outline-primary" href="<?= master_e('admin_penempatan_santri.php?q=' . rawurlencode((string) $selected['nis'])) ?>">Atur penempatan</a></div>
            <div class="ah-card__body"><dl class="ah-kv">
                <div><dt>Kelas</dt><dd><?= ah_e($ringkas['kelas'] ?? 'Belum ada kelas') ?></dd></div>
                <div><dt>Kamar/kobong</dt><dd><?= ah_e($ringkas['kamar'] ?? 'Belum ada kamar') ?></dd></div>
            </dl></div>
        </section>
        <div class="row g-3">
            <div class="col-lg-6"><section class="ah-card h-100" aria-labelledby="ah-riwayat-kelas">
                <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-riwayat-kelas">Riwayat kelas</h2></div>
                <?php if ($history === []): ?><div class="ah-card__body"><p class="text-muted mb-0">Belum ada riwayat kelas.</p></div>
                <?php else: ?><div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Riwayat keanggotaan kelas</caption>
                    <thead><tr><th scope="col">Semester</th><th scope="col">Kelas</th><th scope="col">Masa</th><th scope="col">Status</th></tr></thead><tbody>
                    <?php foreach ($history as $m): ?><tr><td><?= master_e($m['semester'] . ' ' . $m['tahun']) ?></td><td><?= master_e($m['nama_kelas']) ?></td>
                        <td><?= ah_e($m['tanggal_selesai'] ? ah_rentang((string) $m['tanggal_mulai'], (string) $m['tanggal_selesai'], false) : ($m['tanggal_mulai'] ? 'Mulai ' . ah_tanggal((string) $m['tanggal_mulai'], false) : '—')) ?></td><td><?= ah_status((string) $m['status']) ?></td></tr><?php endforeach; ?>
                    </tbody></table></div><?php endif; ?>
            </section></div>
            <div class="col-lg-6"><section class="ah-card h-100" aria-labelledby="ah-riwayat-kamar">
                <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-riwayat-kamar">Riwayat kamar</h2></div>
                <?php if ($kamarRiwayat === []): ?><div class="ah-card__body"><p class="text-muted mb-0">Belum ada riwayat kamar.</p></div>
                <?php else: ?><div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Riwayat kamar</caption>
                    <thead><tr><th scope="col">Semester</th><th scope="col">Kamar</th></tr></thead><tbody>
                    <?php foreach ($kamarRiwayat as $k): ?><tr><td><?= master_e($k['semester'] . ' ' . $k['tahun']) ?><?= $k['status'] === 'Aktif' ? ' ' . ah_badge('Aktif', 'ok') : '' ?></td><td><?= master_e($k['nama_kamar'] ?? 'Kamar tidak ditemukan') ?></td></tr><?php endforeach; ?>
                    </tbody></table></div><?php endif; ?>
            </section></div>
        </div>
    <?php elseif ($tab === 'izin'):
        $izin = $profil->izin($sid); ?>
        <?= ah_stats([
            ['label' => 'Izin semester ini', 'value' => (string) ($ringkas['izin_semester'] ?? 0)],
            ['label' => 'Disetujui', 'value' => (string) ($ringkas['izin_disetujui'] ?? 0)],
        ], 'Ringkasan izin') ?>
        <section class="ah-card" aria-labelledby="ah-izin">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-izin">Izin terbaru</h2>
                <a class="btn btn-sm btn-outline-primary" href="<?= master_e(app_url('/portal/izin.php?q=' . rawurlencode((string) $selected['nis']))) ?>">Buka di Perizinan</a></div>
            <?php if ($izin === []): ?><div class="ah-card__body"><?= ah_empty('Belum ada izin', 'Santri ini belum pernah diajukan izin.') ?></div>
            <?php else: ?><div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Izin santri ini</caption>
                <thead><tr><th scope="col">Tanggal</th><th scope="col">Status</th><th scope="col"><span class="ah-visually-hidden">Buka</span></th></tr></thead><tbody>
                <?php foreach ($izin as $i): ?><tr><td><?= ah_e(ah_rentang((string) $i['tgl_izin'], (string) $i['tgl_kembali'], false)) ?></td><td><?= ah_status((string) $i['status']) ?></td>
                    <td data-label=""><a class="btn btn-sm btn-outline-secondary" href="<?= master_e(app_url('/portal/izin_detail.php?id=' . (int) $i['id'])) ?>">Detail</a></td></tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
        </section>
    <?php elseif ($tab === 'pembinaan'):
        $pelanggaran = ['rows' => [], 'total' => 0];
        $kasus = ['rows' => [], 'total' => 0];
        try {
            $pelanggaran = v3_pelanggaran_service()->page($currentUser, ['santri_id' => (string) $sid]);
            $kasus = v3_konseling_service()->page($currentUser, ['santri_id' => (string) $sid]);
        } catch (Throwable $exception) {
            ah_note('warning', 'Data pembinaan belum dapat dibaca.');
        } ?>
        <?= ah_stats([
            ['label' => 'Total poin semester', 'value' => (string) $profil->totalPoin($sid)],
            ['label' => 'Catatan pelanggaran', 'value' => (string) (int) $pelanggaran['total']],
            ['label' => 'Kasus konseling', 'value' => (string) (int) $kasus['total']],
        ], 'Ringkasan pembinaan') ?>
        <section class="ah-card" aria-labelledby="ah-pelanggaran">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-pelanggaran">Pelanggaran terbaru</h2>
                <a class="btn btn-sm btn-outline-primary" href="<?= master_e(app_url('/portal/v3_pelanggaran.php?santri_id=' . $sid)) ?>">Buka di Pembinaan</a></div>
            <?php if ($pelanggaran['rows'] === []): ?><div class="ah-card__body"><p class="text-muted mb-0">Belum ada catatan pelanggaran.</p></div>
            <?php else: ?><div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Pelanggaran santri ini</caption>
                <thead><tr><th scope="col">Waktu</th><th scope="col">Jenis</th><th scope="col">Poin</th><th scope="col">Status</th></tr></thead><tbody>
                <?php foreach (array_slice($pelanggaran['rows'], 0, 10) as $p): ?><tr><td><a class="ah-row-link" href="<?= master_e(app_url('/portal/v3_pelanggaran_detail.php?id=' . (int) $p['id'])) ?>"><?= ah_e(ah_waktu($p['waktu_kejadian'])) ?></a></td>
                    <td><?= ah_e($p['jenis'] ?? $p['kategori']) ?></td><td><?= (int) $p['poin'] ?></td><td><?= ah_status((string) $p['status']) ?></td></tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
        </section>
        <section class="ah-card" aria-labelledby="ah-kasus">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-kasus">Kasus konseling</h2></div>
            <?php if ($kasus['rows'] === []): ?><div class="ah-card__body"><p class="text-muted mb-0">Belum ada kasus konseling.</p></div>
            <?php else: ?><div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Kasus konseling santri ini</caption>
                <thead><tr><th scope="col">Dibuka</th><th scope="col">Kerahasiaan</th><th scope="col">Sesi</th><th scope="col">Status</th></tr></thead><tbody>
                <?php foreach (array_slice($kasus['rows'], 0, 10) as $k): ?><tr><td><a class="ah-row-link" href="<?= master_e(app_url('/portal/v3_konseling_detail.php?id=' . (int) $k['id'])) ?>"><?= ah_e(ah_tanggal((string) $k['dibuka_pada'], false)) ?></a></td>
                    <td><?= ah_e($k['kerahasiaan']) ?></td><td><?= (int) $k['jumlah_sesi'] ?></td><td><?= ah_status((string) $k['status']) ?></td></tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
        </section>
    <?php else:
        $rapor = $profil->rapor($sid); ?>
        <section class="ah-card" aria-labelledby="ah-rapor">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-rapor">Rapor per semester</h2>
                <a class="btn btn-sm btn-outline-primary" href="<?= master_e(app_url('/portal/rapor_semester.php')) ?>">Buka Penilaian &amp; rapor</a></div>
            <?php if ($rapor === []): ?><div class="ah-card__body"><?= ah_empty('Belum ada rapor', 'Rapor santri ini belum disusun pada semester mana pun.') ?></div>
            <?php else: ?><div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Rapor santri per semester</caption>
                <thead><tr><th scope="col">Semester</th><th scope="col">Status</th><th scope="col">Versi</th></tr></thead><tbody>
                <?php foreach ($rapor as $r): ?><tr><td><?= master_e($r['semester'] . ' ' . $r['tahun']) ?></td><td><?= ah_status((string) $r['status']) ?></td><td><?= (int) $r['current_version'] > 0 ? 'Versi ' . (int) $r['current_version'] : '—' ?></td></tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
        </section>
    <?php endif;
    master_footer();
    exit;
}

// ===========================================================================
// D-4 Impor santri (wizard 3 langkah)
// ===========================================================================
if ($mode === 'impor') {
    master_header('Impor santri', [
        'active' => 'santri',
        'description' => 'Berkas diperiksa di server tanpa menyimpan apa pun; santri baru disimpan setelah Anda mengonfirmasi.',
        'breadcrumbs' => array_merge($crumbs, [['label' => 'Impor santri']]),
        'actions' => '<a class="btn btn-outline-primary" href="proses_import_santri.php?templat=1"><i class="fas fa-download" aria-hidden="true"></i> Unduh templat</a>',
    ]);
    ?>
    <div data-ah-impor>
        <div data-impor-stepper><?= ah_stepper(['Unggah berkas', 'Periksa data', 'Konfirmasi'], 0, 'Langkah impor') ?></div>
        <noscript><?php ah_note('warning', 'Impor membutuhkan JavaScript untuk membaca berkas Excel di peramban.'); ?></noscript>
        <section class="ah-card" data-impor-langkah="1" aria-labelledby="impor-unggah">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="impor-unggah">Unggah berkas</h2></div>
            <div class="ah-card__body">
                <p class="text-muted small">Urutan kolom: <?= ah_e(implode(', ', App\MasterData\MasterDataService::JUDUL_IMPOR)) ?>. Baris judul boleh ada. Maksimal <?= App\MasterData\MasterDataService::BATAS_IMPOR ?> baris.</p>
                <?php ah_note('info', 'Impor tidak membuat identitas wali otomatis.', '<p class="small mb-0 mt-2">Nama ayah/ibu disimpan pada data lama; santri hasil impor muncul di Rekonsiliasi wali untuk dihubungkan ke identitas yang benar atas konfirmasi Anda.</p>'); ?>
                <label class="form-label" for="impor-berkas">Berkas Excel (.xlsx atau .xls)</label>
                <input class="form-control" type="file" id="impor-berkas" accept=".xlsx,.xls">
                <p class="small text-danger mt-2 mb-0" data-impor-galat role="alert" hidden></p>
                <div class="ah-actbar mt-3"><a class="btn btn-outline-secondary" href="<?= master_e($halaman) ?>">Batal</a>
                    <button class="btn btn-primary" type="button" data-impor-periksa>Periksa data</button></div>
            </div>
        </section>
        <section data-impor-langkah="2" hidden aria-labelledby="impor-periksa">
            <h2 class="h6" id="impor-periksa" data-impor-judul>Hasil pemeriksaan</h2>
            <div data-impor-stats></div>
            <section class="ah-card" aria-labelledby="impor-masalah">
                <div class="ah-card__head"><h3 class="h6 mb-0" id="impor-masalah">Baris bermasalah</h3>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-impor-unduh>Unduh daftar perbaikan</button></div>
                <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Baris yang tidak dapat diimpor beserta masalahnya</caption>
                    <thead><tr><th scope="col">Baris</th><th scope="col">NIS</th><th scope="col">Nama</th><th scope="col">Masalah</th></tr></thead>
                    <tbody data-impor-tabel></tbody></table></div>
            </section>
            <div class="ah-actbar ah-actbar--sticky">
                <span class="ah-actbar__text" data-impor-ringkas role="status"></span>
                <button class="btn btn-outline-secondary" type="button" data-impor-kembali>Kembali</button>
                <button class="btn btn-primary" type="button" data-impor-simpan>Impor</button>
                <a class="btn btn-primary" href="<?= master_e($halaman) ?>" data-impor-selesai hidden>Selesai</a>
            </div>
        </section>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script>
    (function () {
        var akar = document.querySelector('[data-ah-impor]');
        if (!akar) { return; }
        var q = function (s) { return akar.querySelector(s); };
        var baris = null, hasil = null;
        var langkah = function (n) {
            q('[data-impor-langkah="1"]').hidden = n !== 1;
            q('[data-impor-langkah="2"]').hidden = n === 1;
            akar.querySelectorAll('[data-impor-stepper] li').forEach(function (li, i) {
                li.className = i < n - 1 ? 'is-done' : (i === n - 1 ? 'is-current' : '');
                if (i === n - 1) { li.setAttribute('aria-current', 'step'); } else { li.removeAttribute('aria-current'); }
            });
        };
        var galat = function (t) { var g = q('[data-impor-galat]'); g.textContent = t; g.hidden = !t; };
        var kirim = function (mode) {
            var body = new FormData();
            body.append('mode', mode);
            body.append('payload', JSON.stringify(baris));
            body.append('_csrf', window.ALHASAN_CSRF || '');
            return fetch('proses_import_santri.php', {method: 'POST', body: body, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
                .then(function (r) { return r.json().then(function (d) { if (!r.ok || !d.ok) { throw new Error(d.pesan || 'Server menolak permintaan.'); } return d; }); });
        };
        var tampil = function (d, selesai) {
            hasil = d;
            var stat = function (l, v, w) { return '<div class="ah-stat' + (w ? ' ah-stat--warn' : '') + '"><p class="ah-stat__label">' + l + '</p><p class="ah-stat__value">' + v + '</p></div>'; };
            q('[data-impor-judul]').textContent = selesai ? 'Hasil impor' : 'Hasil pemeriksaan';
            q('[data-impor-stats]').innerHTML = '<section class="ah-stats" aria-label="Ringkasan">' + stat('Baris', d.baris) + stat(selesai ? 'Disimpan' : 'Siap', selesai ? d.disimpan : d.siap) + stat('Bermasalah', d.bermasalah, d.bermasalah > 0) + '</section>';
            var tb = q('[data-impor-tabel]');
            tb.textContent = '';
            var masalah = d.hasil.filter(function (b) { return !b.siap; });
            masalah.forEach(function (b) {
                var tr = document.createElement('tr');
                [String(b.baris), b.nis || '—', b.nama || '—', b.masalah.join(' ')].forEach(function (t) { var td = document.createElement('td'); td.textContent = t; tr.appendChild(td); });
                tb.appendChild(tr);
            });
            if (masalah.length === 0) { var tr = document.createElement('tr'); var td = document.createElement('td'); td.colSpan = 4; td.textContent = 'Tidak ada baris bermasalah.'; tr.appendChild(td); tb.appendChild(tr); }
            q('[data-impor-unduh]').hidden = masalah.length === 0;
            var siap = selesai ? 0 : d.siap;
            q('[data-impor-ringkas]').textContent = selesai ? d.disimpan + ' santri disimpan.' : siap + ' santri akan ditambahkan';
            q('[data-impor-simpan]').textContent = 'Impor ' + siap + ' santri';
            q('[data-impor-simpan]').hidden = selesai;
            q('[data-impor-simpan]').disabled = siap === 0;
            q('[data-impor-kembali]').hidden = selesai;
            q('[data-impor-selesai]').hidden = !selesai;
            langkah(selesai ? 3 : 2);
        };
        q('[data-impor-periksa]').addEventListener('click', function () {
            var btn = this, file = q('#impor-berkas').files[0];
            if (!file) { galat('Pilih berkas Excel terlebih dahulu.'); return; }
            galat(''); btn.disabled = true; btn.textContent = 'Memeriksa…';
            file.arrayBuffer().then(function (buf) {
                var wb = XLSX.read(buf, {type: 'array', cellDates: false});
                baris = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], {header: 1, raw: false, defval: ''});
                return kirim('periksa');
            }).then(function (d) { tampil(d, false); })
              .catch(function (e) { galat(e && e.message ? e.message : 'Berkas tidak dapat dibaca. Periksa formatnya lalu coba lagi.'); })
              .finally(function () { btn.disabled = false; btn.textContent = 'Periksa data'; });
        });
        q('[data-impor-kembali]').addEventListener('click', function () { langkah(1); });
        q('[data-impor-simpan]').addEventListener('click', function () {
            var btn = this; btn.disabled = true; btn.textContent = 'Menyimpan…';
            kirim('impor').then(function (d) { tampil(d, true); })
                .catch(function (e) { btn.disabled = false; btn.textContent = 'Impor'; galat(e.message); langkah(1); });
        });
        q('[data-impor-unduh]').addEventListener('click', function () {
            if (!hasil) { return; }
            var sel = function (v) { v = String(v == null ? '' : v); if (/^[=+\-@]/.test(v)) { v = "'" + v; } return '"' + v.replace(/"/g, '""') + '"'; };
            var csv = ['Baris,NIS,Nama,Masalah'].concat(hasil.hasil.filter(function (b) { return !b.siap; }).map(function (b) { return [b.baris, b.nis, b.nama, b.masalah.join(' ')].map(sel).join(','); })).join('\r\n');
            var a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob(['﻿' + csv], {type: 'text/csv;charset=utf-8'}));
            a.download = 'perbaikan-impor-santri.csv';
            document.body.appendChild(a); a.click(); a.remove();
        });
    })();
    </script>
    <?php
    master_footer();
    exit;
}

// ===========================================================================
// D-1 Daftar santri
// ===========================================================================
$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = [
    'q' => is_string($_GET['q'] ?? null) ? $_GET['q'] : '',
    'state' => in_array($_GET['state'] ?? 'active', ['active', 'inactive', 'archived', 'all'], true) ? (string) ($_GET['state'] ?? 'active') : 'active',
    'gender' => in_array($_GET['gender'] ?? '', ['L', 'P'], true) ? (string) $_GET['gender'] : '',
    'kelas_id' => max(0, (int) ($_GET['kelas_id'] ?? 0)),
    'kamar_id' => max(0, (int) ($_GET['kamar_id'] ?? 0)),
];
$result = $service->santriList($filters, $page);
$jumlah = $service->santriStateCounts($filters);
$classes = $service->classes();
$kamarOpsi = $service->kamarOptions();
$adaSaring = (int) ($filters['q'] !== '') + (int) ($filters['gender'] !== '') + (int) ($filters['kelas_id'] > 0) + (int) ($filters['kamar_id'] > 0);

master_header('Data santri', [
    'active' => 'santri',
    'description' => 'Identitas santri, orang tua/wali, penempatan, dan riwayatnya.',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Data santri']],
    'actions' => '<a class="btn btn-primary" href="' . $halaman . '?action=create"><i class="fas fa-plus" aria-hidden="true"></i> Tambah santri</a>'
        . ah_menu([
            ['label' => 'Impor dari Excel', 'url' => $halaman . '?action=impor', 'icon' => 'fa-file-import'],
            ['label' => 'Ekspor CSV', 'url' => 'export_master.php?entity=santri&' . http_build_query(array_filter($filters, static fn ($v): bool => $v !== '' && $v !== 0)), 'icon' => 'fa-file-csv'],
            ['label' => 'Unduh templat impor', 'url' => 'proses_import_santri.php?templat=1', 'icon' => 'fa-download'],
        ], 'Tindakan lain', false),
]);

$chip = static fn (string $state): string => '?' . ah_query(['state' => $state, 'page' => null]);
echo ah_chips([
    ['label' => 'Aktif', 'url' => $chip('active'), 'active' => $filters['state'] === 'active', 'count' => $jumlah['active']],
    ['label' => 'Nonaktif', 'url' => $chip('inactive'), 'active' => $filters['state'] === 'inactive', 'count' => $jumlah['inactive']],
    ['label' => 'Arsip', 'url' => $chip('archived'), 'active' => $filters['state'] === 'archived', 'count' => $jumlah['archived']],
    ['label' => 'Semua', 'url' => $chip('all'), 'active' => $filters['state'] === 'all', 'count' => $jumlah['active'] + $jumlah['inactive'] + $jumlah['archived']],
], 'Status santri');

ah_filter_open($adaSaring, ['state' => $filters['state']]);
?>
    <div class="ah-filter__wide"><label class="form-label" for="santri-q">Cari nama atau NIS</label>
        <input class="form-control" type="search" id="santri-q" name="q" maxlength="100" value="<?= master_e($filters['q']) ?>"></div>
    <div><label class="form-label" for="santri-kelas">Kelas</label>
        <select class="form-select" id="santri-kelas" name="kelas_id"><option value="">Semua kelas</option>
            <?php foreach ($classes as $class): if ($class['archived_at'] !== null) { continue; } ?>
                <option value="<?= (int) $class['id'] ?>" <?= $filters['kelas_id'] === (int) $class['id'] ? 'selected' : '' ?>><?= master_e($class['nama_kelas']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="santri-kamar">Kamar</label>
        <select class="form-select" id="santri-kamar" name="kamar_id"><option value="">Semua kamar</option>
            <?php foreach ($kamarOpsi as $kamar): ?>
                <option value="<?= (int) $kamar['id'] ?>" <?= $filters['kamar_id'] === (int) $kamar['id'] ? 'selected' : '' ?>><?= master_e($kamar['nama_kamar']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="santri-gender">L/P</label>
        <select class="form-select" id="santri-gender" name="gender"><option value="">Semua</option>
            <option value="L" <?= $filters['gender'] === 'L' ? 'selected' : '' ?>>Laki-laki</option>
            <option value="P" <?= $filters['gender'] === 'P' ? 'selected' : '' ?>>Perempuan</option>
        </select></div>
<?php ah_filter_close(['state' => $filters['state']]); ?>

<div class="ah-card ah-table-wrap">
    <table class="ah-table ah-table--cards" id="daftar-santri">
        <caption class="ah-visually-hidden">Daftar santri, <?= (int) $result['total'] ?> data</caption>
        <thead><tr><th scope="col">NIS</th><th scope="col">Nama</th><th scope="col">L/P</th><th scope="col">Kelas</th><th scope="col" class="ah-col-opt">Kamar</th><th scope="col">Status</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $row): ?>
            <tr<?= $row['archived_at'] !== null ? ' class="ah-row-muted"' : '' ?>>
                <td><?= master_e($row['nis']) ?></td>
                <td><a class="ah-row-link" href="<?= master_e($halaman . '?action=detail&id=' . (int) $row['id']) ?>"><?= master_e($row['nama_santri']) ?></a></td>
                <td><?= master_e($row['jenis_kelamin']) ?></td>
                <td><?= master_e($row['nama_kelas'] ?: '—') ?></td>
                <td class="ah-col-opt"><?= master_e($row['nama_kamar'] ?: '—') ?></td>
                <td><?= ah_state_badge($row) ?></td>
                <td data-label=""><?= ah_menu($menuSantri($row), 'Tindakan untuk ' . $row['nama_santri']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($result['rows'] === []): ?>
            <tr><td colspan="7" data-label=""><?= $adaSaring > 0
                ? ah_empty('Tidak ada santri yang cocok', 'Ubah kata kunci atau bersihkan saringan.', '<a class="btn btn-sm btn-outline-secondary" href="?state=' . master_e($filters['state']) . '">Bersihkan saringan</a>')
                : ah_empty('Belum ada santri pada status ini', 'Tambahkan santri baru atau impor dari Excel.', '<a class="btn btn-sm btn-primary" href="' . $halaman . '?action=create">Tambah santri</a>') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php master_pagination((int) $result['total'], $page, 20); ?>
<?php master_footer(); ?>
