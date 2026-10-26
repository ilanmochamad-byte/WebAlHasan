<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Notification\NotificationException;
use App\Ui\Layout;

/**
 * Pusat notifikasi website (V2 Fase 4; redesain website Tahap 2, N-3).
 *
 * **Keputusan AK (22 Oktober 2026):** halaman ini terbuka bagi SEMUA akun yang
 * sudah masuk — termasuk guru mapel dan Bagian Pendidikan tanpa peran
 * perizinan. Penjaga khusus perizinan (`portal/_guard.php`) diganti penjaga
 * "akun masuk". Setiap akun hanya melihat notifikasinya sendiri: penerima
 * selalu diambil dari sesi dan setiap query `NotificationRepository`
 * mewajibkan `penerima_user_id`, sehingga mengganti `id` pada URL atau body
 * menghasilkan 403, bukan notifikasi milik orang lain.
 *
 * Menyediakan: chip Semua/Belum dibaca/Sudah dibaca dan kategori dari jenis
 * peristiwa yang sudah tercatat (Perizinan, Rapor, Pembinaan, Kesehatan, Keuangan), pengelompokan
 * per hari, buka-dan-tandai-dibaca, detail, tandai semua dibaca, pagination,
 * serta keadaan kosong dan galat.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$currentUser = authorization()->requireWebUser();
$center = notification_center_service();
$perPage = 20;
$alamat = app_url('/portal/notifikasi.php');

// --- Mutasi (POST) ----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    $aksi = (string) ($_POST['aksi'] ?? '');
    try {
        if ($aksi === 'tandai_dibaca') {
            $center->markRead($currentUser, (int) ($_POST['id'] ?? 0));
            ah_flash_set('success', 'Notifikasi ditandai sudah dibaca.');
        } elseif ($aksi === 'tandai_semua') {
            $hasil = $center->markAllRead($currentUser);
            ah_flash_set('success', $hasil['ditandai'] . ' notifikasi ditandai sudah dibaca.');
        } else {
            ah_flash_set('danger', 'Aksi tidak dikenal.');
        }
    } catch (NotificationException $exception) {
        ah_flash_set('danger', $exception->getMessage());
    }

    header('Location: ' . $alamat . '?' . ah_query(['aksi' => null, 'id' => null]));
    exit;
}

// --- Buka: tandai dibaca lalu menuju halaman tujuan --------------------------
// Tautan hanya membawa ID. Halaman tujuan (detail izin, rapor, informasi
// pembinaan) tetap memeriksa hak dan cakupan akun di server sebelum
// menampilkan apa pun.
$galat = null;
$bukaId = (int) ($_GET['buka'] ?? 0);
if ($bukaId > 0) {
    try {
        $buka = $center->markRead($currentUser, $bukaId)['notifikasi'];
        $tujuan = notifikasi_tujuan($buka);
        header('Location: ' . ($tujuan ?? $alamat . '?detail=' . $bukaId));
        exit;
    } catch (NotificationException $exception) {
        // Sama dengan detail: daftar milik sendiri tetap tampil dengan pesan
        // penolakan; isi notifikasi orang lain tidak pernah dibaca.
        $galat = $exception->getMessage();
    }
}

/**
 * Alamat tujuan notifikasi, atau null bila notifikasi tidak menunjuk halaman.
 *
 * @param array<string, mixed> $item
 */
function notifikasi_tujuan(array $item): ?string
{
    $tautan = $item['tautan'] ?? [];
    if (($tautan['tipe'] ?? '') === 'rapor' && (int) ($tautan['rapor_id'] ?? 0) > 0) {
        return app_url('/portal/rapor_wali.php?id=' . (int) $tautan['rapor_id']);
    }
    if (($tautan['tipe'] ?? '') === 'v3_publikasi' && (int) ($tautan['publikasi_id'] ?? 0) > 0) {
        return app_url('/portal/v3_publikasi.php?id=' . (int) $tautan['publikasi_id']);
    }
    // PRD V5 Fase 3: hanya penunjuk; halaman tujuan memeriksa ulang relasi wali aktif.
    if (($tautan['tipe'] ?? '') === 'kesehatan_ringkasan' && (int) ($tautan['ringkasan_id'] ?? 0) > 0) {
        return app_url('/portal/kesehatan_wali.php?id=' . (int) $tautan['ringkasan_id']);
    }
    // PRD V6 Fase 4: penunjuk keuangan; halaman BSA memeriksa ulang hak (wali anak sendiri).
    $bsa = ['bsa_tagihan' => ['tagihan_id', '/portal/bsa_tagihan.php'], 'bsa_pembayaran' => ['pembayaran_id', '/portal/bsa_kuitansi.php'],
        'bsa_tabungan' => ['transaksi_id', '/portal/bsa_tabungan_kuitansi.php']][$tautan['tipe'] ?? ''] ?? null;
    if ($bsa !== null && (int) ($tautan[$bsa[0]] ?? 0) > 0) {
        return app_url($bsa[1] . '?id=' . (int) $tautan[$bsa[0]]);
    }
    if (($item['pengajuan_id'] ?? null) !== null) {
        return app_url('/portal/izin_detail.php?id=' . (int) $item['pengajuan_id']);
    }

    return null;
}

/**
 * Kelompok hari: Hari ini, Kemarin, Pekan ini, Lebih lama.
 */
function notifikasi_kelompok(string $waktu): string
{
    $time = strtotime($waktu);
    if ($time === false) {
        return 'Lebih lama';
    }
    $hari = (int) floor((strtotime('today') - strtotime(date('Y-m-d', $time))) / 86400);

    return match (true) {
        $hari <= 0 => 'Hari ini',
        $hari === 1 => 'Kemarin',
        $hari < 7 => 'Pekan ini',
        default => 'Lebih lama',
    };
}

// --- Tampilan (GET) ---------------------------------------------------------
$data = null;
$detail = null;
$detailId = (int) ($_GET['detail'] ?? 0);

try {
    $data = $center->index($currentUser, [
        'status' => (string) ($_GET['status'] ?? 'semua'),
        'jenis' => (string) ($_GET['jenis'] ?? ''),
        'page' => (int) ($_GET['page'] ?? 1),
        'per_page' => $perPage,
    ]);
    if ($detailId > 0) {
        // Membuka detail sekaligus menandai dibaca: satu langkah, satu maksud.
        $detail = $center->markRead($currentUser, $detailId)['notifikasi'];
        $data['jumlah_belum_dibaca'] = $center->unreadCount($currentUser)['jumlah'];
    }
} catch (NotificationException $exception) {
    // 403 karena ID milik pengguna lain TIDAK boleh menjatuhkan seluruh halaman;
    // daftar tetap tampil dengan pesan galat yang jelas.
    $galat = $exception->getMessage();
    if ($data === null) {
        http_response_code($exception->status());
    }
}

$filterAktif = (string) ($data['filters']['status'] ?? 'semua');
$jenisAktif = $data['filters']['jenis'] ?? null;
$belumDibaca = (int) ($data['jumlah_belum_dibaca'] ?? 0);

ah_page_open([
    'title' => 'Notifikasi',
    // Ringkasan jumlah belum dibaca tetap tertulis (kontrak V2 Fase 4 WN-3b).
    'description' => 'Pemberitahuan untuk akun Anda. Ketuk notifikasi untuk membuka halaman tujuannya. '
        . ($belumDibaca > 0 ? $belumDibaca . ' notifikasi belum dibaca.' : 'Semua sudah dibaca.'),
    'user' => $currentUser,
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Notifikasi'],
    ],
    // Formulir ini selalu dirender (tombolnya yang dinonaktifkan saat tidak ada
    // yang perlu ditandai) sehingga token CSRF halaman selalu tersedia.
    'actions' => '<form method="post" class="mb-0">' . Csrf::input()
        . '<input type="hidden" name="aksi" value="tandai_semua">'
        . '<button class="btn btn-outline-primary" type="submit"' . ($belumDibaca > 0 ? '' : ' disabled') . '>'
        . '<i class="fas fa-check-double" aria-hidden="true"></i> Tandai semua dibaca</button></form>',
]);

$chips = [
    ['label' => 'Semua', 'query' => ['status' => null, 'jenis' => null], 'aktif' => $filterAktif === 'semua' && $jenisAktif === null],
    ['label' => 'Belum dibaca', 'badge' => $belumDibaca, 'query' => ['status' => 'belum_dibaca'], 'aktif' => $filterAktif === 'belum_dibaca'],
    ['label' => 'Sudah dibaca', 'query' => ['status' => 'sudah_dibaca'], 'aktif' => $filterAktif === 'sudah_dibaca'],
    ['label' => 'Perizinan', 'query' => ['jenis' => 'perizinan'], 'aktif' => $jenisAktif === 'perizinan'],
    ['label' => 'Rapor', 'query' => ['jenis' => 'rapor'], 'aktif' => $jenisAktif === 'rapor'],
    ['label' => 'Pembinaan', 'query' => ['jenis' => 'pembinaan'], 'aktif' => $jenisAktif === 'pembinaan'],
    ['label' => 'Kesehatan', 'query' => ['jenis' => 'kesehatan'], 'aktif' => $jenisAktif === 'kesehatan'],
    ['label' => 'Keuangan', 'query' => ['jenis' => 'keuangan'], 'aktif' => $jenisAktif === 'keuangan'],
];
?>

<ul class="ah-tabs ah-chips" aria-label="Saring notifikasi">
    <?php foreach ($chips as $chip): ?>
        <li><a href="<?= ah_e($alamat . '?' . ah_query($chip['query'] + ['page' => null, 'detail' => null, 'buka' => null])) ?>"<?= $chip['aktif'] ? ' aria-current="page"' : '' ?>>
            <span><?= ah_e($chip['label']) ?></span>
            <?php if (!empty($chip['badge'])): ?><span class="ah-badge ah-badge--info"><?= (int) $chip['badge'] ?></span><?php endif; ?>
        </a></li>
    <?php endforeach; ?>
</ul>

<?php if ($galat !== null): ?>
    <?php Layout::note('danger', 'Notifikasi tidak dapat dibuka. ' . $galat); ?>
<?php endif; ?>

<?php if ($detail !== null): ?>
    <section class="ah-card" aria-labelledby="ah-notif-detail">
        <div class="ah-card__body">
            <p class="mb-2"><?= ah_badge($detail['event_label'], 'muted') ?></p>
            <h2 class="h5 mb-1" id="ah-notif-detail"><?= ah_e($detail['judul']) ?></h2>
            <p class="mb-2"><?= ah_e($detail['isi']) ?></p>
            <p class="text-muted small">
                Diterima <?= ah_e($detail['dibuat_pada']) ?>
                <?php if ($detail['dibaca_pada'] !== null): ?> · dibaca <?= ah_e($detail['dibaca_pada']) ?><?php endif; ?>
            </p>
            <div class="d-flex flex-wrap gap-2">
                <?php $tujuanDetail = notifikasi_tujuan($detail); ?>
                <?php if ($tujuanDetail !== null): ?>
                    <a class="btn btn-primary" href="<?= ah_e($tujuanDetail) ?>"><?= ah_e(match (true) {
                        ($detail['tautan']['tipe'] ?? '') === 'rapor' => 'Buka rapor semester',
                        ($detail['tautan']['tipe'] ?? '') === 'v3_publikasi' => 'Buka informasi pembinaan',
                        ($detail['tautan']['tipe'] ?? '') === 'kesehatan_ringkasan' => 'Buka informasi kesehatan',
                        ($detail['tautan']['tipe'] ?? '') === 'bsa_tagihan' => 'Buka tagihan',
                        ($detail['tautan']['tipe'] ?? '') === 'bsa_pembayaran' => 'Buka kuitansi pembayaran',
                        ($detail['tautan']['tipe'] ?? '') === 'bsa_tabungan' => 'Buka transaksi tabungan',
                        default => 'Buka detail izin',
                    }) ?></a>
                <?php endif; ?>
                <a class="btn btn-outline-secondary" href="<?= ah_e($alamat . '?' . ah_query(['detail' => null])) ?>">Tutup</a>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($data === null): ?>
    <?= ah_empty('Daftar notifikasi tidak dapat dimuat', 'Muat ulang halaman untuk mencoba lagi.') ?>
<?php elseif ($data['items'] === []): ?>
    <?= ah_empty(
        'Belum ada notifikasi',
        $filterAktif === 'belum_dibaca'
            ? 'Semua notifikasi Anda sudah dibaca.'
            : ($jenisAktif !== null
                ? 'Belum ada notifikasi untuk kategori ini.'
                : 'Pemberitahuan perizinan, rapor, dan pembinaan untuk akun Anda akan tampil di sini.'),
        $filterAktif !== 'semua' || $jenisAktif !== null
            ? '<a class="btn btn-sm btn-outline-primary" href="' . ah_e($alamat) . '">Lihat semua notifikasi</a>'
            : null
    ) ?>
<?php else: ?>
    <?php
    $kelompok = array_fill_keys(['Hari ini', 'Kemarin', 'Pekan ini', 'Lebih lama'], []);
    foreach ($data['items'] as $item) {
        $kelompok[notifikasi_kelompok((string) $item['dibuat_pada'])][] = $item;
    }
    $kelompok = array_filter($kelompok);
    ?>
    <?php foreach ($kelompok as $judulKelompok => $items): ?>
        <section class="ah-notif-group" aria-label="<?= ah_e($judulKelompok) ?>">
            <h2 class="ah-notif-group__title"><?= ah_e($judulKelompok) ?></h2>
            <ul class="ah-notif list-unstyled">
                <?php foreach ($items as $item): ?>
                    <li class="ah-notif__item<?= $item['dibaca'] ? '' : ' is-unread' ?>">
                        <a class="ah-notif__link" href="<?= ah_e($alamat . '?buka=' . (int) $item['id']) ?>">
                            <span class="ah-notif__main">
                                <b><?= ah_e($item['judul']) ?></b>
                                <span class="ah-notif__isi"><?= ah_e($item['isi']) ?></span>
                                <small><?= ah_e($item['event_label']) ?> · <?= ah_e(Layout::shortTime((string) $item['dibuat_pada'])) ?></small>
                            </span>
                            <span class="ah-notif__state">
                                <?= ah_status($item['dibaca'] ? 'Dibaca' : 'Belum dibaca') ?>
                                <?php if ($item['pengajuan_status'] !== null): ?><?= ah_status((string) $item['pengajuan_status']) ?><?php endif; ?>
                            </span>
                        </a>
                        <?php if (!$item['dibaca']): ?>
                            <form method="post" class="ah-notif__mark">
                                <?= Csrf::input() ?>
                                <input type="hidden" name="aksi" value="tandai_dibaca">
                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" type="submit">Tandai dibaca</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
    <?php ah_pagination(
        (int) $data['pagination']['total'],
        (int) $data['pagination']['current_page'],
        (int) $data['pagination']['per_page']
    ); ?>
<?php endif; ?>

<?php ah_page_close(); ?>
