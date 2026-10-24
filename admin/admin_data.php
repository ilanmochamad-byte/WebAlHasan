<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Psb\PsbAdmin;
use App\Psb\PsbException;

require_once __DIR__ . '/_guard.php';

/**
 * PSB › Pendaftar (redesain website Tahap 3, K-5).
 *
 * Chip status sekaligus ringkasan angka, saringan, daftar berhalaman 20
 * baris, dan satu panel per pendaftar (biodata, berkas, riwayat, keputusan)
 * menggantikan modal per baris. Tambah manual dan impor Excel memakai panel
 * yang sama; impor diperiksa di server lebih dahulu tanpa menyimpan, lalu
 * disimpan setelah dikonfirmasi (pengganti kotak alert peramban dan muat ulang).
 *
 * Pratinjau berkas (keputusan AG) dipasang pada redesain Tahap 8: gambar mini
 * dan tautan memakai jalur berpenjaga `lihat_berkas_psb.php` (hak admin
 * diperiksa setiap permintaan), bukan folder `berkas_psb/` langsung.
 * Status, migrasi, dan hapus tetap diproses `proses_psb_admin.php` dan
 * `hapus_siswa.php` (POST + CSRF).
 */

$psb = new PsbAdmin($koneksi);
$self = app_url('/admin/admin_data.php');
$bucket = '_psb_tambah';
$imporMaks = 1000;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string) ($_POST['aksi'] ?? (isset($_POST['tambah_manual']) ? 'tambah' : ''));
    if ($aksi === 'tambah') {
        try {
            $baru = $psb->tambah($_POST);
            ah_old_clear($bucket);
            ah_flash_set('success', 'Pendaftar ditambahkan dengan nomor ' . $baru['no'] . '.');
            ah_redirect($self . '?id=' . $baru['id']);
        } catch (PsbException $exception) {
            ah_old_keep($_POST, array_keys(PsbAdmin::FORM), $bucket);
            $_SESSION[$bucket . '_errors'] = $exception->errors();
            ah_flash_set('danger', $exception->getMessage() . ($exception->errors() === [] ? '' : ' Periksa isian bertanda merah.'));
            ah_redirect($self . '?action=create');
        }
    }
    if ($aksi === 'impor_periksa') {
        $berkas = $_FILES['berkas'] ?? null;
        $nama = is_array($berkas) ? (string) ($berkas['name'] ?? '') : '';
        if (!is_array($berkas) || ($berkas['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $berkas['tmp_name'])) {
            ah_flash_set('danger', 'Pilih berkas Excel (.xlsx) lebih dahulu.');
            ah_redirect($self . '?action=impor');
        }
        if (strtolower(pathinfo($nama, PATHINFO_EXTENSION)) !== 'xlsx' || (int) $berkas['size'] > 5 * 1024 * 1024) {
            ah_flash_set('danger', 'Berkas harus .xlsx dan paling besar 5 MB.');
            ah_redirect($self . '?action=impor');
        }
        require_once __DIR__ . '/SimpleXLSX.php';
        $xlsx = \Shuchkin\SimpleXLSX::parse((string) $berkas['tmp_name']);
        if (!$xlsx) {
            ah_flash_set('danger', 'Berkas tidak dapat dibaca. Pastikan formatnya .xlsx sesuai panduan kolom.');
            ah_redirect($self . '?action=impor');
        }
        $sheet = $xlsx->rows();
        if (count($sheet) - 1 > $imporMaks) {
            ah_flash_set('danger', 'Berkas berisi lebih dari ' . $imporMaks . ' baris. Pecah menjadi beberapa berkas.');
            ah_redirect($self . '?action=impor');
        }
        $hasil = $psb->periksaImpor($sheet);
        $_SESSION['_psb_impor'] = ['token' => bin2hex(random_bytes(16)), 'nama' => mb_substr(basename($nama), 0, 120), 'dibuat' => time()] + $hasil;
        ah_redirect($self . '?action=impor&langkah=periksa');
    }
    if ($aksi === 'impor_simpan' || $aksi === 'impor_batal') {
        $impor = $_SESSION['_psb_impor'] ?? null;
        unset($_SESSION['_psb_impor']);
        if ($aksi === 'impor_batal') {
            ah_redirect($self . '?action=impor');
        }
        if (!is_array($impor) || !hash_equals((string) $impor['token'], (string) ($_POST['token'] ?? '')) || time() - (int) $impor['dibuat'] > 3600) {
            ah_flash_set('warning', 'Hasil pemeriksaan sudah tidak berlaku atau sudah disimpan. Pilih berkas lagi.');
            ah_redirect($self . '?action=impor');
        }
        $simpan = $psb->simpanImpor($impor['siap']);
        $catatan = array_merge($impor['lewat'], $simpan['gagal']);
        ah_flash_set(
            $catatan === [] ? 'success' : 'warning',
            'Impor selesai: ' . $simpan['berhasil'] . ' pendaftar ditambahkan · ' . count($catatan) . ' baris dilewati.',
            $catatan === [] ? null : '<ul class="mb-0 mt-1 small">' . implode('', array_map(static fn (array $b): string => '<li>Baris ' . (int) $b['baris'] . ' (' . ah_e($b['nama']) . '): ' . ah_e($b['alasan']) . '</li>', array_slice($catatan, 0, 20))) . '</ul>'
        );
        ah_redirect($self . '?status=Baru');
    }
    http_response_code(422);
    ah_flash_set('danger', 'Tindakan tidak dikenal.');
    ah_redirect($self);
}

$filter = PsbAdmin::filter($_GET);
$halaman = max(1, (int) ($_GET['page'] ?? 1));
$action = in_array($_GET['action'] ?? '', ['create', 'impor'], true) ? (string) $_GET['action'] : '';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$bagian = in_array($_GET['bagian'] ?? '', ['berkas', 'riwayat'], true) ? (string) $_GET['bagian'] : 'biodata';
$dipilih = $id > 0 ? $psb->find($id) : null;
$counts = $psb->statusCounts($filter);
$daftar = $psb->page($filter, $halaman);
$saringan = array_filter($filter, static fn (string $v): bool => $v !== '');
$kembali = http_build_query($saringan + ($daftar['page'] > 1 ? ['page' => $daftar['page']] : []));
$listUrl = $self . ($kembali !== '' ? '?' . $kembali : '');
$detailUrl = static fn (int $pid, array $extra = []): string => $self . '?' . http_build_query($saringan + ($daftar['page'] > 1 ? ['page' => $daftar['page']] : []) + ['id' => $pid] + $extra);
$tahun = beranda_service()->tahunAktif();
if ($id > 0 && $dipilih === null) {
    http_response_code(404);
}

ah_page_open([
    'title' => 'Pendaftar PSB',
    'heading' => 'Pendaftar PSB' . ($tahun !== null ? ' ' . $tahun['tahun'] : ''),
    'user' => $currentUser,
    'active' => 'psb.pendaftar',
    'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'PSB'], ['label' => 'Pendaftar']],
    'actions' => '<a class="btn btn-outline-secondary" href="' . ah_e($self . '?action=impor') . '">Impor Excel</a>'
        . '<a class="btn btn-outline-secondary" href="' . ah_e(app_url('/admin/export_psb.php') . ($kembali !== '' ? '?' . $kembali : '')) . '">Ekspor</a>'
        . '<a class="btn btn-primary" href="' . ah_e($self . '?action=create') . '">Tambah pendaftar</a>',
]);
if ($id > 0 && $dipilih === null) {
    ah_note('danger', 'Pendaftar tidak ditemukan. Mungkin sudah dihapus; pilih dari daftar.');
}

// Chip status mempertahankan saringan lain dan kembali ke halaman 1.
$tanpaStatus = array_diff_key($saringan, ['status' => 1]);
$chips = [];
foreach (['Semua' => '', 'Baru' => 'Baru', 'Diterima' => 'Diterima', 'Cadangan' => 'Cadangan', 'Ditolak' => 'Ditolak', 'Dimigrasi' => 'Dimigrasi'] as $label => $nilai) {
    $query = http_build_query($tanpaStatus + ($nilai === '' ? [] : ['status' => $nilai]));
    $chips[] = ['label' => $label, 'count' => $counts[$label], 'active' => $filter['status'] === $nilai, 'url' => $self . ($query !== '' ? '?' . $query : '')];
}
echo ah_chips($chips, 'Saring menurut status');

// ----------------------------------------------------------------- Panel
if ($action === 'create'):
    ah_panel_open(['title' => 'Tambah pendaftar', 'close_url' => $listUrl, 'description' => 'Isian bertanda * wajib. NISN kosong diberi nomor sementara seperti sebelumnya.']);
    $isian = static function (string $field, string $type = 'text', array $attr = []) use ($bucket): string {
        [$label, $wajib] = PsbAdmin::FORM[$field];
        $extra = '';
        foreach ($attr as $k => $v) { $extra .= ' ' . $k . '="' . ah_e($v) . '"'; }
        return '<div class="mb-2"><label class="form-label" for="psb-' . $field . '">' . ah_e($label) . ($wajib ? ' <span aria-hidden="true">*</span>' : '') . '</label>'
            . '<input class="form-control" id="psb-' . $field . '" name="' . $field . '" type="' . $type . '" maxlength="' . (int) PsbAdmin::FORM[$field][2] . '"'
            . ($wajib ? ' required' : '') . $extra . ' value="' . ah_e(ah_old($field, null, $bucket)) . '">' . ah_field_error($field, $bucket) . '</div>';
    };
    ?>
    <?= ah_error_summary($bucket) ?>
    <form method="post" action="<?= ah_e($self) ?>">
        <?= Csrf::input() ?>
        <input type="hidden" name="aksi" value="tambah">
        <fieldset class="mb-3"><legend class="h6">Identitas santri</legend>
            <?= $isian('nama') ?><?= $isian('nisn', 'text', ['inputmode' => 'numeric']) ?><?= $isian('nik', 'text', ['inputmode' => 'numeric']) ?>
            <div class="mb-2"><label class="form-label" for="psb-jk">Jenis kelamin <span aria-hidden="true">*</span></label>
                <select class="form-select" id="psb-jk" name="jk" required>
                    <option value="L"<?= ah_old('jk', null, $bucket) !== 'P' ? ' selected' : '' ?>>Laki-laki</option>
                    <option value="P"<?= ah_old('jk', null, $bucket) === 'P' ? ' selected' : '' ?>>Perempuan</option>
                </select><?= ah_field_error('jk', $bucket) ?></div>
            <?= $isian('tmp_lahir') ?><?= $isian('tgl_lahir', 'date') ?>
        </fieldset>
        <fieldset class="mb-3"><legend class="h6">Asal &amp; tujuan sekolah</legend>
            <?= $isian('sekolah_asal') ?>
            <div class="mb-2"><label class="form-label" for="psb-jenjang">Unit tujuan <span aria-hidden="true">*</span></label>
                <select class="form-select" id="psb-jenjang" name="jenjang_tujuan" required>
                    <?php foreach (PsbAdmin::UNIT as $unit): ?><option<?= ah_old('jenjang_tujuan', null, $bucket) === $unit ? ' selected' : '' ?>><?= ah_e($unit) ?></option><?php endforeach; ?>
                </select><?= ah_field_error('jenjang_tujuan', $bucket) ?></div>
        </fieldset>
        <fieldset class="mb-3"><legend class="h6">Alamat domisili</legend>
            <?= $isian('alamat') ?><?= $isian('desa') ?><?= $isian('kecamatan') ?><?= $isian('kab_kota') ?><?= $isian('provinsi') ?>
        </fieldset>
        <fieldset class="mb-3"><legend class="h6">Orang tua/wali</legend>
            <?= $isian('ayah') ?><?= $isian('ibu') ?><?= $isian('no_hp_wali', 'tel', ['inputmode' => 'tel', 'placeholder' => '08…']) ?>
        </fieldset>
        <div class="ah-panel__foot">
            <a class="btn btn-outline-secondary" href="<?= ah_e($listUrl) ?>">Batal</a>
            <button class="btn btn-primary" type="submit">Simpan pendaftar</button>
        </div>
    </form>
    <?php
    ah_panel_close();
    ah_old_clear($bucket);

elseif ($action === 'impor'):
    $impor = $_SESSION['_psb_impor'] ?? null;
    $periksa = ($_GET['langkah'] ?? '') === 'periksa' && is_array($impor);
    ah_panel_open(['title' => 'Impor pendaftar dari Excel', 'close_url' => $listUrl]);
    ?>
    <ol class="ah-steps">
        <li<?= $periksa ? ' class="is-done"' : ' aria-current="step"' ?>>Pilih berkas</li>
        <li<?= $periksa ? ' aria-current="step"' : '' ?>>Periksa</li>
        <li>Simpan</li>
    </ol>
    <?php if (!$periksa): ?>
        <p class="small">Baris 1 (judul kolom) dilewati. Kolom dibaca dari kiri ke kanan:</p>
        <ul class="small">
            <li><b>A</b> nama lengkap (wajib), <b>B</b> NISN, <b>C</b> NIK, <b>D</b> L/P</li>
            <li><b>E–K</b> tempat lahir, tanggal lahir, alamat, desa, kecamatan, kab/kota, provinsi</li>
            <li><b>L–P</b> nama ayah, nama ibu, no. HP wali, asal sekolah, jenjang tujuan</li>
        </ul>
        <form method="post" action="<?= ah_e($self) ?>" enctype="multipart/form-data">
            <?= Csrf::input() ?>
            <input type="hidden" name="aksi" value="impor_periksa">
            <label class="ah-drop" data-ah-drop>
                <span>Tarik berkas ke sini atau pilih berkas</span>
                <input type="file" name="berkas" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                <span class="ah-drop__hint">.xlsx · paling besar 5 MB · paling banyak <?= $imporMaks ?> baris</span>
            </label>
            <p class="small text-muted mt-2">Berkas diperiksa lebih dahulu. Belum ada data yang disimpan sampai Anda menekan Simpan.</p>
            <div class="ah-panel__foot">
                <a class="btn btn-outline-secondary" href="<?= ah_e($listUrl) ?>">Batal</a>
                <button class="btn btn-primary" type="submit">Periksa berkas</button>
            </div>
        </form>
    <?php else: ?>
        <p><b><?= ah_e($impor['nama']) ?></b></p>
        <?= ah_stats([
            ['label' => 'Siap ditambahkan', 'value' => (string) count($impor['siap'])],
            ['label' => 'Dilewati', 'value' => (string) count($impor['lewat']), 'warn' => $impor['lewat'] !== []],
        ], 'Hasil pemeriksaan impor') ?>
        <?php if ($impor['lewat'] !== []): ?>
            <h3 class="h6">Baris yang dilewati</h3>
            <ul class="ah-list-plain small mb-3">
                <?php foreach (array_slice($impor['lewat'], 0, 50) as $b): ?><li><span>Baris <?= (int) $b['baris'] ?> · <?= ah_e($b['nama']) ?></span><span><?= ah_e($b['alasan']) ?></span></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($impor['siap'] !== []): ?>
            <h3 class="h6">Contoh baris yang akan ditambahkan</h3>
            <ul class="ah-list-plain small mb-3">
                <?php foreach (array_slice($impor['siap'], 0, 10) as $b): ?><li><span><?= ah_e($b['nama']) ?></span><span><?= ah_e($b['jenjang_tujuan']) ?> · <?= ah_e($b['jk']) ?></span></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <div class="ah-panel__foot">
            <form method="post" action="<?= ah_e($self) ?>"><?= Csrf::input() ?><input type="hidden" name="aksi" value="impor_batal"><button class="btn btn-outline-secondary" type="submit">Pilih berkas lain</button></form>
            <?php if ($impor['siap'] !== []): ?>
                <form method="post" action="<?= ah_e($self) ?>"><?= Csrf::input() ?><input type="hidden" name="aksi" value="impor_simpan"><input type="hidden" name="token" value="<?= ah_e($impor['token']) ?>">
                    <button class="btn btn-primary" type="submit">Simpan <?= count($impor['siap']) ?> pendaftar</button></form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php
    ah_panel_close();

elseif ($dipilih !== null):
    $d = $dipilih;
    $pid = (int) $d['id'];
    $berkasAda = count(array_filter(array_keys(PsbAdmin::BERKAS), static fn (string $c): bool => (string) ($d[$c] ?? '') !== ''));
    $tutup = $listUrl;
    $hidden = static fn (string $act, string $val = ''): string => Csrf::input()
        . '<input type="hidden" name="act" value="' . ah_e($act) . '"><input type="hidden" name="id" value="' . $pid . '">'
        . ($val !== '' ? '<input type="hidden" name="val" value="' . ah_e($val) . '">' : '')
        . '<input type="hidden" name="kembali" value="' . ah_e($kembali) . '">';
    $hp = preg_replace('/\D+/', '', (string) $d['no_hp_wali']);
    ah_panel_open(['title' => $d['no_pendaftaran'] . ' · ' . $d['nama_lengkap'], 'close_url' => $tutup, 'description' => 'Status: ' . $d['status']]);
    echo '<ul class="ah-tabs" aria-label="Bagian detail pendaftar">'
        . '<li><a href="' . ah_e($detailUrl($pid)) . '"' . ($bagian === 'biodata' ? ' aria-current="page"' : '') . '><span>Biodata</span></a></li>'
        . '<li><a href="' . ah_e($detailUrl($pid, ['bagian' => 'berkas'])) . '"' . ($bagian === 'berkas' ? ' aria-current="page"' : '') . '><span>Berkas ' . $berkasAda . '/6</span></a></li>'
        . '<li><a href="' . ah_e($detailUrl($pid, ['bagian' => 'riwayat'])) . '"' . ($bagian === 'riwayat' ? ' aria-current="page"' : '') . '><span>Riwayat</span></a></li></ul>';
    if ($bagian === 'biodata'):
        $tgl = strtotime((string) $d['tgl_lahir']);
        ?>
        <dl class="ah-kv">
            <dt>Status</dt><dd><?= ah_status((string) $d['status']) ?></dd>
            <dt>Unit tujuan</dt><dd><?= ah_e($d['jenjang_tujuan']) ?></dd>
            <dt>NISN · NIK</dt><dd><?= ah_e($d['nisn']) ?> · <?= ah_e($d['nik']) ?></dd>
            <dt>Tempat, tanggal lahir</dt><dd><?= ah_e($d['tempat_lahir']) ?>, <?= $tgl ? ah_e(\App\Ui\Layout::date($tgl)) : ah_e($d['tgl_lahir']) ?></dd>
            <dt>Jenis kelamin</dt><dd><?= $d['jenis_kelamin'] === 'P' ? 'Perempuan' : 'Laki-laki' ?></dd>
            <dt>Asal sekolah</dt><dd><?= ah_e($d['sekolah_asal'] ?: '—') ?></dd>
            <dt>Alamat</dt><dd><?= ah_e(trim($d['alamat_jalan'] . ', Ds. ' . $d['desa'] . ', Kec. ' . $d['kecamatan'] . ', ' . $d['kab_kota'] . ', ' . $d['provinsi'], ', ')) ?></dd>
            <dt>Orang tua</dt><dd><?= ah_e($d['nama_ayah'] ?: '—') ?> · <?= ah_e($d['nama_ibu'] ?: '—') ?></dd>
            <dt>WhatsApp wali</dt><dd><?php if ($hp !== ''): ?><a href="<?= ah_e('https://wa.me/' . (str_starts_with($hp, '0') ? '62' . substr($hp, 1) : $hp)) ?>" target="_blank" rel="noopener"><?= ah_e($d['no_hp_wali']) ?></a><?php else: ?>—<?php endif; ?></dd>
        </dl>
    <?php elseif ($bagian === 'berkas'): ?>
        <p class="small text-muted">Pratinjau dibuka lewat jalur yang memeriksa hak akses admin. Klik berkas untuk membukanya di tab baru.</p>
        <ul class="ah-media">
            <?php foreach (PsbAdmin::BERKAS as $kolom => $label):
                $namaBerkas = (string) ($d[$kolom] ?? '');
                $ext = strtolower(pathinfo($namaBerkas, PATHINFO_EXTENSION));
                $urlBerkas = app_url('/lihat_berkas_psb.php?' . http_build_query(['id' => $pid, 'jenis' => $kolom])); ?>
                <li>
                    <?php if ($namaBerkas !== ''): ?>
                        <a href="<?= ah_e($urlBerkas) ?>" target="_blank" rel="noopener">
                            <?php if (in_array($ext, ['jpg', 'jpeg', 'png'], true)): ?>
                                <img src="<?= ah_e($urlBerkas) ?>" alt="Pratinjau <?= ah_e($label) ?>" loading="lazy">
                            <?php else: ?>
                                <span class="ah-media__ph" aria-hidden="true"><?= ah_e($ext === 'pdf' ? 'PDF' : 'Berkas') ?></span>
                            <?php endif; ?>
                            <span class="ah-media__cap"><b><?= ah_e($label) ?></b><small>Lihat<span class="ah-visually-hidden"> <?= ah_e($label) ?> (tab baru)</span></small></span>
                        </a>
                    <?php else: ?>
                        <span class="ah-media__ph" style="border:1px dashed var(--ah-line-strong);border-radius:var(--ah-radius)"><?= ah_badge($kolom === 'file_prestasi' ? 'Tidak ada' : 'Belum ada', $kolom === 'file_prestasi' ? 'muted' : 'warn') ?></span>
                        <span class="ah-media__cap"><b><?= ah_e($label) ?></b></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else:
        $bayar = $psb->pembayaran((string) $d['no_pendaftaran']); ?>
        <ul class="ah-list-plain">
            <li><span>Mendaftar</span><b><?= $d['tgl_daftar'] ? ah_e(date('d-m-Y H.i', strtotime((string) $d['tgl_daftar']))) : '—' ?></b></li>
            <li><span>Status sekarang</span><?= ah_status((string) $d['status']) ?></li>
            <?php if ($bayar !== null): ?>
                <li><span>Tagihan <?= ah_e($bayar['kategori_biaya']) ?></span><b>Rp <?= number_format((int) $bayar['total_keseluruhan'], 0, ',', '.') ?></b></li>
                <li><span>Pembayaran</span><span><?= ah_status((string) $bayar['status_pembayaran']) ?><?= $bayar['waktu_lunas'] ? ' ' . ah_e(date('d-m-Y H.i', strtotime((string) $bayar['waktu_lunas']))) : '' ?></span></li>
                <li><span></span><a href="<?= ah_e(app_url('/admin/admin_pembayaran_psb.php?id=' . (int) $bayar['id'])) ?>">Buka rincian pembayaran</a></li>
            <?php else: ?>
                <li><span>Pembayaran</span><span class="ah-meta">Belum ada tagihan</span></li>
            <?php endif; ?>
        </ul>
        <p class="small text-muted mt-2">Perubahan status lama tidak dicatat per tanggal; yang tampil adalah keadaan sekarang.</p>
    <?php endif; ?>

    <div class="ah-panel__foot ah-decision">
        <?php if (in_array($d['status'], ['Baru', 'Cadangan'], true)): ?>
            <form id="formStatusTolak<?= $pid ?>" method="post" action="proses_psb_admin.php" data-confirm="Status <?= ah_e($d['nama_lengkap']) ?> menjadi Ditolak." data-confirm-title="Tolak pendaftar ini?" data-confirm-ok="Tolak" data-confirm-tone="danger"><?= $hidden('status', 'Ditolak') ?><button class="btn btn-outline-danger" type="submit">Tolak</button></form>
            <?php if ($d['status'] === 'Baru'): ?>
                <form method="post" action="proses_psb_admin.php"><?= $hidden('status', 'Cadangan') ?><button class="btn btn-outline-secondary" type="submit">Cadangan</button></form>
            <?php endif; ?>
            <form id="formStatusTerima<?= $pid ?>" method="post" action="proses_psb_admin.php"><?= $hidden('status', 'Diterima') ?><button class="btn btn-primary" type="submit">Terima</button></form>
        <?php elseif ($d['status'] === 'Diterima'): ?>
            <form method="post" action="proses_psb_admin.php" data-confirm="Data <?= ah_e($d['nama_lengkap']) ?> disalin ke data santri dengan NIS baru, lalu status pendaftar menjadi Dimigrasi." data-confirm-title="Migrasi ke data santri?" data-confirm-ok="Migrasi"><?= $hidden('migrasi') ?><button class="btn btn-primary" type="submit">Migrasi ke data santri</button></form>
        <?php endif; ?>
        <?= ah_menu([
            ['label' => 'Cetak bukti pendaftaran', 'url' => app_url('/cetak_bukti.php?noreg=' . rawurlencode((string) $d['no_pendaftaran'])), 'icon' => 'fa-print'],
            ['label' => 'Hapus pendaftar', 'icon' => 'fa-trash', 'danger' => true, 'action' => 'hapus_siswa.php', 'fields' => ['id' => $pid, 'kembali' => $kembali],
                'confirm' => ['title' => 'Hapus ' . $d['nama_lengkap'] . '?', 'text' => 'Data pendaftaran dihapus permanen dan tidak dapat dikembalikan.', 'ok' => 'Hapus permanen',
                    'match' => (string) $d['nama_lengkap'], 'match_label' => 'Ketik nama']],
        ], 'Tindakan lain untuk ' . $d['nama_lengkap'], false) ?>
    </div>
    <?php
    ah_panel_close();
endif;

// --------------------------------------------------------------- Daftar
ah_filter_open(count(array_diff_key($saringan, ['status' => 1])), ['status' => $filter['status']]);
?>
    <div class="ah-filter__wide"><label class="form-label" for="psb-cari">Cari</label>
        <input class="form-control" type="search" id="psb-cari" name="cari" maxlength="100" value="<?= ah_e($filter['cari']) ?>" placeholder="Nama, no. daftar, atau NISN"></div>
    <div><label class="form-label" for="psb-f-jenjang">Unit tujuan</label>
        <select class="form-select" id="psb-f-jenjang" name="jenjang"><option value="">Semua unit</option>
            <?php foreach (PsbAdmin::UNIT as $unit): ?><option<?= $filter['jenjang'] === $unit ? ' selected' : '' ?>><?= ah_e($unit) ?></option><?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="psb-f-jk">L/P</label>
        <select class="form-select" id="psb-f-jk" name="jk"><option value="">Semua</option><option value="L"<?= $filter['jk'] === 'L' ? ' selected' : '' ?>>Laki-laki</option><option value="P"<?= $filter['jk'] === 'P' ? ' selected' : '' ?>>Perempuan</option></select></div>
    <div><label class="form-label" for="psb-f-kec">Kecamatan</label>
        <input class="form-control" id="psb-f-kec" name="kecamatan" maxlength="50" value="<?= ah_e($filter['kecamatan']) ?>"></div>
<?php ah_filter_close(['status' => $filter['status']]); ?>
<div class="ah-card ah-table-wrap">
    <table class="ah-table ah-table--cards" id="daftar-pendaftar">
        <caption class="ah-visually-hidden">Pendaftar PSB, <?= (int) $daftar['total'] ?> data</caption>
        <thead><tr><th scope="col">No. daftar</th><th scope="col">Nama</th><th scope="col">Unit tujuan</th><th scope="col" class="ah-col-secondary">Asal sekolah</th><th scope="col">Berkas</th><th scope="col">Status</th></tr></thead>
        <tbody>
        <?php foreach ($daftar['rows'] as $row): ?>
            <tr<?= (int) $row['id'] === $id ? ' class="is-selected"' : '' ?>>
                <td><?= ah_e($row['no_pendaftaran']) ?></td>
                <td><a class="ah-row-link" href="<?= ah_e($detailUrl((int) $row['id'])) ?>"><?= ah_e($row['nama_lengkap']) ?></a></td>
                <td><?= ah_e($row['jenjang_tujuan']) ?></td>
                <td class="ah-col-secondary"><?= ah_e($row['sekolah_asal'] ?: '—') ?></td>
                <td><?= (int) $row['berkas'] ?>/6</td>
                <td><?= ah_status((string) $row['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($daftar['rows'] === []): ?>
            <tr><td colspan="6" data-label=""><?= ah_empty('Tidak ada pendaftar yang cocok', $saringan === [] ? 'Pendaftar baru akan tampil di sini.' : 'Bersihkan saringan atau pilih chip status lain.') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
ah_pagination((int) $daftar['total'], (int) $daftar['page'], PsbAdmin::PER_PAGE);
ah_page_close();
