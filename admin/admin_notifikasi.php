<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Notification\NotificationChannel;
use App\Notification\NotificationException;

require_once __DIR__ . '/_guard.php';

/**
 * Kanal notifikasi (V2 Fase 4; redesain website Tahap 3, N-2).
 *
 * Tab Kanal · Pengiriman gagal · Riwayat di dalam kerangka bersama. Satu
 * tombol utama per kanal; tindakan lain di menu ⋯. Kartu WhatsApp tampil
 * "Ditangguhkan" tanpa Nyalakan, Kirim uji, atau Jalankan worker (keputusan
 * AL); penguncian yang sama berlaku di `NotificationAdminService`, sehingga
 * API pun tidak dapat menyalakannya. Mematikan Push meminta konfirmasi.
 *
 * Halaman ini tidak pernah menampilkan credential, token perangkat, atau nomor
 * tujuan. Yang ditampilkan hanyalah NAMA environment yang dibutuhkan, status
 * pemeriksaan, dan pesan galat yang sudah dibersihkan `SafeError`.
 *
 * Seluruh otorisasi dikerjakan `NotificationAdminService` di server;
 * `_guard.php` hanya lapisan pertama.
 */

$service = notification_admin_service();
$meta = [
    'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
    'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null,
];
$tab = in_array($_GET['tab'] ?? '', ['gagal', 'riwayat'], true) ? (string) $_GET['tab'] : 'kanal';

/**
 * @param array<string, mixed> $hasil
 * @return array<int, string>
 */
function notif_detail(array $hasil): array
{
    return array_values(array_filter([
        ...(array) ($hasil['detail'] ?? []),
        ...(array) ($hasil['catatan'] ?? []),
        isset($hasil['worker']) && is_array($hasil['worker'])
            ? 'Worker: diproses ' . (int) $hasil['worker']['diproses']
                . ', terkirim ' . (int) $hasil['worker']['terkirim']
                . ', gagal ' . (int) $hasil['worker']['gagal']
                . ((string) ($hasil['worker']['alasan'] ?? '') === '' ? '' : ' — ' . (string) $hasil['worker']['alasan'])
            : null,
        isset($hasil['diproses'])
            ? 'Worker: diproses ' . (int) $hasil['diproses']
                . ', terkirim ' . (int) $hasil['terkirim']
                . ', gagal ' . (int) $hasil['gagal']
            : null,
    ], static fn ($baris): bool => is_string($baris) && $baris !== ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string) ($_POST['aksi'] ?? '');
    $kanal = (string) ($_POST['kanal'] ?? '');
    $ujiCoba = ((string) ($_POST['uji_coba'] ?? '0')) === '1';
    $kembali = in_array($_POST['kembali'] ?? '', ['gagal', 'riwayat'], true) ? '?tab=' . $_POST['kembali'] : '';
    try {
        // Keputusan AL: kanal ditangguhkan tidak dijalankan worker-nya dari
        // panel ini. Penolakan menyalakan dan pesan uji ada di layanan.
        if ($aksi === 'worker' && NotificationChannel::ditangguhkan($kanal)) {
            throw NotificationException::conflict(NotificationChannel::label($kanal) . ' ditangguhkan; worker tidak dijalankan dari panel.');
        }
        if ($aksi === 'coba_ulang_massal') {
            // Tanpa JavaScript: daftar terpilih diproses berurutan, satu per
            // satu, dengan aturan yang sama seperti "Coba ulang" per baris.
            // Proses massal ber-JavaScript mengirim satu `outbox_id` per permintaan.
            $pilihan = isset($_POST['outbox_ids']) ? (array) $_POST['outbox_ids'] : [(int) ($_POST['outbox_id'] ?? 0)];
            $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $pilihan), static fn (int $id): bool => $id > 0))), 0, 100);
            if ($ids === []) {
                throw NotificationException::invalid('Pilih pengiriman gagal lebih dahulu.');
            }
            $berhasil = 0;
            $gagal = [];
            foreach ($ids as $id) {
                try {
                    if ($service->cobaUlang($currentUser, $id, $meta)['diantrekan_ulang']) {
                        $berhasil++;
                    } else {
                        $gagal[] = 'Baris ' . $id . ': tidak dapat diantrekan ulang.';
                    }
                } catch (NotificationException $exception) {
                    $gagal[] = 'Baris ' . $id . ': ' . $exception->getMessage();
                }
            }
            $hasil = ['status' => $gagal === [] ? 'Lulus' : 'Gagal', 'pesan' => count($ids) === 1 && $gagal !== []
                ? $gagal[0] : $berhasil . ' diantrekan ulang · ' . count($gagal) . ' gagal.', 'detail' => count($ids) === 1 ? [] : $gagal];
        } else {
            $hasil = match ($aksi) {
                'periksa' => $service->periksaKonfigurasi($currentUser, $kanal, $meta),
                'sakelar' => $service->ubahSakelar($currentUser, $kanal, ((string) ($_POST['aktif'] ?? '0')) === '1', $meta),
                'pesan_uji' => $service->kirimPesanUji($currentUser, $kanal, $meta),
                'coba_ulang' => $service->cobaUlang($currentUser, (int) ($_POST['outbox_id'] ?? 0), $meta),
                'worker' => $service->jalankanWorker($currentUser, $kanal, $ujiCoba),
                default => throw NotificationException::invalid('Aksi tidak dikenal.'),
            };
        }
        if (ah_wants_json()) {
            ah_json(['ok' => ($hasil['status'] ?? 'Lulus') !== 'Gagal' && ($hasil['diantrekan_ulang'] ?? true) !== false,
                'message' => (string) ($hasil['pesan'] ?? 'Perintah dijalankan.')]);
        }
        $detail = notif_detail($hasil);
        ah_flash_set(
            ($hasil['status'] ?? 'Lulus') === 'Gagal' ? 'warning' : 'success',
            (string) ($hasil['pesan'] ?? 'Perintah dijalankan.'),
            $detail === [] ? null : '<ul class="mb-0 mt-1 small">' . implode('', array_map(static fn (string $baris): string => '<li>' . ah_e($baris) . '</li>', $detail)) . '</ul>'
        );
    } catch (NotificationException $exception) {
        if (ah_wants_json()) {
            ah_json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
        ah_flash_set('danger', $exception->getMessage());
    }

    header('Location: ' . app_url('/admin/admin_notifikasi.php' . $kembali));
    exit;
}

$status = $service->status($currentUser);
$kanalGagal = (string) ($_GET['kanal'] ?? '');
$kegagalan = $service->kegagalan($currentUser, [
    'kanal' => $tab === 'gagal' ? $kanalGagal : '',
    'page' => $tab === 'gagal' ? (int) ($_GET['page'] ?? 1) : 1,
    'per_page' => 20,
]);
$jumlahGagal = (int) $kegagalan['pagination']['total'];
if ($tab === 'gagal' && $kanalGagal !== '') {
    $jumlahGagal = (int) $service->kegagalan($currentUser, ['page' => 1, 'per_page' => 1])['pagination']['total'];
}
$riwayat = $tab === 'riwayat' ? $service->riwayatKanal($currentUser, (int) ($_GET['page'] ?? 1)) : null;
$alamat = app_url('/admin/admin_notifikasi.php');

ah_page_open([
    'title' => 'Kanal notifikasi',
    'user' => $currentUser,
    'active' => 'kanal',
    'description' => 'Status kanal, pengiriman yang gagal, dan audit perubahan kanal. Terakhir diperbarui: ' . ($status['diperbarui_pada'] ?? '-') . '.',
    'breadcrumbs' => [['label' => 'Pengaturan'], ['label' => 'Kanal notifikasi']],
    'actions' => '<a class="btn btn-outline-secondary" href="' . ah_e(app_url('/portal/notifikasi.php')) . '">Pusat notifikasi saya</a>',
    'tabs' => [
        ['label' => 'Kanal', 'url' => $alamat, 'active' => $tab === 'kanal'],
        ['label' => 'Pengiriman gagal', 'url' => $alamat . '?tab=gagal', 'active' => $tab === 'gagal', 'badge' => $jumlahGagal > 0 ? (string) $jumlahGagal : ''],
        ['label' => 'Riwayat', 'url' => $alamat . '?tab=riwayat', 'active' => $tab === 'riwayat'],
    ],
]);

/**
 * Formulir satu tindakan kanal.
 *
 * @param array<string, string> $fields
 * @param array<string, string> $confirm
 */
function notif_form(string $label, array $fields, string $class, array $confirm = [], bool $disabled = false): string
{
    $attributes = '';
    foreach (['text' => 'data-confirm', 'title' => 'data-confirm-title', 'ok' => 'data-confirm-ok', 'tone' => 'data-confirm-tone'] as $key => $attribute) {
        if (isset($confirm[$key])) {
            $attributes .= ' ' . $attribute . '="' . ah_e($confirm[$key]) . '"';
        }
    }
    $hidden = Csrf::input();
    foreach ($fields as $name => $value) {
        $hidden .= '<input type="hidden" name="' . ah_e($name) . '" value="' . ah_e($value) . '">';
    }

    return '<form method="post" action="' . ah_e(app_url('/admin/admin_notifikasi.php')) . '"' . $attributes . '>' . $hidden
        . '<button class="' . ah_e($class) . '" type="submit"' . ($disabled ? ' disabled' : '') . '>' . ah_e($label) . '</button></form>';
}

function notif_menu_item(string $label, array $fields, array $extra = []): array
{
    return ['label' => $label, 'action' => app_url('/admin/admin_notifikasi.php'), 'fields' => $fields] + $extra;
}
?>

<?php if ($tab === 'kanal'): ?>
<div class="ah-channels">
    <?php foreach ($status['kanal'] as $kanal): ?>
        <?php
        $kode = (string) $kanal['kanal'];
        $ditangguhkan = !empty($kanal['ditangguhkan']);
        $siap = (bool) ($kanal['kesiapan']['siap'] ?? false);
        $label = $ditangguhkan ? 'Ditangguhkan' : (!$kanal['dapat_dimatikan'] ? 'Selalu aktif' : ($kanal['aktif'] ? 'Aktif' : 'Nonaktif'));
        $menu = [notif_menu_item('Periksa konfigurasi', ['aksi' => 'periksa', 'kanal' => $kode], ['icon' => 'fa-stethoscope'])];
        if (in_array($kode, NotificationChannel::EKSTERNAL, true) && !$ditangguhkan) {
            $menu[] = notif_menu_item('Jalankan worker sekali', ['aksi' => 'worker', 'kanal' => $kode], ['icon' => 'fa-play']);
            $menu[] = notif_menu_item('Uji coba (tanpa kirim)', ['aksi' => 'worker', 'kanal' => $kode, 'uji_coba' => '1'], ['icon' => 'fa-vial']);
        }
        if ($kanal['dapat_dimatikan'] && $kanal['aktif']) {
            $menu[] = notif_menu_item('Matikan kanal', ['aksi' => 'sakelar', 'kanal' => $kode, 'aktif' => '0'], [
                'icon' => 'fa-power-off', 'danger' => true,
                'confirm' => ['title' => 'Matikan ' . $kanal['label'] . '?', 'text' => 'Antrean yang belum terkirim dibatalkan. Notifikasi dalam aplikasi tetap berjalan.', 'ok' => 'Matikan kanal'],
            ]);
        }
        ?>
        <section class="ah-channel<?= $ditangguhkan ? ' ah-channel--paused' : '' ?>" aria-labelledby="kanal-<?= ah_e(strtolower($kode)) ?>">
            <div class="ah-channel__head">
                <h2 id="kanal-<?= ah_e(strtolower($kode)) ?>"><?= ah_e($kanal['label']) ?></h2>
                <?= ah_status($label) ?>
            </div>
            <p class="ah-channel__meta"><?= ah_e($kanal['keterangan']) ?></p>
            <p class="ah-channel__meta"><?= $siap ? 'Konfigurasi siap' : 'Konfigurasi belum siap' ?> · <?= ah_e($kanal['kesiapan']['pesan'] ?? '') ?></p>
            <?php if (isset($kanal['pemeriksaan'])): ?>
                <p class="ah-channel__meta">Pemeriksaan terakhir: <?= ah_e($kanal['pemeriksaan']['status']) ?><?= ($kanal['pemeriksaan']['pada'] ?? null) !== null ? ' · ' . ah_e($kanal['pemeriksaan']['pada']) : '' ?><?= ($kanal['pemeriksaan']['pesan'] ?? null) !== null ? ' — ' . ah_e($kanal['pemeriksaan']['pesan']) : '' ?></p>
            <?php endif; ?>
            <?php if (isset($kanal['perangkat'])): ?>
                <p class="ah-channel__meta">Perangkat terdaftar <?= (int) $kanal['perangkat']['total'] ?> (aktif <?= (int) $kanal['perangkat']['aktif'] ?>, dicabut <?= (int) $kanal['perangkat']['dicabut'] ?>)</p>
            <?php endif; ?>
            <p class="ah-channel__meta">Antrean: menunggu <?= (int) ($kanal['antrean']['Queued'] ?? 0) ?> · terkirim <?= (int) ($kanal['antrean']['Sent'] ?? 0) ?> · gagal <?= (int) ($kanal['antrean']['Failed'] ?? 0) ?> (permanen <?= (int) ($kanal['antrean']['gagal_permanen'] ?? 0) ?>)</p>
            <?php if (($kanal['kesiapan']['detail'] ?? []) !== [] || isset($kanal['penyedia'])): ?>
                <details class="ah-channel__env">
                    <summary>Environment yang dibutuhkan (nama saja)</summary>
                    <ul class="mb-0 mt-1">
                        <?php foreach ((array) ($kanal['kesiapan']['detail'] ?? []) as $baris): ?><li><?= ah_e($baris) ?></li><?php endforeach; ?>
                        <?php if (isset($kanal['penyedia'])): ?>
                            <li>Penyedia: <code><?= ah_e($kanal['penyedia']['nama']) ?></code><?= !$kanal['penyedia']['mengirim_nyata'] ? ' — adapter uji, bukan pengiriman nyata' : '' ?></li>
                            <?php foreach ($kanal['penyedia']['environment_dibutuhkan'] as $env): ?><li><code><?= ah_e($env) ?></code></li><?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </details>
            <?php endif; ?>
            <div class="ah-channel__foot">
                <?php if ($ditangguhkan): ?>
                    <p class="ah-channel__meta mb-0">Tidak ada tombol Nyalakan sampai pesantren memutuskan kanal ini dipakai.</p>
                <?php elseif (!$kanal['dapat_dimatikan']): ?>
                    <?= notif_form('Kirim pesan uji', ['aksi' => 'pesan_uji', 'kanal' => $kode], 'btn btn-primary') ?>
                    <button class="btn btn-outline-secondary" type="button" disabled title="Notifikasi dalam aplikasi adalah sumber status utama dan tidak dapat dimatikan.">Selalu aktif</button>
                <?php elseif ($kanal['aktif']): ?>
                    <?= notif_form('Kirim pesan uji', ['aksi' => 'pesan_uji', 'kanal' => $kode], 'btn btn-primary') ?>
                <?php else: ?>
                    <?= notif_form('Nyalakan kanal', ['aksi' => 'sakelar', 'kanal' => $kode, 'aktif' => '1'], 'btn btn-primary', [], !$siap) ?>
                <?php endif; ?>
                <?= ah_menu($menu, 'Tindakan lain ' . $kanal['label'], false) ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?php ah_note('info', 'Catatan keamanan: halaman ini tidak pernah menampilkan credential, token perangkat, atau nomor tujuan. Secret penyedia berada di environment server dan tidak disimpan pada basis data, audit, maupun log.'); ?>

<?php elseif ($tab === 'gagal'): ?>
<section id="pengiriman-gagal" aria-labelledby="judul-gagal">
    <h2 class="h5" id="judul-gagal">Pengiriman gagal</h2>
    <?php ah_filter_open($kanalGagal !== '' ? 1 : 0, ['tab' => 'gagal']); ?>
        <div><label class="form-label" for="gagal-kanal">Kanal</label>
            <select class="form-select" id="gagal-kanal" name="kanal">
                <option value="">Semua kanal</option>
                <?php foreach (NotificationChannel::EKSTERNAL as $pilihan): ?>
                    <option value="<?= ah_e($pilihan) ?>"<?= $kanalGagal === $pilihan ? ' selected' : '' ?>><?= ah_e(NotificationChannel::label($pilihan)) ?></option>
                <?php endforeach; ?>
            </select></div>
    <?php ah_filter_close(['tab' => 'gagal']); ?>
    <div id="hasil-massal"></div>
    <?php if ($kegagalan['items'] === []): ?>
        <div class="ah-card ah-card__body"><?= ah_empty('Tidak ada pengiriman gagal', 'Notifikasi dalam aplikasi tetap tersedia apa pun keadaan kanal lain.') ?></div>
    <?php else: ?>
    <form method="post" action="<?= ah_e($alamat) ?>" data-ah-bulk="Mencoba ulang pengiriman gagal" data-ah-bulk-field="outbox_id" data-ah-bulk-target="hasil-massal" data-ah-selection="pengiriman">
        <?= Csrf::input() ?>
        <input type="hidden" name="aksi" value="coba_ulang_massal">
        <input type="hidden" name="kembali" value="gagal">
        <div class="ah-card ah-table-wrap">
            <table class="ah-table ah-table--cards">
                <caption class="ah-visually-hidden">Pengiriman gagal, <?= (int) $kegagalan['pagination']['total'] ?> baris</caption>
                <thead><tr><th scope="col"><input class="form-check-input" type="checkbox" data-ah-select-all aria-label="Pilih semua pada halaman ini"></th><th scope="col">Peristiwa</th><th scope="col">Kanal</th><th scope="col">Penerima</th><th scope="col">Percobaan</th><th scope="col" class="ah-col-secondary">Galat aman</th><th scope="col">Tindakan</th></tr></thead>
                <tbody>
                <?php foreach ($kegagalan['items'] as $baris): ?>
                    <?php $tertahan = NotificationChannel::ditangguhkan((string) $baris['kanal']); ?>
                    <tr>
                        <td><?php if (!$tertahan): ?><input class="form-check-input" type="checkbox" name="outbox_ids[]" value="<?= (int) $baris['id'] ?>" data-ah-select data-ah-label="<?= ah_e($baris['event_label'] . ' · ' . ($baris['penerima_nama'] ?? 'Akun #' . (int) $baris['penerima_user_id'])) ?>" aria-label="Pilih <?= ah_e($baris['event_label']) ?>"><?php endif; ?></td>
                        <td><?= ah_e($baris['event_label']) ?><?php if ($baris['pengajuan_id'] !== null): ?><span class="ah-cell-sub">Pengajuan izin #<?= (int) $baris['pengajuan_id'] ?></span><?php endif; ?></td>
                        <td><?= ah_e(NotificationChannel::label((string) $baris['kanal'])) ?></td>
                        <td><?= ah_e($baris['penerima_nama'] ?? ('Akun #' . (int) $baris['penerima_user_id'])) ?></td>
                        <td><?= (int) $baris['percobaan'] ?>/<?= (int) $baris['maksimum_percobaan'] ?><?= $baris['gagal_permanen'] ? ' ' . ah_badge('Permanen', 'danger') : '' ?></td>
                        <td class="ah-col-secondary"><code><?= ah_e($baris['error_kode'] ?? '-') ?></code><span class="ah-cell-sub"><?= ah_e($baris['error_aman'] ?? '-') ?></span></td>
                        <td><?php if ($tertahan): ?><?= ah_status('Ditangguhkan') ?><?php else: ?><button class="btn btn-sm btn-outline-primary" type="submit" form="coba-ulang-<?= (int) $baris['id'] ?>">Coba ulang</button><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= ah_selection_bar('<button class="btn btn-primary btn-sm" type="submit">Coba ulang yang dipilih</button>') ?>
        <noscript><p class="mt-2"><button class="btn btn-outline-primary" type="submit">Coba ulang yang dipilih</button></p></noscript>
    </form>
    <?php foreach (array_filter($kegagalan['items'], static fn (array $b): bool => !NotificationChannel::ditangguhkan((string) $b['kanal'])) as $baris): ?>
        <form id="coba-ulang-<?= (int) $baris['id'] ?>" method="post" action="<?= ah_e($alamat) ?>" hidden>
            <?= Csrf::input() ?>
            <input type="hidden" name="aksi" value="coba_ulang">
            <input type="hidden" name="outbox_id" value="<?= (int) $baris['id'] ?>">
            <input type="hidden" name="kembali" value="gagal">
        </form>
    <?php endforeach; ?>
    <?php ah_pagination((int) $kegagalan['pagination']['total'], (int) $kegagalan['pagination']['current_page'], 20); ?>
    <?php endif; ?>
</section>

<?php else: ?>
<section aria-labelledby="judul-riwayat">
    <h2 class="h5" id="judul-riwayat">Audit perubahan kanal</h2>
    <div class="ah-card ah-table-wrap">
        <table class="ah-table ah-table--cards">
            <caption class="ah-visually-hidden">Audit perubahan kanal, <?= (int) $riwayat['total'] ?> catatan</caption>
            <thead><tr><th scope="col">Waktu</th><th scope="col">Aksi</th><th scope="col">Kanal</th><th scope="col">Perubahan</th><th scope="col">Hasil</th><th scope="col">Oleh</th></tr></thead>
            <tbody>
            <?php foreach ($riwayat['rows'] as $baris): ?>
                <tr>
                    <td><?= ah_e($baris['created_at']) ?></td>
                    <td><?= ah_e($baris['aksi']) ?></td>
                    <td><?= ah_e($baris['kanal'] ?? '-') ?></td>
                    <td><?= ah_e($baris['nilai_sebelum'] ?? '-') ?> → <?= ah_e($baris['nilai_sesudah'] ?? '-') ?></td>
                    <td><?= ah_e($baris['hasil'] ?? '-') ?><?php if (($baris['pesan'] ?? null) !== null): ?><span class="ah-cell-sub"><?= ah_e($baris['pesan']) ?></span><?php endif; ?></td>
                    <td><?= ah_e($baris['aktor_nama'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($riwayat['rows'] === []): ?>
                <tr><td colspan="6" data-label=""><?= ah_empty('Belum ada perubahan kanal', 'Pemeriksaan, sakelar, pesan uji, dan percobaan ulang tercatat di sini.') ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php ah_pagination((int) $riwayat['total'], (int) $riwayat['page'], (int) $riwayat['per_page']); ?>
</section>
<?php endif; ?>
<?php ah_page_close(); ?>
