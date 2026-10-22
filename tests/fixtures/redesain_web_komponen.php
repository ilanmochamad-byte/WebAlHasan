<?php

declare(strict_types=1);

/**
 * Galeri komponen bersama redesain website Tahap 1 — HANYA UNTUK UJI.
 *
 * Dilayani lewat `tests/redesain_web_tahap1_router.php` pada alamat
 * `/__uji/komponen.php`; router produksi menolak seluruh folder tests/.
 * Halaman ini menampilkan setiap komponen dengan data contoh agar dapat
 * diuji di browser sebelum halaman asli memakainya pada tahap berikutnya.
 * Ia tidak menulis ke database: semua formulir mengarah ke halaman ini
 * sendiri dan hanya memantulkan isian yang diterima.
 *
 * Wajib masuk sebagai admin, sama seperti halaman admin.
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Http\Csrf;

$currentUser = authorization()->requireWebRole('admin');
$self = '/__uji/komponen.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (($_POST['mode'] ?? '') === 'massal') {
        // Simulasi tindakan per data untuk proses massal: id kelipatan 5 gagal.
        $id = (int) ($_POST['id'] ?? 0);
        usleep(60000);
        header('Content-Type: application/json; charset=utf-8');
        if ($id % 5 === 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Nilai santri ' . $id . ' belum lengkap.']);
        } else {
            echo json_encode(['ok' => true, 'id' => $id]);
        }
        exit;
    }
    if (($_POST['nama'] ?? '') === '') {
        ah_validation_keep($_POST, ['nama'], new RuntimeException('Nama guru wajib diisi.'), '_uji_komponen');
        ah_redirect($self . '?action=create');
    }
    ah_old_clear('_uji_komponen');
    $alasan = trim((string) ($_POST['alasan'] ?? ''));
    ah_flash_set('success', 'Diterima: ' . ($_POST['tindakan'] ?? 'simpan') . ' ' . ($_POST['nama'] ?? '')
        . ($alasan !== '' ? ' (alasan: ' . $alasan . ')' : '') . '.');
    ah_redirect($self);
}

$action = (string) ($_GET['action'] ?? '');
$daftar = [
    ['id' => 1, 'nip' => '1987001', 'nama' => 'Ust. Mahmud Fauzi', 'hp' => '0812 0000 0001', 'status' => 'Aktif'],
    ['id' => 2, 'nip' => '1987002', 'nama' => 'Ust. Ahmad Mubarok', 'hp' => '0812 0000 0002', 'status' => 'Menunggu'],
    ['id' => 3, 'nip' => '1987003', 'nama' => 'Ustz. Siti Aminah dengan nama yang sangat panjang sekali', 'hp' => '0812 0000 0003', 'status' => 'Nonaktif'],
    ['id' => 4, 'nip' => '1987004', 'nama' => 'Ust. Rahmat Hidayat', 'hp' => '0812 0000 0004', 'status' => 'Ditolak'],
    ['id' => 5, 'nip' => '1987005', 'nama' => 'Ust. Dedi Kurnia', 'hp' => '0812 0000 0005', 'status' => 'Draf'],
];

ah_page_open([
    'title' => 'Galeri komponen',
    'description' => 'Halaman uji Tahap 1: kerangka, panel samping, dialog, toast, kamus status, menu ⋯, dan proses massal.',
    'user' => $currentUser,
    'active' => 'master.guru',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Uji'],
        ['label' => 'Galeri komponen'],
    ],
    'actions' => '<a class="btn btn-primary" href="' . ah_e($self . '?action=create') . '" id="uji-tambah"><i class="fas fa-plus" aria-hidden="true"></i> Tambah guru</a>'
        . ah_menu([
            ['label' => 'Ekspor CSV', 'url' => '#ekspor', 'icon' => 'fa-file-csv'],
            ['label' => 'Cetak', 'url' => '#cetak', 'icon' => 'fa-print'],
        ], 'Tindakan halaman lain', false),
    'tabs' => [
        ['label' => 'Guru', 'url' => $self, 'active' => true, 'badge' => '5'],
        ['label' => 'Pengurus', 'url' => $self . '?tab=pengurus'],
        ['label' => 'Penugasan aktif', 'url' => $self . '?tab=penugasan'],
        ['label' => 'Riwayat perubahan', 'url' => $self . '?tab=riwayat'],
        ['label' => 'Arsip', 'url' => $self . '?tab=arsip'],
    ],
]);

if ($action === 'create') {
    ah_panel_open(['title' => 'Tambah guru', 'close_url' => $self, 'description' => 'Isian bertanda * wajib.']);
    ?>
    <?= ah_error_summary('_uji_komponen') ?>
    <form method="post" action="<?= ah_e($self) ?>" id="uji-form-panel">
        <div class="mb-3">
            <label class="form-label" for="nip">NIP</label>
            <input class="form-control" id="nip" name="nip" inputmode="numeric">
        </div>
        <div class="mb-3">
            <label class="form-label" for="nama">Nama lengkap <span aria-hidden="true">*</span></label>
            <input class="form-control" id="nama" name="nama" value="<?= ah_e(ah_old('nama', null, '_uji_komponen')) ?>">
            <?= ah_field_error('nama', '_uji_komponen') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="hp">Nomor HP</label>
            <input class="form-control" id="hp" name="hp" inputmode="tel" placeholder="08…">
        </div>
        <input type="hidden" name="tindakan" value="simpan">
        <div class="ah-panel__foot">
            <a class="btn btn-outline-secondary" href="<?= ah_e($self) ?>">Batal</a>
            <button class="btn btn-primary" type="submit">Simpan guru</button>
        </div>
    </form>
    <?php
    ah_panel_close();
}
?>

<section class="ah-card" aria-labelledby="uji-daftar">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="uji-daftar">Daftar guru (tabel menjadi kartu di HP)</h2></div>
    <div data-ah-selection="guru" id="uji-pilihan">
        <div class="ah-table-wrap">
            <table class="ah-table ah-table--cards">
                <caption class="ah-visually-hidden">Daftar guru contoh</caption>
                <thead><tr>
                    <th scope="col"><input type="checkbox" class="form-check-input" data-ah-select-all aria-label="Pilih semua guru"></th>
                    <th scope="col">NIP</th><th scope="col">Nama</th><th scope="col">HP</th><th scope="col">Status</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th>
                </tr></thead>
                <tbody>
                <?php foreach ($daftar as $baris): ?>
                    <tr>
                        <td data-label=""><input type="checkbox" class="form-check-input" data-ah-select value="<?= (int) $baris['id'] ?>" aria-label="Pilih <?= ah_e($baris['nama']) ?>"></td>
                        <td><?= ah_e($baris['nip']) ?></td>
                        <td><?= ah_e($baris['nama']) ?></td>
                        <td><?= ah_e($baris['hp']) ?></td>
                        <td><?= ah_status($baris['status']) ?></td>
                        <td data-label=""><?= ah_menu([
                            ['label' => 'Ubah', 'url' => $self . '?action=create&id=' . $baris['id'], 'icon' => 'fa-pen'],
                            ['label' => 'Lihat penugasan', 'url' => '#penugasan', 'icon' => 'fa-id-badge'],
                            ['label' => 'Nonaktifkan', 'icon' => 'fa-ban', 'danger' => true, 'action' => $self,
                             'fields' => ['nama' => $baris['nama'], 'tindakan' => 'nonaktifkan'],
                             'confirm' => ['title' => 'Nonaktifkan ' . $baris['nama'] . '?',
                                           'text' => $baris['nama'] . ' tidak bisa dipilih untuk jadwal baru. Jadwal dan riwayat tetap tersimpan.',
                                           'ok' => 'Nonaktifkan', 'reason' => 'alasan', 'reason_label' => 'Alasan menonaktifkan']],
                        ], 'Tindakan untuk ' . $baris['nama']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= ah_selection_bar('<button class="btn btn-light btn-sm" type="button" id="uji-massal">Proses yang dipilih</button>') ?>
    </div>
</section>

<div id="uji-massal-hasil"></div>

<section class="ah-card" aria-labelledby="uji-status">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="uji-status">Kamus status</h2></div>
    <div class="ah-card__body d-flex flex-wrap gap-2" id="uji-kamus">
        <?php foreach (['Selesai', 'Disetujui', 'Lunas', 'Terbit', 'Diajukan', 'Baru', 'Diserahkan', 'Menunggu', 'Perlu Perbaikan', 'Belum Lunas', 'Ditolak', 'Ditarik', 'Gagal', 'Draf', 'Arsip', 'Dibatalkan'] as $status): ?>
            <?= ah_status($status) ?>
        <?php endforeach; ?>
    </div>
</section>

<section class="ah-card" aria-labelledby="uji-kosong">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="uji-kosong">Keadaan kosong</h2></div>
    <?= ah_empty('Belum ada pengajuan bulan ini', 'Pengajuan yang dibuat pengurus akan muncul di sini.', '<a class="btn btn-primary" href="#">Ajukan izin</a>') ?>
</section>

<section class="ah-card" aria-labelledby="uji-konfirmasi">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="uji-konfirmasi">Dialog konfirmasi lama (data-confirm)</h2></div>
    <div class="ah-card__body">
        <form method="post" action="<?= ah_e($self) ?>" data-confirm="Data contoh akan diarsipkan." id="uji-form-lama">
            <input type="hidden" name="nama" value="Contoh">
            <button class="btn btn-outline-danger" type="submit" name="tindakan" value="arsipkan">Arsipkan contoh</button>
        </form>
    </div>
</section>

<script>
document.getElementById('uji-massal').addEventListener('click', function () {
    var scope = document.getElementById('uji-pilihan');
    var items = AlHasan.selected(scope).map(function (id) { return {id: id, label: 'Santri ' + id}; });
    AlHasan.bulk({
        container: '#uji-massal-hasil',
        title: 'Memproses ' + items.length + ' data',
        items: items,
        run: function (item) { return AlHasan.post('<?= ah_e($self) ?>', {mode: 'massal', id: item.id}); }
    });
});
</script>
<?php
ah_page_close();
